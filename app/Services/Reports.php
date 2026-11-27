<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;

/**
 * Sales are payments that were confirmed (PAID) in the range; dates are Asia/Bangkok. A cancelled or rejected order
 * with a PAID payment was paid online and refused afterwards (D42, D44): that money is due back, so it is not a sale.
 * Counts cover orders that reached the shop (placed_at); an online order never paid in time never did.
 */
final class Reports
{
    public static function summary(string $from, string $to, ?int $shopId = null): array
    {
        $from = Input::date($from === '' ? Clock::businessDate() : $from, 'วันที่เริ่ม');
        $to = Input::date($to === '' ? Clock::businessDate() : $to, 'วันที่สิ้นสุด');
        if ($from > $to || (strtotime($to) - strtotime($from)) > 366 * 86400) {
            throw HttpError::invalid('ช่วงวันที่ไม่ถูกต้อง (สูงสุด 1 ปี)');
        }
        $range = Clock::utcRange($from, $to);
        $shop = $shopId === null ? '' : ' AND o.shop_id = ' . $shopId;
        $sales = Database::row("SELECT COALESCE(SUM(p.amount), 0.00) AS sales, COUNT(*) AS paid FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.status = 'PAID' AND NOT EXISTS (SELECT 1 FROM orders x WHERE x.id = p.order_id AND x.status IN ('CANCELLED','REJECTED')) AND p.paid_at >= ? AND p.paid_at < ?$shop", $range);
        $counts = ['COMPLETED' => 0, 'REJECTED' => 0, 'CANCELLED' => 0, 'PENDING' => 0];
        foreach (Database::rows("SELECT l.to_status, COUNT(*) AS n FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE o.placed_at IS NOT NULL AND l.created_at >= ? AND l.created_at < ?$shop GROUP BY l.to_status", $range) as $row) {
            $counts[$row['to_status']] = (int) $row['n'];
        }
        $seconds = Database::value("SELECT AVG(TIMESTAMPDIFF(SECOND, o.cooking_started_at, o.ready_at)) FROM orders o WHERE o.cooking_started_at IS NOT NULL AND o.ready_at >= ? AND o.ready_at < ?$shop", $range);
        return [
            'from' => $from, 'to' => $to, 'sales' => $sales['sales'], 'paid_orders' => (int) $sales['paid'],
            'placed' => $counts['PENDING'], 'completed' => $counts['COMPLETED'], 'rejected' => $counts['REJECTED'], 'cancelled' => $counts['CANCELLED'],
            'avg_prep_minutes' => $seconds === null ? null : round((float) $seconds / 60, 1),
            'top_menus' => Database::rows(
                "SELECT MAX(i.name_snapshot) AS name, SUM(i.quantity) AS quantity FROM order_items i JOIN orders o ON o.id = i.order_id JOIN payments p ON p.order_id = o.id
                 WHERE p.status = 'PAID' AND NOT EXISTS (SELECT 1 FROM orders x WHERE x.id = p.order_id AND x.status IN ('CANCELLED','REJECTED')) AND p.paid_at >= ? AND p.paid_at < ?$shop GROUP BY i.menu_id ORDER BY quantity DESC, name LIMIT 10",
                $range
            ),
        ];
    }

    /**
     * A shop's report: the summary of the chosen dates, plus paid sales for today and the last 7 and 30 days
     * (both including today), orders placed per Bangkok hour in the chosen dates, and the review average.
     */
    public static function shop(int $shopId, string $from, string $to): array
    {
        $report = self::summary($from, $to, $shopId);
        $today = Clock::bangkok();
        $periods = [];
        foreach (['today' => 0, 'last7' => 6, 'last30' => 29] as $name => $back) {
            $row = Database::row(
                "SELECT COALESCE(SUM(p.amount), 0.00) AS sales, COUNT(*) AS paid_orders FROM payments p JOIN orders o ON o.id = p.order_id
                 WHERE p.status = 'PAID' AND NOT EXISTS (SELECT 1 FROM orders x WHERE x.id = p.order_id AND x.status IN ('CANCELLED','REJECTED')) AND p.paid_at >= ? AND p.paid_at < ? AND o.shop_id = ?",
                [...Clock::utcRange($today->modify("-$back day")->format('Y-m-d'), $today->format('Y-m-d')), $shopId]
            );
            $periods[$name] = ['sales' => $row['sales'], 'paid_orders' => (int) $row['paid_orders']];
        }
        $hours = array_fill(0, 24, 0);
        foreach (Database::rows('SELECT HOUR(placed_at + INTERVAL 7 HOUR) AS h, COUNT(*) AS n FROM orders WHERE placed_at >= ? AND placed_at < ? AND shop_id = ? GROUP BY h', [...Clock::utcRange($report['from'], $report['to']), $shopId]) as $row) {
            $hours[(int) $row['h']] = (int) $row['n'];
        }
        return $report + [
            'periods' => $periods,
            'by_hour' => array_map(static fn (int $h): array => ['hour' => $h, 'orders' => $hours[$h]], array_keys($hours)),
        ] + Reviews::summaries([$shopId])[$shopId];
    }

    /** Sales per shop and per day for the admin overview. */
    public static function breakdown(string $from, string $to): array
    {
        $range = Clock::utcRange($from, $to);
        return [
            'by_shop' => Database::rows(
                "SELECT s.id, s.name, COUNT(*) AS orders, SUM(p.amount) AS sales FROM payments p JOIN orders o ON o.id = p.order_id JOIN shops s ON s.id = o.shop_id
                 WHERE p.status = 'PAID' AND NOT EXISTS (SELECT 1 FROM orders x WHERE x.id = p.order_id AND x.status IN ('CANCELLED','REJECTED')) AND p.paid_at >= ? AND p.paid_at < ? GROUP BY s.id, s.name ORDER BY sales DESC LIMIT 50",
                $range
            ),
            'by_day' => Database::rows(
                "SELECT DATE(p.paid_at + INTERVAL 7 HOUR) AS day, COUNT(*) AS orders, SUM(p.amount) AS sales FROM payments p
                 WHERE p.status = 'PAID' AND NOT EXISTS (SELECT 1 FROM orders x WHERE x.id = p.order_id AND x.status IN ('CANCELLED','REJECTED')) AND p.paid_at >= ? AND p.paid_at < ? GROUP BY day ORDER BY day",
                $range
            ),
        ];
    }
}
