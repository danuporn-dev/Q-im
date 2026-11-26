<?php
declare(strict_types=1);

// Usage: php scripts/e2e.php
// Development only. Runs one whole order over real HTTP on the throw-away qim_test:
// merchant sets up the menu and PromptPay, customer orders with PromptPay, accept (cooking starts), ready with the
// PromptPay QR, the shop confirms the money (twice, recorded once), scans the single-use pickup QR, history, report;
// the pickup QR cannot be used again. Then the same with online PromptPay (D42, PAYMENT_MODE=gateway, FakeGateway: no call
// to Stripe): order, shop accepts, the customer pays online, the signed webhook makes it PAID, ready, the pickup QR
// completes it with no money step.

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
        if ($status === 'PREPARING') {
            T::eq([null, null], [$view['promptpay'], $view['pickup']], 'no QR codes while cooking');
        }
    }
    $token = $view['pickup']['qr'] ?? '';
    $payload = $view['promptpay']['payload'] ?? '';
    T::eq([true, App\Services\PromptPay::payload('0812345678', '75.00'), '75.00'], [str_starts_with($token, 'QIM1.'), $payload, $view['promptpay']['amount']], 'ready: pickup QR, and the PromptPay QR for the server total');
    T::eq(App\Services\PromptPay::crc(substr($payload, 0, -4)), substr($payload, -4), 'PromptPay CRC is valid');
    T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token]), 'cannot complete unpaid', 'PAYMENT_REQUIRED');
    T::eq(null, App\Support\Database::value('SELECT used_at FROM pickup_tokens WHERE order_id = ?', [$o]), 'the refused scan did not spend the QR');
    foreach (['first', 'second'] as $tap) {
        T::status(200, $m->post("$a/merchant/orders/$o/payment", ['method' => 'PROMPTPAY']), "shop taps 'ได้รับเงินแล้ว' ($tap)");
    }
    T::eq([1, 'PAID', 'PROMPTPAY'], [
        (int) App\Support\Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o]),
        App\Support\Database::value('SELECT status FROM payments WHERE order_id = ?', [$o]), App\Support\Database::value('SELECT method FROM payments WHERE order_id = ?', [$o]),
    ], 'the payment is recorded once');
    $view = $c->get("$a/orders/$o")['body']['data'];
    T::eq(['PAID', null, $token], [$view['payment_status'], $view['promptpay'], $view['pickup']['qr']], 'customer: paid, no PromptPay QR, the pickup QR stays');
    T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token]), 'shop scans the pickup QR');
    T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token]), 'the pickup QR works once', 'PICKUP_QR_USED');
    $view = $c->get("$a/orders/$o")['body']['data'];
    T::eq(['COMPLETED', 'PAID', 'PROMPTPAY', 5], [$view['status'], $view['payment_status'], $view['payment_method'], count($view['timeline'])], 'customer history is complete');
    T::eq('75.00', $m->get("$a/merchant/shops/{$s['shop']}/reports")['body']['data']['sales'], 'report');

    // Online PromptPay through the gateway.
    $secret = 'whsec_test_' . bin2hex(random_bytes(16));
    $fake = sys_get_temp_dir() . '/qim-e2e-gateway-' . Fixture::run() . '.json';
    $gw = Servers::start(8162, ['PAYMENT_MODE' => 'gateway', 'PAYMENT_GATEWAY' => 'fake', 'FAKE_GATEWAY_FILE' => $fake, 'STRIPE_WEBHOOK_SECRET' => $secret,
        'STRIPE_SECRET_KEY' => 'sk_test_FAKE' . bin2hex(random_bytes(8)), 'RATE_LIMIT_MULTIPLIER' => '1000']);
    $gm = Fixture::login($gw, 'merchant', $s['login']);
    $gc = Fixture::customer($gw, 'e2e_online');
    $cart = $gc->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $menu['id'], 'quantity' => 1, 'option_ids' => [$spicy]]]]);
    $r = $gc->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'], 'payment_method' => 'STRIPE_PROMPTPAY']);
    T::status(201, $r, 'online: checkout with online PromptPay');
    $go = $r['body']['data']['id'];
    T::status(200, $gm->post("$a/merchant/orders/$go/accept"), 'online: shop accepts');
    $view = $gc->post("$a/orders/$go/online-payment")['body']['data'];
    T::eq('qr', $view['online_payment']['state'] ?? null, 'online: customer gets the QR');
    $intentId = (string) App\Support\Database::value('SELECT intent_id FROM payment_intents WHERE order_id = ?', [$go]);
    $intent = App\Payments\FakeGateway::change($fake, $intentId, ['status' => 'succeeded']);
    $event = json_encode(['id' => 'evt_e2e' . bin2hex(random_bytes(6)), 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $intent['amount'], 'currency' => 'thb', 'metadata' => $intent['metadata']]]]);
    T::status(200, (new Client($gw))->post('/api/webhooks/stripe', $event, ['Content-Type: application/json', 'Stripe-Signature: ' . App\Payments\StripeSignature::header($event, $secret, time())]), 'online: Stripe webhook');
    $view = $gc->get("$a/orders/$go")['body']['data'];
    T::eq(['PAID', 'STRIPE_PROMPTPAY', 'paid'], [$view['payment_status'], $view['payment_method'], $view['online_payment']['state']], 'online: PAID automatically');
    T::status(200, $gm->post("$a/merchant/orders/$go/ready"), 'online: ready');
    $token = $gc->get("$a/orders/$go")['body']['data']['pickup']['qr'] ?? '';
    T::status(200, $gm->post("$a/merchant/orders/$go/complete", ['pickup_qr' => $token]), 'online: shop scans the pickup QR, no money step');
    T::eq('COMPLETED', $gc->get("$a/orders/$go")['body']['data']['status'] ?? null, 'online: completed');
    @unlink($fake);
} catch (Throwable $e) {
    T::ok(false, 'exception: ' . $e->getMessage());
}
echo "\n== E2E " . T::$passed . ' passed, ' . count(T::$failed) . " failed\n";
exit(TestDb::finish() && T::$failed === [] ? 0 : 1);
