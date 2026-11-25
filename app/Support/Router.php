<?php
declare(strict_types=1);

namespace App\Support;

final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    /** {id} matches a positive integer, {key} a hex string. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = strtr($pattern, ['{id}' => '([1-9][0-9]{0,18})', '{sub}' => '([1-9][0-9]{0,18})', '{key}' => '([0-9a-f]{32})']);
        $this->routes[] = [$method, "#^$regex$#D", $handler];
    }

    /** @return array{0: mixed, 1: int}|null data and status, or null when the handler wrote the response itself */
    public function dispatch(Request $request): ?array
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed = true;
                continue;
            }
            $args = array_map(static fn (string $v): int|string => ctype_digit($v) && strlen($v) < 19 ? (int) $v : $v, array_slice($m, 1));
            return $handler($request, ...$args);
        }
        throw $allowed
            ? new HttpError(405, 'METHOD_NOT_ALLOWED', 'ไม่รองรับคำขอนี้')
            : HttpError::notFound('ไม่พบเส้นทางที่ร้องขอ');
    }
}
