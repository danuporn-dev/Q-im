<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** All stored times are UTC; business dates and shop hours are Asia/Bangkok. */
final class Clock
{
    public static function timestamp(): int
    {
        // Test hook, ignored outside development.
        return time() + (Env::isDevelopment() ? (int) Env::get('TEST_CLOCK_OFFSET', '0') : 0);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s', self::timestamp());
    }

    public static function bangkok(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . self::timestamp()))->setTimezone(new DateTimeZone('Asia/Bangkok'));
    }

    public static function businessDate(): string
    {
        return self::bangkok()->format('Y-m-d');
    }

    /** UTC bounds [from, to) covering the given Bangkok dates, inclusive. */
    public static function utcRange(string $fromDate, string $toDate): array
    {
        $tz = new DateTimeZone('Asia/Bangkok');
        $utc = new DateTimeZone('UTC');
        return [
            (new DateTimeImmutable($fromDate . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }
}
