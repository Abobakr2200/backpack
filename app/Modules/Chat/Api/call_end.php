<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Call.php';
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

$user_id = getUserId();
$call_id = (int)($data['call_id'] ?? 0);

if (!$call_id) {
    echo json_encode(['success' => false, 'message' => 'call_id مطلوب']);
    exit();
}

$model  = new Call();
$call   = $model->getById($call_id);
$status = $model->end($call_id, $user_id);

if ($status && $call) {
    // نسجّل المكالمة كرسالة في المحادثة (زي واتساب) عشان تفضل في السجل
    $labels = [
        'ended'     => 'call_ended',
        'rejected'  => 'call_rejected',
        'cancelled' => 'call_cancelled',
    ];
    $label = $labels[$status] ?? null;
    if ($label) {
        $duration = null;
        if ($status === 'ended') {
            $fresh    = $model->getById($call_id);
            $duration = $fresh['duration'] ?? 0;
        }
        $payload = json_encode([
            'status'    => $label,
            'duration'  => $duration,
            'call_type' => $call['call_type'] ?? 'audio',
        ], JSON_UNESCAPED_UNICODE);
        try {
            $msgModel = new Message();
            $msgModel->sendMessage((int)$call['caller_id'], (int)$call['callee_id'], $payload, null, 'call');
        } catch (Exception $e) {}
    }
}

echo json_encode(['success' => (bool)$status, 'status' => $status]);
