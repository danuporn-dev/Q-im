<?php
declare(strict_types=1);

use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('chk');
$s2 = Fixture::shop('chk2');
$c = Fixture::customer($base, 'chk');
$put = static fn (array $items, int $shop, array $extra = []) => $c->put("$a/cart", ['shop_id' => $shop, 'items' => $items] + $extra);
$line = static fn (int $menu, int $qty = 1, array $options = []) => ['menu_id' => $menu, 'quantity' => $qty, 'option_ids' => $options];
$key = static fn () => bin2hex(random_bytes(16));

T::group('Phase 4 cart');
T::status(401, (new Client($base))->get("$a/cart"), 'cart needs login');
$r = $put([$line($s['menu'], 2, [$s['option']]), $line($s['menu2'])], $s['shop']);
T::status(200, $r, 'fill cart');
T::eq('130.50', $r['body']['data']['total'], 'total = 2 x (45 + 10) + 20.50');
T::eq(3, $r['body']['data']['count'], 'item count');
T::status(409, $put([$line($s['menu'], 1, [$s['option'], $s['option2']])], $s['shop']), 'max options enforced', 'ITEM_UNAVAILABLE');
T::status(409, $put([$line($s['menu'], 1, [$s2['option']])], $s['shop']), 'option of another menu refused');
T::status(409, $put([$line($s2['menu'])], $s['shop']), 'menu of another shop refused');
foreach ([0, -1, 21, 1.5, '2x', null] as $bad) {
    T::status(422, $put([['menu_id' => $s['menu'], 'quantity' => $bad]], $s['shop']), 'bad quantity ' . json_encode($bad));
}
T::status(422, $put([$line($s['menu']) + ['note' => str_repeat('x', 256)]], $s['shop']), 'note too long');

T::group('T04 cart with another shop');
$r = $put([$line($s2['menu'])], $s2['shop']);
T::status(409, $r, 'needs confirmation', 'CART_SHOP_CONFLICT');
T::eq($s['shop'], $c->get("$a/cart")['body']['data']['shop']['id'], 'old cart kept');
T::status(200, $put([$line($s2['menu'])], $s2['shop'], ['replace' => true]), 'confirmed replacement');
T::eq($s2['shop'], $c->get("$a/cart")['body']['data']['shop']['id'], 'cart moved');
$r = $put([$line($s['menu'], 2, [$s['option']]), $line($s['menu2'])], $s['shop'], ['replace' => true]);
$quote = $r['body']['data']['quote'];

T::group('T05 price tampering and price change');
$r = $c->post("$a/orders", ['idempotency_key' => $key(), 'quote' => str_repeat('0', 64), 'total' => '1.00']);
T::status(409, $r, 'forged quote refused', 'PRICE_CHANGED');
Database::update('menus', $s['menu'], ['price' => '50.00']);
$r = $c->post("$a/orders", ['idempotency_key' => $key(), 'quote' => $quote]);
T::status(409, $r, 'changed price needs reconfirmation', 'PRICE_CHANGED');
T::eq('140.50', $r['body']['error']['details']['total'] ?? null, 'new total reported');
$quote = $c->get("$a/cart")['body']['data']['quote'];
T::status(422, $c->post("$a/orders", ['idempotency_key' => 'short', 'quote' => $quote]), 'weak idempotency key');
$k = $key();
$r = $c->post("$a/orders", ['idempotency_key' => $k, 'quote' => $quote, 'total' => '1.00', 'status' => 'COMPLETED', 'queue_number' => 1]);
T::status(201, $r, 'order created');
$order = $r['body']['data'];
T::eq(['140.50', 'PENDING', null], [$order['total'], $order['status'], $order['queue']], 'server decides total, status, queue');
T::ok((bool) preg_match('/^QI\d{6}-[2-9A-Z]{6}$/', $order['order_no']), 'order number format', $order['order_no']);
Database::update('menus', $s['menu'], ['price' => '99.00', 'name' => 'ชื่อใหม่']);
$again = $c->get("$a/orders/{$order['id']}")['body']['data'];
T::eq(['140.50', '50.00', '10.00'], [$again['total'], $again['items'][0]['unit_price'], $again['items'][0]['options'][0]['price_delta']], 'snapshots survive price change');
T::ok($again['items'][0]['name'] !== 'ชื่อใหม่', 'name snapshot');

T::group('T06 double checkout');
$r = $c->post("$a/orders", ['idempotency_key' => $k, 'quote' => $quote]);
T::status(200, $r, 'retry returns the same order');
T::eq($order['id'], $r['body']['data']['id'], 'same id');
T::status(409, $c->post("$a/orders", ['idempotency_key' => $key(), 'quote' => $quote]), 'cart was consumed', 'CART_EMPTY');
$r = $put([$line($s['menu'])], $s['shop']);
$k2 = $key();
$ports = [];
for ($i = 0; $i < 6; $i++) {
    $ports[] = Servers::start(8121 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
$responses = parallel(array_map(static fn ($b) => [$c, 'POST', "$a/orders", ['idempotency_key' => $k2, 'quote' => $r['body']['data']['quote']], $b], $ports));
T::eq(1, count(array_unique(array_map(static fn ($x) => $x['body']['data']['id'] ?? 'x', $responses))), '6 parallel submits give one id', array_column($responses, 'status'));
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM orders WHERE idempotency_key = ?', [$k2]), 'one row in database');
T::eq(1, count(array_filter($responses, static fn ($x) => $x['status'] === 201)), 'created exactly once');
$r = $put([$line($s['menu'])], $s['shop']);
$responses = parallel(array_map(static fn ($b) => [$c, 'POST', "$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $r['body']['data']['quote']], $b], $ports));
T::eq(1, count(array_filter($responses, static fn ($x) => $x['status'] === 201)), 'different keys still consume the cart once', array_column($responses, 'status'));

T::group('T08 closed shop, sold-out menu, full shop');
$submit = static function () use ($c, $a, $key) {
    return $c->post("$a/orders", ['idempotency_key' => $key(), 'quote' => $c->get("$a/cart")['body']['data']['quote'] ?? 'x']);
};
$put([$line($s['menu'])], $s['shop']);
Database::update('shops', $s['shop'], ['accepting_orders' => 0]);
T::status(409, $submit(), 'paused', 'SHOP_CLOSED');
Database::update('shops', $s['shop'], ['accepting_orders' => 1]);
$otherDay = (int) \App\Support\Clock::bangkok()->modify('+3 days')->format('N');
Database::insert('shop_hours', ['shop_id' => $s['shop'], 'day_of_week' => $otherDay, 'open_time' => '08:00', 'close_time' => '09:00']);
T::status(409, $submit(), 'outside hours', 'SHOP_CLOSED');
Database::run('UPDATE shop_hours SET shop_id = ? WHERE shop_id = ?', [$s2['shop'], $s['shop']]);
Database::update('menus', $s['menu'], ['available' => 0]);
T::status(409, $submit(), 'sold out', 'ITEM_UNAVAILABLE');
T::eq(true, $c->get("$a/cart")['body']['data']['has_problems'], 'cart shows the problem');
Database::update('menus', $s['menu'], ['available' => 1]);
Database::update('menu_option_groups', $s['group'], ['min_select' => 1]);
T::status(409, $submit(), 'option became required', 'ITEM_UNAVAILABLE');
Database::update('menu_option_groups', $s['group'], ['min_select' => 0]);
Database::run('UPDATE shop_settings SET max_active_orders = (SELECT COUNT(*) FROM orders WHERE shop_id = ?) WHERE shop_id = ?', [$s['shop'], $s['shop']]);
T::status(409, $submit(), 'shop is full', 'SHOP_FULL');
$active = (int) Database::value('SELECT COUNT(*) FROM orders WHERE shop_id = ?', [$s['shop']]);
Database::run('UPDATE shop_settings SET max_active_orders = ? WHERE shop_id = ?', [$active + 2, $s['shop']]);
$many = [];
foreach ($ports as $i => $b) {
    $x = Fixture::customer($base, "full$i");
    $cart = $x->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [$line($s['menu2'])]]);
    $many[] = [$x, 'POST', "$a/orders", ['idempotency_key' => $key(), 'quote' => $cart['body']['data']['quote']], $b];
}
$codes = array_count_values(array_column(parallel($many), 'status'));
T::eq([2, 4], [$codes[201] ?? 0, $codes[409] ?? 0], 'parallel checkouts never exceed max_active_orders');

T::group('T15 customer isolation');
$x = Fixture::customer($base, 'spy');
T::status(404, $x->get("$a/orders/{$order['id']}"), 'read');
T::status(404, $x->post("$a/orders/{$order['id']}/cancel"), 'cancel');
T::eq([], $x->get("$a/orders")['body']['data'], 'list');
T::eq('PENDING', Database::value('SELECT status FROM orders WHERE id = ?', [$order['id']]), 'order untouched');
T::eq(null, $x->get("$a/cart")['body']['data']['shop'], 'own cart only');

T::group('T10 customer cancel, T18 persistence');
T::status(200, $c->post("$a/orders/{$order['id']}/cancel"), 'cancel pending');
T::status(200, $c->post("$a/orders/{$order['id']}/cancel"), 'repeat is safe');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'CANCELLED'", [$order['id']]), 'one log entry');
T::eq(0, (int) Database::value('SELECT COUNT(*) FROM queues WHERE order_id = ?', [$order['id']]), 'no queue');
$reopened = new Client($base);
$reopened->cookies = $c->cookies;
T::eq('CANCELLED', $reopened->get("$a/orders/{$order['id']}")['body']['data']['status'], 'state restored with the same session');
$relogin = Fixture::customer($base, 'chk');
T::ok(in_array($order['id'], array_column($relogin->get("$a/orders?scope=cancelled")['body']['data'], 'id'), true), 'state restored after new login');
T::ok(count($relogin->get("$a/orders?scope=active")['body']['data']) >= 1, 'active orders listed');
