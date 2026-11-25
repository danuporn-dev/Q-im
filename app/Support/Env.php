<?php
declare(strict_types=1);

namespace App\Support;

/** Minimal .env reader. Real environment variables take precedence over the file. */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = [];
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            }
            self::$values[$key] = $value;
        }
    }

    public static function isDevelopment(): bool
    {
        return self::get('APP_ENV') === 'development';
    }

    public static function isProduction(): bool
    {
        return !self::isDevelopment();
    }

    public static function get(string $key, string $default = ''): string
    {
        $real = getenv($key);
        $value = $real !== false ? $real : (self::$values[$key] ?? '');
        return $value === '' ? $default : $value;
    }
}
