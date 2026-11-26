<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;

/**
 * The customer's notifications on the website. They are the outbox entries themselves, so they exist
 * whether or not LINE accepted the message. Reading is recorded apart from the outbox.
 */
final class Inbox
{
    public static function unread(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads r ON r.notification_id = n.id WHERE n.user_id = ? AND r.notification_id IS NULL', [$userId]);
    }

    /** Newest first. @return array{0: list<array>, 1: int} */
    public static function ofCustomer(int $userId, int $limit, int $offset): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
        $rows = Database::rows(NotificationWorker::SELECT . " WHERE n.user_id = ? ORDER BY n.id DESC LIMIT $limit OFFSET $offset", [$userId]);
        $read = [];
        if ($rows !== []) {
            $in = implode(',', array_map('intval', array_column($rows, 'id')));
            $read = array_flip(array_column(Database::rows("SELECT notification_id FROM notification_reads WHERE notification_id IN ($in)"), 'notification_id'));
        }
        return [array_map(static fn (array $n): array => [
            'id' => (int) $n['id'],
            'order_id' => (int) $n['order_id'],
            'type' => $n['event_type'],
            'text' => NotificationWorker::message($n),
            'read' => isset($read[$n['id']]),
            'created_at' => Clock::iso($n['created_at']),
        ], $rows), $total];
    }

    public static function markRead(int $userId, int $id): void
    {
        if (!Database::value('SELECT 1 FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId])) {
            throw HttpError::notFound('ไม่พบการแจ้งเตือน');
        }
        Database::run('INSERT IGNORE INTO notification_reads (notification_id, read_at) VALUES (?, ?)', [$id, Clock::now()]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::run('INSERT IGNORE INTO notification_reads (notification_id, read_at) SELECT id, ? FROM notifications WHERE user_id = ?', [Clock::now(), $userId]);
    }

    /** What the shop's bell needs: how many orders wait for an answer, and the newest of them. */
    public static function shopBell(int $shopId): array
    {
        $row = Database::row("SELECT COUNT(*) AS pending, COALESCE(MAX(id), 0) AS latest FROM orders WHERE shop_id = ? AND status = 'PENDING'", [$shopId]);
        return ['pending' => (int) $row['pending'], 'latest_id' => (int) $row['latest']];
    }
}
