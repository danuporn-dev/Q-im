<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;

/** Waiting-time estimates. Null always means "cannot be estimated"; values are never negative. */
final class Eta
{
    /**
     * Queue summary per shop.
     *
     * @param list<int> $shopIds
     * @return array<int, array{waiting: int, eta_minutes: ?int, jobs: list<array>, avg: ?int, capacity: ?int}>
     */
    public static function shops(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $shopIds));
        $result = [];
        foreach (Database::rows("SELECT s.id, s.capacity, t.avg_prep_minutes FROM shops s LEFT JOIN shop_settings t ON t.shop_id = s.id WHERE s.id IN ($in)") as $s) {
            $result[(int) $s['id']] = [
                'waiting' => 0, 'eta_minutes' => null, 'jobs' => [],
                'avg' => $s['avg_prep_minutes'] === null ? null : (int) $s['avg_prep_minutes'],
                'capacity' => $s['capacity'] === null ? null : (int) $s['capacity'],
            ];
        }
        // Prefer what the shop really takes once there is enough data.
        $since = gmdate('Y-m-d H:i:s', Clock::timestamp() - 14 * 86400);
        $measured = Database::rows(
            "SELECT shop_id, AVG(TIMESTAMPDIFF(SECOND, cooking_started_at, ready_at)) AS seconds FROM orders
             WHERE shop_id IN ($in) AND cooking_started_at IS NOT NULL AND ready_at >= ? GROUP BY shop_id HAVING COUNT(*) >= 5",
            [$since]
        );
        foreach ($measured as $m) {
            $result[(int) $m['shop_id']]['avg'] = max(1, (int) ceil((float) $m['seconds'] / 60));
        }
        $jobs = Database::rows(
            "SELECT o.id, o.shop_id, o.status, o.cooking_started_at, MAX(m.prep_minutes) AS longest,
                    SUM(m.prep_minutes IS NULL) AS unknown_items, SUM(i.quantity) AS quantity
             FROM orders o JOIN queues q ON q.order_id = o.id
             JOIN order_items i ON i.order_id = o.id JOIN menus m ON m.id = i.menu_id
             WHERE o.shop_id IN ($in) AND o.status IN ('ACCEPTED','PREPARING')
             GROUP BY o.id, o.shop_id, o.status, o.cooking_started_at, q.business_date, q.queue_number
             ORDER BY q.business_date, q.queue_number"
        );
        foreach ($jobs as $job) {
            $shop = &$result[(int) $job['shop_id']];
            // Accepting starts PREPARING at once (D31); an order counts down only from when it got a kitchen slot (D32).
            $shop['waiting']++;
            $shop['jobs'][] = ['id' => (int) $job['id'], 'status' => $job['status'], 'cooking' => $job['cooking_started_at'] !== null, 'minutes' => self::remaining($job, $shop['avg'])];
            unset($shop);
        }
        foreach ($result as &$shop) {
            $backlog = self::backlog($shop['jobs'], $shop['capacity']);
            $shop['eta_minutes'] = $backlog === null || $shop['avg'] === null ? null : $backlog + $shop['avg'];
        }
        return $result;
    }

    /** @return array{ahead: ?int, eta_minutes: ?int} */
    public static function order(int $orderId, int $shopId, string $status): array
    {
        if (!in_array($status, ['ACCEPTED', 'PREPARING'], true)) {
            return ['ahead' => null, 'eta_minutes' => null];
        }
        $shop = self::shops([$shopId])[$shopId];
        $ahead = [];
        foreach ($shop['jobs'] as $job) {
            if ($job['id'] === $orderId) {
                // Up to `capacity` orders cook side by side; one without a slot waits for the backlog ahead of it.
                $before = $shop['capacity'] === null ? null : ($job['cooking'] ? 0 : self::backlog($ahead, $shop['capacity']));
                $eta = $before === null || $job['minutes'] === null ? null : $before + $job['minutes'];
                return ['ahead' => count($ahead), 'eta_minutes' => $eta];
            }
            $ahead[] = $job;
        }
        return ['ahead' => null, 'eta_minutes' => null];
    }

    private static function remaining(array $job, ?int $avg): ?int
    {
        if ((int) $job['unknown_items'] > 0 && $avg === null) {
            return null;
        }
        $longest = max((int) $job['longest'], (int) $job['unknown_items'] > 0 ? $avg : 0);
        $minutes = $longest + (int) $job['quantity'] - 1;
        if ($job['cooking_started_at'] !== null) {
            $minutes -= intdiv(Clock::timestamp() - strtotime($job['cooking_started_at'] . ' UTC'), 60);
        }
        return max(1, $minutes);
    }

    private static function backlog(array $jobs, ?int $capacity): ?int
    {
        $total = 0;
        foreach ($jobs as $job) {
            if ($job['minutes'] === null) {
                return null;
            }
            $total += $job['minutes'];
        }
        return $capacity === null ? null : (int) ceil($total / $capacity);
    }
}
