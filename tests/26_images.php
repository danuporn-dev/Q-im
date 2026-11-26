<?php
declare(strict_types=1);

// Pictures of menus and shops (D41): what an upload may be, who may use it, and what is left on disk.

use App\Services\Images;
use App\Support\Database;

$a = '/api/v1';
$dir = Images::dir();
$s = Fixture::shop('img');
$other = Fixture::shop('img_other');
$m = Fixture::login($base, 'merchant', $s['login']);
$mOther = Fixture::login($base, 'merchant', $other['login']);
$anon = new Client($base);
$tmp = tempnam(sys_get_temp_dir(), 'qimimg');

/** A photo-like picture: a gradient, so it compresses like a real one. */
$draw = static function (int $w, int $h, string $type = 'jpeg'): string {
    $img = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 8) {
        imagefilledrectangle($img, $x, 0, $x + 7, $h, imagecolorallocate($img, $x % 256, (int) ($x / 3) % 256, 120));
    }
    ob_start();
    match ($type) {
        'jpeg' => imagejpeg($img, null, 85),
        'png' => imagepng($img),
        'webp' => imagewebp($img),
        'gif' => imagegif($img),
    };
    return (string) ob_get_clean();
};
/** Uploads bytes under any file name and Content-Type. */
$send = static function (Client $c, int $shop, string $bytes, string $name = 'photo.jpg', string $type = 'image/jpeg') use ($a, $tmp): array {
    file_put_contents($tmp, $bytes);
    return $c->post("$a/merchant/shops/$shop/images", ['__multipart' => ['image' => new CURLFile($tmp, $type, $name)]]);
};
$key = static fn (array $r): string => substr((string) ($r['body']['data']['image_url'] ?? 'image:'), 6);
$reason = static fn (array $r): ?string => $r['body']['error']['details']['reason'] ?? null;
$chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$jpeg = $draw(640, 480);
$png = $draw(640, 480, 'png');

T::group('T28 upload: real JPEG, PNG and WebP become new JPEG files with a random name');
foreach (['jpeg' => $jpeg, 'png' => $png, 'webp' => $draw(640, 480, 'webp')] as $type => $bytes) {
    $r = $send($m, $s['shop'], $bytes, "my-secret-name.$type", "image/$type");
    T::status(201, $r, "$type accepted");
    $k = $key($r);
    $full = $anon->get("$a/images/$k");
    $thumb = $anon->get("$a/images/$k/thumb");
    $size = getimagesizefromstring($full['raw']) ?: [0, 0];
    T::ok(preg_match('/^[0-9a-f]{32}$/D', $k) === 1 && str_contains($full['head'], 'image/jpeg') && stripos($full['head'], 'X-Content-Type-Options: nosniff') !== false
        && [$size[0], $size[1]] === [640, 480] && (getimagesizefromstring($thumb['raw'])[0] ?? 0) === 480, "$type served as JPEG with nosniff, full and 480 px thumbnail", [$k, $size, $full['head']]);
}
$names = array_map('basename', glob("$dir/*") ?: []);
T::ok($names !== [] && preg_grep('/^[0-9a-f]{32}(-t)?\.jpg$|^\.htaccess$|^\.gitkeep$/D', $names, PREG_GREP_INVERT) === [] && preg_grep('/secret/', $names) === [], 'only random names on disk', array_slice($names, 0, 5));
T::eq(0, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE safe_metadata LIKE '%my-secret%'"), 'the original file name is kept nowhere');
$r = $send($m, $s['shop'], $draw(2400, 1600));
T::eq([1200, 800], array_slice(getimagesizefromstring($anon->get("$a/images/{$key($r)}")['raw']) ?: [], 0, 2), 'large photo reduced to 1200 px');
T::status(201, $send($m, $s['shop'], $jpeg, 'notes.txt', 'text/plain'), 'judged by content: a JPEG named .txt is a JPEG');

T::group('T29 fake type and fake extension');
foreach ([
    'text named .jpg' => ['just some text, not a picture', 'x.jpg', 'image/jpeg'],
    'PHP named .png' => ['<?php system($_GET["c"]); ?>', 'x.png', 'image/png'],
    'HTML named .webp' => ['<!doctype html><script>alert(1)</script>', 'x.webp', 'image/webp'],
    'GIF named .png' => [$draw(640, 480, 'gif'), 'x.png', 'image/png'],
    'JPEG signature, no picture' => ["\xFF\xD8\xFF\xE0" . str_repeat('A', 300), 'x.jpg', 'image/jpeg'],
] as $label => [$bytes, $name, $type]) {
    $r = $send($m, $s['shop'], $bytes, $name, $type);
    T::ok($r['status'] === 422 && in_array($reason($r), ['type', 'corrupt'], true), "$label refused", [$r['status'], $reason($r)]);
}
$r = $send($m, $s['shop'], substr($jpeg, 0, (int) (strlen($jpeg) / 2)));
T::eq([422, 'corrupt'], [$r['status'], $reason($r)], 'cut-off JPEG refused');

T::group('T30 too large, too small, too many pixels');
$r = $send($m, $s['shop'], "\xFF\xD8\xFF\xE0" . random_bytes(Images::MAX_BYTES + 1 - 4));
T::eq([413, 'size'], [$r['status'], $reason($r)], 'over 3 MB refused');
T::eq([422, 'dimensions'], [($r = $send($m, $s['shop'], $draw(150, 150)))['status'], $reason($r)], 'smaller than 200 px refused');
T::eq([422, 'dimensions'], [($r = $send($m, $s['shop'], $draw(6100, 200, 'png'), 'x.png', 'image/png'))['status'], $reason($r)], 'wider than 6000 px refused');

T::group('T31 SVG refused, whatever its name');
foreach (['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', "<?xml version=\"1.0\"?>\n<!DOCTYPE svg>\n<svg xmlns=\"http://www.w3.org/2000/svg\"/>"] as $i => $svg) {
    $r = $send($m, $s['shop'], $svg, 'food.png', 'image/png');
    T::eq([422, 'svg'], [$r['status'], $reason($r)], 'SVG ' . ($i + 1) . ' refused');
}

T::group('T32 polyglot files refused');
$iend = strrpos($png, 'IEND') - 4;
foreach ([
    'JPEG + PHP after the end' => [$jpeg . '<?php system($_GET["c"]); ?>', 'image/jpeg'],
    'JPEG + HTML after the end' => [$jpeg . '<html><body>x</body></html>', 'image/jpeg'],
    'JPEG + ZIP after the end' => [$jpeg . "PK\x03\x04" . random_bytes(64), 'image/jpeg'],
    'JPEG with a script comment' => [substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', 27) . '<script>alert(1)</script>' . substr($jpeg, 2), 'image/jpeg'],
    'PNG with an SVG text chunk' => [substr($png, 0, $iend) . $chunk('tEXt', "Comment\0<svg onload=alert(1)>") . substr($png, $iend), 'image/png'],
    'PNG + ZIP after IEND' => [$png . "PK\x03\x04" . random_bytes(64), 'image/png'],
] as $label => [$bytes, $type]) {
    $r = $send($m, $s['shop'], $bytes, 'x.' . substr($type, 6), $type);
    T::eq([422, 'hidden'], [$r['status'], $reason($r)], "$label refused");
}
T::status(201, $send($m, $s['shop'], $jpeg . str_repeat("\x00", 32)), 'padding after a JPEG is fine');

T::group('T33 only members of the shop upload or use its pictures');
$c = Fixture::customer($base, 'img');
T::status(401, $send($c, $s['shop'], $jpeg), 'customer cannot upload');
T::status(401, $send($anon, $s['shop'], $jpeg), 'nobody signed in cannot upload');
T::status(404, $send($mOther, $s['shop'], $jpeg), 'merchant of another shop cannot upload here');
$theirs = $key($send($mOther, $other['shop'], $jpeg));
T::status(422, $m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$theirs"]), "another shop's upload cannot go on my menu");
T::status(422, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['image_url' => "image:$theirs"]), "nor on my shop");
T::status(422, $m->post("$a/merchant/shops/{$s['shop']}/menus", ['name' => 'x', 'price' => '1', 'image_url' => 'image:' . str_repeat('a', 32)]), 'nor an unknown key on a new menu');
T::status(200, $anon->get("$a/images/$theirs"), 'their file is untouched');
T::status(404, $mOther->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => null]), 'nor can they clear my menu picture');
foreach (['/../../../.env', '/' . str_repeat('a', 32) . '/../../.env', '/' . str_repeat('A', 32), '/' . str_repeat('a', 31) . '.', '/' . str_repeat('a', 32) . '.php'] as $path) {
    $r = $anon->get("$a/images$path");
    T::ok($r['status'] === 404 && !str_contains($r['raw'], 'DB_'), "no file outside the picture folder: $path", $r['status']);
}

T::group('T34 CSRF and rate limit');
$noToken = clone $m;
$noToken->csrf = '';
T::status(403, $send($noToken, $s['shop'], $jpeg), 'upload without CSRF token', 'CSRF_FAILED');
$limited = Servers::start(8341, ['RATE_LIMIT_MULTIPLIER' => '1']);
$slow = Fixture::login($limited, 'merchant', $s['login']);
$codes = [];
for ($i = 0; $i < 61; $i++) {
    $codes[] = $send($slow, $s['shop'], 'not a picture')['status'];
}
T::eq([422, 429], [$codes[59], $codes[60]], '60 uploads an hour, then 429');

T::group('T35 metadata removed, photo turned upright');
// EXIF says "turn 90 degrees clockwise"; a comment carries something private.
$tiff = 'II*' . "\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
$exif = "Exif\0\0" . $tiff . 'SecretCam GPS 13.7563,100.5018';
$withExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . "\xFF\xFE" . pack('n', 18) . 'Owner: SecretCam' . substr($jpeg, 2);
$r = $send($m, $s['shop'], $withExif);
T::status(201, $r, 'photo with EXIF accepted');
$served = $anon->get("$a/images/{$key($r)}")['raw'];
$thumb = $anon->get("$a/images/{$key($r)}/thumb")['raw'];
T::ok(!str_contains($served . $thumb, 'Exif') && !str_contains($served . $thumb, 'SecretCam') && !str_contains($served . $thumb, '13.7563'), 'no EXIF, comment or location in the served files');
T::eq([480, 640], array_slice(getimagesizefromstring($served) ?: [], 0, 2), 'turned upright from the EXIF orientation');

T::group('T36 replaced, removed and unsaved pictures are deleted');
// The server deletes the files; this process must not answer from its stat cache.
$exists = static fn (string $k): bool => clearstatcache() || is_file("$dir/$k.jpg") || is_file("$dir/$k-t.jpg") || (bool) Database::value('SELECT 1 FROM images WHERE id = ?', [$k]);
$k1 = $key($send($m, $s['shop'], $jpeg));
T::status(200, $m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$k1"]), 'picture on a menu');
$k2 = $key($send($m, $s['shop'], $png, 'x.png', 'image/png'));
T::status(200, $m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$k2"]), 'picture replaced');
T::ok(!$exists($k1) && $anon->get("$a/images/$k1")['status'] === 404 && $exists($k2), 'old file, thumbnail and row deleted');
T::status(200, $m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$k2", 'price' => '46']), 'saving the same picture again');
T::ok($exists($k2), 'keeps it');
$m->patch("$a/merchant/menus/{$s['menu2']}", ['image_url' => "image:$k2"]);
$m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => null]);
T::ok($exists($k2), 'still used by another menu: kept');
$m->patch("$a/merchant/menus/{$s['menu2']}", ['image_url' => null]);
T::ok(!$exists($k2), 'removed from the last menu: deleted');
$k3 = $key($send($m, $s['shop'], $jpeg));
T::status(200, $m->patch("$a/merchant/shops/{$s['shop']}/settings", ['image_url' => "image:$k3"]), 'shop picture');
$m->patch("$a/merchant/shops/{$s['shop']}/settings", ['image_url' => 'https://example.com/shop.jpg']);
T::ok(!$exists($k3), 'shop picture replaced by an address: deleted');
$k4 = $key($send($m, $s['shop'], $jpeg));
Database::run('UPDATE images SET created_at = NOW() - INTERVAL 2 DAY WHERE id = ?', [$k4]);
$send($m, $s['shop'], $jpeg);
T::ok(!$exists($k4), 'an upload left unsaved for a day is deleted at the next upload');
$full = Fixture::shop('img_full');
$mFull = Fixture::login($base, 'merchant', $full['login']);
$small = $draw(200, 200);
for ($i = 0, $codes = []; $i < 31; $i++) {
    $codes[] = $send($mFull, $full['shop'], $small)['status'];
}
T::eq([201, 409], [$codes[29], $codes[30]], '30 unsaved uploads per shop, then 409');

T::group('T37 old orders keep working when a menu changes');
$k5 = $key($send($m, $s['shop'], $jpeg));
$m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$k5"]);
[$c, $order] = Fixture::order($base, 'img_old', $s);
$item = static fn (): array => $c->get("$a/orders/$order")['body']['data']['items'][0] ?? [];
T::eq("image:$k5", $item()['image_url'] ?? null, 'order shows the menu picture');
T::status(200, $m->patch("$a/merchant/menus/{$s['menu']}", ['active' => false]), 'menu retired');
$i = $item();
T::ok(array_key_exists('image_url', $i) && $i['image_url'] === null && ($i['art'] ?? '') === 'rice' && str_starts_with((string) ($i['name'] ?? ''), 'ข้าว'), 'retired menu: drawn picture, name from the order', $i);
$m->patch("$a/merchant/menus/{$s['menu']}", ['active' => true]);
$k6 = $key($send($m, $s['shop'], $png, 'x.png', 'image/png'));
$m->patch("$a/merchant/menus/{$s['menu']}", ['image_url' => "image:$k6"]);
T::ok(($item()['image_url'] ?? null) === "image:$k6" && !$exists($k5), 'picture changed: the order shows the new one, the old file is gone');
T::eq(200, $c->get("$a/orders")['status'], 'history still loads');
$mi = $m->get("$a/merchant/shops/{$s['shop']}/orders")['body']['data'][0]['items'][0] ?? [];
T::eq(['image:' . $k6, 'rice'], [$mi['image_url'] ?? null, $mi['art'] ?? null], 'the shop board gets the picture too');

T::group('T38 drawn picture chosen by the shop');
$menuOf = static function (Client $who, string $path) use ($s): array {
    foreach ($who->get($path)['body']['data']['menus'] as $x) {
        if ((int) $x['id'] === $s['menu2']) {
            return $x;
        }
    }
    return [];
};
T::eq('drink', $menuOf($anon, "$a/shops/{$s['shop']}/menus")['art'] ?? null, 'guessed from the name (น้ำ)');
T::status(200, $m->patch("$a/merchant/menus/{$s['menu2']}", ['art' => 'dessert']), 'shop chooses one');
T::eq(['dessert', 'dessert'], [$menuOf($m, "$a/merchant/shops/{$s['shop']}/menus")['art_choice'] ?? null, $menuOf($anon, "$a/shops/{$s['shop']}/menus")['art'] ?? null], 'saved and shown');
T::ok(!array_key_exists('art_choice', $menuOf($anon, "$a/shops/{$s['shop']}/menus")), 'customers only get the picture to draw');
T::status(422, $m->patch("$a/merchant/menus/{$s['menu2']}", ['art' => 'pizza']), 'unknown kind refused');
$m->patch("$a/merchant/menus/{$s['menu2']}", ['art' => null]);
T::eq('drink', $menuOf($anon, "$a/shops/{$s['shop']}/menus")['art'] ?? null, 'back to the guess');
unlink($tmp);
