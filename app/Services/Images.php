<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;

/**
 * Pictures of menus and shops (D41). An upload is judged by its bytes, never by its name or Content-Type. It is
 * then drawn again by GD into new JPEG files with a random name, so nothing of the original file is kept:
 * no metadata, no hidden content, no file name. Files live outside the web root and are only sent by output().
 */
final class Images
{
    public const MAX_BYTES = 3 * 1024 * 1024;
    public const MIN_SIDE = 200;
    public const MAX_SIDE = 6000;
    private const MAX_PIXELS = 24_000_000;
    private const FULL = 1200;
    private const THUMB = 480;
    /** Uploads that are not on a menu or the shop yet. Older ones are deleted; too many stop new uploads. */
    private const PENDING_LIMIT = 30;
    private const PENDING_HOURS = 24;
    /** Never in a real photo, and long enough not to turn up by chance in compressed picture data. */
    private const ANYWHERE = ['<?php', '<script', '<html', '<!doctype', '<iframe', '<object', '<embed', 'javascript:', '%pdf-'];
    /** Shorter, so only looked for in metadata (comments, EXIF, XMP, text chunks). */
    private const IN_METADATA = ['<?=', '<svg', '<body', 'onerror', 'onload', "pk\x03\x04", "\x7felf", 'rar!'];

    /** Not on any menu or shop; `i` is the images row. */
    private const UNUSED = "NOT EXISTS (SELECT 1 FROM menus m WHERE m.image_url = CONCAT('image:', i.id)) AND NOT EXISTS (SELECT 1 FROM shops s WHERE s.image_url = CONCAT('image:', i.id))";

    /** @param array<string, mixed>|null $file one entry of $_FILES */
    public static function upload(?array $file, int $shopId, int $userId): string
    {
        $error = $file['error'] ?? null;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file === null && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BYTES)) {
            throw self::tooLarge();
        }
        if (!is_array($file) || $error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw HttpError::invalid('อัปโหลดรูปไม่สำเร็จ', ['field' => 'image', 'reason' => 'missing']);
        }
        if ((int) filesize($file['tmp_name']) > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        self::sweep($shopId);
        if ((int) Database::value('SELECT COUNT(*) FROM images i WHERE i.shop_id = ? AND ' . self::UNUSED, [$shopId]) >= self::PENDING_LIMIT) {
            throw HttpError::conflict('TOO_MANY_PENDING', 'มีรูปที่อัปโหลดแต่ยังไม่ได้บันทึกมากเกินไป กรุณาบันทึกเมนูก่อน หรือรอ 24 ชั่วโมง');
        }
        return self::save($file['tmp_name'], $shopId, $userId);
    }

    /** The demo seed: a file on this computer, with the same checks and drawing as an upload. */
    public static function import(string $path, int $shopId): string
    {
        if (!is_file($path)) {
            throw HttpError::invalid('ไม่พบไฟล์รูป', ['field' => 'image', 'reason' => 'missing']);
        }
        if ((int) filesize($path) > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        return self::save($path, $shopId, null);
    }

    /**
     * Inside the transaction that saves a menu or a shop. A picture is either the one it already has, an https
     * address, or an upload of this same shop: one shop can never show, or later delete, another shop's file.
     */
    public static function claim(?string $url, ?string $current, int $shopId): void
    {
        $key = self::key($url);
        if ($key === null || $url === $current) {
            return;
        }
        if (!Database::value('SELECT 1 FROM images WHERE id = ? AND shop_id = ? FOR UPDATE', [$key, $shopId])) {
            throw HttpError::invalid('รูปภาพไม่ถูกต้อง', ['field' => 'image_url']);
        }
    }

    /** After a picture was replaced or removed: its files are deleted once no menu or shop uses it. */
    public static function release(?string $url): void
    {
        $key = self::key($url);
        if ($key === null) {
            return;
        }
        $deleted = Database::tx(static function () use ($key): bool {
            if (!Database::value('SELECT 1 FROM images i WHERE i.id = ? FOR UPDATE', [$key]) || !Database::value('SELECT 1 FROM images i WHERE i.id = ? AND ' . self::UNUSED, [$key])) {
                return false;
            }
            Database::run('DELETE FROM images WHERE id = ?', [$key]);
            return true;
        });
        if ($deleted) {
            foreach (self::files($key) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /** Files of an upload whose row was never committed (the demo seed failed). */
    public static function discard(string $key): void
    {
        if (!Database::value('SELECT 1 FROM images WHERE id = ?', [$key])) {
            foreach (self::files($key) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    public static function output(string $key, bool $thumb = false): void
    {
        [$path, $full] = self::files($key);
        if (!$thumb || !is_file($path)) {
            $path = $full; // a thumbnail is missing for uploads made before D41
        }
        $type = 'image/jpeg';
        if (!is_file($path)) {
            // Placeholders drawn by an earlier scripts/seed-demo.php; uploads are never SVG.
            $path = substr($full, 0, -4) . '.svg';
            if (!Env::isDevelopment() || !is_file($path)) {
                throw HttpError::notFound('ไม่พบรูป');
            }
            $type = 'image/svg+xml';
        }
        header("Content-Type: $type");
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    /** Test hook, ignored outside development: another folder for the files. */
    public static function dir(): string
    {
        $dir = (Env::isDevelopment() ? Env::get('UPLOAD_DIR') : '') ?: dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the picture folder $dir.");
        }
        return $dir;
    }

    private static function key(?string $url): ?string
    {
        return $url !== null && preg_match('/^image:([0-9a-f]{32})$/D', $url, $m) ? $m[1] : null;
    }

    /** @return array{0: string, 1: string} thumbnail and full size */
    private static function files(string $key): array
    {
        if (!preg_match('/^[0-9a-f]{32}$/D', $key)) {
            throw HttpError::notFound('ไม่พบรูป');
        }
        $dir = self::dir();
        return ["$dir/$key-t.jpg", "$dir/$key.jpg"];
    }

    /** Uploads left unsaved for a day. */
    private static function sweep(int $shopId): void
    {
        $old = Database::rows('SELECT i.id FROM images i WHERE i.shop_id = ? AND i.created_at < NOW() - INTERVAL ' . self::PENDING_HOURS . ' HOUR AND ' . self::UNUSED . ' LIMIT 50', [$shopId]);
        foreach ($old as $row) {
            self::release('image:' . $row['id']);
        }
    }

    private static function tooLarge(): HttpError
    {
        return new HttpError(413, 'TOO_LARGE', 'รูปต้องมีขนาดไม่เกิน 3 MB', ['field' => 'image', 'reason' => 'size']);
    }

    private static function rejected(string $reason, string $message): HttpError
    {
        return HttpError::invalid($message, ['field' => 'image', 'reason' => $reason]);
    }

    private static function save(string $source, int $shopId, ?int $userId): string
    {
        $image = self::decode($source, self::FULL);
        $key = bin2hex(random_bytes(16));
        [$thumb, $full] = self::files($key);
        try {
            self::write($image, $full);
            self::write(self::resize($image, self::THUMB), $thumb);
            Database::insert('images', ['id' => $key, 'shop_id' => $shopId, 'uploaded_by' => $userId, 'width' => imagesx($image), 'height' => imagesy($image), 'bytes' => (int) filesize($full)]);
        } catch (\Throwable $e) {
            foreach ([$thumb, $full] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }
        return $key;
    }

    /**
     * The same checks and drawing as an upload, into one new JPEG file: used for the demo photos that are kept in
     * public/assets/demo-food (D41), so they carry no metadata either. Refuses to replace an existing file.
     */
    public static function redraw(string $source, string $target, int $max = self::FULL): void
    {
        if (!is_file($source)) {
            throw self::rejected('missing', 'ไม่พบไฟล์รูป');
        }
        if ((int) filesize($source) > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        self::write(self::decode($source, $max), $target);
    }

    /** Checks the file from its bytes and returns it upright, no larger than $max. */
    private static function decode(string $source, int $max): \GdImage
    {
        $bytes = (string) file_get_contents($source);
        $orientation = self::inspect($bytes);
        $original = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$original instanceof \GdImage) {
            throw self::rejected('corrupt', 'ไฟล์รูปเสียหายหรือเป็นรูปเคลื่อนไหว');
        }
        // Reduced before turning: turning the original would need its memory twice (up to 2 x 96 MB).
        return self::upright(self::resize($original, $max), $orientation);
    }

    /** A new true-colour image no larger than $max on its longer side. */
    private static function resize(\GdImage $source, int $max): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = min(1, $max / max($sw, $sh));
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        $target = imagecreatetruecolor($w, $h);
        // Transparent parts of a PNG or WebP become white, as JPEG has no transparency.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $sw, $sh);
        return $target;
    }

    private static function write(\GdImage $image, string $path): void
    {
        // "x": fails instead of replacing a file that is already there.
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create a picture file.');
        }
        try {
            $ok = imagejpeg($image, $handle, 82);
        } finally {
            fclose($handle);
        }
        if (!$ok) {
            throw new \RuntimeException('Cannot write a picture file.');
        }
    }

    /** Phones store photos sideways with an EXIF note; the note is dropped, so the turn is applied here. */
    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4], true)) {
            imageflip($image, $orientation === 2 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            return $image;
        }
        $angle = [3 => 180, 5 => 90, 6 => 270, 7 => 270, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $turned = imagerotate($image, $angle, 0);
            $image = $turned instanceof \GdImage ? $turned : $image;
        }
        if (in_array($orientation, [5, 7], true)) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        return $image;
    }

    /**
     * Rejects anything but a single well-formed JPEG, PNG or WebP picture of a sensible size, and files that
     * are also something else (a "polyglot": a picture with a page, script or archive in it or after it).
     *
     * @return int EXIF orientation (1 = as stored)
     */
    private static function inspect(string $b): int
    {
        $orientation = 1;
        if (str_starts_with($b, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
            [$meta, $end, $orientation] = self::jpeg($b);
            // Some cameras pad the file after the picture; anything else there is hidden content.
            $after = trim(substr($b, $end), "\x00\xFF");
        } elseif (str_starts_with($b, "\x89PNG\r\n\x1A\n")) {
            $mime = 'image/png';
            [$meta, $end] = self::png($b);
            $after = substr($b, $end);
        } elseif (strlen($b) >= 16 && substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
            [$meta, $end] = self::webp($b);
            $after = substr($b, $end);
        } else {
            $start = strtolower(ltrim(substr($b, 0, 1024), "\xEF\xBB\xBF \t\r\n"));
            if (str_starts_with($start, '<svg') || (str_starts_with($start, '<?xml') || str_starts_with($start, '<!')) && str_contains($start, '<svg')) {
                throw self::rejected('svg', 'ไม่รับไฟล์ SVG กรุณาใช้รูป JPEG, PNG หรือ WebP');
            }
            throw self::rejected('type', 'รองรับเฉพาะรูป JPEG, PNG หรือ WebP');
        }
        // Two independent readers of the content must agree with the signature.
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($b);
        $info = @getimagesizefromstring($b);
        if ($sniffed !== $mime || !$info || ($info['mime'] ?? '') !== $mime) {
            throw self::rejected('type', 'รองรับเฉพาะรูป JPEG, PNG หรือ WebP');
        }
        $lower = strtolower($b);
        $metaLower = strtolower($meta);
        $hidden = $after !== '';
        foreach (self::ANYWHERE as $sign) {
            $hidden = $hidden || str_contains($lower, $sign);
        }
        foreach (self::IN_METADATA as $sign) {
            $hidden = $hidden || str_contains($metaLower, $sign);
        }
        if ($hidden) {
            throw self::rejected('hidden', 'ไฟล์รูปมีข้อมูลอื่นแฝงอยู่ กรุณาบันทึกรูปใหม่ (เช่น ส่งออกจากแอปแต่งรูปหรือแคปหน้าจอ) แล้วลองอีกครั้ง');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if (min($w, $h) < self::MIN_SIDE || max($w, $h) > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) {
            throw self::rejected('dimensions', 'รูปต้องกว้างและสูงอย่างน้อย ' . self::MIN_SIDE . ' px และไม่เกิน ' . self::MAX_SIDE . ' px');
        }
        return $orientation;
    }

    private static function corrupt(): HttpError
    {
        return self::rejected('corrupt', 'ไฟล์รูปเสียหายหรือไม่สมบูรณ์');
    }

    /** @return array{0: string, 1: int, 2: int} metadata, offset after the end marker, EXIF orientation */
    private static function jpeg(string $b): array
    {
        $n = strlen($b);
        $p = 2;
        $meta = '';
        $orientation = 1;
        while ($p + 1 < $n) {
            if ($b[$p] !== "\xFF") {
                throw self::corrupt();
            }
            $marker = ord($b[$p + 1]);
            if ($marker === 0xFF) {
                $p++; // fill byte
                continue;
            }
            if ($marker === 0xD9) {
                return [$meta, $p + 2, $orientation];
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $p += 2;
                continue;
            }
            if ($p + 4 > $n) {
                throw self::corrupt();
            }
            $length = unpack('n', $b, $p + 2)[1];
            if ($length < 2 || $p + 2 + $length > $n) {
                throw self::corrupt();
            }
            if (($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE) {
                $data = substr($b, $p + 4, $length - 2);
                $meta .= $data;
                if ($marker === 0xE1 && str_starts_with($data, "Exif\0\0")) {
                    $orientation = self::orientation(substr($data, 6)) ?? $orientation;
                }
            }
            $p += 2 + $length;
            if ($marker === 0xDA) {
                // Compressed data runs to the next marker; FF 00 is an escaped byte and FF D0-D7 a restart.
                while (true) {
                    $q = strpos($b, "\xFF", $p);
                    if ($q === false || $q + 1 >= $n) {
                        throw self::corrupt();
                    }
                    $next = ord($b[$q + 1]);
                    if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                        $p = $q + 2;
                    } elseif ($next === 0xFF) {
                        $p = $q + 1;
                    } else {
                        $p = $q;
                        break;
                    }
                }
            }
        }
        throw self::corrupt();
    }

    /** The orientation tag of the first EXIF directory, if any. */
    private static function orientation(string $tiff): ?int
    {
        $n = strlen($tiff);
        [$short, $long] = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => [null, null],
        };
        if ($short === null || $n < 8) {
            return null;
        }
        $ifd = unpack($long, $tiff, 4)[1];
        if ($ifd + 2 > $n) {
            return null;
        }
        $count = min(unpack($short, $tiff, $ifd)[1], 500);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($entry + 12 > $n) {
                return null;
            }
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                $value = unpack($short, $tiff, $entry + 8)[1];
                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }

    /** @return array{0: string, 1: int} ancillary chunks, offset after IEND */
    private static function png(string $b): array
    {
        $n = strlen($b);
        $p = 8;
        $meta = '';
        while ($p + 12 <= $n) {
            $length = unpack('N', $b, $p)[1];
            $type = substr($b, $p + 4, 4);
            if ($length > $n - $p - 12 || ($p === 8 && $type !== 'IHDR') || !preg_match('/^[A-Za-z]{4}$/D', $type)) {
                throw self::corrupt();
            }
            if (ctype_lower($type[0])) {
                $meta .= substr($b, $p + 8, $length);
            }
            $p += 12 + $length;
            if ($type === 'IEND') {
                return [$meta, $p];
            }
        }
        throw self::corrupt();
    }

    /** @return array{0: string, 1: int} metadata and unknown chunks, offset after the RIFF container */
    private static function webp(string $b): array
    {
        $size = unpack('V', $b, 4)[1];
        $end = 8 + $size;
        if ($size < 4 || $end > strlen($b)) {
            throw self::corrupt();
        }
        $p = 12;
        $meta = '';
        while ($p + 8 <= $end) {
            $chunk = substr($b, $p, 4);
            $length = unpack('V', $b, $p + 4)[1];
            if ($length > $end - $p - 8) {
                throw self::corrupt();
            }
            if (!in_array($chunk, ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ICCP'], true)) {
                $meta .= substr($b, $p + 8, $length);
            }
            $p += 8 + $length + ($length & 1);
        }
        if ($p !== $end) {
            throw self::corrupt();
        }
        return [$meta, $end];
    }
}
