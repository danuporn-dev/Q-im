<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;

/** Outbox writer. Call inside the transaction that changes the order. */
final class Notifications
{
    public static function enqueue(int $orderId, int $userId, string $event): void
    {
        Database::run(
            'INSERT IGNORE INTO notifications (order_id, user_id, event_type, dedupe_key, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $userId, $event, "order:$orderId:$event", Clock::now(), Clock::now()]
        );
    }
}
