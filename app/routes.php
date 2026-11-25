<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Request;
use App\Support\Router;

return static function (Router $r): void {
    $r->add('GET', '/health', static function (Request $q): array {
        Database::value('SELECT 1');
        return [['status' => 'ok'], 200];
    });
};
