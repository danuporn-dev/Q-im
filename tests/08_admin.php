<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Env;

$a = '/api/v1';
T::group('Phase 8 admin access');
[$adminId, $login] = Fixture::staff('admin', Fixture::run());
$admin = Fixture::login($base, 'admin', $login);
$s = Fixture::shop('adm');
$m = Fixture::login($base, 'merchant', $s['login']);
$c = Fixture::customer($base, 'adm');
$reads = ['overview', 'settings', 'food-courts', 'reports', 'shops', 'users', 'orders', 'audit-logs'];
foreach ($reads as $path) {
    T::status(200, $admin->get("$a/admin/$path"), "admin reads $path");
    T::status(401, $m->get("$a/admin/$path"), "merchant refused: $path");
    T::status(401, $c->get("$a/admin/$path"), "customer refused: $path");
}
T::status(401, $m->post("$a/admin/users", ['role' => 'admin', 'display_name' => 'x', 'login' => 'evil_admin', 'password' => 'x-x-x-x-x-x-x']), 'merchant cannot create admins');
T::status(401, $m->patch("$a/admin/users/{$s['user']}", ['roles' => ['admin']]), 'no self promotion');
$raw = $admin->get("$a/admin/settings")['raw'] . $admin->get("$a/admin/users")['raw'];
T::ok(!str_contains($raw, 'password') && !str_contains($raw, Env::get('SEED_DEMO_PASSWORD')), 'no secrets in responses');

T::group('Shops, membership, accounts');
$r = $admin->post("$a/admin/food-courts", ['name' => 'TEST court ' . Fixture::run()]);
T::status(201, $r, 'create food court');
$court = $r['body']['data']['id'];
$r = $admin->post("$a/admin/shops", ['name' => 'TEST admin shop ' . Fixture::run(), 'food_court_id' => $court, 'active' => false]);
T::status(201, $r, 'create shop, not approved yet');
$shop = $r['body']['data']['id'];
T::status(404, $c->get("$a/shops/$shop"), 'unapproved shop is hidden');
$newLogin = 'test_m_' . strtolower(Fixture::run());
$body = ['role' => 'merchant', 'display_name' => 'TEST แม่ค้า', 'login' => $newLogin, 'password' => Env::get('SEED_DEMO_PASSWORD')];
T::status(422, $admin->post("$a/admin/users", ['password' => 'short'] + $body), 'weak password');
$r = $admin->post("$a/admin/users", $body);
T::status(201, $r, 'create merchant account');
$uid = $r['body']['data']['id'];
T::status(409, $admin->post("$a/admin/users", $body), 'duplicate login', 'LOGIN_TAKEN');
T::ok(str_starts_with((string) Database::value('SELECT password_hash FROM merchant_accounts WHERE user_id = ?', [$uid]), '$2y$'), 'password stored as a hash');
$mm = Fixture::login($base, 'merchant', $newLogin);
T::status(404, $mm->get("$a/merchant/shops/$shop/settings"), 'no membership, no access');
T::status(200, $admin->post("$a/admin/shops/$shop/members", ['user_id' => $uid]), 'add member');
T::status(200, $admin->post("$a/admin/shops/$shop/members", ['login' => $newLogin]), 'adding again by login is safe');
T::status(200, $mm->get("$a/merchant/shops/$shop/settings"), 'member has access');
T::status(422, $admin->post("$a/admin/shops/$shop/members", ['user_id' => $c->get("$a/me")['body']['data']['id']]), 'a customer cannot be a member');
T::status(200, $admin->patch("$a/admin/shops/$shop", ['active' => true]), 'approve shop');
T::status(200, $c->get("$a/shops/$shop"), 'approved shop is visible');
T::status(200, $admin->patch("$a/admin/food-courts/$court", ['active' => false]), 'close food court');
T::status(404, $c->get("$a/shops/$shop"), 'shops of a closed food court are hidden');
T::status(200, $admin->call('DELETE', "$a/admin/shops/$shop/members/$uid", []), 'remove member');
T::status(404, $mm->get("$a/merchant/shops/$shop/settings"), 'access ends at once');

T::group('Users and roles');
T::status(200, $admin->patch("$a/admin/users/$uid", ['roles' => []]), 'remove roles');
T::status(401, $mm->get("$a/me?realm=merchant"), 'role removal ends the session');
T::status(422, $admin->patch("$a/admin/users/$uid", ['roles' => ['admin']]), 'admin role needs an admin account');
T::status(422, $admin->patch("$a/admin/users/$uid", ['roles' => ['root']]), 'unknown role');
T::status(409, $admin->patch("$a/admin/users/$adminId", ['active' => false]), 'cannot disable self', 'SELF_LOCKOUT');
T::status(409, $admin->patch("$a/admin/users/$adminId", ['roles' => ['customer']]), 'cannot drop own admin role', 'SELF_LOCKOUT');
T::status(200, $admin->patch("$a/admin/users/{$s['user']}", ['password' => 'a-new-password-123']), 'reset password');
T::status(401, $m->get("$a/me?realm=merchant"), 'reset ends sessions');
T::ok((int) Database::value('SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ?', [$adminId]) >= 8, 'admin actions audited');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE safe_metadata LIKE '%a-new-password%' OR safe_metadata LIKE ?", ['%' . Env::get('SEED_DEMO_PASSWORD') . '%']), 'no passwords in audit logs');

T::group('Order monitor');
[, $o] = Fixture::order($base, 'mon', $s);
$r = $admin->get("$a/admin/orders/$o");
T::eq(['PENDING', 1], [$r['body']['data']['status'] ?? null, count($r['body']['data']['logs'] ?? [])], 'admin inspects an order and its log');
T::status(404, $admin->post("$a/admin/orders/$o/accept"), 'admin has no status change route');
T::status(401, $admin->post("$a/merchant/orders/$o/accept"), 'admin session is not a merchant session');
T::eq(1, $admin->get("$a/admin/orders?q=" . Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$o]))['body']['meta']['total'], 'search by order number');
T::status(200, $admin->get("$a/admin/orders?status=" . rawurlencode("X' OR 1=1") . '&shop_id=abc&page=-5'), 'odd filters are harmless');
$rep = $admin->get("$a/admin/reports")['body']['data'];
$sum = Database::value("SELECT COALESCE(SUM(p.amount), 0.00) FROM payments p WHERE p.status = 'PAID' AND p.paid_at >= ?", [\App\Support\Clock::utcRange($rep['from'], $rep['to'])[0]]);
T::eq([$sum, $rep['sales']], [$rep['sales'], $sum], 'report total matches payments');
