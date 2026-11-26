<?php
declare(strict_types=1);

// D32: kitchen slots for ETAs and cooking times, the wrong-QR limit, and repeated accepts of old orders.

use App\Support\Database;

$a = '/api/v1';
$ago = static fn (int $minutes): string => gmdate('Y-m-d H:i:s', time() - $minutes * 60);
$eta = static fn (Client $c, int $o): ?int => $c->get("$a/orders/$o")['body']['data']['eta_minutes'];

T::group('ETA after D31: a waiting order counts down only from its kitchen slot');
$s = Fixture::shop('slot', ['capacity' => 1]);
$m = Fixture::login($base, 'merchant', $s['login']);
[$ca, $oa] = Fixture::order($base, 'slot_a', $s);
[$cb, $ob] = Fixture::order($base, 'slot_b', $s);
$m->post("$a/merchant/orders/$oa/accept");
$m->post("$a/merchant/orders/$ob/accept");
$started = static fn (int $o): bool => Database::value('SELECT cooking_started_at FROM orders WHERE id = ?', [$o]) !== null;
T::eq([true, false], [$started($oa), $started($ob)], 'capacity 1: A gets the slot, B waits');
T::eq([6, 12], [$eta($ca, $oa), $eta($cb, $ob)], 'A 6 minutes; B waits for A, then 6');
// A has been cooking for 10 minutes (longer than planned); B accepted at the same time has waited 10 minutes.
Database::run('UPDATE orders SET preparing_at = ?, cooking_started_at = ? WHERE id = ?', [$ago(10), $ago(10), $oa]);
Database::run('UPDATE orders SET preparing_at = ? WHERE id = ?', [$ago(10), $ob]);
T::eq(7, $eta($cb, $ob), 'B before A is ready: A overdue counts as 1 minute, then 6');
T::status(200, $m->post("$a/merchant/orders/$oa/ready"), 'A ready at minute 10');
T::ok($started($ob), 'B gets the free slot');
T::eq(6, $eta($cb, $ob), 'B shows about 6 minutes, not 1');
T::eq(0, $cb->get("$a/orders/$ob")['body']['data']['ahead'], 'nobody ahead of B any more');

T::group('Capacity changes give out free slots');
[$cc, $oc] = Fixture::order($base, 'slot_c', $s);
$m->post("$a/merchant/orders/$oc/accept");
T::eq(false, $started($oc), 'C waits behind B');
T::status(200, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['capacity' => 2]), 'shop raises capacity to 2');
T::eq(true, $started($oc), 'C starts at once');
$m->post("$a/merchant/orders/$ob/cancel", ['reason' => 'ทดสอบ']);
$m->post("$a/merchant/orders/$oc/ready");

T::group('Average cooking time leaves out the wait for a slot');
$avg = Fixture::shop('avg', ['capacity' => 1], ['avg_prep_minutes' => 20]);
$ma = Fixture::login($base, 'merchant', $avg['login']);
for ($i = 0; $i < 5; $i++) {
    [, $o] = Fixture::order($base, "avg$i", $avg);
    $ma->post("$a/merchant/orders/$o/accept");
    $ma->post("$a/merchant/orders/$o/ready");
    // Accepted 30 minutes ago, cooked for the last 4 of them.
    Database::run('UPDATE orders SET accepted_at = ?, preparing_at = ?, cooking_started_at = ?, ready_at = ? WHERE id = ?', [$ago(30), $ago(30), $ago(4), $ago(0), $o]);
}
T::eq(4, (new Client($base))->get("$a/shops/{$avg['shop']}")['body']['data']['eta_minutes'], 'shop estimate uses 4 minutes of cooking, not 30');
T::eq(4.0, (float) $ma->get("$a/merchant/shops/{$avg['shop']}/reports")['body']['data']['avg_prep_minutes'], 'report average is 4 minutes');

T::group('T13 accept repeated on an order still ACCEPTED from before D31');
[$co, $old] = Fixture::order($base, 'old', $s);
$m->post("$a/merchant/orders/$old/accept");
Database::run("UPDATE orders SET status = 'ACCEPTED', preparing_at = NULL WHERE id = ?", [$old]);
Database::run("DELETE FROM order_status_logs WHERE order_id = ? AND to_status = 'PREPARING'", [$old]);
$logs = static fn (): int => (int) Database::value('SELECT COUNT(*) FROM order_status_logs WHERE order_id = ?', [$old]);
$before = [$logs(), (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id = ?', [$old])];
$r = $m->post("$a/merchant/orders/$old/accept");
T::status(200, $r, 'safe repeat, not 409');
T::eq(['ACCEPTED', $before], [$r['body']['data']['status'], [$logs(), (int) Database::value('SELECT COUNT(*) FROM notifications WHERE order_id = ?', [$old])]], 'nothing changed');
T::status(200, $m->post("$a/merchant/orders/$old/ready"), 'and it still goes to READY');

T::group('T17 wrong QR codes: counted, audited, locked after 5');
$p = Fixture::shop('lock');
$s2 = Fixture::shop('lock2');
$mp = Fixture::login($base, 'merchant', $p['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[$cp, $op] = Fixture::order($base, 'lock', $p);
$mp->post("$a/merchant/orders/$op/accept");
$mp->post("$a/merchant/orders/$op/ready");
$number = $cp->get("$a/orders/$op")['body']['data']['order_no'];
$audits = static fn (): int => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.qr_wrong' AND entity_id = ?", [$op]);
T::status(404, $m2->post("$a/merchant/orders/$op/complete", ['pickup_qr' => 'QI000000-AAAAAA']), 'other shop: 404');
T::eq(0, $audits(), 'other shop guesses are not counted on this order');
$codes = [];
for ($i = 1; $i <= 4; $i++) {
    $r = $mp->post("$a/merchant/orders/$op/complete", ['pickup_qr' => substr($number, 0, -4) . sprintf('%04d', $i), 'payment_received' => true]);
    $codes[] = $r['body']['error']['code'] ?? $r['status'];
}
T::eq(array_fill(0, 4, 'PICKUP_QR_WRONG'), $codes, 'wrong codes 1 to 4 are refused');
T::eq([4, 'UNPAID', 'READY'], [$audits(), Database::value('SELECT status FROM payments WHERE order_id = ?', [$op]), Database::value('SELECT status FROM orders WHERE id = ?', [$op])], 'each one audited; nothing paid or completed');
$meta = json_decode((string) Database::value("SELECT safe_metadata FROM audit_logs WHERE action = 'pickup.qr_wrong' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$op]), true);
T::eq(['shop_id' => $p['shop'], 'attempt' => 4], $meta, 'audit names the shop and the attempt, not the code');
T::status(409, $mp->post("$a/merchant/orders/$op/complete", ['pickup_qr' => 'WRONG5']), 'fifth wrong code locks scanning', 'PICKUP_QR_LOCKED');
T::status(409, $mp->post("$a/merchant/orders/$op/complete", ['pickup_qr' => $number, 'payment_received' => true]), 'even the right code is refused now', 'PICKUP_QR_LOCKED');
T::eq([5, 5], [$audits(), (int) Database::value('SELECT pickup_failures FROM orders WHERE id = ?', [$op])], 'five audited attempts, no more counted');
T::status(409, $mp->post("$a/merchant/orders/$op/complete", ['override_reason' => 'ตรวจบัตรนักศึกษาแล้ว']), 'hand-over with a reason still needs the money', 'PAYMENT_REQUIRED');
T::status(200, $mp->post("$a/merchant/orders/$op/complete", ['override_reason' => 'ตรวจบัตรนักศึกษาแล้ว', 'payment_received' => true]), 'hand-over with a reason works');
T::eq([1, 'PAID'], [(int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.override' AND entity_id = ?", [$op]), Database::value('SELECT status FROM payments WHERE order_id = ?', [$op])], 'override audited and paid');

T::group('T16 the money is a separate confirmation');
[$cq, $oq] = Fixture::order($base, 'money', $p);
$mp->post("$a/merchant/orders/$oq/accept");
$mp->post("$a/merchant/orders/$oq/ready");
$qr = $cq->get("$a/orders/$oq")['body']['data']['order_no'];
$r = $mp->post("$a/merchant/orders/$oq/complete", ['pickup_qr' => $qr]);
T::status(409, $r, 'right QR, money not confirmed', 'PAYMENT_REQUIRED');
T::eq(['READY', 'UNPAID', 0], [Database::value('SELECT status FROM orders WHERE id = ?', [$oq]), Database::value('SELECT status FROM payments WHERE order_id = ?', [$oq]), (int) Database::value('SELECT pickup_failures FROM orders WHERE id = ?', [$oq])], 'nothing changed and nothing counted');
T::status(200, $mp->post("$a/merchant/orders/$oq/complete", ['pickup_qr' => $qr, 'payment_received' => true]), 'confirmed by the shop');
