<?php
declare(strict_types=1);

// The destructive scripts must refuse everything except their one purpose. They are never allowed to succeed here.

use App\Support\Database;

T::group('reset-dev.php and clean_install.php refuse');
$php = static function (string $script, array $args, array $env): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . "/$script", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
$before = Database::value('SELECT COUNT(*) FROM users');
$cases = [
    'no --confirm' => [[], ['DB_NAME' => 'qim_dev'], '--confirm'],
    'wrong flag' => [['--yes'], ['DB_NAME' => 'qim_dev'], '--confirm'],
    'extra argument' => [['--confirm', 'x'], ['DB_NAME' => 'qim_dev'], '--confirm'],
    'production' => [['--confirm'], ['APP_ENV' => 'production', 'DB_NAME' => 'qim_dev'], 'APP_ENV'],
    'staging' => [['--confirm'], ['APP_ENV' => 'staging', 'DB_NAME' => 'qim_dev'], 'APP_ENV'],
    'other database' => [['--confirm'], ['DB_NAME' => 'qim_other_db'], 'DB_NAME'],
    'fresh database' => [['--confirm'], ['DB_NAME' => 'qim_fresh_test'], 'DB_NAME'],
    'remote host' => [['--confirm'], ['DB_NAME' => 'qim_dev', 'DB_HOST' => '10.255.255.1'], 'DB_HOST'],
    'weak seed password' => [['--confirm'], ['DB_NAME' => 'qim_dev', 'SEED_DEMO_PASSWORD' => 'short'], 'SEED_DEMO_PASSWORD'],
];
foreach ($cases as $label => [$args, $env, $reason]) {
    [$code, $out] = $php('scripts/reset-dev.php', $args, $env);
    T::ok($code === 1 && str_contains($out, 'Refusing') && str_contains($out, $reason), "reset-dev: $label", $out);
}
[$code, $out] = $php('tests/clean_install.php', [], ['APP_ENV' => 'production']);
T::ok($code === 1 && str_contains($out, 'Refusing'), 'clean_install: production', $out);
[$code, $out] = $php('tests/run.php', [], ['DB_NAME' => 'qim_other_db']);
T::ok($code === 1 && str_contains($out, 'only'), 'tests: other database', $out);
T::eq($before, Database::value('SELECT COUNT(*) FROM users'), 'nothing was deleted');
