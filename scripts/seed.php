<?php
declare(strict_types=1);

// Usage: php scripts/seed.php (development only). Safe to re-run: existing rows are left unchanged.

use App\Support\Database;
use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

// Fail closed: an unset APP_ENV is refused as well as production.
if (Env::get('APP_ENV') !== 'development') {
    fwrite(STDERR, "Refusing to seed: APP_ENV must be development.\n");
    exit(1);
}
$password = Env::get('SEED_DEMO_PASSWORD');
if (strlen($password) < 12) {
    fwrite(STDERR, "Refusing to seed: set SEED_DEMO_PASSWORD (at least 12 characters) in .env.\n");
    exit(1);
}

/** Returns the id of the row matching $match, inserting it with $extra when missing. */
function ensure(PDO $pdo, string $table, array $match, array $extra = [], string $idColumn = 'id'): int
{
    $where = implode(' AND ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($match)));
    $find = $pdo->prepare("SELECT `$idColumn` FROM `$table` WHERE $where LIMIT 1");
    $find->execute(array_values($match));
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $row = $match + $extra;
    $columns = implode(', ', array_map(static fn (string $c): string => "`$c`", array_keys($row)));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($marks)")->execute(array_values($row));
    return isset($row[$idColumn]) ? (int) $row[$idColumn] : (int) $pdo->lastInsertId();
}

function ensureStaff(PDO $pdo, string $table, string $login, string $name, string $password): int
{
    $find = $pdo->prepare("SELECT user_id FROM `$table` WHERE login_name = ?");
    $find->execute([$login]);
    $userId = $find->fetchColumn();
    if ($userId !== false) {
        return (int) $userId;
    }
    $pdo->prepare('INSERT INTO users (display_name) VALUES (?)')->execute([$name]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `$table` (user_id, login_name, password_hash) VALUES (?, ?, ?)")
        ->execute([$userId, $login, password_hash($password, PASSWORD_DEFAULT)]);
    return $userId;
}

$spice = ['ระดับความเผ็ด', 1, 1, [['ไม่เผ็ด', '0.00'], ['เผ็ดน้อย', '0.00'], ['เผ็ดมาก', '0.00']]];
$extras = ['เพิ่มเติม', 0, 2, [['ไข่ดาว', '10.00'], ['พิเศษ', '15.00']]];
$noodle = ['เลือกเส้น', 1, 1, [['เส้นเล็ก', '0.00'], ['เส้นใหญ่', '0.00'], ['บะหมี่', '5.00']]];
$sweet = ['ระดับความหวาน', 1, 1, [['หวานปกติ', '0.00'], ['หวานน้อย', '0.00'], ['ไม่หวาน', '0.00']]];

// shop => [description, capacity, avg_prep_minutes, max_active_orders, category => [[menu, price, prep, groups]]]
$shops = [
    'ครัวตามสั่ง (Demo)' => ['อาหารตามสั่งจานเดียว', 2, 7, 20, [
        'จานเดียว' => [['ข้าวกะเพราหมูสับ', '45.00', 6, [$spice, $extras]], ['ข้าวผัดหมู', '45.00', 6, [$extras]]],
        'ต้ม/แกง' => [['ต้มยำหมู', '60.00', 9, [$spice]]],
    ]],
    'ก๋วยเตี๋ยวเรือ (Demo)' => ['ก๋วยเตี๋ยวหมูและเนื้อ', 3, 5, 30, [
        'ก๋วยเตี๋ยว' => [['ก๋วยเตี๋ยวหมูน้ำตก', '40.00', 4, [$noodle, $spice]], ['ก๋วยเตี๋ยวเนื้อ', '50.00', 5, [$noodle]]],
        'ของทานเล่น' => [['ลูกชิ้นลวก', '30.00', 3, []]],
    ]],
    'เครื่องดื่ม (Demo)' => ['ชา กาแฟ และน้ำผลไม้', 2, 3, 40, [
        'ชา/กาแฟ' => [['ชาไทยเย็น', '30.00', 2, [$sweet]], ['กาแฟเย็น', '35.00', 3, [$sweet]]],
        'น้ำผลไม้' => [['น้ำส้มคั้น', '30.00', 2, []]],
    ]],
];

try {
    $pdo = Database::connect();
    $pdo->beginTransaction();

    $roles = [];
    foreach (['customer', 'merchant', 'admin'] as $role) {
        $roles[$role] = ensure($pdo, 'roles', ['name' => $role]);
    }

    $courtId = ensure($pdo, 'food_courts', ['name' => 'โรงอาหารกลาง (Demo)'], ['location' => 'อาคารตัวอย่าง']);

    $shopIds = [];
    foreach ($shops as $shopName => [$description, $capacity, $avgPrep, $maxActive, $categories]) {
        $shopId = ensure($pdo, 'shops', ['food_court_id' => $courtId, 'name' => $shopName],
            ['description' => $description, 'capacity' => $capacity, 'accepting_orders' => 1]);
        $shopIds[] = $shopId;
        ensure($pdo, 'shop_settings', ['shop_id' => $shopId],
            ['avg_prep_minutes' => $avgPrep, 'max_active_orders' => $maxActive], 'shop_id');
        for ($day = 1; $day <= 5; $day++) {
            ensure($pdo, 'shop_hours', ['shop_id' => $shopId, 'day_of_week' => $day, 'open_time' => '07:00:00'],
                ['close_time' => '17:00:00']);
        }
        $sort = 0;
        foreach ($categories as $categoryName => $menus) {
            $categoryId = ensure($pdo, 'categories', ['shop_id' => $shopId, 'name' => $categoryName], ['sort_order' => $sort++]);
            foreach ($menus as [$menuName, $price, $prep, $groups]) {
                $menuId = ensure($pdo, 'menus', ['shop_id' => $shopId, 'name' => $menuName],
                    ['category_id' => $categoryId, 'price' => $price, 'prep_minutes' => $prep]);
                foreach ($groups as [$groupName, $min, $max, $options]) {
                    $groupId = ensure($pdo, 'menu_option_groups', ['menu_id' => $menuId, 'name' => $groupName],
                        ['min_select' => $min, 'max_select' => $max]);
                    foreach ($options as [$optionName, $delta]) {
                        ensure($pdo, 'menu_options', ['group_id' => $groupId, 'name' => $optionName], ['price_delta' => $delta]);
                    }
                }
            }
        }
    }

    $adminId = ensureStaff($pdo, 'admin_accounts', 'demo_admin', 'Demo Admin', $password);
    ensure($pdo, 'user_roles', ['user_id' => $adminId, 'role_id' => $roles['admin']]);

    foreach ([0, 1] as $i) {
        $n = $i + 1;
        $merchantId = ensureStaff($pdo, 'merchant_accounts', "demo_merchant_$n", "Demo Merchant $n", $password);
        ensure($pdo, 'user_roles', ['user_id' => $merchantId, 'role_id' => $roles['merchant']]);
        ensure($pdo, 'shop_members', ['shop_id' => $shopIds[$i], 'user_id' => $merchantId]);

        // Not a real LINE user ID; for local mock identity only.
        $customerId = ensure($pdo, 'users', ['line_user_id' => "DEMO_CUSTOMER_$n"], ['display_name' => "Demo Customer $n"]);
        ensure($pdo, 'user_roles', ['user_id' => $customerId, 'role_id' => $roles['customer']]);
    }

    $pdo->commit();
    echo "Seed complete.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
