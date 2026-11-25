<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Database
{
    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys', 'phpmyadmin'];

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
        return $pdo;
    }

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    private static function config(): array
    {
        return require dirname(__DIR__, 2) . '/config/database.php';
    }
}
