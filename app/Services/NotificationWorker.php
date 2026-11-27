<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpClient;

/** Sends the outbox through the LINE Messaging API. Safe to run in parallel. */
final class NotificationWorker
{
    public const MAX_ATTEMPTS = 5;
    private const BACKOFF = [60, 300, 900, 3600];
    private const LEASE = 300;
    private const MAX_AGE = 86400;
    public const SELECT =
        'SELECT n.id, n.event_type, n.dedupe_key, n.attempts, n.created_at, o.id AS order_id, o.status AS order_status, o.public_order_no, s.name AS shop_name,
                    q.queue_number, u.line_user_id, u.line_friend, u.active,
                    (SELECT l.reason FROM order_status_logs l WHERE l.order_id = o.id AND l.reason IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS reason,
                    EXISTS (SELECT 1 FROM payment_intents pi WHERE pi.order_id = o.id AND pi.refund_due_at IS NOT NULL) AS refund_due
             FROM notifications n JOIN orders o ON o.id = n.order_id JOIN shops s ON s.id = o.shop_id
             JOIN users u ON u.id = n.user_id LEFT JOIN queues q ON q.order_id = o.id';

    /** @return array{sent: int, retry: int, failed: int} */
    public static function run(int $limit = 50): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        $ids = array_column(Database::rows(
            "SELECT id FROM notifications WHERE status IN ('PENDING','SENDING') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY next_attempt_at, id LIMIT $limit",
            [Clock::now()]
        ), 'id');
        foreach ($ids as $id) {
            // The conditional update is the claim: a second worker changes no row. A crashed worker's lease expires.
            $claimed = Database::run(
                "UPDATE notifications SET status = 'SENDING', attempts = attempts + 1, next_attempt_at = ?
                 WHERE id = ? AND status IN ('PENDING','SENDING') AND (next_attempt_at IS NULL OR next_attempt_at <= ?)",
                [self::after(self::LEASE), $id, Clock::now()]
            );
            if ($claimed === 1) {
                $stats[self::send((int) $id)]++;
            }
        }
        return $stats;
    }

    private static function send(int $id): string
    {
        $n = Database::row(
            self::SELECT . ' WHERE n.id = ?',
            [$id]
        );
        $skip = match (true) {
            (int) $n['attempts'] > self::MAX_ATTEMPTS => 'too many attempts',
            !preg_match('/^U[0-9a-f]{32}$/D', (string) $n['line_user_id']) => 'not a LINE user',
            !$n['active'] => 'user disabled',
            $n['line_friend'] !== null && (int) $n['line_friend'] === 0 => 'user blocked the official account',
            strtotime($n['created_at'] . ' UTC') < Clock::timestamp() - self::MAX_AGE => 'expired',
            in_array($n['event_type'], ['ORDER_ACCEPTED', 'ORDER_READY'], true) && in_array($n['order_status'], ['COMPLETED', 'CANCELLED', 'REJECTED'], true) => 'obsolete',
            default => null,
        };
        if ($skip !== null) {
            return self::fail($id, $skip);
        }
        $response = HttpClient::post(
            HttpClient::lineBase() . '/v2/bot/message/push',
            json_encode(['to' => $n['line_user_id'], 'messages' => [['type' => 'text', 'text' => self::text($n)]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json', 'Authorization: Bearer ' . Env::get('LINE_CHANNEL_ACCESS_TOKEN'), 'X-Line-Retry-Key: ' . self::retryKey($n)]
        );
        $status = $response['status'];
        // 409 means LINE already accepted this retry key.
        if (($status >= 200 && $status < 300) || $status === 409) {
            Database::run("UPDATE notifications SET status = 'SENT', sent_at = ?, next_attempt_at = NULL, last_error = NULL WHERE id = ?", [Clock::now(), $id]);
            return 'sent';
        }
        $error = $status === 0 ? 'network error' : "LINE HTTP $status";
        // 401, 403 and 429 can be fixed by the operator or by waiting; other 4xx never succeed.
        $retryable = $status === 0 || $status >= 500 || in_array($status, [401, 403, 429], true);
        if (!$retryable || (int) $n['attempts'] >= self::MAX_ATTEMPTS) {
            return self::fail($id, $error);
        }
        Database::run(
            "UPDATE notifications SET status = 'PENDING', next_attempt_at = ?, last_error = ? WHERE id = ?",
            [self::after(self::BACKOFF[(int) $n['attempts'] - 1]), $error, $id]
        );
        return 'retry';
    }

    private static function fail(int $id, string $error): string
    {
        Database::run("UPDATE notifications SET status = 'FAILED', next_attempt_at = NULL, last_error = ? WHERE id = ?", [$error, $id]);
        return 'failed';
    }

    /** The wording without the link. */
    public static function message(array $n): string
    {
        $queue = $n['queue_number'] === null ? '' : sprintf('Q-%03d', $n['queue_number']);
        $reason = $n['reason'] ? "\nเหตุผล: " . $n['reason'] : '';
        $text = match ($n['event_type']) {
            'ORDER_ACCEPTED' => "{$n['shop_name']} รับออเดอร์แล้ว\nคิวของคุณ $queue",
            'ORDER_READY' => "อาหารพร้อมรับแล้ว $queue\nเดินไปรับได้ที่ {$n['shop_name']}",
            'ORDER_REJECTED' => "{$n['shop_name']} ไม่สามารถรับออเดอร์ได้$reason",
            default => "{$n['shop_name']} ยกเลิกออเดอร์ของคุณ$reason",
        };
        // Refused after the customer paid online (D44): say plainly that the money comes back.
        $refund = in_array($n['event_type'], ['ORDER_REJECTED', 'ORDER_CANCELLED'], true) && !empty($n['refund_due'])
            ? "\nเงินที่จ่ายออนไลน์จะได้รับคืน ร้านคืนผ่าน Stripe ติดต่อร้านถ้ายังไม่ได้รับ" : '';
        return "$text$refund\nออเดอร์ {$n['public_order_no']}";
    }

    private static function text(array $n): string
    {
        $liff = Env::get('LIFF_ID');
        $base = $liff !== '' ? "https://liff.line.me/$liff" : rtrim(Env::get('APP_URL'), '/') . '/customer/';
        return self::message($n) . "\n$base#/order/{$n['order_id']}";
    }

    /** The same notification always uses the same UUID, so LINE drops duplicates. */
    private static function retryKey(array $n): string
    {
        $h = hash('sha256', Env::get('APP_URL') . '|' . $n['dedupe_key'] . '|' . $n['created_at']);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    private static function after(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', Clock::timestamp() + $seconds);
    }
}
