<?php
declare(strict_types=1);

// Final review of the endpoints and tables added in A0-A6: CSRF on every new mutation, realms, and the
// pickup token when two requests replace it at the same moment.

use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('sec', ['capacity' => 50]);
$m = Fixture::login($base, 'merchant', $s['login']);
[, $adminLogin] = Fixture::staff('admin', 'sec_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $adminLogin);
[$c, $o] = Fixture::order($base, 'sec_a', $s);
$m->post("$a/merchant/orders/$o/accept");
$m->post("$a/merchant/orders/$o/ready");

T::group('T21 CSRF on the new mutations');
[$c2, $o2] = Fixture::order($base, 'sec_b', $s);
foreach (['accept', 'ready'] as $x) {
    $m->post("$a/merchant/orders/$o2/$x");
}
$m->post("$a/merchant/orders/$o2/complete", ['override_reason' => 'ทดสอบ', 'payment_received' => true]);
$review = (int) Database::insert('reviews', ['order_id' => $o2, 'shop_id' => $s['shop'], 'user_id' => (int) Database::value('SELECT user_id FROM orders WHERE id = ?', [$o2]), 'rating' => 3]);
$cases = [
    [$c2, 'POST', "$a/orders/$o2/review", ['rating' => 5]],
    [$c, 'PUT', "$a/favorites/shops/{$s['shop']}", []],
    [$c, 'DELETE', "$a/favorites/menus/{$s['menu']}", []],
    [$c, 'POST', "$a/notifications/read-all", []],
    [$admin, 'PATCH', "$a/admin/reviews/$review", ['hidden' => true, 'reason' => 'ทดสอบ']],
    [$m, 'POST', "$a/merchant/orders/$o/payment", ['method' => 'PAY_AT_STORE']],
    [$m, 'POST', "$a/merchant/orders/$o/complete", ['override_reason' => 'ทดสอบ', 'payment_received' => true]],
    [$m, 'PATCH', "$a/merchant/shops/{$s['shop']}/settings", ['promptpay_phone' => '0812345678', 'promptpay_enabled' => true]],
];
foreach ($cases as [$client, $method, $path, $body]) {
    $token = $client->csrf;
    foreach (['missing' => '', 'wrong' => str_repeat('0', 64)] as $label => $value) {
        $client->csrf = $value;
        T::status(403, $client->call($method, $path, $body), "$label token: $method " . preg_replace('/\d+/', 'N', substr($path, 8)), 'CSRF_FAILED');
    }
    $client->csrf = $token;
    T::status(403, $client->call($method, $path, $body, ['Origin: https://evil.example']), "other site: $method " . preg_replace('/\d+/', 'N', substr($path, 8)), 'CROSS_ORIGIN');
}
T::eq([0, 'READY', 'UNPAID', null, 0], [
    (int) Database::value('SELECT COUNT(*) FROM reviews WHERE order_id = ? AND rating = 5', [$o2]),
    Database::value('SELECT status FROM orders WHERE id = ?', [$o]), Database::value('SELECT status FROM payments WHERE order_id = ?', [$o]),
    Database::value('SELECT hidden_at FROM reviews WHERE id = ?', [$review]), (int) Database::value('SELECT promptpay_enabled FROM shop_settings WHERE shop_id = ?', [$s['shop']]),
], 'none of them changed anything');

T::group('T22 two requests replacing the token after a key change both show the stored one');
$keys = [str_repeat('a', 40), str_repeat('b', 40)];
$groups = [];
foreach ($keys as $i => $key) {
    $groups[] = [Servers::start(8318 + 2 * $i, ['PICKUP_TOKEN_KEY' => $key]), Servers::start(8319 + 2 * $i, ['PICKUP_TOKEN_KEY' => $key])];
}
$bad = [];
for ($round = 0; $round < 12; $round++) {
    $servers = $groups[$round % 2];
    $responses = parallel(array_map(static fn ($b) => [$c, 'GET', "$a/orders/$o", null, $b], $servers));
    $stored = Database::value('SELECT token_hash FROM pickup_tokens WHERE order_id = ?', [$o]);
    foreach ($responses as $r) {
        $qr = $r['body']['data']['pickup']['qr'] ?? '';
        if (hash('sha256', $qr) !== $stored) {
            $bad[] = $round;
        }
    }
}
T::eq([], $bad, 'every response shows the token that is stored');
