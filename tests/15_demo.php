<?php
declare(strict_types=1);

// Presentation helpers. Polling speed is a plain setting; everything else works only with APP_ENV=development.

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$config = static fn (int $port, array $env): array => (new Client(Servers::start($port, $env)))->get("$a/config")['body']['data'] ?? [];

T::group('POLL_SECONDS');
T::eq(null, $config(8263, ['POLL_SECONDS' => ''])['poll_seconds'], 'unset keeps the built-in intervals');
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

$binary = Browser::find();
if ($binary === null) {
    T::skip('POLL', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
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
    $b->open("$fast/customer/");
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
    $m->post("$a/merchant/orders/$o/prepare");
    T::ok($b->sees('ร้านกำลังทำอาหารของคุณ', 20), 'back online: recovers without a reload');
} finally {
    $b->close();
}
