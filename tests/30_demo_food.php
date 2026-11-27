<?php
declare(strict_types=1);

// Demo menu photos from Wikimedia Commons (D41): every file credited under an open licence, small, and accepted
// by the same checks as an upload; the /credits page lists them with every cell escaped.

use App\Services\Credits;
use App\Services\Images;

$dir = dirname(__DIR__) . '/public/assets/demo-food';
[$head, $rows] = Credits::table((string) file_get_contents("$dir/CREDITS.md"));
$byFile = array_column($rows, null, 1);
$files = array_map('basename', glob("$dir/*.{jpg,jpeg,png,webp}", GLOB_BRACE) ?: []);

T::group('T56 demo photos: credited, open licence, small, safe');
T::eq(['เมนู', 'ไฟล์', 'ไฟล์ต้นทาง', 'ผู้ถ่าย', 'ใบอนุญาต', 'ลิงก์ใบอนุญาต', 'หน้าไฟล์ใน Commons', 'วันที่โหลด', 'การปรับ'], $head, 'credits table columns');
T::eq([], array_values(array_diff($files, array_keys($byFile))), 'every photo has a row in CREDITS.md');
T::eq([], array_values(array_diff(array_keys($byFile), $files)), 'every row has its photo');
$bad = [];
$tmp = sys_get_temp_dir() . '/qim-demo-food-check-' . Fixture::run() . '.jpg';
foreach ($files as $file) {
    $path = "$dir/$file";
    $row = $byFile[$file] ?? [];
    $licence = $row[4] ?? '';
    if (filesize($path) > Images::MAX_BYTES) {
        $bad[] = "$file: over 3 MB";
    }
    if (!preg_match('/^(CC0( 1\.0)?|Public domain|CC BY( \d\.\d)?|CC BY-SA( \d\.\d)?)$/D', $licence)) {
        $bad[] = "$file: licence '$licence'";
    }
    if (str_starts_with($licence, 'CC BY') && in_array(trim($row[3] ?? ''), ['', '-'], true)) {
        $bad[] = "$file: CC BY without author";
    }
    if (!preg_match('#^https://commons\.wikimedia\.org/wiki/File:#', $row[6] ?? '')) {
        $bad[] = "$file: no Commons page";
    }
    if (str_contains((string) file_get_contents($path), 'Exif')) {
        $bad[] = "$file: metadata left";
    }
    try {
        @unlink($tmp);
        Images::redraw($path, $tmp);
    } catch (Throwable $e) {
        $bad[] = "$file: refused by the upload checks: " . $e->getMessage();
    }
}
@unlink($tmp);
T::eq([], $bad, count($files) . ' photos pass');

T::group('T57 /credits page');
$r = (new Client($base))->get('/credits');
T::ok($r['status'] === 200 && str_contains($r['raw'], 'เครดิตรูปภาพ') && ($files === [] || str_contains($r['raw'], '>' . $files[0] . '<')) && stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false && stripos($r['head'], "default-src 'none'") !== false, 'lists the photos, nosniff and a strict CSP', $r['status']);
// A credits file with hostile text, as an example of what escaping must handle.
$evil = sys_get_temp_dir() . '/qim-credits-' . Fixture::run();
@mkdir($evil);
file_put_contents("$evil/CREDITS.md", "| เมนู | ไฟล์ | ลิงก์ |\n|---|---|---|\n| <script>alert(1)</script> | x\" onmouseover=\"alert(2) | javascript:alert(3) |\n| ต้มยำ & น้ำ | a.jpg | https://commons.wikimedia.org/wiki/File:A\"><b>x |\n");
$page = (new Client(Servers::start(8371, ['DEMO_FOOD_DIR' => $evil])))->get('/credits')['raw'];
T::ok(!str_contains($page, '<script>alert') && str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page, 'href="javascript') && !str_contains($page, '<b>x') && !preg_match('/<[^>]+onmouseover=/i', $page) && str_contains($page, 'ต้มยำ &amp; น้ำ'), 'every cell is escaped; only plain https addresses become links');
array_map('unlink', glob("$evil/*") ?: []);
@rmdir($evil);
T::ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/js/customer.js'), "href: '../credits'"), 'the customer home page links to it');
