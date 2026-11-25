<?php
declare(strict_types=1);

// READY -> CANCELLED by the shop: unpaid, waited long enough, with a reason (decision D26).

use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('ns');
$other = Fixture::shop('ns2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $other['login']);
$ready = static function (string $tag, int $minutesAgo) use ($base, $s, $m, $a): array {
    [$c, $o] = Fixture::order($base, "ns_$tag", $s);
    foreach (['accept', 'prepare', 'ready'] as $x) {
        $m->post("$a/merchant/orders/$o/$x");
    }
    Database::update('orders', $o, ['ready_at' => gmdate('Y-m-d H:i:s', time() - $minutesAgo * 60)]);
    return [$c, $o];
};
$status = static fn (int $o) => Database::value('SELECT status FROM orders WHERE id = ?', [$o]);
$effects = static fn (int $o): array => [
    (int) Database::value("SELECT COUNT(*) FROM order_status_logs WHERE order_id = ? AND to_status = 'CANCELLED'", [$o]),
    (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.no_show' AND entity_type = 'order' AND entity_id = ?", [$o]),
    (int) Database::value("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND event_type = 'ORDER_CANCELLED'", [$o]),
];
$cancel = static fn (Client $x, int $o, mixed $reason = 'ลูกค้าไม่มารับ') => $x->post("$a/merchant/orders/$o/cancel", $reason === null ? [] : ['reason' => $reason]);

T::group('T10 no-show refused');
[$c, $o] = $ready('a', 19);
T::status(409, $cancel($m, $o), 'before 20 minutes', 'NO_SHOW_TOO_EARLY');
T::eq(false, $m->get("$a/merchant/orders/$o")['body']['data']['can_no_show'], 'board hides the button');
Database::update('orders', $o, ['ready_at' => gmdate('Y-m-d H:i:s', time() - 1205)]);
T::eq(true, $m->get("$a/merchant/orders/$o")['body']['data']['can_no_show'], 'board offers the button after the wait');
foreach ([null, '', '   ', ['x']] as $bad) {
    T::status(422, $cancel($m, $o, $bad), 'no reason: ' . json_encode($bad));
}
T::status(404, $cancel($m2, $o), 'not a member of the shop');
T::status(401, $cancel($c, $o), 'customer session on the merchant route');
T::status(409, $c->post("$a/orders/$o/cancel"), 'customer cannot cancel READY', 'INVALID_STATE');
[, $adminLogin] = Fixture::staff('admin', 'ns_' . Fixture::run());
T::status(401, $cancel(Fixture::login($base, 'admin', $adminLogin), $o), 'admin cannot either');
T::eq(['READY', [0, 0, 0]], [$status($o), $effects($o)], 'refusals leave no trace');
Database::run('DELETE FROM shop_members WHERE shop_id = ? AND user_id = ?', [$s['shop'], $s['user']]);
T::status(404, $cancel($m, $o), 'removed member');
Database::insert('shop_members', ['shop_id' => $s['shop'], 'user_id' => $s['user']]);

[, $paid] = $ready('paid', 60);
$m->post("$a/merchant/orders/$paid/payment");
T::status(409, $cancel($m, $paid), 'PAID order', 'ALREADY_PAID');
T::eq(false, $m->get("$a/merchant/orders/$paid")['body']['data']['can_no_show'], 'no button for a paid order');
T::eq(['READY', [0, 0, 0]], [$status($paid), $effects($paid)], 'paid order untouched');

T::group('T10 T13 no-show accepted once');
T::status(200, $cancel($m, $o), 'after the wait');
T::eq(['CANCELLED', [1, 1, 1]], [$status($o), $effects($o)], 'status, one log, one audit entry, one notification');
$log = Database::row("SELECT from_status, actor_user_id, reason FROM order_status_logs WHERE order_id = ? AND to_status = 'CANCELLED'", [$o]);
T::eq(['READY', $s['user'], 'ลูกค้าไม่มารับ'], [$log['from_status'], $log['actor_user_id'], $log['reason']], 'log has state, actor, reason');
$audit = Database::row("SELECT actor_user_id, safe_metadata FROM audit_logs WHERE action = 'order.no_show' AND entity_id = ?", [$o]);
T::eq([$s['user'], ['shop_id' => $s['shop'], 'reason' => 'ลูกค้าไม่มารับ']], [$audit['actor_user_id'], json_decode($audit['safe_metadata'], true)], 'audit has actor, shop, reason');
T::status(200, $cancel($m, $o), 'repeat is safe');
T::status(200, $cancel($m, $o, 'เหตุผลอื่น'), 'repeat with another reason is safe');
T::eq([1, 1, 1], $effects($o), 'no second log, audit entry or notification');
T::eq('ลูกค้าไม่มารับ', $c->get("$a/orders/$o")['body']['data']['reason'], 'customer sees the first reason');
foreach (['accept', 'prepare', 'ready', 'payment', 'complete'] as $x) {
    T::status(409, $m->post("$a/merchant/orders/$o/$x", ['override_reason' => 'xxxx']), "terminal refuses $x", 'INVALID_STATE');
}
T::eq('UNPAID', Database::value('SELECT status FROM payments WHERE order_id = ?', [$o]), 'payment stays UNPAID');

T::group('T13 no-show in parallel');
$ports = [];
for ($i = 0; $i < 6; $i++) {
    $ports[] = Servers::start(8221 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
[, $p] = $ready('par', 30);
$codes = array_column(parallel(array_map(static fn ($b) => [$m, 'POST', "$a/merchant/orders/$p/cancel", ['reason' => 'x'], $b], $ports)), 'status');
T::eq([[200], [1, 1, 1]], [array_values(array_unique($codes)), $effects($p)], '6 parallel cancels: one set of effects');
$bad = [];
$wins = ['cancel' => 0, 'payment' => 0];
for ($i = 0; $i < 8; $i++) {
    [, $q] = $ready("race$i", 30);
    $pair = [[$m, 'POST', "$a/merchant/orders/$q/cancel", ['reason' => 'x'], $ports[0]], [$m, 'POST', "$a/merchant/orders/$q/payment", [], $ports[1]]];
    $r = array_column(parallel($i % 2 ? array_reverse($pair) : $pair), 'status');
    $final = [$status($q), Database::value('SELECT status FROM payments WHERE order_id = ?', [$q])];
    sort($r);
    if ($r !== [200, 409] || !in_array($final, [['CANCELLED', 'UNPAID'], ['READY', 'PAID']], true)) {
        $bad[] = [$r, $final];
    }
    $wins[$final[0] === 'CANCELLED' ? 'cancel' : 'payment']++;
}
T::eq([], $bad, "8 races of cancel against payment: exactly one wins (cancel {$wins['cancel']}, payment {$wins['payment']})");

T::group('No-show wait is configurable and shared with the alert');
[, $w] = $ready('cfg', 6);
$five = Servers::start(8231, ['RATE_LIMIT_MULTIPLIER' => '1000', 'READY_ALERT_MINUTES' => '5']);
$m5 = Fixture::login($five, 'merchant', $s['login']);
T::eq(5, $m5->get("$a/merchant/shops/{$s['shop']}/settings")['body']['data']['ready_alert_minutes'], 'alert uses the setting');
T::status(409, $cancel($m, $w), 'default 20: too early', 'NO_SHOW_TOO_EARLY');
T::status(200, $cancel($m5, $w), 'configured 5: allowed');
foreach (['0', '-1', 'abc', '99999', ''] as $i => $value) {
    $x = Servers::start(8241 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000', 'READY_ALERT_MINUTES' => $value]);
    $y = Fixture::login($x, 'merchant', $s['login']);
    T::eq(20, $y->get("$a/merchant/shops/{$s['shop']}/settings")['body']['data']['ready_alert_minutes'], 'invalid setting ' . json_encode($value) . ' falls back to 20');
}
$m->patch("$a/merchant/shops/{$s['shop']}/settings", ['ready_alert_minutes' => 1]);
T::eq(20, $m->get("$a/merchant/shops/{$s['shop']}/settings")['body']['data']['ready_alert_minutes'], 'a shop cannot shorten the wait');
[, $idle] = $ready('idle', 5000);
$m->get("$a/merchant/shops/{$s['shop']}/orders");
T::eq('READY', $status($idle), 'never cancelled automatically');

T::group('T12 no-show notification is sent once (fake LINE API)');
$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qim_ns_' . Fixture::run();
file_put_contents($file, '200');
touch($file . '.log');
$mock = Servers::start(8251, ['MOCK_PUSH_FILE' => $file], __DIR__, __DIR__ . '/mock_line.php');
$sub = 'U' . bin2hex(random_bytes(16));
Database::run('UPDATE users u JOIN orders o ON o.user_id = u.id SET u.line_user_id = ? WHERE o.id = ?', [$sub, $o]);
$env = ['LINE_API_BASE' => $mock, 'LINE_CHANNEL_ACCESS_TOKEN' => 'fake-token'];
for ($i = 0; $i < 3; $i++) {
    proc_close(proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/notify.php'], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]));
    $cancel($m, $o);
}
$pushes = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($file . '.log')), static fn ($x) => $x['to'] === $sub));
$texts = array_column($pushes, 'text');
T::eq(1, count($pushes), 'one message to the customer', $texts);
T::ok(str_contains($texts[0] ?? '', 'ยกเลิก') && str_contains($texts[0] ?? '', 'ลูกค้าไม่มารับ'), 'it is the cancellation with its reason');
T::eq('FAILED', Database::value("SELECT status FROM notifications WHERE order_id = ? AND event_type = 'ORDER_READY'", [$o]), 'the outdated "ready" message is not sent');
unlink($file);
unlink($file . '.log');
