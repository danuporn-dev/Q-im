<?php
declare(strict_types=1);

namespace App\Support;

/** One cookie per realm, so a customer, merchant and admin session never share privileges. */
final class Session
{
    public const REALMS = ['customer', 'merchant', 'admin'];
    private const LIFETIME = ['customer' => 7 * 86400, 'merchant' => 12 * 3600, 'admin' => 12 * 3600];

    /** @return array<string, mixed>|null */
    public static function read(string $realm): ?array
    {
        if (!isset($_COOKIE['qim_' . $realm])) {
            return null;
        }
        self::configure($realm);
        session_start(['read_and_close' => true]);
        $data = $_SESSION;
        if (($data['realm'] ?? '') !== $realm || ($data['expires_at'] ?? 0) < time()) {
            return null;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function create(string $realm, array $data): array
    {
        self::configure($realm);
        session_start();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION = $data + [
            'realm' => $realm,
            'csrf' => bin2hex(random_bytes(32)),
            'expires_at' => time() + self::LIFETIME[$realm],
        ];
        $data = $_SESSION;
        session_write_close();
        return $data;
    }

    public static function destroy(string $realm): void
    {
        if (!isset($_COOKIE['qim_' . $realm])) {
            return;
        }
        self::configure($realm);
        session_start();
        $_SESSION = [];
        session_destroy();
        setcookie('qim_' . $realm, '', ['expires' => 1] + self::cookie());
    }

    private static function configure(string $realm): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name('qim_' . $realm);
        $sent = $_COOKIE['qim_' . $realm] ?? '';
        session_id(is_string($sent) && preg_match('/^[A-Za-z0-9,-]{20,128}$/D', $sent) ? $sent : (string) session_create_id());
        session_save_path(dirname(__DIR__, 2) . '/storage/sessions');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME['customer']);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
        session_set_cookie_params(['lifetime' => self::LIFETIME[$realm]] + self::cookie());
        session_cache_limiter('');
    }

    private static function cookie(): array
    {
        return [
            'path' => '/',
            'secure' => Env::isProduction() || ($_SERVER['HTTPS'] ?? 'off') !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
