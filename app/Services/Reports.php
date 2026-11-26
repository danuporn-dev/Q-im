<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;

/** Sales are payments that were confirmed (PAID) in the range; dates are Asia/Bangkok. */
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
        $sales = Database::row("SELECT COALESCE(SUM(p.amount), 0.00) AS sales, COUNT(*) AS paid FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.status = 'PAID' AND p.paid_at >= ? AND p.paid_at < ?$shop", $range);
        $counts = ['COMPLETED' => 0, 'REJECTED' => 0, 'CANCELLED' => 0, 'PENDING' => 0];
        foreach (Database::rows("SELECT l.to_status, COUNT(*) AS n FROM order_status_logs l JOIN orders o ON o.id = l.order_id WHERE l.created_at >= ? AND l.created_at < ?$shop GROUP BY l.to_status", $range) as $row) {
            $counts[$row['to_status']] = (int) $row['n'];
        }
        $seconds = Database::value("SELECT AVG(TIMESTAMPDIFF(SECOND, o.cooking_started_at, o.ready_at)) FROM orders o WHERE o.cooking_started_at IS NOT NULL AND o.ready_at >= ? AND o.ready_at < ?$shop", $range);
        return [
            'from' => $from, 'to' => $to, 'sales' => $sales['sales'], 'paid_orders' => (int) $sales['paid'],
            'placed' => $counts['PENDING'], 'completed' => $counts['COMPLETED'], 'rejected' => $counts['REJECTED'], 'cancelled' => $counts['CANCELLED'],
            'avg_prep_minutes' => $seconds === null ? null : round((float) $seconds / 60, 1),
            'top_menus' => Database::rows(
                "SELECT MAX(i.name_snapshot) AS name, SUM(i.quantity) AS quantity FROM order_items i JOIN orders o ON o.id = i.order_id JOIN payments p ON p.order_id = o.id
                 WHERE p.status = 'PAID' AND p.paid_at >= ? AND p.paid_at < ?$shop GROUP BY i.menu_id ORDER BY quantity DESC, name LIMIT 10",
                $range
            ),
        ];
    }

    /** Sales per shop and per day for the admin overview. */
    public static function breakdown(string $from, string $to): array
    {
        $range = Clock::utcRange($from, $to);
        return [
            'by_shop' => Database::rows(
                "SELECT s.id, s.name, COUNT(*) AS orders, SUM(p.amount) AS sales FROM payments p JOIN orders o ON o.id = p.order_id JOIN shops s ON s.id = o.shop_id
                 WHERE p.status = 'PAID' AND p.paid_at >= ? AND p.paid_at < ? GROUP BY s.id, s.name ORDER BY sales DESC LIMIT 50",
                $range
            ),
            'by_day' => Database::rows(
                "SELECT DATE(p.paid_at + INTERVAL 7 HOUR) AS day, COUNT(*) AS orders, SUM(p.amount) AS sales FROM payments p
                 WHERE p.status = 'PAID' AND p.paid_at >= ? AND p.paid_at < ? GROUP BY day ORDER BY day",
                $range
            ),
        ];
    }
}
