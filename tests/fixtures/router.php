<?php

declare(strict_types=1);

$body = file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request = [
    'method' => (string) $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'headers' => $headers,
    'json' => $body === '' ? null : json_decode($body, true),
];

$logPath = getenv('PURESMS_FIXTURE_LOG');
if (is_string($logPath) && $logPath !== '') {
    file_put_contents($logPath, json_encode($request) . PHP_EOL, FILE_APPEND);
}

header('Content-Type: application/json');

if (str_starts_with($path, '/invalid-json/')) {
    echo 'not json';
    return;
}

if (str_starts_with($path, '/error/')) {
    http_response_code(401);
    echo '{"error":"denied"}';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/sms/send') {
    echo '{"id":"123","countryCode":"GB"}';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/sms/send/bulk') {
    http_response_code(207);
    echo '{"batchId":"456","messageCount":1,"errors":[{"index":1,"error":"example validation error"}]}';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $path === '/sms/send/123') {
    http_response_code(200);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $path === '/sms/send/bulk/456') {
    echo '{"cancelledCount":2,"reason":null,"errors":[]}';
    return;
}

http_response_code(404);
echo '{"error":"not found"}';
