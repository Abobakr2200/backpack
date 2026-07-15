<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/PushSubscription.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCsrf($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
    exit();
}

$sub     = $data['subscription'] ?? null;
$endpoint = (string)($sub['endpoint'] ?? '');
$p256dh   = (string)($sub['keys']['p256dh'] ?? '');
$auth     = (string)($sub['keys']['auth'] ?? '');

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    echo json_encode(['success' => false, 'message' => 'بيانات الاشتراك ناقصة']);
    exit();
}

$model = new PushSubscription();
$ok = $model->save(getUserId(), $endpoint, $p256dh, $auth, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));

echo json_encode(['success' => (bool)$ok]);
