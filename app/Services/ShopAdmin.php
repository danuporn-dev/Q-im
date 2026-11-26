<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;

/** Menu and settings management by a shop's own members. Callers check membership first. */
final class ShopAdmin
{
    public static function settings(int $shopId): array
    {
        $shop = Database::row(
            'SELECT s.id, s.name, s.description, s.image_url, s.accepting_orders, s.capacity, s.active, t.avg_prep_minutes, t.max_active_orders, t.promptpay_phone, t.promptpay_enabled
             FROM shops s LEFT JOIN shop_settings t ON t.shop_id = s.id WHERE s.id = ?',
            [$shopId]
        ) ?? throw HttpError::notFound('ไม่พบร้าน');
        $shop['accepting_orders'] = (bool) $shop['accepting_orders'];
        $shop['active'] = (bool) $shop['active'];
        $shop['promptpay_enabled'] = (bool) $shop['promptpay_enabled'];
        $hours = Catalog::hours([$shopId])[$shopId] ?? [];
        return $shop + ['ready_alert_minutes' => OrderFlow::readyWaitMinutes(), 'hours' => $hours, 'is_open' => $shop['active'] && $shop['accepting_orders'] && Catalog::withinHours($hours)];
    }

    public static function updateSettings(int $userId, int $shopId, array $in): array
    {
        $shop = [];
        $settings = [];
        if (array_key_exists('name', $in)) {
            $shop['name'] = Input::text($in, 'name', 'ชื่อร้าน', 150);
        }
        if (array_key_exists('description', $in)) {
            $shop['description'] = Input::optionalText($in, 'description', 'รายละเอียดร้าน', 2000);
        }
        if (array_key_exists('image_url', $in)) {
            $shop['image_url'] = Input::imageUrl($in, 'image_url');
        }
        if (array_key_exists('accepting_orders', $in)) {
            $shop['accepting_orders'] = Input::bool($in, 'accepting_orders', 'สถานะรับออเดอร์');
        }
        if (array_key_exists('capacity', $in)) {
            $shop['capacity'] = Input::optionalInt($in, 'capacity', 'จำนวนที่ทำพร้อมกัน', 1, 100);
        }
        if (array_key_exists('avg_prep_minutes', $in)) {
            $settings['avg_prep_minutes'] = Input::optionalInt($in, 'avg_prep_minutes', 'เวลาทำเฉลี่ย', 1, 240);
        }
        if (array_key_exists('max_active_orders', $in)) {
            $settings['max_active_orders'] = Input::optionalInt($in, 'max_active_orders', 'จำนวนออเดอร์สูงสุด', 1, 1000);
        }
        if (array_key_exists('promptpay_phone', $in)) {
            $settings['promptpay_phone'] = in_array($in['promptpay_phone'], [null, ''], true) ? null : PromptPay::phone($in['promptpay_phone']);
        }
        if (array_key_exists('promptpay_enabled', $in)) {
            $settings['promptpay_enabled'] = Input::bool($in, 'promptpay_enabled', 'การเปิด PromptPay');
        }
        $hours = array_key_exists('hours', $in) ? self::validHours($in) : null;
        $replaced = Database::tx(static function () use ($userId, $shopId, $shop, $settings, $hours): ?string {
            $current = Database::row('SELECT image_url FROM shops WHERE id = ? FOR UPDATE', [$shopId])['image_url'] ?? null;
            if (array_key_exists('image_url', $shop)) {
                Images::claim($shop['image_url'], $current, $shopId);
            }
            Database::update('shops', $shopId, $shop);
            if ($settings !== []) {
                Database::run('INSERT IGNORE INTO shop_settings (shop_id) VALUES (?)', [$shopId]);
                foreach ($settings as $column => $value) {
                    Database::run("UPDATE shop_settings SET `$column` = ? WHERE shop_id = ?", [$value, $shopId]);
                }
            }
            if (Database::value('SELECT 1 FROM shop_settings WHERE shop_id = ? AND promptpay_enabled = 1 AND promptpay_phone IS NULL', [$shopId])) {
                throw HttpError::invalid('ต้องใส่หมายเลขมือถือก่อนเปิด PromptPay', ['field' => 'promptpay_phone']);
            }
            if ($hours !== null) {
                Database::run('DELETE FROM shop_hours WHERE shop_id = ?', [$shopId]);
                foreach ($hours as $h) {
                    Database::insert('shop_hours', ['shop_id' => $shopId] + $h);
                }
            }
            if (array_key_exists('capacity', $shop)) {
                OrderFlow::fillKitchen($shopId);
            }
            Audit::log($userId, 'shop.settings', 'shop', $shopId, ['fields' => array_merge(array_keys($shop + $settings), $hours === null ? [] : ['hours'])]);
            return array_key_exists('image_url', $shop) && $shop['image_url'] !== $current ? $current : null;
        });
        Images::release($replaced);
        return self::settings($shopId);
    }

    private static function validHours(array $in): array
    {
        $hours = [];
        foreach (Input::list($in, 'hours', 'เวลาเปิดปิด', 21) as $h) {
            $h = is_array($h) ? $h : [];
            $row = ['day_of_week' => Input::int($h, 'day_of_week', 'วัน', 1, 7)];
            foreach (['open_time', 'close_time'] as $key) {
                if (!is_string($h[$key] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $h[$key])) {
                    throw HttpError::invalid('เวลาเปิดปิดไม่ถูกต้อง');
                }
                $row[$key] = $h[$key];
            }
            if ($row['open_time'] === $row['close_time']) {
                throw HttpError::invalid('เวลาเปิดและปิดต้องต่างกัน');
            }
            $unique = $row['day_of_week'] . $row['open_time'];
            if (isset($hours[$unique])) {
                throw HttpError::invalid('เวลาเปิดซ้ำกันในวันเดียว');
            }
            $hours[$unique] = $row;
        }
        return array_values($hours);
    }

    public static function saveCategory(int $userId, int $shopId, ?int $id, array $in): array
    {
        if ($id !== null && !Database::value('SELECT 1 FROM categories WHERE id = ? AND shop_id = ?', [$id, $shopId])) {
            throw HttpError::notFound('ไม่พบหมวดหมู่');
        }
        $row = [];
        if ($id === null || array_key_exists('name', $in)) {
            $row['name'] = Input::text($in, 'name', 'ชื่อหมวดหมู่', 100);
        }
        if (array_key_exists('sort_order', $in)) {
            $row['sort_order'] = Input::int($in, 'sort_order', 'ลำดับ', 0, 9999);
        }
        if (array_key_exists('active', $in)) {
            $row['active'] = Input::bool($in, 'active', 'สถานะ');
        }
        return Database::tx(static function () use ($userId, $shopId, $id, $row): array {
            if ($id === null) {
                $id = Database::insert('categories', $row + ['shop_id' => $shopId]);
            } else {
                Database::update('categories', $id, $row);
            }
            Audit::log($userId, 'category.save', 'category', $id, ['shop_id' => $shopId]);
            return Database::row('SELECT id, name, sort_order, active FROM categories WHERE id = ?', [$id]);
        });
    }

    public static function menuShop(int $menuId): int
    {
        return (int) (Database::value('SELECT shop_id FROM menus WHERE id = ?', [$menuId]) ?: throw HttpError::notFound('ไม่พบเมนู'));
    }

    public static function saveMenu(int $userId, int $shopId, ?int $menuId, array $in): array
    {
        $new = $menuId === null;
        $row = [];
        if ($new || array_key_exists('name', $in)) {
            $row['name'] = Input::text($in, 'name', 'ชื่อเมนู', 150);
        }
        if (array_key_exists('description', $in)) {
            $row['description'] = Input::optionalText($in, 'description', 'รายละเอียด', 2000);
        }
        if (array_key_exists('image_url', $in)) {
            $row['image_url'] = Input::imageUrl($in, 'image_url');
        }
        if (array_key_exists('art', $in)) {
            if ($in['art'] !== null && !in_array($in['art'], FoodArt::KINDS, true)) {
                throw HttpError::invalid('ภาพประกอบไม่ถูกต้อง', ['field' => 'art']);
            }
            $row['art'] = $in['art'];
        }
        if ($new || array_key_exists('price', $in)) {
            $row['price'] = Input::money($in, 'price', 'ราคา');
        }
        if (array_key_exists('prep_minutes', $in)) {
            $row['prep_minutes'] = Input::optionalInt($in, 'prep_minutes', 'เวลาทำ', 1, 240);
        }
        foreach (['available' => 'สถานะเมนู', 'active' => 'การใช้งานเมนู'] as $key => $label) {
            if (array_key_exists($key, $in)) {
                $row[$key] = Input::bool($in, $key, $label);
            }
        }
        if (array_key_exists('category_id', $in)) {
            $row['category_id'] = Input::optionalInt($in, 'category_id', 'หมวดหมู่', 1, PHP_INT_MAX);
            if ($row['category_id'] !== null && !Database::value('SELECT 1 FROM categories WHERE id = ? AND shop_id = ?', [$row['category_id'], $shopId])) {
                throw HttpError::invalid('หมวดหมู่ไม่ใช่ของร้านนี้');
            }
        }
        $groups = array_key_exists('option_groups', $in) ? self::validGroups($in) : null;

        [$menuId, $replaced] = Database::tx(static function () use ($userId, $shopId, $menuId, $row, $groups): array {
            $current = $menuId === null ? null : (Database::row('SELECT image_url FROM menus WHERE id = ? FOR UPDATE', [$menuId])['image_url'] ?? null);
            if (array_key_exists('image_url', $row)) {
                Images::claim($row['image_url'], $current, $shopId);
            }
            if ($menuId === null) {
                $menuId = Database::insert('menus', $row + ['shop_id' => $shopId]);
            } else {
                Database::update('menus', $menuId, $row);
            }
            if ($groups !== null) {
                self::syncGroups($menuId, $groups);
            }
            Audit::log($userId, 'menu.save', 'menu', $menuId, ['shop_id' => $shopId, 'fields' => array_keys($row)]);
            return [$menuId, array_key_exists('image_url', $row) && $row['image_url'] !== $current ? $current : null];
        });
        Images::release($replaced);
        foreach (Catalog::menus($shopId, true)['menus'] as $menu) {
            if ((int) $menu['id'] === $menuId) {
                return $menu;
            }
        }
        throw HttpError::notFound('ไม่พบเมนู');
    }

    private static function validGroups(array $in): array
    {
        $groups = [];
        foreach (Input::list($in, 'option_groups', 'ตัวเลือก', 10) as $g) {
            $g = is_array($g) ? $g : [];
            $options = [];
            foreach (Input::list($g, 'options', 'ตัวเลือก', 30) as $o) {
                $o = is_array($o) ? $o : [];
                $options[] = [
                    'id' => Input::optionalInt($o, 'id', 'ตัวเลือก', 1, PHP_INT_MAX),
                    'name' => Input::text($o, 'name', 'ชื่อตัวเลือก', 100),
                    'price_delta' => Input::money($o, 'price_delta', 'ราคาเพิ่ม'),
                ];
            }
            $min = Input::int($g, 'min_select', 'เลือกอย่างน้อย', 0, 30);
            $max = Input::int($g, 'max_select', 'เลือกได้สูงสุด', 1, 30);
            if ($options === [] || $min > $max || $max > count($options)) {
                throw HttpError::invalid('จำนวนที่เลือกได้ไม่สอดคล้องกับจำนวนตัวเลือก');
            }
            $groups[] = [
                'id' => Input::optionalInt($g, 'id', 'กลุ่มตัวเลือก', 1, PHP_INT_MAX),
                'row' => ['name' => Input::text($g, 'name', 'ชื่อกลุ่มตัวเลือก', 100), 'min_select' => $min, 'max_select' => $max, 'active' => 1],
                'options' => $options,
            ];
        }
        return $groups;
    }

    /** Groups and options that are left out are retired, never deleted. */
    private static function syncGroups(int $menuId, array $groups): void
    {
        Database::run('UPDATE menu_option_groups SET active = 0 WHERE menu_id = ?', [$menuId]);
        foreach ($groups as $g) {
            if ($g['id'] === null) {
                $groupId = Database::insert('menu_option_groups', $g['row'] + ['menu_id' => $menuId]);
            } else {
                $groupId = $g['id'];
                if (!Database::value('SELECT 1 FROM menu_option_groups WHERE id = ? AND menu_id = ?', [$groupId, $menuId])) {
                    throw HttpError::invalid('กลุ่มตัวเลือกไม่ใช่ของเมนูนี้');
                }
                Database::update('menu_option_groups', $groupId, $g['row']);
                Database::run('UPDATE menu_options SET active = 0 WHERE group_id = ?', [$groupId]);
            }
            foreach ($g['options'] as $o) {
                $row = ['name' => $o['name'], 'price_delta' => $o['price_delta'], 'active' => 1];
                if ($o['id'] === null) {
                    Database::insert('menu_options', $row + ['group_id' => $groupId]);
                    continue;
                }
                if (!Database::value('SELECT 1 FROM menu_options WHERE id = ? AND group_id = ?', [$o['id'], $groupId])) {
                    throw HttpError::invalid('ตัวเลือกไม่ใช่ของกลุ่มนี้');
                }
                Database::update('menu_options', $o['id'], $row);
            }
        }
    }
}
