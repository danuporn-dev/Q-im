<?php
declare(strict_types=1);

// Usage: php scripts/migrate.php
// Applies database/migrations/*.sql in filename order. The runner never drops anything itself.
// Each statement must end with ";" at the end of a line. MariaDB commits DDL implicitly,
// so migrations must be re-runnable (CREATE TABLE IF NOT EXISTS, ...).

use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $name = Database::name();
    $server = Database::connect(false);
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$name]);
    if ((int) $exists->fetchColumn() === 0) {
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Created database $name\n";
    }

    $pdo = Database::connect();
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['migrate_' . substr(sha1($name), 0, 16)]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Another migration is running.');
    }

    // Refuse a database that belongs to something else.
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables !== [] && !in_array('schema_migrations', $tables, true)) {
        throw new RuntimeException("Database $name is not empty and has no schema_migrations table; refusing to touch it.");
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(191) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

    $files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        throw new RuntimeException('No migration files found.');
    }

    $count = 0;
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        $sql = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $checksum = hash('sha256', $sql);
        if (isset($applied[$version])) {
            if ($applied[$version] !== $checksum) {
                throw new RuntimeException("$version changed after it was applied; add a new migration instead.");
            }
            continue;
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;[ \t]*(?:\n|$)/', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
        echo "Applied $version\n";
        $count++;
    }
    echo $count === 0 ? "Nothing to migrate.\n" : "Done: $count migration(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
