<?php
declare(strict_types=1);

namespace App\Services;

use App\Payments\GatewayError;
use App\Payments\Payments;
use App\Payments\StripeGateway;
use App\Payments\StripeSignature;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;
use App\Support\Log;
use App\Support\Money;
use PDOException;
use Throwable;

/**
 * Online PromptPay through Stripe (D42, PAYMENT_MODE=gateway). The customer pays only after the shop accepted the
 * order. Only Stripe's word makes an order PAID: the signed webhook, or the server reading the PaymentIntent back;
 * never the customer's page. Amount and currency always come from the order on the server.
 */
final class OnlinePayments
{
    public const METHOD = 'STRIPE_PROMPTPAY';
    public const PROVIDER = 'stripe';
    /** Stripe's smallest PromptPay payment for a Thai account. */
    public const MIN_TOTAL = '10.00';
    private const PAYABLE = ['PREPARING', 'READY'];
    private const OPEN = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    /** How long the page shows a QR before asking for a fresh one; Stripe states no expiry for PromptPay. */
    public static function qrMinutes(): int
    {
        $value = Env::get('PROMPTPAY_QR_MINUTES', '10');
        return preg_match('/^\d{1,2}$/D', $value) && (int) $value >= 1 && (int) $value <= 60 ? (int) $value : 10;
    }

    /** What the customer's order page shows; null for orders that never chose online payment. */
    public static function forCustomer(int $orderId, string $status, string $paymentStatus, string $method): ?array
    {
        $requested = Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$orderId]);
        $rows = Database::rows('SELECT active_order_id, status, qr_data, qr_image_url, hosted_url, qr_expires_at, succeeded_at, refund_due_at, refunded_at FROM payment_intents WHERE order_id = ? ORDER BY attempt', [$orderId]);
        if ($requested !== self::METHOD && $method !== self::METHOD && $rows === []) {
            return null;
        }
        $active = null;
        foreach ($rows as $r) {
            $active = $r['active_order_id'] !== null ? $r : $active;
        }
        $paidOnline = $paymentStatus === 'PAID' && $method === self::METHOD;
        $state = match (true) {
            $paidOnline => 'paid',
            in_array($status, ['CANCELLED', 'REJECTED', 'COMPLETED'], true) || $paymentStatus !== 'UNPAID' || $requested !== self::METHOD => 'closed',
            !in_array($status, self::PAYABLE, true) => 'waiting_shop',
            $active !== null && $active['status'] === 'processing' => 'processing',
            $active !== null && $active['qr_data'] !== null && $active['qr_expires_at'] !== null && $active['qr_expires_at'] > Clock::now() => 'qr',
            default => 'payable',
        };
        $refundDue = false;
        foreach ($rows as $r) {
            $refundDue = $refundDue || ($r['refund_due_at'] !== null && $r['refunded_at'] === null);
        }
        return [
            'state' => $state,
            'qr' => $state === 'qr' ? [
                'data' => $active['qr_data'],
                'image_url' => $active['qr_image_url'],
                // Stripe's sandbox page with "authorise / fail" buttons; never shown with a live key.
                'test_url' => Payments::sandbox() ? $active['hosted_url'] : null,
                'expires_at' => Clock::iso($active['qr_expires_at']),
            ] : null,
            'refund_due' => $refundDue,
        ];
    }

    /** The customer asks for the QR: reuses the open PaymentIntent, or creates the order's next one. */
    public static function start(int $userId, int $orderId): void
    {
        $order = self::payable($userId, $orderId);
        $active = Database::row('SELECT * FROM payment_intents WHERE active_order_id = ?', [$orderId]);
        if ($active !== null && $active['status'] === 'requires_action' && $active['qr_data'] !== null && $active['qr_expires_at'] > Clock::now()) {
            return;
        }
        if ($active !== null) {
            $intent = self::call(static fn () => Payments::gateway()->retrieve($active['intent_id']));
            if ($intent['status'] === 'requires_action' && $intent['qr'] !== null) {
                Database::run('UPDATE payment_intents SET status = ?, qr_data = ?, qr_image_url = ?, hosted_url = ?, qr_expires_at = ? WHERE id = ? AND active_order_id IS NOT NULL', [$intent['status'], $intent['qr']['data'], $intent['qr']['image_png'], $intent['qr']['hosted_url'], self::qrExpiry(), $active['id']]);
                return;
            }
            Database::tx(static fn () => self::apply($intent, 'start'));
            if (!in_array($intent['status'], ['canceled', 'requires_payment_method'], true)) {
                return; // succeeded, processing: nothing to pay any more, or not yet
            }
            Database::run('UPDATE payment_intents SET active_order_id = NULL WHERE id = ?', [$active['id']]);
        }
        // The idempotency key is fixed per attempt, so two taps at once get the same PaymentIntent from Stripe.
        $attempt = (int) Database::value('SELECT COALESCE(MAX(attempt), 0) + 1 FROM payment_intents WHERE order_id = ?', [$orderId]);
        $amount = Money::toSatang($order['total']);
        $intent = self::call(static fn () => Payments::gateway()->createPromptPay($amount, 'THB', ['order_id' => (string) $orderId, 'order_no' => $order['public_order_no']], "qim-order-$orderId-$attempt", Env::get('STRIPE_PLACEHOLDER_EMAIL') ?: null));
        if ($intent['amount'] !== $amount || $intent['currency'] !== 'THB' || ($intent['metadata']['order_id'] ?? '') !== (string) $orderId || $intent['qr'] === null) {
            Log::write('payments', "created intent does not match order $orderId");
            throw new HttpError(502, 'GATEWAY_ERROR', 'ระบบชำระเงินออนไลน์ตอบไม่ถูกต้อง กรุณาลองใหม่ หรือเปลี่ยนเป็นจ่ายที่ร้าน');
        }
        try {
            Database::insert('payment_intents', [
                'order_id' => $orderId, 'active_order_id' => $orderId, 'provider' => self::PROVIDER, 'intent_id' => $intent['id'], 'amount' => $amount, 'currency' => 'THB',
                'status' => $intent['status'], 'attempt' => $attempt, 'qr_data' => $intent['qr']['data'], 'qr_image_url' => $intent['qr']['image_png'],
                'hosted_url' => $intent['qr']['hosted_url'], 'qr_expires_at' => self::qrExpiry(), 'created_at' => Clock::now(),
            ]);
        } catch (PDOException $e) {
            if (!Database::isDuplicate($e)) {
                throw $e;
            }
            // A parallel request stored the same PaymentIntent first.
        }
    }

    /** "Check payment": reads the PaymentIntent back from Stripe, for when the webhook is not set up or late. */
    public static function check(int $userId, int $orderId): void
    {
        self::own($userId, $orderId);
        $row = Database::row('SELECT intent_id FROM payment_intents WHERE order_id = ? AND succeeded_at IS NULL ORDER BY active_order_id IS NULL, attempt DESC LIMIT 1', [$orderId]);
        if ($row !== null) {
            $intent = self::call(static fn () => Payments::gateway()->retrieve($row['intent_id']));
            Database::tx(static fn () => self::apply($intent, 'check'));
        }
    }

    /** "Pay at the shop instead": only after Stripe confirmed that the PaymentIntent is cancelled. */
    public static function switchToStore(int $userId, int $orderId): void
    {
        $order = self::own($userId, $orderId);
        if ($order['requested_method'] !== self::METHOD) {
            return;
        }
        if ($order['payment_status'] !== 'UNPAID' || in_array($order['status'], ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
            throw HttpError::conflict('ALREADY_PAID', 'ออเดอร์นี้ชำระแล้วหรือปิดแล้ว เปลี่ยนวิธีชำระไม่ได้');
        }
        $active = Database::row('SELECT id, intent_id FROM payment_intents WHERE active_order_id = ?', [$orderId]);
        if ($active !== null) {
            try {
                $intent = Payments::gateway()->cancel($active['intent_id'], 'qim-cancel-' . $active['id']);
            } catch (GatewayError $e) {
                // Not cancelled: find out why, and record a payment that already went through.
                $intent = self::call(static fn () => Payments::gateway()->retrieve($active['intent_id']));
                Database::tx(static fn () => self::apply($intent, 'switch'));
                throw match ($intent['status']) {
                    'succeeded' => HttpError::conflict('ALREADY_PAID', 'ชำระออนไลน์สำเร็จแล้ว ไม่ต้องจ่ายที่ร้าน'),
                    'processing' => HttpError::conflict('PAYMENT_PROCESSING', 'ธนาคารกำลังดำเนินการชำระเงินนี้ เปลี่ยนวิธีชำระไม่ได้ในตอนนี้'),
                    default => self::unavailable($e),
                };
            }
            if ($intent['status'] !== 'canceled') {
                throw new HttpError(502, 'GATEWAY_ERROR', 'ยกเลิกการชำระออนไลน์ไม่สำเร็จ กรุณาลองใหม่');
            }
        }
        Database::tx(static function () use ($userId, $orderId, $active): void {
            $payment = Database::row('SELECT id, status FROM payments WHERE order_id = ? FOR UPDATE', [$orderId]);
            if ($payment['status'] !== 'UNPAID') {
                throw HttpError::conflict('ALREADY_PAID', 'ออเดอร์นี้ชำระแล้ว เปลี่ยนวิธีชำระไม่ได้');
            }
            if ($active !== null) {
                Database::run("UPDATE payment_intents SET status = 'canceled', active_order_id = NULL, qr_data = NULL, qr_expires_at = NULL WHERE id = ?", [$active['id']]);
            }
            Database::run("UPDATE payments SET method = 'PAY_AT_STORE', requested_method = 'PAY_AT_STORE' WHERE id = ?", [$payment['id']]);
            Audit::log($userId, 'payment.method_changed', 'order', $orderId, ['from' => self::METHOD, 'to' => 'PAY_AT_STORE']);
        });
    }

    /**
     * POST /api/webhooks/stripe. Returns [HTTP status, body]. 400 for anything not signed by Stripe; 2xx once an
     * event is handled (also when it is ignored or a repeat); 5xx on a database failure, so that Stripe retries.
     *
     * @return array{0: int, 1: array}
     */
    public static function webhook(string $payload, string $signature): array
    {
        $secret = trim(Env::get('STRIPE_WEBHOOK_SECRET'));
        if ($secret === '') {
            Log::write('payments', 'webhook refused: STRIPE_WEBHOOK_SECRET is not set');
            return [503, ['error' => 'not configured']];
        }
        if (!StripeSignature::verify($payload, $signature, $secret, Clock::timestamp())) {
            return [400, ['error' => 'bad signature']];
        }
        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? null;
        if (!is_string($event['id'] ?? null) || !preg_match('/^evt_[A-Za-z0-9_]{1,90}$/D', $event['id']) || !is_string($event['type'] ?? null) || !is_array($object)) {
            return [400, ['error' => 'bad event']];
        }
        $type = substr($event['type'], 0, 80);
        try {
            $result = Database::tx(static function () use ($event, $type, $object): string {
                $intentId = is_string($object['id'] ?? null) ? substr($object['id'], 0, 100) : null;
                Database::run('INSERT IGNORE INTO gateway_events (event_id, provider, type, intent_id, received_at) VALUES (?, ?, ?, ?, ?)', [$event['id'], self::PROVIDER, $type, $intentId, Clock::now()]);
                if (Database::value('SELECT processed_at FROM gateway_events WHERE event_id = ? FOR UPDATE', [$event['id']]) !== null) {
                    return 'duplicate';
                }
                $result = str_starts_with($type, 'payment_intent.') && ($object['object'] ?? '') === 'payment_intent'
                    ? self::apply(StripeGateway::intent($object), 'webhook')
                    : 'ignored';
                $orderId = $intentId === null ? null : Database::value('SELECT order_id FROM payment_intents WHERE provider = ? AND intent_id = ?', [self::PROVIDER, $intentId]);
                Database::run('UPDATE gateway_events SET result = ?, order_id = ?, processed_at = ? WHERE event_id = ?', [$result, $orderId ?: null, Clock::now(), $event['id']]);
                return $result;
            });
        } catch (Throwable $e) {
            Log::write('payments', 'webhook failed, Stripe will retry: ' . StripeGateway::redact($e->getMessage()));
            return [500, ['error' => 'retry']];
        }
        return [200, ['received' => true, 'result' => $result]];
    }

    /**
     * Records what Stripe says about one of our PaymentIntents. Call inside a transaction. Marks the order PAID
     * only when the intent is ours for that order, for its full amount in THB, and the order still waits for
     * its money; money that arrives for a closed or already paid order is marked refund due instead.
     *
     * @return string paid | duplicate | refund_due | mismatch | recorded | unknown
     */
    public static function apply(array $intent, string $source): string
    {
        $orderId = (int) Database::value('SELECT order_id FROM payment_intents WHERE provider = ? AND intent_id = ?', [self::PROVIDER, $intent['id']]);
        if ($orderId === 0) {
            return 'unknown';
        }
        // Lock order used everywhere: order, then its payment rows.
        $order = Database::row('SELECT id, status, total, shop_id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        $row = Database::row('SELECT * FROM payment_intents WHERE provider = ? AND intent_id = ? FOR UPDATE', [self::PROVIDER, $intent['id']]);
        $payment = Database::row('SELECT id, status FROM payments WHERE order_id = ? FOR UPDATE', [$orderId]);
        if ($intent['status'] !== 'succeeded') {
            if ($row['succeeded_at'] === null) {
                $closed = in_array($intent['status'], ['canceled', 'requires_payment_method'], true);
                Database::run('UPDATE payment_intents SET status = ?, qr_expires_at = IF(?, NULL, qr_expires_at), active_order_id = IF(? = 1, NULL, active_order_id) WHERE id = ?', [$intent['status'], $closed ? 1 : 0, $intent['status'] === 'canceled' ? 1 : 0, $row['id']]);
            }
            return 'recorded';
        }
        if ($row['succeeded_at'] !== null) {
            return 'duplicate';
        }
        $problems = [];
        if (($intent['metadata']['order_id'] ?? '') !== (string) $orderId) {
            $problems[] = 'order';
        }
        if ($intent['amount'] !== (int) $row['amount'] || $intent['amount'] !== Money::toSatang($order['total'])) {
            $problems[] = 'amount';
        }
        if ($intent['currency'] !== 'THB' || $row['currency'] !== 'THB') {
            $problems[] = 'currency';
        }
        if ($problems !== []) {
            Database::run('UPDATE payment_intents SET status = ?, problem = ? WHERE id = ?', [$intent['status'], 'ไม่ตรงกับออเดอร์: ' . implode(', ', $problems), $row['id']]);
            Audit::log(null, 'payment.gateway_mismatch', 'order', $orderId, ['intent' => $intent['id'], 'problems' => $problems, 'amount' => $intent['amount'], 'currency' => $intent['currency'], 'source' => $source]);
            Log::write('payments', "intent {$intent['id']} does not match order $orderId: " . implode(', ', $problems));
            return 'mismatch';
        }
        $now = Clock::now();
        Database::run('UPDATE payment_intents SET status = ?, succeeded_at = ?, qr_data = NULL, qr_expires_at = NULL WHERE id = ?', [$intent['status'], $now, $row['id']]);
        if (in_array($order['status'], ['CANCELLED', 'REJECTED'], true) || $payment['status'] !== 'UNPAID') {
            $reason = $payment['status'] !== 'UNPAID' ? 'ชำระซ้ำ: ออเดอร์ชำระแล้วด้วยวิธีอื่น' : 'เงินเข้าหลังออเดอร์ถูกยกเลิก';
            self::refundDue((int) $row['id'], $orderId, $reason, null);
            return 'refund_due';
        }
        Database::run("UPDATE payments SET status = 'PAID', method = ?, requested_method = ?, paid_at = ?, confirmed_by = NULL WHERE id = ?", [self::METHOD, self::METHOD, $now, $payment['id']]);
        Audit::log(null, 'payment.confirm', 'order', $orderId, ['amount' => $order['total'], 'method' => self::METHOD, 'intent' => $intent['id'], 'source' => $source]);
        return 'paid';
    }

    /** In the shop's cancel transaction: money already received online must go back (by hand, D42). */
    public static function cancelledByShop(int $orderId, int $actorId, string $reason): void
    {
        $paid = Database::row("SELECT pi.id FROM payment_intents pi JOIN payments p ON p.order_id = pi.order_id AND p.status = 'PAID' AND p.method = ?
                               WHERE pi.order_id = ? AND pi.succeeded_at IS NOT NULL AND pi.refund_due_at IS NULL FOR UPDATE", [self::METHOD, $orderId]);
        if ($paid !== null) {
            self::refundDue((int) $paid['id'], $orderId, 'ร้านยกเลิกหลังลูกค้าชำระ: ' . $reason, $actorId);
        }
    }

    /**
     * After the order was cancelled or paid another way: cancels its open PaymentIntent at Stripe, so the QR
     * stops working. Best effort and never throws; a payment that still arrives is marked refund due.
     */
    public static function cancelOpen(int $orderId): void
    {
        $open = Database::row('SELECT id, intent_id, status FROM payment_intents WHERE active_order_id = ? AND succeeded_at IS NULL', [$orderId]);
        if ($open === null || !in_array($open['status'], self::OPEN, true)) {
            return;
        }
        try {
            $intent = Payments::gateway()->cancel($open['intent_id'], 'qim-cancel-' . $open['id']);
            Database::tx(static fn () => self::apply($intent, 'order_closed'));
        } catch (Throwable $e) {
            Log::write('payments', "could not cancel intent of order $orderId: " . StripeGateway::redact($e->getMessage()));
        }
    }

    private static function refundDue(int $rowId, int $orderId, string $reason, ?int $actorId): void
    {
        Database::run('UPDATE payment_intents SET refund_due_at = ?, refund_reason = ? WHERE id = ?', [Clock::now(), mb_substr($reason, 0, 300), $rowId]);
        Audit::log($actorId, 'payment.refund_due', 'order', $orderId, ['reason' => $reason]);
    }

    private static function own(int $userId, int $orderId): array
    {
        return Database::row(
            'SELECT o.id, o.status, o.total, o.public_order_no, p.status AS payment_status, p.requested_method FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.id = ? AND o.user_id = ?',
            [$orderId, $userId]
        ) ?? throw HttpError::notFound('ไม่พบออเดอร์');
    }

    private static function payable(int $userId, int $orderId): array
    {
        $order = self::own($userId, $orderId);
        if (!Payments::online()) {
            throw HttpError::conflict('ONLINE_PAYMENT_OFF', 'ระบบไม่ได้เปิดการชำระออนไลน์');
        }
        if ($order['requested_method'] !== self::METHOD) {
            throw HttpError::conflict('NOT_ONLINE', 'ออเดอร์นี้เลือกชำระที่ร้าน');
        }
        if ($order['payment_status'] !== 'UNPAID') {
            throw HttpError::conflict('ALREADY_PAID', 'ออเดอร์นี้ชำระแล้ว');
        }
        if (!in_array($order['status'], self::PAYABLE, true)) {
            throw HttpError::conflict('NOT_PAYABLE', 'จ่ายออนไลน์ได้เมื่อร้านรับออเดอร์แล้ว', ['status' => $order['status']]);
        }
        return $order;
    }

    private static function qrExpiry(): string
    {
        return gmdate('Y-m-d H:i:s', Clock::timestamp() + self::qrMinutes() * 60);
    }

    /** Runs a gateway call; its failures become a safe answer for the customer and a log line without keys. */
    private static function call(callable $work): array
    {
        try {
            return $work();
        } catch (GatewayError $e) {
            throw self::unavailable($e);
        }
    }

    private static function unavailable(GatewayError $e): HttpError
    {
        Log::write('payments', 'gateway: ' . StripeGateway::redact($e->getMessage()) . ($e->providerCode !== '' ? " [{$e->providerCode}]" : ''));
        return $e->status === 0 && $e->providerCode === '' && str_contains($e->getMessage(), 'STRIPE_SECRET_KEY')
            ? new HttpError(503, 'ONLINE_PAYMENT_UNAVAILABLE', 'การชำระออนไลน์ยังไม่พร้อมใช้งาน กรุณาเปลี่ยนเป็นจ่ายที่ร้าน')
            : new HttpError(502, 'GATEWAY_ERROR', 'ระบบชำระเงินออนไลน์ไม่ตอบ กรุณาลองใหม่ หรือเปลี่ยนเป็นจ่ายที่ร้าน');
    }
}
