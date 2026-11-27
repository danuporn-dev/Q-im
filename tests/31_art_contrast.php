<?php
declare(strict_types=1);

// The drawn pictures (D41) stand out on every surface they appear on: the frame of img.art and the outline of the
// main dish in each drawing reach 3:1 (WCAG 1.4.11, non-text contrast) against what lies next to them. The colours
// are read from app.css and the SVG files, so a change of either is checked again.

$root = dirname(__DIR__) . '/public/assets';
$css = (string) file_get_contents("$root/css/app.css");
preg_match('/:root\s*\{(.*?)\}/s', $css, $rootBlock);
preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6})\b/', $rootBlock[1] ?? '', $tokenRows, PREG_SET_ORDER);
$token = array_column($tokenRows, 2, 1);
$hex = static function (string $c): array {
    $c = ltrim($c, '#');
    if (strlen($c) === 3) {
        $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    }
    return array_map('hexdec', str_split(strtolower($c), 2));
};
$luminance = static function (string $c) use ($hex): float {
    $l = array_map(static function (int $v): float {
        $s = $v / 255;
        return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }, $hex($c));
    return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
};
$ratio = static function (string $a, string $b) use ($luminance): float {
    [$x, $y] = [$luminance($a), $luminance($b)];
    return round((max($x, $y) + 0.05) / (min($x, $y) + 0.05), 2);
};

T::group('T62 drawn pictures stand out on peach cards, the shop board and white pages');
T::ok(preg_match('/img\.art\s*\{[^}]*outline:\s*2px solid var\(--muted-on-tint\)/', $css) === 1, 'img.art has a 2px frame in --muted-on-tint');
$frame = $token['muted-on-tint'] ?? '';
foreach (['tint' => 'peach cards and the shop board (.ocard)', 'tint-2' => 'light peach rows', 'white' => 'white cards and pages', 'surface' => 'grey page background'] as $name => $where) {
    $r = $ratio($frame, $token[$name] ?? '#000');
    T::ok($r >= 3.0, sprintf('frame %s on --%s %s: %.2f:1', $frame, $name, $where, $r), $r);
}
foreach (glob("$root/images/art/*.svg") ?: [] as $file) {
    $svg = (string) file_get_contents($file);
    $name = basename($file, '.svg');
    preg_match('/<rect width="400" height="300" fill="(#[0-9a-f]{6})"/i', $svg, $bg);
    preg_match('/<circle[^>]*\br="1\d\d" fill="(#[0-9a-f]{6})"/i', $svg, $halo);
    preg_match_all('/stroke="(#[0-9a-f]{6})" stroke-width="(\d+)"[^>]*class="edge"/i', $svg, $edges, PREG_SET_ORDER);
    $colours = array_unique(array_map('strtolower', array_column($edges, 1)));
    $behind = array_filter([$bg[1] ?? null, $halo[1] ?? null]);
    $worst = min(array_merge([99.0], ...array_map(static fn (string $e): array => array_map(static fn (string $b): float => $ratio($e, $b), $behind), $colours)));
    T::ok($edges !== [] && $colours === [strtolower($frame)] && min(array_map('intval', array_column($edges, 2))) >= 3 && count($behind) === 2 && $worst >= 3.0,
        sprintf('%s.svg: main dish outlined in --muted-on-tint, %.2f:1 against its background', $name, $worst), [$colours, $behind, $worst]);
}
