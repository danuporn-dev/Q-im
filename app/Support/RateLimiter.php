<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Fixed-window counters kept in the database. A counter belongs to one window, so it ends with it;
 * rows of finished windows are purged while counting.
 */
final class RateLimiter
{
    public static function hit(string $name, string $subject, int $limit, int $seconds): void
    {
        if (Env::isDevelopment()) {
            // Test hook: looser limits.
            $limit *= max(1, (int) Env::get('RATE_LIMIT_MULTIPLIER', '1'));
        }
        $now = Clock::timestamp();
        $window = intdiv($now, $seconds) * $seconds;
        $bucket = self::bucket($name, $subject);
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO rate_limits (bucket, window_start) VALUES (?, ?) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$bucket, $window]);
        if (random_int(1, 100) === 1) {
            // No window is longer than an hour; the margin keeps test clocks from deleting live rows.
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ? LIMIT 1000')->execute([min($now, time()) - 7200]);
        }
        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$bucket, $window]);
        if ((int) $stmt->fetchColumn() > $limit) {
            header('Retry-After: ' . ($window + $seconds - $now));
            throw new HttpError(429, 'RATE_LIMITED', 'ทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่');
        }
    }

    /** Takes one hit back, for limits that count failures only. Counting first keeps parallel attempts from slipping through. */
    public static function refund(string $name, string $subject, int $seconds): void
    {
        $window = intdiv(Clock::timestamp(), $seconds) * $seconds;
        Database::run('UPDATE rate_limits SET hits = hits - 1 WHERE bucket = ? AND window_start = ? AND hits > 0', [self::bucket($name, $subject), $window]);
    }

    /** Forgets the current and earlier windows, for example after a successful login. */
    public static function clear(string $name, string $subject): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = ?', [self::bucket($name, $subject)]);
    }

    private static function bucket(string $name, string $subject): string
    {
        // Test hook: each test run has its own counters.
        $scope = Env::isDevelopment() ? Env::get('RATE_LIMIT_SCOPE') : '';
        return hash('sha256', "$name|$subject|$scope");
    }
}
