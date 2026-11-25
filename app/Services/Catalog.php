<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;

/** Public shop and menu discovery. */
final class Catalog
{
    private const VISIBLE = 's.active = 1 AND f.active = 1';

    /** @return array{0: list<array>, 1: int} shops and total count */
    public static function shops(string $q, string $category, bool $openOnly, int $limit, int $offset): array
    {
        $where = [self::VISIBLE];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(s.name LIKE ? OR EXISTS (SELECT 1 FROM menus m WHERE m.shop_id = s.id AND m.active = 1 AND m.name LIKE ?))';
            array_push($params, $like, $like);
        }
        if ($category !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM categories c JOIN menus m ON m.category_id = c.id AND m.active = 1 WHERE c.shop_id = s.id AND c.active = 1 AND c.name = ?)';
            $params[] = $category;
        }
        $rows = Database::rows(
            'SELECT s.id, s.name, s.description, s.image_url, s.accepting_orders, f.name AS food_court,
                    (SELECT MIN(m.price) FROM menus m WHERE m.shop_id = s.id AND m.active = 1) AS min_price,
                    (SELECT MAX(m.price) FROM menus m WHERE m.shop_id = s.id AND m.active = 1) AS max_price
             FROM shops s JOIN food_courts f ON f.id = s.food_court_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY s.accepting_orders DESC, s.name, s.id',
            $params
        );
        $hours = self::hours(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['is_open'] = (bool) $row['accepting_orders'] && self::withinHours($hours[$row['id']] ?? []);
            unset($row['accepting_orders']);
        }
        unset($row);
        if ($openOnly) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['is_open']));
        }
        usort($rows, static fn (array $a, array $b): int => $b['is_open'] <=> $a['is_open']);
        $total = count($rows);
        $rows = array_slice($rows, $offset, $limit);
        $load = Eta::shops(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$row) {
            $row['waiting'] = $load[$row['id']]['waiting'];
            $row['eta_minutes'] = $load[$row['id']]['eta_minutes'];
            if ($q !== '') {
                $row['matched_menus'] = Database::rows(
                    'SELECT id, name, price, available FROM menus WHERE shop_id = ? AND active = 1 AND name LIKE ? ORDER BY name LIMIT 5',
                    [$row['id'], '%' . addcslashes($q, '\\%_') . '%']
                );
            }
        }
        return [$rows, $total];
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_column(Database::rows(
            'SELECT c.name, COUNT(DISTINCT s.id) AS n FROM categories c
             JOIN shops s ON s.id = c.shop_id JOIN food_courts f ON f.id = s.food_court_id
             JOIN menus m ON m.category_id = c.id AND m.active = 1
             WHERE c.active = 1 AND ' . self::VISIBLE . ' GROUP BY c.name ORDER BY n DESC, c.name LIMIT 20'
        ), 'name');
    }

    public static function shop(int $id): array
    {
        $shop = Database::row(
            'SELECT s.id, s.name, s.description, s.image_url, s.accepting_orders, f.name AS food_court, f.location
             FROM shops s JOIN food_courts f ON f.id = s.food_court_id WHERE s.id = ? AND ' . self::VISIBLE,
            [$id]
        ) ?? throw HttpError::notFound('ไม่พบร้าน');
        $hours = self::hours([$id])[$id] ?? [];
        $shop['is_open'] = (bool) $shop['accepting_orders'] && self::withinHours($hours);
        unset($shop['accepting_orders']);
        $load = Eta::shops([$id])[$id];
        return $shop + ['hours' => $hours, 'waiting' => $load['waiting'], 'eta_minutes' => $load['eta_minutes']];
    }

    /**
     * Categories with menus, options included.
     *
     * @return array{categories: list<array>, menus: list<array>}
     */
    public static function menus(int $shopId, bool $includeInactive = false): array
    {
        $active = $includeInactive ? '' : ' AND active = 1';
        $menus = Database::rows("SELECT id, category_id, name, description, image_url, price, prep_minutes, available, active FROM menus WHERE shop_id = ?$active ORDER BY name, id", [$shopId]);
        $groups = [];
        if ($menus !== []) {
            $in = implode(',', array_column($menus, 'id'));
            $activeGroup = $includeInactive ? '' : ' AND g.active = 1 AND (o.active = 1 OR o.id IS NULL)';
            $rows = Database::rows(
                "SELECT g.id, g.menu_id, g.name, g.min_select, g.max_select, g.active, o.id AS option_id, o.name AS option_name, o.price_delta, o.active AS option_active
                 FROM menu_option_groups g LEFT JOIN menu_options o ON o.group_id = g.id
                 WHERE g.menu_id IN ($in)$activeGroup ORDER BY g.id, o.id"
            );
            foreach ($rows as $r) {
                $groups[$r['menu_id']][$r['id']] ??= ['id' => $r['id'], 'name' => $r['name'], 'min_select' => $r['min_select'], 'max_select' => $r['max_select'], 'active' => (bool) $r['active'], 'options' => []];
                if ($r['option_id'] !== null) {
                    $groups[$r['menu_id']][$r['id']]['options'][] = ['id' => $r['option_id'], 'name' => $r['option_name'], 'price_delta' => $r['price_delta'], 'active' => (bool) $r['option_active']];
                }
            }
        }
        foreach ($menus as &$menu) {
            $menu['available'] = (bool) $menu['available'];
            $menu['active'] = (bool) $menu['active'];
            $menu['option_groups'] = array_values($groups[$menu['id']] ?? []);
        }
        return [
            'categories' => Database::rows("SELECT id, name, sort_order, active FROM categories WHERE shop_id = ?$active ORDER BY sort_order, id", [$shopId]),
            'menus' => $menus,
        ];
    }

    /** @return array<int, list<array>> */
    public static function hours(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $shopIds));
        $hours = [];
        foreach (Database::rows("SELECT shop_id, day_of_week, TIME_FORMAT(open_time, '%H:%i') AS open_time, TIME_FORMAT(close_time, '%H:%i') AS close_time FROM shop_hours WHERE shop_id IN ($in) ORDER BY day_of_week, open_time") as $h) {
            $shopId = $h['shop_id'];
            unset($h['shop_id']);
            $hours[$shopId][] = $h;
        }
        return $hours;
    }

    /** No hours configured means always open; close before open crosses midnight. */
    public static function withinHours(array $hours): bool
    {
        if ($hours === []) {
            return true;
        }
        $now = Clock::bangkok();
        $today = (int) $now->format('N');
        $yesterday = $today === 1 ? 7 : $today - 1;
        $time = $now->format('H:i');
        foreach ($hours as $h) {
            $overnight = $h['close_time'] <= $h['open_time'];
            $day = (int) $h['day_of_week'];
            if ($day === $today && $time >= $h['open_time'] && ($overnight || $time < $h['close_time'])) {
                return true;
            }
            if ($overnight && $day === $yesterday && $time < $h['close_time']) {
                return true;
            }
        }
        return false;
    }
}
