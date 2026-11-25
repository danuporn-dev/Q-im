<?php
declare(strict_types=1);

use App\Support\Clock;
use App\Support\Money;

T::group('Phase 1 foundation');
$c = new Client($base);
T::status(200, $c->get('/api/v1/health'), 'health');
T::status(404, $c->get('/api/v1/nope'), 'unknown route', 'NOT_FOUND');
T::status(405, $c->post('/api/v1/health'), 'wrong method', 'METHOD_NOT_ALLOWED');
foreach (['/.env', '/../.env', '/..%2f.env', '/../config/database.php'] as $path) {
    T::ok(!str_contains($c->get($path)['raw'], 'DB_PASSWORD'), "secret not served: $path");
}
T::eq('-12.05', Money::fromSatang(Money::toSatang('-12.05')), 'money negative');
T::eq(4550, Money::toSatang('45.5'), 'money pad');
T::eq(4500, Money::toSatang('45'), 'money whole');
T::eq(['2026-01-01 17:00:00', '2026-01-02 17:00:00'], Clock::utcRange('2026-01-02', '2026-01-02'), 'Bangkok day to UTC');
