<?php
declare(strict_types=1);

// The destructive scripts must refuse everything except their one purpose. They are never allowed to succeed here:
// DB_PORT points at a closed port, so even a broken check could not reach a database.

use App\Support\Database;

T::group('Test database isolation');
T::eq('qim_test', Database::value('SELECT DATABASE()'), 'tests are connected to qim_test');
T::eq('qim_test', getenv('DB_NAME'), 'child processes inherit qim_test');
// Any PHP file that names another schema could write outside qim_test.
$offenders = [];
foreach (array_merge(glob(__DIR__ . '/*.php') ?: [], [dirname(__DIR__) . '/scripts/e2e.php']) as $file) {
    if (!in_array(basename($file), ['testdb.php', '12_dev_scripts.php'], true) && str_contains((string) file_get_contents($file), 'qim_' . 'dev')) {
        $offenders[] = basename($file);
    }
}
T::eq([], $offenders, 'no test names the development database');

T::group('reset-dev.php and the test runner refuse');
$php = static function (string $script, array $args, array $env): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . "/$script", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv(), ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
$before = Database::value('SELECT COUNT(*) FROM users');
$closed = ['DB_PORT' => '1', 'DB_NAME' => 'qim_dev'];
$cases = [
    'no --confirm' => [[], [], '--confirm'],
    'wrong flag' => [['--yes'], [], '--confirm'],
    'extra argument' => [['--confirm', 'x'], [], '--confirm'],
    'production' => [['--confirm'], ['APP_ENV' => 'production'], 'APP_ENV'],
    'staging' => [['--confirm'], ['APP_ENV' => 'staging'], 'APP_ENV'],
    'other database' => [['--confirm'], ['DB_NAME' => 'qim_other_db'], 'DB_NAME'],
    'test database' => [['--confirm'], ['DB_NAME' => 'qim_test'], 'DB_NAME'],
    'remote host' => [['--confirm'], ['DB_HOST' => '10.255.255.1'], 'DB_HOST'],
    'weak seed password' => [['--confirm'], ['SEED_DEMO_PASSWORD' => 'short'], 'SEED_DEMO_PASSWORD'],
];
foreach ($cases as $label => [$args, $env, $reason]) {
    foreach (['reset-dev', 'demo-reset'] as $script) {
        [$code, $out] = $php("scripts/$script.php", $args, $env + $closed);
        T::ok($code === 1 && str_contains($out, 'Refusing') && str_contains($out, $reason), "$script: $label", $out);
    }
}
foreach (['tests/run.php', 'scripts/e2e.php'] as $script) {
    [$code, $out] = $php($script, [], ['APP_ENV' => 'production', 'DB_PORT' => '1']);
    T::ok($code === 1 && str_contains($out, 'APP_ENV'), "$script: production", $out);
    [$code, $out] = $php($script, [], ['DB_HOST' => '10.255.255.1']);
    T::ok($code === 1 && str_contains($out, 'DB_HOST'), "$script: remote host", $out);
    [$code, $out] = $php($script, [], ['DB_NAME' => 'qim_dev']);
    T::ok($code === 1 && str_contains($out, 'another test run'), "$script: ignores DB_NAME and never shares a running test database", $out);
}
T::eq($before, Database::value('SELECT COUNT(*) FROM users'), 'nothing was deleted');
