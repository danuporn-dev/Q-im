<?php
declare(strict_types=1);

// Test toolkit: real PHP servers on local ports, an HTTP client with its own cookies, assertions, fixtures.

use App\Support\Database;
use App\Support\Env;

require dirname(__DIR__) . '/app/bootstrap.php';

if (!Env::isDevelopment() || Database::name() !== 'qim_dev') {
    fwrite(STDERR, "Tests run only with APP_ENV=development on qim_dev.\n");
    exit(1);
}

final class T
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failed = [];
    /** @var array<string, bool> */
    public static array $ids = [];
    public static string $current = '';

    public static function group(string $name): void
    {
        self::$current = $name;
        echo "\n# $name\n";
        preg_match_all('/T\d\d/', $name, $m);
        foreach ($m[0] as $id) {
            self::$ids[$id] ??= true;
        }
    }

    public static function ok(bool $condition, string $label, mixed $detail = null): void
    {
        if ($condition) {
            self::$passed++;
            echo "  ok   $label\n";
            return;
        }
        self::$failed[] = self::$current . ': ' . $label;
        preg_match_all('/T\d\d/', self::$current, $m);
        foreach ($m[0] as $id) {
            self::$ids[$id] = false;
        }
        echo "  FAIL $label" . ($detail !== null ? ' :: ' . mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 600) : '') . "\n";
    }

    public static function eq(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label, ['expected' => $expected, 'actual' => $actual]);
    }

    public static function status(int $expected, array $response, string $label, ?string $code = null): void
    {
        $ok = $response['status'] === $expected && ($code === null || ($response['body']['error']['code'] ?? null) === $code);
        self::ok($ok, $label . " [$expected" . ($code ? " $code" : '') . ']', ['status' => $response['status'], 'body' => $response['body'] ?? substr($response['raw'], 0, 300)]);
    }
}

final class Servers
{
    /** @var array<int, resource> */
    private static array $processes = [];

    public static function start(int $port, array $env = [], ?string $root = null, ?string $router = null): string
    {
        if (@fsockopen('127.0.0.1', $port, $no, $err, 0.3)) {
            throw new RuntimeException("Port $port is already in use.");
        }
        $command = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root ?? dirname(__DIR__) . '/public'];
        if ($router !== null) {
            $command[] = $router;
        }
        $null = ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'];
        $process = proc_open($command, [1 => $null, 2 => $null], $pipes, dirname(__DIR__), $env + getenv(), ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP server.');
        }
        self::$processes[$port] = $process;
        for ($i = 0; $i < 100; $i++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $no, $err, 0.2)) {
                fclose($socket);
                return "http://127.0.0.1:$port";
            }
            usleep(50000);
        }
        throw new RuntimeException("Server on $port did not start.");
    }

    public static function stopAll(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$processes = [];
    }
}
register_shutdown_function([Servers::class, 'stopAll']);

final class Client
{
    /** @var array<string, string> */
    public array $cookies = [];
    public string $csrf = '';

    public function __construct(public string $base)
    {
    }

    public function handle(string $method, string $path, mixed $body = null, array $headers = [], ?string $base = null): CurlHandle
    {
        $h = array_merge(['Accept: application/json'], $headers);
        if ($this->csrf !== '') {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($this->cookies !== []) {
            $h[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $curl = curl_init(($base ?? $this->base) . $path);
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60];
        if ($method !== 'GET') {
            if (is_array($body) && isset($body['__multipart'])) {
                $options[CURLOPT_POSTFIELDS] = $body['__multipart'];
            } else {
                $options[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body ?? new stdClass(), JSON_UNESCAPED_UNICODE);
                if (!array_filter($headers, static fn ($x) => stripos($x, 'content-type') === 0)) {
                    $h[] = 'Content-Type: application/json';
                }
            }
        }
        $options[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($curl, $options);
        return $curl;
    }

    public function parse(CurlHandle $curl, string|bool $out): array
    {
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $head = substr((string) $out, 0, $size);
        $raw = substr((string) $out, $size);
        preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/mi', $head, $m, PREG_SET_ORDER);
        foreach ($m as [, $name, $value]) {
            if ($value === '' || $value === 'deleted') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'head' => $head, 'raw' => $raw, 'body' => json_decode($raw, true)];
    }

    public function call(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $curl = $this->handle($method, $path, $body, $headers);
        return $this->parse($curl, curl_exec($curl));
    }

    public function get(string $path): array
    {
        return $this->call('GET', $path);
    }

    public function post(string $path, mixed $body = null, array $headers = []): array
    {
        return $this->call('POST', $path, $body, $headers);
    }

    public function patch(string $path, mixed $body): array
    {
        return $this->call('PATCH', $path, $body);
    }

    public function put(string $path, mixed $body): array
    {
        return $this->call('PUT', $path, $body);
    }
}

/**
 * Sends requests at the same moment; each entry is [Client, method, path, body, base].
 *
 * @return list<array>
 */
function parallel(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as [$client, $method, $path, $body, $base]) {
        $handles[] = $handle = $client->handle($method, $path, $body, [], $base);
        curl_multi_add_handle($multi, $handle);
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running);
    $results = [];
    foreach ($handles as $i => $handle) {
        $results[] = $requests[$i][0]->parse($handle, curl_multi_getcontent($handle));
        curl_multi_remove_handle($multi, $handle);
    }
    return $results;
}
