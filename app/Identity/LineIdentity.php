<?php
declare(strict_types=1);

namespace App\Identity;

use App\Support\Env;
use App\Support\HttpClient;
use App\Support\HttpError;

/** Verifies a LINE Login ID token with LINE, then checks the claims again locally. */
final class LineIdentity implements IdentityProvider
{
    public function verify(string $credential): Identity
    {
        $channel = Env::get('LINE_LOGIN_CHANNEL_ID');
        if ($channel === '') {
            throw new HttpError(503, 'LINE_NOT_CONFIGURED', 'ยังไม่ได้ตั้งค่า LINE Login');
        }
        $response = HttpClient::post(
            HttpClient::lineBase() . '/oauth2/v2.1/verify',
            http_build_query(['id_token' => $credential, 'client_id' => $channel]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        if ($response['status'] === 0 || $response['status'] >= 500 || $response['status'] === 429) {
            throw new HttpError(503, 'LINE_UNAVAILABLE', 'เชื่อมต่อ LINE ไม่ได้ กรุณาลองใหม่');
        }
        $claims = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($claims)
            || ($claims['iss'] ?? '') !== 'https://access.line.me'
            || !hash_equals($channel, (string) ($claims['aud'] ?? ''))
            || (int) ($claims['exp'] ?? 0) <= time()
            || !preg_match('/^U[0-9a-f]{32}$/D', (string) ($claims['sub'] ?? ''))) {
            throw new HttpError(401, 'INVALID_IDENTITY', 'ไม่สามารถยืนยันตัวตนกับ LINE ได้ กรุณาเข้าสู่ระบบใหม่');
        }
        $picture = $claims['picture'] ?? null;
        return new Identity(
            $claims['sub'],
            is_string($claims['name'] ?? null) && trim($claims['name']) !== '' ? $claims['name'] : 'ลูกค้า LINE',
            is_string($picture) && strlen($picture) <= 500 && str_starts_with($picture, 'https://') ? $picture : null
        );
    }
}
