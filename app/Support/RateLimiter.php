<?php
declare(strict_types=1);

namespace App\Support;

/** Fixed-window counter kept in the database. */
final class RateLimiter
{
    public static function hit(string $name, string $subject, int $limit, int $seconds): void
    {
        if (Env::isDevelopment()) {
            $limit *= max(1, (int) Env::get('RATE_LIMIT_MULTIPLIER', '1'));
        }
        $now = time();
        $window = intdiv($now, $seconds) * $seconds;
        $bucket = hash('sha256', $name . '|' . $subject);
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO rate_limits (bucket, window_start) VALUES (?, ?) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$bucket, $window]);
        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$bucket, $window]);
        if ((int) $stmt->fetchColumn() > $limit) {
            header('Retry-After: ' . ($window + $seconds - $now));
            throw new HttpError(429, 'RATE_LIMITED', 'ทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่');
        }
        if (random_int(1, 500) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);
        }
    }
}
