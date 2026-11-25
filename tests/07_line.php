<?php
declare(strict_types=1);

// Uses a fake LINE API. Real LINE must still be tested by hand (docs/line-setup.md).

use App\Support\Clock;
use App\Support\Database;

$a = '/api/v1';
$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qim_push_' . Fixture::run();
file_put_contents($file, '200');
touch($file . '.log');
$mock = Servers::start(8171, ['MOCK_PUSH_FILE' => $file], __DIR__, __DIR__ . '/mock_line.php');
$env = ['LINE_API_BASE' => $mock, 'LINE_LOGIN_CHANNEL_ID' => '1234567890', 'LINE_CHANNEL_SECRET' => 'fake-secret', 'LINE_CHANNEL_ACCESS_TOKEN' => 'fake-token', 'LIFF_ID' => '1234567890-AbcdEfgh', 'RATE_LIMIT_MULTIPLIER' => '1000'];
$line = Servers::start(8172, $env);
$sub = 'U' . bin2hex(random_bytes(16));

$worker = static function (array $extra = []) use ($env): string {
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/notify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $extra + $env + getenv(), ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
};
$drain = static function () use ($worker): void {
    for ($i = 0; $i < 60 && Database::value("SELECT 1 FROM notifications WHERE status IN ('PENDING','SENDING') AND next_attempt_at <= ? LIMIT 1", [Clock::now()]); $i++) {
        $worker();
    }
};
$pushes = static fn (string $to): array => array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($file . '.log')), static fn ($p) => $p['to'] === $to));

T::group('T01 LINE login (fake LINE API)');
$c = new Client($line);
$r = $c->post("$a/auth/line", ['id_token' => "good:$sub"]);
T::status(200, $r, 'valid token');
$c->csrf = $r['body']['data']['csrf'] ?? '';
$user = (int) Database::value('SELECT id FROM users WHERE line_user_id = ?', [$sub]);
T::eq([$user, 'ลูกค้า LINE ทดสอบ'], [$c->get("$a/me")['body']['data']['id'] ?? null, $c->get("$a/me")['body']['data']['display_name'] ?? null], 'own account');
$again = new Client($line);
$again->post("$a/auth/line", ['id_token' => "good:$sub", 'user_id' => 1, 'line_user_id' => 'DEMO_CUSTOMER_1']);
T::eq($user, $again->get("$a/me")['body']['data']['id'] ?? null, 'same account again; ids from the client are ignored');
T::eq('1234567890-AbcdEfgh', $c->get("$a/config")['body']['data']['liff_id'], 'LIFF id exposed to the page');

T::group('T02 invalid LINE tokens');
foreach (['expired' => 'bad:x', 'wrong audience' => "aud:$sub", 'wrong issuer' => "iss:$sub", 'expired claim' => "exp:$sub", 'bad subject' => 'good:DEMO_CUSTOMER_1'] as $label => $token) {
    $x = new Client($line);
    T::status(401, $x->post("$a/auth/line", ['id_token' => $token]), $label, 'INVALID_IDENTITY');
    T::eq([], $x->cookies, "$label: no session");
}
$x = new Client($line);
T::status(503, $x->post("$a/auth/line", ['id_token' => "down:$sub"]), 'LINE is down', 'LINE_UNAVAILABLE');
T::status(422, $x->post("$a/auth/line", ['id_token' => ['x']]), 'token is not text');
Database::update('users', $user, ['active' => 0]);
T::status(403, $x->post("$a/auth/line", ['id_token' => "good:$sub"]), 'disabled account', 'ACCOUNT_DISABLED');
Database::update('users', $user, ['active' => 1]);
T::eq([], $x->cookies, 'no session created');
T::status(503, (new Client($base))->post("$a/auth/line", ['id_token' => 'x']), 'not configured', 'LINE_NOT_CONFIGURED');

T::group('Webhook signature and duplicates');
$hook = static function (array $events, ?string $signature = null) use ($line, $a): array {
    $raw = json_encode(['destination' => 'Ux', 'events' => $events]);
    return (new Client($line))->post("$a/line/webhook", $raw, ['X-Line-Signature: ' . ($signature ?? base64_encode(hash_hmac('sha256', $raw, 'fake-secret', true)))]);
};
$event = static fn (string $type, int $at, string $id) => ['type' => $type, 'timestamp' => $at, 'webhookEventId' => $id, 'source' => ['type' => 'user', 'userId' => $sub]];
$friend = static fn () => Database::value('SELECT line_friend FROM users WHERE id = ?', [$user]);
T::status(200, $hook([]), 'console verify request with no events');
T::status(401, $hook([$event('unfollow', 1000, 'E0' . Fixture::run())], 'AAAA'), 'wrong signature', 'BAD_SIGNATURE');
T::status(401, $hook([], ''), 'missing signature', 'BAD_SIGNATURE');
T::eq(null, $friend(), 'nothing processed');
$e1 = $event('follow', 2000, 'E1' . Fixture::run());
$hook([$e1]);
T::eq(1, $friend(), 'follow');
$hook([$event('unfollow', 3000, 'E2' . Fixture::run())]);
T::eq(0, $friend(), 'unfollow');
$hook([$e1]);
$hook([$event('follow', 2500, 'E3' . Fixture::run())]);
T::eq(0, $friend(), 'redelivered and older events change nothing');
T::eq(1, (int) Database::value('SELECT COUNT(*) FROM line_webhook_events WHERE event_id = ?', ['E1' . Fixture::run()]), 'event stored once');

T::group('T12 notification delivery, failure and retry');
$s = Fixture::shop('line');
$m = Fixture::login($line, 'merchant', $s['login']);
$drain(); // finish what earlier tests queued
$order = static function () use ($c, $a, $s): int {
    $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1]]]);
    return $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']])['body']['data']['id'];
};
$note = static fn (int $o, string $e): ?array => Database::row('SELECT id, status, attempts, next_attempt_at, sent_at, last_error FROM notifications WHERE order_id = ? AND event_type = ?', [$o, $e]);

$o = $order();
$m->post("$a/merchant/orders/$o/accept");
$worker();
T::eq('FAILED', $note($o, 'ORDER_ACCEPTED')['status'], 'blocked OA: not sent');
T::eq(0, count($pushes($sub)), 'no request for a blocked user');
Database::update('users', $user, ['line_friend' => 1]);

$m->post("$a/merchant/orders/$o/prepare");
$m->post("$a/merchant/orders/$o/ready");
file_put_contents($file, '500');
$worker();
$n = $note($o, 'ORDER_READY');
T::eq(['PENDING', 1, 'LINE HTTP 500'], [$n['status'], $n['attempts'], $n['last_error']], 'failure is tracked for retry');
T::ok($n['next_attempt_at'] > Clock::now(), 'backoff');
T::eq('READY', $c->get("$a/orders/$o")['body']['data']['status'], 'order is READY on the web anyway');
$worker();
T::eq(1, $note($o, 'ORDER_READY')['attempts'], 'not retried before its time');
$due = static fn (int $id) => Database::run('UPDATE notifications SET next_attempt_at = ? WHERE id = ?', [Clock::now(), $id]);
$due($n['id']);
$worker(['LINE_API_BASE' => 'http://127.0.0.1:9']);
T::eq('network error', $note($o, 'ORDER_READY')['last_error'], 'network failure is tracked');
file_put_contents($file, '200');
$due($n['id']);
$worker();
$n = $note($o, 'ORDER_READY');
T::eq(['SENT', 3], [$n['status'], $n['attempts']], 'sent on a later attempt');
$sent = $pushes($sub);
T::eq(1, count(array_unique(array_column($sent, 'key'))), 'same retry key every time');
T::ok((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', $sent[0]['key']), 'retry key is a UUID');
T::ok(str_contains(end($sent)['text'], 'Q-001') && str_contains(end($sent)['text'], "https://liff.line.me/1234567890-AbcdEfgh#/order/$o"), 'message has queue and link', end($sent)['text']);
$worker();
T::eq(count($sent), count($pushes($sub)), 'never sent again');

$o2 = $order();
$m->post("$a/merchant/orders/$o2/accept");
file_put_contents($file, '500');
for ($i = 0; $i < 7; $i++) {
    if (($x = $note($o2, 'ORDER_ACCEPTED'))['status'] === 'PENDING') {
        $due($x['id']);
    }
    $worker();
}
$x = $note($o2, 'ORDER_ACCEPTED');
T::eq(['FAILED', 5], [$x['status'], $x['attempts']], 'bounded: stops after 5 attempts');

$o3 = $order();
file_put_contents($file, '400');
$m->post("$a/merchant/orders/$o3/accept");
$worker();
T::eq(['FAILED', 1], [$note($o3, 'ORDER_ACCEPTED')['status'], $note($o3, 'ORDER_ACCEPTED')['attempts']], '400 is permanent');
file_put_contents($file, '409');
$m->post("$a/merchant/orders/$o3/cancel", ['reason' => 'ทดสอบ']);
$worker();
T::eq('SENT', $note($o3, 'ORDER_CANCELLED')['status'], '409 means already accepted by LINE');

file_put_contents($file, '200');
$ids = [];
for ($i = 0; $i < 6; $i++) {
    $ids[] = $x = $order();
    $m->post("$a/merchant/orders/$x/reject", ['reason' => 'ทดสอบ']);
}
$count = count($pushes($sub));
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/notify.php'], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
}
array_map('proc_close', $procs);
T::eq(6, count($pushes($sub)) - $count, '4 parallel workers send each message once');

$o4 = $order();
$m->post("$a/merchant/orders/$o4/accept");
Database::run("UPDATE notifications SET status = 'SENDING', next_attempt_at = ? WHERE order_id = ?", [gmdate('Y-m-d H:i:s', time() - 1), $o4]);
$worker();
T::eq('SENT', $note($o4, 'ORDER_ACCEPTED')['status'], 'crashed worker lease is recovered');
T::ok(str_contains($worker(['LINE_CHANNEL_ACCESS_TOKEN' => '']), 'not set'), 'worker refuses to run unconfigured');
$drain();
T::ok(!str_contains((string) implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/storage/logs/*.log') ?: [])), 'fake-token'), 'token never logged');
unlink($file);
unlink($file . '.log');
