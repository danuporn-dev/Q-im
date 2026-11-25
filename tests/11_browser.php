<?php
declare(strict_types=1);

// T20 in a real headless browser. Skipped, not passed, when Chrome or Edge is not installed.

use App\Support\Database;
use App\Support\Env;

require_once __DIR__ . '/browser.php';

$binary = Browser::find();
if ($binary === null) {
    T::skip('T20', 'no Chrome or Edge; set CHROME_PATH');
    return;
}

T::group('T20 responsive, slow and offline network; T18 reload; T21 rendering of hostile text');
$s = Fixture::shop('ui');
$evil = '<img src=x onerror=alert(1)>';
$shopName = 'TESTUI' . Fixture::run();
Database::update('shops', $s['shop'], ['name' => "$shopName $evil", 'description' => '<script>alert(2)</script>']);
Database::update('menus', $s['menu'], ['name' => "เมนู $evil", 'description' => $evil]);
[, $adminLogin] = Fixture::staff('admin', 'ui_' . Fixture::run());
$pw = Env::get('SEED_DEMO_PASSWORD');
$dialog = "document.querySelector('dialog')||document.createElement('i')";

$b = new Browser($binary, 9377);
try {
    // Customer: order once, from search to tracking.
    $b->size(390, 844);
    $b->open("$base/customer/#/search");
    $b->fill('input[type=search]', $shopName);
    $b->click($shopName);
    $b->click('เมนู <img');
    $b->fill('dialog input[placeholder]', '<svg onload=alert(3)>');
    $b->click('เพิ่มลงตะกร้า', $dialog);
    $b->click('Demo CUSTOMER_1');
    T::ok($b->sees('ตะกร้า 1 รายการ'), 'cart bar appears after the first item');
    $b->open("$base/customer/#/cart");
    T::ok($b->sees('ยืนยันออเดอร์ ฿45'), 'cart page with total');
    $b->js("{const q=[...document.querySelectorAll('button')].find(e=>e.innerText.startsWith('ยืนยันออเดอร์'));q.click();q.click();q.click();}");
    T::ok($b->sees('รหัสรับอาหาร'), 'tracking page after checkout');
    T::eq(1, (int) Database::value('SELECT COUNT(*) FROM orders WHERE shop_id = ?', [$s['shop']]), 'triple click makes one order');
    $order = (int) Database::value('SELECT id FROM orders WHERE shop_id = ?', [$s['shop']]);
    $b->send('Page.reload');
    T::ok($b->sees('รอร้านยืนยัน'), 'order restored after reload');

    foreach ([320, 390, 768, 1280] as $width) {
        $b->size($width);
        $bad = [];
        foreach (['#/', '#/search', '#/cart', '#/orders', '#/profile', '#/help', "#/shop/{$s['shop']}", "#/order/$order"] as $hash) {
            $b->open("$base/customer/$hash");
            if (!$b->until("document.querySelector('.tabbar')&&!document.querySelector('.spinner')")) {
                $bad[] = "$hash never finished loading";
            } elseif (($o = $b->overflow()) > 0) {
                $bad[] = "$hash overflows by $o";
            }
        }
        T::eq([], $bad, "customer pages fit $width px");
    }
    $b->size(390, 844);
    $b->open("$base/customer/#/shop/{$s['shop']}");
    $b->sees('เมนู <img');
    T::eq('[]', $b->js("JSON.stringify([...document.querySelectorAll('button,a,input')].filter(e=>e.offsetParent&&!['checkbox','radio'].includes(e.type)&&e.getBoundingClientRect().height<43).map(e=>e.outerHTML.slice(0,90)))"), 'touch targets are about 44 px or more');
    T::eq('[]', $b->js("JSON.stringify([...document.querySelectorAll('button,a')].filter(e=>e.offsetParent&&!e.innerText.trim()&&!e.getAttribute('aria-label')).map(e=>e.outerHTML.slice(0,90)))"), 'every control has a name');

    $b->js("location.hash='#/help'");
    $b->sees('วิธีใช้งาน');
    $b->network(false, 1500, 40000);
    $b->js("location.hash='#/shop/{$s['shop']}'");
    T::ok($b->sees('กำลังโหลด', 1.2), 'slow network: loading state');
    T::ok($b->sees('เมนู <img', 20), 'slow network: content arrives');
    $b->network(true);
    $b->js("location.hash='#/orders'");
    T::ok($b->sees('เชื่อมต่อไม่ได้') && $b->sees('ลองใหม่'), 'offline: message and retry');
    $b->network(false);
    $b->click('ลองใหม่');
    T::ok($b->sees($shopName), 'back online: retry works');

    // Merchant: alerts for orders that wait too long.
    T::group('T20 merchant pages and waiting alerts');
    [, $fresh] = Fixture::order($base, 'ui_fresh', $s);
    $m = Fixture::login($base, 'merchant', $s['login']);
    [, $ready] = Fixture::order($base, 'ui_ready', $s);
    foreach (['accept', 'prepare', 'ready'] as $x) {
        $m->post("/api/v1/merchant/orders/$ready/$x");
    }
    $ago = static fn (int $minutes): string => gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    $b->size(1280);
    $b->open("$base/merchant/");
    $b->fill('input[name=login]', $s['login']);
    $b->fill('input[name=password]', $pw);
    $b->click('เข้าสู่ระบบ');
    T::ok($b->sees('รับออเดอร์') && $b->sees('<svg onload'), 'board shows the order and its note as text');
    $late = "document.querySelectorAll('.ocard.late').length";
    T::eq(0, $b->js($late), 'no alert while orders are fresh');
    T::ok(!$b->sees('ลูกค้าไม่มารับ', 0.5), 'no no-show button before the wait');
    Database::update('orders', $order, ['created_at' => $ago(6)]);
    T::ok($b->sees('รอยืนยันมา 6 นาที'), 'PENDING for 6 minutes: alert appears through polling');
    Database::update('orders', $ready, ['ready_at' => $ago(21)]);
    T::ok($b->sees('พร้อมรับมา 21 นาที กรุณาตรวจสอบ'), 'READY for 21 minutes: alert appears through polling');
    T::eq(2, $b->js($late), 'only the two late orders are marked');
    T::eq(['PENDING', 'PENDING', 'READY'], array_column(Database::rows('SELECT status FROM orders WHERE id IN (?, ?, ?) ORDER BY status', [$order, $fresh, $ready]), 'status'), 'nothing is cancelled automatically');
    $b->click('ลูกค้าไม่มารับ');
    $b->click('ติดต่อลูกค้าไม่ได้', $dialog);
    $b->click('ยกเลิกออเดอร์', $dialog);
    T::ok($b->until("!document.body.innerText.includes('พร้อมรับมา 21 นาที')"), 'no-show button cancels the order on the board');
    T::eq(['CANCELLED', 'ติดต่อลูกค้าไม่ได้'], [Database::value('SELECT status FROM orders WHERE id = ?', [$ready]), Database::value('SELECT reason FROM order_status_logs WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$ready])], 'saved with its reason');
    foreach ([390, 768, 1280] as $width) {
        $b->size($width);
        $bad = [];
        foreach (['orders' => 'พร้อมรับ', 'history' => 'ประวัติออเดอร์', 'menus' => 'เพิ่มเมนู', 'settings' => 'ทำพร้อมกันได้', 'reports' => 'ยอดขาย'] as $tab => $text) {
            $b->js("location.hash='#/$tab'");
            if (!$b->sees($text) || $b->overflow() > 0) {
                $bad[] = $tab;
            }
        }
        T::eq([], $bad, "merchant pages fit $width px");
    }

    T::group('T20 admin pages');
    $b->open("$base/admin/");
    $b->fill('input[name=login]', $adminLogin);
    $b->fill('input[name=password]', $pw);
    $b->click('เข้าสู่ระบบ');
    T::ok($b->sees('ภาพรวมวันนี้'), 'admin overview');
    foreach ([390, 768, 1280] as $width) {
        $b->size($width);
        $bad = [];
        foreach (['overview' => 'ภาพรวมวันนี้', 'orders' => $shopName, 'shops' => 'เพิ่มร้าน', 'courts' => 'เพิ่มโรงอาหาร', 'users' => 'เพิ่มบัญชี', 'reports' => 'รายงานรวม', 'audit' => 'auth.login', 'settings' => 'LINE Webhook'] as $tab => $text) {
            $b->js("location.hash='#/$tab'");
            if (!$b->sees($text) || $b->overflow() > 0) {
                $bad[] = $tab;
            }
        }
        T::eq([], $bad, "admin pages fit $width px");
    }
    T::eq([], $b->errors, 'no script errors, dialogs or CSP violations on any page');
    $m->post("/api/v1/merchant/orders/$order/reject", ['reason' => 'ทดสอบ']);
    $m->post("/api/v1/merchant/orders/$fresh/reject", ['reason' => 'ทดสอบ']);
} finally {
    $b->close();
}
