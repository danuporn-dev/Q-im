<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use App\Support\Money;

/**
 * PromptPay QR text (EMVCo merchant-presented mode) for a mobile number. The system never learns whether
 * money arrived: the shop checks its own account and confirms the payment itself (D38).
 */
final class PromptPay
{
    /** A Thai mobile number as ten digits. National ID and other numbers are refused and never stored. */
    public static function phone(mixed $value): string
    {
        $digits = is_string($value) && strlen($value) <= 20 && preg_match('/^[0-9 -]+$/D', $value) ? str_replace([' ', '-'], '', $value) : '';
        if (!preg_match('/^0[689][0-9]{8}$/D', $digits)) {
            throw HttpError::invalid('หมายเลข PromptPay ต้องเป็นเบอร์มือถือไทย 10 หลัก (ไม่รับเลขบัตรประชาชน)', ['field' => 'promptpay_phone']);
        }
        return $digits;
    }

    /** $amount is a decimal string such as "45.00". */
    public static function payload(string $phone, string $amount): string
    {
        $field = static fn (string $id, string $value): string => $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
        $data = $field('00', '01') . $field('01', '12')
            . $field('29', $field('00', 'A000000677010111') . $field('01', '0066' . substr($phone, 1)))
            . $field('58', 'TH') . $field('53', '764') . $field('54', Money::fromSatang(Money::toSatang($amount))) . '6304';
        return $data . self::crc($data);
    }

    /** CRC-16/CCITT-FALSE: polynomial 0x1021, start 0xFFFF, as four upper-case hex digits. */
    public static function crc(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) !== 0 ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xFFFF;
            }
        }
        return sprintf('%04X', $crc);
    }

    /** The number of a shop that currently offers PromptPay, or null. */
    public static function numberOf(int $shopId): ?string
    {
        $phone = Database::value('SELECT promptpay_phone FROM shop_settings WHERE shop_id = ? AND promptpay_enabled = 1', [$shopId]);
        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /** What the owner of a ready, unpaid order sees; the amount comes from the order. */
    public static function forOrder(int $shopId, string $total): array
    {
        $phone = self::numberOf($shopId);
        return ['payload' => $phone === null ? null : self::payload($phone, $total), 'amount' => $total];
    }
}
