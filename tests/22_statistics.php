<?php
declare(strict_types=1);

// T26: shop statistics and the demo history (D37). Every figure is checked against rows made for it.

use App\Support\Clock;
use App\Support\Database;

$a = '/api/v1';
$s = Fixture::shop('stat');
$s2 = Fixture::shop('stat2');
$m = Fixture::login($base, 'merchant', $s['login']);
$m2 = Fixture::login($base, 'merchant', $s2['login']);
$url = "$a/merchant/shops/{$s['shop']}/reports";
// A paid, completed order placed at the given Bangkok hour, $back days before today.
$past = static function (int $back, int $hour, string $tag) use ($base, $a, $s, $m): int {
    [, $o] = Fixture::order($base, "stat_$tag", $s);
    foreach (['accept', 'ready'] as $x) {
        $m->post("$a/merchant/orders/$o/$x");
    }
    $m->post("$a/merchant/orders/$o/complete", ['override_reason' => 'ทดสอบสถิติ', 'payment_received' => true]);
    $at = Clock::bangkok()->modify("-$back day")->setTime($hour, 30)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    Database::run('UPDATE orders SET created_at = ?, placed_at = created_at, cooking_started_at = ?, ready_at = ?, completed_at = ? WHERE id = ?', [$at, $at, gmdate('Y-m-d H:i:s', strtotime("$at UTC") + 360), $at, $o]);
    Database::run('UPDATE payments SET paid_at = ? WHERE order_id = ?', [$at, $o]);
    Database::run('UPDATE order_status_logs SET created_at = ? WHERE order_id = ?', [$at, $o]);
    return $o;
};

T::group('T26 shop statistics');
$past(0, 0, 'today');
$past(6, 12, 'six');
$past(7, 12, 'seven');
$past(29, 23, 'twentynine');
$past(30, 12, 'thirty');
[, $rej] = Fixture::order($base, 'stat_rej', $s);
$m->post("$a/merchant/orders/$rej/reject", ['reason' => 'หมด']);
// Placed at a fixed hour today: "now" would fall into hour 0 or 23 when the test runs around midnight.
Database::run('UPDATE orders SET created_at = ?, placed_at = created_at WHERE id = ?', [Clock::bangkok()->setTime(13, 15)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $rej]);
$r = $m->get($url)['body']['data'];
T::eq([['45.00', 1], ['90.00', 2], ['180.00', 4]], array_map(static fn ($p) => [$p['sales'], $p['paid_orders']], array_values($r['periods'])), 'today, 7 days and 30 days include today and exclude the day before the window');
T::eq([24, 0, 1], [count($r['by_hour']), $r['by_hour'][0]['hour'], $r['by_hour'][0]['orders']], 'half past midnight Bangkok counts in hour 0');
T::eq([2, 1], [array_sum(array_column($r['by_hour'], 'orders')), $r['rejected']], 'hours count every order placed today');
$from = Clock::bangkok()->modify('-29 day')->format('Y-m-d');
$to = Clock::bangkok()->format('Y-m-d');
$r = $m->get("$url?from=$from&to=$to")['body']['data'];
$h = array_column($r['by_hour'], 'orders', 'hour');
T::eq([2, 2, 1], [$h[0] + $h[23], $h[12], $h[23]], 'range: hours in Bangkok time');
T::eq([6.0, null, 0], [(float) $r['avg_prep_minutes'], $r['rating'], $r['review_count']], 'average cooking time and no reviews');
Database::insert('reviews', ['order_id' => $rej, 'shop_id' => $s['shop'], 'user_id' => (int) Database::value('SELECT user_id FROM orders WHERE id = ?', [$rej]), 'rating' => 3]);
T::eq([3.0, 1], [(float) $m->get($url)['body']['data']['rating'], $m->get($url)['body']['data']['review_count']], 'rating average');
T::status(404, $m2->get($url), 'another shop');
T::status(401, (new Client($base))->get($url), 'no session');
T::status(422, $m->get("$url?from=2026-13-01"), 'invalid date');
T::ok(!preg_match('/customer|user_id|display_name/', $m->get("$url?from=$from&to=$to")['raw']), 'no personal data');

T::group('T26 demo-history.php');
$run = static function (array $env) use (&$out): int {
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/demo-history.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return proc_close($p);
};
foreach ([['APP_ENV' => 'production'], ['APP_ENV' => 'staging'], ['DB_NAME' => 'qim_other_db'], ['DB_HOST' => '10.255.255.1']] as $env) {
    T::ok($run($env + ['DB_PORT' => '1']) === 1 && str_contains($out, 'Refusing'), 'refuses ' . json_encode($env), $out);
}
$count = static fn (string $t): int => (int) Database::value("SELECT COUNT(*) FROM $t");
$before = [$count('reviews'), $count('notifications')];
T::eq(0, $run([]), 'creates history');
$history = "o.idempotency_key LIKE 'demohist-%'";
$n = (int) Database::value("SELECT COUNT(*) FROM orders o WHERE $history");
T::ok($n > 50, 'orders were created', [$n, $out]);
T::eq($before, [$count('reviews'), $count('notifications')], 'no reviews, no notifications');
[$todayStart] = Clock::utcRange(Clock::businessDate(), Clock::businessDate());
$range = Database::row("SELECT MIN(created_at) AS first, MAX(created_at) AS last FROM orders o WHERE $history");
T::ok($range['last'] < $todayStart && $range['first'] >= Clock::utcRange(Clock::bangkok()->modify('-14 day')->format('Y-m-d'), Clock::businessDate())[0], 'only the 14 days before today', $range);
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id WHERE $history AND ((o.status = 'COMPLETED') <> (p.status = 'PAID') OR p.amount <> o.total)"), 'completed means paid, with the order total');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o WHERE $history AND o.total <> (SELECT SUM((i.unit_price + COALESCE((SELECT SUM(x.price_delta_snapshot) FROM order_item_options x WHERE x.order_item_id = i.id), 0)) * i.quantity) FROM order_items i WHERE i.order_id = o.id)"), 'totals match the lines');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o LEFT JOIN queues q ON q.order_id = o.id WHERE $history AND (o.status = 'COMPLETED') <> (q.id IS NOT NULL)"), 'a queue number exactly for accepted orders');
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM orders o WHERE $history AND (SELECT l.to_status FROM order_status_logs l WHERE l.order_id = o.id ORDER BY l.id DESC LIMIT 1) <> o.status"), 'the last log is the status');
T::eq(0, $run([]), 'again');
T::eq($n, (int) Database::value("SELECT COUNT(*) FROM orders o WHERE $history"), 'adds nothing the second time');
