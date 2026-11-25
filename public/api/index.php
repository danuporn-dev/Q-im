<?php
declare(strict_types=1);

use App\Support\HttpError;
use App\Support\Log;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

ini_set('display_errors', '0');

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
