<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Env;

/** Creates isolated shops, staff and customers in the test database. */
final class Fixture
{
    public static string $run = '';

    public static function run(): string
    {
        return self::$run === '' ? self::$run = strtoupper(bin2hex(random_bytes(4))) : self::$run;
    }

    /** @return array{shop: int, login: string, user: int, menu: int, menu2: int, group: int, option: int, option2: int} */
    public static function shop(string $tag, array $shop = [], array $settings = []): array
    {
        $tag .= '_' . self::run() . '_' . bin2hex(random_bytes(2));
        $court = (int) Database::value('SELECT id FROM food_courts ORDER BY id LIMIT 1');
        $shopId = Database::insert('shops', $shop + ['food_court_id' => $court, 'name' => "TEST $tag", 'accepting_orders' => 1, 'capacity' => 2]);
        Database::insert('shop_settings', $settings + ['shop_id' => $shopId, 'avg_prep_minutes' => 5, 'max_active_orders' => 100]);
        $category = Database::insert('categories', ['shop_id' => $shopId, 'name' => 'ทั่วไป']);
        $menu = Database::insert('menus', ['shop_id' => $shopId, 'category_id' => $category, 'name' => "ข้าว $tag", 'price' => '45.00', 'prep_minutes' => 6]);
        $menu2 = Database::insert('menus', ['shop_id' => $shopId, 'category_id' => $category, 'name' => "น้ำ $tag", 'price' => '20.50', 'prep_minutes' => 2]);
        $group = Database::insert('menu_option_groups', ['menu_id' => $menu, 'name' => 'เพิ่ม', 'min_select' => 0, 'max_select' => 1]);
        $option = Database::insert('menu_options', ['group_id' => $group, 'name' => 'ไข่ดาว', 'price_delta' => '10.00']);
        $option2 = Database::insert('menu_options', ['group_id' => $group, 'name' => 'พิเศษ', 'price_delta' => '15.25']);
        [$user, $login] = self::staff('merchant', $tag);
        Database::insert('shop_members', ['shop_id' => $shopId, 'user_id' => $user]);
        return ['shop' => $shopId, 'login' => $login, 'user' => $user, 'menu' => $menu, 'menu2' => $menu2, 'group' => $group, 'option' => $option, 'option2' => $option2];
    }

    /** @return array{0: int, 1: string} */
    public static function staff(string $realm, string $tag): array
    {
        $login = strtolower("test_{$realm}_$tag");
        $user = Database::insert('users', ['display_name' => "TEST $realm $tag"]);
        Database::run('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$user, $realm]);
        Database::insert($realm . '_accounts', ['user_id' => $user, 'login_name' => $login, 'password_hash' => password_hash(Env::get('SEED_DEMO_PASSWORD'), PASSWORD_BCRYPT, ['cost' => 4])]);
        return [$user, $login];
    }

    public static function login(string $base, string $realm, string $login): Client
    {
        $c = new Client($base);
        $r = $c->post('/api/v1/auth/login', ['realm' => $realm, 'login' => $login, 'password' => Env::get('SEED_DEMO_PASSWORD')]);
        $c->csrf = $r['body']['data']['csrf'] ?? throw new RuntimeException("login $login: " . $r['raw']);
        return $c;
    }

    public static function customer(string $base, string $tag): Client
    {
        $c = new Client($base);
        $r = $c->post('/api/v1/auth/dev', ['customer' => 'TEST_' . self::run() . '_' . strtoupper($tag)]);
        $c->csrf = $r['body']['data']['csrf'] ?? throw new RuntimeException("customer $tag: " . $r['raw']);
        return $c;
    }

    /** Places an order for one main dish through the API. @return array{0: Client, 1: int} */
    public static function order(string $base, string $tag, array $shop): array
    {
        $c = self::customer($base, $tag);
        $cart = $c->put('/api/v1/cart', ['shop_id' => $shop['shop'], 'items' => [['menu_id' => $shop['menu'], 'quantity' => 1]], 'replace' => true]);
        $r = $c->post('/api/v1/orders', ['idempotency_key' => bin2hex(random_bytes(16)), 'quote' => $cart['body']['data']['quote'] ?? '']);
        return [$c, $r['body']['data']['id'] ?? throw new RuntimeException('order: ' . $r['raw'])];
    }
}
