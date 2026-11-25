<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Support\Database;
use App\Support\Request;
use App\Support\Router;

return static function (Router $r): void {
    $r->add('GET', '/health', static function (Request $q): array {
        Database::value('SELECT 1');
        return [['status' => 'ok'], 200];
    });

    // Identity
    $r->add('GET', '/config', [AuthController::class, 'config']);
    $r->add('POST', '/auth/dev', [AuthController::class, 'dev']);
    $r->add('POST', '/auth/login', [AuthController::class, 'login']);
    $r->add('POST', '/auth/logout', [AuthController::class, 'logout']);
    $r->add('GET', '/me', [AuthController::class, 'me']);


};
