<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';

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

$user_id    = getUserId();
$message_id = (int)($data['message_id'] ?? 0);

if (!$message_id) {
    echo json_encode(['success' => false, 'message' => 'message_id مطلوب']);
    exit();
}

$model = new Message();
// deleteMessage بيتحقق داخلياً إن sender_id = user_id، فمينفعش حد يمسح رسالة مش بتاعته
$ok = $model->deleteMessage($message_id, $user_id);

echo json_encode([
    'success' => $ok,
    'message' => $ok ? 'تم حذف الرسالة' : 'تعذر حذف الرسالة (ربما مش رسالتك)',
]);
