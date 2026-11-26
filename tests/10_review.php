<?php
declare(strict_types=1);

// Regression tests of the post-MVP review: access matrix, rate limits, order transactions.

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\RateLimiter;
use App\Support\Router;

$a = '/api/v1';
$s = Fixture::shop('rev');
$s2 = Fixture::shop('rev2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[, $adminLogin] = Fixture::staff('admin', 'rev_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $adminLogin);
[$c, $o] = Fixture::order($base, 'rev', $s);
$category = Database::value('SELECT category_id FROM menus WHERE id = ?', [$s['menu']]);

T::group('T14 T15 access matrix: every registered route');
$router = new Router();
(require dirname(__DIR__) . '/app/routes.php')($router);
$routes = (new ReflectionProperty(Router::class, 'routes'))->getValue($router);
// Any route that is not listed here must demand a session of its realm.
$public = ['GET /health', 'GET /config', 'POST /auth/line', 'POST /line/webhook', 'POST /auth/dev', 'POST /auth/login', 'GET /shops', 'GET /categories', 'GET /shops/{id}', 'GET /shops/{id}/menus', 'GET /images/{key}', 'GET /images/{key}/thumb', 'GET /food-courts', 'GET /shops/{id}/reviews', 'POST /webhooks/stripe'];
$clients = ['customer' => $c, 'merchant' => $m, 'admin' => $admin];
$checked = 0;
$bad = [];
$seen = [];
foreach ($routes as [$method, $regex]) {
    $pattern = strtr(substr($regex, 2, -3), ['([1-9][0-9]{0,17})' => '{id}', '([0-9a-f]{32})' => '{key}']);
    $seen[] = "$method $pattern";
    if (in_array("$method $pattern", $public, true)) {
        continue;
    }
    $realm = str_starts_with($pattern, '/merchant') ? 'merchant' : (str_starts_with($pattern, '/admin') ? 'admin' : 'customer');
    $path = $a . str_replace('{id}', '1', $pattern);
    foreach ([null, 'customer', 'merchant', 'admin'] as $as) {
        // auth/logout and me choose their realm with a parameter, customer by default.
        if ($as === $realm) {
            continue;
        }
        $r = ($as === null ? new Client($base) : $clients[$as])->call($method, $path, $method === 'GET' ? null : []);
        $checked++;
        if ($r['status'] !== 401) {
            $bad[] = "$method $pattern as " . ($as ?? 'anonymous') . " -> {$r['status']}";
        }
    }
}
T::eq([], $bad, "$checked requests without the right realm are all 401");
T::eq([], array_values(array_diff($public, $seen)), 'the public list is current');

T::group('T14 T15 ownership: every route that carries an id');
$menuBody = ['name' => 'x', 'price' => '1'];
$foreign = [
    ['GET', "merchant/shops/{$s['shop']}/settings"], ['PATCH', "merchant/shops/{$s['shop']}/settings", ['accepting_orders' => false]],
    ['GET', "merchant/shops/{$s['shop']}/menus"], ['POST', "merchant/shops/{$s['shop']}/menus", $menuBody], ['PATCH', "merchant/menus/{$s['menu']}", $menuBody],
    ['POST', "merchant/shops/{$s['shop']}/categories", ['name' => 'x']], ['PATCH', "merchant/shops/{$s['shop']}/categories/$category", ['name' => 'x']],
    ['PATCH', "merchant/shops/{$s2['shop']}/categories/$category", ['name' => 'x']], ['POST', "merchant/shops/{$s['shop']}/images"],
    ['GET', "merchant/shops/{$s['shop']}/orders"], ['GET', "merchant/shops/{$s['shop']}/reports"], ['GET', "merchant/orders/$o"],
];
foreach (['accept', 'reject', 'prepare', 'ready', 'cancel', 'payment', 'complete'] as $x) {
    $foreign[] = ['POST', "merchant/orders/$o/$x", ['reason' => 'x', 'override_reason' => 'xxxx']];
}
foreach ($foreign as $f) {
    T::status(404, $m2->call($f[0], "$a/{$f[1]}", $f[2] ?? []), "other merchant: {$f[0]} " . preg_replace('/\d+/', 'N', $f[1]));
}
$spy = Fixture::customer($base, 'rev_spy');
T::status(404, $spy->get("$a/orders/$o"), 'other customer reads');
T::status(404, $spy->post("$a/orders/$o/cancel"), 'other customer cancels');
foreach ([['GET', "merchant/orders/%d"], ['POST', 'merchant/orders/%d/accept'], ['PATCH', 'merchant/menus/%d']] as [$method, $path]) {
    $mine = str_contains($path, 'menus') ? $s['menu'] : $o;
    T::eq($m2->call($method, "$a/" . sprintf($path, 999999999), $menuBody)['raw'], $m2->call($method, "$a/" . sprintf($path, $mine), $menuBody)['raw'], "existing and missing ids answer alike: $method $path");
}
T::eq('PENDING', Database::value('SELECT status FROM orders WHERE id = ?', [$o]), 'order untouched');
Database::run('DELETE FROM shop_members WHERE shop_id = ? AND user_id = ?', [$s['shop'], $s['user']]);
T::status(404, $m->post("$a/merchant/orders/$o/accept"), 'removed member loses the order at once');
Database::insert('shop_members', ['shop_id' => $s['shop'], 'user_id' => $s['user']]);

T::group('Privacy and durable role removal');
$raw = $m->get("$a/merchant/orders/$o")['body']['data'] + [];
T::ok(!array_key_exists('customer_name', $raw), 'the shop does not receive the customer name');
$no = Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$o]);
foreach (["merchant/orders/$o", "merchant/shops/{$s['shop']}/orders", "merchant/shops/{$s['shop']}/orders?scope=closed"] as $path) {
    T::ok(!str_contains($m->get("$a/$path")['raw'], $no), 'full order number hidden on ' . preg_replace('/\d+/', 'N', $path));
}
$uid = $c->get("$a/me")['body']['data']['id'];
T::status(200, $admin->patch("$a/admin/users/$uid", ['roles' => []]), 'admin removes the customer role');
T::status(401, $c->get("$a/orders"), 'session ends');
$again = new Client($base);
T::status(403, $again->post("$a/auth/dev", ['customer' => 'TEST_' . Fixture::run() . '_REV']), 'signing in again does not give the role back', 'ACCOUNT_DISABLED');
T::eq([], $again->cookies, 'no session');
T::status(200, $admin->patch("$a/admin/users/$uid", ['roles' => ['customer']]), 'admin restores the role');
T::status(200, $c->get("$a/orders"), 'access is back');

T::group('Two admins disabling each other');
$ports = [Servers::start(8201, ['RATE_LIMIT_MULTIPLIER' => '1000']), Servers::start(8202, ['RATE_LIMIT_MULTIPLIER' => '1000'])];
for ($i = 0; $i < 4; $i++) {
    [$u1, $l1] = Fixture::staff('admin', "x{$i}_" . Fixture::run());
    [$u2, $l2] = Fixture::staff('admin', "y{$i}_" . Fixture::run());
    $a1 = Fixture::login($base, 'admin', $l1);
    $a2 = Fixture::login($base, 'admin', $l2);
    $codes = array_column(parallel([[$a1, 'PATCH', "$a/admin/users/$u2", ['active' => false], $ports[0]], [$a2, 'PATCH', "$a/admin/users/$u1", ['active' => false], $ports[1]]]), 'status');
    sort($codes);
    T::eq([[200, 401], 1], [$codes, (int) Database::value("SELECT SUM(active) FROM users WHERE id IN ($u1, $u2)")], 'exactly one survives');
}

T::group('T05 price limits');
Database::update('menus', $s['menu'], ['price' => '999999.99']);
$big = Fixture::customer($base, 'rev_big');
$cart = $big->put("$a/cart", ['shop_id' => $s['shop'], 'items' => array_fill(0, 30, ['menu_id' => $s['menu'], 'quantity' => 20])]);
T::eq('599999994.00', $cart['body']['data']['total'], 'exact arithmetic on large totals');
T::status(422, $big->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']]), 'total beyond the column is refused cleanly', 'VALIDATION_FAILED');
Database::update('menus', $s['menu'], ['price' => '0.10']);
$cart = $big->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 3, 'option_ids' => [$s['option']]]]]);
T::eq('30.30', $cart['body']['data']['total'], '3 x (0.10 + 10.00) has no float error');
Database::update('menus', $s['menu'], ['price' => '45.00']);

T::group('T13 T16 T17 parallel transitions');
$eight = $ports;
for ($i = 2; $i < 6; $i++) {
    $eight[] = Servers::start(8201 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
$all = static fn (string $path, array $body = []) => array_map(static fn ($b) => [$m, 'POST', "$a/$path", $body, $b], $eight);
$log = static fn (int $id, string $to) => (int) Database::value('SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = ?', [$id, $to]);
[$cx, $ox] = Fixture::order($base, 'rev_x', $s);
$mixed = array_column(parallel([[$m, 'POST', "$a/merchant/orders/$ox/accept", [], $eight[0]], [$cx, 'POST', "$a/orders/$ox/cancel", [], $eight[1]]]), 'status');
sort($mixed);
$final = Database::value('SELECT status FROM orders WHERE id = ?', [$ox]);
T::eq([[200, 409], $final === 'PREPARING' ? 1 : 0], [$mixed, (int) Database::value('SELECT COUNT(*) FROM queues WHERE order_id = ?', [$ox])], "accept against customer cancel: one wins ($final)");
[$cy, $oy] = Fixture::order($base, 'rev_y', $s);
foreach (['accept' => 'ACCEPTED', 'prepare' => 'PREPARING', 'ready' => 'READY'] as $action => $to) {
    $codes = array_unique(array_column(parallel($all("merchant/orders/$oy/$action")), 'status'));
    T::eq([[200], 1], [array_values($codes), $log($oy, $to)], "6 parallel $action: one change");
}
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND event_type = 'ORDER_READY'", [$oy]), 'one READY notification');
$code = $cy->get("$a/orders/$oy")['body']['data']['order_no'];
$race = parallel([[$m, 'POST', "$a/merchant/orders/$oy/complete", ['pickup_qr' => $code], $eight[0]], [$m, 'POST', "$a/merchant/orders/$oy/payment", [], $eight[1]]]);
T::eq(200, $race[1]['status'], 'payment wins its race');
T::ok(in_array($race[0]['status'], [200, 409], true), 'complete is either after payment or refused');
$codes = array_unique(array_column(parallel($all("merchant/orders/$oy/payment")), 'status'));
T::eq([[200], 1], [array_values($codes), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$oy])], 'one payment confirmation');
$codes = array_unique(array_column(parallel($all("merchant/orders/$oy/complete", ['pickup_qr' => $code])), 'status'));
T::eq([[200], 1], [array_values($codes), $log($oy, 'COMPLETED')], '6 parallel complete: one change');
$p = Database::row('SELECT status, amount, confirmed_by FROM payments WHERE order_id = ?', [$oy]);
T::eq(['PAID', '45.00', $s['user']], [$p['status'], $p['amount'], $p['confirmed_by']], 'payment amount and actor');

T::group('Transaction rollback');
[, $oz] = Fixture::order($base, 'rev_z', $s);
// Occupy the next queue number so that the accept fails after it already changed the counter.
$next = (int) Database::value('SELECT last_number FROM queue_counters WHERE shop_id = ? AND business_date = ?', [$s['shop'], Clock::businessDate()]) + 1;
[, $blocker] = Fixture::order($base, 'rev_blk', $s);
$block = Database::insert('queues', ['order_id' => $blocker, 'shop_id' => $s['shop'], 'business_date' => Clock::businessDate(), 'queue_number' => $next]);
T::status(500, $m->post("$a/merchant/orders/$oz/accept"), 'forced failure inside accept');
T::eq(['PENDING', 0, 0, $next - 1], [Database::value('SELECT status FROM orders WHERE id = ?', [$oz]), $log($oz, 'ACCEPTED'), (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id = ?', [$oz]), (int) Database::value('SELECT last_number FROM queue_counters WHERE shop_id = ? AND business_date = ?', [$s['shop'], Clock::businessDate()])], 'status, log, notification and counter all rolled back');
Database::run('DELETE FROM queues WHERE id = ?', [$block]);
T::status(200, $m->post("$a/merchant/orders/$oz/accept"), 'accept works afterwards');

T::group('Rate limits');
// Do not let a fixed window end in the middle of a burst.
$window = static function (int $seconds, int $margin): void {
    $left = $seconds - time() % $seconds;
    if ($left < $margin) {
        sleep($left + 1);
    }
};
$limited = Servers::start(8211, ['RATE_LIMIT_MULTIPLIER' => '1']);
$later = Servers::start(8212, ['RATE_LIMIT_MULTIPLIER' => '1', 'TEST_CLOCK_OFFSET' => '901']);
$try = static fn (string $b, string $login, string $password) => (new Client($b))->post("$a/auth/login", ['realm' => 'merchant', 'login' => $login, 'password' => $password])['status'];
$pw = Env::get('SEED_DEMO_PASSWORD');
$window(900, 20);
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $codes[] = $try($limited, $s['login'], 'wrong');
}
T::eq([401, 429], [$codes[9], $codes[10]], 'login: 10 failures per account and address, then 429');
T::eq(429, $try($limited, $s['login'], $pw), 'right password is also refused while locked');
T::eq(429, $try($limited, strtoupper($s['login']), 'wrong'), 'letter case is the same account');
$accent = str_replace('e', 'é', $s['login']);
T::eq(401, $try($limited, $accent, $pw), 'an accented look-alike never reaches the account');
T::eq(200, $try($limited, $s2['login'], $pw), 'another account on the same address still works');
T::eq(200, $try($later, $s['login'], $pw), 'the counter ends with its window');
for ($i = 0; $i < 9; $i++) {
    $try($limited, $s2['login'], 'wrong');
}
T::eq([200, 401], [$try($limited, $s2['login'], $pw), $try($limited, $s2['login'], 'wrong')], 'a successful login clears earlier failures');

$window(60, 25);
$u1 = Fixture::customer($limited, 'rl1');
$u2 = Fixture::customer($limited, 'rl2');
$order = static fn (Client $x, string $b) => $x->call('POST', "$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => 'x'])['status'];
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = $order($u1, $limited);
}
T::eq([409, 429], [$codes[19], $codes[20]], 'checkout: 20 per minute per customer');
T::eq(409, $order($u2, $limited), 'another customer on the same address is not affected');
$u1->base = $later;
T::eq(409, $order($u1, $later), 'checkout counter ends with its window');
$codes = [];
for ($i = 0; $i < 31; $i++) {
    $codes[] = $u2->post("$a/auth/dev", ['customer' => 'TEST_' . Fixture::run() . '_RL2'])['status'];
}
T::eq([200, 429], [$codes[29], $codes[30]], 'a signed-in customer is limited alone');
T::eq(200, (new Client($limited))->post("$a/auth/dev", ['customer' => 'TEST_' . Fixture::run() . '_RL3'])['status'], 'others on the same address can still sign in');

$old = hash('sha256', 'old' . Fixture::run());
Database::run('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 5)', [$old, time() - 8000]);
for ($i = 0; $i < 3000 && Database::value('SELECT 1 FROM rate_limits WHERE bucket = ?', [$old]); $i++) {
    RateLimiter::hit('purge-test', Fixture::run(), 1000000, 60);
}
T::eq(false, Database::value('SELECT 1 FROM rate_limits WHERE bucket = ?', [$old]), "finished windows are purged (after $i hits)");
RateLimiter::clear('purge-test', Fixture::run());
