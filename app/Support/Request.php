<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    private ?array $json = null;
    private ?string $raw = null;

    private function __construct(public readonly string $method, public readonly string $path)
    {
    }

    public static function capture(): self
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $at = strpos($path, '/api/v1');
        $path = $at === false ? '/' : substr($path, $at + 7);
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', '/' . trim($path, '/'));
    }

    public function header(string $name): string
    {
        return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    }

    public function raw(): string
    {
        return $this->raw ??= (string) file_get_contents('php://input');
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (strlen($this->raw()) > 262144) {
            throw new HttpError(413, 'TOO_LARGE', 'ข้อมูลมีขนาดใหญ่เกินไป');
        }
        if (trim($this->raw()) === '') {
            return $this->json = [];
        }
        $data = json_decode($this->raw(), true, 16);
        if (!is_array($data)) {
            throw new HttpError(400, 'BAD_JSON', 'รูปแบบข้อมูลไม่ถูกต้อง');
        }
        return $this->json = $data;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? trim($value) : $default;
    }

    /** @return array{0: int, 1: int, 2: int} page, per page, offset */
    public function page(int $perPage = 20): array
    {
        $page = max(1, min(10000, (int) $this->query('page', '1')));
        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /** For rate limits. One IPv6 client owns a whole /64, so the rest of the address is dropped. */
    public function ip(): string
    {
        $ip = Proxy::clientIp();
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
            ? (string) inet_ntop(substr($packed, 12))
            : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    public function isMutation(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
