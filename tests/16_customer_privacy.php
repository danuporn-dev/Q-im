<?php
declare(strict_types=1);

// What a customer receives about an order: exact fields, and never anything written for the shop or the admin (D29).

use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('priv');
$m = Fixture::login($base, 'merchant', $s['login']);
[, $adminLogin] = Fixture::staff('admin', 'priv_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $adminLogin);
$staffName = (string) Database::value('SELECT display_name FROM users WHERE id = ?', [$s['user']]);
$secret = 'ความลับร้าน' . Fixture::run();

$list = ['accepted_at', 'completed_at', 'created_at', 'id', 'items', 'order_no', 'paid_at', 'pay_by', 'payment_method', 'payment_status', 'preparing_at', 'queue', 'ready_at', 'reason', 'shop', 'status', 'total'];
$detail = [...$list, 'ahead', 'can_cancel', 'eta_minutes', 'pickup', 'promptpay', 'review', 'timeline'];
sort($detail);
$keys = static function (array $x): array {
    $k = array_keys($x);
    sort($k);
    return $k;
};
$check = static function (array $o, array $fields, string $label) use ($keys): void {
    T::eq($fields, $keys($o), "$label: only the agreed fields");
    $bad = $keys($o['shop']) === ['id', 'name'] ? [] : ['shop'];
    if ($o['queue'] !== null && $keys($o['queue']) !== ['business_date', 'number']) {
        $bad[] = 'queue';
    }
    foreach ($o['items'] as $i) {
        // image_url and art: the menu's picture today, or the drawn one (D41).
        if ($keys($i) !== ['art', 'image_url', 'line_total', 'menu_id', 'name', 'note', 'options', 'quantity', 'unit_price']) {
            $bad[] = 'item';
        }
        foreach ($i['options'] as $x) {
            if ($keys($x) !== ['name', 'price_delta']) {
                $bad[] = 'option';
            }
        }
    }
    foreach ($o['timeline'] ?? [] as $t) {
        if ($keys($t) !== ['at', 'reason', 'status'] || ($t['reason'] !== null && !in_array($t['status'], ['REJECTED', 'CANCELLED'], true))) {
            $bad[] = 'timeline';
        }
    }
    T::eq([], $bad, "$label: nested fields");
};
$place = static function (string $tag) use ($base, $a, $s): array {
    $c = Fixture::customer($base, "priv_$tag");
    $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1, 'option_ids' => [$s['option']]]], 'replace' => true]);
    $body = ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']];
    return [$c, $c->post("$a/orders", $body), $body];
};

T::group('T17 hand-over without the code stays internal');
[$c, $r, $body] = $place('ov');
$o = $r['body']['data']['id'];
$check($r['body']['data'], $detail, 'checkout');
foreach (['accept', 'prepare', 'ready', 'payment'] as $x) {
    $m->post("$a/merchant/orders/$o/$x");
    $check($c->get("$a/orders/$o")['body']['data'], $detail, "tracking after $x");
}
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['override_reason' => $secret]), 'hand-over with an internal reason');
$responses = [
    'tracking' => $c->get("$a/orders/$o"),
    'history' => $c->get("$a/orders"),
    'completed' => $c->get("$a/orders?scope=completed"),
    'checkout retry' => $c->post("$a/orders", $body),
    'cancel attempt' => $c->post("$a/orders/$o/cancel"),
    'cart' => $c->get("$a/cart"),
    'me' => $c->get("$a/me"),
    'shop' => $c->get("$a/shops/{$s['shop']}"),
    'menus' => $c->get("$a/shops/{$s['shop']}/menus"),
    'simulated messages' => $c->get("$a/dev/messages"),
];
foreach ($responses as $label => $x) {
    $leaks = array_filter([$secret, 'รับโดยไม่ใช้รหัส', $staffName, $s['login'], 'actor', 'audit', 'override', 'confirmed_by', 'customer_name', 'password', 'idempotency'], static fn (string $w): bool => str_contains($x['raw'], $w));
    T::ok($x['status'] < 500 && $leaks === [], "$label has nothing internal", $leaks);
}
T::eq([200, 'COMPLETED', null], [$responses['tracking']['status'], $responses['tracking']['body']['data']['status'], $responses['tracking']['body']['data']['reason']], 'completed order has no reason');
$check($responses['tracking']['body']['data'], $detail, 'tracking');
$check($responses['checkout retry']['body']['data'], $detail, 'checkout retry');
foreach (['history', 'completed'] as $x) {
    T::eq($o, $responses[$x]['body']['data'][0]['id'] ?? null, "$x lists the order");
    $check($responses[$x]['body']['data'][0], $list, $x);
}
T::ok(str_contains($m->get("$a/merchant/shops/{$s['shop']}/orders?scope=closed")['raw'], $secret), 'the shop still sees its reason');
T::ok(str_contains($admin->get("$a/admin/orders/$o")['raw'], $secret), 'the admin still sees it in the order');
T::ok(str_contains($admin->get("$a/admin/audit-logs?action=pickup.override")['raw'], $secret), 'and in the audit log');

T::group('T10 reasons meant for the customer stay visible');
[$c1, $r] = $place('rej');
$o1 = $r['body']['data']['id'];
$m->post("$a/merchant/orders/$o1/reject", ['reason' => 'วัตถุดิบหมด']);
[$c2, $r] = $place('can');
$o2 = $r['body']['data']['id'];
$m->post("$a/merchant/orders/$o2/accept");
$m->post("$a/merchant/orders/$o2/cancel", ['reason' => 'อุปกรณ์ขัดข้อง']);
foreach ([[$c1, $o1, 'วัตถุดิบหมด'], [$c2, $o2, 'อุปกรณ์ขัดข้อง']] as [$x, $id, $reason]) {
    $v = $x->get("$a/orders/$id")['body']['data'];
    $h = $x->get("$a/orders?scope=cancelled")['body']['data'][0];
    T::eq([$reason, $reason, $reason], [$v['reason'], end($v['timeline'])['reason'], $h['reason']], "{$v['status']}: reason in tracking, timeline and history");
    $check($v, $detail, $v['status']);
    $check($h, $list, "{$v['status']} in history");
}
[$c3, $r] = $place('own');
$x = $c3->post("$a/orders/{$r['body']['data']['id']}/cancel");
T::status(200, $x, 'customer cancels');
$check($x['body']['data'], $detail, 'own cancellation');
