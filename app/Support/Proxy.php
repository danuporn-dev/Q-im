<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The client's address, host and scheme. X-Forwarded-* counts only when the connection itself comes from an
 * address listed in TRUSTED_PROXY (comma separated, exact addresses); anyone else could write anything there.
 */
final class Proxy
{
    public static function isTrusted(): bool
    {
        return self::listed(self::peer());
    }

    public static function https(): bool
    {
        $scheme = self::scheme();
        if ($scheme !== null) {
            return $scheme === 'https';
        }
        return ($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '';
    }

    /** The scheme a trusted proxy reports, or null. */
    public static function scheme(): ?string
    {
        $proto = strtolower(self::last('X_FORWARDED_PROTO'));
        return $proto === 'http' || $proto === 'https' ? $proto : null;
    }

    /** Host name with its port unless that is the default one. */
    public static function host(): string
    {
        $host = self::last('X_FORWARDED_HOST');
        if (!preg_match('/^([A-Za-z0-9.-]{1,253}|\[[0-9A-Fa-f:.]{2,45}\])(:[0-9]{1,5})?$/D', $host)) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        }
        return (string) preg_replace('/:(80|443)$/', '', $host);
    }

    public static function clientIp(): string
    {
        $peer = self::peer();
        if (!self::listed($peer)) {
            return $peer;
        }
        // Each proxy appends the address it saw, so only the part right of the first untrusted address is reliable.
        foreach (array_reverse(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $peer;
            }
            if (!self::listed($ip)) {
                return $ip;
            }
        }
        return $peer;
    }

    private static function peer(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function listed(string $ip): bool
    {
        $packed = @inet_pton($ip);
        foreach (explode(',', Env::get('TRUSTED_PROXY')) as $proxy) {
            if ($packed !== false && @inet_pton(trim($proxy)) === $packed) {
                return true;
            }
        }
        return false;
    }

    /** The last value is the one our own proxy wrote. */
    private static function last(string $header): string
    {
        if (!self::isTrusted()) {
            return '';
        }
        $values = explode(',', (string) ($_SERVER['HTTP_' . $header] ?? ''));
        return trim(end($values));
    }
}
