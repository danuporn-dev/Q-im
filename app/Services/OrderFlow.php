<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;
use App\Support\Input;

/**
 * The only place that changes orders.status. Every change locks the order row, checks the current
 * state, and writes the log, the queue and the notification in the same transaction.
 */
final class OrderFlow
{
    /**
     * action => [from states, to state, reason required, notification event]
     * Shops work in three taps (D31): accept puts the order straight into the kitchen, ready, then hand-over.
     */
    private const ACTIONS = [
        'accept' => [['PENDING'], 'PREPARING', false, 'ORDER_ACCEPTED'],
        'reject' => [['PENDING'], 'REJECTED', true, 'ORDER_REJECTED'],
        'prepare' => [['ACCEPTED'], 'PREPARING', false, null],
        'ready' => [['ACCEPTED', 'PREPARING'], 'READY', false, 'ORDER_READY'],
        'cancel' => [['PENDING', 'ACCEPTED', 'PREPARING', 'READY'], 'CANCELLED', true, 'ORDER_CANCELLED'],
    ];
    /** States passed through on the way, so each still gets its log row and timestamp. ACCEPTED assigns the queue. */
    private const VIA = ['PENDING>PREPARING' => ['ACCEPTED'], 'ACCEPTED>READY' => ['PREPARING']];
    /** Audit action of a hand-over without scanning the customer's QR. */
    public const PICKUP_OVERRIDE = 'pickup.override';
    /** Wrong QR codes or typed order numbers per order before scanning is locked (D32). */
    public const PICKUP_ATTEMPTS = 5;
    /** Already done for these actions: a repeated tap answers 200 and changes nothing. */
    private const DONE = ['accept' => ['ACCEPTED', 'PREPARING']];
    private const STAMP = ['ACCEPTED' => 'accepted_at', 'PREPARING' => 'preparing_at', 'READY' => 'ready_at', 'COMPLETED' => 'completed_at'];

    /** Minutes an order may stay READY before the shop is alerted and may close it as a no-show. */
    public static function readyWaitMinutes(): int
    {
        $value = Env::get('READY_ALERT_MINUTES', '20');
        return preg_match('/^\d{1,4}$/D', $value) && (int) $value >= 1 && (int) $value <= 1440 ? (int) $value : 20;
    }

    /** $readyAt is UTC, or null when the order is not ready. */
    public static function waitedLongEnough(?string $readyAt): bool
    {
        return $readyAt !== null && strtotime($readyAt . ' UTC') + self::readyWaitMinutes() * 60 <= Clock::timestamp();
    }

    public static function cancelByCustomer(int $userId, int $orderId): void
    {
        Database::tx(static function () use ($userId, $orderId): void {
            $order = self::lock($orderId);
            if ($order === null || (int) $order['user_id'] !== $userId) {
                throw HttpError::notFound('ไม่พบออเดอร์');
            }
            if ($order['status'] === 'CANCELLED') {
                return;
            }
            if ($order['status'] !== 'PENDING') {
                throw HttpError::conflict('INVALID_STATE', 'ร้านรับออเดอร์แล้ว กรุณาติดต่อร้านเพื่อยกเลิก', ['status' => $order['status']]);
            }
            self::move($order, 'CANCELLED', $userId, 'ลูกค้ายกเลิก');
        });
    }

    public static function byMerchant(int $userId, int $orderId, string $action, array $in): void
    {
        [$from, $to, $needsReason, $event] = self::ACTIONS[$action];
        $done = self::DONE[$action] ?? [$to];
        Database::tx(static function () use ($userId, $orderId, $in, $from, $to, $needsReason, $event, $done): void {
            // The kitchen slots need the shop row; take it before the order row, the lock order used everywhere.
            $shopId = Database::value('SELECT o.shop_id FROM orders o JOIN shop_members m ON m.shop_id = o.shop_id AND m.user_id = ? WHERE o.id = ?', [$userId, $orderId]);
            if ($shopId !== false) {
                Database::row('SELECT id FROM shops WHERE id = ? FOR UPDATE', [$shopId]);
            }
            $order = self::lockForMember($userId, $orderId);
            if (in_array($order['status'], $done, true)) {
                return; // repeated click: same result, no side effects
            }
            if (!in_array($order['status'], $from, true)) {
                throw HttpError::conflict('INVALID_STATE', 'สถานะออเดอร์เปลี่ยนไปแล้ว', ['status' => $order['status']]);
            }
            $reason = $needsReason ? Input::text($in, 'reason', 'เหตุผล', 300) : null;
            if ($order['status'] === 'READY' && $to === 'CANCELLED') {
                self::checkNoShow($order);
                Audit::log($userId, 'order.no_show', 'order', $orderId, ['shop_id' => (int) $order['shop_id'], 'reason' => $reason]);
            }
            foreach ([...(self::VIA[$order['status'] . '>' . $to] ?? []), $to] as $step) {
                if ($step === 'ACCEPTED') {
                    self::assignQueue($order);
                }
                self::move($order, $step, $userId, $step === $to ? $reason : null);
                $order['status'] = $step;
            }
            if ($to === 'READY') {
                PickupTokens::issue($orderId, Clock::now());
            }
            if ($event !== null) {
                Notifications::enqueue($orderId, (int) $order['user_id'], $event);
            }
            self::fillKitchen((int) $order['shop_id']);
        });
    }

    /**
     * Gives free kitchen slots (shops.capacity) to the oldest waiting orders, in queue order, by setting
     * cooking_started_at. Called whenever an order enters or leaves the kitchen, and when capacity changes.
     * The shop row lock keeps two transactions from filling the same slot.
     */
    public static function fillKitchen(int $shopId): void
    {
        $capacity = Database::value('SELECT capacity FROM shops WHERE id = ? FOR UPDATE', [$shopId]);
        $jobs = Database::rows(
            "SELECT o.id, o.cooking_started_at FROM orders o JOIN queues q ON q.order_id = o.id
             WHERE o.shop_id = ? AND o.status IN ('ACCEPTED','PREPARING') ORDER BY q.business_date, q.queue_number",
            [$shopId]
        );
        $free = $capacity === null ? count($jobs) : (int) $capacity - count(array_filter(array_column($jobs, 'cooking_started_at')));
        foreach ($jobs as $job) {
            if ($free <= 0) {
                break;
            }
            if ($job['cooking_started_at'] === null) {
                Database::run('UPDATE orders SET cooking_started_at = ? WHERE id = ?', [Clock::now(), $job['id']]);
                $free--;
            }
        }
    }

    public static function confirmPayment(int $userId, int $orderId, array $in = []): void
    {
        Database::tx(static function () use ($userId, $orderId, $in): void {
            $order = self::lockForMember($userId, $orderId);
            $payment = Database::row('SELECT id, status, requested_method FROM payments WHERE order_id = ? FOR UPDATE', [$orderId]);
            if ($payment['status'] === 'PAID') {
                return;
            }
            if ($order['status'] !== 'READY') {
                throw HttpError::conflict('INVALID_STATE', 'รับชำระได้เมื่ออาหารพร้อมรับ', ['status' => $order['status']]);
            }
            self::markPaid($userId, $order, (int) $payment['id'], self::receivedMethod($in, $payment, (int) $order['shop_id']));
        });
    }

    /**
     * Hand-over. pickup_qr is what the shop scanned or typed: the single-use token in the customer's QR
     * (D38), or, as the fallback, the full order number, which shops only see masked. payment_received: true
     * is the shop's own confirmation of the money; method says how it arrived.
     */
    public static function complete(int $userId, int $orderId, array $in): void
    {
        // A wrong code is counted and audited, so that transaction must commit before the error is returned.
        $wrong = Database::tx(static function () use ($userId, $orderId, $in): ?HttpError {
            $order = self::lockForMember($userId, $orderId);
            $scan = Input::optionalText($in, 'pickup_qr', 'QR รับอาหาร', 200);
            if ($order['status'] === 'COMPLETED') {
                // A token works once; repeating the typed number or the reason stays a harmless repeat.
                if ($scan !== null && PickupTokens::looksLikeToken($scan)) {
                    throw HttpError::conflict('PICKUP_QR_USED', 'QR นี้ใช้ไม่ได้แล้ว ออเดอร์ส่งมอบไปแล้ว');
                }
                return null;
            }
            if ($order['status'] !== 'READY') {
                throw HttpError::conflict('INVALID_STATE', 'ส่งมอบได้เมื่ออาหารพร้อมรับ', ['status' => $order['status']]);
            }
            if ($scan !== null && str_starts_with($scan, '000201')) {
                // The customer's PromptPay QR is on the same screen. It is no guess, so it is not counted.
                throw new HttpError(422, 'PICKUP_QR_PROMPTPAY', 'นั่นคือ QR PromptPay ให้สแกน QR รับอาหาร');
            }
            $reason = null;
            if ($scan !== null) {
                $failures = (int) $order['pickup_failures'];
                if ($failures >= self::PICKUP_ATTEMPTS) {
                    throw self::scanLocked();
                }
                $result = PickupTokens::looksLikeToken($scan)
                    ? PickupTokens::check($orderId, $scan)
                    : (hash_equals($order['public_order_no'], strtoupper($scan)) ? 'OK' : 'WRONG');
                if ($result === 'EXPIRED') {
                    throw HttpError::conflict('PICKUP_QR_EXPIRED', 'QR หมดอายุ ใช้เลขออเดอร์จากหน้าจอลูกค้า หรือส่งมอบโดยระบุเหตุผล');
                }
                if ($result !== 'OK') {
                    Database::run('UPDATE orders SET pickup_failures = pickup_failures + 1 WHERE id = ?', [$orderId]);
                    Audit::log($userId, 'pickup.qr_wrong', 'order', $orderId, ['shop_id' => (int) $order['shop_id'], 'attempt' => $failures + 1]);
                    $left = self::PICKUP_ATTEMPTS - $failures - 1;
                    return $left > 0 ? new HttpError(422, 'PICKUP_QR_WRONG', "QR ไม่ตรงกับออเดอร์นี้ (ลองได้อีก $left ครั้ง)") : self::scanLocked();
                }
            } else {
                $reason = 'รับโดยไม่สแกน QR: ' . Input::text($in, 'override_reason', 'QR รับอาหารหรือเหตุผล', 300, 3);
            }
            // Checked after the QR, so the shop learns that the code is right and then confirms the money itself.
            $payment = Database::row('SELECT id, status, requested_method FROM payments WHERE order_id = ? FOR UPDATE', [$orderId]);
            if ($payment['status'] !== 'PAID' && ($in['payment_received'] ?? false) !== true) {
                throw HttpError::conflict('PAYMENT_REQUIRED', 'ต้องยืนยันการชำระเงินก่อนส่งมอบ', ['total' => $order['total']]);
            }
            if ($payment['status'] !== 'PAID') {
                self::markPaid($userId, $order, (int) $payment['id'], self::receivedMethod($in, $payment, (int) $order['shop_id']));
            }
            if ($reason !== null) {
                Audit::log($userId, self::PICKUP_OVERRIDE, 'order', $orderId, ['shop_id' => (int) $order['shop_id'], 'reason' => $reason]);
            }
            self::move($order, 'COMPLETED', $userId, $reason);
            PickupTokens::spend($orderId);
            return null;
        });
        if ($wrong !== null) {
            throw $wrong;
        }
    }

    private static function scanLocked(): HttpError
    {
        return HttpError::conflict('PICKUP_QR_LOCKED', 'สแกนผิดครบ ' . self::PICKUP_ATTEMPTS . ' ครั้ง ออเดอร์นี้ต้องส่งมอบโดยระบุเหตุผล');
    }

    /** The caller holds the order and payment row locks. */
    private static function markPaid(int $userId, array $order, int $paymentId, string $method): void
    {
        Database::run("UPDATE payments SET status = 'PAID', method = ?, paid_at = ?, confirmed_by = ? WHERE id = ?", [$method, Clock::now(), $userId, $paymentId]);
        Audit::log($userId, 'payment.confirm', 'order', (int) $order['id'], ['amount' => $order['total'], 'method' => $method]);
    }

    /** How the shop says the money arrived; the customer's choice when the shop does not say. */
    private static function receivedMethod(array $in, array $payment, int $shopId): string
    {
        $method = $in['method'] ?? $payment['requested_method'];
        $offered = $method === 'PROMPTPAY' && ($payment['requested_method'] === 'PROMPTPAY' || PromptPay::numberOf($shopId) !== null);
        if ($method !== 'PAY_AT_STORE' && !$offered) {
            throw HttpError::invalid('วิธีรับเงินไม่ถูกต้อง', ['field' => 'method']);
        }
        return $method;
    }

    /** The order row is locked, and payments are only confirmed under that lock, so the check stays true until commit. */
    private static function checkNoShow(array $order): void
    {
        if (Database::value('SELECT status FROM payments WHERE order_id = ? FOR UPDATE', [$order['id']]) !== 'UNPAID') {
            throw HttpError::conflict('ALREADY_PAID', 'ออเดอร์นี้ชำระแล้ว ยกเลิกไม่ได้');
        }
        if (!self::waitedLongEnough($order['ready_at'])) {
            throw HttpError::conflict('NO_SHOW_TOO_EARLY', 'ยกเลิกเพราะลูกค้าไม่มารับได้เมื่อพร้อมรับครบ ' . self::readyWaitMinutes() . ' นาที');
        }
    }

    private static function lock(int $orderId): ?array
    {
        return Database::row('SELECT id, public_order_no, user_id, shop_id, status, total, ready_at, pickup_failures FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
    }

    private static function lockForMember(int $userId, int $orderId): array
    {
        $order = self::lock($orderId) ?? throw HttpError::notFound('ไม่พบออเดอร์');
        if (!Database::value('SELECT 1 FROM shop_members WHERE shop_id = ? AND user_id = ?', [$order['shop_id'], $userId])) {
            throw HttpError::notFound('ไม่พบออเดอร์');
        }
        return $order;
    }

    private static function move(array $order, string $to, int $actorId, ?string $reason): void
    {
        $now = Clock::now();
        $changes = ['status' => $to];
        if (isset(self::STAMP[$to])) {
            $changes[self::STAMP[$to]] = $now;
        }
        Database::update('orders', (int) $order['id'], $changes);
        Database::insert('order_status_logs', ['order_id' => $order['id'], 'from_status' => $order['status'], 'to_status' => $to, 'actor_user_id' => $actorId, 'reason' => $reason, 'created_at' => $now]);
    }

    /** The upsert takes an exclusive lock on the counter row until commit, so numbers are unique and gap-free. */
    private static function assignQueue(array $order): void
    {
        $date = Clock::businessDate();
        Database::run('INSERT INTO queue_counters (shop_id, business_date, last_number) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1', [$order['shop_id'], $date]);
        $number = (int) Database::value('SELECT last_number FROM queue_counters WHERE shop_id = ? AND business_date = ? FOR UPDATE', [$order['shop_id'], $date]);
        Database::insert('queues', ['order_id' => $order['id'], 'shop_id' => $order['shop_id'], 'business_date' => $date, 'queue_number' => $number, 'assigned_at' => Clock::now()]);
    }
}
