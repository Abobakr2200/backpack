<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../../../config/env.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit();
}

$key = env('VAPID_PUBLIC_KEY', '');
echo json_encode(['success' => $key !== '', 'key' => $key]);
