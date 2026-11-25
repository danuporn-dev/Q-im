<?php
declare(strict_types=1);

namespace App\Support;

use Throwable;

/** Server-side log. Never pass tokens, passwords or request bodies. */
final class Log
{
    public static function error(string $context, Throwable $e): void
    {
        // Driver messages can echo user input, so keep only the code for database errors.
        $message = $e instanceof \PDOException ? 'SQLSTATE ' . $e->getCode() . ' / ' . ($e->errorInfo[1] ?? '') : $e->getMessage();
        self::write($context, get_class($e) . ': ' . $message . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    public static function write(string $context, string $message): void
    {
        $line = gmdate('c') . " [$context] " . preg_replace('/[\r\n]+/', ' ', $message) . "\n";
        @file_put_contents(dirname(__DIR__, 2) . '/storage/logs/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
