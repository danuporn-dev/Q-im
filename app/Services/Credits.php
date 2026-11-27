<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;

/** The public /credits page: the table of public/assets/demo-food/CREDITS.md, every cell escaped. */
final class Credits
{
    public static function file(): string
    {
        // Test hook, ignored outside development: the demo photo folder of seed-demo.php.
        $dir = (Env::isDevelopment() ? Env::get('DEMO_FOOD_DIR') : '') ?: dirname(__DIR__, 2) . '/public/assets/demo-food';
        return "$dir/CREDITS.md";
    }

    /**
     * The first Markdown table of the file: header cells and the rows that have as many cells.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public static function table(string $markdown): array
    {
        $head = [];
        $rows = [];
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                if ($head !== []) {
                    break;
                }
                continue;
            }
            $cells = array_map(static fn (string $c): string => trim(trim($c), '`'), explode('|', trim($line, '|')));
            if ($head === []) {
                $head = $cells;
            } elseif (!preg_match('/^[\s|:-]+$/', $line) && count($cells) === count($head)) {
                $rows[] = $cells;
            }
        }
        return [$head, $rows];
    }

    public static function page(): void
    {
        $file = self::file();
        [$head, $rows] = self::table(is_file($file) ? (string) file_get_contents($file) : '');
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $cell = static fn (string $s): string => preg_match('#^https://[^\s"\'<>]+$#D', $s)
            ? '<a href="' . $e($s) . '" rel="noopener noreferrer" target="_blank">' . $e(urldecode(preg_replace('#^https://(www\.)?#', '', $s))) . '</a>'
            : $e($s);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'");
        header('Cache-Control: no-cache');
        echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>เครดิตรูปภาพ · Q-im</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main class="page credits">',
            '<p><a href="customer/">‹ กลับไปสั่งอาหาร</a></p><h1>เครดิตรูปภาพ</h1>',
            '<p class="muted">รูปเมนูในการสาธิตมาจาก Wikimedia Commons ภายใต้ใบอนุญาตที่ระบุ ระบบย่อรูปและบันทึกใหม่เป็น JPEG โดยไม่ครอป เมนูที่ไม่มีรูปในตารางแสดงภาพวาดของระบบเอง</p>';
        if ($rows === []) {
            echo '<p>ยังไม่มีรูปที่ต้องให้เครดิต</p>';
        } else {
            echo '<div class="tablewrap"><table><thead><tr>';
            foreach ($head as $h) {
                echo '<th>', $e($h), '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                foreach ($r as $c) {
                    echo '<td>', $cell($c), '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</main></body></html>';
    }
}
