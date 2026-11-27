<?php
declare(strict_types=1);

// Online PromptPay through a payment gateway (D42), paid at checkout (D44), against FakeGateway: no test ever calls Stripe.

use App\Payments\FakeGateway;
use App\Payments\StripeGateway;
use App\Payments\StripeSignature;
use App\Services\NotificationWorker;
use App\Support\Database;

$a = '/api/v1';
$secret = 'whsec_test_' . bin2hex(random_bytes(16));
$key = 'sk_test_FAKE' . bin2hex(random_bytes(12));
$fake = sys_get_temp_dir() . '/qim-fake-gateway-' . Fixture::run() . '.json';
@unlink($fake);
$env = ['PAYMENT_MODE' => 'gateway', 'PAYMENT_GATEWAY' => 'fake', 'FAKE_GATEWAY_FILE' => $fake, 'STRIPE_WEBHOOK_SECRET' => $secret, 'STRIPE_SECRET_KEY' => $key,
    'STRIPE_PLACEHOLDER_EMAIL' => 'payments@example.com', 'RATE_LIMIT_MULTIPLIER' => '1000', 'PROMPTPAY_QR_MINUTES' => '10'];
$gw = Servers::start(8351, $env);
$s = Fixture::shop('gw');
$m = Fixture::login($gw, 'merchant', $s['login']);
$c = Fixture::customer($gw, 'gw');
$other = Fixture::customer($gw, 'gw_other');
$responses = [];

$place = static function (Client $who, string $method = 'STRIPE_PROMPTPAY', ?int $menu = null, ?string $idem = null) use ($a, $s, &$responses): array {
    $cart = $who->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $menu ?? $s['menu'], 'quantity' => 1]], 'replace' => true]);
    return $responses[] = $who->post("$a/orders", ['idempotency_key' => $idem ?? bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'] ?? '', 'payment_method' => $method]);
};
$idOf = static fn (array $r): int => (int) ($r['body']['data']['id'] ?? 0);
$intentOf = static fn (int $order): ?string => Database::value('SELECT intent_id FROM payment_intents WHERE order_id = ? ORDER BY attempt DESC LIMIT 1', [$order]) ?: null;
$fakeIntent = static fn (string $id): array => (new FakeGateway($fake))->retrieve($id);
$event = static function (string $type, array $intent, ?string $id = null): string {
    return json_encode(['id' => $id ?? 'evt_' . bin2hex(random_bytes(8)), 'object' => 'event', 'type' => $type, 'data' => ['object' => [
        'id' => $intent['id'], 'object' => 'payment_intent', 'status' => $intent['status'], 'amount' => $intent['amount'], 'currency' => strtolower($intent['currency']), 'metadata' => $intent['metadata'],
    ]]]);
};
$signed = static fn (string $payload): array => ['Content-Type: application/json', 'Stripe-Signature: ' . StripeSignature::header($payload, $secret, time())];
$hook = static function (string $payload, ?string $signature = null, ?string $base = null) use ($gw, $secret, &$responses): array {
    $r = (new Client($base ?? $gw))->post('/api/webhooks/stripe', $payload, ['Content-Type: application/json', 'Stripe-Signature: ' . ($signature ?? StripeSignature::header($payload, $secret, time()))]);
    $responses[] = $r;
    return $r;
};
$paid = static fn (int $order): array => Database::row('SELECT status, method, confirmed_by FROM payments WHERE order_id = ?', [$order]) ?? [];
$status = static fn (int $order): string => (string) Database::value('SELECT status FROM orders WHERE id = ?', [$order]);
$pay = static function (int $order) use ($fake, $hook, $event, $intentOf): array {
    $intent = FakeGateway::change($fake, (string) $intentOf($order), ['status' => 'succeeded']);
    return $hook($event('payment_intent.succeeded', $intent));
};
/** An online order that was paid: it is now PENDING at the shop. */
$placed = static function (Client $who) use ($place, $idOf, $pay): int {
    $order = $idOf($place($who));
    $pay($order);
    return $order;
};
$accepted = static function (Client $who) use ($placed, $m, $a): int {
    $order = $placed($who);
    $m->post("$a/merchant/orders/$order/accept");
    return $order;
};
$overdue = static fn (int $order) => Database::run('UPDATE orders SET pay_by = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 5), $order]);
$board = static fn (string $scope = 'active'): array => array_column($m->get("$a/merchant/shops/{$s['shop']}/orders?scope=$scope&limit=100")['body']['data'] ?? [], null, 'id');
$bell = static fn (): array => $m->get("$a/merchant/shops/{$s['shop']}/notifications")['body']['data'] ?? [];
$logs = static fn (int $order, string $to): int => (int) Database::value('SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = ?', [$order, $to]);
$confirms = static fn (int $order): int => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$order]);

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

T::group('T42 checkout with online PromptPay: the QR comes at once (D44)');
$cfg = (new Client($gw))->get("$a/config")['body']['data'];
T::eq('gateway', $cfg['payment_mode'] ?? null, 'the page knows online payment is on');
T::status(422, $place($c, 'PROMPTPAY'), "the shop's own PromptPay number is not offered in gateway mode");
$cheap = Database::insert('menus', ['shop_id' => $s['shop'], 'name' => 'น้ำแข็ง gw', 'price' => '5.00']);
T::status(409, $place($c, 'STRIPE_PROMPTPAY', $cheap), 'below Stripe minimum (10 THB): refused', 'ONLINE_MINIMUM');
$idem = bin2hex(random_bytes(16));
$before = time();
$r = $place($c, 'STRIPE_PROMPTPAY', null, $idem);
T::status(201, $r, 'online PromptPay chosen');
$o1 = $idOf($r);
$view = $r['body']['data'];
$qr = $view['online_payment'] ?? [];
T::eq(['AWAITING_PAYMENT', 'STRIPE_PROMPTPAY', 'UNPAID', 'qr'], [$view['status'], $view['payment_method'], $view['payment_status'], $qr['state'] ?? null], 'AWAITING_PAYMENT with the QR in the same answer');
$deadline = strtotime((string) ($qr['qr']['expires_at'] ?? ''));
T::ok(str_starts_with((string) ($qr['qr']['data'] ?? ''), '000201') && $deadline >= $before + 595 && $deadline <= time() + 605 && ($qr['qr']['expires_at'] ?? '') === ($view['pay_by'] ?? null), 'QR with the order deadline: PROMPTPAY_QR_MINUTES after checkout', [$qr, $view['pay_by'] ?? null]);
$intent = $fakeIntent((string) $intentOf($o1));
T::eq([4500, 'THB', (string) $o1], [$intent['amount'], $intent['currency'], $intent['metadata']['order_id'] ?? null], 'amount and currency from the order on the server');
$calls = FakeGateway::calls($fake);
T::status(200, $place($c, 'STRIPE_PROMPTPAY', null, $idem), 'the same checkout again (same key)');
T::ok(FakeGateway::calls($fake) === $calls && (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ?', [$o1]) === 1, 'one order, one PaymentIntent, nobody called', FakeGateway::calls($fake));
FakeGateway::failNext($fake, 'create');
$r = $place($c);
$o2 = $idOf($r);
T::ok($r['status'] === 201 && ($r['body']['data']['online_payment']['state'] ?? '') === 'payable' && $intentOf($o2) === null, 'Stripe down at checkout: the order is kept, the page offers to try again', $r['body']['data']['online_payment'] ?? null);
$results = parallel(array_map(static fn (int $i): array => [$c, 'POST', "$a/orders/$o2/online-payment", [], null], range(1, 4)));
T::ok(array_unique(array_column($results, 'status')) === [200] && (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ?', [$o2]) === 1, 'four taps at once: one PaymentIntent', array_column($results, 'status'));
T::eq('qr', $c->get("$a/orders/$o2")['body']['data']['online_payment']['state'] ?? null, 'then the QR shows');
T::status(422, $place(Fixture::customer($base, 'gw_manual')), 'manual mode server: online payment refused');

T::group('T58 an unpaid online order never reaches the shop');
$shopBefore = (new Client($gw))->get("$a/shops/{$s['shop']}")['body']['data'];
$hidden = $idOf($place($c));
T::ok(!isset($board()[$hidden]) && !isset($board()[$o1]), 'not on the board');
T::status(404, $m->get("$a/merchant/orders/$hidden"), 'nor readable by id');
T::status(404, $m->post("$a/merchant/orders/$hidden/accept"), 'nor accepted');
T::status(404, $m->post("$a/merchant/orders/$hidden/reject", ['reason' => 'ไม่รับ']), 'nor rejected');
T::eq(0, $bell()['pending'] ?? null, 'the bell stays quiet');
$shopAfter = (new Client($gw))->get("$a/shops/{$s['shop']}")['body']['data'];
T::eq([$shopBefore['waiting'], $shopBefore['crowd']], [$shopAfter['waiting'], $shopAfter['crowd']], 'crowd level and waiting orders unchanged');
T::eq(0, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id IN (?, ?, ?)', [$o1, $o2, $hidden]), 'no notification for an unpaid order');
T::status(409, $c->post("$a/orders/$hidden/cancel"), 'the customer cannot cancel it: pay, switch, or let it expire', 'AWAITING_PAYMENT');
$active = array_column($c->get("$a/orders?scope=active")['body']['data'] ?? [], 'status', 'id');
T::eq('AWAITING_PAYMENT', $active[$hidden] ?? null, "the customer's active orders show it");

T::group('T44 paid: the order reaches the shop as PENDING, PAID');
$cash = $idOf($place($c, 'PAY_AT_STORE'));
$seenBefore = $bell();
T::eq([1, $cash], [$seenBefore['pending'], $seenBefore['latest_id']], 'a newer cash order rings first');
$intent = FakeGateway::change($fake, (string) $intentOf($o1), ['status' => 'succeeded']);
T::eq('UNPAID', $paid($o1)['status'] ?? null, 'the gateway alone is not enough: still unpaid until Stripe tells us');
$evt = $event('payment_intent.succeeded', $intent);
T::eq('paid', $hook($evt)['body']['result'] ?? null, 'webhook accepted');
T::eq(['PENDING', 'PAID', 'STRIPE_PROMPTPAY', null], [$status($o1), ...array_values($paid($o1))], 'PENDING, PAID online, no person confirmed it');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND from_status = 'AWAITING_PAYMENT' AND to_status = 'PENDING' AND actor_user_id IS NULL", [$o1]), 'logged as a system change');
$seen = $bell();
T::ok($seen['pending'] === 2 && $seen['latest'] > $seenBefore['latest'], 'the bell rings although the order is older than the cash order', [$seenBefore, $seen]);
$card = $board()[$o1] ?? [];
T::ok(($card['payment_status'] ?? '') === 'PAID' && ($card['payment_method'] ?? '') === 'STRIPE_PROMPTPAY' && strtotime((string) $card['placed_at']) >= strtotime((string) $card['created_at']), 'on the board as paid online, placed when paid', $card);
T::eq('paid', $c->get("$a/orders/$o1")['body']['data']['online_payment']['state'] ?? null, 'customer sees it');
T::eq('duplicate', $hook($evt)['body']['result'] ?? null, 'same event again');
T::eq('duplicate', $hook($event('payment_intent.succeeded', $intent))['body']['result'] ?? null, 'another event for the same payment changes nothing');
T::eq([1, 1], [$confirms($o1), $logs($o1, 'PENDING')], 'confirmed once, reached the shop once');
T::status(409, $c->post("$a/orders/$o1/online-payment"), 'paid: no new QR', 'ALREADY_PAID');
T::status(200, $m->post("$a/merchant/orders/$o1/accept"), 'the shop accepts as usual');

T::group('T45 a payment that does not match the order is not accepted');
foreach (['amount' => ['amount' => 100], 'order' => ['metadata' => ['order_id' => '999999']], 'currency' => ['currency' => 'USD']] as $what => $change) {
    $order = $idOf($place($c));
    $intent = FakeGateway::change($fake, (string) $intentOf($order), $change + ['status' => 'succeeded']);
    $r = $hook($event('payment_intent.succeeded', $intent));
    T::ok($r['status'] === 200 && ($r['body']['result'] ?? '') === 'mismatch' && ($paid($order)['status'] ?? '') === 'UNPAID' && $status($order) === 'AWAITING_PAYMENT', "wrong $what: recorded, not PAID, not sent to the shop", $r['body']);
}
T::eq(3, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.gateway_mismatch' AND entity_id IN (SELECT order_id FROM payment_intents WHERE problem IS NOT NULL)"), 'each one audited for the admin');
T::eq('unknown', $hook($event('payment_intent.succeeded', ['id' => 'pi_notours', 'status' => 'succeeded', 'amount' => 4500, 'currency' => 'THB', 'metadata' => []]))['body']['result'] ?? null, 'an intent that is not ours is ignored');

T::group('T59 the deadline: unpaid orders are cancelled, late money is refund due');
$late = $idOf($place($c));
$lateIntent = (string) $intentOf($late);
$overdue($late);
$view = $c->get("$a/orders/$late")['body']['data'];
T::eq(['CANCELLED', 'หมดเวลาชำระเงิน', 'closed'], [$view['status'], $view['reason'], $view['online_payment']['state'] ?? null], 'opening the order after the deadline cancels it');
T::eq('canceled', $fakeIntent($lateIntent)['status'], 'the PaymentIntent was cancelled at the gateway first');
T::eq([1, 0], [(int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND from_status = 'AWAITING_PAYMENT' AND to_status = 'CANCELLED' AND actor_user_id IS NULL", [$late]), (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id = ?', [$late])], 'cancelled by the system, nothing sent');
T::ok(!isset($board('closed')[$late]), 'not in the shop history either');
T::status(409, $c->post("$a/orders/$late/online-payment"), 'no QR any more', 'NOT_PAYABLE');
$r = $hook($event('payment_intent.succeeded', FakeGateway::change($fake, $lateIntent, ['status' => 'succeeded'])));
T::eq(['refund_due', 'UNPAID', 'CANCELLED'], [$r['body']['result'] ?? null, $paid($late)['status'] ?? null, $status($late)], 'money arriving after the deadline: refund due, the order is not brought back');
T::eq('เงินเข้าหลังหมดเวลาชำระ', Database::value('SELECT refund_reason FROM payment_intents WHERE order_id = ?', [$late]), 'with the reason');
T::ok($c->get("$a/orders/$late")['body']['data']['online_payment']['refund_due'] ?? false, 'the customer sees that the money is due back');
$justInTime = $idOf($place($c));
FakeGateway::change($fake, (string) $intentOf($justInTime), ['status' => 'succeeded']);
$overdue($justInTime);
$view = $c->get("$a/orders/$justInTime")['body']['data'];
T::eq(['PENDING', 'PAID'], [$view['status'], $view['payment_status']], 'paid before the deadline but the webhook is late: the cancellation finds the payment and sends the order to the shop');
$down = $idOf($place($c));
$overdue($down);
FakeGateway::failNext($fake, 'cancel');
FakeGateway::failNext($fake, 'retrieve');
T::eq('CANCELLED', $c->get("$a/orders/$down")['body']['data']['status'] ?? null, 'Stripe unreachable at the deadline: cancelled all the same (late money becomes refund due)');
$cron = [$idOf($place($c)), $idOf($place($other))];
$notYet = $idOf($place($c));
array_map($overdue, $cron);
$p = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/notify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LINE_CHANNEL_ACCESS_TOKEN' => ''] + $env + getenv(), ['bypass_shell' => true]);
$out = stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
$code = proc_close($p);
T::ok($code === 0 && str_contains($out, 'unpaid online orders cancelled: 2'), 'scripts/notify.php cancels overdue orders, even without LINE', [$code, $out]);
T::eq(['CANCELLED', 'CANCELLED', 'AWAITING_PAYMENT'], [$status($cron[0]), $status($cron[1]), $status($notYet)], 'only the overdue ones');

T::group('T46 refused after payment: refund due, the customer is told');
$rej = $placed($c);
T::status(200, $m->post("$a/merchant/orders/$rej/reject", ['reason' => 'วัตถุดิบหมด']), 'shop rejects a paid order (reason required as before)');
T::eq(['REJECTED', 'PAID'], [$status($rej), $paid($rej)['status'] ?? null], 'rejected, the payment stays recorded');
T::eq('ร้านปฏิเสธหลังลูกค้าชำระ: วัตถุดิบหมด', Database::value('SELECT refund_reason FROM payment_intents WHERE order_id = ? AND refund_due_at IS NOT NULL', [$rej]), 'refund due with the reason');
T::ok($c->get("$a/orders/$rej")['body']['data']['online_payment']['refund_due'] ?? false, 'the customer page says the money comes back');
$n = Database::row(NotificationWorker::SELECT . ' WHERE n.order_id = ? AND n.event_type = ?', [$rej, 'ORDER_REJECTED']);
T::ok($n !== null && str_contains(NotificationWorker::message($n), 'เงินที่จ่ายออนไลน์จะได้รับคืน'), 'and so does the LINE message', $n === null ? null : NotificationWorker::message($n));
$after = $accepted($c);
T::status(200, $m->post("$a/merchant/orders/$after/cancel", ['reason' => 'ไฟดับ']), 'shop cancels after the payment');
T::ok(Database::value('SELECT refund_due_at FROM payment_intents WHERE order_id = ? AND succeeded_at IS NOT NULL', [$after]) !== null, 'refund due');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.refund_due' AND entity_id = ?", [$after]), 'audited');
$cashCancel = $idOf($place($c, 'PAY_AT_STORE'));
$m->post("$a/merchant/orders/$cashCancel/reject", ['reason' => 'ปิดร้าน']);
$n = Database::row(NotificationWorker::SELECT . ' WHERE n.order_id = ? AND n.event_type = ?', [$cashCancel, 'ORDER_REJECTED']);
T::ok($n !== null && !str_contains(NotificationWorker::message($n), 'คืน'), 'a cash order says nothing about refunds');

T::group('T47 concurrent webhooks: PAID once, PENDING once');
$race = $idOf($place($c));
$intent = FakeGateway::change($fake, (string) $intentOf($race), ['status' => 'succeeded']);
$ports = [$gw];
for ($i = 1; $i < 4; $i++) {
    $ports[] = Servers::start(8352 + $i, $env);
}
$batch = [];
foreach ($ports as $i => $port) {
    foreach ([$event('payment_intent.succeeded', $intent), $event('payment_intent.succeeded', $intent, 'evt_race' . $race)] as $payload) {
        $batch[] = [new Client($port), 'POST', '/api/webhooks/stripe', $payload, $port, $signed($payload)];
    }
}
$results = parallel($batch);
T::ok(array_unique(array_column($results, 'status')) === [200], 'all deliveries answered 200', array_column($results, 'status'));
T::eq(1, count(array_filter(array_column(array_column($results, 'body'), 'result'), static fn ($x) => $x === 'paid')), 'exactly one made it PAID');
T::eq([1, 1, 'PENDING'], [$confirms($race), $logs($race, 'PENDING'), $status($race)], 'confirmed once, sent to the shop once');

T::group('T48 only the owner, only in the right state');
$mine = $idOf($place($c));
foreach (['online-payment', 'online-payment/check'] as $path) {
    T::status(404, $other->post("$a/orders/$mine/$path"), "another customer: $path", 'NOT_FOUND');
    T::status(401, $m->post("$a/orders/$mine/$path"), "a shop session: $path");
}
T::status(404, $other->post("$a/orders/$mine/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'another customer cannot switch the method');
$noToken = clone $c;
$noToken->csrf = '';
T::status(403, $noToken->post("$a/orders/$mine/online-payment"), 'no CSRF token', 'CSRF_FAILED');
T::status(403, $noToken->post("$a/orders/$mine/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'nor switch without it', 'CSRF_FAILED');
T::status(409, $c->post("$a/orders/$cash/online-payment"), 'an order paid at the shop', 'NOT_ONLINE');
T::eq('AWAITING_PAYMENT', $status($mine), 'unchanged by all of that');

T::group('T49 switching to paying at the shop');
$sw = $idOf($place($c));
$swIntent = (string) $intentOf($sw);
$r = $c->post("$a/orders/$sw/payment-method", ['payment_method' => 'PAY_AT_STORE']);
T::ok($r['status'] === 200 && $r['body']['data']['payment_method'] === 'PAY_AT_STORE' && $r['body']['data']['status'] === 'PENDING' && $fakeIntent($swIntent)['status'] === 'canceled', 'switched after the gateway cancelled the PaymentIntent; the order goes to the shop', $r['body']);
T::ok(isset($board()[$sw]), 'the shop sees it now');
T::status(200, $c->post("$a/orders/$sw/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'a repeated tap changes nothing');
T::eq(1, $logs($sw, 'PENDING'), 'sent to the shop once');
$m->post("$a/merchant/orders/$sw/accept");
$m->post("$a/merchant/orders/$sw/ready");
$no = (string) Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$sw]);
T::status(409, $m->post("$a/merchant/orders/$sw/complete", ['pickup_qr' => $no]), 'cash: the shop confirms the money as before', 'PAYMENT_REQUIRED');
T::status(200, $m->post("$a/merchant/orders/$sw/complete", ['pickup_qr' => $no, 'payment_received' => true]), 'shop takes cash as before');
T::eq(['PAID', 'PAY_AT_STORE'], array_slice(array_values($paid($sw)), 0, 2), 'paid at the shop');
$busy = $idOf($place($c));
FakeGateway::change($fake, (string) $intentOf($busy), ['status' => 'processing']);
T::status(409, $c->post("$a/orders/$busy/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'bank still processing: refused', 'PAYMENT_PROCESSING');
T::eq(['STRIPE_PROMPTPAY', 'AWAITING_PAYMENT'], [Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$busy]), $status($busy)], 'method unchanged');
$done = $idOf($place($c));
FakeGateway::change($fake, (string) $intentOf($done), ['status' => 'succeeded']);
T::status(409, $c->post("$a/orders/$done/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'already paid at the gateway: refused', 'ALREADY_PAID');
T::eq(['PAID', 'PENDING'], [$paid($done)['status'] ?? null, $status($done)], 'and the payment is recorded, the order sent to the shop');
$gone = $idOf($place($c));
FakeGateway::failNext($fake, 'cancel');
FakeGateway::failNext($fake, 'retrieve');
T::status(502, $c->post("$a/orders/$gone/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'gateway not answering: refused', 'GATEWAY_ERROR');
T::eq(['STRIPE_PROMPTPAY', 'AWAITING_PAYMENT'], [Database::value('SELECT requested_method FROM payments WHERE order_id = ?', [$gone]), $status($gone)], 'method unchanged');
$expired = $idOf($place($c));
$overdue($expired);
T::status(409, $c->post("$a/orders/$expired/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'after the deadline: too late', 'PAYMENT_EXPIRED');

T::group('T60 switching while the money arrives: never paid twice');
$outcomes = [];
foreach ([false, false, true, true] as $round => $bankFirst) {
    $order = $idOf($place($c));
    $intent = $fakeIntent((string) $intentOf($order));
    // bankFirst: the customer paid and taps "pay at the shop" at the same moment; otherwise the webhook races the switch.
    if ($bankFirst) {
        $intent = FakeGateway::change($fake, $intent['id'], ['status' => 'succeeded']);
    }
    $payload = $event('payment_intent.succeeded', ['status' => 'succeeded'] + $intent);
    $results = parallel([
        [$c, 'POST', "$a/orders/$order/payment-method", ['payment_method' => 'PAY_AT_STORE'], null],
        [new Client($ports[1]), 'POST', '/api/webhooks/stripe', $payload, $ports[1], $signed($payload)],
    ]);
    $pay = $paid($order);
    $outcomes[] = [$results[0]['status'], $pay['status'], $pay['method']];
    $ok = $status($order) === 'PENDING' && $logs($order, 'PENDING') === 1 && $confirms($order) <= 1
        && (int) Database::value('SELECT COUNT(*) FROM payment_intents WHERE order_id = ? AND refund_due_at IS NOT NULL', [$order]) === 0
        && ($pay['status'] === 'UNPAID' ? $pay['method'] === 'PAY_AT_STORE' : $pay['method'] === 'STRIPE_PROMPTPAY' && $confirms($order) === 1)
        && (!$bankFirst || ($results[0]['status'] === 409 && $pay['status'] === 'PAID'));
    T::ok($ok, 'round ' . ($round + 1) . ($bankFirst ? ' (paid first)' : '') . ': one way of paying, the order at the shop once', [$results[0]['status'], $results[1]['body'] ?? null, $pay]);
}
$expiring = $idOf($place($c));
FakeGateway::change($fake, (string) $intentOf($expiring), ['status' => 'succeeded']);
$overdue($expiring);
$payload = $event('payment_intent.succeeded', $fakeIntent((string) $intentOf($expiring)));
parallel([
    [$c, 'GET', "$a/orders/$expiring", null, null],
    [new Client($ports[2]), 'POST', '/api/webhooks/stripe', $payload, $ports[2], $signed($payload)],
    [$c, 'GET', "$a/orders/$expiring", null, $ports[3]],
]);
T::eq(['PENDING', 'PAID', 1, 1], [$status($expiring), $paid($expiring)['status'] ?? null, $confirms($expiring), $logs($expiring, 'PENDING')], 'the deadline and the webhook at once: paid once, at the shop once');

T::group('T50 checking with the gateway, then hand-over');
$chk = $idOf($place($c));
FakeGateway::change($fake, (string) $intentOf($chk), ['status' => 'succeeded']);
$r = $c->post("$a/orders/$chk/online-payment/check");
T::eq(['PAID', 'paid', 'PENDING'], [$paid($chk)['status'] ?? null, $r['body']['data']['online_payment']['state'] ?? null, $r['body']['data']['status'] ?? null], 'no webhook yet: the check reads Stripe, records the payment and sends the order to the shop');
$m->post("$a/merchant/orders/$chk/accept");
$m->post("$a/merchant/orders/$chk/ready");
$token = (string) ($c->get("$a/orders/$chk")['body']['data']['pickup']['qr'] ?? '');
T::status(200, $m->post("$a/merchant/orders/$chk/complete", ['pickup_qr' => $token]), 'paid online: hand-over with the QR only');
T::eq('COMPLETED', $status($chk), 'completed');
$limited = Servers::start(8357, ['RATE_LIMIT_MULTIPLIER' => '1', 'TEST_CLOCK_OFFSET' => (string) (61 - time() % 60)] + $env);
$slow = Fixture::customer($limited, 'gw_rate');
for ($i = 0, $codes = []; $i < 13; $i++) {
    $codes[] = $slow->post("$a/orders/$chk/online-payment/check")['status'];
}
T::eq([404, 429], [$codes[11], $codes[12]], 'checks are limited to 12 a minute');
$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$report = $m->get("$a/merchant/shops/{$s['shop']}/reports?from=$day&to=$day")['body']['data'] ?? [];
$allPaid = (string) Database::value("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.shop_id = ? AND p.status = 'PAID' AND o.status NOT IN ('CANCELLED','REJECTED')", [$s['shop']]);
T::ok(($report['sales'] ?? null) === $allPaid && $paid($rej)['status'] === 'PAID' && $paid($after)['status'] === 'PAID', 'sales leave out orders refused after their online payment', [$report['sales'] ?? null, $allPaid]);
$placedCount = (int) Database::value("SELECT COUNT(*) FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.shop_id = ? AND l.to_status = 'PENDING'", [$s['shop']]);
$expiredCount = (int) Database::value("SELECT COUNT(*) FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.shop_id = ? AND l.from_status = 'AWAITING_PAYMENT' AND l.to_status = 'CANCELLED'", [$s['shop']]);
T::ok($expiredCount >= 4 && ($report['placed'] ?? null) === $placedCount && ($report['cancelled'] ?? null) === (int) Database::value("SELECT COUNT(*) FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.shop_id = ? AND l.to_status = 'CANCELLED' AND o.placed_at IS NOT NULL", [$s['shop']]), 'orders that never reached the shop are not counted as placed or cancelled', [$report['placed'] ?? null, $placedCount, $report['cancelled'] ?? null, $expiredCount]);

T::group('T51 keys: sandbox only, never shown');
foreach (['sk_live_' . bin2hex(random_bytes(8)) => 'live key', 'rk_test_' . bin2hex(random_bytes(8)) => 'restricted key', 'pk_test_' . bin2hex(random_bytes(8)) => 'publishable key', ' ' => 'no key'] as $bad => $label) {
    T::ok(StripeGateway::keyProblem(trim($bad)) !== null, "$label refused");
}
T::eq(null, StripeGateway::keyProblem('sk_test_' . bin2hex(random_bytes(8))), 'sandbox key accepted');
T::ok(!str_contains((string) StripeGateway::keyProblem('sk_live_SECRETVALUE'), 'SECRETVALUE'), 'the refusal does not repeat the key');
T::eq('Stripe 401: Invalid API Key provided: [key]', StripeGateway::redact('Stripe 401: Invalid API Key provided: sk_test_abc123'), 'keys are removed from messages');
T::ok(preg_match('/^\d{4}-\d{2}-\d{2}(\.[a-z]+)?$/D', StripeGateway::API_VERSION) === 1, 'every Stripe request names its API version');
$live = Servers::start(8358, ['PAYMENT_GATEWAY' => ' ', 'STRIPE_SECRET_KEY' => 'sk_live_' . bin2hex(random_bytes(8))] + $env);
$lc = Fixture::customer($live, 'gw_live');
$cart = $lc->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);
$r = $lc->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'], 'payment_method' => 'STRIPE_PROMPTPAY']);
$lo = $idOf($r);
T::eq([201, 'payable'], [$r['status'], $r['body']['data']['online_payment']['state'] ?? null], 'a live key in development: the order is kept without a QR');
T::status(503, $lc->post("$a/orders/$lo/online-payment"), 'and asking again is refused before any call to Stripe', 'ONLINE_PAYMENT_UNAVAILABLE');
T::status(200, $lc->post("$a/orders/$lo/payment-method", ['payment_method' => 'PAY_AT_STORE']), 'the customer can still pay at the shop');
$leaks = array_filter([...$responses, $c->get("$a/orders/$o1"), $c->get("$a/orders"), $m->get("$a/merchant/shops/{$s['shop']}/orders")], static fn (array $r): bool => str_contains($r['head'] . $r['raw'], $key) || str_contains($r['raw'], $secret));
T::eq([], $leaks, 'no key or webhook secret in any response');
$logText = implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/storage/logs/*.log') ?: []));
T::ok(!str_contains($logText, $key) && !str_contains($logText, $secret) && !preg_match('/sk_live_[0-9a-f]{16}/', $logText), 'nor in the logs');

T::group('T54 refunds due: listed for the shop and the admin, marked by hand');
$list = $m->get("$a/merchant/shops/{$s['shop']}/refunds");
$rows = array_column($list['body']['data'] ?? [], null, 'order_id');
T::ok($list['status'] === 200 && isset($rows[$late], $rows[$after], $rows[$rej]) && str_ends_with($rows[$after]['order_no'], '••••') && str_starts_with($rows[$after]['intent_id'], 'pi_'), 'the shop sees its refunds with the Stripe id, order number masked', array_keys($rows));
$otherShop = Fixture::shop('gw_other');
$mo = Fixture::login($gw, 'merchant', $otherShop['login']);
T::status(404, $mo->get("$a/merchant/shops/{$s['shop']}/refunds"), 'another shop cannot list them');
T::status(404, $mo->post("$a/merchant/shops/{$otherShop['shop']}/refunds/{$rows[$after]['id']}/done", ['note' => 'x']), "nor mark another shop's refund through its own shop");
T::status(401, $c->get("$a/merchant/shops/{$s['shop']}/refunds"), 'customers cannot');
T::status(200, $m->post("$a/merchant/shops/{$s['shop']}/refunds/{$rows[$after]['id']}/done", ['note' => 're_123 คืนแล้ว']), 'shop marks one as refunded');
T::eq(['REFUNDED', 1], [Database::value('SELECT status FROM payments WHERE order_id = ?', [$after]), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.refunded' AND entity_id = ?", [$after])], 'the cancelled order payment is REFUNDED, audited');
T::status(200, $m->post("$a/merchant/shops/{$s['shop']}/refunds/{$rows[$rej]['id']}/done", ['note' => 're_456']), 'and the rejected one');
T::eq('REFUNDED', Database::value('SELECT status FROM payments WHERE order_id = ?', [$rej]), 'a rejected order payment is REFUNDED too');
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
$found = array_column($admin->get("$a/admin/orders?status=AWAITING_PAYMENT&limit=100")['body']['data'] ?? [], 'status', 'id');
T::ok(isset($found[$notYet]) && array_unique(array_values($found)) === ['AWAITING_PAYMENT'], 'the admin can list orders waiting for payment');

T::group('T52 manual mode stays as it was');
$cfg = (new Client($base))->get("$a/config")['body']['data'];
T::eq('manual', $cfg['payment_mode'] ?? null, 'default mode is manual');
[$mc, $mo] = Fixture::order($base, 'gw_manual2', $s);
$view = $mc->get("$a/orders/$mo")['body']['data'];
T::ok(!array_key_exists('online_payment', $view) && $view['status'] === 'PENDING' && Database::value('SELECT placed_at FROM orders WHERE id = ?', [$mo]) !== null && Database::value('SELECT pay_by FROM orders WHERE id = ?', [$mo]) === null, 'orders go to the shop at once, no online payment block, no deadline');
T::status(409, $mc->post("$a/orders/$mo/online-payment"), 'no QR', 'ONLINE_PAYMENT_OFF');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders WHERE status = 'AWAITING_PAYMENT' AND user_id = (SELECT user_id FROM orders WHERE id = ?)", [$mo]), 'nothing waits for payment');
@unlink($fake);
