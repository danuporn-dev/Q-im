<?php
declare(strict_types=1);

// Usage: php scripts/reset-dev.php --confirm
// DESTROYS qim_dev, then migrates and seeds it again. Development only; no other database can be reset.
// Login sessions are removed too, because user ids start again from 1.

use App\Support\Database;
use App\Support\Env;

const RESET_DB = 'qim_dev';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$refuse = match (true) {
    Env::get('APP_ENV') !== 'development' => 'APP_ENV must be development',
    Env::get('DB_NAME', RESET_DB) !== RESET_DB => 'DB_NAME must be ' . RESET_DB,
    !in_array(Env::get('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true) => 'DB_HOST must be this computer',
    strlen(Env::get('SEED_DEMO_PASSWORD')) < 12 => 'SEED_DEMO_PASSWORD must be set, or the seed would fail after the drop',
    array_slice($argv, 1) !== ['--confirm'] => 'this deletes every row of ' . RESET_DB . '; add --confirm',
    default => null,
};
if ($refuse !== null) {
    fwrite(STDERR, "Refusing: $refuse.\n");
    exit(1);
}

try {
    $server = Database::connect(false);
    $server->exec('DROP DATABASE IF EXISTS ' . RESET_DB);
    echo 'Dropped ' . RESET_DB . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$sessions = glob(dirname(__DIR__) . '/storage/sessions/sess_*') ?: [];
array_map('unlink', $sessions);
echo 'Removed ' . count($sessions) . " session(s)\n";

foreach (['migrate.php', 'seed.php'] as $script) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script), $code);
    if ($code !== 0) {
        fwrite(STDERR, "$script failed; run it again after fixing the cause.\n");
        exit(1);
    }
}
