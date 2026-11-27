<?php
ini_set('display_errors', '0');
header_remove('X-Powered-By');
// Keep the query string: LIFF passes its state through it.
$query = $_SERVER['QUERY_STRING'] ?? '';
// "/admin" without a slash arrives here too (.htaccess). The target is relative, so it fits any host, scheme and folder.
$page = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
// Photo credits of the demo menus: a plain public page (D41).
if ($page === 'credits') {
    require dirname(__DIR__) . '/app/bootstrap.php';
    App\Services\Credits::page();
    exit;
}
$page = in_array($page, ['merchant', 'admin'], true) ? $page : 'customer';
header("Location: $page/" . ($query !== '' ? '?' . $query : ''), true, 302);
