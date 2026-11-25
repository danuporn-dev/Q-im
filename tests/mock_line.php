<?php
// Fake LINE API for tests. Run as a router script of the PHP built-in server; never deployed.
// The push result is chosen with MOCK_PUSH_FILE, whose content is the HTTP status to return.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/oauth2/v2.1/verify') {
    [$kind, $sub] = explode(':', $_POST['id_token'] ?? '', 2) + [1 => ''];
    $claims = ['iss' => 'https://access.line.me', 'sub' => $sub, 'aud' => $_POST['client_id'] ?? '', 'exp' => time() + 3600, 'iat' => time(), 'name' => 'ลูกค้า LINE ทดสอบ', 'picture' => 'https://profile.line-scdn.net/x'];
    switch ($kind) {
        case 'good': break;
        case 'aud': $claims['aud'] = 'other'; break;
        case 'iss': $claims['iss'] = 'https://evil.example'; break;
        case 'exp': $claims['exp'] = time() - 10; break;
        case 'down': http_response_code(500); echo '{}'; return;
        default: http_response_code(400); echo json_encode(['error' => 'invalid_request', 'error_description' => 'IdToken expired.']); return;
    }
    echo json_encode($claims);
    return;
}
if ($path === '/v2/bot/message/push') {
    $status = (int) @file_get_contents(getenv('MOCK_PUSH_FILE')) ?: 200;
    $body = json_decode(file_get_contents('php://input'), true);
    file_put_contents(getenv('MOCK_PUSH_FILE') . '.log', json_encode(['to' => $body['to'] ?? null, 'text' => $body['messages'][0]['text'] ?? null, 'key' => $_SERVER['HTTP_X_LINE_RETRY_KEY'] ?? null, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'status' => $status]) . "\n", FILE_APPEND);
    http_response_code($status);
    echo '{}';
    return;
}
http_response_code(404);
