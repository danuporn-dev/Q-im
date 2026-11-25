<?php
declare(strict_types=1);

// Usage: php tests/clean_install.php [--replace] [--keep]
// Creates qim_fresh_test, runs every migration twice, seeds, runs tests/run.php and scripts/e2e.php, then
// drops qim_fresh_test. The name is fixed on purpose: no other database is ever created or dropped here.
// --replace: drop a qim_fresh_test left by an interrupted run first.  --keep: do not drop at the end.

const FRESH_DB = 'qim_fresh_test';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Set before bootstrap, so this process and every child see only the fresh database.
putenv('DB_NAME=' . FRESH_DB);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Support\Database;
use App\Support\Env;

if (!Env::isDevelopment() || Database::name() !== FRESH_DB) {
    fwrite(STDERR, "Refusing: needs APP_ENV=development and database " . FRESH_DB . ".\n");
    exit(1);
}

$server = Database::connect(false);
$exists = static fn (): bool => (bool) $server->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . FRESH_DB . "'")->fetchColumn();
// Proof that the development database is not touched.
$fingerprint = static function () use ($server): string {
    $tables = $server->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'qim_dev' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $sums = [];
    foreach ($tables as $table) {
        $sums[$table] = $server->query("CHECKSUM TABLE `qim_dev`.`$table`")->fetch()['Checksum'];
    }
    return hash('sha256', json_encode($sums));
};
$run = static function (string $script) use (&$failed): void {
    echo "\n--- $script\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/' . $script) . ' 2>&1', $code);
    echo "--- exit $code: $script\n";
    $failed = $failed || $code !== 0;
};

if ($exists()) {
    if (!in_array('--replace', $argv, true)) {
        fwrite(STDERR, FRESH_DB . " already exists; use --replace.\n");
        exit(1);
    }
    $server->exec('DROP DATABASE ' . FRESH_DB);
}

$failed = false;
$before = $fingerprint();
try {
    $run('scripts/migrate.php');
    if (!$failed) {
        // Forget the history: every migration must also survive being run again on existing tables.
        Database::run('DELETE FROM schema_migrations');
    }
    foreach (['scripts/migrate.php', 'scripts/migrate.php', 'scripts/seed.php', 'scripts/seed.php', 'tests/run.php', 'scripts/e2e.php'] as $script) {
        if (!$failed) {
            $run($script);
        }
    }
    $versions = Database::rows('SELECT version FROM schema_migrations ORDER BY version');
    echo "\nMigrations on the empty database: " . implode(', ', array_column($versions, 'version')) . "\n";
} finally {
    if (!in_array('--keep', $argv, true) && $exists()) {
        $server->exec('DROP DATABASE ' . FRESH_DB);
    }
}
$untouched = $before === $fingerprint();
echo 'qim_dev unchanged: ' . ($untouched ? 'yes' : 'NO') . "\n" . FRESH_DB . ' exists: ' . ($exists() ? 'yes' : 'no') . "\n";
echo $failed || !$untouched ? "CLEAN INSTALL FAILED\n" : "CLEAN INSTALL OK\n";
exit($failed || !$untouched ? 1 : 0);
