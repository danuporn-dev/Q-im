<?php
declare(strict_types=1);

use App\Support\Clock;
use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('q');
$s2 = Fixture::shop('q2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
$status = static fn (int $id) => Database::value('SELECT status FROM orders WHERE id = ?', [$id]);
$logs = static fn (int $id, string $to) => (int) Database::value('SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = ?', [$id, $to]);
$notes = static fn (int $id, string $event) => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id = ? AND event_type = ?', [$id, $event]);
$place = static fn (string $tag, ?array $shop = null) => Fixture::order($base, $tag, $shop ?? $s);

T::group('T09 accept');
[$c, $o] = $place('a');
$r = $m->get("$a/merchant/shops/{$s['shop']}/orders");
T::eq([$o], array_column($r['body']['data'], 'id'), 'new order on the board');
T::ok(!str_contains($r['raw'], substr(Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$o]), -4) . '"'), 'pickup code hidden from merchant');
$r = $m->post("$a/merchant/orders/$o/accept");
T::status(200, $r, 'accept');
T::eq(['PREPARING', 1, Clock::businessDate()], [$r['body']['data']['status'], $r['body']['data']['queue']['number'], $r['body']['data']['queue']['business_date']], 'queue assigned, cooking starts at once (D31)');
T::eq([1, 1, 1], [$logs($o, 'ACCEPTED'), $logs($o, 'PREPARING'), $notes($o, 'ORDER_ACCEPTED')], 'both logs and one notification saved');
$rows = Database::rows('SELECT l.actor_user_id, l.from_status, l.to_status, o.accepted_at, o.preparing_at FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.id = ? AND l.from_status IS NOT NULL ORDER BY l.id', [$o]);
T::eq([[$s['user'], 'PENDING', 'ACCEPTED'], [$s['user'], 'ACCEPTED', 'PREPARING'], true], [
    [$rows[0]['actor_user_id'], $rows[0]['from_status'], $rows[0]['to_status']], [$rows[1]['actor_user_id'], $rows[1]['from_status'], $rows[1]['to_status']],
    $rows[0]['accepted_at'] !== null && $rows[0]['preparing_at'] !== null,
], 'actor, from states, timestamps');
$view = $c->get("$a/orders/$o")['body']['data'];
T::eq([0, 6, 1, false], [$view['ahead'], $view['eta_minutes'], $view['queue']['number'], $view['can_cancel']], 'customer sees queue, ahead, ETA');

T::group('T13 invalid transitions and repeats');
T::status(200, $m->post("$a/merchant/orders/$o/accept"), 'repeat accept is safe');
T::status(200, $m->post("$a/merchant/orders/$o/prepare"), 'prepare after accept is a safe repeat');
T::eq([1, 1, 1, 1], [$logs($o, 'ACCEPTED'), $logs($o, 'PREPARING'), $notes($o, 'ORDER_ACCEPTED'), (int) Database::value('SELECT COUNT(*) FROM queues WHERE order_id = ?', [$o])], 'no duplicate side effects');
T::status(409, $m->post("$a/merchant/orders/$o/reject", ['reason' => 'x']), 'PREPARING -> REJECTED', 'INVALID_STATE');
T::status(409, $c->post("$a/orders/$o/cancel"), 'customer cannot cancel after accept', 'INVALID_STATE');
T::status(422, $m->post("$a/merchant/orders/$o/cancel"), 'merchant cancel needs a reason');
T::status(404, $m->post("$a/merchant/orders/999999999/accept"), 'unknown order');

T::group('T10 reject and merchant cancel');
[, $p] = $place('b');
T::status(422, $m->post("$a/merchant/orders/$p/reject"), 'reject needs a reason');
T::eq('PENDING', $status($p), 'still pending');
T::status(200, $m->post("$a/merchant/orders/$p/reject", ['reason' => 'วัตถุดิบหมด']), 'reject');
T::eq(['REJECTED', 0, 1], [$status($p), (int) Database::value('SELECT COUNT(*) FROM queues WHERE order_id = ?', [$p]), $notes($p, 'ORDER_REJECTED')], 'terminal, no queue, notified');
foreach (['accept', 'prepare', 'ready'] as $x) {
    T::status(409, $m->post("$a/merchant/orders/$p/$x"), "terminal refuses $x", 'INVALID_STATE');
}
T::status(409, $m->post("$a/merchant/orders/$p/cancel", ['reason' => 'x']), 'terminal refuses cancel', 'INVALID_STATE');
[$cc, $p2] = $place('b2');
$m->post("$a/merchant/orders/$p2/accept");
T::status(200, $m->post("$a/merchant/orders/$p2/cancel", ['reason' => 'ลูกค้าขอ']), 'cancel accepted');
T::eq('ลูกค้าขอ', $cc->get("$a/orders/$p2")['body']['data']['reason'], 'customer sees the reason');

T::group('T14 merchant ownership: orders');
foreach (['accept', 'reject', 'prepare', 'ready', 'cancel'] as $x) {
    $r = $m2->post("$a/merchant/orders/$o/$x", ['reason' => 'x']);
    T::status(404, $r, "other shop $x");
    T::ok(!str_contains($r['raw'], 'QI'), "no data leak on $x");
}
T::status(404, $m2->get("$a/merchant/orders/$o"), 'other shop read');
T::status(404, $m2->get("$a/merchant/shops/{$s['shop']}/orders"), 'other shop list');
T::status(401, $c->post("$a/merchant/orders/$o/prepare"), 'customer session cannot act as merchant');
T::eq('PREPARING', $status($o), 'order untouched');

T::group('Queue position and ETA');
[$c3, $o3] = $place('c');
$m->post("$a/merchant/orders/$o3/accept");
$v = $c3->get("$a/orders/$o3")['body']['data'];
T::eq(1, $v['ahead'], 'cancelled queue numbers are not counted (number ' . $v['queue']['number'] . ')');
T::eq(6, $v['eta_minutes'], 'within capacity 2: cooks alongside the first order');
[$c5, $o5] = $place('c5');
$m->post("$a/merchant/orders/$o5/accept");
$v = $c5->get("$a/orders/$o5")['body']['data'];
T::eq([2, 12], [$v['ahead'], $v['eta_minutes']], 'beyond capacity: ceil((6 + 6) / 2) + 6');
Database::run('UPDATE orders SET preparing_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 7200), $o5]);
T::eq(12, $c5->get("$a/orders/$o5")['body']['data']['eta_minutes'], 'a waiting order does not count down before a slot is free');
$m->post("$a/merchant/orders/$o5/cancel", ['reason' => 'ทดสอบ']);
$shopView = (new Client($base))->get("$a/shops/{$s['shop']}")['body']['data'];
T::eq([2, 11], [$shopView['waiting'], $shopView['eta_minutes']], 'shop summary');
Database::update('shops', $s['shop'], ['capacity' => null]);
T::eq(null, $c3->get("$a/orders/$o3")['body']['data']['eta_minutes'], 'no capacity means no estimate');
Database::update('shops', $s['shop'], ['capacity' => 2]);
Database::run("UPDATE orders SET status = 'PREPARING', preparing_at = ?, cooking_started_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 7200), $o]);
T::eq(1, $c->get("$a/orders/$o")['body']['data']['eta_minutes'], 'overdue ETA is never negative');
// Back to an order accepted before D31, which still waits in ACCEPTED.
Database::run("UPDATE orders SET status = 'ACCEPTED', preparing_at = NULL, cooking_started_at = NULL WHERE id = ?", [$o]);

T::group('T07 concurrent accepts: 24 orders, 48 requests (2 per order) on 8 server processes');
$ports = [];
for ($i = 0; $i < 8; $i++) {
    $ports[] = Servers::start(8131 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
$fresh = Fixture::shop('conc');
$mm = Fixture::login($base, 'merchant', $fresh['login']);
$ids = [];
for ($i = 0; $i < 24; $i++) {
    $ids[] = Fixture::order($base, "n$i", $fresh)[1];
}
$requests = [];
foreach ($ids as $i => $id) {
    $requests[] = [$mm, 'POST', "$a/merchant/orders/$id/accept", [], $ports[$i % 8]];
    $requests[] = [$mm, 'POST', "$a/merchant/orders/$id/accept", [], $ports[($i + 3) % 8]];
}
$responses = parallel($requests);
T::eq([200], array_values(array_unique(array_column($responses, 'status'))), 'all 48 requests answer 200');
T::eq(48, count($responses), '48 requests were sent');
$numbers = array_map('intval', array_column(Database::rows('SELECT queue_number FROM queues WHERE shop_id = ? ORDER BY queue_number', [$fresh['shop']]), 'queue_number'));
T::eq(24, count(array_unique($numbers)), '24 orders have 24 different numbers');
T::eq(range(1, 24), $numbers, 'numbers are continuous from 1 to 24');
T::eq([24, 24], [(int) Database::value('SELECT last_number FROM queue_counters WHERE shop_id = ?', [$fresh['shop']]), (int) Database::value('SELECT COUNT(DISTINCT order_id) FROM queues WHERE shop_id = ?', [$fresh['shop']])], 'counter is 24 and each order has one queue');
T::eq(24, (int) Database::value("SELECT COUNT(*) FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.shop_id = ? AND l.to_status = 'ACCEPTED'", [$fresh['shop']]), 'one ACCEPTED log each');
T::eq(24, (int) Database::value("SELECT COUNT(*) FROM notifications n JOIN orders o ON o.id = n.order_id WHERE o.shop_id = ? AND n.event_type = 'ORDER_ACCEPTED'", [$fresh['shop']]), 'one notification each');
[, $race] = Fixture::order($base, 'race', $fresh);
$responses = parallel([[$mm, 'POST', "$a/merchant/orders/$race/accept", [], $ports[0]], [$mm, 'POST', "$a/merchant/orders/$race/reject", ['reason' => 'x'], $ports[1]]]);
$codes = array_column($responses, 'status');
sort($codes);
T::eq([200, 409], $codes, 'accept against reject: exactly one wins');

T::group('T19 business date rollover');
$tomorrow = Servers::start(8141, ['TEST_CLOCK_OFFSET' => '86400', 'RATE_LIMIT_MULTIPLIER' => '1000']);
[$c4, $o4] = $place('d');
$late = Fixture::login($tomorrow, 'merchant', $s['login']);
$r = $late->post("$a/merchant/orders/$o4/accept");
T::eq([1, Clock::bangkok()->modify('+1 day')->format('Y-m-d')], [$r['body']['data']['queue']['number'] ?? null, $r['body']['data']['queue']['business_date'] ?? null], 'new date starts at 1');
T::eq(2, $c4->get("$a/orders/$o4")['body']['data']['ahead'], 'yesterday\'s open orders are ahead');
T::ok(in_array($o, array_column($late->get("$a/merchant/shops/{$s['shop']}/orders")['body']['data'], 'id'), true), 'old order still on the board');
T::status(200, $late->post("$a/merchant/orders/$o/ready"), 'old ACCEPTED order goes to READY in one tap');
T::eq(['READY', 2, true], [$status($o), $logs($o, 'PREPARING'), Database::value('SELECT preparing_at FROM orders WHERE id = ?', [$o]) !== null], 'old order moved on through PREPARING');
