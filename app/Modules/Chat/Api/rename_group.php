<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Conversation.php';

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

$user_id         = getUserId();
$conversation_id = (int)($data['conversation_id'] ?? 0);
$title           = (string)($data['title'] ?? '');

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'conversation_id مطلوب']);
    exit();
}

$model  = new Conversation();
$result = $model->renameGroup($conversation_id, $user_id, $title);

if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
