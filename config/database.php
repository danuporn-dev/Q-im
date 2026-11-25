<?php
declare(strict_types=1);

use App\Support\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', 'qim_dev'),
    'user' => Env::get('DB_USER'),
    'password' => Env::get('DB_PASSWORD'),
];
