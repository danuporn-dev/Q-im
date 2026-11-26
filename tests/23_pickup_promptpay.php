<?php
declare(strict_types=1);

// T22 single-use pickup tokens and T27 PromptPay confirmed by the shop (D38).

use App\Services\PromptPay;
use App\Support\Database;
use App\Support\Env;

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$anon = new Client($base);
$s = Fixture::shop('tok', ['capacity' => 50]);
$s2 = Fixture::shop('tok2', ['capacity' => 50]);
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
$n = 0;
/** An order of $shop, taken to READY. @return array{0: Client, 1: int} */
$ready = static function (array $shop, Client $merchant) use ($base, $a, &$n): array {
    [$c, $o] = Fixture::order($base, 'tok' . (++$n), $shop);
    $merchant->post("$a/merchant/orders/$o/accept");
    $merchant->post("$a/merchant/orders/$o/ready");
    return [$c, $o];
};
$view = static fn (Client $c, int $o): array => $c->get("$a/orders/$o")['body']['data'];
$state = static fn (int $o): array => [Database::value('SELECT status FROM orders WHERE id = ?', [$o]), Database::value('SELECT status FROM payments WHERE order_id = ?', [$o])];
$failures = static fn (int $o): int => (int) Database::value('SELECT pickup_failures FROM orders WHERE id = ?', [$o]);
$done = static fn (Client $merchant, int $o, string $qr, array $more = []): array => $merchant->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $qr, 'payment_received' => true] + $more);
$other = static fn (string $token): string => substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

T::group('T22 the token exists only for a ready order');
[$c, $o] = Fixture::order($base, 'tok_life', $s);
T::eq(null, $view($c, $o)['pickup'], 'PENDING: no QR');
$m->post("$a/merchant/orders/$o/accept");
T::eq([null, 0], [$view($c, $o)['pickup'], (int) Database::value('SELECT COUNT(*) FROM pickup_tokens WHERE order_id = ?', [$o])], 'PREPARING: no QR, no token');
$m->post("$a/merchant/orders/$o/ready");
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM pickup_tokens WHERE order_id = ?', [$o]), 'created when it becomes ready');
$pickup = $view($c, $o)['pickup'];
$token = $pickup['qr'];
T::ok(preg_match('/^QIM1\.[A-Z2-7]{32}$/D', (string) $token) === 1, '160-bit token', $token);
T::eq($token, $view($c, $o)['pickup']['qr'], 'the same on every read');
$row = Database::row('SELECT * FROM pickup_tokens WHERE order_id = ?', [$o]);
T::eq([hash('sha256', $token), null], [$row['token_hash'], $row['used_at']], 'only the hash is stored');
T::ok(!str_contains(json_encode($row), substr($token, 5)), 'the token is nowhere in the row');
$hours = (strtotime($row['expires_at'] . ' UTC') - strtotime(Database::value('SELECT ready_at FROM orders WHERE id = ?', [$o]) . ' UTC')) / 3600;
T::ok(abs($hours - 12) < 0.01, 'expires 12 hours after ready', $hours);
foreach (['shop board' => $m->get("$a/merchant/shops/{$s['shop']}/orders"), 'shop order' => $m->get("$a/merchant/orders/$o"), 'customer list' => $c->get("$a/orders"), 'notifications' => $c->get("$a/notifications")] as $label => $r) {
    T::ok(!str_contains($r['raw'], 'QIM1'), "$label has no token");
}
T::status(404, Fixture::customer($base, 'tok_spy')->get("$a/orders/$o"), 'another customer');

T::group('T22 wrong, foreign and unpaid');
[$c2, $o2] = $ready($s, $m);
[$cb, $ob] = $ready($s2, $m2);
T::status(422, $done($m, $o, $other($token)), 'one character off', 'PICKUP_QR_WRONG');
T::status(422, $done($m, $o, $view($c2, $o2)['pickup']['qr']), 'token of another order of the shop', 'PICKUP_QR_WRONG');
T::status(404, $done($m2, $o, $token), 'another shop with the right token');
T::status(422, $done($m2, $ob, $token), 'a token used on another shop\'s order', 'PICKUP_QR_WRONG');
T::eq([2, 1, 0], [$failures($o), $failures($ob), $failures($o2)], 'wrong tries count on the order they were made on');
T::ok(!str_contains($done($m, $o, $other($token))['raw'], 'QIM1'), 'errors do not echo tokens');
$r = $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $token]);
T::status(409, $r, 'right token, money not confirmed', 'PAYMENT_REQUIRED');
T::eq([['READY', 'UNPAID'], null], [$state($o), Database::value('SELECT used_at FROM pickup_tokens WHERE order_id = ?', [$o])], 'T16: the token is not spent yet');

T::group('T22 single use');
T::status(200, $done($m, $o, strtolower($token)), 'token, then money; letter case does not matter');
T::eq([['COMPLETED', 'PAID'], true], [$state($o), Database::value('SELECT used_at FROM pickup_tokens WHERE order_id = ?', [$o]) !== null], 'completed, paid, spent');
T::status(409, $done($m, $o, $token), 'replay', 'PICKUP_QR_USED');
T::status(409, $done($m, $o, $other($token)), 'any token on a completed order answers the same', 'PICKUP_QR_USED');
T::status(404, $done($m2, $o, $token), 'replay by another shop');
T::status(200, $done($m, $o, $view($c, $o)['order_no']), 'repeating the typed number stays harmless');
T::eq([null, 1, 1], [$view($c, $o)['pickup'], (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'COMPLETED'", [$o]), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o])], 'no QR afterwards; one completion, one payment');

T::group('T22 expiry');
$token2 = $view($c2, $o2)['pickup']['qr'];
Database::run('UPDATE pickup_tokens SET expires_at = ? WHERE order_id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $o2]);
T::status(409, $done($m, $o2, $token2), 'expired', 'PICKUP_QR_EXPIRED');
T::eq([0, ['READY', 'UNPAID']], [$failures($o2), $state($o2)], 'not counted as a wrong try');
$p = $view($c2, $o2)['pickup'];
T::eq([null, true], [$p['qr'], $p['expired']], 'the customer is told');
T::status(200, $done($m, $o2, $view($c2, $o2)['order_no']), 'fallback: the typed order number');
T::eq(true, Database::value('SELECT used_at FROM pickup_tokens WHERE order_id = ?', [$o2]) !== null, 'any hand-over spends the token');
T::status(409, $done($m, $o2, $token2), 'and it stays useless', 'PICKUP_QR_USED');
foreach (['1' => 1, '200' => 12, 'x' => 12] as $value => $expected) {
    $port = 8300 + strlen((string) $value) + ($value === 'x' ? 4 : 0);
    $server = Servers::start($port, ['PICKUP_TOKEN_HOURS' => (string) $value, 'RATE_LIMIT_MULTIPLIER' => '1000']);
    [, $oh] = Fixture::order($base, "tok_h$value", $s);
    $m->post("$a/merchant/orders/$oh/accept");
    $mine = Fixture::login($server, 'merchant', $s['login']);
    $mine->post("$a/merchant/orders/$oh/ready");
    $row = Database::row('SELECT t.expires_at, o.ready_at FROM pickup_tokens t JOIN orders o ON o.id = t.order_id WHERE o.id = ?', [$oh]);
    T::ok(abs((strtotime($row['expires_at']) - strtotime($row['ready_at'])) / 3600 - $expected) < 0.01, "PICKUP_TOKEN_HOURS=$value gives $expected h");
}

T::group('T22 cancelled orders');
[$c3, $o3] = $ready($s, $m);
$token3 = $view($c3, $o3)['pickup']['qr'];
Database::update('orders', $o3, ['ready_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
T::status(200, $m->post("$a/merchant/orders/$o3/cancel", ['reason' => 'ลูกค้าไม่มารับ']), 'no-show');
T::status(409, $done($m, $o3, $token3), 'token of a cancelled order', 'INVALID_STATE');
T::eq([null, 'UNPAID'], [$view($c3, $o3)['pickup'], $state($o3)[1]], 'no QR, nothing paid');

T::group('T22 the wrong-try limit covers tokens and numbers');
[$c4, $o4] = $ready($s, $m);
$token4 = $view($c4, $o4)['pickup']['qr'];
foreach ([$other($token4), 'QIM1.AAAA', 'QI000000-AAAAAA', $other($token4)] as $bad) {
    T::status(422, $done($m, $o4, $bad), 'wrong', 'PICKUP_QR_WRONG');
}
T::status(409, $done($m, $o4, $other($token4)), 'fifth', 'PICKUP_QR_LOCKED');
T::status(409, $done($m, $o4, $token4), 'the right token is locked out too', 'PICKUP_QR_LOCKED');
T::status(200, $m->post("$a/merchant/orders/$o4/complete", ['override_reason' => 'ตรวจบัตรแล้ว', 'payment_received' => true]), 'hand-over with a reason');

T::group('T22 parallel scans: exactly one succeeds');
$ports = [];
for ($i = 0; $i < 6; $i++) {
    $ports[] = Servers::start(8310 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
for ($round = 1; $round <= 3; $round++) {
    [$c5, $o5] = $ready($s, $m);
    $t = $view($c5, $o5)['pickup']['qr'];
    $codes = array_column(parallel(array_map(static fn ($p) => [$m, 'POST', "$a/merchant/orders/$o5/complete", ['pickup_qr' => $t, 'payment_received' => true], $p], $ports)), 'status');
    sort($codes);
    T::eq([[200, 409, 409, 409, 409, 409], 1, 1, 0], [$codes, (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'COMPLETED'", [$o5]), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o5]), $failures($o5)], "round $round");
}

T::group('T22 older ready orders and a changed server key');
[$c6, $o6] = $ready($s, $m);
Database::run('DELETE FROM pickup_tokens WHERE order_id = ?', [$o6]);
$t = $view($c6, $o6)['pickup']['qr'];
T::ok(str_starts_with((string) $t, 'QIM1.'), 'an order ready before the migration gets a token when viewed');
$rekeyed = Servers::start(8317, ['PICKUP_TOKEN_KEY' => str_repeat('k', 40), 'RATE_LIMIT_MULTIPLIER' => '1000']);
$again = new Client($rekeyed);
$again->cookies = $c6->cookies;
$t2 = $again->get("$a/orders/$o6")['body']['data']['pickup']['qr'];
T::ok($t2 !== $t && str_starts_with((string) $t2, 'QIM1.'), 'a new key gives a new token');
T::status(422, $done($m, $o6, $t), 'the old one is gone', 'PICKUP_QR_WRONG');
T::status(200, $done($m, $o6, $t2), 'the new one works');

T::group('T27 PromptPay payload');
T::eq('29B1', PromptPay::crc('123456789'), 'CRC-16/CCITT-FALSE check value');
// Published test vectors of the MIT library dtinth/promptpay-qr (index.test.js).
$vectors = [
    '00020101021129370016A000000677010111011300668012345675802TH530376463046197',
    '00020101021129370016A000000677010111011300668912345675802TH5303764630429C1',
    '00020101021129370016A000000677010111021311111111111115802TH530376463047B5A',
    '00020101021229370016A000000677010111011300660000000005802TH530376454044.226304E469',
];
foreach ($vectors as $i => $v) {
    T::eq(substr($v, -4), PromptPay::crc(substr($v, 0, -4)), "CRC of vector $i");
}
T::eq($vectors[3], PromptPay::payload('0000000000', '4.22'), 'whole payload with an amount equals the vector');
$x = PromptPay::payload('0801234567', '1250.50');
T::ok(str_starts_with($x, '00020101021229370016A00000067701011101130066801234567' . '5802TH530376454071250.5' . '06304') && PromptPay::crc(substr($x, 0, -4)) === substr($x, -4), 'amount length and CRC', $x);
T::eq('0812345678', PromptPay::phone(' 081-234-5678 '), 'spaces and dashes are removed');
foreach (['1111111111111', '1-1111-11111-11-1', '0212345678', '081234567', '08123456789', '+66812345678', 'abcdefghij', 812345678, ['0812345678'], ''] as $bad) {
    try {
        PromptPay::phone($bad);
        T::ok(false, 'refused: ' . json_encode($bad));
    } catch (App\Support\HttpError $e) {
        T::eq(422, $e->status, 'refused: ' . json_encode($bad));
    }
}

T::group('T27 shop settings');
$p = Fixture::shop('pp', ['capacity' => 50]);
$q = Fixture::shop('pp_other', ['capacity' => 50]);
$mp = Fixture::login($base, 'merchant', $p['login']);
$mq = Fixture::login($base, 'merchant', $q['login']);
$set = static fn (Client $x, array $shop, array $body): array => $x->patch("$a/merchant/shops/{$shop['shop']}/settings", $body);
$stored = static fn (array $shop): array => array_values(Database::row('SELECT promptpay_phone, promptpay_enabled FROM shop_settings WHERE shop_id = ?', [$shop['shop']]));
T::status(422, $set($mp, $p, ['promptpay_enabled' => true]), 'cannot enable without a number');
T::status(422, $set($mp, $p, ['promptpay_phone' => '1111111111111', 'promptpay_enabled' => true]), 'national ID refused');
T::eq([null, 0], $stored($p), 'nothing stored');
T::status(404, $set($mq, $p, ['promptpay_phone' => '0899999999', 'promptpay_enabled' => true]), 'another shop cannot set it');
T::status(200, $set($mp, $p, ['promptpay_phone' => '081-234-5678', 'promptpay_enabled' => true]), 'set and enable');
T::eq(['0812345678', 1], $stored($p), 'digits only');
T::status(422, $set($mp, $p, ['promptpay_phone' => '']), 'cannot clear while enabled');
T::ok(!str_contains((string) Database::value("SELECT GROUP_CONCAT(safe_metadata) FROM audit_logs WHERE entity_type = 'shop' AND entity_id = ?", [$p['shop']]), '0812345678'), 'the audit log names fields, not the number');
$cust = Fixture::customer($base, 'pp_a');
$cart = static function (Client $x, array $shop) use ($a): array {
    return $x->put("$a/cart", ['shop_id' => $shop['shop'], 'items' => [['menu_id' => $shop['menu'], 'quantity' => 2]], 'replace' => true]);
};
$view1 = $cart($cust, $p);
T::eq(true, $view1['body']['data']['shop']['promptpay'], 'the cart offers it');
foreach (['shops' => $anon->get("$a/shops?q=" . rawurlencode('pp_')), 'shop' => $anon->get("$a/shops/{$p['shop']}"), 'menus' => $anon->get("$a/shops/{$p['shop']}/menus"), 'cart' => $view1] as $label => $r) {
    T::ok(!str_contains($r['raw'], '0812345678') && !str_contains($r['raw'], '66812345678'), "$label does not show the number");
}

T::group('T27 choosing at checkout');
$order = static function (Client $x, array $shop, mixed $method, ?string $key = null) use ($a, $cart): array {
    $quote = $cart($x, $shop)['body']['data']['quote'];
    return $x->post("$a/orders", ['idempotency_key' => $key ?? bin2hex(random_bytes(16)), 'quote' => $quote] + ($method === null ? [] : ['payment_method' => $method]));
};
foreach (['CARD', 'promptpay', ['PROMPTPAY'], 1] as $bad) {
    T::status(422, $order($cust, $p, $bad), 'invalid method ' . json_encode($bad));
}
T::status(409, $order($cust, $q, 'PROMPTPAY'), 'a shop without PromptPay', 'PAYMENT_METHOD_UNAVAILABLE');
T::eq(0, (int) Database::value('SELECT COUNT(*) FROM orders WHERE shop_id = ?', [$q['shop']]), 'no order');
$key = bin2hex(random_bytes(16));
$r = $order($cust, $p, 'PROMPTPAY', $key);
T::status(201, $r, 'PromptPay order');
$po = $r['body']['data']['id'];
T::eq(['PROMPTPAY', 'UNPAID', null], [$r['body']['data']['payment_method'], $r['body']['data']['payment_status'], $r['body']['data']['promptpay']], 'chosen, unpaid, no QR yet');
$r = $order($cust, $p, 'PAY_AT_STORE', $key);
T::eq([$po, 'PROMPTPAY'], [$r['body']['data']['id'], $r['body']['data']['payment_method']], 'a retry returns the same order unchanged');
T::eq('PAY_AT_STORE', $order(Fixture::customer($base, 'pp_def'), $p, null)['body']['data']['payment_method'], 'pay at the shop is the default');

T::group('T27 the QR appears only when ready, with the server amount');
T::status(409, $mp->post("$a/merchant/orders/$po/payment", ['method' => 'PROMPTPAY']), 'no payment before ready', 'INVALID_STATE');
$mp->post("$a/merchant/orders/$po/accept");
T::eq(null, $view($cust, $po)['promptpay'], 'not while cooking');
$mp->post("$a/merchant/orders/$po/ready");
$pp = $view($cust, $po)['promptpay'];
T::eq([PromptPay::payload('0812345678', '90.00'), '90.00'], [$pp['payload'], $pp['amount']], 'payload for the order total');
$board = $mp->get("$a/merchant/shops/{$p['shop']}/orders");
T::ok(str_contains($board['raw'], 'PROMPTPAY') && !str_contains($board['raw'], '000201'), 'the shop sees the choice');
T::ok(!str_contains($cust->get("$a/orders")['raw'], '000201'), 'not in the history list');
T::status(422, $done($mp, $po, $pp['payload']), 'scanning the PromptPay QR as pickup QR', 'PICKUP_QR_PROMPTPAY');
T::eq([0, ['READY', 'UNPAID']], [$failures($po), $state($po)], 'not counted, nothing paid');
$set($mp, $p, ['promptpay_enabled' => false]);
T::eq(null, $view($cust, $po)['promptpay']['payload'], 'the shop switched PromptPay off: no QR');
$set($mp, $p, ['promptpay_enabled' => true]);

T::group('T27 the shop confirms what it received, once');
foreach (['CARD', ['x'], 5] as $bad) {
    T::status(422, $mp->post("$a/merchant/orders/$po/payment", ['method' => $bad]), 'invalid ' . json_encode($bad));
}
T::eq(['READY', 'UNPAID'], $state($po), 'unpaid');
T::status(404, $mq->post("$a/merchant/orders/$po/payment", ['method' => 'PROMPTPAY']), 'another shop');
$codes = array_column(parallel(array_map(static fn ($p) => [$mp, 'POST', "$a/merchant/orders/$po/payment", ['method' => (int) substr($p, -1) % 2 ? 'PROMPTPAY' : 'PAY_AT_STORE'], $p], $ports)), 'status');
T::eq([200], array_values(array_unique($codes)), 'six confirmations at once, two different methods');
$paid = Database::row('SELECT method, requested_method, status, amount FROM payments WHERE order_id = ?', [$po]);
T::eq([1, 1], [(int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$po]), (int) Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$po])], 'recorded once');
$mp->post("$a/merchant/orders/$po/payment", ['method' => $paid['method'] === 'PROMPTPAY' ? 'PAY_AT_STORE' : 'PROMPTPAY']);
T::eq($paid['method'], Database::value('SELECT method FROM payments WHERE order_id = ?', [$po]), 'a later confirmation changes nothing');
T::eq([null, $paid['method']], [$view($cust, $po)['promptpay'], $view($cust, $po)['payment_method']], 'no QR once paid; the method received is shown');
T::status(200, $done($mp, $po, $view($cust, $po)['pickup']['qr'], ['method' => 'CARD']), 'hand-over of a paid order ignores the method');

$r = $order(Fixture::customer($base, 'pp_cash'), $p, 'PROMPTPAY');
$po2 = $r['body']['data']['id'];
foreach (['accept', 'ready'] as $x) {
    $mp->post("$a/merchant/orders/$po2/$x");
}
$number = Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$po2]);
T::status(200, $done($mp, $po2, $number, ['method' => 'PAY_AT_STORE']), 'chose PromptPay, paid cash');
T::eq(['PAY_AT_STORE', 'PROMPTPAY'], array_values(Database::row('SELECT method, requested_method FROM payments WHERE order_id = ?', [$po2])), 'both are kept');
[$cc, $po3] = $ready($q, $mq);
T::status(422, $mq->post("$a/merchant/orders/$po3/payment", ['method' => 'PROMPTPAY']), 'a shop without PromptPay cannot record it');
T::status(422, $done($mq, $po3, $view($cc, $po3)['order_no'], ['method' => 'PROMPTPAY']), 'also at hand-over');
T::eq(['READY', 'UNPAID'], $state($po3), 'nothing changed');
try {
    Database::run("UPDATE payments SET method = 'CARD' WHERE order_id = ?", [$po3]);
    T::ok(false, 'the database refuses other methods');
} catch (PDOException) {
    T::ok(true, 'the database refuses other methods');
}

T::group('T07 capacity changes while orders are accepted: no deadlock errors');
$k = Fixture::shop('kitchen', ['capacity' => 1]);
$mk = Fixture::login($base, 'merchant', $k['login']);
$ids = [];
for ($i = 0; $i < 6; $i++) {
    $ids[] = Fixture::order($base, "k$i", $k)[1];
}
$requests = [];
foreach ($ids as $i => $id) {
    $requests[] = [$mk, 'POST', "$a/merchant/orders/$id/accept", [], $ports[$i]];
    $requests[] = [$mk, 'PATCH', "$a/merchant/shops/{$k['shop']}/settings", ['capacity' => 1 + $i % 3], $ports[($i + 1) % 6]];
}
T::eq([200], array_values(array_unique(array_column(parallel($requests), 'status'))), 'all answer 200');
$capacity = (int) Database::value('SELECT capacity FROM shops WHERE id = ?', [$k['shop']]);
$cooking = (int) Database::value('SELECT COUNT(*) FROM orders WHERE shop_id = ? AND cooking_started_at IS NOT NULL', [$k['shop']]);
T::ok($cooking >= $capacity && $cooking <= 3, 'slots were filled, never beyond the largest capacity used', [$cooking, $capacity]);

$binary = Browser::find();
if ($binary === null) {
    T::skip('T22-browser', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
T::group('T22 T27 in the browser');
$decode = "new Promise((ok,no)=>{const read=()=>{ok([...document.querySelectorAll('.qr img')].map(i=>{const c=document.createElement('canvas');c.width=i.naturalWidth;c.height=i.naturalHeight;const x=c.getContext('2d');x.drawImage(i,0,0);const d=x.getImageData(0,0,c.width,c.height);return jsQR(d.data,d.width,d.height)?.data??null;}));};if(window.jsQR)return read();const s=document.createElement('script');s.src='../assets/js/vendor/jsQR.min.js';s.onload=read;s.onerror=no;document.head.append(s);})";
$b = new Browser($binary, 9383);
try {
    $b->size(390, 844);
    $b->open("$base/customer/#/");
    $b->click('ฟ้าใส');
    $b->until("document.querySelector('[data-bell]')");
    $demo = new Client($base);
    $demo->csrf = $demo->post("$a/auth/dev", ['customer' => 'CUSTOMER_3'])['body']['data']['csrf'];
    $cart($demo, $p);
    $b->open("$base/customer/#/cart");
    $b->until("document.querySelector('input[value=PROMPTPAY]')");
    $b->js("document.querySelector('input[value=PROMPTPAY]').click()");
    $b->click('ยืนยันออเดอร์');
    T::ok($b->sees('QR รับอาหารจะแสดงเมื่ออาหารพร้อม') && $b->sees('เลือกจ่ายด้วย PromptPay'), 'chosen in the cart; no QR while waiting');
    T::eq(0, $b->js("document.querySelectorAll('.qr').length"), 'no QR at all');
    $bo = (int) Database::value('SELECT MAX(id) FROM orders WHERE shop_id = ?', [$p['shop']]);
    foreach (['accept', 'ready'] as $x) {
        $mp->post("$a/merchant/orders/$bo/$x");
    }
    T::ok($b->sees('รอร้านตรวจสอบยอด', 20) && $b->until("[...document.querySelectorAll('.qr img')].filter(i=>i.naturalWidth>0).length===2") && $b->overflow() === 0, 'both QR codes when ready');
    $mine = $view($demo, $bo);
    T::eq([$mine['pickup']['qr'], $mine['promptpay']['payload']], $b->js($decode), 'jsQR reads back exactly the token and the PromptPay payload');

    $b->size(1280);
    $b->open("$base/merchant/");
    $b->fill('input[name=login]', $p['login']);
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    T::ok($b->sees('ลูกค้าเลือก PromptPay'), 'the card shows the choice');
    $dialog = "document.querySelector('dialog')||document.createElement('i')";
    $b->click('ส่งมอบ');
    $b->fill('dialog input[name=pickup_qr]', $mine['pickup']['qr']);
    $b->click('ตรวจ', $dialog);
    T::ok($b->sees('สลิปของลูกค้าไม่ใช่หลักฐาน') && $b->until("document.querySelector('dialog input[name=method][value=PROMPTPAY]').checked"), 'warning, and the choice is preselected');
    T::eq(['READY', 'UNPAID'], $state($bo), 'nothing recorded before the tap');
    $b->click('ได้รับเงินแล้ว', $dialog);
    T::ok($b->sees('ส่งมอบเรียบร้อย'), 'confirmed');
    T::eq(['COMPLETED', 'PAID', 'PROMPTPAY'], [...$state($bo), Database::value('SELECT method FROM payments WHERE order_id = ?', [$bo])], 'received by PromptPay');
    $b->js("location.hash='#/settings'");
    T::ok($b->sees('รับเงินด้วย PromptPay') && $b->until("document.querySelector('input[name=promptpay_phone]').value==='0812345678'"), 'settings form');
    T::eq([], $b->errors, 'no script errors or CSP violations');
} finally {
    $b->close();
}
