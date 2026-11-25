<?php
declare(strict_types=1);

/** Drives an installed Chrome or Edge through the DevTools protocol. No packages needed. */
final class Browser
{
    /** @var resource */
    private $process;
    /** @var resource */
    private $socket;
    private int $id = 0;
    private string $buffer = '';
    /** @var list<string> */
    public array $errors = [];

    public static function find(): ?string
    {
        $candidates = [
            (string) getenv('CHROME_PATH'),
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
            '/usr/bin/google-chrome', '/usr/bin/chromium',
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public function __construct(string $binary, private int $port)
    {
        if (@fsockopen('127.0.0.1', $port, $no, $err, 0.3)) {
            throw new RuntimeException("Port $port is already in use.");
        }
        $profile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qim_browser_' . bin2hex(random_bytes(4));
        $null = ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'];
        $this->process = proc_open(
            [$binary, '--headless=new', "--remote-debugging-port=$port", "--user-data-dir=$profile", '--no-first-run', '--disable-gpu', '--disable-extensions', 'about:blank'],
            [1 => $null, 2 => $null], $pipes, null, null, ['bypass_shell' => true]
        );
        for ($i = 0; $i < 100; $i++) {
            $info = @file_get_contents("http://127.0.0.1:$port/json/list");
            foreach ($info ? json_decode($info, true) : [] as $target) {
                if ($target['type'] === 'page') {
                    $this->connect($target['webSocketDebuggerUrl']);
                    return;
                }
            }
            usleep(100000);
        }
        throw new RuntimeException('Browser did not start.');
    }

    private function connect(string $url): void
    {
        $this->socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $no, $err, 5) ?: throw new RuntimeException('No DevTools connection.');
        fwrite($this->socket, 'GET ' . parse_url($url, PHP_URL_PATH) . " HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
        while (($line = fgets($this->socket)) !== false && rtrim($line) !== '') {
            // skip the handshake headers
        }
        stream_set_blocking($this->socket, false);
        foreach (['Page', 'Runtime', 'Network', 'Log'] as $domain) {
            $this->send("$domain.enable");
        }
        $this->send('Emulation.setFocusEmulationEnabled', ['enabled' => true]);
    }

    public function send(string $method, array $params = []): array
    {
        $id = ++$this->id;
        $data = json_encode(['id' => $id, 'method' => $method, 'params' => $params ?: new stdClass()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $length = strlen($data);
        $head = "\x81" . ($length < 126 ? chr(0x80 | $length) : ($length < 65536 ? chr(0xFE) . pack('n', $length) : chr(0xFF) . pack('J', $length)));
        $mask = random_bytes(4);
        stream_set_blocking($this->socket, true);
        fwrite($this->socket, $head . $mask . ($data ^ str_pad('', $length, $mask)));
        stream_set_blocking($this->socket, false);
        $until = microtime(true) + 20;
        while (microtime(true) < $until) {
            $message = $this->receive();
            if ($message === null) {
                usleep(5000);
            } elseif (($message['id'] ?? 0) === $id) {
                return $message['result'] ?? throw new RuntimeException($method . ': ' . json_encode($message['error'] ?? null));
            }
        }
        throw new RuntimeException("$method timed out");
    }

    private function receive(): ?array
    {
        $this->buffer .= (string) stream_get_contents($this->socket);
        $text = '';
        $at = 0;
        do {
            if (strlen($this->buffer) < $at + 2) {
                return null;
            }
            $final = ord($this->buffer[$at]) & 0x80;
            $length = ord($this->buffer[$at + 1]) & 0x7F;
            $extra = [126 => 2, 127 => 8][$length] ?? 0;
            if (strlen($this->buffer) < $at + 2 + $extra) {
                return null;
            }
            if ($extra > 0) {
                $length = unpack($extra === 2 ? 'n' : 'J', substr($this->buffer, $at + 2, $extra))[1];
            }
            if (strlen($this->buffer) < $at + 2 + $extra + $length) {
                return null;
            }
            $text .= substr($this->buffer, $at + 2 + $extra, $length);
            $at += 2 + $extra + $length;
        } while (!$final);
        $this->buffer = substr($this->buffer, $at);
        $message = json_decode($text, true) ?? [];
        if (($message['method'] ?? '') === 'Runtime.exceptionThrown') {
            $this->errors[] = 'exception: ' . ($message['params']['exceptionDetails']['exception']['description'] ?? '?');
        }
        if (($message['method'] ?? '') === 'Page.javascriptDialogOpening') {
            $this->errors[] = 'dialog: ' . $message['params']['message'];
        }
        if (($message['method'] ?? '') === 'Log.entryAdded' && ($message['params']['entry']['source'] ?? '') === 'security') {
            $this->errors[] = 'security: ' . $message['params']['entry']['text'];
        }
        return $message;
    }

    public function js(string $expression): mixed
    {
        $r = $this->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true, 'awaitPromise' => true]);
        if (isset($r['exceptionDetails'])) {
            throw new RuntimeException("$expression: " . ($r['exceptionDetails']['exception']['description'] ?? '?'));
        }
        return $r['result']['value'] ?? null;
    }

    public function open(string $url): void
    {
        $this->send('Page.navigate', ['url' => 'about:blank']);
        $this->send('Page.navigate', ['url' => $url]);
    }

    public function size(int $width, int $height = 800): void
    {
        $this->send('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => $width < 700]);
    }

    public function network(bool $offline, int $latency = 0, int $bytesPerSecond = -1): void
    {
        $this->send('Network.emulateNetworkConditions', ['offline' => $offline, 'latency' => $latency, 'downloadThroughput' => $bytesPerSecond, 'uploadThroughput' => $bytesPerSecond]);
    }

    public function until(string $condition, float $seconds = 12): bool
    {
        $end = microtime(true) + $seconds;
        do {
            try {
                if ($this->js("Boolean($condition)") === true) {
                    return true;
                }
            } catch (RuntimeException) {
                // the page is still changing
            }
            usleep(80000);
        } while (microtime(true) < $end);
        return false;
    }

    public function sees(string $text, float $seconds = 12): bool
    {
        return $this->until('document.body.innerText.includes(' . json_encode($text, JSON_UNESCAPED_UNICODE) . ')', $seconds);
    }

    /** Clicks the first visible, enabled control whose text starts with, or else contains, $text. */
    public function click(string $text, string $root = 'document'): void
    {
        $t = json_encode($text, JSON_UNESCAPED_UNICODE);
        $find = "[...($root).querySelectorAll('button,a,label,summary')].filter(e=>e.offsetParent!==null&&!e.disabled&&e.innerText.includes($t)).sort((a,b)=>b.innerText.trim().startsWith($t)-a.innerText.trim().startsWith($t))";
        if (!$this->until("$find.length")) {
            throw new RuntimeException("No control \"$text\" on: " . mb_substr((string) $this->js('document.body.innerText'), 0, 300));
        }
        $this->js("({$find})[0].click()");
    }

    public function fill(string $selector, string $value): void
    {
        $s = json_encode($selector);
        $this->until("document.querySelector($s)");
        $this->js("{const e=document.querySelector($s);e.value=" . json_encode($value, JSON_UNESCAPED_UNICODE) . ";e.dispatchEvent(new Event('input',{bubbles:true}));}");
    }

    public function overflow(): int
    {
        return (int) $this->js('document.documentElement.scrollWidth-document.documentElement.clientWidth');
    }

    public function close(): void
    {
        try {
            $this->send('Browser.close');
        } catch (Throwable) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
    }
}
