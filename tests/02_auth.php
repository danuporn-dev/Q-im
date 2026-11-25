<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Env;

T::group('Phase 2 auth, sessions, RBAC');
$a = '/api/v1';
$pw = Env::get('SEED_DEMO_PASSWORD');
$shop = Fixture::shop('auth');

$anon = new Client($base);
T::status(401, $anon->get("$a/me"), 'anonymous /me', 'UNAUTHENTICATED');
T::status(401, $anon->get("$a/me?realm=admin"), 'anonymous admin /me');
T::status(415, $anon->post("$a/auth/dev", 'customer=A', ['Content-Type: application/x-www-form-urlencoded']), 'form post refused', 'JSON_REQUIRED');
T::status(403, $anon->post("$a/auth/dev", ['customer' => 'A'], ['Origin: https://evil.example']), 'cross-origin login refused', 'CROSS_ORIGIN');
T::status(401, $anon->post("$a/auth/dev", ['customer' => 'bad id!']), 'bad mock identity');
T::eq([], $anon->cookies, 'no session after failed login');

$c = new Client($base);
$r = $c->post("$a/auth/dev", ['customer' => 'CUSTOMER_1']);
T::status(200, $r, 'dev login');
T::ok((bool) preg_match('/^Set-Cookie: qim_customer=.*HttpOnly.*SameSite=Lax/mi', $r['head']), 'cookie flags', $r['head']);
$c->csrf = $r['body']['data']['csrf'];
$me = $c->get("$a/me");
T::eq(Database::value("SELECT id FROM users WHERE line_user_id = 'DEMO_CUSTOMER_1'"), $me['body']['data']['id'] ?? null, 'correct account');
$before = $c->cookies['qim_customer'];
$c->post("$a/auth/dev", ['customer' => 'CUSTOMER_1']);
T::ok($before !== $c->cookies['qim_customer'], 'session id regenerated on login');
$old = new Client($base);
$old->cookies = ['qim_customer' => $before];
T::status(401, $old->get("$a/me"), 'old session id is dead');
$fixed = new Client($base);
$fixed->cookies = ['qim_customer' => 'attackerchosenid0000000000'];
$fixed->post("$a/auth/dev", ['customer' => 'CUSTOMER_2']);
T::ok($fixed->cookies['qim_customer'] !== 'attackerchosenid0000000000', 'session fixation refused');

T::status(401, $anon->post("$a/auth/login", ['realm' => 'merchant', 'login' => $shop['login'], 'password' => 'wrong-password']), 'wrong password', 'LOGIN_FAILED');
T::status(401, $anon->post("$a/auth/login", ['realm' => 'admin', 'login' => $shop['login'], 'password' => $pw]), 'merchant cannot use admin realm', 'LOGIN_FAILED');
T::status(422, $anon->post("$a/auth/login", ['realm' => 'customer', 'login' => $shop['login'], 'password' => $pw]), 'client cannot choose another realm');
T::status(401, $anon->post("$a/auth/login", ['realm' => 'merchant', 'login' => "' OR 1=1 -- ", 'password' => "' OR '1'='1"]), 'SQL injection login');

$m = Fixture::login($base, 'merchant', $shop['login']);
$me = $m->get("$a/me?realm=merchant");
T::eq([$shop['shop']], array_column($me['body']['data']['shops'] ?? [], 'id'), 'merchant sees only own shops');
T::status(401, $m->get("$a/me?realm=admin"), 'merchant session has no admin realm');
$swap = new Client($base);
$swap->cookies = ['qim_admin' => $m->cookies['qim_merchant']];
T::status(401, $swap->get("$a/me?realm=admin"), 'merchant session id in admin cookie refused');

$token = $m->csrf;
$m->csrf = '';
T::status(403, $m->post("$a/auth/logout?realm=merchant"), 'CSRF token required', 'CSRF_FAILED');
$m->csrf = $token;
T::status(403, $m->post("$a/auth/logout?realm=merchant", [], ['Origin: https://evil.example']), 'cross-origin mutation refused', 'CROSS_ORIGIN');

Database::update('users', $shop['user'], ['active' => 0]);
T::status(401, $m->get("$a/me?realm=merchant"), 'deactivated user loses access at once');
Database::update('users', $shop['user'], ['active' => 1]);
T::status(200, $m->get("$a/me?realm=merchant"), 'reactivated');
$hash = Database::value('SELECT password_hash FROM merchant_accounts WHERE user_id = ?', [$shop['user']]);
Database::run('UPDATE merchant_accounts SET password_hash = ? WHERE user_id = ?', [password_hash('another-password-1', PASSWORD_BCRYPT, ['cost' => 4]), $shop['user']]);
T::status(401, $m->get("$a/me?realm=merchant"), 'password change ends sessions');
Database::run('UPDATE merchant_accounts SET password_hash = ? WHERE user_id = ?', [$hash, $shop['user']]);
T::status(200, $m->post("$a/auth/logout?realm=merchant"), 'logout');
T::status(401, $m->get("$a/me?realm=merchant"), 'after logout');

$prod = Servers::start(8112, ['APP_ENV' => 'production']);
$p = new Client($prod);
T::status(404, $p->post("$a/auth/dev", ['customer' => 'CUSTOMER_1']), 'mock identity refused in production');
T::eq(false, $p->get("$a/config")['body']['data']['dev_login'] ?? null, 'dev login hidden in production');
$r = $p->post("$a/auth/login", ['realm' => 'admin', 'login' => 'demo_admin', 'password' => $pw]);
T::ok((bool) preg_match('/^Set-Cookie: qim_admin=.*secure/mi', $r['head']), 'Secure cookie in production');
$unset = Servers::start(8113, ['APP_ENV' => 'staging']);
T::status(404, (new Client($unset))->post("$a/auth/dev", ['customer' => 'CUSTOMER_1']), 'mock identity refused in any other environment');

$limited = Servers::start(8114, ['RATE_LIMIT_MULTIPLIER' => '1']);
$l = new Client($limited);
$name = 'nobody_' . Fixture::run();
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $codes[] = $l->post("$a/auth/login", ['realm' => 'merchant', 'login' => $name, 'password' => 'x'])['status'];
}
T::eq(429, end($codes), 'login rate limit');
T::eq(401, $codes[9], 'below the limit is a normal failure');
