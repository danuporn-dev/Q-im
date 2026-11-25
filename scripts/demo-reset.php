<?php
declare(strict_types=1);

// Usage: php scripts/demo-reset.php --confirm
// DESTROYS qim_dev and all login sessions, then migrates and seeds the presentation data (seed-demo.php).
// Every guard of reset-dev.php applies: APP_ENV=development, qim_dev and this computer only.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
const RESET_SEED = 'seed-demo.php';
require __DIR__ . '/reset-dev.php';
