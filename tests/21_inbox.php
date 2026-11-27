<?php
declare(strict_types=1);

// T25: notifications on the website (D36): own messages only, independent of LINE, and the shop's bell.

use App\Support\Database;
use App\Support\Env;

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$anon = new Client($base);
$s = Fixture::shop('inbox');
$s2 = Fixture::shop('inbox2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
[$c, $o] = Fixture::order($base, 'inbox_a', $s);
[$other, $o2] = Fixture::order($base, 'inbox_b', $s);
$inbox = static fn (Client $x, string $q = ''): array => $x->get("$a/notifications$q")['body'];
$count = static fn (Client $x): int => $x->get("$a/notifications/count")['body']['data']['unread'];
$outbox = static fn (): array => Database::rows('SELECT id, status, attempts, next_attempt_at, dedupe_key, updated_at FROM notifications WHERE order_id IN (?, ?) ORDER BY id', [$o, $o2]);

T::group('T25 the shop bell');
$bell = static fn (): array => $m->get("$a/merchant/shops/{$s['shop']}/notifications")['body']['data'];
$arrived = (int) Database::value("SELECT MAX(id) FROM order_status_logs WHERE order_id = ? AND to_status = 'PENDING'", [$o2]);
T::eq(['pending' => 2, 'latest_id' => $o2, 'latest' => $arrived], $bell(), 'two orders wait; "latest" is when the newest one reached the shop');
T::status(404, $m2->get("$a/merchant/shops/{$s['shop']}/notifications"), 'another shop');
T::status(401, $c->get("$a/merchant/shops/{$s['shop']}/notifications"), 'a customer');
T::status(401, $anon->get("$a/merchant/shops/{$s['shop']}/notifications"), 'no session');

T::group('T25 customer notifications follow the order events');
T::eq(0, $count($c), 'nothing before the shop answers');
$m->post("$a/merchant/orders/$o/accept");
$m->post("$a/merchant/orders/$o/ready");
$m->post("$a/merchant/orders/$o2/reject", ['reason' => 'วัตถุดิบหมด']);
T::eq(['pending' => 0, 'latest_id' => 0, 'latest' => 0], $bell(), 'the bell is quiet again');
$r = $inbox($c);
T::eq([['ORDER_READY', 'ORDER_ACCEPTED'], 2, 2], [array_column($r['data'], 'type'), $r['meta']['unread'], $r['meta']['total']], 'accepted and ready, newest first');
$keys = array_keys($r['data'][0]);
sort($keys);
T::eq(['created_at', 'id', 'order_id', 'read', 'text', 'type'], $keys, 'only public fields');
$rejected = $inbox($other)['data'];
T::ok(count($rejected) === 1 && str_contains($rejected[0]['text'], 'วัตถุดิบหมด'), 'rejection with its reason, own order only');
[$c3, $o3] = Fixture::order($base, 'inbox_c', $s);
$m->post("$a/merchant/orders/$o3/accept");
$m->post("$a/merchant/orders/$o3/cancel", ['reason' => 'อุปกรณ์ขัดข้อง']);
T::eq(['ORDER_CANCELLED', 'ORDER_ACCEPTED'], array_column($inbox($c3)['data'], 'type'), 'cancellation by the shop');
$secret = 'ลับ' . Fixture::run();
$m->post("$a/merchant/orders/$o/complete", ['override_reason' => $secret, 'payment_received' => true]);
$raw = $c->get("$a/notifications")['raw'];
T::ok(!str_contains($raw, $secret) && !preg_match('/last_error|attempts|line_user_id|dedupe|display_name/', $raw), 'nothing internal', $raw);

T::group('T25 LINE failures do not matter, and reading leaves the outbox alone');
Database::run("UPDATE notifications SET status = 'FAILED', last_error = 'LINE HTTP 400' WHERE order_id = ?", [$o]);
T::eq(2, count($inbox($c)['data']), 'still listed after a failed push');
$before = $outbox();
$first = $inbox($c)['data'][0]['id'];
T::status(404, $other->post("$a/notifications/$first/read"), 'another customer cannot mark it');
T::eq(2, $count($c), 'still unread');
T::status(401, $anon->get("$a/notifications"), 'no session');
T::status(401, $m->get("$a/notifications/count"), 'a merchant session is not a customer');
$csrf = $c->csrf;
$c->csrf = '';
T::status(403, $c->post("$a/notifications/$first/read"), 'CSRF token required', 'CSRF_FAILED');
T::status(403, $c->post("$a/notifications/read-all"), 'also for read all', 'CSRF_FAILED');
$c->csrf = $csrf;
T::status(200, $c->post("$a/notifications/$first/read"), 'read one');
T::status(200, $c->post("$a/notifications/$first/read"), 'twice');
T::eq([1, true], [$count($c), $inbox($c)['data'][0]['read']], 'one left');
$otherUnread = $count($other);
T::status(200, $c->post("$a/notifications/read-all"), 'read all');
T::eq([0, $otherUnread], [$count($c), $count($other)], 'only the own ones');
T::eq($before, $outbox(), 'the outbox rows did not change');
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM notification_reads WHERE notification_id = ?', [$first]), 'one read mark');

$binary = Browser::find();
if ($binary === null) {
    T::skip('T25', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
T::group('T25 in the browser');
$b = new Browser($binary, 9382);
try {
    $b->size(390, 844);
    $b->open("$base/customer/#/");
    $b->click('มินท์');
    $b->until("document.querySelector('[data-bell]')");
    $demo = new Client($base);
    $demo->csrf = $demo->post("$a/auth/dev", ['customer' => 'CUSTOMER_1'])['body']['data']['csrf'];
    $cart = $demo->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]], 'replace' => true]);
    $o4 = $demo->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']])['body']['data']['id'];
    $demo->post("$a/notifications/read-all");
    $m->post("$a/merchant/orders/$o4/accept");
    T::ok($b->sees('แจ้งเตือน 1', 15), 'unread count appears through polling');
    $b->open("$base/customer/#/notifications");
    T::ok($b->sees('รับออเดอร์แล้ว') && $b->sees('ใหม่') && $b->overflow() === 0, 'list');
    $b->click('อ่านแล้วทั้งหมด');
    T::ok($b->until("!document.querySelector('.item.unread')"), 'read all');

    $b->size(1280);
    $b->open("$base/merchant/");
    $b->fill('input[name=login]', $s['login']);
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    T::ok($b->sees('ออเดอร์ใหม่ 0'), 'bell on the shop page');
    $b->click('เปิดเสียงเตือน');
    T::ok($b->sees('เสียงเตือนเปิดอยู่'), 'sound is switched on by a tap');
    $b->js("location.hash='#/menus'");
    $b->sees('เพิ่มเมนู');
    $b->js("window.toasts=0;new MutationObserver(m=>{for(const x of m)for(const n of x.addedNodes)if(n.classList?.contains('toast')&&n.textContent==='มีออเดอร์ใหม่')window.toasts++;}).observe(document.body,{childList:true,subtree:true});");
    Fixture::order($base, 'inbox_new', $s);
    T::ok($b->sees('ออเดอร์ใหม่ 1', 15) && $b->sees('มีออเดอร์ใหม่'), 'alert on another tab');
    $b->js("location.hash='#/reports'");
    $b->sees('ยอดขาย');
    sleep(14);
    T::eq(1, $b->js('window.toasts'), 'the same order is announced once');
    $b->reload();
    T::ok($b->sees('ออเดอร์ใหม่ 1') && !$b->sees('มีออเดอร์ใหม่', 8), 'not announced again after a reload');
    T::eq([], $b->errors, 'no script errors or CSP violations');
} finally {
    $b->close();
}
