<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

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

$user_id   = getUserId();
$target_id = (int)($data['user_id'] ?? 0);
$action    = $data['action'] ?? 'block'; // block | unblock

if (!$target_id) {
    echo json_encode(['success' => false, 'message' => 'user_id مطلوب']);
    exit();
}

$model = new User();

if ($action === 'unblock') {
    $result = $model->unblockUser($user_id, $target_id);
} else {
    $result = $model->blockUser($user_id, $target_id);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
