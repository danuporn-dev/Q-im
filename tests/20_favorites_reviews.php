<?php
declare(strict_types=1);

// T24: favourites and reviews (D35). Ownership, one review per completed order, anonymity, hiding.

use App\Support\Database;

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$anon = new Client($base);
$s = Fixture::shop('fav');
$s2 = Fixture::shop('fav2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[, $adminLogin] = Fixture::staff('admin', 'fav_' . Fixture::run());
$admin = Fixture::login($base, 'admin', $adminLogin);
$c = Fixture::customer($base, 'fav_one');
$other = Fixture::customer($base, 'fav_two');
$mine = static fn (Client $x): array => $x->get("$a/favorites")['body']['data'];

T::group('T24 favourites');
T::status(401, $anon->get("$a/favorites"), 'needs a customer session');
T::status(401, $m->get("$a/favorites"), 'a merchant session is not enough');
T::status(200, $c->put("$a/favorites/shops/{$s['shop']}", []), 'add a shop');
T::status(200, $c->put("$a/favorites/shops/{$s['shop']}", []), 'adding again is fine');
T::status(200, $c->put("$a/favorites/menus/{$s['menu']}", []), 'add a menu');
T::eq([1, 1], [count($mine($c)['shops']), count($mine($c)['menus'])], 'one of each');
T::eq([[], []], [$mine($other)['shops'], $mine($other)['menus']], 'other customers see nothing of it');
T::status(200, $other->call('DELETE', "$a/favorites/shops/{$s['shop']}"), 'removing something one does not have is fine');
T::eq(1, count($mine($c)['shops']), 'and never touches another customer');
T::status(404, $c->put("$a/favorites/shops/999999999", []), 'unknown shop');
T::status(404, $c->put("$a/favorites/menus/999999999", []), 'unknown menu');
$csrf = $c->csrf;
$c->csrf = '';
T::status(403, $c->put("$a/favorites/shops/{$s2['shop']}", []), 'CSRF token required', 'CSRF_FAILED');
T::status(403, $c->call('DELETE', "$a/favorites/shops/{$s['shop']}"), 'also to remove', 'CSRF_FAILED');
$c->csrf = $csrf;
Database::update('menus', $s['menu'], ['available' => 0]);
T::eq([true, false], [$mine($c)['menus'][0]['listed'], $mine($c)['menus'][0]['available']], 'sold out is marked');
Database::update('menus', $s['menu'], ['active' => 0]);
T::eq([false, null], [$mine($c)['menus'][0]['listed'], $mine($c)['menus'][0]['price']], 'a removed menu stays, without a price');
T::status(404, $other->put("$a/favorites/menus/{$s['menu']}", []), 'and cannot be added');
Database::update('menus', $s['menu'], ['active' => 1, 'available' => 1]);
Database::update('shops', $s['shop'], ['active' => 0]);
T::eq([false, false], [$mine($c)['shops'][0]['listed'], $mine($c)['shops'][0]['is_open']], 'a closed-down shop is marked');
T::status(200, $c->call('DELETE', "$a/favorites/shops/{$s['shop']}"), 'and can be removed');
Database::update('shops', $s['shop'], ['active' => 1]);
T::eq([], $mine($c)['shops'], 'removed');

T::group('T24 who may review');
[$owner, $o] = Fixture::order($base, 'rev_owner', $s);
$stranger = Fixture::customer($base, 'rev_stranger');
T::status(409, $owner->post("$a/orders/$o/review", ['rating' => 5]), 'PENDING', 'NOT_COMPLETED');
foreach (['accept', 'ready'] as $x) {
    $m->post("$a/merchant/orders/$o/$x");
}
T::status(409, $owner->post("$a/orders/$o/review", ['rating' => 5]), 'READY', 'NOT_COMPLETED');
$m->post("$a/merchant/orders/$o/complete", ['override_reason' => 'ทดสอบรีวิว', 'payment_received' => true]);
T::status(404, $stranger->post("$a/orders/$o/review", ['rating' => 1]), 'another customer');
T::status(401, $anon->post("$a/orders/$o/review", ['rating' => 1]), 'no session');
T::status(401, $m->post("$a/orders/$o/review", ['rating' => 5]), 'the shop cannot review itself');
foreach ([[], ['rating' => 0], ['rating' => 6], ['rating' => 4.5], ['rating' => 'x'], ['rating' => 5, 'comment' => str_repeat('ก', 501)], ['rating' => 5, 'comment' => ['x']]] as $body) {
    T::status(422, $owner->post("$a/orders/$o/review", $body), 'invalid: ' . mb_substr(json_encode($body), 0, 40));
}
T::eq(0, (int) Database::value('SELECT COUNT(*) FROM reviews WHERE order_id = ?', [$o]), 'nothing was stored');
$evil = '<img src=x onerror=alert(1)> ' . str_repeat('ก', 470);
T::status(201, $owner->post("$a/orders/$o/review", ['rating' => 4, 'comment' => $evil]), 'owner of a completed order');
T::status(409, $owner->post("$a/orders/$o/review", ['rating' => 1]), 'only once', 'ALREADY_REVIEWED');
T::eq([1, 4], [(int) Database::value('SELECT COUNT(*) FROM reviews WHERE order_id = ?', [$o]), (int) Database::value('SELECT rating FROM reviews WHERE order_id = ?', [$o])], 'the first review stays');

T::group('T24 parallel reviews of one order');
[$racer, $o2] = Fixture::order($base, 'rev_race', $s);
foreach (['accept', 'ready'] as $x) {
    $m->post("$a/merchant/orders/$o2/$x");
}
$m->post("$a/merchant/orders/$o2/complete", ['override_reason' => 'ทดสอบรีวิว', 'payment_received' => true]);
$ports = [];
for ($i = 0; $i < 4; $i++) {
    $ports[] = Servers::start(8296 + $i, ['RATE_LIMIT_MULTIPLIER' => '1000']);
}
$codes = array_column(parallel(array_map(static fn ($p) => [$racer, 'POST', "$a/orders/$o2/review", ['rating' => 2], $p], $ports)), 'status');
sort($codes);
T::eq([201, 409, 409, 409], $codes, 'one wins');

T::group('T24 anonymous and read only');
$name = (string) Database::value('SELECT u.display_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?', [$o]);
$number = (string) Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$o]);
foreach (['public' => $anon->get("$a/shops/{$s['shop']}/reviews"), 'shop' => $m->get("$a/merchant/shops/{$s['shop']}/reviews")] as $label => $r) {
    T::status(200, $r, "$label list");
    $keys = array_keys($r['body']['data'][0]);
    sort($keys);
    T::eq([['comment', 'date', 'rating'], 2], [$keys, count($r['body']['data'])], "$label: only rating, text and date");
    T::ok(!str_contains($r['raw'], $name) && !str_contains($r['raw'], substr($number, 0, -4)) && !preg_match('/user_id|order_id|customer/', $r['raw']), "$label: no author or order", $r['raw']);
}
T::eq([3.0, 2], [(float) $anon->get("$a/shops/{$s['shop']}")['body']['data']['rating'], $anon->get("$a/shops/{$s['shop']}")['body']['data']['review_count']], 'average and count on the shop');
T::status(404, $m2->get("$a/merchant/shops/{$s['shop']}/reviews"), 'another shop cannot read through the merchant API');
foreach (['POST', 'PATCH', 'DELETE'] as $method) {
    T::status(405, $m->call($method, "$a/merchant/shops/{$s['shop']}/reviews", []), "shop: $method is not possible");
}
$id = (int) Database::value('SELECT id FROM reviews WHERE order_id = ?', [$o]);
T::status(401, $m->patch("$a/admin/reviews/$id", ['hidden' => true, 'reason' => 'ร้านพยายามซ่อน']), 'a shop cannot hide');
T::status(401, $owner->patch("$a/admin/reviews/$id", ['hidden' => true, 'reason' => 'ลูกค้าพยายามซ่อน']), 'nor a customer');
$own = $owner->get("$a/orders/$o")['body']['data']['review'];
T::eq([4, false], [$own['rating'], $own['hidden']], 'the owner sees the own review');
T::status(404, $stranger->get("$a/orders/$o"), 'others cannot read the order or its review');

T::group('T24 admin hides a review, with audit');
T::status(422, $admin->patch("$a/admin/reviews/$id", ['hidden' => true]), 'reason required');
T::status(422, $admin->patch("$a/admin/reviews/$id", ['hidden' => 'yes', 'reason' => 'ไม่สุภาพ']), 'boolean required');
T::status(404, $admin->patch("$a/admin/reviews/999999999", ['hidden' => true, 'reason' => 'ไม่สุภาพ']), 'unknown review');
T::status(200, $admin->patch("$a/admin/reviews/$id", ['hidden' => true, 'reason' => 'ไม่สุภาพ']), 'hide');
T::status(200, $admin->patch("$a/admin/reviews/$id", ['hidden' => true, 'reason' => 'ซ้ำ']), 'repeat is safe');
T::eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'review.hide' AND entity_id = ?", [$id]), 'audited once');
$shop = $anon->get("$a/shops/{$s['shop']}")['body']['data'];
T::eq([1, 1, 1], [$shop['review_count'], count($anon->get("$a/shops/{$s['shop']}/reviews")['body']['data']), count($m->get("$a/merchant/shops/{$s['shop']}/reviews")['body']['data'])], 'hidden from the public, the shop and the average');
T::eq(true, $owner->get("$a/orders/$o")['body']['data']['review']['hidden'], 'the owner is told');
T::status(409, $owner->post("$a/orders/$o/review", ['rating' => 5]), 'hiding does not allow a second review', 'ALREADY_REVIEWED');
$r = $admin->get("$a/admin/reviews?q=$number");
T::eq([1, true], [$r['body']['meta']['total'], $r['body']['data'][0]['hidden']], 'admin still finds it');
T::ok(!str_contains($r['raw'], $name), 'without naming the author');
T::status(200, $admin->patch("$a/admin/reviews/$id", ['hidden' => false, 'reason' => 'ซ่อนผิดรายการ']), 'unhide');
T::eq(2, $anon->get("$a/shops/{$s['shop']}")['body']['data']['review_count'], 'back');

$binary = Browser::find();
if ($binary === null) {
    T::skip('T24', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
T::group('T24 in the browser: hostile text stays text');
$b = new Browser($binary, 9381);
try {
    $b->size(390, 844);
    $b->open("$base/customer/#/reviews/{$s['shop']}");
    T::ok($b->sees('<img src=x onerror=alert(1)>') && $b->overflow() === 0, 'public review list');
    $b->open("$base/merchant/");
    $b->fill('input[name=login]', $s['login']);
    $b->fill('input[name=password]', App\Support\Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    $b->sees('ออเดอร์');
    $b->js("location.hash='#/reviews'");
    T::ok($b->sees('คะแนนเฉลี่ย') && $b->sees('<img src=x'), 'shop review page');
    $b->open("$base/admin/");
    $b->fill('input[name=login]', $adminLogin);
    $b->fill('input[name=password]', App\Support\Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    $b->sees('ภาพรวมวันนี้');
    $b->js("location.hash='#/reviews'");
    T::ok($b->sees($number) && $b->sees('<img src=x'), 'admin review page');
    $b->open("$base/customer/#/");
    $b->click('มินท์');
    $b->open("$base/customer/#/shop/{$s['shop']}");
    $b->click('เพิ่มในรายการโปรด');
    T::ok($b->sees('อยู่ในรายการโปรด'), 'heart button');
    $b->open("$base/customer/#/favorites");
    T::ok($b->sees('ร้านโปรด') && $b->sees('เอาออก') && $b->overflow() === 0, 'favourites page');
    T::eq([], $b->errors, 'no script errors, dialogs or CSP violations');
} finally {
    $b->close();
}
