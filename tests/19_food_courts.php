<?php
declare(strict_types=1);

// T23: food courts, crowd level and the information pages (D34).

use App\Support\Database;

require_once __DIR__ . '/browser.php';

$a = '/api/v1';
$anon = new Client($base);
$court = Database::insert('food_courts', ['name' => 'TEST court ' . Fixture::run(), 'location' => 'ตึกทดสอบ']);
$empty = Database::insert('food_courts', ['name' => 'TEST empty ' . Fixture::run()]);
$hidden = Database::insert('food_courts', ['name' => 'TEST hidden ' . Fixture::run(), 'active' => 0]);
Fixture::shop('hid', ['food_court_id' => $hidden]);
$s1 = Fixture::shop('fc1', ['food_court_id' => $court, 'capacity' => 2], ['max_active_orders' => 4]);
$s2 = Fixture::shop('fc2', ['food_court_id' => $court, 'capacity' => 4], ['max_active_orders' => null]);
$m = Fixture::login($base, 'merchant', $s1['login']);
$shop = static fn (int $id): array => $anon->get("$a/shops/$id")['body']['data'];
$one = static function (int $id) use ($anon, $a): ?array {
    foreach ($anon->get("$a/food-courts")['body']['data'] as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
};
$cook = static function (array $s, Client $merchant, int $n, string $tag) use ($base, $a): array {
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = $id = Fixture::order($base, "$tag$i", $s)[1];
        $merchant->post("$a/merchant/orders/$id/accept");
    }
    return $ids;
};

T::group('T23 food courts');
$list = $anon->get("$a/food-courts")['body']['data'];
$ids = array_column($list, 'id');
T::eq([true, false, false], [in_array($court, $ids, true), in_array($empty, $ids, true), in_array($hidden, $ids, true)], 'only active courts that have a visible shop');
$row = $one($court);
$keys = array_keys($row);
sort($keys);
T::eq(['crowd', 'id', 'location', 'name', 'open_shops', 'shops'], $keys, 'only public fields');
T::eq([2, 2, 'LOW'], [$row['shops'], $row['open_shops'], $row['crowd']], 'two open shops, nothing waiting');
$r = $anon->get("$a/shops?food_court_id=$court");
T::eq([$s1['shop'], $s2['shop']], [min(array_column($r['body']['data'], 'id')), max(array_column($r['body']['data'], 'id'))], 'filter by food court');
T::eq(2, $r['body']['meta']['total'], 'and only those');
T::eq(0, $anon->get("$a/shops?food_court_id=$hidden")['body']['meta']['total'], 'a hidden court shows nothing');
T::ok($anon->get("$a/shops?food_court_id=" . rawurlencode("1 OR 1=1"))['status'] === 200 && $anon->get("$a/shops?food_court_id=abc")['body']['meta']['total'] > 2, 'a value that is not a number is ignored or reduced to one');

T::group('T23 crowd level: PREPARING against max_active_orders, or capacity');
$mine = $cook($s1, $m, 1, 'a');
T::eq('LOW', $shop($s1['shop'])['crowd'], '1 of 4 = 25%');
$mine = [...$mine, ...$cook($s1, $m, 1, 'b')];
T::eq('MEDIUM', $shop($s1['shop'])['crowd'], '2 of 4 = 50%');
$mine = [...$mine, ...$cook($s1, $m, 1, 'c')];
T::eq('HIGH', $shop($s1['shop'])['crowd'], '3 of 4 = 75%');
T::eq('LOW', $shop($s2['shop'])['crowd'], 'no maximum: capacity 4 is the limit, nothing waiting');
T::eq('LOW', $one($court)['crowd'], 'court: 3 of 8 = 37%');
$m->post("$a/merchant/orders/{$mine[0]}/ready");
T::eq('MEDIUM', $shop($s1['shop'])['crowd'], 'a ready order no longer counts');
Database::update('shops', $s2['shop'], ['accepting_orders' => 0]);
T::eq(['MEDIUM', 1], [$one($court)['crowd'], $one($court)['open_shops']], 'a closed idle shop is left out of the court level: 2 of 4');
Database::update('shops', $s2['shop'], ['capacity' => null, 'accepting_orders' => 1]);
T::eq(null, $shop($s2['shop'])['crowd'], 'no limit at all: no level');
$strict = Servers::start(8294, ['CROWD_MEDIUM_PERCENT' => '10', 'CROWD_HIGH_PERCENT' => '50']);
T::eq('HIGH', (new Client($strict))->get("$a/shops/{$s1['shop']}")['body']['data']['crowd'], 'thresholds can be set');
$bad = Servers::start(8295, ['CROWD_MEDIUM_PERCENT' => 'x', 'CROWD_HIGH_PERCENT' => '0']);
T::eq('MEDIUM', (new Client($bad))->get("$a/shops/{$s1['shop']}")['body']['data']['crowd'], 'invalid thresholds keep the defaults');

$binary = Browser::find();
if ($binary === null) {
    T::skip('T23', 'no Chrome or Edge; set CHROME_PATH');
    return;
}
T::group('T23 choosing a court in the browser, and the information pages');
$b = new Browser($binary, 9380);
try {
    $b->size(390, 844);
    $b->open("$base/customer/");
    $b->until("document.querySelector('select[name=food_court]')");
    $b->js("{const s=document.querySelector('select[name=food_court]');s.value='$court';s.dispatchEvent(new Event('change'));}");
    T::ok($b->until("document.querySelectorAll('.list a.item').length===2") && $b->sees('ความหนาแน่นปานกลาง'), 'two shops of the court, with a text level');
    $b->open("$base/customer/#/");
    $b->reload();
    T::ok($b->until("document.querySelector('select[name=food_court]')?.value==='$court' && document.querySelectorAll('.list a.item').length===2"), 'the choice is remembered');
    foreach (['privacy' => 'ข้อมูลที่ระบบเก็บ', 'faq' => 'ต้องจองคิวไหม', 'about' => 'คิวอิ่ม'] as $page => $text) {
        $b->open("$base/customer/#/$page");
        T::ok($b->sees('ฉบับร่าง') && $b->sees($text) && $b->overflow() === 0, "$page: readable without signing in, marked as a draft");
    }
    T::eq([], $b->errors, 'no script errors');
} finally {
    $b->close();
}
