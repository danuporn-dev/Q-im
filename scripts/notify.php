<?php
declare(strict_types=1);

// Usage: php scripts/notify.php [--watch]
// Sends due LINE notifications once, or every 5 seconds for 55 seconds with --watch (run it every minute).
// With PAYMENT_MODE=gateway it also cancels online orders not paid by their deadline (D44); that part runs even
// when LINE is not configured.

use App\Payments\Payments;
use App\Services\NotificationWorker;
use App\Services\OnlinePayments;
use App\Support\Env;
use App\Support\Log;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$line = Env::get('LINE_CHANNEL_ACCESS_TOKEN') !== '';
$online = Payments::online();
if (!$line) {
    fwrite(STDERR, "LINE_CHANNEL_ACCESS_TOKEN is not set; notifications stay pending.\n");
    if (!$online) {
        exit(1);
    }
}

$until = in_array('--watch', $argv, true) ? time() + 55 : 0;
try {
    do {
        $expired = $online ? OnlinePayments::expireDue() : 0;
        if ($expired > 0 || ($online && $until === 0)) {
            echo "unpaid online orders cancelled: $expired\n";
        }
        if ($line) {
            $s = NotificationWorker::run();
            if (array_sum($s) > 0 || $until === 0) {
                echo "sent {$s['sent']}, retry {$s['retry']}, failed {$s['failed']}\n";
            }
        }
        if (time() + 5 > $until) {
            break;
        }
        sleep(5);
    } while (true);
} catch (Throwable $e) {
    Log::error('notify', $e);
    fwrite(STDERR, "Worker failed; see storage/logs.\n");
    exit(1);
}
