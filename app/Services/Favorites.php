<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;

/** A customer's own favourite shops and menus. Every query is scoped to that user. */
final class Favorites
{
    private const MAX = 200;
    private const KINDS = [
        'shops' => ['table' => 'favorite_shops', 'column' => 'shop_id',
            'visible' => 'SELECT 1 FROM shops s JOIN food_courts f ON f.id = s.food_court_id WHERE s.id = ? AND s.active = 1 AND f.active = 1'],
        'menus' => ['table' => 'favorite_menus', 'column' => 'menu_id',
            'visible' => 'SELECT 1 FROM menus m JOIN shops s ON s.id = m.shop_id JOIN food_courts f ON f.id = s.food_court_id WHERE m.id = ? AND m.active = 1 AND s.active = 1 AND f.active = 1'],
    ];

    public static function add(int $userId, string $kind, int $id): void
    {
        ['table' => $table, 'column' => $column, 'visible' => $visible] = self::KINDS[$kind];
        if (!Database::value($visible, [$id])) {
            throw HttpError::notFound($kind === 'shops' ? 'ไม่พบร้าน' : 'ไม่พบเมนู');
        }
        $others = (int) Database::value("SELECT COUNT(*) FROM $table WHERE user_id = ? AND $column <> ?", [$userId, $id]);
        if ($others >= self::MAX) {
            throw HttpError::conflict('TOO_MANY_FAVORITES', 'รายการโปรดเต็มแล้ว (สูงสุด ' . self::MAX . ' รายการ)');
        }
        Database::run("INSERT IGNORE INTO $table (user_id, $column) VALUES (?, ?)", [$userId, $id]);
    }

    /** Removing something that is not a favourite, or no longer exists, is fine. */
    public static function remove(int $userId, string $kind, int $id): void
    {
        ['table' => $table, 'column' => $column] = self::KINDS[$kind];
        Database::run("DELETE FROM $table WHERE user_id = ? AND $column = ?", [$userId, $id]);
    }

    /** Closed, sold out and removed items stay listed, marked, so the customer can remove them. */
    public static function ofCustomer(int $userId): array
    {
        $shops = Database::rows(
            'SELECT s.id, s.name, s.image_url, s.accepting_orders, (s.active = 1 AND f.active = 1) AS listed FROM favorite_shops x
             JOIN shops s ON s.id = x.shop_id JOIN food_courts f ON f.id = s.food_court_id WHERE x.user_id = ? ORDER BY s.name, s.id',
            [$userId]
        );
        $hours = Catalog::hours(array_column($shops, 'id'));
        $menus = Database::rows(
            'SELECT m.id, m.name, m.price, m.image_url, m.available, s.id AS shop_id, s.name AS shop_name, (m.active = 1 AND s.active = 1 AND f.active = 1) AS listed FROM favorite_menus x
             JOIN menus m ON m.id = x.menu_id JOIN shops s ON s.id = m.shop_id JOIN food_courts f ON f.id = s.food_court_id WHERE x.user_id = ? ORDER BY s.name, m.name, m.id',
            [$userId]
        );
        $pictures = FoodArt::forMenus(array_column($menus, 'id'));
        return [
            'shops' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'name' => $s['name'], 'image_url' => $s['listed'] ? $s['image_url'] : null, 'listed' => (bool) $s['listed'],
                'is_open' => $s['listed'] && $s['accepting_orders'] && Catalog::withinHours($hours[$s['id']] ?? []),
            ], $shops),
            'menus' => array_map(static fn (array $m): array => [
                'id' => $m['id'], 'name' => $m['name'], 'price' => $m['listed'] ? $m['price'] : null, 'image_url' => $m['listed'] ? $m['image_url'] : null, 'art' => $pictures[(int) $m['id']]['art'] ?? 'other',
                'listed' => (bool) $m['listed'], 'available' => $m['listed'] && $m['available'], 'shop' => ['id' => $m['shop_id'], 'name' => $m['shop_name']],
            ], $menus),
        ];
    }
}
