<?php
declare(strict_types=1);

// Usage: php scripts/e2e.php
// Development only. Runs one whole order over real HTTP on the throw-away qim_test:
// merchant sets up the menu and PromptPay, customer orders with PromptPay, accept (cooking starts), ready,
// scan the single-use QR, confirm the money, history, report; the QR cannot be used again.

require dirname(__DIR__) . '/tests/lib.php';
require dirname(__DIR__) . '/tests/fixture.php';

$base = Servers::start(8161, ['RATE_LIMIT_MULTIPLIER' => '1000']);
$a = '/api/v1';

T::group('E2E');
try {
    $s = Fixture::shop('e2e');
    $m = Fixture::login($base, 'merchant', $s['login']);
    $r = $m->post("$a/merchant/shops/{$s['shop']}/menus", ['name' => 'ข้าวไข่เจียว', 'price' => '35', 'prep_minutes' => 4, 'option_groups' => [
        ['name' => 'ความเผ็ด', 'min_select' => 1, 'max_select' => 1, 'options' => [['name' => 'ไม่เผ็ด', 'price_delta' => '0'], ['name' => 'เผ็ด', 'price_delta' => '2.50']]],
    ]]);
    T::status(201, $r, 'merchant creates a menu');
    $menu = $r['body']['data'];
    $spicy = $menu['option_groups'][0]['options'][1]['id'];

    T::status(200, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['promptpay_phone' => '0812345678', 'promptpay_enabled' => true]), 'merchant switches PromptPay on');

    $c = Fixture::customer($base, 'e2e');
    $list = $c->get("$a/shops?q=" . rawurlencode('ข้าวไข่เจียว'));
    T::ok(in_array($s['shop'], array_column($list['body']['data'], 'id'), true), 'customer finds the shop');
    $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $menu['id'], 'quantity' => 2, 'note' => 'ไม่ใส่หอม', 'option_ids' => [$spicy]]]]);
    T::eq('75.00', $cart['body']['data']['total'] ?? null, 'cart total');
    T::eq(true, $cart['body']['data']['shop']['promptpay'], 'PromptPay is offered');
    $body = ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'], 'payment_method' => 'PROMPTPAY'];
    $r = $c->post("$a/orders", $body);
    T::status(201, $r, 'checkout');
    $o = $r['body']['data']['id'];
    T::eq($o, $c->post("$a/orders", $body)['body']['data']['id'] ?? null, 'retry gives the same order');
    T::eq('PENDING', $r['body']['data']['status'], 'waiting for the shop');

    $board = $m->get("$a/merchant/shops/{$s['shop']}/orders")['body']['data'];
    T::eq(['ไม่ใส่หอม', 'เผ็ด'], [$board[0]['items'][0]['note'], $board[0]['items'][0]['options'][0]['name']], 'merchant sees note and option');
    foreach (['accept' => 'PREPARING', 'ready' => 'READY'] as $action => $status) {
        T::status(200, $m->post("$a/merchant/orders/$o/$action"), $action);
        $view = $c->get("$a/orders/$o")['body']['data'];
        T::eq($status, $view['status'], "customer sees $status");
        T::eq(1, $view['queue']['number'], 'queue number stays');
    }
    $token = $view['pickup']['qr'] ?? '';
    T::ok(str_starts_with($token, 'QIM1.') && str_starts_with($view['promptpay']['payload'] ?? '', '000201') && $view['promptpay']['amount'] === '75.00', 'pickup token and PromptPay QR for 75.00');
    T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token]), 'cannot complete unpaid', 'PAYMENT_REQUIRED');
    T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token, 'payment_received' => true, 'method' => 'PROMPTPAY']), 'scan the QR, then confirm the money');
    T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token, 'payment_received' => true]), 'the QR works once', 'PICKUP_QR_USED');
    $view = $c->get("$a/orders/$o")['body']['data'];
    T::eq(['COMPLETED', 'PAID', 'PROMPTPAY', 5], [$view['status'], $view['payment_status'], $view['payment_method'], count($view['timeline'])], 'customer history is complete');
    T::eq('75.00', $m->get("$a/merchant/shops/{$s['shop']}/reports")['body']['data']['sales'], 'report');
} catch (Throwable $e) {
    T::ok(false, 'exception: ' . $e->getMessage());
}
echo "\n== E2E " . T::$passed . ' passed, ' . count(T::$failed) . " failed\n";
exit(TestDb::finish() && T::$failed === [] ? 0 : 1);
