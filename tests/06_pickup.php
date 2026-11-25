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
T::status(409, $m->post("$a/merchant/orders/$o/cancel", ['reason' => 'x']), 'READY cannot be cancelled', 'INVALID_STATE');

T::group('T16 complete before paid');
$code = $v['pickup_code'];
T::status(409, $m->post("$a/merchant/orders/$o/complete", ['pickup_code' => $code]), 'refused', 'PAYMENT_REQUIRED');
T::eq('READY', Database::value('SELECT status FROM orders WHERE id = ?', [$o]), 'still READY');

T::group('T14 merchant ownership: payment');
T::status(404, $m2->post("$a/merchant/orders/$o/payment"), 'other shop payment');
T::status(404, $m2->post("$a/merchant/orders/$o/complete", ['pickup_code' => $code]), 'other shop complete');
T::status(404, $m2->get("$a/merchant/shops/{$s['shop']}/reports"), 'other shop report');

T::group('T17 paid and correct pickup');
$ports = [Servers::start(8151, ['RATE_LIMIT_MULTIPLIER' => '1000']), Servers::start(8152, ['RATE_LIMIT_MULTIPLIER' => '1000'])];
$r = parallel([[$m, 'POST', "$a/merchant/orders/$o/payment", [], $ports[0]], [$m, 'POST', "$a/merchant/orders/$o/payment", [], $ports[1]]]);
T::eq([200, 200], array_column($r, 'status'), 'parallel payment confirmations');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.confirm' AND entity_id = ?", [$o]), 'payment recorded once');
T::status(422, $m->post("$a/merchant/orders/$o/complete", ['pickup_code' => 'ZZZ0']), 'wrong code', 'PICKUP_CODE_WRONG');
T::status(422, $m->post("$a/merchant/orders/$o/complete", []), 'code or reason required');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_code' => strtolower($code)]), 'complete');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_code' => $code]), 'repeat complete is safe');
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
