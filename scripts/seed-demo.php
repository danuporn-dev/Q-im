<?php
declare(strict_types=1);

// Usage: php scripts/seed-demo.php (development only; normally run by demo-reset.php)
// Two food courts with five Thai shops for a live presentation, on an empty migrated database. Safe to re-run: existing rows stay,
// only pictures are refreshed. Photos come from public/assets/demo-food/<slug>.(jpg|jpeg|png|webp), see docs/demo.md;
// a menu or shop without one shows the drawn picture of its kind. Nothing is downloaded.

use App\Services\Images;
use App\Support\Database;
use App\Support\HttpError;
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

/**
 * A photo from public/assets/demo-food/<slug>.(jpg|jpeg|png|webp), put through the same checks and drawing as an
 * upload by the shop (D41). No file: null, and the pages show the drawn picture of the menu's kind.
 *
 * @param list<string> $created keys made by this run, removed again when the seed fails
 */
function demoPicture(string $folder, string $slug, int $shopId, string $credits, array &$created): ?string
{
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $type) {
        $path = "$folder/$slug.$type";
        if (!is_file($path)) {
            continue;
        }
        if (!str_contains($credits, "$slug.$type")) {
            echo "Warning: $slug.$type has no line in public/assets/demo-food/CREDITS.md (source and licence)\n";
        }
        try {
            $key = Images::import($path, $shopId);
        } catch (HttpError $e) {
            echo "Skipped $slug.$type: {$e->getMessage()}\n";
            continue;
        }
        $created[] = $key;
        return "image:$key";
    }
    return null;
}

$spice = ['ระดับความเผ็ด', 1, 1, [['ไม่เผ็ด', '0.00'], ['เผ็ดน้อย', '0.00'], ['เผ็ดกลาง', '0.00'], ['เผ็ดมาก', '0.00']]];
$extras = ['เพิ่มเติม', 0, 2, [['ไข่ดาว', '10.00'], ['ไข่เจียว', '15.00'], ['พิเศษ', '10.00']]];
$noodle = ['เลือกเส้น', 1, 1, [['เส้นเล็ก', '0.00'], ['เส้นใหญ่', '0.00'], ['เส้นหมี่', '0.00'], ['บะหมี่', '5.00']]];
$soup = ['น้ำซุป', 1, 1, [['น้ำ', '0.00'], ['แห้ง', '0.00']]];
$bowl = ['ขนาด', 1, 1, [['ธรรมดา', '0.00'], ['พิเศษ', '10.00']]];
$glass = ['ขนาดแก้ว', 1, 1, [['16 ออนซ์', '0.00'], ['22 ออนซ์', '10.00']]];
$sweet = ['ระดับความหวาน', 1, 1, [['หวานปกติ', '0.00'], ['หวานน้อย', '0.00'], ['ไม่หวาน', '0.00']]];
$topping = ['ท็อปปิง', 0, 2, [['ไข่มุก', '10.00'], ['วุ้นมะพร้าว', '10.00']]];

// [name, photo slug, description, capacity, average minutes, max active orders, login, staff name,
//  category => [[menu, photo slug, price, minutes, groups, description]]]
$shops = [
    ['ครัวป้าแดง อาหารตามสั่ง', 'shop-tamsang', 'ผัดจานต่อจาน ข้าวหอมมะลิ', 2, 6, 25, 'demo_merchant_1', 'ป้าแดง', [
        'ข้าวราดแกง/ผัด' => [
            ['ข้าวกะเพราหมูสับ', 'kaphrao-mu', '45.00', 5, [$spice, $extras], 'กะเพราแท้ ผัดไฟแรง'],
            ['ข้าวกะเพราไก่กรอบ', 'kaphrao-kai', '55.00', 7, [$spice, $extras], 'ไก่ทอดกรอบคลุกกะเพรา'],
            ['ข้าวผัดหมู', 'khao-phat', '45.00', 5, [$extras], 'เสิร์ฟพร้อมแตงกวาและมะนาว'],
            ['ข้าวหมูกระเทียม', 'mu-krathiam', '50.00', 5, [$extras], 'กระเทียมเจียวหอม ๆ'],
            ['ข้าวคะน้าหมูกรอบ', 'khana-mukrop', '60.00', 6, [$spice, $extras], 'หมูกรอบทอดใหม่ทุกวัน'],
            ['ข้าวไข่เจียวหมูสับ', 'khai-chiao', '40.00', 4, [], 'ไข่ฟู กรอบนอกนุ่มใน'],
        ],
        'ต้ม' => [
            ['ต้มยำกุ้งน้ำข้น', 'tom-yam', '80.00', 9, [$spice], 'กุ้งสด 4 ตัว พร้อมข้าวสวย'],
        ],
    ]],
    ['ก๋วยเตี๋ยวเรือลุงหนวด', 'shop-kuaitiao', 'น้ำซุปเคี่ยว 6 ชั่วโมง', 3, 4, 30, 'demo_merchant_2', 'ลุงหนวด', [
        'ก๋วยเตี๋ยว' => [
            ['ก๋วยเตี๋ยวเรือหมู', 'ruea-mu', '40.00', 4, [$noodle, $soup, $bowl], 'หมูนุ่ม ลูกชิ้น ตับ'],
            ['ก๋วยเตี๋ยวเรือเนื้อ', 'ruea-nuea', '50.00', 4, [$noodle, $soup, $bowl], 'เนื้อสดและเนื้อเปื่อย'],
            ['ก๋วยเตี๋ยวต้มยำหมู', 'tomyam-mu', '45.00', 5, [$noodle, $spice, $bowl], 'ใส่ถั่วและมะนาวสด'],
            ['เย็นตาโฟ', 'yentafo', '45.00', 5, [$noodle, $bowl], 'ปลาหมึกกรอบ เต้าหู้ทอด'],
            ['เกาเหลาหมู', 'kaolao', '50.00', 4, [$bowl], 'ไม่ใส่เส้น เครื่องแน่น'],
        ],
        'ทานเล่น' => [
            ['ลูกชิ้นลวก', 'luk-chin', '30.00', 2, [], '10 ลูก พร้อมน้ำจิ้ม'],
            ['เกี๊ยวทอด', 'kiao-thot', '25.00', 3, [], '8 ชิ้น'],
        ],
    ]],
    ['ชื่นใจ เครื่องดื่ม', 'shop-chuenchai', 'ชา กาแฟ และผลไม้ปั่น', 2, 3, 40, 'demo_merchant_3', 'พี่ชื่น', [
        'ชา/กาแฟ' => [
            ['ชาไทยเย็น', 'cha-thai', '30.00', 2, [$glass, $sweet, $topping], 'ชาไทยเข้มข้น'],
            ['ชาเขียวนม', 'cha-khiao', '35.00', 2, [$glass, $sweet, $topping], 'หอมชาเขียวแท้'],
            ['ชานมไข่มุก', 'cha-nom', '40.00', 3, [$glass, $sweet], 'ไข่มุกต้มใหม่ทุกเช้า'],
            ['กาแฟเย็น', 'kafae-yen', '35.00', 3, [$glass, $sweet], 'กาแฟคั่วกลาง'],
            ['อเมริกาโน่เย็น', 'americano', '40.00', 2, [$glass], 'ไม่ใส่น้ำตาล'],
        ],
        'ผลไม้' => [
            ['น้ำส้มคั้นสด', 'nam-som', '35.00', 2, [$glass], 'ส้มเขียวหวานคั้นสด'],
            ['แตงโมปั่น', 'taengmo', '40.00', 3, [$glass, $sweet], 'ปั่นสดแก้วต่อแก้ว'],
            ['มะม่วงปั่น', 'mamuang', '45.00', 3, [$glass, $sweet], 'มะม่วงน้ำดอกไม้'],
        ],
    ]],
    ['ข้าวมันไก่เจ๊หมวย', 'shop-khaomankai', 'ไก่นุ่ม น้ำจิ้มสูตรร้าน', 3, 3, 20, 'demo_merchant_4', 'เจ๊หมวย', [
        'ข้าวมันไก่' => [
            ['ข้าวมันไก่ต้ม', 'kai-tom', '40.00', 3, [$bowl], 'เสิร์ฟพร้อมน้ำซุป'],
            ['ข้าวมันไก่ทอด', 'kai-thot', '45.00', 4, [$bowl], 'ไก่ทอดกรอบ'],
            ['ข้าวมันไก่ผสม', 'kai-phasom', '55.00', 4, [], 'ต้มและทอดในจานเดียว'],
        ],
        'ของเพิ่ม' => [
            ['ไข่ต้ม', 'khai-tom', '10.00', 1, [], 'หนึ่งฟอง'],
            ['น้ำซุปไก่ตุ๋น', 'sup-kai', '25.00', 2, [], 'ถ้วยใหญ่'],
        ],
    ]],
    ['ขนมปังยามบ่าย', 'shop-khanom', 'ขนมปังปิ้งและของหวาน', 2, 4, 20, 'demo_merchant_5', 'พี่บีม', [
        'ขนมปังปิ้ง' => [
            ['ขนมปังเนยนม', 'toast-noey', '25.00', 3, [], 'ปิ้งร้อน ๆ'],
            ['ขนมปังสังขยา', 'toast-sangkhaya', '30.00', 3, [], 'สังขยาใบเตย'],
        ],
        'ของหวาน' => [
            ['บัวลอยไข่หวาน', 'bualoi', '35.00', 5, [], 'กะทิหอม'],
            ['ลอดช่องกะทิ', 'lodchong', '25.00', 2, [], 'ใส่น้ำแข็ง'],
        ],
    ]],
];

try {
    // The shared connection: pictures are recorded on it too, for shops created in this transaction.
    $pdo = Database::pdo();
    $created = [];
    $replaced = [];
    // Test hook (this script is development only): another photo folder.
    $folder = Env::get('DEMO_FOOD_DIR') ?: dirname(__DIR__) . '/public/assets/demo-food';
    $credits = (string) @file_get_contents("$folder/CREDITS.md");
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

    foreach ($shops as $index => [$name, $file, $description, $capacity, $average, $max, $login, $staff, $categories]) {
        // The first three shops are in the central court, the others in the second one.
        $court = $courts[$index < 3 ? 0 : 1];
        // No shop_hours rows: open at any time, so a rehearsal at night or on Sunday works too.
        $shop = ensure($pdo, 'shops', ['food_court_id' => $court, 'name' => $name], ['description' => $description, 'capacity' => $capacity, 'accepting_orders' => 1]);
        $replaced[] = $pdo->query('SELECT image_url FROM shops WHERE id = ' . (int) $shop)->fetchColumn() ?: null;
        $shopImage->execute([demoPicture($folder, $file, $shop, $credits, $created), $shop]);
        ensure($pdo, 'shop_settings', ['shop_id' => $shop], ['avg_prep_minutes' => $average, 'max_active_orders' => $max], 'shop_id');
        $sort = 0;
        foreach ($categories as $category => $menus) {
            $categoryId = ensure($pdo, 'categories', ['shop_id' => $shop, 'name' => $category], ['sort_order' => $sort++]);
            foreach ($menus as [$menuName, $menuFile, $price, $minutes, $groups, $text]) {
                $menu = ensure($pdo, 'menus', ['shop_id' => $shop, 'name' => $menuName], ['category_id' => $categoryId, 'price' => $price, 'prep_minutes' => $minutes, 'description' => $text]);
                $replaced[] = $pdo->query('SELECT image_url FROM menus WHERE id = ' . (int) $menu)->fetchColumn() ?: null;
                $image->execute([demoPicture($folder, $menuFile, $shop, $credits, $created), $menu]);
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
    // Pictures of an earlier run, now replaced.
    foreach ($replaced as $old) {
        Images::release($old);
    }
    $photos = count($created);
    echo "Demo seed complete ($photos photos; the other menus and shops show a drawn picture).\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($created ?? [] as $key) {
        Images::discard($key);
    }
    fwrite(STDERR, 'Demo seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
