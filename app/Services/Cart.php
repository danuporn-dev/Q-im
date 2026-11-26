<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;
use App\Support\Money;

/** Server-side cart: one shop at a time, always priced from the database. */
final class Cart
{
    public static function view(int $userId): array
    {
        $cart = Database::row('SELECT id, shop_id FROM carts WHERE user_id = ?', [$userId]);
        if ($cart === null) {
            return self::emptyView();
        }
        $priced = self::price((int) $cart['id'], (int) $cart['shop_id']);
        $shop = Database::row('SELECT id, name FROM shops WHERE id = ?', [$cart['shop_id']]);
        try {
            $shop['is_open'] = Catalog::shop((int) $cart['shop_id'])['is_open'];
        } catch (HttpError) {
            $shop['is_open'] = false;
        }
        $shop['promptpay'] = PromptPay::numberOf((int) $cart['shop_id']) !== null;
        return ['shop' => $shop] + $priced;
    }

    public static function emptyView(): array
    {
        return ['shop' => null, 'items' => [], 'total' => '0.00', 'quote' => null, 'count' => 0, 'has_problems' => false];
    }

    /** Replaces the whole cart content. */
    public static function replace(int $userId, array $in): array
    {
        $items = [];
        foreach (Input::list($in, 'items', 'รายการอาหาร', 30) as $item) {
            $item = is_array($item) ? $item : [];
            $options = [];
            foreach (Input::list($item + ['option_ids' => []], 'option_ids', 'ตัวเลือก', 60) as $optionId) {
                $options[] = Input::int(['o' => $optionId], 'o', 'ตัวเลือก', 1, PHP_INT_MAX);
            }
            $items[] = [
                'menu_id' => Input::int($item, 'menu_id', 'เมนู', 1, PHP_INT_MAX),
                'quantity' => Input::int($item, 'quantity', 'จำนวน', 1, 20),
                'note' => Input::optionalText($item, 'note', 'หมายเหตุ', 255),
                'options' => array_values(array_unique($options)),
            ];
        }
        if ($items === []) {
            Database::run('DELETE FROM carts WHERE user_id = ?', [$userId]);
            return self::emptyView();
        }
        $shopId = Input::int($in, 'shop_id', 'ร้าน', 1, PHP_INT_MAX);
        Catalog::shop($shopId);
        foreach ($items as $item) {
            $problem = self::line($shopId, $item['menu_id'], $item['options'])['problem'];
            if ($problem !== null) {
                throw HttpError::conflict('ITEM_UNAVAILABLE', $problem, ['menu_id' => $item['menu_id']]);
            }
        }
        Database::tx(static function () use ($userId, $shopId, $items, $in): void {
            // Lock only the cart: locking the shop row too would make carts wait for every checkout.
            $cart = Database::row('SELECT id, shop_id FROM carts WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($cart !== null && (int) $cart['shop_id'] !== $shopId) {
                if (($in['replace'] ?? false) !== true) {
                    $cart['name'] = Database::value('SELECT name FROM shops WHERE id = ?', [$cart['shop_id']]);
                    throw HttpError::conflict('CART_SHOP_CONFLICT', 'ตะกร้ามีอาหารจากร้าน ' . $cart['name'] . ' อยู่ ต้องล้างตะกร้าเดิมก่อน', ['shop_id' => $cart['shop_id'], 'shop_name' => $cart['name']]);
                }
                Database::run('DELETE FROM carts WHERE id = ?', [$cart['id']]);
                $cart = null;
            }
            if ($cart === null) {
                Database::run('INSERT INTO carts (user_id, shop_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP', [$userId, $shopId]);
                $cart = Database::row('SELECT id, shop_id FROM carts WHERE user_id = ? FOR UPDATE', [$userId]);
                if ((int) $cart['shop_id'] !== $shopId) {
                    throw HttpError::conflict('CART_SHOP_CONFLICT', 'ตะกร้าถูกเปลี่ยนจากอุปกรณ์อื่น กรุณาลองใหม่');
                }
            }
            Database::run('DELETE FROM cart_items WHERE cart_id = ?', [$cart['id']]);
            foreach ($items as $item) {
                $itemId = Database::insert('cart_items', ['cart_id' => $cart['id'], 'menu_id' => $item['menu_id'], 'quantity' => $item['quantity'], 'note' => $item['note']]);
                foreach ($item['options'] as $optionId) {
                    Database::insert('cart_item_options', ['cart_item_id' => $itemId, 'option_id' => $optionId]);
                }
            }
        });
        return self::view($userId);
    }

    /** Prices every line again; the quote changes whenever anything the customer pays for changes. */
    public static function price(int $cartId, int $shopId): array
    {
        $options = [];
        foreach (Database::rows('SELECT o.cart_item_id, o.option_id FROM cart_item_options o JOIN cart_items i ON i.id = o.cart_item_id WHERE i.cart_id = ? ORDER BY o.option_id', [$cartId]) as $o) {
            $options[$o['cart_item_id']][] = (int) $o['option_id'];
        }
        $items = [];
        // The cart id makes a quote unique per cart, so reordering the same food is a new order.
        $signature = [$cartId, $shopId];
        $total = 0;
        $count = 0;
        $problems = false;
        foreach (Database::rows('SELECT id, menu_id, quantity, note FROM cart_items WHERE cart_id = ? ORDER BY id', [$cartId]) as $row) {
            $line = self::line($shopId, (int) $row['menu_id'], $options[$row['id']] ?? []);
            $unit = Money::toSatang($line['base_price']);
            foreach ($line['options'] as $o) {
                $unit += Money::toSatang($o['price_delta']);
            }
            $total += $unit * $row['quantity'];
            $count += $row['quantity'];
            $problems = $problems || $line['problem'] !== null;
            $signature[] = [$row['menu_id'], $row['quantity'], $line['base_price'], array_map(static fn (array $o): array => [$o['id'], $o['price_delta']], $line['options'])];
            $items[] = ['id' => $row['id'], 'menu_id' => $row['menu_id'], 'quantity' => $row['quantity'], 'note' => $row['note'], 'unit_price' => Money::fromSatang($unit), 'line_total' => Money::fromSatang($unit * $row['quantity'])] + $line;
        }
        return ['items' => $items, 'total' => Money::fromSatang($total), 'quote' => hash('sha256', json_encode($signature)), 'count' => $count, 'has_problems' => $problems];
    }

    /** @return array{name: string, image_url: ?string, art: string, base_price: string, options: list<array>, problem: ?string} */
    private static function line(int $shopId, int $menuId, array $optionIds): array
    {
        $menu = Database::row('SELECT m.id, m.name, m.image_url, m.art, m.price, m.available, m.active, c.name AS category FROM menus m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id = ? AND m.shop_id = ?', [$menuId, $shopId]);
        if ($menu === null) {
            return ['name' => '', 'image_url' => null, 'art' => 'other', 'base_price' => '0.00', 'options' => [], 'problem' => 'ไม่พบเมนูในร้านนี้'];
        }
        $problem = !$menu['active'] || !$menu['available'] ? $menu['name'] . ' หมดแล้ว' : null;
        $chosen = [];
        foreach (Database::rows('SELECT id, name, min_select, max_select FROM menu_option_groups WHERE menu_id = ? AND active = 1 ORDER BY id', [$menuId]) as $group) {
            $picked = 0;
            foreach (Database::rows('SELECT id, name, price_delta FROM menu_options WHERE group_id = ? AND active = 1 ORDER BY id', [$group['id']]) as $option) {
                if (in_array((int) $option['id'], $optionIds, true)) {
                    $chosen[] = ['id' => (int) $option['id'], 'name' => $option['name'], 'price_delta' => $option['price_delta']];
                    $picked++;
                }
            }
            if ($picked < $group['min_select'] || $picked > $group['max_select']) {
                $problem ??= 'กรุณาเลือก ' . $group['name'] . ' ของ ' . $menu['name'] . ' ใหม่';
            }
        }
        if (count($chosen) !== count($optionIds)) {
            $problem ??= 'ตัวเลือกของ ' . $menu['name'] . ' เปลี่ยนไป กรุณาเลือกใหม่';
        }
        return ['name' => $menu['name'], 'image_url' => $menu['image_url'], 'art' => FoodArt::of($menu['art'], $menu['name'], $menu['category']), 'base_price' => $menu['price'], 'options' => $chosen, 'problem' => $problem];
    }
}
