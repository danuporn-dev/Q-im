<?php
declare(strict_types=1);

namespace App\Support;

/** Server-side validation helpers. Every method throws a 422 HttpError on bad input. */
final class Input
{
    public static function text(array $in, string $key, string $label, int $max, int $min = 1): string
    {
        $value = $in[$key] ?? '';
        if (!is_string($value)) {
            throw HttpError::invalid("$label ไม่ถูกต้อง", ['field' => $key]);
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if (!mb_check_encoding($value, 'UTF-8') || $length < $min || $length > $max) {
            throw HttpError::invalid($length < $min ? "กรุณากรอก$label" : "$label ยาวเกินไป (สูงสุด $max ตัวอักษร)", ['field' => $key]);
        }
        return $value;
    }

    public static function optionalText(array $in, string $key, string $label, int $max): ?string
    {
        if (!isset($in[$key])) {
            return null;
        }
        $value = self::text($in, $key, $label, $max, 0);
        return $value === '' ? null : $value;
    }

    public static function int(array $in, string $key, string $label, int $min, int $max): int
    {
        $value = $in[$key] ?? null;
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw HttpError::invalid("$label ต้องเป็นตัวเลข $min ถึง $max", ['field' => $key]);
        }
        return $value;
    }

    public static function optionalInt(array $in, string $key, string $label, int $min, int $max): ?int
    {
        return !isset($in[$key]) || $in[$key] === '' ? null : self::int($in, $key, $label, $min, $max);
    }

    public static function bool(array $in, string $key, string $label): int
    {
        if (!is_bool($in[$key] ?? null)) {
            throw HttpError::invalid("$label ไม่ถูกต้อง", ['field' => $key]);
        }
        return $in[$key] ? 1 : 0;
    }

    /** Decimal string with two digits; money is never a float. */
    public static function money(array $in, string $key, string $label, bool $allowNegative = false): string
    {
        $value = $in[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        $sign = $allowNegative ? '-?' : '';
        if (!is_string($value) || !preg_match("/^$sign\d{1,6}(\.\d{1,2})?$/D", trim($value))) {
            throw HttpError::invalid("$label ไม่ถูกต้อง", ['field' => $key]);
        }
        return Money::fromSatang(Money::toSatang(trim($value)));
    }

    /** @return list<mixed> */
    public static function list(array $in, string $key, string $label, int $max): array
    {
        $value = $in[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw HttpError::invalid("$label ไม่ถูกต้อง", ['field' => $key]);
        }
        return $value;
    }

    public static function date(string $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw HttpError::invalid("$label ไม่ถูกต้อง");
        }
        return $value;
    }

    public static function imageUrl(array $in, string $key): ?string
    {
        $value = self::optionalText($in, $key, 'รูปภาพ', 500);
        if ($value !== null && !preg_match('#^(image:[0-9a-f]{32}|https://[^\s"\'<>]+)$#D', $value)) {
            throw HttpError::invalid('รูปภาพไม่ถูกต้อง', ['field' => $key]);
        }
        return $value;
    }
}
