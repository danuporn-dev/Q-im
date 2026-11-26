<?php
declare(strict_types=1);

// Online PromptPay through a payment gateway (D42), against FakeGateway: no test ever calls Stripe.

use App\Payments\FakeGateway;
use App\Payments\StripeGateway;
use App\Payments\StripeSignature;
use App\Support\Database;

$a = '/api/v1';
$secret = 'whsec_test_' . bin2hex(random_bytes(16));
$key = 'sk_test_FAKE' . bin2hex(random_bytes(12));
$fake = sys_get_temp_dir() . '/qim-fake-gateway-' . Fixture::run() . '.json';
@unlink($fake);
$env = ['PAYMENT_MODE' => 'gateway', 'PAYMENT_GATEWAY' => 'fake', 'FAKE_GATEWAY_FILE' => $fake, 'STRIPE_WEBHOOK_SECRET' => $secret, 'STRIPE_SECRET_KEY' => $key,
    'STRIPE_PLACEHOLDER_EMAIL' => 'payments@example.com', 'RATE_LIMIT_MULTIPLIER' => '1000'];
$gw = Servers::start(8351, $env);
$s = Fixture::shop('gw');
$m = Fixture::login($gw, 'merchant', $s['login']);
$c = Fixture::customer($gw, 'gw');
$other = Fixture::customer($gw, 'gw_other');
$responses = [];

$place = static function (Client $who, string $method = 'STRIPE_PROMPTPAY', ?int $menu = null) use ($a, $s): array {
    $cart = $who->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $menu ?? $s['menu'], 'quantity' => 1]], 'replace' => true]);
    return $who->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'] ?? '', 'payment_method' => $method]);
};
$intentOf = static fn (int $order): ?string => Database::value('SELECT intent_id FROM payment_intents WHERE order_id = ? ORDER BY attempt DESC LIMIT 1', [$order]) ?: null;
$fakeIntent = static fn (string $id): array => (new FakeGateway($fake))->retrieve($id);
$event = static function (string $type, array $intent, ?string $id = null): string {
    return json_encode(['id' => $id ?? 'evt_' . bin2hex(random_bytes(8)), 'object' => 'event', 'type' => $type, 'data' => ['object' => [
        'id' => $intent['id'], 'object' => 'payment_intent', 'status' => $intent['status'], 'amount' => $intent['amount'], 'currency' => strtolower($intent['currency']), 'metadata' => $intent['metadata'],
    ]]]);
};
$hook = static function (string $payload, ?string $signature = null, ?string $base = null) use ($gw, $secret, &$responses): array {
    $r = (new Client($base ?? $gw))->post('/api/webhooks/stripe', $payload, ['Content-Type: application/json', 'Stripe-Signature: ' . ($signature ?? StripeSignature::header($payload, $secret, time()))]);
    $responses[] = $r;
    return $r;
};
$paid = static fn (int $order): array => Database::row('SELECT status, method, confirmed_by FROM payments WHERE order_id = ?', [$order]) ?? [];
$ready = static function (Client $who) use ($place, $m, $a): int {
    $order = (int) ($place($who)['body']['data']['id'] ?? 0);
    $m->post("$a/merchant/orders/$order/accept");
    return $order;
};
$pay = static function (int $order) use ($c, $a, $intentOf, $fakeIntent, $fake, $hook, $event, &$responses): array {
    $responses[] = $c->post("$a/orders/$order/online-payment");
    $intent = FakeGateway::change($fake, (string) $intentOf($order), ['status' => 'succeeded']);
    return $hook($event('payment_intent.succeeded', $intent));
};

T::group('T41 webhook signature');
$valid = json_encode(['id' => 'evt_sig' . bin2hex(random_bytes(6)), 'object' => 'event', 'type' => 'charge.updated', 'data' => ['object' => ['id' => 'ch_x', 'object' => 'charge']]]);
$now = time();
T::ok(StripeSignature::verify($valid, StripeSignature::header($valid, $secret, $now), $secret, $now), 'own signature verifies');
T::ok(!StripeSignature::verify($valid . ' ', StripeSignature::header($valid, $secret, $now), $secret, $now), 'changed payload fails');
T::ok(!StripeSignature::verify($valid, StripeSignature::header($valid, $secret, $now - 301), $secret, $now), 'older than 5 minutes fails');
T::ok(!StripeSignature::verify($valid, StripeSignature::header($valid, $secret, $now + 301), $secret, $now), 'more than 5 minutes ahead fails');
T::ok(StripeSignature::verify($valid, "t=$now,v1=" . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', "$now.$valid", $secret) . ',v0=abc', $secret, $now), 'one of several v1 entries is enough (secret rotation)');
T::ok(!StripeSignature::verify($valid, "t=$now,v0=" . hash_hmac('sha256', "$now.$valid", $secret), $secret, $now), 'v0 alone is not accepted');
T::ok(!StripeSignature::verify($valid, StripeSignature::header($valid, 'whsec_other', $now), $secret, $now), 'another secret fails');
T::eq(200, $hook($valid)['status'], 'signed event accepted');
T::eq(200, $hook($valid)['status'], 'the same event again: accepted, nothing done');
T::eq('duplicate', $hook($valid)['body']['result'] ?? null, 'recognised as a repeat');
T::eq(400, $hook($valid, StripeSignature::header($valid, 'whsec_wrong', time()))['status'], 'wrong secret: 400');
T::eq(400, $hook(str_replace('charge.updated', 'payment_intent.succeeded', $valid), StripeSignature::header($valid, $secret, time()))['status'], 'payload changed after signing: 400');
T::eq(400, $hook($valid, StripeSignature::header($valid, $secret, time() - 600))['status'], 'old timestamp: 400');
T::eq(400, (new Client($gw))->post('/api/webhooks/stripe', $valid, ['Content-Type: application/json'])['status'], 'no signature: 400');
$noSecret = Servers::start(8352, ['STRIPE_WEBHOOK_SECRET' => ' '] + $env);
T::eq(503, (new Client($noSecret))->post('/api/webhooks/stripe', $valid, ['Content-Type: application/json', 'Stripe-Signature: ' . StripeSignature::header($valid, $secret, time())])['status'], 'secret not configured: 503, Stripe retries later');
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM gateway_events WHERE event_id = ?', [json_decode($valid, true)['id']]), 'each event recorded once');

T::group('T42 choosing online payment at checkout');
$cfg = (new Client($gw))->get("$a/config")['body']['data'];
T::eq('gateway', $cfg['payment_mode'] ?? null, 'the page knows online payment is on');
T::status(422, $place($c, 'PROMPTPAY'), "the shop's own PromptPay number is not offered in gateway mode");
$cheap = Database::insert('menus', ['shop_id' => $s['shop'], 'name' => 'น้ำแข็ง gw', 'price' => '5.00']);
T::status(409, $place($c, 'STRIPE_PROMPTPAY', $cheap), 'below Stripe minimum (10 THB): refused', 'ONLINE_MINIMUM');
$r = $place($c);
T::status(201, $r, 'online PromptPay chosen');
$o1 = (int) $r['body']['data']['id'];
T::eq(['STRIPE_PROMPTPAY', 'waiting_shop'], [$r['body']['data']['payment_method'], $r['body']['data']['online_payment']['state'] ?? null], 'waits for the shop to accept');
T::status(422, $place(Fixture::customer($base, 'gw_manual')), 'manual mode server: online payment refused');

T::group('T43 pay only after the shop accepted; one PaymentIntent reused');
T::status(409, $c->post("$a/orders/$o1/online-payment"), 'PENDING: cannot pay yet', 'NOT_PAYABLE');
T::eq(null, $intentOf($o1), 'no PaymentIntent created');
$m->post("$a/merchant/orders/$o1/accept");
$r = $c->post("$a/orders/$o1/online-payment");
$qr = $r['body']['data']['online_payment'] ?? [];
T::ok($r['status'] === 200 && ($qr['state'] ?? '') === 'qr' && str_starts_with((string) ($qr['qr']['data'] ?? ''), '000201') && strtotime((string) ($qr['qr']['expires_at'] ?? '')) > time(), 'PREPARING: QR with expiry', $qr);
$first = $intentOf($o1);
$intent = $fakeIntent($first);
T::eq([4500, 'THB', (string) $o1], [$intent['amount'], $intent['currency'], $intent['metadata']['order_id'] ?? null], 'amount and currency from the order on the server');
$calls = FakeGateway::calls($fake);
$c->post("$a/orders/$o1/online-payment");
T::eq($calls, FakeGateway::calls($fake), 'asking again while the QR is valid calls nobody');
Database::run('UPDATE payment_intents SET qr_expires_at = ? WHERE intent_id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $first]);
T::eq('expired', $c->get("$a/orders/$o1")['body']['data']['online_payment']['state'] ?? null, 'expired QR is no longer shown');
$r = $c->post("$a/orders/$o1/online-payment");
T::ok(($r['body']['data']['online_payment']['state'] ?? '') === 'qr' && $intentOf($o1) === $first && (FakeGateway::calls($fake)['create'] ?? 0) === ($calls['create'] ?? 0), 'new QR from the same PaymentIntent, nothing created');
$o2 = $ready($c);
$results = parallel(array_map(static fn (int $i): array => [$c, 'POST', "$a/orders/$o2/online-payment", [], null], range(1, 4)));
T::ok(array_unique(array_column($results, 'status')) === [200] && (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ?', [$o2]) === 1, 'four taps at once: one PaymentIntent', array_column($results, 'status'));
FakeGateway::change($fake, (string) $intentOf($o2), ['status' => 'canceled']);
Database::run('UPDATE payment_intents SET qr_expires_at = ? WHERE order_id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $o2]);
$c->post("$a/orders/$o2/online-payment");
T::eq(2, (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ?', [$o2]), 'cancelled at the gateway: the next attempt gets a new one');
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE active_order_id = ?', [$o2]), 'only one active');

T::group('T44 succeeded webhook: PAID once, by the gateway');
$intent = FakeGateway::change($fake, $first, ['status' => 'succeeded']);
T::eq('UNPAID', $paid($o1)['status'] ?? null, 'the gateway alone is not enough: still unpaid until Stripe tells us');
$evt = $event('payment_intent.succeeded', $intent);
T::eq(200, $hook($evt)['status'], 'webhook accepted');
T::eq(['PAID', 'STRIPE_PROMPTPAY', null], array_values($paid($o1)), 'PAID online, no person confirmed it');
T::eq('paid', $c->get("$a/orders/$o1")['body']['data']['online_payment']['state'] ?? null, 'customer sees it');
T::eq(200, $hook($evt)['status'], 'same event again');
T::eq('duplicate', $hook($event('payment_intent.succeeded', $intent))['body']['result'] ?? null, 'another event for the same payment changes nothing');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o1]), 'confirmed once');
T::status(409, $c->post("$a/orders/$o1/online-payment"), 'paid: no new QR', 'ALREADY_PAID');

T::group('T45 a payment that does not match the order is not accepted');
foreach (['amount' => ['amount' => 100], 'order' => ['metadata' => ['order_id' => '999999']], 'currency' => ['currency' => 'USD']] as $what => $change) {
    $order = $ready($c);
    $c->post("$a/orders/$order/online-payment");
    $intent = FakeGateway::change($fake, (string) $intentOf($order), $change + ['status' => 'succeeded']);
    $r = $hook($event('payment_intent.succeeded', $intent));
    T::ok($r['status'] === 200 && ($r['body']['result'] ?? '') === 'mismatch' && ($paid($order)['status'] ?? '') === 'UNPAID', "wrong $what: recorded, not PAID", $r['body']);
}
T::eq(3, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.gateway_mismatch' AND entity_id IN (SELECT order_id FROM payment_intents WHERE problem IS NOT NULL)"), 'each one audited for the admin');
T::eq('unknown', $hook($event('payment_intent.succeeded', ['id' => 'pi_notours', 'status' => 'succeeded', 'amount' => 4500, 'currency' => 'THB', 'metadata' => []]))['body']['result'] ?? null, 'an intent that is not ours is ignored');

T::group('T46 money after the order was cancelled: refund due');
$late = $ready($c);
$c->post("$a/orders/$late/online-payment");
$lateIntent = (string) $intentOf($late);
T::status(200, $m->post("$a/merchant/orders/$late/cancel", ['reason' => 'วัตถุดิบหมด']), 'shop cancels while the QR is open');
T::eq('canceled', $fakeIntent($lateIntent)['status'], 'the open PaymentIntent is cancelled at the gateway');
$intent = FakeGateway::change($fake, $lateIntent, ['status' => 'succeeded']);
$r = $hook($event('payment_intent.succeeded', $intent));
T::eq(['refund_due', 'UNPAID'], [$r['body']['result'] ?? null, $paid($late)['status'] ?? null], 'money arriving later: refund due, the order stays cancelled');
$after = $ready($c);
$pay($after);
T::eq('PAID', $paid($after)['status'] ?? null, 'paid online');
T::status(200, $m->post("$a/merchant/orders/$after/cancel", ['reason' => 'ไฟดับ']), 'shop cancels after the payment (reason required as before)');
T::ok(Database::value('SELECT refund_due_at FROM payment_intents WHERE order_id = ? AND succeeded_at IS NOT NULL', [$after]) !== null, 'refund due');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.refund_due' AND entity_id = ?", [$after]), 'audited');
T::ok($c->get("$a/orders/$after")['body']['data']['online_payment']['refund_due'] ?? false, 'the customer sees that the money is due back');

T::group('T47 concurrent webhooks: PAID once');
$race = $ready($c);
$c->post("$a/orders/$race/online-payment");
$intent = FakeGateway::change($fake, (string) $intentOf($race), ['status' => 'succeeded']);
$ports = [$gw];
for ($i = 1; $i < 4; $i++) {
    $ports[] = Servers::start(8352 + $i, $env);
}
$batch = [];
foreach ($ports as $i => $port) {
    foreach ([$event('payment_intent.succeeded', $intent), $event('payment_intent.succeeded', $intent, 'evt_race' . $race)] as $payload) {
        $batch[] = [new Client($port), 'POST', '/api/webhooks/stripe', $payload, $port, ['Content-Type: application/json', 'Stripe-Signature: ' . StripeSignature::header($payload, $secret, time())]];
    }
}
$results = parallel($batch);
T::ok(array_unique(array_column($results, 'status')) === [200], 'all deliveries answered 200', array_column($results, 'status'));
T::eq(1, count(array_filter(array_column(array_column($results, 'body'), 'result'), static fn ($x) => $x === 'paid')), 'exactly one made it PAID');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$race]), 'confirmed once');

T::group('T48 only the owner, only in the right state');
$mine = $ready($c);
foreach (['online-payment', 'online-payment/check'] as $path) {
    T::status(404, $other->post("$a/orders/$mine/$path"), "another customer: $path", 'NOT_FOUND');
    T::status(401, $m->post("$a/orders/$mine/$path"), "a shop session: $path");
}
T::status(404, $other->post("$a/orders/$mine/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'another customer cannot switch the method');
$noToken = clone $c;
$noToken->csrf = '';
T::status(403, $noToken->post("$a/orders/$mine/online-payment"), 'no CSRF token', 'CSRF_FAILED');
$cash = (int) $place($c, 'PAY_AT_STORE')['body']['data']['id'];
$m->post("$a/merchant/orders/$cash/accept");
T::status(409, $c->post("$a/orders/$cash/online-payment"), 'an order paid at the shop', 'NOT_ONLINE');
$m->post("$a/merchant/orders/$mine/cancel", ['reason' => 'ปิดร้าน']);
T::status(409, $c->post("$a/orders/$mine/online-payment"), 'cancelled order', 'NOT_PAYABLE');

T::group('T49 switching to paying at the shop');
$sw = $ready($c);
$c->post("$a/orders/$sw/online-payment");
$swIntent = (string) $intentOf($sw);
$r = $c->post("$a/orders/$sw/payment-method", ['payment_method' => 'PAY_AT_STORE']);
T::ok($r['status'] === 200 && $r['body']['data']['payment_method'] === 'PAY_AT_STORE' && $fakeIntent($swIntent)['status'] === 'canceled', 'switched after the gateway cancelled the PaymentIntent', $r['body']);
$m->post("$a/merchant/orders/$sw/ready");
$no = (string) Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$sw]);
T::status(200, $m->post("$a/merchant/orders/$sw/complete", ['pickup_qr' => $no, 'payment_received' => true]), 'shop takes cash as before');
T::eq(['PAID', 'PAY_AT_STORE'], array_slice(array_values($paid($sw)), 0, 2), 'paid at the shop');
$busy = $ready($c);
$c->post("$a/orders/$busy/online-payment");
FakeGateway::change($fake, (string) $intentOf($busy), ['status' => 'processing']);
T::status(409, $c->post("$a/orders/$busy/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'bank still processing: refused', 'PAYMENT_PROCESSING');
T::eq('STRIPE_PROMPTPAY', Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$busy]), 'method unchanged');
$done = $ready($c);
$c->post("$a/orders/$done/online-payment");
FakeGateway::change($fake, (string) $intentOf($done), ['status' => 'succeeded']);
T::status(409, $c->post("$a/orders/$done/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'already paid at the gateway: refused', 'ALREADY_PAID');
T::eq('PAID', $paid($done)['status'] ?? null, 'and the payment is recorded');
$down = $ready($c);
$c->post("$a/orders/$down/online-payment");
FakeGateway::failNext($fake, 'cancel');
FakeGateway::failNext($fake, 'retrieve');
T::status(502, $c->post("$a/orders/$down/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'gateway not answering: refused', 'GATEWAY_ERROR');
T::eq('STRIPE_PROMPTPAY', Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$down]), 'method unchanged');

T::group('T50 checking with the gateway, then hand-over');
$chk = $ready($c);
$c->post("$a/orders/$chk/online-payment");
FakeGateway::change($fake, (string) $intentOf($chk), ['status' => 'succeeded']);
$r = $c->post("$a/orders/$chk/online-payment/check");
T::eq(['PAID', 'paid'], [$paid($chk)['status'] ?? null, $r['body']['data']['online_payment']['state'] ?? null], 'no webhook yet: the check reads Stripe and records the payment');
$m->post("$a/merchant/orders/$chk/ready");
$token = (string) ($c->get("$a/orders/$chk")['body']['data']['pickup']['qr'] ?? '');
T::status(200, $m->post("$a/merchant/orders/$chk/complete", ['pickup_qr' => $token]), 'paid online: hand-over with the QR only');
T::eq('COMPLETED', Database::value('SELECT status FROM orders WHERE id = ?', [$chk]), 'completed');
$limited = Servers::start(8357, ['RATE_LIMIT_MULTIPLIER' => '1', 'TEST_CLOCK_OFFSET' => (string) (61 - time() % 60)] + $env);
$slow = Fixture::customer($limited, 'gw_rate');
for ($i = 0, $codes = []; $i < 13; $i++) {
    $codes[] = $slow->post("$a/orders/$chk/online-payment/check")['status'];
}
T::eq([404, 429], [$codes[11], $codes[12]], 'checks are limited to 12 a minute');
$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$sales = $m->get("$a/merchant/shops/{$s['shop']}/reports?from=$day&to=$day")['body']['data']['sales'] ?? null;
$allPaid = (string) Database::value("SELECT SUM(p.amount) - 45.00 FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.shop_id = ? AND p.status = 'PAID'", [$s['shop']]);
T::ok($sales === $allPaid && $paid($after)['status'] === 'PAID', 'sales leave out the order cancelled after its online payment', [$sales, $allPaid]);

T::group('T51 keys: sandbox only, never shown');
foreach (['sk_live_' . bin2hex(random_bytes(8)) => 'live key', 'rk_test_' . bin2hex(random_bytes(8)) => 'restricted key', 'pk_test_' . bin2hex(random_bytes(8)) => 'publishable key', ' ' => 'no key'] as $bad => $label) {
    T::ok(StripeGateway::keyProblem(trim($bad)) !== null, "$label refused");
}
T::eq(null, StripeGateway::keyProblem('sk_test_' . bin2hex(random_bytes(8))), 'sandbox key accepted');
T::ok(!str_contains((string) StripeGateway::keyProblem('sk_live_SECRETVALUE'), 'SECRETVALUE'), 'the refusal does not repeat the key');
T::eq('Stripe 401: Invalid API Key provided: [key]', StripeGateway::redact('Stripe 401: Invalid API Key provided: sk_test_abc123'), 'keys are removed from messages');
$live = Servers::start(8358, ['PAYMENT_GATEWAY' => ' ', 'STRIPE_SECRET_KEY' => 'sk_live_' . bin2hex(random_bytes(8))] + $env);
$lc = Fixture::customer($live, 'gw_live');
$lm = Fixture::login($live, 'merchant', $s['login']);
$cart = $lc->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);
$lo = (int) $lc->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'], 'payment_method' => 'STRIPE_PROMPTPAY'])['body']['data']['id'];
$lm->post("$a/merchant/orders/$lo/accept");
T::status(503, $lc->post("$a/orders/$lo/online-payment"), 'a live key in development is refused before any call to Stripe', 'ONLINE_PAYMENT_UNAVAILABLE');
$leaks = array_filter([...$responses, $c->get("$a/orders/$o1"), $c->get("$a/orders"), $m->get("$a/merchant/shops/{$s['shop']}/orders")], static fn (array $r): bool => str_contains($r['head'] . $r['raw'], $key) || str_contains($r['raw'], $secret));
T::eq([], $leaks, 'no key or webhook secret in any response');
$logs = implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/storage/logs/*.log') ?: []));
T::ok(!str_contains($logs, $key) && !str_contains($logs, $secret) && !preg_match('/sk_live_[0-9a-f]{16}/', $logs), 'nor in the logs');

T::group('T54 refunds due: listed for the shop and the admin, marked by hand');
$list = $m->get("$a/merchant/shops/{$s['shop']}/refunds");
$rows = array_column($list['body']['data'] ?? [], null, 'order_id');
T::ok($list['status'] === 200 && isset($rows[$late], $rows[$after]) && str_ends_with($rows[$after]['order_no'], '••••') && str_starts_with($rows[$after]['intent_id'], 'pi_'), 'the shop sees its refunds with the Stripe id, order number masked', array_keys($rows));
$otherShop = Fixture::shop('gw_other');
$mo = Fixture::login($gw, 'merchant', $otherShop['login']);
T::status(404, $mo->get("$a/merchant/shops/{$s['shop']}/refunds"), 'another shop cannot list them');
T::status(404, $mo->post("$a/merchant/shops/{$otherShop['shop']}/refunds/{$rows[$after]['id']}/done", ['note' => 'x']), "nor mark another shop's refund through its own shop");
T::status(401, $c->get("$a/merchant/shops/{$s['shop']}/refunds"), 'customers cannot');
T::status(200, $m->post("$a/merchant/shops/{$s['shop']}/refunds/{$rows[$after]['id']}/done", ['note' => 're_123 คืนแล้ว']), 'shop marks one as refunded');
T::eq(['REFUNDED', 1], [Database::value('SELECT status FROM payments WHERE order_id = ?', [$after]), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.refunded' AND entity_id = ?", [$after])], 'the cancelled order payment is REFUNDED, audited');
T::status(200, $m->post("$a/merchant/shops/{$s['shop']}/refunds/{$rows[$after]['id']}/done", ['note' => 'again']), 'marking again changes nothing');
T::eq('re_123 คืนแล้ว', Database::value('SELECT refund_note FROM payment_intents WHERE id = ?', [$rows[$after]['id']]), 'first note kept');
$plain = (int) Database::value('SELECT id FROM payment_intents WHERE order_id = ?', [$chk]);
T::status(409, $m->post("$a/merchant/shops/{$s['shop']}/refunds/$plain/done", ['note' => 'x']), 'a payment that is not refund due cannot be marked', 'NOT_REFUND_DUE');
[, $adminLogin] = Fixture::staff('admin', 'gw_' . Fixture::run());
$admin = Fixture::login($gw, 'admin', $adminLogin);
$all = array_column($admin->get("$a/admin/refunds")['body']['data'] ?? [], null, 'id');
T::ok(count(array_filter($all, static fn ($r) => $r['problem'] !== null)) >= 3 && isset($all[$rows[$late]['id']]) && !str_contains((string) $all[$rows[$late]['id']]['order_no'], '•'), 'the admin also sees payments that did not match, with full order numbers');
T::status(200, $admin->post("$a/admin/refunds/{$rows[$late]['id']}/done", ['note' => 'admin']), 'admin marks one');
T::status(409, $admin->post("$a/admin/refunds/" . array_key_first(array_filter($all, static fn ($r) => $r['refund_due_at'] === null)) . '/done', ['note' => 'x']), 'a mismatch that is not refund due cannot be marked', 'NOT_REFUND_DUE');

T::group('T52 manual mode stays as it was');
$cfg = (new Client($base))->get("$a/config")['body']['data'];
T::eq('manual', $cfg['payment_mode'] ?? null, 'default mode is manual');
[$mc, $mo] = Fixture::order($base, 'gw_manual2', $s);
T::ok(!array_key_exists('online_payment', $mc->get("$a/orders/$mo")['body']['data']), 'no online payment block on orders');
T::status(409, $mc->post("$a/orders/$mo/online-payment"), 'no QR', 'ONLINE_PAYMENT_OFF');
@unlink($fake);
