<?php
declare(strict_types=1);

// Usage: php tests/run.php [filter]   e.g. php tests/run.php 05
// Real HTTP against PHP built-in servers. Everything runs on qim_test, which is created from the migrations
// and the seed, and dropped at the end.

require __DIR__ . '/lib.php';

require __DIR__ . '/fixture.php';

$filter = $argv[1] ?? '';
$base = Servers::start(8111, ['RATE_LIMIT_MULTIPLIER' => '1000']);

foreach (glob(__DIR__ . '/[0-9][0-9]_*.php') ?: [] as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    try {
        (static function (string $file, string $base): void {
            require $file;
        })($file, $base);
    } catch (Throwable $e) {
        T::ok(false, 'exception in ' . basename($file) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
echo "\n== " . T::$passed . ' passed, ' . count(T::$failed) . " failed\n";
foreach (T::$failed as $label) {
    echo "FAILED $label\n";
}
ksort(T::$ids);
foreach (T::$ids as $id => $ok) {
    echo $id . '=' . ($ok ? 'pass' : 'FAIL') . ' ';
}
foreach (T::$skipped as $id => $why) {
    echo "$id=SKIPPED($why) ";
}
echo "\n";
exit(TestDb::finish() && T::$failed === [] ? 0 : 1);
