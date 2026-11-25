<?php
declare(strict_types=1);

namespace App\Services;

use App\Identity\Identity;
use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Session;

final class Auth
{
    private const ACCOUNT_TABLES = ['merchant' => 'merchant_accounts', 'admin' => 'admin_accounts'];

    /**
     * The signed-in user of a realm. State is re-read from the database on every request, so
     * deactivation, role removal and password changes take effect immediately.
     *
     * @return array{id: int, display_name: string, avatar_url: ?string, realm: string, csrf: string}
     */
    public static function user(Request $request, string $realm): array
    {
        $session = Session::read($realm);
        $user = $session === null ? null : Database::row(
            'SELECT u.id, u.display_name, u.avatar_url FROM users u
             JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.name = ?
             WHERE u.id = ? AND u.active = 1',
            [$realm, $session['user_id']]
        );
        if ($user !== null && $realm !== 'customer') {
            $account = self::account($realm, 'user_id', $user['id']);
            if ($account === null || !hash_equals((string) $session['credential'], self::fingerprint($account))) {
                $user = null;
            }
        }
        if ($user === null) {
            throw new HttpError(401, 'UNAUTHENTICATED', 'กรุณาเข้าสู่ระบบ');
        }
        if ($request->isMutation()) {
            self::checkSameOrigin($request);
            if (!hash_equals($session['csrf'], $request->header('X-CSRF-Token'))) {
                throw new HttpError(403, 'CSRF_FAILED', 'เซสชันไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
            }
        }
        return ['id' => (int) $user['id']] + $user + ['realm' => $realm, 'csrf' => $session['csrf']];
    }

    /** Rejects browser requests coming from another site. */
    public static function checkSameOrigin(Request $request): void
    {
        $origin = $request->header('Origin');
        if ($origin !== '') {
            $parts = parse_url($origin);
            $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $own = preg_replace('/:(80|443)$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
            if (strcasecmp($host, $own) !== 0) {
                throw new HttpError(403, 'CROSS_ORIGIN', 'ไม่อนุญาตคำขอจากเว็บไซต์อื่น');
            }
        }
    }

    public static function checkJson(Request $request): void
    {
        self::checkSameOrigin($request);
        if ($request->contentType() !== 'application/json') {
            throw new HttpError(415, 'JSON_REQUIRED', 'ต้องส่งข้อมูลแบบ JSON');
        }
    }

    public static function loginStaff(string $realm, string $login, string $password): array
    {
        $account = self::account($realm, 'login_name', $login);
        // Always verify something, so unknown logins take as long as known ones.
        $hash = $account['password_hash'] ?? '$2y$10$wHQ0dQ1yq8z0wV1J9yq0UeQ8f6m3n0J3Kf0xkYt5q1r9mG7y8p0yK';
        $valid = password_verify($password, $hash);
        $allowed = $account !== null && Database::value(
            'SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? AND u.active = 1 AND r.name = ?',
            [$account['user_id'], $realm]
        );
        if (!$valid || !$allowed) {
            throw new HttpError(401, 'LOGIN_FAILED', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
        }
        Session::destroy($realm);
        Audit::log((int) $account['user_id'], 'auth.login', $realm, (int) $account['user_id']);
        return Session::create($realm, ['user_id' => (int) $account['user_id'], 'credential' => self::fingerprint($account)]);
    }

    public static function loginCustomer(Identity $identity): array
    {
        $userId = self::upsertCustomer($identity);
        Session::destroy('customer');
        return Session::create('customer', ['user_id' => $userId]);
    }

    private static function upsertCustomer(Identity $identity): int
    {
        Database::run(
            'INSERT INTO users (line_user_id, display_name, avatar_url, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), avatar_url = VALUES(avatar_url)',
            [$identity->subject, mb_substr($identity->displayName, 0, 150), $identity->avatarUrl, Clock::now()]
        );
        $user = Database::row('SELECT id, active FROM users WHERE line_user_id = ?', [$identity->subject]);
        if (!$user['active']) {
            throw new HttpError(403, 'ACCOUNT_DISABLED', 'บัญชีนี้ถูกระงับ กรุณาติดต่อผู้ดูแล');
        }
        Database::run('INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$user['id'], 'customer']);
        return (int) $user['id'];
    }

    /** Throws 404 rather than 403, so other shops' ids are not revealed. */
    public static function requireShop(int $userId, int $shopId): void
    {
        if (!Database::value('SELECT 1 FROM shop_members WHERE shop_id = ? AND user_id = ?', [$shopId, $userId])) {
            throw HttpError::notFound('ไม่พบร้าน');
        }
    }

    private static function account(string $realm, string $column, mixed $value): ?array
    {
        $table = self::ACCOUNT_TABLES[$realm];
        return Database::row("SELECT user_id, password_hash FROM `$table` WHERE `$column` = ? AND active = 1 AND password_hash IS NOT NULL", [$value]);
    }

    private static function fingerprint(array $account): string
    {
        return hash('sha256', $account['user_id'] . '|' . $account['password_hash']);
    }
}
