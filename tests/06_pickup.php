<?php
declare(strict_types=1);

use App\Support\Clock;
use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('pick');
$s2 = Fixture::shop('pick2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[$c, $o] = Fixture::order($base, 'pick', $s);
$m->post("$a/merchant/orders/$o/accept");
T::status(409, $m->post("$a/merchant/orders/$o/payment"), 'no payment before READY', 'INVALID_STATE');
$m->post("$a/merchant/orders/$o/prepare");

T::group('T11 PREPARING to READY');
T::status(200, $m->post("$a/merchant/orders/$o/ready"), 'ready');
T::status(200, $m->post("$a/merchant/orders/$o/ready"), 'repeat ready');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND event_type = 'ORDER_READY'", [$o]), 'one ready notification');
$v = $c->get("$a/orders/$o")['body']['data'];
T::eq(['READY', null], [$v['status'], $v['eta_minutes']], 'tracking shows READY and no waiting time');
T::eq(['PENDING', 'ACCEPTED', 'PREPARING', 'READY'], array_column($v['timeline'], 'status'), 'timeline');
T::status(409, $m->post("$a/merchant/orders/$o/cancel", ['reason' => 'x']), 'READY cannot be cancelled at once', 'NO_SHOW_TOO_EARLY');

T::group('T16 complete before paid');
$code = $v['order_no'];
T::ok(!array_key_exists('pickup_code', $v), 'no separate pickup code: the QR holds the order number');
T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $code]), 'refused', 'PAYMENT_REQUIRED');
T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $code, 'payment_received' => 'yes']), 'payment_received must be true', 'PAYMENT_REQUIRED');
T::status(422, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => substr($code, 0, -1) . 'Z', 'payment_received' => true]), 'wrong QR with money', 'PICKUP_QR_WRONG');
T::eq(['READY', 'UNPAID', 0], [Database::value('SELECT status FROM orders WHERE id = ?', [$o]), Database::value('SELECT status FROM payments WHERE order_id = ?', [$o]), (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o])], 'still READY and UNPAID');

T::group('T14 merchant ownership: payment');
T::status(404, $m2->post("$a/merchant/orders/$o/payment"), 'other shop payment');
T::status(404, $m2->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $code, 'payment_received' => true]), 'other shop complete');
T::status(404, $m2->get("$a/merchant/shops/{$s['shop']}/reports"), 'other shop report');

T::group('T17 paid and correct pickup');
$ports = [Servers::start(8151, ['RATE_LIMIT_MULTIPLIER' => '1000']), Servers::start(8152, ['RATE_LIMIT_MULTIPLIER' => '1000'])];
$r = parallel([[$m, 'POST', "$a/merchant/orders/$o/payment", [], $ports[0]], [$m, 'POST', "$a/merchant/orders/$o/payment", [], $ports[1]]]);
T::eq([200, 200], array_column($r, 'status'), 'parallel payment confirmations');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o]), 'payment recorded once');
T::status(422, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => 'ZZZ0']), 'wrong QR', 'PICKUP_QR_WRONG');
T::status(422, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => substr($code, -4)]), 'the last four characters alone are not enough', 'PICKUP_QR_WRONG');
T::status(422, $m->post("$a/merchant/orders/$o/complete", []), 'QR or reason required');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => strtolower($code)]), 'complete');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $code]), 'repeat complete is safe');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'COMPLETED'", [$o]), 'one log entry');
T::ok(in_array($o, array_column($c->get("$a/orders?scope=completed")['body']['data'], 'id'), true), 'customer history');
T::ok(in_array($o, array_column($m->get("$a/merchant/shops/{$s['shop']}/orders?scope=closed")['body']['data'], 'id'), true), 'merchant history');
$rep = $m->get("$a/merchant/shops/{$s['shop']}/reports")['body']['data'];
T::eq(['45.00', 1, 0, 0, 1], [$rep['sales'], $rep['completed'], $rep['rejected'], $rep['cancelled'], (int) $rep['top_menus'][0]['quantity']], 'report');
$yesterday = Clock::bangkok()->modify('-1 day')->format('Y-m-d');
T::eq('0.00', $m->get("$a/merchant/shops/{$s['shop']}/reports?from=$yesterday&to=$yesterday")['body']['data']['sales'], 'other day is empty');
T::status(422, $m->get("$a/merchant/shops/{$s['shop']}/reports?from=2026-02-30"), 'invalid date');

[, $o2] = Fixture::order($base, 'pick_b', $s);
foreach (['accept', 'prepare', 'ready', 'payment'] as $x) {
    $m->post("$a/merchant/orders/$o2/$x");
}
T::status(200, $m->post("$a/merchant/orders/$o2/complete", ['override_reason' => 'โทรศัพท์ลูกค้าแบตหมด']), 'override with a reason');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.override' AND entity_id = ?", [$o2]), 'override audited');

T::group('Three taps: accept, ready, hand-over with the QR (D31)');
[$c3, $o3] = Fixture::order($base, 'pick_c', $s);
T::eq(['PREPARING', true], [$m->post("$a/merchant/orders/$o3/accept")['body']['data']['status'], ($c3->get("$a/orders/$o3")['body']['data']['queue']['number'] ?? 0) >= 1], 'accept goes straight to PREPARING with a queue');
T::status(200, $m->post("$a/merchant/orders/$o3/ready"), 'ready');
$qr = $c3->get("$a/orders/$o3")['body']['data']['order_no'];
$r = parallel(array_map(static fn ($p) => [$m, 'POST', "$a/merchant/orders/$o3/complete", ['pickup_qr' => $qr, 'payment_received' => true], $p], $ports));
T::eq([200, 200], array_column($r, 'status'), 'scan and collect money in one step, double tap is safe');
$p = Database::row('SELECT status, confirmed_by FROM payments WHERE order_id = ?', [$o3]);
T::eq(['COMPLETED', 'PAID', $s['user'], 1, 1, 0], [
    Database::value('SELECT status FROM orders WHERE id = ?', [$o3]), $p['status'], $p['confirmed_by'],
    (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o3]),
    (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'COMPLETED'", [$o3]),
    (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.override' AND entity_id = ?", [$o3]),
], 'completed, paid once by the shop, not an override');
T::eq(['PENDING', 'ACCEPTED', 'PREPARING', 'READY', 'COMPLETED'], array_column($c3->get("$a/orders/$o3")['body']['data']['timeline'], 'status'), 'every state is still logged');
