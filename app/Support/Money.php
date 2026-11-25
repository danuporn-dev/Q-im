<?php
declare(strict_types=1);

namespace App\Support;

/** Exact money arithmetic in satang (1/100 baht). */
final class Money
{
    public static function toSatang(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = explode('.', ltrim($decimal, '-') . '.', 3) + [1 => ''];
        $satang = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$satang : $satang;
    }

    public static function fromSatang(int $satang): string
    {
        return sprintf('%s%d.%02d', $satang < 0 ? '-' : '', intdiv(abs($satang), 100), abs($satang) % 100);
    }
}
