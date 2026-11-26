<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Which drawn picture (public/assets/images/art/<kind>.svg) stands in for a menu without a photo.
 * The shop can choose one; otherwise it is guessed from the menu name, then the category name.
 */
final class FoodArt
{
    public const KINDS = ['rice', 'noodle', 'fried', 'drink', 'dessert', 'other'];

    /**
     * Checked in this order: a sweet made with rice is a dessert, and a noodle soup or a soup eaten with rice
     * is not a drink although its name says น้ำ. A trailing 2 only separates a second list of the same kind.
     */
    private const WORDS = [
        'dessert' => ['ข้าวเหนียวมะม่วง', 'ข้าวเหนียวมูน', 'ข้าวเหนียวเปียก', 'ขนมปัง', 'บัวลอย', 'ลอดช่อง', 'ไอศกรีม', 'ไอติม', 'เค้ก', 'บิงซู', 'พุดดิ้ง', 'วาฟเฟิล', 'โทสต์', 'ทับทิมกรอบ', 'เฉาก๊วย', 'สังขยา', 'ของหวาน', 'ขนมหวาน'],
        'noodle' => ['ก๋วยเตี๋ยว', 'ก๋วยจั๊บ', 'บะหมี่', 'เส้น', 'ราดหน้า', 'ผัดไทย', 'ผัดซีอิ๊ว', 'ขนมจีน', 'เย็นตาโฟ', 'สุกี้', 'มาม่า', 'สปาเกตตี', 'ราเมง', 'อุด้ง', 'เกี๊ยวน้ำ'],
        'rice' => ['ข้าว', 'โจ๊ก', 'ต้มยำ', 'แกง', 'ซุป'],
        'drink' => ['เครื่องดื่ม', 'น้ำ', 'ชา', 'กาแฟ', 'โกโก้', 'นม', 'โซดา', 'ปั่น', 'สมูทตี้', 'ลาเต้', 'อเมริกาโน่', 'เอสเพรสโซ', 'คาปูชิโน่', 'มอคค่า'],
        'fried' => ['ทอด', 'เฟรนช์ฟราย', 'นักเก็ต', 'ปาท่องโก๋', 'ทานเล่น'],
        'rice2' => ['กะเพรา', 'ราด', 'ไข่เจียว', 'คะน้า', 'ผัด'],
        'dessert2' => ['ขนม', 'หวาน'],
    ];

    public static function of(?string $chosen, string $name, ?string $category = null): string
    {
        if ($chosen !== null && in_array($chosen, self::KINDS, true)) {
            return $chosen;
        }
        foreach ([$name, (string) $category] as $text) {
            foreach (self::WORDS as $kind => $words) {
                foreach ($words as $word) {
                    if ($word !== '' && str_contains($text, $word)) {
                        return rtrim($kind, '2');
                    }
                }
            }
        }
        return 'other';
    }

    /**
     * The picture and the drawn picture of each menu, for lists that only have menu ids (cart, orders, favourites).
     * A retired menu has no picture any more, so an old order shows the drawn one.
     *
     * @param list<int|string> $menuIds
     * @return array<int, array{image_url: ?string, art: string}>
     */
    public static function forMenus(array $menuIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($menuIds, static fn ($id): bool => $id !== null))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $out = [];
        foreach (Database::rows("SELECT m.id, m.name, m.image_url, m.art, m.active, c.name AS category FROM menus m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id IN ($in)") as $m) {
            $out[(int) $m['id']] = ['image_url' => $m['active'] ? $m['image_url'] : null, 'art' => self::of($m['art'], $m['name'], $m['category'])];
        }
        return $out;
    }
}
