<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';

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

$user_id    = getUserId();
$message_id = (int)($data['message_id'] ?? 0);
$emoji      = trim($data['emoji'] ?? '');

// قايمة إيموجيهات مسموحة بس (مش أي نص حر) - أمان + شكل موحّد
$allowed_emojis = ['❤️', '😂', '👍', '😮', '😢', '🙏'];

if (!$message_id || !in_array($emoji, $allowed_emojis, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
    exit();
}

$model  = new Message();
$result = $model->toggleReaction($message_id, $user_id, $emoji);

if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
