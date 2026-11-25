<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;

/**
 * The only place that changes orders.status. Every change locks the order row, checks the current
 * state, and writes the log, the queue and the notification in the same transaction.
 */
final class OrderFlow
{
    /** action => [from states, to state, reason required, notification event] */
    private const ACTIONS = [
        'accept' => [['PENDING'], 'ACCEPTED', false, 'ORDER_ACCEPTED'],
        'reject' => [['PENDING'], 'REJECTED', true, 'ORDER_REJECTED'],
        'prepare' => [['ACCEPTED'], 'PREPARING', false, null],
        'ready' => [['PREPARING'], 'READY', false, 'ORDER_READY'],
        'cancel' => [['PENDING', 'ACCEPTED', 'PREPARING'], 'CANCELLED', true, 'ORDER_CANCELLED'],
    ];
    private const STAMP = ['ACCEPTED' => 'accepted_at', 'PREPARING' => 'preparing_at', 'READY' => 'ready_at', 'COMPLETED' => 'completed_at'];

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
        Database::tx(static function () use ($userId, $orderId, $in, $from, $to, $needsReason, $event): void {
            $order = self::lockForMember($userId, $orderId);
            if ($order['status'] === $to) {
                return; // repeated click: same result, no side effects
            }
            if (!in_array($order['status'], $from, true)) {
                throw HttpError::conflict('INVALID_STATE', 'สถานะออเดอร์เปลี่ยนไปแล้ว', ['status' => $order['status']]);
            }
            $reason = $needsReason ? Input::text($in, 'reason', 'เหตุผล', 300) : null;
            if ($to === 'ACCEPTED') {
                self::assignQueue($order);
            }
            self::move($order, $to, $userId, $reason);
            if ($event !== null) {
                Notifications::enqueue($orderId, (int) $order['user_id'], $event);
            }
        });
    }

    public static function confirmPayment(int $userId, int $orderId): void
    {
        Database::tx(static function () use ($userId, $orderId): void {
            $order = self::lockForMember($userId, $orderId);
            $payment = Database::row('SELECT id, status FROM payments WHERE order_id = ? FOR UPDATE', [$orderId]);
            if ($payment['status'] === 'PAID') {
                return;
            }
            if ($order['status'] !== 'READY') {
                throw HttpError::conflict('INVALID_STATE', 'รับชำระได้เมื่ออาหารพร้อมรับ', ['status' => $order['status']]);
            }
            Database::run("UPDATE payments SET status = 'PAID', paid_at = ?, confirmed_by = ? WHERE id = ?", [Clock::now(), $userId, $payment['id']]);
            Audit::log($userId, 'payment.confirm', 'order', $orderId, ['amount' => $order['total']]);
        });
    }

    public static function complete(int $userId, int $orderId, array $in): void
    {
        Database::tx(static function () use ($userId, $orderId, $in): void {
            $order = self::lockForMember($userId, $orderId);
            if ($order['status'] === 'COMPLETED') {
                return;
            }
            if ($order['status'] !== 'READY') {
                throw HttpError::conflict('INVALID_STATE', 'ส่งมอบได้เมื่ออาหารพร้อมรับ', ['status' => $order['status']]);
            }
            if (Database::value('SELECT status FROM payments WHERE order_id = ?', [$orderId]) !== 'PAID') {
                throw HttpError::conflict('PAYMENT_REQUIRED', 'ต้องยืนยันการชำระเงินก่อนส่งมอบ');
            }
            $code = Input::optionalText($in, 'pickup_code', 'รหัสรับอาหาร', 4);
            $reason = null;
            if ($code !== null) {
                if (!hash_equals(substr($order['public_order_no'], -4), strtoupper($code))) {
                    throw new HttpError(422, 'PICKUP_CODE_WRONG', 'รหัสรับอาหารไม่ตรงกับออเดอร์นี้');
                }
            } else {
                $reason = 'รับโดยไม่ใช้รหัส: ' . Input::text($in, 'override_reason', 'รหัสรับอาหารหรือเหตุผล', 300, 3);
                Audit::log($userId, 'pickup.override', 'order', $orderId, ['reason' => $reason]);
            }
            self::move($order, 'COMPLETED', $userId, $reason);
        });
    }

    private static function lock(int $orderId): ?array
    {
        return Database::row('SELECT id, public_order_no, user_id, shop_id, status, total FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
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
