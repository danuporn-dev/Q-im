<?php
declare(strict_types=1);

namespace App\Payments;

/**
 * Stripe-Signature check, as Stripe documents for manual verification: the header holds t=<unix time> and one or
 * more v1=<hex>; the signed text is "<t>.<raw body>"; HMAC-SHA256 with the endpoint secret must equal one v1 entry
 * (several during a secret rotation). v0 and other entries are ignored. Compared in constant time.
 */
final class StripeSignature
{
    public const TOLERANCE = 300;

    public static function verify(string $payload, string $header, string $secret, int $now, int $tolerance = self::TOLERANCE): bool
    {
        if ($secret === '' || $header === '' || strlen($header) > 4096) {
            return false;
        }
        $time = null;
        $signatures = [];
        foreach (explode(',', $header) as $entry) {
            [$key, $value] = array_pad(explode('=', trim($entry), 2), 2, '');
            if ($key === 't' && $time === null && preg_match('/^\d{1,12}$/D', $value)) {
                $time = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $value)) {
                $signatures[] = $value;
            }
        }
        if ($time === null || $signatures === [] || abs($now - $time) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
        $match = false;
        foreach ($signatures as $signature) {
            $match = hash_equals($expected, $signature) || $match;
        }
        return $match;
    }

    /** What Stripe would send; used by the tests and the fake rehearsal. */
    public static function header(string $payload, string $secret, int $time): string
    {
        return "t=$time,v1=" . hash_hmac('sha256', $time . '.' . $payload, $secret);
    }
}
