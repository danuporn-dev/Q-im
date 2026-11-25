<?php
ini_set('display_errors', '0');
header_remove('X-Powered-By');
// Keep the query string: LIFF passes its state through it.
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: customer/' . ($query !== '' ? '?' . $query : ''), true, 302);
