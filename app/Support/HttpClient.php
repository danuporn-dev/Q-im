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
        return self::request('POST', $url, $body, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string} status 0 = network failure or timeout
     */
    public static function request(string $method, string $url, ?string $body, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]));
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
