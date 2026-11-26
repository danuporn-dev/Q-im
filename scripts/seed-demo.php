<?php
declare(strict_types=1);

// Usage: php scripts/seed-demo.php (development only; normally run by demo-reset.php)
// Two food courts with five Thai shops for a live presentation, on an empty migrated database. Safe to re-run: existing rows stay,
// only pictures are refreshed. Pictures come from public/assets/images/demo/<name>.(jpg|jpeg|png|webp);
// a menu without a file gets an SVG placeholder. Nothing is downloaded.

use App\Support\Database;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/seed-lib.php';

$password = seedPassword();
if (!in_array(Database::name(), ['qim_dev', 'qim_test'], true) || !in_array(Env::get('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to seed: only qim_dev on this computer.\n");
    exit(1);
}

/** Re-encodes a photo, or draws a placeholder, into storage/uploads; the key depends on the content. */
function picture(string $file, string $name, int $hue): string
{
    $root = dirname(__DIR__);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $type) {
        $path = "$root/public/assets/images/demo/$file.$type";
        if (!is_file($path)) {
            continue;
        }
        $size = @getimagesize($path);
        $source = $size && $size[0] * $size[1] <= 25_000_000 ? @imagecreatefromstring((string) file_get_contents($path)) : false;
        if (!$source) {
            echo "Skipped $file.$type: not a readable picture of at most 25 megapixels\n";
            continue;
        }
        $scale = min(1, 1200 / max($size[0], $size[1]));
        [$w, $h] = [max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale))];
        $target = imagecreatetruecolor($w, $h);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
        $key = md5('demo' . md5_file($path));
        imagejpeg($target, "$root/storage/uploads/$key.jpg", 82);
        return "image:$key";
    }
    $lines = preg_split('/\|/', $name);
    $label = '';
    foreach ($lines as $i => $line) {
        $y = 200 + ($i - (count($lines) - 1) / 2) * 50 + 14;
        $label .= "<text x=\"200\" y=\"$y\">" . htmlspecialchars($line, ENT_XML1) . '</text>';
    }
    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 400 400\" width=\"400\" height=\"400\"><rect width=\"400\" height=\"400\" fill=\"hsl($hue,48%,86%)\"/>"
        . "<g fill=\"hsl($hue,55%,24%)\" font-family=\"Leelawadee UI,Noto Sans Thai,Thonburi,sans-serif\" font-size=\"40\" font-weight=\"700\" text-anchor=\"middle\">$label</g></svg>";
    $key = md5('demo' . $svg);
    file_put_contents("$root/storage/uploads/$key.svg", $svg);
    return "image:$key";
}

$spice = ['ระดับความเผ็ด', 1, 1, [['ไม่เผ็ด', '0.00'], ['เผ็ดน้อย', '0.00'], ['เผ็ดกลาง', '0.00'], ['เผ็ดมาก', '0.00']]];
$extras = ['เพิ่มเติม', 0, 2, [['ไข่ดาว', '10.00'], ['ไข่เจียว', '15.00'], ['พิเศษ', '10.00']]];
$noodle = ['เลือกเส้น', 1, 1, [['เส้นเล็ก', '0.00'], ['เส้นใหญ่', '0.00'], ['เส้นหมี่', '0.00'], ['บะหมี่', '5.00']]];
$soup = ['น้ำซุป', 1, 1, [['น้ำ', '0.00'], ['แห้ง', '0.00']]];
$bowl = ['ขนาด', 1, 1, [['ธรรมดา', '0.00'], ['พิเศษ', '10.00']]];
$glass = ['ขนาดแก้ว', 1, 1, [['16 ออนซ์', '0.00'], ['22 ออนซ์', '10.00']]];
$sweet = ['ระดับความหวาน', 1, 1, [['หวานปกติ', '0.00'], ['หวานน้อย', '0.00'], ['ไม่หวาน', '0.00']]];
$topping = ['ท็อปปิง', 0, 2, [['ไข่มุก', '10.00'], ['วุ้นมะพร้าว', '10.00']]];

// [name, picture, label, hue, description, capacity, average minutes, max active orders, login, staff name,
//  category => [[menu, picture, label with | between lines, price, minutes, groups, description]]]
$shops = [
    ['ครัวป้าแดง อาหารตามสั่ง', 'shop-tamsang', 'ครัวป้าแดง|อาหารตามสั่ง', 18, 'ผัดจานต่อจาน ข้าวหอมมะลิ', 2, 6, 25, 'demo_merchant_1', 'ป้าแดง', [
        'ข้าวราดแกง/ผัด' => [
            ['ข้าวกะเพราหมูสับ', 'kaphrao-mu', 'ข้าวกะเพรา|หมูสับ', '45.00', 5, [$spice, $extras], 'กะเพราแท้ ผัดไฟแรง'],
            ['ข้าวกะเพราไก่กรอบ', 'kaphrao-kai', 'ข้าวกะเพรา|ไก่กรอบ', '55.00', 7, [$spice, $extras], 'ไก่ทอดกรอบคลุกกะเพรา'],
            ['ข้าวผัดหมู', 'khao-phat', 'ข้าวผัดหมู', '45.00', 5, [$extras], 'เสิร์ฟพร้อมแตงกวาและมะนาว'],
            ['ข้าวหมูกระเทียม', 'mu-krathiam', 'ข้าวหมู|กระเทียม', '50.00', 5, [$extras], 'กระเทียมเจียวหอม ๆ'],
            ['ข้าวคะน้าหมูกรอบ', 'khana-mukrop', 'ข้าวคะน้า|หมูกรอบ', '60.00', 6, [$spice, $extras], 'หมูกรอบทอดใหม่ทุกวัน'],
            ['ข้าวไข่เจียวหมูสับ', 'khai-chiao', 'ข้าวไข่เจียว|หมูสับ', '40.00', 4, [], 'ไข่ฟู กรอบนอกนุ่มใน'],
        ],
        'ต้ม' => [
            ['ต้มยำกุ้งน้ำข้น', 'tom-yam', 'ต้มยำกุ้ง|น้ำข้น', '80.00', 9, [$spice], 'กุ้งสด 4 ตัว พร้อมข้าวสวย'],
        ],
    ]],
    ['ก๋วยเตี๋ยวเรือลุงหนวด', 'shop-kuaitiao', 'ก๋วยเตี๋ยวเรือ|ลุงหนวด', 200, 'น้ำซุปเคี่ยว 6 ชั่วโมง', 3, 4, 30, 'demo_merchant_2', 'ลุงหนวด', [
        'ก๋วยเตี๋ยว' => [
            ['ก๋วยเตี๋ยวเรือหมู', 'ruea-mu', 'ก๋วยเตี๋ยวเรือ|หมู', '40.00', 4, [$noodle, $soup, $bowl], 'หมูนุ่ม ลูกชิ้น ตับ'],
            ['ก๋วยเตี๋ยวเรือเนื้อ', 'ruea-nuea', 'ก๋วยเตี๋ยวเรือ|เนื้อ', '50.00', 4, [$noodle, $soup, $bowl], 'เนื้อสดและเนื้อเปื่อย'],
            ['ก๋วยเตี๋ยวต้มยำหมู', 'tomyam-mu', 'ก๋วยเตี๋ยว|ต้มยำหมู', '45.00', 5, [$noodle, $spice, $bowl], 'ใส่ถั่วและมะนาวสด'],
            ['เย็นตาโฟ', 'yentafo', 'เย็นตาโฟ', '45.00', 5, [$noodle, $bowl], 'ปลาหมึกกรอบ เต้าหู้ทอด'],
            ['เกาเหลาหมู', 'kaolao', 'เกาเหลาหมู', '50.00', 4, [$bowl], 'ไม่ใส่เส้น เครื่องแน่น'],
        ],
        'ทานเล่น' => [
            ['ลูกชิ้นลวก', 'luk-chin', 'ลูกชิ้นลวก', '30.00', 2, [], '10 ลูก พร้อมน้ำจิ้ม'],
            ['เกี๊ยวทอด', 'kiao-thot', 'เกี๊ยวทอด', '25.00', 3, [], '8 ชิ้น'],
        ],
    ]],
    ['ชื่นใจ เครื่องดื่ม', 'shop-chuenchai', 'ชื่นใจ|เครื่องดื่ม', 150, 'ชา กาแฟ และผลไม้ปั่น', 2, 3, 40, 'demo_merchant_3', 'พี่ชื่น', [
        'ชา/กาแฟ' => [
            ['ชาไทยเย็น', 'cha-thai', 'ชาไทยเย็น', '30.00', 2, [$glass, $sweet, $topping], 'ชาไทยเข้มข้น'],
            ['ชาเขียวนม', 'cha-khiao', 'ชาเขียวนม', '35.00', 2, [$glass, $sweet, $topping], 'หอมชาเขียวแท้'],
            ['ชานมไข่มุก', 'cha-nom', 'ชานม|ไข่มุก', '40.00', 3, [$glass, $sweet], 'ไข่มุกต้มใหม่ทุกเช้า'],
            ['กาแฟเย็น', 'kafae-yen', 'กาแฟเย็น', '35.00', 3, [$glass, $sweet], 'กาแฟคั่วกลาง'],
            ['อเมริกาโน่เย็น', 'americano', 'อเมริกาโน่|เย็น', '40.00', 2, [$glass], 'ไม่ใส่น้ำตาล'],
        ],
        'ผลไม้' => [
            ['น้ำส้มคั้นสด', 'nam-som', 'น้ำส้ม|คั้นสด', '35.00', 2, [$glass], 'ส้มเขียวหวานคั้นสด'],
            ['แตงโมปั่น', 'taengmo', 'แตงโมปั่น', '40.00', 3, [$glass, $sweet], 'ปั่นสดแก้วต่อแก้ว'],
            ['มะม่วงปั่น', 'mamuang', 'มะม่วงปั่น', '45.00', 3, [$glass, $sweet], 'มะม่วงน้ำดอกไม้'],
        ],
    ]],
    ['ข้าวมันไก่เจ๊หมวย', 'shop-khaomankai', 'ข้าวมันไก่|เจ๊หมวย', 45, 'ไก่นุ่ม น้ำจิ้มสูตรร้าน', 3, 3, 20, 'demo_merchant_4', 'เจ๊หมวย', [
        'ข้าวมันไก่' => [
            ['ข้าวมันไก่ต้ม', 'kai-tom', 'ข้าวมันไก่|ต้ม', '40.00', 3, [$bowl], 'เสิร์ฟพร้อมน้ำซุป'],
            ['ข้าวมันไก่ทอด', 'kai-thot', 'ข้าวมันไก่|ทอด', '45.00', 4, [$bowl], 'ไก่ทอดกรอบ'],
            ['ข้าวมันไก่ผสม', 'kai-phasom', 'ข้าวมันไก่|ผสม', '55.00', 4, [], 'ต้มและทอดในจานเดียว'],
        ],
        'ของเพิ่ม' => [
            ['ไข่ต้ม', 'khai-tom', 'ไข่ต้ม', '10.00', 1, [], 'หนึ่งฟอง'],
            ['น้ำซุปไก่ตุ๋น', 'sup-kai', 'น้ำซุป|ไก่ตุ๋น', '25.00', 2, [], 'ถ้วยใหญ่'],
        ],
    ]],
    ['ขนมปังยามบ่าย', 'shop-khanom', 'ขนมปัง|ยามบ่าย', 280, 'ขนมปังปิ้งและของหวาน', 2, 4, 20, 'demo_merchant_5', 'พี่บีม', [
        'ขนมปังปิ้ง' => [
            ['ขนมปังเนยนม', 'toast-noey', 'ขนมปัง|เนยนม', '25.00', 3, [], 'ปิ้งร้อน ๆ'],
            ['ขนมปังสังขยา', 'toast-sangkhaya', 'ขนมปัง|สังขยา', '30.00', 3, [], 'สังขยาใบเตย'],
        ],
        'ของหวาน' => [
            ['บัวลอยไข่หวาน', 'bualoi', 'บัวลอย|ไข่หวาน', '35.00', 5, [], 'กะทิหอม'],
            ['ลอดช่องกะทิ', 'lodchong', 'ลอดช่อง|กะทิ', '25.00', 2, [], 'ใส่น้ำแข็ง'],
        ],
    ]],
];

try {
    $pdo = Database::connect();
    // The ordinary seed has its own shops; mixing both would show six.
    if ((int) $pdo->query("SELECT COUNT(*) FROM food_courts WHERE name = 'โรงอาหารกลาง (Demo)' AND active = 1")->fetchColumn() > 0) {
        throw new RuntimeException('this database has the ordinary seed data; run scripts/demo-reset.php --confirm');
    }
    $pdo->beginTransaction();
    $roles = [];
    foreach (['customer', 'merchant', 'admin'] as $role) {
        $roles[$role] = ensure($pdo, 'roles', ['name' => $role]);
    }
    $courts = [
        ensure($pdo, 'food_courts', ['name' => 'โรงอาหารกลาง'], ['location' => 'อาคารกิจกรรมนักศึกษา ชั้น 1']),
        ensure($pdo, 'food_courts', ['name' => 'โรงอาหารคณะวิศวกรรมศาสตร์'], ['location' => 'อาคารวิศวกรรม ชั้นล่าง']),
    ];
    $image = $pdo->prepare('UPDATE menus SET image_url = ? WHERE id = ?');
    $shopImage = $pdo->prepare('UPDATE shops SET image_url = ? WHERE id = ?');

    foreach ($shops as $index => [$name, $file, $label, $hue, $description, $capacity, $average, $max, $login, $staff, $categories]) {
        // The first three shops are in the central court, the others in the second one.
        $court = $courts[$index < 3 ? 0 : 1];
        // No shop_hours rows: open at any time, so a rehearsal at night or on Sunday works too.
        $shop = ensure($pdo, 'shops', ['food_court_id' => $court, 'name' => $name], ['description' => $description, 'capacity' => $capacity, 'accepting_orders' => 1]);
        $shopImage->execute([picture($file, $label, $hue), $shop]);
        ensure($pdo, 'shop_settings', ['shop_id' => $shop], ['avg_prep_minutes' => $average, 'max_active_orders' => $max], 'shop_id');
        $sort = 0;
        $tint = 0;
        foreach ($categories as $category => $menus) {
            $categoryId = ensure($pdo, 'categories', ['shop_id' => $shop, 'name' => $category], ['sort_order' => $sort++]);
            foreach ($menus as [$menuName, $menuFile, $menuLabel, $price, $minutes, $groups, $text]) {
                $menu = ensure($pdo, 'menus', ['shop_id' => $shop, 'name' => $menuName], ['category_id' => $categoryId, 'price' => $price, 'prep_minutes' => $minutes, 'description' => $text]);
                $image->execute([picture($menuFile, $menuLabel, ($hue + 14 * $tint++) % 360), $menu]);
                foreach ($groups as [$groupName, $min, $most, $options]) {
                    $group = ensure($pdo, 'menu_option_groups', ['menu_id' => $menu, 'name' => $groupName], ['min_select' => $min, 'max_select' => $most]);
                    foreach ($options as [$option, $delta]) {
                        ensure($pdo, 'menu_options', ['group_id' => $group, 'name' => $option], ['price_delta' => $delta]);
                    }
                }
            }
        }
        $merchant = ensureStaff($pdo, 'merchant_accounts', $login, $staff, $password);
        ensure($pdo, 'user_roles', ['user_id' => $merchant, 'role_id' => $roles['merchant']]);
        ensure($pdo, 'shop_members', ['shop_id' => $shop, 'user_id' => $merchant]);
    }
    $admin = ensureStaff($pdo, 'admin_accounts', 'demo_admin', 'ผู้ดูแลโรงอาหาร', $password);
    ensure($pdo, 'user_roles', ['user_id' => $admin, 'role_id' => $roles['admin']]);
    foreach (App\Identity\DevMockIdentity::CUSTOMERS as $key => $customerName) {
        $customer = ensure($pdo, 'users', ['line_user_id' => "DEMO_$key"], ['display_name' => $customerName]);
        ensure($pdo, 'user_roles', ['user_id' => $customer, 'role_id' => $roles['customer']]);
    }
    $pdo->commit();
    echo "Demo seed complete.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Demo seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
