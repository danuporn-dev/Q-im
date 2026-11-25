<?php
declare(strict_types=1);

use App\Support\HttpError;
use App\Support\Log;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;

// Before anything can fail: XAMPP shows errors with file paths by default.
ini_set('display_errors', '0');
header_remove('X-Powered-By');

require dirname(__DIR__, 2) . '/app/bootstrap.php';

// Errors that cannot be caught, such as running out of memory.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Log::write('api', 'fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
    if (!headers_sent()) {
        Response::error(new HttpError(500, 'SERVER_ERROR', 'ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง'));
    }
});

try {
    $request = Request::capture();
    $router = new Router();
    (require dirname(__DIR__, 2) . '/app/routes.php')($router);
    $result = $router->dispatch($request);
    if ($result !== null) {
        Response::json(['data' => $result[0]] + (isset($result[2]) ? ['meta' => $result[2]] : []), $result[1]);
    }
} catch (HttpError $e) {
    Response::error($e);
} catch (Throwable $e) {
    Log::error('api', $e);
    Response::error(new HttpError(500, 'SERVER_ERROR', 'ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง'));
}
