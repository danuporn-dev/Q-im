<?php
declare(strict_types=1);

namespace App\Support;

/** Small outbound HTTP client. TLS verification stays on. */
final class HttpClient
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string} status 0 = network failure or timeout
     */
    public static function post(string $url, string $body, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $result = curl_exec($curl);
        return ['status' => $result === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => is_string($result) ? $result : ''];
    }

    /** LINE API base URL; the override is a test hook that only works in development. */
    public static function lineBase(): string
    {
        $override = Env::isDevelopment() ? Env::get('LINE_API_BASE') : '';
        return $override !== '' ? rtrim($override, '/') : 'https://api.line.me';
    }
}
