<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;
use App\Support\Input;
use PDOException;

/** Admin management. Admins see orders but never change order status. */
final class Admin
{
    public static function foodCourts(): array
    {
        return Database::rows('SELECT f.id, f.name, f.location, f.active, COUNT(s.id) AS shops FROM food_courts f LEFT JOIN shops s ON s.food_court_id = f.id GROUP BY f.id, f.name, f.location, f.active ORDER BY f.name');
    }

    public static function saveFoodCourt(int $adminId, ?int $id, array $in): array
    {
        if ($id !== null && !Database::value('SELECT 1 FROM food_courts WHERE id = ?', [$id])) {
            throw HttpError::notFound('ไม่พบโรงอาหาร');
        }
        $row = [];
        if ($id === null || array_key_exists('name', $in)) {
            $row['name'] = Input::text($in, 'name', 'ชื่อโรงอาหาร', 150);
        }
        if (array_key_exists('location', $in)) {
            $row['location'] = Input::optionalText($in, 'location', 'ที่ตั้ง', 255);
        }
        if (array_key_exists('active', $in)) {
            $row['active'] = Input::bool($in, 'active', 'สถานะ');
        }
        return Database::tx(static function () use ($adminId, $id, $row): array {
            $id === null ? $id = Database::insert('food_courts', $row) : Database::update('food_courts', $id, $row);
            Audit::log($adminId, 'food_court.save', 'food_court', $id, $row);
            return Database::row('SELECT id, name, location, active FROM food_courts WHERE id = ?', [$id]);
        });
    }

    /** @return array{0: list<array>, 1: int} */
    public static function shops(string $q, int $limit, int $offset): array
    {
        $like = self::like($q);
        $rows = Database::rows(
            "SELECT s.id, s.name, s.food_court_id, f.name AS food_court, s.accepting_orders, s.active,
                    (SELECT COUNT(*) FROM orders o WHERE o.shop_id = s.id AND o.status IN " . Orders::ACTIVE . ") AS active_orders
             FROM shops s JOIN food_courts f ON f.id = s.food_court_id WHERE s.name LIKE ? ORDER BY s.active DESC, s.name, s.id LIMIT $limit OFFSET $offset",
            [$like]
        );
        $members = [];
        if ($rows !== []) {
            foreach (Database::rows('SELECT m.shop_id, u.id, u.display_name, a.login_name FROM shop_members m JOIN users u ON u.id = m.user_id LEFT JOIN merchant_accounts a ON a.user_id = u.id WHERE m.shop_id IN (' . implode(',', array_column($rows, 'id')) . ') ORDER BY u.display_name') as $m) {
                $shopId = $m['shop_id'];
                unset($m['shop_id']);
                $members[$shopId][] = $m;
            }
        }
        $overrides = $rows === [] ? [] : array_column(Database::rows(
            "SELECT o.shop_id, COUNT(*) AS n FROM audit_logs a JOIN orders o ON o.id = a.entity_id
             WHERE a.entity_type = 'order' AND a.action = ? AND o.shop_id IN (" . implode(',', array_column($rows, 'id')) . ') GROUP BY o.shop_id',
            [OrderFlow::PICKUP_OVERRIDE]
        ), 'n', 'shop_id');
        foreach ($rows as &$row) {
            $row['members'] = $members[$row['id']] ?? [];
            $row['pickup_overrides'] = (int) ($overrides[$row['id']] ?? 0);
        }
        return [$rows, (int) Database::value('SELECT COUNT(*) FROM shops s WHERE s.name LIKE ?', [$like])];
    }

    public static function saveShop(int $adminId, ?int $id, array $in): array
    {
        if ($id !== null && !Database::value('SELECT 1 FROM shops WHERE id = ?', [$id])) {
            throw HttpError::notFound('ไม่พบร้าน');
        }
        $row = [];
        if ($id === null || array_key_exists('name', $in)) {
            $row['name'] = Input::text($in, 'name', 'ชื่อร้าน', 150);
        }
        if ($id === null || array_key_exists('food_court_id', $in)) {
            $row['food_court_id'] = Input::int($in, 'food_court_id', 'โรงอาหาร', 1, PHP_INT_MAX);
            if (!Database::value('SELECT 1 FROM food_courts WHERE id = ?', [$row['food_court_id']])) {
                throw HttpError::invalid('ไม่พบโรงอาหาร');
            }
        }
        if (array_key_exists('active', $in)) {
            $row['active'] = Input::bool($in, 'active', 'สถานะ');
        }
        return Database::tx(static function () use ($adminId, $id, $row): array {
            if ($id === null) {
                $id = Database::insert('shops', $row);
                Database::insert('shop_settings', ['shop_id' => $id]);
            } else {
                Database::update('shops', $id, $row);
            }
            Audit::log($adminId, 'shop.save', 'shop', $id, $row);
            return Database::row('SELECT id, name, food_court_id, active FROM shops WHERE id = ?', [$id]);
        });
    }

    public static function addMember(int $adminId, int $shopId, array $in): void
    {
        $userId = isset($in['login'])
            ? (int) Database::value('SELECT user_id FROM merchant_accounts WHERE login_name = ?', [Input::text($in, 'login', 'ชื่อผู้ใช้', 100)])
            : Input::int($in, 'user_id', 'ผู้ใช้', 1, PHP_INT_MAX);
        if (!Database::value('SELECT 1 FROM shops WHERE id = ?', [$shopId])) {
            throw HttpError::notFound('ไม่พบร้าน');
        }
        if (!Database::value("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = 'merchant'", [$userId])) {
            throw HttpError::invalid('ผู้ใช้นี้ไม่มีสิทธิ์ร้านค้า');
        }
        Database::tx(static function () use ($adminId, $shopId, $userId): void {
            if (Database::run('INSERT IGNORE INTO shop_members (shop_id, user_id) VALUES (?, ?)', [$shopId, $userId]) > 0) {
                Audit::log($adminId, 'shop.member_add', 'shop', $shopId, ['user_id' => $userId]);
            }
        });
    }

    public static function removeMember(int $adminId, int $shopId, int $userId): void
    {
        Database::tx(static function () use ($adminId, $shopId, $userId): void {
            if (Database::run('DELETE FROM shop_members WHERE shop_id = ? AND user_id = ?', [$shopId, $userId]) === 0) {
                throw HttpError::notFound('ไม่พบสมาชิกร้าน');
            }
            Audit::log($adminId, 'shop.member_remove', 'shop', $shopId, ['user_id' => $userId]);
        });
    }

    /** Creates a merchant or admin with a local login. */
    public static function createStaff(int $adminId, array $in): array
    {
        $role = $in['role'] ?? '';
        if (!in_array($role, ['merchant', 'admin'], true)) {
            throw HttpError::invalid('ประเภทบัญชีไม่ถูกต้อง');
        }
        $name = Input::text($in, 'display_name', 'ชื่อ', 150);
        $login = mb_strtolower(Input::text($in, 'login', 'ชื่อผู้ใช้', 100, 4));
        if (!preg_match('/^[a-z0-9._@-]+$/D', $login)) {
            throw HttpError::invalid('ชื่อผู้ใช้ใช้ได้เฉพาะ a-z 0-9 . _ @ -');
        }
        $hash = password_hash(self::password($in), PASSWORD_DEFAULT);
        try {
            $id = Database::tx(static function () use ($adminId, $role, $name, $login, $hash): int {
                $id = Database::insert('users', ['display_name' => $name]);
                Database::insert($role . '_accounts', ['user_id' => $id, 'login_name' => $login, 'password_hash' => $hash]);
                Database::run('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
                Audit::log($adminId, 'user.create', 'user', $id, ['role' => $role, 'login' => $login]);
                return $id;
            });
        } catch (PDOException $e) {
            throw Database::isDuplicate($e) ? HttpError::conflict('LOGIN_TAKEN', 'ชื่อผู้ใช้นี้มีแล้ว') : $e;
        }
        return ['id' => $id, 'display_name' => $name, 'login_name' => $login];
    }

    /** @return array{0: list<array>, 1: int} */
    public static function users(string $q, string $role, int $limit, int $offset): array
    {
        $where = '(u.display_name LIKE ? OR ma.login_name LIKE ? OR aa.login_name LIKE ?)';
        $params = array_fill(0, 3, self::like($q));
        if (in_array($role, ['customer', 'merchant', 'admin'], true)) {
            $where .= ' AND EXISTS (SELECT 1 FROM user_roles x JOIN roles y ON y.id = x.role_id WHERE x.user_id = u.id AND y.name = ?)';
            $params[] = $role;
        }
        $from = "FROM users u LEFT JOIN merchant_accounts ma ON ma.user_id = u.id LEFT JOIN admin_accounts aa ON aa.user_id = u.id WHERE $where";
        $rows = Database::rows(
            "SELECT u.id, u.display_name, u.active, u.created_at, u.line_user_id IS NOT NULL AS has_line, COALESCE(ma.login_name, aa.login_name) AS login_name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
             $from ORDER BY u.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        foreach ($rows as &$row) {
            $row['roles'] = $row['roles'] === null ? [] : explode(',', $row['roles']);
            $row['active'] = (bool) $row['active'];
            $row['created_at'] = Clock::iso($row['created_at']);
        }
        return [$rows, (int) Database::value("SELECT COUNT(*) $from", $params)];
    }

    public static function updateUser(int $adminId, int $userId, array $in): void
    {
        $roles = null;
        if (array_key_exists('roles', $in)) {
            $roles = array_values(array_unique(array_filter(Input::list($in, 'roles', 'สิทธิ์', 3), 'is_string')));
            if (array_diff($roles, ['customer', 'merchant', 'admin']) !== [] || count($roles) !== count($in['roles'])) {
                throw HttpError::invalid('สิทธิ์ไม่ถูกต้อง');
            }
        }
        $active = array_key_exists('active', $in) ? Input::bool($in, 'active', 'สถานะ') : null;
        $hash = array_key_exists('password', $in) ? password_hash(self::password($in), PASSWORD_DEFAULT) : null;
        Database::tx(static function () use ($adminId, $userId, $roles, $active, $hash): void {
            // Serializes role and status changes, so two admins cannot disable each other at once.
            Database::row("SELECT id FROM roles WHERE name = 'admin' FOR UPDATE");
            if (!Database::value("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.id = ? AND u.active = 1 AND r.name = 'admin'", [$adminId])) {
                throw new HttpError(401, 'UNAUTHENTICATED', 'กรุณาเข้าสู่ระบบ');
            }
            if (Database::row('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === null) {
                throw HttpError::notFound('ไม่พบผู้ใช้');
            }
            // An admin can never lock themselves out, so one working admin always remains.
            if ($userId === $adminId && ($active === 0 || ($roles !== null && !in_array('admin', $roles, true)))) {
                throw HttpError::conflict('SELF_LOCKOUT', 'ปิดบัญชีหรือถอดสิทธิ์ผู้ดูแลของตัวเองไม่ได้');
            }
            $changed = [];
            if ($active !== null) {
                Database::update('users', $userId, ['active' => $active]);
                $changed['active'] = (bool) $active;
            }
            if ($roles !== null) {
                foreach (['merchant', 'admin'] as $staff) {
                    if (in_array($staff, $roles, true) && !Database::value("SELECT 1 FROM {$staff}_accounts WHERE user_id = ?", [$userId])) {
                        throw HttpError::invalid('ผู้ใช้นี้ไม่มีบัญชี login ของสิทธิ์ที่เลือก');
                    }
                }
                Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
                foreach ($roles as $role) {
                    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$userId, $role]);
                }
                $changed['roles'] = $roles;
            }
            if ($hash !== null) {
                $rows = 0;
                foreach (['merchant', 'admin'] as $staff) {
                    $rows += Database::run("UPDATE {$staff}_accounts SET password_hash = ? WHERE user_id = ?", [$hash, $userId]);
                }
                if ($rows === 0) {
                    throw HttpError::invalid('ผู้ใช้นี้ไม่มีรหัสผ่านในระบบ');
                }
                $changed['password'] = 'reset';
            }
            Audit::log($adminId, 'user.update', 'user', $userId, $changed);
        });
    }

    /** @return array{0: list<array>, 1: int} */
    public static function orders(string $q, string $status, int $shopId, int $limit, int $offset): array
    {
        $where = 'o.public_order_no LIKE ?';
        $params = [self::like($q)];
        if (preg_match('/^[A-Z]{4,10}$/D', $status)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        if ($shopId > 0) {
            $where .= ' AND o.shop_id = ?';
            $params[] = $shopId;
        }
        return Orders::page($where, $params, 'o.id DESC', $limit, $offset);
    }

    public static function order(int $id): array
    {
        [$rows] = Orders::page('o.id = ?', [$id], 'o.id', 1, 0);
        $order = $rows[0] ?? throw HttpError::notFound('ไม่พบออเดอร์');
        $order['logs'] = array_map(
            static fn (array $l): array => ['created_at' => Clock::iso($l['created_at'])] + $l,
            Database::rows('SELECT l.from_status, l.to_status, l.reason, l.created_at, u.display_name AS actor FROM order_status_logs l LEFT JOIN users u ON u.id = l.actor_user_id WHERE l.order_id = ? ORDER BY l.id', [$id])
        );
        $order['notifications'] = Database::rows('SELECT event_type, status, attempts, last_error FROM notifications WHERE order_id = ? ORDER BY id', [$id]);
        return $order;
    }

    /** @return array{0: list<array>, 1: int} */
    public static function auditLogs(string $q, string $action, int $limit, int $offset): array
    {
        $from = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE (a.action LIKE ? OR u.display_name LIKE ?)';
        $params = [self::like($q), self::like($q)];
        if ($action !== '') {
            $from .= ' AND a.action = ?';
            $params[] = $action;
        }
        $rows = Database::rows("SELECT a.id, a.action, a.entity_type, a.entity_id, a.safe_metadata, a.created_at, u.display_name AS actor $from ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $params);
        foreach ($rows as &$row) {
            $row['created_at'] = Clock::iso($row['created_at']);
        }
        return [$rows, (int) Database::value("SELECT COUNT(*) $from", $params)];
    }

    public static function overview(): array
    {
        $counts = [];
        foreach (Database::rows('SELECT status, COUNT(*) AS n FROM orders WHERE status IN ' . Orders::ACTIVE . ' GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return [
            'active_orders' => $counts,
            'today' => Reports::summary('', ''),
            'shops_open' => (int) Database::value('SELECT COUNT(*) FROM shops WHERE active = 1 AND accepting_orders = 1'),
            'shops_active' => (int) Database::value('SELECT COUNT(*) FROM shops WHERE active = 1'),
            'customers' => (int) Database::value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'customer'"),
            'ready_too_long' => (int) Database::value("SELECT COUNT(*) FROM orders WHERE status = 'READY' AND ready_at < ?", [gmdate('Y-m-d H:i:s', Clock::timestamp() - OrderFlow::readyWaitMinutes() * 60)]),
            'ready_alert_minutes' => OrderFlow::readyWaitMinutes(),
        ];
    }

    /** Read-only; configuration itself lives in the server environment. Values are never returned. */
    public static function settings(): array
    {
        $notifications = [];
        foreach (Database::rows('SELECT status, COUNT(*) AS n FROM notifications GROUP BY status') as $row) {
            $notifications[$row['status']] = (int) $row['n'];
        }
        return [
            'environment' => Env::isDemoPublic() ? 'demo' : (Env::isDevelopment() ? 'development' : 'production'),
            'timezone' => 'Asia/Bangkok',
            'line_login' => Env::get('LINE_LOGIN_CHANNEL_ID') !== '' && Env::get('LIFF_ID') !== '',
            'line_messaging' => Env::get('LINE_CHANNEL_ACCESS_TOKEN') !== '',
            'line_webhook' => Env::get('LINE_CHANNEL_SECRET') !== '',
            'pickup_key' => PickupTokens::keyStatus(),
            'notifications' => $notifications,
        ];
    }

    private static function password(array $in): string
    {
        $password = $in['password'] ?? null;
        // bcrypt reads at most 72 bytes.
        if (!is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72) {
            throw HttpError::invalid('รหัสผ่านต้องมี 12 ตัวอักษรขึ้นไป และไม่เกิน 72 ไบต์');
        }
        return $password;
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes(mb_substr($q, 0, 100), '\\%_') . '%';
    }
}
