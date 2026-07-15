<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Post.php';

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

$user_id = getUserId();
$post_id = (int)($data['post_id'] ?? 0);

if (!$post_id) {
    echo json_encode(['success' => false, 'message' => 'post_id مطلوب']);
    exit();
}

$model = new Post();
// deletePost بيتحقق داخلياً إن user_id = صاحب البوست، فمينفعش حد يمسح بوست مش بتاعه
$result = $model->deletePost($post_id, $user_id);

// لو نجح الحذف وكان في صورة، نشيلها من على السيرفر كمان
if ($result['success'] && !empty($result['image'])) {
    $imgPath = __DIR__ . '/../../../../public/uploads/' . $user_id . '/' . $result['image'];
    if (is_file($imgPath)) {
        @unlink($imgPath);
    }
}
unset($result['image']);

echo json_encode($result);
