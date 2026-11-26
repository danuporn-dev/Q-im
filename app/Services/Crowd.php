<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Env;

/**
 * How busy a shop or a food court is: accepted orders that are not ready yet (PREPARING, and ACCEPTED from
 * before D31) against the shop's limit, which is max_active_orders, or capacity when no maximum is set (D34).
 */
final class Crowd
{
    private const MEDIUM_PERCENT = 40;
    private const HIGH_PERCENT = 75;

    /** @return 'LOW'|'MEDIUM'|'HIGH'|null null when no limit is known */
    public static function level(int $waiting, int $limit): ?string
    {
        if ($limit <= 0) {
            return null;
        }
        $percent = $waiting * 100 / $limit;
        $high = self::percent('CROWD_HIGH_PERCENT', self::HIGH_PERCENT);
        $medium = min($high, self::percent('CROWD_MEDIUM_PERCENT', self::MEDIUM_PERCENT));
        return $percent >= $high ? 'HIGH' : ($percent >= $medium ? 'MEDIUM' : 'LOW');
    }

    /**
     * @param list<int> $shopIds
     * @return array<int, int> shop id => limit, 0 when unknown
     */
    public static function limits(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $shopIds));
        $limits = [];
        foreach (Database::rows("SELECT s.id, COALESCE(t.max_active_orders, s.capacity, 0) AS n FROM shops s LEFT JOIN shop_settings t ON t.shop_id = s.id WHERE s.id IN ($in)") as $row) {
            $limits[(int) $row['id']] = (int) $row['n'];
        }
        return $limits;
    }

    private static function percent(string $key, int $default): int
    {
        $value = filter_var(Env::get($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        return $value === false ? $default : $value;
    }
}
