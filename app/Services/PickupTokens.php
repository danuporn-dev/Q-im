<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Env;
use RuntimeException;

/**
 * The single-use token inside the customer's pickup QR (D38). It is HMAC(server key, order, random nonce),
 * 160 bits. The database keeps the nonce and a hash of the token, so a copy of the database alone cannot
 * produce a working QR. Checking a scanned token needs the hash only.
 */
final class PickupTokens
{
    public const PREFIX = 'QIM1.';
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const HOURS = 12;

    /** Creates the token of an order that became ready at $readyAt (UTC). Does nothing when it exists. */
    public static function issue(int $orderId, string $readyAt): void
    {
        $nonce = bin2hex(random_bytes(16));
        $expires = gmdate('Y-m-d H:i:s', strtotime($readyAt . ' UTC') + self::hours() * 3600);
        Database::run(
            'INSERT IGNORE INTO pickup_tokens (order_id, nonce, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $nonce, self::hash(self::token($orderId, $nonce)), $expires, Clock::now()]
        );
    }

    /** For the owner of a READY order. @return array{qr: ?string, expires_at: string, expired: bool} */
    public static function forCustomer(int $orderId, string $readyAt): array
    {
        $row = self::row($orderId);
        if ($row === null) {
            self::issue($orderId, $readyAt); // ready since before this table existed
            $row = self::row($orderId);
        }
        $token = self::token($orderId, $row['nonce']);
        if (!hash_equals($row['token_hash'], self::hash($token))) {
            // The server key changed: replace the token, keeping its expiry.
            $nonce = bin2hex(random_bytes(16));
            $token = self::token($orderId, $nonce);
            if (Database::run('UPDATE pickup_tokens SET nonce = ?, token_hash = ? WHERE order_id = ? AND token_hash = ?', [$nonce, self::hash($token), $orderId, $row['token_hash']]) === 0) {
                // Another request replaced it first: show that one, not a token that was never stored.
                $row = self::row($orderId);
                $token = self::token($orderId, $row['nonce']);
            }
        }
        $expired = $row['expires_at'] <= Clock::now();
        return ['qr' => $expired || $row['used_at'] !== null ? null : $token, 'expires_at' => Clock::iso($row['expires_at']), 'expired' => $expired];
    }

    public static function looksLikeToken(string $scanned): bool
    {
        return str_starts_with(strtoupper($scanned), self::PREFIX);
    }

    /** Call under the order row lock. @return 'OK'|'WRONG'|'EXPIRED'|'USED' */
    public static function check(int $orderId, string $scanned): string
    {
        $row = Database::row('SELECT token_hash, expires_at, used_at FROM pickup_tokens WHERE order_id = ? FOR UPDATE', [$orderId]);
        if ($row === null || !hash_equals($row['token_hash'], self::hash(strtoupper($scanned)))) {
            return 'WRONG';
        }
        return $row['used_at'] !== null ? 'USED' : ($row['expires_at'] <= Clock::now() ? 'EXPIRED' : 'OK');
    }

    /** The order was handed over, by whichever way: its token is spent. */
    public static function spend(int $orderId): void
    {
        Database::run('UPDATE pickup_tokens SET used_at = ? WHERE order_id = ? AND used_at IS NULL', [Clock::now(), $orderId]);
    }

    public static function hours(): int
    {
        $value = filter_var(Env::get('PICKUP_TOKEN_HOURS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 72]]);
        return $value === false ? self::HOURS : $value;
    }

    private static function row(int $orderId): ?array
    {
        return Database::row('SELECT nonce, token_hash, expires_at, used_at FROM pickup_tokens WHERE order_id = ?', [$orderId]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function token(int $orderId, string $nonce): string
    {
        $bytes = substr(hash_hmac('sha256', "pickup|$orderId|$nonce", self::key(), true), 0, 20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        return self::PREFIX . implode('', array_map(static fn (string $five): string => self::ALPHABET[bindec($five)], str_split($bits, 5)));
    }

    /** PICKUP_TOKEN_KEY (32 characters or more), else a random key kept in private storage. */
    private static function key(): string
    {
        $key = trim(Env::get('PICKUP_TOKEN_KEY'));
        if (strlen($key) >= 32) {
            return $key;
        }
        $path = dirname(__DIR__, 2) . '/storage/keys/pickup.key';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        $file = fopen($path, 'c+b');
        if ($file === false || !flock($file, LOCK_EX)) {
            throw new RuntimeException('Cannot open the pickup key; storage/keys must be writable.');
        }
        try {
            $key = trim((string) stream_get_contents($file));
            if (strlen($key) < 64) {
                $key = bin2hex(random_bytes(32));
                rewind($file);
                ftruncate($file, 0);
                if (fwrite($file, $key) !== 64 || !fflush($file)) {
                    throw new RuntimeException('Cannot write the pickup key.');
                }
            }
            return $key;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
