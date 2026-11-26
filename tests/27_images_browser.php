<?php
declare(strict_types=1);

// Pictures in a real headless browser (D41): the shop uploads one, and every page shows a picture or the drawn
// one, with the menu name as alt text, never an empty box or a broken image. Skipped without Chrome or Edge.

use App\Support\Database;
use App\Support\Env;

require_once __DIR__ . '/browser.php';

$binary = Browser::find();
if ($binary === null) {
    T::skip('T39', 'no Chrome or Edge; set CHROME_PATH');
    return;
}

$a = '/api/v1';
$s = Fixture::shop('pics');
$m = Fixture::login($base, 'merchant', $s['login']);
$pw = Env::get('SEED_DEMO_PASSWORD');
$dialog = "document.querySelector('dialog')||document.createElement('i')";
$tmp = sys_get_temp_dir() . '/qim-photo-' . Fixture::run();
@mkdir($tmp);
$photo = "$tmp/kaphrao.jpg";
$img = imagecreatetruecolor(800, 600);
imagefilledellipse($img, 400, 300, 500, 400, imagecolorallocate($img, 230, 120, 40));
imagejpeg($img, $photo, 85);
file_put_contents("$tmp/drawing.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

/** Puts a file in an <input type=file> as if the user picked it; Chrome fires the change event. */
$pick = static function (Browser $b, string $selector, string $path): void {
    $root = $b->send('DOM.getDocument')['root']['nodeId'];
    $node = $b->send('DOM.querySelector', ['nodeId' => $root, 'selector' => $selector])['nodeId'];
    $b->send('DOM.setFileInputFiles', ['nodeId' => $node, 'files' => [$path]]);
};

/** A toast from the previous save can still be on screen: wait for the database instead. */
$saved = static function (string $sql, array $params, callable $ok): mixed {
    for ($i = 0; $i < 60; $i++) {
        $row = Database::row($sql, $params);
        if ($ok($row)) {
            break;
        }
        usleep(200000);
    }
    return $row;
};

$b = new Browser($binary, 9391);
try {
    T::group('T39 the shop uploads, replaces and removes a menu picture in the browser');
    $b->size(1280, 800);
    $b->open("$base/merchant/");
    $b->fill('input[name=login]', $s['login']);
    $b->fill('input[name=password]', $pw);
    $b->click('เข้าสู่ระบบ');
    $b->sees('พร้อมรับ');
    $b->js("location.hash='#/menus'");
    $b->sees('เพิ่มเมนู');
    $b->js("[...document.querySelectorAll('.item')].find(e=>e.innerText.includes('ข้าว pics'))?.querySelector('button:last-of-type')?.click()");
    T::ok($b->sees('ภาพวาดเมื่อไม่มีรูป'), 'menu form has the picture field and the drawn-picture choice');
    $pick($b, 'dialog input[type=file]', "$tmp/drawing.svg");
    T::ok($b->sees('ไม่รับไฟล์ SVG'), 'an SVG is refused with a clear message');
    $pick($b, 'dialog input[type=file]', $photo);
    T::ok($b->sees('อัปโหลดแล้ว กดบันทึกเพื่อใช้รูปนี้'), 'photo uploaded');
    T::ok((bool) $b->until("document.querySelector('dialog img.preview')?.src.includes('/images/')"), 'preview shows the uploaded picture');
    T::eq(null, Database::value('SELECT image_url FROM menus WHERE id = ?', [$s['menu']]), 'nothing changes before saving');
    $b->click('บันทึก', $dialog);
    T::ok($b->sees('บันทึกแล้ว'), 'saved');
    $first = (string) Database::value('SELECT image_url FROM menus WHERE id = ?', [$s['menu']]);
    T::ok(str_starts_with($first, 'image:'), 'menu has the picture', $first);

    $b->js("[...document.querySelectorAll('.item')].find(e=>e.innerText.includes('ข้าว pics'))?.querySelector('button:last-of-type')?.click()");
    $b->sees('เอารูปออก');
    $pick($b, 'dialog input[type=file]', $photo);
    $b->sees('อัปโหลดแล้ว');
    $b->click('บันทึก', $dialog);
    $second = (string) $saved('SELECT image_url FROM menus WHERE id = ?', [$s['menu']], static fn ($r) => $r['image_url'] !== $first)['image_url'];
    T::ok($second !== $first && !Database::value('SELECT 1 FROM images WHERE id = ?', [substr($first, 6)]), 'replaced: the first upload is gone');

    $b->js("[...document.querySelectorAll('.item')].find(e=>e.innerText.includes('ข้าว pics'))?.querySelector('button:last-of-type')?.click()");
    $b->sees('เอารูปออก');
    $b->click('เอารูปออก', $dialog);
    T::ok($b->sees('จะเอารูปออกเมื่อกดบันทึก') && (bool) $b->until("document.querySelector('dialog img.preview')?.src.includes('/art/')"), 'removing shows the drawn picture');
    $b->js("{const s=document.querySelector('dialog select[name=art]');s.value='noodle';s.dispatchEvent(new Event('change'));}");
    T::ok((bool) $b->until("document.querySelector('dialog img.preview')?.src.endsWith('/art/noodle.svg')"), 'choosing a drawn picture updates the preview');
    $b->click('บันทึก', $dialog);
    T::eq([null, 'noodle'], array_values($saved('SELECT image_url, art FROM menus WHERE id = ?', [$s['menu']], static fn ($r) => $r['art'] === 'noodle') ?? []), 'saved without a picture, with the chosen drawing');
    $m->patch("$a/merchant/menus/{$s['menu']}", ['art' => null]);
    T::eq([], $b->errors, 'no script errors');

    T::group('T40 every page shows a picture or the drawn one, with the menu or shop name as alt text');
    // A photo, a menu without one, a picture address that fails, and a menu retired after it was ordered.
    file_put_contents("$tmp/up.jpg", (string) file_get_contents($photo));
    $up = $m->post("$a/merchant/shops/{$s['shop']}/images", ['__multipart' => ['image' => new CURLFile("$tmp/up.jpg", 'image/jpeg', 'up.jpg')]]);
    $m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => $up['body']['data']['image_url'] ?? null]);
    // A closed port: on Windows under load the refusal can take longer than a polling page takes to redraw,
    // which is the case where a picture would otherwise never leave its empty box.
    $gone = 'https://127.0.0.1:9/gone.jpg';
    $category = (int) Database::value('SELECT category_id FROM menus WHERE id = ?', [$s['menu']]);
    $broken = Database::insert('menus', ['shop_id' => $s['shop'], 'category_id' => $category, 'name' => 'เค้กส้ม pics', 'price' => '30.00', 'image_url' => $gone]);
    $retired = Database::insert('menus', ['shop_id' => $s['shop'], 'category_id' => $category, 'name' => 'ไก่ทอด pics', 'price' => '25.00']);
    $c = new Client($base);
    $c->csrf = $c->post("$a/auth/dev", ['customer' => 'CUSTOMER_3'])['body']['data']['csrf'];
    $place = static function (array $items) use ($c, $a, $s): int {
        $cart = $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => $items, 'replace' => true]);
        return (int) $c->post("$a/orders", ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote']])['body']['data']['id'];
    };
    $old = $place([['menu_id' => $retired, 'quantity' => 1]]);
    $m->patch("$a/merchant/menus/$retired", ['active' => false]);
    $ready = $place([['menu_id' => $s['menu'], 'quantity' => 1], ['menu_id' => $s['menu2'], 'quantity' => 2], ['menu_id' => $broken, 'quantity' => 1]]);
    $m->post("$a/merchant/orders/$ready/accept");
    $m->post("$a/merchant/orders/$ready/ready");
    foreach (["shops/{$s['shop']}", "menus/{$s['menu']}", "menus/{$s['menu2']}", "menus/$broken"] as $x) {
        $c->put("$a/favorites/$x", []);
    }
    $c->put("$a/cart", ['shop_id' => $s['shop'], 'items' => [['menu_id' => $s['menu'], 'quantity' => 1], ['menu_id' => $broken, 'quantity' => 1]], 'replace' => true]);

    // Scrolls every picture into view so the lazy ones load, then checks what is on screen.
    $check = static function (Browser $b, int $least, string $label): void {
        $r = json_decode((string) $b->js("(async () => {
            const sel = 'img.pic, img.cover, img.hero, img.preview, div.tile';
            // A broken picture is complete at once; its error handler swaps it for the drawn one a moment later,
            // so wait for it to leave the page (at most 3 s) instead of taking complete as done.
            const gone = (e) => new Promise((done) => { const t0 = performance.now(); const tick = () => (!e.isConnected || e.naturalWidth || performance.now() - t0 > 3000 ? done() : requestAnimationFrame(tick)); tick(); });
            const wait = (e) => (e.complete ? (e.naturalWidth ? Promise.resolve() : gone(e)) : new Promise((done) => { e.addEventListener('load', done, { once: true }); e.addEventListener('error', () => gone(e).then(done), { once: true }); setTimeout(done, 5000); }));
            // Until nothing is loading: a failed picture is replaced by the drawn one, which loads in turn.
            for (let round = 0; round < 6; round++) {
              const loading = [...document.querySelectorAll(sel)].filter((e) => e.tagName === 'IMG' && !(e.complete && e.naturalWidth));
              if (round > 0 && !loading.length) break;
              for (const e of round ? loading : document.querySelectorAll(sel)) if (e.tagName === 'IMG') { e.scrollIntoView({ block: 'center' }); await wait(e); }
            }
            scrollTo(0, 0);
            const shown = [...document.querySelectorAll(sel)].filter((e) => e.getClientRects().length);
            const bad = [];
            for (const e of shown) {
              if (e.tagName !== 'IMG') { bad.push('letter instead of a picture: ' + e.outerHTML.slice(0, 80)); continue; }
              if (!e.alt.trim()) bad.push('no alt: ' + e.src);
              if (!e.complete || !e.naturalWidth) bad.push('broken: ' + e.src);
              if (!e.getAttribute('width') || !e.getAttribute('height')) bad.push('no size before loading: ' + e.src);
              const box = e.getBoundingClientRect();
              if (box.width < 20 || box.height < 20) bad.push('empty box: ' + e.src);
              if (!e.className.startsWith('hero') && e.loading !== 'lazy') bad.push('not lazy: ' + e.src);
              if (parseFloat(getComputedStyle(e).borderTopLeftRadius) < 4 && !e.closest('.stall')) bad.push('square corners: ' + e.className);
            }
            return JSON.stringify({ count: shown.length, bad, alts: shown.map((e) => e.alt) });
        })()"), true);
        T::ok(($r['count'] ?? 0) >= $least && ($r['bad'] ?? ['?']) === [], "$label: $least or more pictures, none broken or without alt", $r);
    };
    $alts = static fn (Browser $b): string => (string) $b->js("JSON.stringify([...document.querySelectorAll('img')].filter((e) => e.getClientRects().length).map((e) => e.alt + ' ' + e.src.split('/').pop()))");

    $b->size(390, 844);
    $b->open("$base/customer/");
    $b->sees('ร้านในโรงอาหาร');
    $b->click('ฟ้าใส');
    $b->until("document.querySelector('[data-bell]')");
    $check($b, 2, 'home');
    $b->open("$base/customer/#/search");
    $b->fill('input[type=search]', 'pics');
    $b->until("document.querySelectorAll('.stall.compact').length > 0");
    $check($b, 4, 'search results with the matching menus');
    $b->open("$base/customer/#/shop/{$s['shop']}");
    $b->sees('เค้กส้ม pics');
    $check($b, 4, 'shop');
    $shopAlts = $alts($b);
    T::ok(str_contains($shopAlts, 'เค้กส้ม pics dessert.svg') && str_contains($shopAlts, 'น้ำ pics') && str_contains($shopAlts, 'drink.svg'), 'a failing address and a menu without a picture show the drawn one of their kind', $shopAlts);
    $b->click('ข้าว pics');
    $b->sees('เพิ่มลงตะกร้า');
    $check($b, 1, 'food dialog, large picture');
    T::ok(str_contains((string) $b->js("document.querySelector('dialog img.hero')?.src"), '/images/') && !str_contains((string) $b->js("document.querySelector('dialog img.hero')?.src"), '/thumb'), 'food dialog uses the full-size picture');
    $b->js("document.querySelector('dialog').close()");
    $b->open("$base/customer/#/cart");
    $b->sees('ยืนยันออเดอร์');
    $check($b, 2, 'cart');
    $b->open("$base/customer/#/order/$ready");
    $b->sees('รายการ');
    $check($b, 3, 'tracking');
    $b->open("$base/customer/#/order/$old");
    $b->sees('ไก่ทอด pics');
    $check($b, 1, 'old order of a retired menu');
    T::ok(str_contains($alts($b), 'ไก่ทอด pics fried.svg'), 'retired menu: drawn picture, name from the order', $alts($b));
    $b->open("$base/customer/#/orders");
    $b->sees('ไก่ทอด pics');
    $check($b, 2, 'order history');
    $b->open("$base/customer/#/favorites");
    $b->sees('เค้กส้ม pics');
    $check($b, 4, 'favourites');

    $b->size(1280, 800);
    $b->open("$base/merchant/#/menus");
    $b->sees('เค้กส้ม pics');
    $check($b, 4, 'shop: menu management');
    $b->open("$base/merchant/#/orders");
    $b->sees('พร้อมรับ');
    $b->until("document.querySelectorAll('ul.lines img').length >= 3");
    $check($b, 4, 'shop: order board');
    $b->click('ส่งมอบ');
    $b->until("document.querySelectorAll('dialog ul.lines img').length >= 3");
    $check($b, 3, 'shop: order details in the hand-over');
    $b->js("document.querySelector('dialog').close()");
    T::eq([], $b->errors, 'no script errors');
} finally {
    $b->close();
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}
