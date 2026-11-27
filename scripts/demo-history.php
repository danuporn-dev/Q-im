<?php
declare(strict_types=1);

// Usage: php scripts/demo-history.php   (after demo-reset.php; development only)
// Writes orders of the 14 days before today into the ordinary tables, so shop statistics have something to
// show. Today stays empty, so a live demo starts at Q-001. No reviews and no notifications are created.
// Running it again adds nothing.

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use App\Support\Money;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

const DAYS = 14;
$refuse = match (true) {
    Env::get('APP_ENV') !== 'development' => 'APP_ENV must be development',
    !in_array(Database::name(), ['qim_dev', 'qim_test'], true) => 'only qim_dev',
    !in_array(Env::get('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true) => 'DB_HOST must be this computer',
    default => null,
};
if ($refuse !== null) {
    fwrite(STDERR, "Refusing: $refuse.\n");
    exit(1);
}

try {
    if (Database::value("SELECT 1 FROM orders WHERE idempotency_key LIKE 'demohist-%' LIMIT 1")) {
        echo "Demo history already exists; nothing added.\n";
        exit(0);
    }
    $created = Database::tx(static function (): int {
        mt_srand(20261125); // the same figures on every rehearsal
        $role = Database::value("SELECT id FROM roles WHERE name = 'customer'") ?: throw new RuntimeException('run scripts/demo-reset.php first');
        $customers = [];
        for ($i = 1; $i <= 6; $i++) {
            Database::run('INSERT IGNORE INTO users (line_user_id, display_name, created_at) VALUES (?, ?, ?)', ["DEMO_HISTORY_$i", "ลูกค้าตัวอย่าง $i", Clock::now()]);
            $customers[] = $id = (int) Database::value('SELECT id FROM users WHERE line_user_id = ?', ["DEMO_HISTORY_$i"]);
            Database::run('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $role]);
        }
        $shops = Database::rows('SELECT s.id, (SELECT MIN(user_id) FROM shop_members m WHERE m.shop_id = s.id) AS staff FROM shops s JOIN food_courts f ON f.id = s.food_court_id WHERE s.active = 1 AND f.active = 1 ORDER BY s.id');
        $bangkok = new DateTimeZone('Asia/Bangkok');
        $today = Clock::bangkok()->setTime(0, 0);
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        // Weighted opening hours: busiest at lunch.
        $hours = [7, 8, 8, 9, 10, 11, 11, 11, 12, 12, 12, 12, 13, 13, 14, 15, 16];
        $total = 0;
        foreach ($shops as $shop) {
            $menus = Database::rows('SELECT id, name, price, prep_minutes FROM menus WHERE shop_id = ? AND active = 1 ORDER BY id', [$shop['id']]);
            if ($menus === [] || $shop['staff'] === null) {
                continue;
            }
            for ($back = DAYS; $back >= 1; $back--) {
                $day = $today->modify("-$back day");
                $date = $day->format('Y-m-d');
                $times = [];
                for ($n = mt_rand(6, 14); $n > 0; $n--) {
                    $times[] = $day->setTime($hours[mt_rand(0, count($hours) - 1)], mt_rand(0, 59));
                }
                sort($times);
                $queue = 0;
                foreach ($times as $n => $at) {
                    $utc = static fn (int $minutes): string => $at->modify("+$minutes minute")->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $roll = mt_rand(1, 100);
                    $status = $roll <= 90 ? 'COMPLETED' : ($roll <= 95 ? 'REJECTED' : 'CANCELLED');
                    $customer = $customers[mt_rand(0, count($customers) - 1)];
                    // Dishes near the top of the menu sell more.
                    $menu = $menus[min(mt_rand(0, count($menus) - 1), mt_rand(0, count($menus) - 1))];
                    $quantity = mt_rand(1, 100) <= 80 ? 1 : 2;
                    $options = [];
                    foreach (Database::rows('SELECT id, min_select FROM menu_option_groups WHERE menu_id = ? AND active = 1 ORDER BY id', [$menu['id']]) as $group) {
                        $choices = Database::rows('SELECT id, name, price_delta FROM menu_options WHERE group_id = ? AND active = 1 ORDER BY id', [$group['id']]);
                        if ($group['min_select'] > 0 && $choices !== []) {
                            $options[] = $choices[mt_rand(0, count($choices) - 1)];
                        }
                    }
                    $unit = Money::toSatang($menu['price']);
                    foreach ($options as $o) {
                        $unit += Money::toSatang($o['price_delta']);
                    }
                    $amount = Money::fromSatang($unit * $quantity);
                    $accept = 1;
                    $ready = $accept + max(2, (int) $menu['prep_minutes'] + mt_rand(-1, 3));
                    $done = $ready + mt_rand(1, 8);
                    $number = 'QI' . $at->format('ymd') . '-';
                    for ($i = 0; $i < 6; $i++) {
                        $number .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
                    }
                    $cooked = $status === 'COMPLETED';
                    $order = Database::insert('orders', [
                        'public_order_no' => $number, 'user_id' => $customer, 'shop_id' => $shop['id'], 'status' => $status, 'total' => $amount,
                        'idempotency_key' => "demohist-{$shop['id']}-$date-$n", 'created_at' => $utc(0), 'placed_at' => $utc(0),
                        'accepted_at' => $cooked ? $utc($accept) : null, 'preparing_at' => $cooked ? $utc($accept) : null, 'cooking_started_at' => $cooked ? $utc($accept) : null,
                        'ready_at' => $cooked ? $utc($ready) : null, 'completed_at' => $cooked ? $utc($done) : null,
                    ]);
                    $item = Database::insert('order_items', ['order_id' => $order, 'menu_id' => $menu['id'], 'name_snapshot' => $menu['name'], 'unit_price' => $menu['price'], 'quantity' => $quantity]);
                    foreach ($options as $o) {
                        Database::insert('order_item_options', ['order_item_id' => $item, 'option_id' => $o['id'], 'name_snapshot' => $o['name'], 'price_delta_snapshot' => $o['price_delta']]);
                    }
                    Database::insert('payments', ['order_id' => $order, 'amount' => $amount]
                        + ($cooked ? ['status' => 'PAID', 'paid_at' => $utc($done), 'confirmed_by' => $shop['staff']] : []));
                    $log = static fn (?string $from, string $to, int $minute, int $actor, ?string $reason = null) => Database::insert('order_status_logs', ['order_id' => $order, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor, 'reason' => $reason, 'created_at' => $utc($minute)]);
                    $log(null, 'PENDING', 0, $customer);
                    if ($cooked) {
                        Database::insert('queues', ['order_id' => $order, 'shop_id' => $shop['id'], 'business_date' => $date, 'queue_number' => ++$queue, 'assigned_at' => $utc($accept)]);
                        foreach ([['PENDING', 'ACCEPTED', $accept], ['ACCEPTED', 'PREPARING', $accept], ['PREPARING', 'READY', $ready], ['READY', 'COMPLETED', $done]] as [$from, $to, $minute]) {
                            $log($from, $to, $minute, (int) $shop['staff']);
                        }
                    } else {
                        $log('PENDING', $status, 2, (int) $shop['staff'], $status === 'REJECTED' ? 'วัตถุดิบหมด' : 'ลูกค้าขอยกเลิก');
                    }
                    $total++;
                }
                if ($queue > 0) {
                    Database::run('INSERT IGNORE INTO queue_counters (shop_id, business_date, last_number) VALUES (?, ?, ?)', [$shop['id'], $date, $queue]);
                }
            }
        }
        return $total;
    });
    echo "Demo history: $created orders over the " . DAYS . " days before today.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Demo history failed: ' . $e->getMessage() . "\n");
    exit(1);
}
