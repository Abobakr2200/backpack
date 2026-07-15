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
$action          = $data['action'] ?? '';

if (!$conversation_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
    exit();
}

$model = new Conversation();

switch ($action) {
    case 'add':
        $member_ids = $data['member_ids'] ?? [];
        if (!is_array($member_ids)) $member_ids = [];
        $result = $model->addMembers($conversation_id, $user_id, $member_ids);
        break;

    case 'remove':
        $target_id = (int)($data['user_id'] ?? 0);
        if (!$target_id) { $result = ['success' => false, 'message' => 'user_id مطلوب']; break; }
        $result = $model->removeMember($conversation_id, $user_id, $target_id);
        break;

    case 'leave':
        $result = $model->removeMember($conversation_id, $user_id, $user_id);
        break;

    default:
        $result = ['success' => false, 'message' => 'إجراء غير معروف'];
}

if (!$result['success']) {
    http_response_code(400);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
