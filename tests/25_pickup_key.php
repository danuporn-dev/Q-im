<?php
declare(strict_types=1);

// T22: the pickup key when PICKUP_TOKEN_KEY is not set (D39). The key file is created on this machine; when it
// cannot be, orders still become ready, and the customer, the admin and the log all say so.

use App\Support\Database;

$a = '/api/v1';
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qim_key_' . bin2hex(random_bytes(4));
mkdir($dir);
$fresh = "$dir/new/folder/pickup.key";
file_put_contents("$dir/blocker", 'a file, not a folder');
$blocked = "$dir/blocker/keys/pickup.key";
[, $adminLogin] = Fixture::staff('admin', 'key_' . Fixture::run());
$log = dirname(__DIR__) . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
$s = Fixture::shop('key', ['capacity' => 50]);
$flow = static function (string $base) use ($a, $s): array {
    $m = Fixture::login($base, 'merchant', $s['login']);
    [$c, $o] = Fixture::order($base, 'key' . bin2hex(random_bytes(2)), $s);
    $m->post("$a/merchant/orders/$o/accept");
    $ready = $m->post("$a/merchant/orders/$o/ready");
    return [$m, $c, $o, $ready];
};

T::group('T22 no PICKUP_TOKEN_KEY: the key file is created');
$base1 = Servers::start(8322, ['PICKUP_KEY_FILE' => $fresh, 'RATE_LIMIT_MULTIPLIER' => '1000']);
[$m, $c, $o, $ready] = $flow($base1);
T::status(200, $ready, 'ready');
T::ok(is_file($fresh) && preg_match('/^[0-9a-f]{64}$/D', (string) file_get_contents($fresh)) === 1, 'folders and a 64-hex key were created');
$view = $c->get("$a/orders/$o")['body']['data']['pickup'];
T::ok(str_starts_with((string) $view['qr'], 'QIM1.') && $view['unavailable'] === false, 'the customer gets a QR');
T::status(200, $m->post("$a/merchant/orders/$o/complete", ['pickup_qr' => $view['qr'], 'payment_received' => true]), 'and it works');
$admin = Fixture::login($base1, 'admin', $adminLogin);
T::eq('file', $admin->get("$a/admin/settings")['body']['data']['pickup_key'], 'admin: key from the file');

T::group('T22 the key file cannot be created: not silent');
$before = is_file($log) ? filesize($log) : 0;
$base2 = Servers::start(8323, ['PICKUP_KEY_FILE' => $blocked, 'RATE_LIMIT_MULTIPLIER' => '1000']);
[$m2, $c2, $o2, $ready2] = $flow($base2);
T::status(200, $ready2, 'the order still becomes ready');
T::eq([0, 'READY'], [(int) Database::value('SELECT COUNT(*) FROM pickup_tokens WHERE order_id = ?', [$o2]), Database::value('SELECT status FROM orders WHERE id = ?', [$o2])], 'no token without a key');
$r = $c2->get("$a/orders/$o2");
T::status(200, $r, 'the order page still loads');
T::eq(['qr' => null, 'expires_at' => null, 'expired' => false, 'unavailable' => true], $r['body']['data']['pickup'], 'the customer is told that there is no QR');
T::ok(!str_contains($r['raw'], 'blocker') && !str_contains($r['raw'], 'storage'), 'without paths');
$admin2 = Fixture::login($base2, 'admin', $adminLogin);
T::eq('unavailable', $admin2->get("$a/admin/settings")['body']['data']['pickup_key'], 'admin: unavailable');
$written = (string) file_get_contents($log, false, null, $before);
T::ok(str_contains($written, '[pickup] Pickup QR codes are off: cannot create the key folder') && str_contains($written, 'PICKUP_TOKEN_KEY'), 'the log says what is wrong and how to fix it');
T::status(200, $m2->post("$a/merchant/orders/$o2/complete", ['pickup_qr' => $c2->get("$a/orders/$o2")['body']['data']['order_no'], 'payment_received' => true]), 'hand-over with the typed order number');
$base3 = Servers::start(8324, ['PICKUP_KEY_FILE' => $blocked, 'PICKUP_TOKEN_KEY' => str_repeat('z', 32), 'RATE_LIMIT_MULTIPLIER' => '1000']);
T::eq('env', Fixture::login($base3, 'admin', $adminLogin)->get("$a/admin/settings")['body']['data']['pickup_key'], 'PICKUP_TOKEN_KEY fixes it');

T::group('Production ignores the test hook');
$prod = Servers::start(8325, ['APP_ENV' => 'production', 'PICKUP_KEY_FILE' => $blocked]);
T::eq('file', Fixture::login($prod, 'admin', $adminLogin)->get("$a/admin/settings")['body']['data']['pickup_key'], 'the real storage/keys is used');
T::ok(is_file(dirname(__DIR__) . '/storage/keys/pickup.key') && strlen((string) file_get_contents(dirname(__DIR__) . '/storage/keys/pickup.key')) === 64, 'storage/keys/pickup.key exists on this machine');

unlink($fresh);
rmdir(dirname($fresh));
rmdir(dirname($fresh, 2));
unlink("$dir/blocker");
rmdir($dir);
