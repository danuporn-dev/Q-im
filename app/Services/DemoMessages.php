<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;

/** What LINE would say: the customer's own outbox as text. Read only; sends nothing. */
final class DemoMessages
{
    /** @return list<array{id: int, order_id: int, text: string, created_at: string}> oldest first */
    public static function ofCustomer(int $userId): array
    {
        $rows = Database::rows(NotificationWorker::SELECT . ' WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 30', [$userId]);
        return array_map(static fn (array $n): array => [
            'id' => (int) $n['id'],
            'order_id' => (int) $n['order_id'],
            'text' => NotificationWorker::message($n),
            'created_at' => Clock::iso($n['created_at']),
        ], array_reverse($rows));
    }
}
