<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use App\Support\HttpError;

/** Uploads are re-encoded to JPEG and stored outside the web root. */
final class Images
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_SIDE = 1200;

    public static function store(?array $file): string
    {
        if ($file === null || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpError::invalid('อัปโหลดรูปไม่สำเร็จ');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw HttpError::invalid('รูปต้องมีขนาดไม่เกิน 5 MB');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $size = @getimagesize($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$size || $size[0] * $size[1] > 25_000_000) {
            throw HttpError::invalid('รองรับเฉพาะรูป JPEG, PNG หรือ WebP');
        }
        $source = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$source) {
            throw HttpError::invalid('ไฟล์รูปเสียหาย');
        }
        $scale = min(1, self::MAX_SIDE / max($size[0], $size[1]));
        $w = max(1, (int) round($size[0] * $scale));
        $h = max(1, (int) round($size[1] * $scale));
        $target = imagecreatetruecolor($w, $h);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
        $key = bin2hex(random_bytes(16));
        if (!imagejpeg($target, self::path($key), 82)) {
            throw new \RuntimeException('Cannot write image.');
        }
        return $key;
    }

    public static function output(string $key): void
    {
        $path = self::path($key);
        $type = 'image/jpeg';
        if (!is_file($path)) {
            // Placeholders drawn by scripts/seed-demo.php; uploads are never SVG.
            $path = substr($path, 0, -4) . '.svg';
            if (!Env::isDevelopment() || !is_file($path)) {
                throw HttpError::notFound('ไม่พบรูป');
            }
            $type = 'image/svg+xml';
            header("Content-Security-Policy: default-src 'none'");
        }
        header("Content-Type: $type");
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    private static function path(string $key): string
    {
        return dirname(__DIR__, 2) . "/storage/uploads/$key.jpg";
    }
}
