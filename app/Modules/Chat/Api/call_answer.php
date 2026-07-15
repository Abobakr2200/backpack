<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Call.php';

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
$call_id    = (int)($data['call_id'] ?? 0);
$answer_sdp = (string)($data['answer'] ?? '');

if (!$call_id || $answer_sdp === '') {
    echo json_encode(['success' => false, 'message' => 'بيانات ناقصة']);
    exit();
}

$model = new Call();
$ok = $model->accept($call_id, $user_id, $answer_sdp);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'تعذر الرد على المكالمة (ربما انتهت)']);
    exit();
}

echo json_encode(['success' => true]);
