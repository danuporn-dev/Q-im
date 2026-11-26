<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Input;

/**
 * One review per completed order, written by its owner. Customers and shops see reviews without any link to a
 * person or an order, and only the Bangkok date, so a review cannot be matched to a pick-up time.
 */
final class Reviews
{
    public static function create(int $userId, int $orderId, array $in): void
    {
        $rating = Input::int($in, 'rating', 'คะแนน', 1, 5);
        $comment = Input::optionalText($in, 'comment', 'ความคิดเห็น', 500);
        try {
            Database::tx(static function () use ($userId, $orderId, $rating, $comment): void {
                $order = Database::row('SELECT user_id, shop_id, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
                if ($order === null || (int) $order['user_id'] !== $userId) {
                    throw HttpError::notFound('ไม่พบออเดอร์');
                }
                if ($order['status'] !== 'COMPLETED') {
                    throw HttpError::conflict('NOT_COMPLETED', 'รีวิวได้หลังรับอาหารแล้วเท่านั้น');
                }
                Database::insert('reviews', ['order_id' => $orderId, 'shop_id' => $order['shop_id'], 'user_id' => $userId, 'rating' => $rating, 'comment' => $comment, 'created_at' => Clock::now()]);
            });
        } catch (\PDOException $e) {
            if (!Database::isDuplicate($e)) {
                throw $e;
            }
            throw HttpError::conflict('ALREADY_REVIEWED', 'ออเดอร์นี้รีวิวแล้ว');
        }
    }

    /** The owner's own review of an order, or null. */
    public static function ofOrder(int $orderId): ?array
    {
        $row = Database::row('SELECT rating, comment, hidden_at, created_at FROM reviews WHERE order_id = ?', [$orderId]);
        return $row === null ? null : ['rating' => (int) $row['rating'], 'comment' => $row['comment'], 'hidden' => $row['hidden_at'] !== null, 'date' => self::date($row['created_at'])];
    }

    /**
     * @param list<int> $shopIds
     * @return array<int, array{rating: ?float, review_count: int}>
     */
    public static function summaries(array $shopIds): array
    {
        $result = array_fill_keys($shopIds, ['rating' => null, 'review_count' => 0]);
        if ($shopIds === []) {
            return $result;
        }
        $in = implode(',', array_map('intval', $shopIds));
        foreach (Database::rows("SELECT shop_id, AVG(rating) AS average, COUNT(*) AS n FROM reviews WHERE shop_id IN ($in) AND hidden_at IS NULL GROUP BY shop_id") as $r) {
            $result[(int) $r['shop_id']] = ['rating' => round((float) $r['average'], 1), 'review_count' => (int) $r['n']];
        }
        return $result;
    }

    /** Visible reviews of one shop, newest first. @return array{0: list<array>, 1: int} */
    public static function ofShop(int $shopId, int $limit, int $offset): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM reviews WHERE shop_id = ? AND hidden_at IS NULL', [$shopId]);
        $rows = Database::rows("SELECT rating, comment, created_at FROM reviews WHERE shop_id = ? AND hidden_at IS NULL ORDER BY id DESC LIMIT $limit OFFSET $offset", [$shopId]);
        return [array_map(static fn (array $r): array => ['rating' => (int) $r['rating'], 'comment' => $r['comment'], 'date' => self::date($r['created_at'])], $rows), $total];
    }

    /** For the admin, hidden ones included; the author is still not named. @return array{0: list<array>, 1: int} */
    public static function all(string $like, int $shopId, int $limit, int $offset): array
    {
        $where = "(COALESCE(r.comment, '') LIKE ? OR s.name LIKE ? OR o.public_order_no LIKE ?)" . ($shopId > 0 ? ' AND r.shop_id = ' . $shopId : '');
        $from = 'FROM reviews r JOIN shops s ON s.id = r.shop_id JOIN orders o ON o.id = r.order_id';
        $params = [$like, $like, $like];
        $total = (int) Database::value("SELECT COUNT(*) $from WHERE $where", $params);
        $rows = Database::rows("SELECT r.id, r.rating, r.comment, r.hidden_at, r.created_at, s.name AS shop_name, o.public_order_no AS order_no $from WHERE $where ORDER BY r.id DESC LIMIT $limit OFFSET $offset", $params);
        return [array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'rating' => (int) $r['rating'], 'comment' => $r['comment'], 'shop_name' => $r['shop_name'], 'order_no' => $r['order_no'], 'hidden' => $r['hidden_at'] !== null, 'created_at' => Clock::iso($r['created_at'])], $rows), $total];
    }

    /** Hiding needs a reason; it is the only change anyone can make to a review. */
    public static function setHidden(int $adminId, int $reviewId, array $in): void
    {
        $hidden = Input::bool($in, 'hidden', 'การซ่อน') === 1;
        $reason = Input::text($in, 'reason', 'เหตุผล', 300, 3);
        Database::tx(static function () use ($adminId, $reviewId, $hidden, $reason): void {
            $row = Database::row('SELECT hidden_at, shop_id FROM reviews WHERE id = ? FOR UPDATE', [$reviewId]) ?? throw HttpError::notFound('ไม่พบรีวิว');
            if (($row['hidden_at'] !== null) === $hidden) {
                return;
            }
            Database::run('UPDATE reviews SET hidden_at = ?, hidden_by = ? WHERE id = ?', [$hidden ? Clock::now() : null, $hidden ? $adminId : null, $reviewId]);
            Audit::log($adminId, $hidden ? 'review.hide' : 'review.unhide', 'review', $reviewId, ['shop_id' => (int) $row['shop_id'], 'reason' => $reason]);
        });
    }

    private static function date(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('Y-m-d');
    }
}
