<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Conversation.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$user_id         = getUserId();
$conversation_id = (int)($_GET['conversation_id'] ?? 0);

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'conversation_id مطلوب']);
    exit();
}

$model  = new Conversation();
$result = $model->getGroupInfo($conversation_id, $user_id);

if (!$result['success']) {
    http_response_code(403);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
