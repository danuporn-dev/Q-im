<?php
declare(strict_types=1);

// Usage: php scripts/ui-screenshots.php [out-dir]   (development only; needs Chrome or Edge)
// Screenshots of the main pages with the demo data, for comparing with the Figma frames in docs/ui-compare/figma/.
// Runs on the throw-away qim_test like the tests, never on qim_dev. Default out-dir: docs/ui-compare/app.

use App\Support\Database;
use App\Support\Env;

require dirname(__DIR__) . '/tests/lib.php';
require dirname(__DIR__) . '/tests/fixture.php';
require dirname(__DIR__) . '/tests/browser.php';

$out = $argv[1] ?? dirname(__DIR__) . '/docs/ui-compare/app';
@mkdir($out, 0777, true);
$binary = Browser::find() ?? exit("No Chrome or Edge; set CHROME_PATH.\n");

// The demo shops instead of the ordinary seed (as in tests/15_demo.php).
Database::run("UPDATE food_courts SET active = 0 WHERE name = 'โรงอาหารกลาง (Demo)'");
Database::run("DELETE m FROM shop_members m JOIN shops s ON s.id = m.shop_id JOIN food_courts f ON f.id = s.food_court_id WHERE f.name = 'โรงอาหารกลาง (Demo)'");
foreach (['seed-demo.php', 'demo-history.php'] as $script) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . "/scripts/$script") . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        exit("$script failed: " . implode("\n", $lines) . "\n");
    }
}
$base = Servers::start(8330, ['RATE_LIMIT_MULTIPLIER' => '1000']);
$a = '/api/v1';
$shop = (int) Database::value("SELECT id FROM shops WHERE name = 'ครัวป้าแดง อาหารตามสั่ง'");
$m = Fixture::login($base, 'merchant', 'demo_merchant_1');
$m->patch("$a/merchant/shops/$shop/settings", ['promptpay_phone' => '0812345678', 'promptpay_enabled' => true]);
// Other customers' orders, so the shop board has something in each column.
$menus = $m->get("$a/merchant/shops/$shop/menus")['body']['data']['menus'];
$others = [];
foreach (['CUSTOMER_2', 'CUSTOMER_3', 'CUSTOMER_2'] as $i => $who) {
    $c = new Client($base);
    $c->csrf = $c->post("$a/auth/dev", ['customer' => $who])['body']['data']['csrf'];
    $menu = $menus[$i + 1];
    $options = [];
    foreach ($menu['option_groups'] as $g) {
        if ($g['min_select'] > 0) {
            $options[] = $g['options'][0]['id'];
        }
    }
    $cart = $c->put("$a/cart", ['shop_id' => $shop, 'items' => [['menu_id' => $menu['id'], 'quantity' => 1 + $i % 2, 'option_ids' => $options, 'note' => $i === 0 ? 'ไม่ใส่ผักชี' : null]], 'replace' => true]);
    $others[] = $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']])['body']['data']['id'];
}
$m->post("$a/merchant/orders/{$others[1]}/accept");
$m->post("$a/merchant/orders/{$others[2]}/accept");
$m->post("$a/merchant/orders/{$others[2]}/ready");

$shot = static function (Browser $b, string $name, int $w, int $h) use ($out): void {
    $b->size($w, $h);
    $b->until("!document.querySelector('.spinner') && [...document.images].every(i => i.complete)");
    usleep(600000);
    file_put_contents("$out/$name.png", base64_decode($b->send('Page.captureScreenshot')['data']));
    $o = $b->overflow();
    echo $o > 0 ? "OVERFLOW $name by $o px\n" : "  $name\n";
};
$dialog = "document.querySelector('dialog')||document.createElement('i')";
$b = new Browser($binary, 9384);
try {
    $b->size(393, 852);
    $b->open("$base/customer/");
    $b->sees('ร้านในโรงอาหาร');
    $shot($b, 'c01-home-signed-out', 393, 852);
    $b->click('มินท์');
    $b->until("document.querySelector('[data-bell]')");
    $b->js("{const s=document.querySelector('select[name=food_court]');s.value=s.options[1].value;s.dispatchEvent(new Event('change'));}");
    $b->sees('เปิด 3 จาก 3 ร้าน');
    $shot($b, 'c01-home', 393, 852);
    $b->open("$base/customer/#/search");
    $b->fill('input[type=search]', 'ข้าว');
    $b->until("document.querySelectorAll('.list a.item').length > 0");
    $shot($b, 'c03-search-results', 393, 852);
    $b->open("$base/customer/#/shop/$shop");
    $b->sees('ข้าวกะเพราหมูสับ');
    $shot($b, 'c04-shop', 393, 852);
    $b->click('ข้าวกะเพราหมูสับ');
    $b->sees('เพิ่มลงตะกร้า');
    $shot($b, 'c04-food', 393, 852);
    $b->click('เพิ่มลงตะกร้า', $dialog);
    $b->sees('ตะกร้า 1 รายการ');
    $b->open("$base/customer/#/cart");
    $b->until("document.querySelector('input[value=PROMPTPAY]')");
    $b->js("document.querySelector('input[value=PROMPTPAY]').click()");
    $shot($b, 'c05-cart-payment', 393, 852);
    $b->click('ยืนยันออเดอร์');
    $b->sees('รอร้านยืนยัน');
    $shot($b, 'c06-track-pending', 393, 852);
    $mine = (int) Database::value("SELECT o.id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.line_user_id = 'DEMO_CUSTOMER_1' ORDER BY o.id DESC LIMIT 1");
    $m->post("$a/merchant/orders/$mine/accept");
    $b->sees('ร้านกำลังทำ', 15);
    $shot($b, 'c07-track-preparing', 393, 852);
    $m->post("$a/merchant/orders/$mine/ready");
    $b->sees('รอร้านตรวจสอบยอด', 15);
    $shot($b, 'c08-track-ready', 393, 852);
    $b->open("$base/customer/#/notifications");
    $b->sees('รับออเดอร์แล้ว');
    $shot($b, 'c09-notifications', 393, 852);
    $b->open("$base/customer/#/profile");
    $shot($b, 'c10-profile', 393, 852);

    $b->open("$base/merchant/");
    $b->sees('รหัสผ่าน');
    $shot($b, 'm00-login', 393, 852);
    $b->fill('input[name=login]', 'demo_merchant_1');
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    $b->sees('พร้อมรับ');
    $shot($b, 'm01-board-1280', 1280, 800);
    $shot($b, 'm01-board-393', 393, 852);
    $b->size(1280, 800);
    $b->click('ส่งมอบ');
    $b->fill('dialog input[name=pickup_qr]', (string) Database::value('SELECT public_order_no FROM orders WHERE id = ?', [$mine]));
    $b->click('ตรวจ', $dialog);
    $b->sees('ได้รับเงินแล้ว');
    $shot($b, 'm02-handover-1280', 1280, 800);
    $b->js("document.querySelector('dialog').close()");
    foreach (['reports' => 'm03-reports', 'settings' => 'm04-settings'] as $hash => $name) {
        $b->js("location.hash='#/$hash'");
        $b->sees($hash === 'reports' ? 'ออเดอร์ตามชั่วโมง' : 'รับเงินด้วย PromptPay');
        $shot($b, "$name-1280", 1280, 800);
        $shot($b, "$name-393", 393, 852);
    }
    $b->open("$base/admin/");
    $b->fill('input[name=login]', 'demo_admin');
    $b->fill('input[name=password]', Env::get('SEED_DEMO_PASSWORD'));
    $b->click('เข้าสู่ระบบ');
    $b->sees('ภาพรวมวันนี้');
    $shot($b, 'a01-overview-1280', 1280, 800);
    $b->js("location.hash='#/shops'");
    $b->sees('เพิ่มร้าน');
    $shot($b, 'a02-shops-1280', 1280, 800);
    echo $b->errors === [] ? "no script errors\n" : 'ERRORS ' . json_encode($b->errors, JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    $b->close();
}
