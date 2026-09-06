<?php

// Synthetic loopback fixture. It never reads project credentials or persists bodies.
$scenario = $_SERVER['HTTP_X_CLIENT_ID'] ?? '';
if ($scenario === 'redirect') {
    http_response_code(302);
    header('Location: http://127.0.0.1:1/credential-must-not-arrive');
    exit;
}
if ($scenario === 'oversized-length') {
    header('Content-Type: audio/wav');
    header('Content-Length: 8192');
    echo str_repeat('x', 8192);
    exit;
}
if ($scenario === 'oversized-body') {
    header('Content-Type: audio/wav');
    echo str_repeat('x', 8192);
    exit;
}
if ($scenario === 'slow-body') {
    header('Content-Type: audio/wav');
    echo str_repeat('x', 1024);
    flush();
    usleep(1800000);
    echo 'late body';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Retry-After: 500');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'client_id' => $scenario,
    'token_ok' => ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer synthetic-wire-token',
    'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
    'payload' => json_decode(file_get_contents('php://input'), true),
]);
