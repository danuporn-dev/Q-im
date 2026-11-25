<?php
declare(strict_types=1);

// The demo behind an HTTPS reverse proxy such as ngrok. Requests carry simulated proxy headers.

$a = '/api/v1';
$login = static fn (string $base, array $headers, ?Client $c = null): array => ($c ?? new Client($base))->post("$a/auth/dev", ['customer' => 'CUSTOMER_1'], $headers);
$secure = static fn (array $r): bool => (bool) preg_match('/^Set-Cookie: qim_customer=(?!deleted)[^\r\n]*;\s*secure/mi', $r['head']);
$ngrok = ['X-Forwarded-Proto: https', 'X-Forwarded-Host: demo.ngrok.example', 'X-Forwarded-For: 203.0.113.7'];

T::group('Trusted proxy headers');
$trusted = Servers::start(8281, ['TRUSTED_PROXY' => '::1, 127.0.0.1']);
$r = $login($trusted, [...$ngrok, 'Origin: https://demo.ngrok.example']);
T::status(200, $r, 'sign-in through the proxy');
T::ok($secure($r) && stripos($r['head'], 'httponly') !== false && stripos($r['head'], 'samesite=lax') !== false, 'cookie is Secure, HttpOnly, SameSite=Lax', $r['head']);
$c = new Client($trusted);
$c->csrf = $login($trusted, [...$ngrok, 'Origin: https://demo.ngrok.example'], $c)['body']['data']['csrf'];
T::status(200, $c->call('PUT', "$a/cart", ['items' => []], [...$ngrok, 'Origin: https://demo.ngrok.example']), 'mutation with the public origin');
foreach (['https://evil.example', 'http://demo.ngrok.example', 'https://demo.ngrok.example:8443', 'null', 'http://127.0.0.1:8281'] as $origin) {
    T::status(403, $c->call('PUT', "$a/cart", ['items' => []], [...$ngrok, "Origin: $origin"]), "refused origin $origin", 'CROSS_ORIGIN');
}
T::status(403, $c->call('PUT', "$a/cart", ['items' => []], ['X-Forwarded-Host: x/<script>', 'Origin: https://x']), 'malformed forwarded host is not used', 'CROSS_ORIGIN');
T::status(200, $c->call('PUT', "$a/cart", ['items' => []], ['X-Forwarded-Proto: https', 'X-Forwarded-Host: evil.example, demo.ngrok.example', 'Origin: https://demo.ngrok.example']), 'only the last value, written by the proxy, counts');
$csrf = $c->csrf;
$c->csrf = '';
T::status(403, $c->call('PUT', "$a/cart", ['items' => []], [...$ngrok, 'Origin: https://demo.ngrok.example']), 'CSRF token still required', 'CSRF_FAILED');
$c->csrf = $csrf;
T::ok($secure($login($trusted, ['X-Forwarded-Proto: http, https'])) && !$secure($login($trusted, ['X-Forwarded-Proto: https, http'])), 'last forwarded scheme decides');
T::ok(!$secure($login($trusted, [])), 'plain local request keeps working without Secure');

T::group('Untrusted proxy headers are ignored');
foreach (['' => 8282, '10.0.0.9' => 8283, 'nonsense,*,0.0.0.0/0' => 8284] as $list => $port) {
    $other = Servers::start($port, ['TRUSTED_PROXY' => (string) $list]);
    T::ok(!$secure($login($other, $ngrok)), "TRUSTED_PROXY=\"$list\": scheme ignored");
    T::status(403, $login($other, [...$ngrok, 'Origin: https://demo.ngrok.example']), "\"$list\": host ignored", 'CROSS_ORIGIN');
}

T::group('Client address for rate limits');
$ip = static function (string $remote, array $server, string $list): string {
    $old = $_SERVER;
    putenv("TRUSTED_PROXY=$list");
    $_SERVER = ['REMOTE_ADDR' => $remote] + $server + $_SERVER;
    $result = App\Support\Request::capture()->ip();
    $_SERVER = $old;
    putenv('TRUSTED_PROXY');
    return $result;
};
$for = static fn (string $v): array => ['HTTP_X_FORWARDED_FOR' => $v];
T::eq('203.0.113.7', $ip('127.0.0.1', $for('203.0.113.7'), '127.0.0.1'), 'trusted: forwarded address');
T::eq('203.0.113.7', $ip('127.0.0.1', $for('1.1.1.1, 203.0.113.7'), '127.0.0.1'), 'trusted: an address the client invented is ignored');
T::eq('203.0.113.7', $ip('127.0.0.1', $for('203.0.113.7, ::1'), '127.0.0.1,::1'), 'trusted: own proxies are skipped');
T::eq('127.0.0.1', $ip('127.0.0.1', $for('203.0.113.7, junk'), '127.0.0.1'), 'trusted: malformed');
T::eq('127.0.0.1', $ip('127.0.0.1', [], '127.0.0.1'), 'trusted: no header');
T::eq('198.51.100.1', $ip('198.51.100.1', $for('203.0.113.7'), '127.0.0.1'), 'untrusted: header ignored');
T::eq('198.51.100.1', $ip('198.51.100.1', $for('203.0.113.7'), ''), 'nothing trusted: header ignored');
T::eq('2001:db8:1:2::/64', $ip('2001:db8:1:2:3:4:5:6', [], ''), 'IPv6 is counted per /64');
T::eq('203.0.113.7', $ip('::ffff:203.0.113.7', [], ''), 'IPv4 in IPv6 notation is the same client');

T::group('No absolute local addresses in the pages');
$bad = [];
foreach ([...glob(dirname(__DIR__) . '/public/*/index.html'), ...glob(dirname(__DIR__) . '/public/assets/*/*.{js,css}', GLOB_BRACE)] as $file) {
    if (preg_match('#localhost|127\.0\.0\.1|http://(?!www\.w3\.org)#', (string) file_get_contents($file))) {
        $bad[] = basename($file);
    }
}
T::eq([], $bad, 'pages, scripts and styles use relative addresses');
$r = (new Client($trusted))->call('GET', '/?x=1', null, $ngrok);
T::ok($r['status'] === 302 && preg_match('#^Location: customer/\?x=1\r?$#mi', $r['head']) === 1, 'root redirect is relative', $r['head']);
