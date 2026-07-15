<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/admin_session.php';
require_once __DIR__ . '/../Models/Admin.php';

requireAdminApi();

$data = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCsrf($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
    exit();
}

$admin_id = getAdminId();
$user_id  = (int)($data['user_id'] ?? 0);
$action   = $data['action'] ?? '';
$reason   = trim($data['reason'] ?? '');

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'user_id مطلوب']);
    exit();
}

$model = new AdminModel();

if ($action === 'ban') {
    $result = $model->banUser($admin_id, $user_id, $reason ?: 'بدون سبب محدد');
} elseif ($action === 'unban') {
    $result = $model->unbanUser($admin_id, $user_id);
} else {
    $result = ['success' => false, 'message' => 'إجراء غير معروف'];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
