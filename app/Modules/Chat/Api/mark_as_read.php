<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../Models/Conversation.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false]);
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
$sender_id       = (int)($data['sender_id'] ?? 0);

try {
    if ($conversation_id) {
        $convModel = new Conversation();
        if (!$convModel->isParticipant($conversation_id, $user_id)) {
            echo json_encode(['success' => false]);
            exit();
        }
        $msgModel = new Message();
        $last = $msgModel->getConversationMessages($conversation_id, 1, 0);
        $lastId = !empty($last) ? (int)end($last)['id'] : 0;
        $convModel->markRead($conversation_id, $user_id, $lastId);
        echo json_encode(['success' => true]);
        exit();
    }

    if (!$sender_id) {
        echo json_encode(['success' => false, 'message' => 'sender_id مطلوب']);
        exit();
    }

    $model = new Message();
    $model->markAllAsRead($sender_id, $user_id);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
