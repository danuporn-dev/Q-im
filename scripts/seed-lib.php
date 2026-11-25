<?php
declare(strict_types=1);

// Shared by seed.php and seed-demo.php.

use App\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Ends the script unless seeding is allowed. Fail closed: an unset APP_ENV is refused as well as production. */
function seedPassword(): string
{
    if (Env::get('APP_ENV') !== 'development') {
        fwrite(STDERR, "Refusing to seed: APP_ENV must be development.\n");
        exit(1);
    }
    $password = Env::get('SEED_DEMO_PASSWORD');
    if (strlen($password) < 12) {
        fwrite(STDERR, "Refusing to seed: set SEED_DEMO_PASSWORD (at least 12 characters) in .env.\n");
        exit(1);
    }
    return $password;
}

/** Returns the id of the row matching $match, inserting it with $extra when missing. */
function ensure(PDO $pdo, string $table, array $match, array $extra = [], string $idColumn = 'id'): int
{
    $where = implode(' AND ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($match)));
    $find = $pdo->prepare("SELECT `$idColumn` FROM `$table` WHERE $where LIMIT 1");
    $find->execute(array_values($match));
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $row = $match + $extra;
    $columns = implode(', ', array_map(static fn (string $c): string => "`$c`", array_keys($row)));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($marks)")->execute(array_values($row));
    return isset($row[$idColumn]) ? (int) $row[$idColumn] : (int) $pdo->lastInsertId();
}

function ensureStaff(PDO $pdo, string $table, string $login, string $name, string $password): int
{
    $find = $pdo->prepare("SELECT user_id FROM `$table` WHERE login_name = ?");
    $find->execute([$login]);
    $userId = $find->fetchColumn();
    if ($userId !== false) {
        return (int) $userId;
    }
    $pdo->prepare('INSERT INTO users (display_name) VALUES (?)')->execute([$name]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `$table` (user_id, login_name, password_hash) VALUES (?, ?, ?)")
        ->execute([$userId, $login, password_hash($password, PASSWORD_DEFAULT)]);
    return $userId;
}
