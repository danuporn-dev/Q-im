<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Env;

$a = '/api/v1';
$s = Fixture::shop('sec');
$other = Fixture::shop('sec2');
$m = Fixture::login($base, 'merchant', $s['login']);
[$c, $o] = Fixture::order($base, 'sec', $s);

T::group('T21 CSRF on every mutation route');
[, $login] = Fixture::staff('admin', 'sec_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $login);
$routes = [
    [$c, 'PUT', 'cart'], [$c, 'POST', 'orders'], [$c, 'POST', "orders/$o/cancel"], [$c, 'POST', 'auth/logout'],
    [$m, 'PATCH', "merchant/shops/{$s['shop']}/settings"], [$m, 'POST', "merchant/shops/{$s['shop']}/menus"], [$m, 'PATCH', "merchant/menus/{$s['menu']}"],
    [$m, 'POST', "merchant/shops/{$s['shop']}/categories"], [$m, 'POST', "merchant/shops/{$s['shop']}/images"],
    [$m, 'POST', "merchant/orders/$o/accept"], [$m, 'POST', "merchant/orders/$o/payment"], [$m, 'POST', "merchant/orders/$o/complete"],
    [$admin, 'POST', 'admin/shops'], [$admin, 'PATCH', "admin/shops/{$s['shop']}"], [$admin, 'POST', 'admin/users'], [$admin, 'PATCH', "admin/users/{$s['user']}"],
    [$admin, 'POST', "admin/shops/{$s['shop']}/members"], [$admin, 'DELETE', "admin/shops/{$s['shop']}/members/{$s['user']}"], [$admin, 'POST', 'admin/food-courts'],
];
foreach ($routes as [$client, $method, $path]) {
    $token = $client->csrf;
    foreach (['' => 'missing', str_repeat('a', 64) => 'wrong'] as $value => $label) {
        $client->csrf = (string) $value;
        T::status(403, $client->call($method, "$a/$path", []), "$label token $method " . preg_replace('/\d+/', 'N', $path), 'CSRF_FAILED');
    }
    $client->csrf = $token;
}
$token = $m->csrf;
$m->csrf = $c->csrf;
T::status(403, $m->post("$a/merchant/orders/$o/accept"), 'token of another session', 'CSRF_FAILED');
$m->csrf = $token;
T::eq('PENDING', Database::value('SELECT status FROM orders WHERE id = ?', [$o]), 'nothing happened');

T::group('T21 SQL injection and hostile input');
$evil = ["' OR '1'='1", "1; DROP TABLE orders;--", '" UNION SELECT password_hash FROM admin_accounts -- ', "\\", '%', '_', "\x00", "\xff\xfe", str_repeat('A', 5000), '<img src=x onerror=alert(1)>'];
foreach ($evil as $i => $text) {
    $e = rawurlencode($text);
    foreach (["shops?q=$e", "shops?category=$e", "shops?page=$e", "orders?scope=$e", "orders?page=$e", "admin/users?q=$e&role=$e", "admin/orders?q=$e&status=$e&shop_id=$e", "admin/audit-logs?q=$e", "admin/shops?q=$e", "merchant/shops/{$s['shop']}/orders?scope=$e"] as $path) {
        $client = str_starts_with($path, 'admin') ? $admin : (str_starts_with($path, 'merchant') ? $m : $c);
        $r = $client->get("$a/$path");
        if ($r['status'] !== 200 || str_contains($r['raw'], '$2y$')) {
            T::status(200, $r, "input $i on " . strtok($path, '?'));
        }
    }
}
T::ok(true, count($evil) . ' hostile strings on 10 query routes');
T::ok((int) Database::value('SELECT COUNT(*) FROM orders') > 0, 'orders table intact');
foreach (["$a/orders/9999999999999999999", "$a/orders/0", "$a/orders/-1", "$a/orders/1%27", "$a/orders/1e3", "$a/images/..%2f..%2f.env", "$a/shops?q[]=x"] as $path) {
    $r = $c->get($path);
    T::ok(in_array($r['status'], [200, 404], true) && !str_contains($r['raw'], 'DB_'), "odd path $path -> {$r['status']}");
}
foreach (['{', '[]', '"x"', 'null', '{"items":"x"}', '{"items":[1,2]}', '{"items":[{"menu_id":{"a":1},"quantity":1}],"shop_id":1}', '{"items":[{"menu_id":1,"quantity":1,"option_ids":{"a":1}}],"shop_id":1}', str_repeat('[', 100) . str_repeat(']', 100)] as $body) {
    $r = $c->call('PUT', "$a/cart", $body);
    T::ok(in_array($r['status'], [400, 422, 404, 409, 200], true), 'odd body ' . substr($body, 0, 30) . " -> {$r['status']}");
}
T::status(413, $c->call('PUT', "$a/cart", '{"x":"' . str_repeat('a', 300000) . '"}'), 'huge body', 'TOO_LARGE');
$r = $c->get("$a/orders/999999");
T::ok(!preg_match('/PDO|SQLSTATE|stack|\.php/i', $r['raw']), 'errors carry no internals');
T::ok(!preg_match('/^X-Powered-By/mi', $r['head']) && preg_match('/^Cache-Control: no-store/mi', $r['head']), 'API headers', $r['head']);

T::group('T21 XSS');
$xss = '<img src=x onerror=alert(1)>';
$cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1, 'note' => $xss]]]);
$r = $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']]);
T::eq($xss, $r['body']['data']['items'][0]['note'] ?? null, 'stored as plain data');
T::ok(str_contains($r['head'], 'application/json'), 'JSON content type');
foreach (['core', 'customer', 'merchant', 'admin', 'liff'] as $file) {
    $js = file_get_contents(dirname(__DIR__) . "/public/assets/js/$file.js");
    T::ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function/', $js), "$file.js has no HTML sinks");
}
foreach (['customer', 'merchant', 'admin'] as $page) {
    $html = file_get_contents(dirname(__DIR__) . "/public/$page/index.html");
    preg_match('/Content-Security-Policy" content="([^"]+)"/', $html, $csp);
    T::ok(isset($csp[1]) && !preg_match("/script-src[^;]*'unsafe|default-src[^;]*'unsafe/", $csp[1]), "$page has a CSP without unsafe scripts");
}

T::group('T14 T15 IDOR sweep');
$thief = Fixture::login($base, 'merchant', $other['login']);
$spy = Fixture::customer($base, 'sec_spy');
foreach (["merchant/orders/$o", "merchant/shops/{$s['shop']}/orders", "merchant/shops/{$s['shop']}/menus", "merchant/shops/{$s['shop']}/settings", "merchant/shops/{$s['shop']}/reports"] as $path) {
    T::status(404, $thief->get("$a/$path"), 'GET ' . preg_replace('/\d+/', 'N', $path));
}
foreach (['accept', 'reject', 'prepare', 'ready', 'cancel', 'payment', 'complete'] as $x) {
    T::status(404, $thief->post("$a/merchant/orders/$o/$x", ['reason' => 'x']), "POST $x");
}
T::status(404, $thief->post("$a/merchant/shops/{$s['shop']}/images"), 'upload to other shop');
T::status(404, $spy->get("$a/orders/$o"), 'other customer');

T::group('Sessions and production mode');
$prod = Servers::start(8181, ['APP_ENV' => 'production', 'LINE_API_BASE' => 'http://127.0.0.1:9', 'TEST_CLOCK_OFFSET' => '999999', 'DB_NAME' => Database::name()]);
$p = new Client($prod);
T::status(404, $p->post("$a/auth/dev", ['customer' => 'A']), 'no mock login');
$broken = Servers::start(8182, ['APP_ENV' => 'production', 'DB_PASSWORD' => 'definitely-wrong']);
$r = (new Client($broken))->get("$a/health");
T::status(500, $r, 'database failure', 'SERVER_ERROR');
T::ok(!preg_match('/PDO|SQLSTATE|root|denied|\.php/i', $r['raw']), 'no details in the response');
$log = file_get_contents(dirname(__DIR__) . '/storage/logs/app-' . gmdate('Y-m-d') . '.log');
T::ok(!str_contains($log, 'definitely-wrong') && !str_contains($log, Env::get('SEED_DEMO_PASSWORD')), 'no passwords in logs');
$limited = Servers::start(8183, ['RATE_LIMIT_MULTIPLIER' => '1']);
$x = Fixture::customer($limited, 'sec_rate');
$codes = [];
for ($i = 0; $i < 22; $i++) {
    $codes[] = $x->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => 'x'])['status'];
}
T::eq([409, 429], [$codes[0], end($codes)], 'checkout rate limit');
foreach (['/storage/logs/', '/app/bootstrap.php', '/config/database.php', '/.git/config', '/.env', '/scripts/seed.php', '/tests/run.php'] as $path) {
    $r = (new Client($base))->get($path);
    T::ok(!preg_match('/DB_|repositoryformat|<\?php|SQLSTATE/', $r['raw']), "not exposed: $path");
}

T::group('Database invariants');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o LEFT JOIN queues q ON q.order_id = o.id WHERE o.status IN ('ACCEPTED','PREPARING','READY','COMPLETED') AND q.id IS NULL"), 'every accepted order has a queue');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o JOIN queues q ON q.order_id = o.id WHERE o.status IN ('PENDING','REJECTED')"), 'no queue without acceptance');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.status = 'COMPLETED' AND p.status <> 'PAID'"), 'completed orders are paid');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.status IN ('CANCELLED','REJECTED') AND p.status = 'PAID'"), 'no paid order was cancelled');
T::eq(0, (int) Database::value('SELECT COUNT(*) FROM (SELECT o.id, o.total, SUM(i.quantity * (i.unit_price + COALESCE((SELECT SUM(x.price_delta_snapshot) FROM order_item_options x WHERE x.order_item_id = i.id), 0))) AS calc FROM orders o JOIN order_items i ON i.order_id = o.id GROUP BY o.id, o.total) t WHERE t.total <> t.calc'), 'totals equal their snapshots');
