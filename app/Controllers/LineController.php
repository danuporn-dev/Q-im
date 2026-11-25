<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Identity\LineIdentity;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;
use App\Support\Request;

final class LineController
{
    public static function login(Request $q): array
    {
        return AuthController::customer($q, new LineIdentity(), 'id_token');
    }

    /** The signature covers the raw body, so it is checked before anything is parsed. */
    public static function webhook(Request $q): array
    {
        $secret = Env::get('LINE_CHANNEL_SECRET');
        if ($secret === '') {
            throw new HttpError(503, 'LINE_NOT_CONFIGURED', 'LINE is not configured');
        }
        if (strlen($q->raw()) > 1048576) {
            throw new HttpError(413, 'TOO_LARGE', 'Body too large');
        }
        $expected = base64_encode(hash_hmac('sha256', $q->raw(), $secret, true));
        if (!hash_equals($expected, $q->header('X-Line-Signature'))) {
            throw new HttpError(401, 'BAD_SIGNATURE', 'Invalid signature');
        }
        $body = json_decode($q->raw(), true);
        foreach (is_array($body['events'] ?? null) ? $body['events'] : [] as $event) {
            if (is_array($event)) {
                self::handle($event);
            }
        }
        return [['ok' => true], 200];
    }

    private static function handle(array $event): void
    {
        $id = $event['webhookEventId'] ?? '';
        $type = $event['type'] ?? '';
        $user = $event['source']['userId'] ?? '';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || !in_array($type, ['follow', 'unfollow'], true) || !is_string($user)) {
            return;
        }
        Database::tx(static function () use ($id, $type, $user, $event): void {
            if (Database::run('INSERT IGNORE INTO line_webhook_events (event_id, received_at) VALUES (?, ?)', [$id, Clock::now()]) === 0) {
                return; // redelivery
            }
            // Events can arrive out of order; only a newer one may change the state.
            $at = max(0, (int) ($event['timestamp'] ?? 0));
            Database::run(
                'UPDATE users SET line_friend = ?, line_friend_at = ? WHERE line_user_id = ? AND (line_friend_at IS NULL OR line_friend_at < ?)',
                [$type === 'follow' ? 1 : 0, $at, $user, $at]
            );
        });
    }
}
