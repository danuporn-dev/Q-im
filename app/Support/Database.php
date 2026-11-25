<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Database
{
    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys', 'phpmyadmin'];
    private static ?PDO $shared = null;

    public static function pdo(): PDO
    {
        return self::$shared ??= self::connect();
    }

    /**
     * Runs $work in one transaction; deadlocks and lock timeouts are retried from the start.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public static function tx(callable $work): mixed
    {
        $pdo = self::pdo();
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (!in_array($code, [1205, 1213], true) || $attempt >= 5) {
                    throw $e;
                }
                usleep(random_int(5, 40) * 1000 * $attempt);
            }
        }
    }

    public static function isDuplicate(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** @return array<string, mixed>|null */
    public static function row(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return int affected rows */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** $table and the keys of $row must be trusted identifiers. */
    public static function insert(string $table, array $row): int
    {
        $columns = '`' . implode('`, `', array_keys($row)) . '`';
        $marks = implode(', ', array_fill(0, count($row), '?'));
        self::run("INSERT INTO `$table` ($columns) VALUES ($marks)", array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** $table and the keys of $changes must be trusted identifiers. */
    public static function update(string $table, int $id, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($changes)));
        self::run("UPDATE `$table` SET $set WHERE id = ?", [...array_values($changes), $id]);
    }


    public static function name(): string
    {
        $name = self::config()['name'];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) || in_array(strtolower($name), self::SYSTEM_SCHEMAS, true)) {
            throw new RuntimeException('DB_NAME is not an allowed database name.');
        }
        return $name;
    }

    /** Connection to the application database, or to the server only when $withDatabase is false. */
    public static function connect(bool $withDatabase = true): PDO
    {
        $c = self::config();
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
        if ($withDatabase) {
            $dsn .= ';dbname=' . self::name();
        }
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // UTC timestamps, and strict mode because the XAMPP default sql_mode is not strict.
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec(
            "SET SESSION time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,"
            . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
        // Reads made after taking a row lock must see the latest committed data.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $pdo;
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    private static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/database.php';
    }
}
