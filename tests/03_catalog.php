<?php
declare(strict_types=1);

use App\Services\Catalog;
use App\Support\Clock;
use App\Support\Database;

T::group('Phase 3 discovery and menu management (T03)');
$a = '/api/v1';
$s = Fixture::shop('cat');
$other = Fixture::shop('cat2');
$anon = new Client($base);
$name = Database::value('SELECT name FROM menus WHERE id = ?', [$s['menu']]);

$r = $anon->get("$a/shops?q=" . rawurlencode($name));
T::eq([$s['shop']], array_column($r['body']['data'] ?? [], 'id'), 'search by menu name');
T::eq($name, $r['body']['data'][0]['matched_menus'][0]['name'] ?? null, 'matched menu shown');
T::eq([0, 5, true], [$r['body']['data'][0]['waiting'] ?? null, $r['body']['data'][0]['eta_minutes'] ?? null, $r['body']['data'][0]['is_open'] ?? null], 'queue, ETA and open state');
T::eq(0, count($anon->get("$a/shops?q=" . rawurlencode('%_%' . Fixture::run()))['body']['data']), 'LIKE wildcards are literal');
T::status(200, $anon->get("$a/shops?q=" . rawurlencode("' OR 1=1 -- ")), 'SQL text in search is harmless');
$r = $anon->get("$a/shops/{$s['shop']}/menus");
$menu = array_values(array_filter($r['body']['data']['menus'], static fn ($m) => $m['id'] === $s['menu']))[0];
T::eq('45.00', $menu['price'], 'price is a decimal string');
T::eq('10.00', $menu['option_groups'][0]['options'][0]['price_delta'] ?? null, 'options listed');
T::status(404, $anon->get("$a/shops/999999999"), 'unknown shop');
T::status(401, $anon->get("$a/merchant/shops/{$s['shop']}/menus"), 'merchant API needs login');

$m = Fixture::login($base, 'merchant', $s['login']);
T::status(200, $m->patch("$a/merchant/menus/{$s['menu2']}", ['available' => false]), 'mark sold out');
$menus = array_column($anon->get("$a/shops/{$s['shop']}/menus")['body']['data']['menus'], 'available', 'id');
T::eq(false, $menus[$s['menu2']], 'sold out is visible');
$r = $m->post("$a/merchant/shops/{$s['shop']}/menus", ['name' => '<script>alert(1)</script>', 'price' => '12.50', 'option_groups' => [
    ['name' => 'เผ็ด', 'min_select' => 1, 'max_select' => 1, 'options' => [['name' => 'น้อย', 'price_delta' => '0'], ['name' => 'มาก', 'price_delta' => '5']]],
]]);
T::status(201, $r, 'create menu with options');
$new = $r['body']['data'];
$group = $new['option_groups'][0];
$group['options'] = [$group['options'][1]];
$r = $m->patch("$a/merchant/menus/{$new['id']}", ['option_groups' => [$group]]);
T::status(200, $r, 'retire one option');
$public = array_values(array_filter($anon->get("$a/shops/{$s['shop']}/menus")['body']['data']['menus'], static fn ($x) => $x['id'] === $new['id']))[0];
T::eq(['มาก'], array_column($public['option_groups'][0]['options'], 'name'), 'retired option hidden');
T::eq(2, (int) Database::value('SELECT COUNT(*) FROM menu_options WHERE group_id = ?', [$group['id']]), 'retired option kept in database');
T::status(200, $m->patch("$a/merchant/menus/{$new['id']}", ['active' => false]), 'retire menu');
T::ok(!in_array($new['id'], array_column($anon->get("$a/shops/{$s['shop']}/menus")['body']['data']['menus'], 'id'), true), 'retired menu hidden');

foreach ([['price' => '-1'], ['price' => '1.999'], ['price' => 'abc'], ['name' => ''], ['name' => str_repeat('ก', 151)], ['available' => 'yes'], ['image_url' => 'javascript:alert(1)'],
    ['option_groups' => [['name' => 'x', 'min_select' => 2, 'max_select' => 1, 'options' => [['name' => 'a', 'price_delta' => '0']]]]]] as $bad) {
    T::status(422, $m->patch("$a/merchant/menus/{$s['menu']}", $bad), 'invalid ' . json_encode($bad, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)[0] . array_key_first($bad));
}
$foreign = Database::value('SELECT category_id FROM menus WHERE id = ?', [$other['menu']]);
T::status(422, $m->patch("$a/merchant/menus/{$s['menu']}", ['category_id' => $foreign]), 'category of another shop');
T::status(422, $m->patch("$a/merchant/menus/{$s['menu']}", ['option_groups' => [['id' => $other['group'], 'name' => 'x', 'min_select' => 0, 'max_select' => 1, 'options' => [['name' => 'a', 'price_delta' => '0']]]]]), 'option group of another shop');
T::eq('เพิ่ม', Database::value('SELECT name FROM menu_option_groups WHERE id = ?', [$other['group']]), 'other shop untouched');

T::group('T14 merchant ownership: menus and settings');
T::status(404, $m->get("$a/merchant/shops/{$other['shop']}/menus"), 'read other menus');
T::status(404, $m->patch("$a/merchant/menus/{$other['menu']}", ['price' => '1']), 'edit other menu');
T::status(404, $m->post("$a/merchant/shops/{$other['shop']}/menus", ['name' => 'x', 'price' => '1']), 'create in other shop');
T::status(404, $m->get("$a/merchant/shops/{$other['shop']}/settings"), 'read other settings');
T::status(404, $m->patch("$a/merchant/shops/{$other['shop']}/settings", ['accepting_orders' => false]), 'edit other settings');
T::status(404, $m->post("$a/merchant/shops/{$other['shop']}/categories", ['name' => 'x']), 'category in other shop');
T::status(404, $m->patch("$a/merchant/shops/{$s['shop']}/categories/$foreign", ['name' => 'x']), 'edit other category through own shop');

T::group('Phase 3 settings, hours, images');
$r = $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['capacity' => 4, 'avg_prep_minutes' => 9, 'max_active_orders' => 7, 'hours' => [['day_of_week' => 1, 'open_time' => '08:00', 'close_time' => '16:00']]]);
T::status(200, $r, 'update settings');
T::eq([4, 9, 7, 1], [$r['body']['data']['capacity'], $r['body']['data']['avg_prep_minutes'], $r['body']['data']['max_active_orders'], count($r['body']['data']['hours'])], 'settings saved');
T::status(422, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['capacity' => 0]), 'capacity must be positive');
T::status(422, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['hours' => [['day_of_week' => 8, 'open_time' => '08:00', 'close_time' => '16:00']]]), 'bad day');
$m->patch("$a/merchant/shops/{$s['shop']}/settings", ['hours' => []]);
$now = Clock::bangkok();
$today = (int) $now->format('N');
$yesterday = $today === 1 ? 7 : $today - 1;
$later = ($now->format('H:i') < '23:58') ? '23:59' : '23:58';
T::eq(true, Catalog::withinHours([['day_of_week' => $today, 'open_time' => '00:00', 'close_time' => '23:59']]) || $now->format('H:i') === '23:59', 'inside hours');
T::eq(false, Catalog::withinHours([['day_of_week' => $yesterday, 'open_time' => '00:00', 'close_time' => '23:59']]), 'other day closed');
T::eq($now->format('H:i') < '23:59', Catalog::withinHours([['day_of_week' => $yesterday, 'open_time' => '23:59', 'close_time' => '23:58']]) || $now->format('H:i') === '23:58', 'overnight hours from yesterday');

$m->patch("$a/merchant/shops/{$s['shop']}/settings", ['accepting_orders' => false]);
T::eq(false, $anon->get("$a/shops/{$s['shop']}")['body']['data']['is_open'], 'paused shop is closed');
$m->patch("$a/merchant/shops/{$s['shop']}/settings", ['accepting_orders' => true]);

$png = tempnam(sys_get_temp_dir(), 'qim');
$img = imagecreatetruecolor(1600, 800);
imagepng($img, $png);
$r = $m->post("$a/merchant/shops/{$s['shop']}/images", ['__multipart' => ['image' => new CURLFile($png, 'image/png', 'x.php')]]);
T::status(201, $r, 'upload image');
$key = substr($r['body']['data']['image_url'] ?? 'image:', 6);
$r = $anon->get("$a/images/$key");
T::ok($r['status'] === 200 && str_contains($r['head'], 'image/jpeg'), 'image served as JPEG');
T::eq(1200, getimagesizefromstring($r['raw'])[0] ?? null, 'image resized');
file_put_contents($png, '<?php echo 1;');
T::status(422, $m->post("$a/merchant/shops/{$s['shop']}/images", ['__multipart' => ['image' => new CURLFile($png, 'image/png', 'x.png')]]), 'PHP disguised as PNG refused');
unlink($png);
T::ok((int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ? AND action = 'menu.save'", [$s['user']]) >= 3, 'changes audited');
