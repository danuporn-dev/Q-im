<?php
declare(strict_types=1);

// Hand-over without the pickup code: allowed with a reason, audited, filterable and counted (decision D27).

use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('ov');
$s2 = Fixture::shop('ov2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[, $login] = Fixture::staff('admin', 'ov_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $login);
$paid = static function (string $tag, array $shop, Client $merchant) use ($base, $a): array {
    [$c, $o] = Fixture::order($base, "ov_$tag", $shop);
    foreach (['accept', 'prepare', 'ready', 'payment'] as $x) {
        $merchant->post("$a/merchant/orders/$o/$x");
    }
    return [$c, $o];
};
$count = static fn (int $o) => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.override' AND entity_id = ?", [$o]);
$shown = static function (array $shop) use ($admin, $a): mixed {
    $name = Database::value('SELECT name FROM shops WHERE id = ?', [$shop['shop']]);
    return $admin->get("$a/admin/shops?q=" . rawurlencode($name))['body']['data'][0]['pickup_overrides'] ?? null;
};

T::group('T17 hand-over without the QR');
[, $o] = $paid('a', $s, $m);
T::eq([0, 0], [$shown($s), $shown($s2)], 'counts start at zero');
foreach ([[], ['override_reason' => ''], ['override_reason' => 'ab'], ['override_reason' => ['x']]] as $body) {
    T::status(422, $m->post("$a/merchant/orders/$o/complete", $body), 'reason required: ' . json_encode($body));
}
T::status(404, $m2->post("$a/merchant/orders/$o/complete", ['override_reason' => 'ร้านอื่น']), 'other shop');
T::eq(0, $count($o), 'refusals are not counted');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['override_reason' => 'โทรศัพท์แบตหมด']), 'shop hands over with a reason');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['override_reason' => 'กดซ้ำ']), 'repeat is safe');
T::eq(1, $count($o), 'one audit entry');
$row = Database::row("SELECT actor_user_id, safe_metadata FROM audit_logs WHERE action = 'pickup.override' AND entity_id = ?", [$o]);
$meta = json_decode($row['safe_metadata'], true);
T::eq([$s['user'], $s['shop'], true], [$row['actor_user_id'], $meta['shop_id'], str_contains($meta['reason'], 'โทรศัพท์แบตหมด')], 'actor, shop and reason');

[$c, $normal] = $paid('b', $s, $m);
$m->post("$a/merchant/orders/$normal/complete", ['pickup_qr' => $c->get("$a/orders/$normal")['body']['data']['order_no']]);
T::eq(['COMPLETED', 0], [Database::value('SELECT status FROM orders WHERE id = ?', [$normal]), $count($normal)], 'hand-over with the QR is not an override');
[, $p] = $paid('c', $s, $m);
$ports = [Servers::start(8261, ['RATE_LIMIT_MULTIPLIER' => '1000']), Servers::start(8262, ['RATE_LIMIT_MULTIPLIER' => '1000'])];
parallel(array_map(static fn ($b) => [$m, 'POST', "$a/merchant/orders/$p/complete", ['override_reason' => 'พร้อมกัน'], $b], $ports));
T::eq(1, $count($p), 'parallel clicks: one audit entry');

T::group('Admin filter and count, read only');
T::eq([2, 0], [$shown($s), $shown($s2)], 'count per shop');
$r = $admin->get("$a/admin/audit-logs?action=pickup.override");
T::eq(['pickup.override'], array_values(array_unique(array_column($r['body']['data'], 'action'))), 'filter returns only this kind');
T::eq((int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pickup.override'"), $r['body']['meta']['total'], 'and all of them');
T::eq(0, $admin->get("$a/admin/audit-logs?action=pickup")['body']['meta']['total'], 'filter is exact');
T::eq(0, $admin->get("$a/admin/audit-logs?action=" . rawurlencode("x' OR '1'='1"))['body']['meta']['total'], 'filter is not injectable');
T::ok($admin->get("$a/admin/audit-logs")['body']['meta']['total'] > $r['body']['meta']['total'], 'no filter shows everything');
T::status(401, $m->get("$a/admin/audit-logs?action=pickup.override"), 'merchants cannot read it');
foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
    T::status(405, $admin->call($method, "$a/admin/audit-logs", []), "$method is not possible");
}
$admin->patch("$a/admin/shops/{$s['shop']}", ['pickup_overrides' => 0]);
T::eq(2, $shown($s), 'the count cannot be edited');
T::status(404, $admin->post("$a/admin/orders/$o/complete"), 'admin has no hand-over route');
