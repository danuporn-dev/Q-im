<?php
declare(strict_types=1);

// Presentation helpers. Polling speed is a plain setting; everything else works only with APP_ENV=development.

use App\Support\Database;

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$config = static fn (int $port, array $env): array => (new Client(Servers::start($port, $env)))->get("$a/config")['body']['data'] ?? [];

T::group('POLL_SECONDS');
T::eq(null, $config(8263, ['POLL_SECONDS' => ' '])['poll_seconds'], 'unset keeps the built-in intervals');
T::eq(2, $config(8264, ['POLL_SECONDS' => '2', 'RATE_LIMIT_MULTIPLIER' => '1000'])['poll_seconds'], '2 seconds');
foreach (['1', '0', '61', '2.5', 'fast'] as $i => $bad) {
    T::eq(null, $config(8265 + $i, ['POLL_SECONDS' => $bad])['poll_seconds'], "refused: $bad");
}

T::group('Demo sign-in is development only');
foreach (['production', 'staging', ''] as $i => $env) {
    $base2 = Servers::start(8270 + $i, ['APP_ENV' => $env === '' ? 'unset' : $env]);
    $data = (new Client($base2))->get("$a/config")['body']['data'];
    T::eq([false, null], [$data['dev_login'], $data['demo_customers']], "$env: not offered");
    T::status(404, (new Client($base2))->post("$a/auth/dev", ['customer' => 'CUSTOMER_1']), "$env: refused");
}
$c = new Client($base);
$c->csrf = $c->post("$a/auth/dev", ['customer' => 'CUSTOMER_3'])['body']['data']['csrf'];
T::eq('ฟ้าใส', $c->get("$a/me")['body']['data']['display_name'], 'demo customers have Thai names');

T::group('seed-demo.php');
$run = static function (array $env) use (&$out): int {
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/seed-demo.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return proc_close($p);
};
foreach ([['APP_ENV' => 'production'], ['APP_ENV' => 'staging'], ['SEED_DEMO_PASSWORD' => 'short'], ['DB_NAME' => 'qim_other_db'], ['DB_HOST' => '10.255.255.1']] as $env) {
    T::ok($run($env + ['DB_PORT' => '1']) === 1 && str_contains($out, 'Refusing'), 'refuses ' . json_encode($env), $out);
}
T::ok($run([]) === 1 && str_contains($out, 'demo-reset'), 'refuses to mix with the ordinary seed', $out);
Database::run("UPDATE food_courts SET active = 0 WHERE name = 'โรงอาหารกลาง (Demo)'");
T::eq(0, $run([]), 'seeds');
$tables = static fn (): array => array_map(static fn ($t) => Database::value("SELECT COUNT(*) FROM $t"), ['shops', 'menus', 'menu_options', 'users', 'shop_members', 'user_roles', 'categories']);
$first = $tables();
T::eq(0, $run([]), 'seeds again');
T::eq($first, $tables(), 'a second run adds nothing');
$court = (int) Database::value("SELECT id FROM food_courts WHERE name = 'โรงอาหารกลาง'");
$demo = array_column(Database::rows('SELECT id FROM shops WHERE food_court_id = ? ORDER BY id', [$court]), 'id');
T::eq(3, count($demo), 'three shops in the central food court');
T::eq(2, (int) Database::value("SELECT COUNT(*) FROM shops s JOIN food_courts f ON f.id = s.food_court_id WHERE f.name = 'โรงอาหารคณะวิศวกรรมศาสตร์'"), 'two shops in the second food court');
$anon = new Client($base);
$prod = new Client('http://127.0.0.1:8270');
foreach ($demo as $i => $id) {
    $view = $anon->get("$a/shops/$id")['body']['data'];
    $list = $anon->get("$a/shops/$id/menus")['body']['data']['menus'];
    T::ok($view['is_open'] && $view['eta_minutes'] >= 1 && $view['eta_minutes'] <= 10 && count($list) >= 6 && count($list) <= 8, "{$view['name']}: open, sensible ETA, 6 to 8 menus", [$view['eta_minutes'], count($list)]);
    $bad = [];
    foreach ([$view, ...$list] as $x) {
        $key = substr((string) $x['image_url'], 6);
        $r = $anon->get("$a/images/$key");
        if ($r['status'] !== 200 || !preg_match('#Content-Type: image/(jpeg|svg\+xml)#i', $r['head'])) {
            $bad[] = $x['name'];
        }
        if (str_contains($r['head'], 'svg') && $prod->get("$a/images/$key")['status'] !== 404) {
            $bad[] = 'placeholder served in production';
        }
    }
    T::eq([], $bad, 'every picture loads; placeholders only in development');

    // The whole cycle with the demo data of this shop.
    $c = new Client($base);
    $c->csrf = $c->post("$a/auth/dev", ['customer' => 'CUSTOMER_' . ($i + 1)])['body']['data']['csrf'];
    $m = Fixture::login($base, 'merchant', 'demo_merchant_' . ($i + 1));
    $dish = $list[0];
    $options = [];
    foreach ($dish['option_groups'] as $g) {
        array_push($options, ...array_column(array_slice($g['options'], 0, max(1, $g['min_select'])), 'id'));
    }
    $cart = $c->put("$a/cart", ['shop_id' => $id, 'items' => [['menu_id' => $dish['id'], 'quantity' => 1, 'option_ids' => $options]], 'replace' => true]);
    $r = $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'] ?? '']);
    T::status(201, $r, 'order with the demo customer');
    $o = $r['body']['data']['id'];
    $statuses = [];
    foreach (['accept', 'prepare', 'ready', 'payment'] as $x) {
        $statuses[] = $m->post("$a/merchant/orders/$o/$x")['status'];
        $v = $c->get("$a/orders/$o")['body']['data'];
        if ($x === 'accept') {
            T::ok($v['queue']['number'] >= 1 && $v['eta_minutes'] >= 1 && $v['eta_minutes'] <= 15, 'queue and ETA', $v['eta_minutes']);
        }
    }
    $statuses[] = $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $v['order_no']])['status'];
    T::eq([200, 200, 200, 200, 200], $statuses, 'accept, prepare, ready, payment, complete');
    $messages = $c->get("$a/dev/messages")['body']['data'];
    $texts = array_column(array_values(array_filter($messages, static fn (array $x): bool => $x['order_id'] === (int) $o)), 'text');
    T::ok(count($texts) === 2 && str_contains($texts[0], 'รับออเดอร์แล้ว') && str_contains($texts[1], 'อาหารพร้อมรับแล้ว') && str_contains($texts[1], $v['order_no']), 'simulated messages of the order', $texts);
    $mine = array_column(Database::rows('SELECT o.id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.line_user_id = ?', ['DEMO_CUSTOMER_' . ($i + 1)]), 'id');
    T::eq([], array_values(array_diff(array_column($messages, 'order_id'), $mine)), 'no messages of other customers');
    T::eq('COMPLETED', $c->get("$a/orders/$o")['body']['data']['status'], 'completed');
}
$rows = static fn (): array => Database::rows('SELECT * FROM notifications ORDER BY id');
$before = $rows();
$c->get("$a/dev/messages");
T::eq($before, $rows(), 'reading the messages changes and sends nothing');
T::status(401, $anon->get("$a/dev/messages"), 'messages need a customer session');
T::status(401, $m->get("$a/dev/messages"), 'a merchant session is not enough');
T::status(405, $c->post("$a/dev/messages"), 'messages are read only');
foreach ([8270, 8271, 8272] as $port) {
    $other = new Client("http://127.0.0.1:$port");
    $other->cookies = $c->cookies;
    T::status(404, $other->get("$a/dev/messages"), "port $port is not development: refused even with a session");
}
$shopEdit = $anon->get("$a/shops/{$demo[2]}/menus")['body']['data']['menus'][0];
T::status(200, $m->patch("$a/merchant/menus/{$shopEdit['id']}", ['image_url' => $shopEdit['image_url'], 'price' => '31']), 'a demo menu can be edited during the presentation');

Database::run("UPDATE food_courts SET active = 1 WHERE name = 'โรงอาหารกลาง (Demo)'");

$binary = Browser::find();
if ($binary === null) {
    T::skip('POLL', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
T::group('Demo sign-in and polling in a browser');
$fast = 'http://127.0.0.1:8264';
$s = Fixture::shop('poll');
$m = Fixture::login($base, 'merchant', $s['login']);
$b = new Browser($binary, 9378);
try {
    $b->size(390, 844);
    $b->open("$fast/customer/");
    $b->click('ต้นกล้า');
    T::ok($b->until("document.querySelector('.demobar').innerText.includes('ต้นกล้า')"), 'one tap on the home page signs in, banner shown');
    $b->open('http://127.0.0.1:8270/customer/');
    T::ok($b->sees('เดินไปรับเมื่ออาหารเสร็จ') && !$b->sees('สาธิต', 1), 'production page has no banner and no demo button');
    $b->open('http://127.0.0.1:8270/customer/#/messages');
    T::ok($b->sees('เดินไปรับเมื่ออาหารเสร็จ') && !$b->sees('จำลอง', 1), 'production page has no simulated chat');
    $b->open("$fast/customer/#/messages");
    T::ok($b->sees('ไม่ได้ส่งผ่าน LINE จริง') && $b->sees('อาหารพร้อมรับแล้ว') && $b->sees('ยังไม่ได้ส่ง'), 'chat is labelled as a simulation');
    $b->sees('หน้าหลัก');
    $b->js("fetch('/api/v1/auth/dev',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer:'TEST_POLL'})}).then(r=>r.ok)");
    $c = new Client($base);
    $c->csrf = $c->post("$a/auth/dev", ['customer' => 'TEST_POLL'])['body']['data']['csrf'];
    $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);
    $o = $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']])['body']['data']['id'];
    $b->open("$fast/customer/#/order/$o");
    T::ok($b->sees('รอร้านยืนยัน'), 'tracking page open');
    $m->post("$a/merchant/orders/$o/accept");
    T::ok($b->sees('Q-001', 3), 'accepted order shows its queue within 3 seconds');
    $b->js('window.n=0;{const f=window.fetch;window.fetch=(...x)=>{window.n++;return f(...x);}}');
    $b->js("Object.defineProperty(document,'hidden',{value:true,configurable:true})");
    usleep(500_000);
    $n = $b->js('window.n');
    usleep(5_000_000);
    T::eq($n, $b->js('window.n'), 'hidden tab: no requests');
    $b->js("Object.defineProperty(document,'hidden',{value:false,configurable:true});document.dispatchEvent(new Event('visibilitychange'))");
    T::ok($b->until("window.n>$n", 1.5), 'visible again: updates at once');
    $b->network(true);
    $b->js('window.n=0');
    usleep(11_000_000);
    $n = $b->js('window.n');
    T::ok($n >= 1 && $n <= 3, 'offline: polling backs off', $n);
    $b->network(false);
    $m->post("$a/merchant/orders/$o/ready");
    T::ok($b->sees('อาหารพร้อมแล้ว', 20), 'back online: recovers without a reload');
} finally {
    $b->close();
}
