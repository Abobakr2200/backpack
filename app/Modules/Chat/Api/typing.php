<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/TypingStatus.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCsrf($data['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
    exit();
}

$user_id     = getUserId();
$receiver_id = (int)($data['receiver_id'] ?? 0);
$action      = $data['action'] ?? 'ping'; // ping | stop

$model = new TypingStatus();

if ($action === 'stop' || !$receiver_id) {
    $model->stop($user_id);
    echo json_encode(['success' => true]);
    exit();
}

$model->ping($user_id, $receiver_id);
echo json_encode(['success' => true]);
