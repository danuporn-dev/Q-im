<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Identity\DevMockIdentity;
use App\Identity\IdentityProvider;
use App\Services\Auth;
use App\Services\DemoMessages;
use App\Support\Database;
use App\Support\Env;
use App\Support\HttpError;
use App\Support\Input;
use App\Support\RateLimiter;
use App\Support\Request;
use App\Support\Session;

final class AuthController
{
    public static function config(Request $q): array
    {
        return [[
            'liff_id' => Env::get('LIFF_ID') ?: null,
            'dev_login' => Env::isDevelopment(),
            'demo_customers' => Env::isDevelopment() ? DevMockIdentity::CUSTOMERS : null,
            'demo_code_required' => Env::isDevelopment() && trim(Env::get('DEMO_ACCESS_CODE')) !== '',
            'poll_seconds' => self::pollSeconds(),
        ], 200];
    }

    /** Null (unset or not a whole number from 2 to 60) keeps the intervals built into the pages. */
    private static function pollSeconds(): ?int
    {
        $value = filter_var(Env::get('POLL_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 60]]);
        return $value === false ? null : $value;
    }

    public static function dev(Request $q): array
    {
        // Checked before anything else: this route does not exist outside development.
        if (!Env::isDevelopment()) {
            throw HttpError::notFound('ไม่พบเส้นทางที่ร้องขอ');
        }
        Auth::checkJson($q);
        self::checkDemoCode($q);
        return self::customer($q, new DevMockIdentity(), 'customer');
    }

    /** DEMO_ACCESS_CODE keeps strangers out of a demo that is open to the internet. Never log the submitted value. */
    private static function checkDemoCode(Request $q): void
    {
        $code = trim(Env::get('DEMO_ACCESS_CODE'));
        if ($code === '') {
            return;
        }
        $given = $q->json()['access_code'] ?? '';
        if (!is_string($given) || $given === '') {
            throw new HttpError(403, 'DEMO_CODE_REQUIRED', 'กรุณากรอกรหัสเข้าชมการสาธิต');
        }
        RateLimiter::hit('demo-code', $q->ip(), 20, 900);
        if (!hash_equals(hash('sha256', $code), hash('sha256', $given))) {
            throw new HttpError(403, 'DEMO_CODE_WRONG', 'รหัสเข้าชมการสาธิตไม่ถูกต้อง');
        }
        RateLimiter::refund('demo-code', $q->ip(), 900);
    }

    /** Simulated LINE chat of the signed-in customer. This route does not exist outside development either. */
    public static function devMessages(Request $q): array
    {
        if (!Env::isDevelopment()) {
            throw HttpError::notFound('ไม่พบเส้นทางที่ร้องขอ');
        }
        return [DemoMessages::ofCustomer(Auth::user($q, 'customer')['id']), 200];
    }

    public static function customer(Request $q, IdentityProvider $provider, string $field): array
    {
        Auth::checkJson($q);
        // A whole campus can share one address, so a signed-in customer is counted alone.
        $known = Auth::sessionUserId('customer');
        $known === null ? RateLimiter::hit('auth-ip', $q->ip(), 600, 60) : RateLimiter::hit('auth-user', (string) $known, 30, 60);
        $credential = Input::text($q->json(), $field, 'ข้อมูลยืนยันตัวตน', 4096);
        $session = Auth::loginCustomer($provider->verify($credential));
        return [['csrf' => $session['csrf']], 200];
    }

    public static function login(Request $q): array
    {
        Auth::checkJson($q);
        $in = $q->json();
        $realm = $in['realm'] ?? '';
        if (!in_array($realm, ['merchant', 'admin'], true)) {
            throw HttpError::invalid('ประเภทผู้ใช้ไม่ถูกต้อง');
        }
        $login = Input::text($in, 'login', 'ชื่อผู้ใช้', 100);
        $password = $in['password'] ?? '';
        if (!is_string($password) || $password === '' || strlen($password) > 200) {
            throw HttpError::invalid('กรุณากรอกรหัสผ่าน');
        }
        // Account + address, so one person's mistakes never block colleagues on the same network.
        // The wide per-address limit only stops one address from trying many accounts.
        $subject = $q->ip() . '|' . $realm . '|' . mb_strtolower($login);
        RateLimiter::hit('login-spray', $q->ip(), 300, 900);
        RateLimiter::hit('login', $subject, 10, 900);
        $session = Auth::loginStaff($realm, $login, $password);
        RateLimiter::clear('login', $subject);
        return [['csrf' => $session['csrf']], 200];
    }

    public static function logout(Request $q): array
    {
        $realm = self::realm($q);
        Auth::user($q, $realm);
        Session::destroy($realm);
        return [['ok' => true], 200];
    }

    public static function me(Request $q): array
    {
        $realm = self::realm($q);
        $user = Auth::user($q, $realm);
        $data = ['id' => $user['id'], 'display_name' => $user['display_name'], 'avatar_url' => $user['avatar_url'], 'realm' => $realm, 'csrf' => $user['csrf']];
        if ($realm === 'merchant') {
            $data['shops'] = Database::rows(
                'SELECT s.id, s.name FROM shops s JOIN shop_members m ON m.shop_id = s.id WHERE m.user_id = ? ORDER BY s.name',
                [$user['id']]
            );
        }
        return [$data, 200];
    }

    private static function realm(Request $q): string
    {
        $realm = $q->query('realm', 'customer');
        if (!in_array($realm, Session::REALMS, true)) {
            throw HttpError::invalid('ประเภทผู้ใช้ไม่ถูกต้อง');
        }
        return $realm;
    }
}
