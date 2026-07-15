<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Call.php';
require_once __DIR__ . '/../../../../config/logger.php';

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

$user_id  = getUserId();
$call_id  = (int)($data['call_id'] ?? 0);
$ice_since = (int)($data['ice_since'] ?? 0);
$candidate = isset($data['candidate']) ? trim((string)$data['candidate']) : '';

$model = new Call();

// مفيش مكالمة نشطة عندنا: دوّر على مكالمة واردة (بترن) للمستخدم ده
if (!$call_id) {
    $incoming = $model->getIncomingFor($user_id);
    if ($incoming) {
        Logger::info('call offer served to callee', ['callee_id' => $user_id, 'call_id' => (int)$incoming['id'], 'offer_len' => strlen($incoming['offer_sdp'] ?? '')]);
        echo json_encode([
            'success'  => true,
            'incoming' => [
                'call_id'      => (int)$incoming['id'],
                'caller_id'    => (int)$incoming['caller_id'],
                'caller_name'  => $incoming['caller_name'],
                'caller_photo' => $incoming['caller_photo'] ?: 'default.png',
                'offer'        => $incoming['offer_sdp'],
                'call_type'    => $incoming['call_type'] ?? 'audio',
            ],
        ]);
    } else {
        echo json_encode(['success' => true, 'incoming' => null]);
    }
    exit();
}

// عندنا مكالمة نشطة: تأكد إننا طرف فيها
$call = $model->getById($call_id);
if (!$call || ((int)$call['caller_id'] !== $user_id && (int)$call['callee_id'] !== $user_id)) {
    echo json_encode(['success' => false, 'message' => 'مكالمة غير موجودة']);
    exit();
}

// لو بعتنا candidate جديد، سجّله
if ($candidate !== '') {
    $model->addIceCandidate($call_id, $user_id, $candidate);
}

// انتهت صلاحية الرنين؟ (تحويل تلقائي لـ "فاتت")
if ($call['status'] === 'ringing') {
    $model->expireStaleRinging();
    $call = $model->getById($call_id);
}

$other_id = ((int)$call['caller_id'] === $user_id) ? (int)$call['callee_id'] : (int)$call['caller_id'];
$candidates = $model->getIceCandidatesSince($call_id, $other_id, $ice_since);

echo json_encode([
    'success' => true,
    'call' => [
        'id'         => (int)$call['id'],
        'status'     => $call['status'],
        'answer_sdp' => $call['answer_sdp'],
        'call_type'  => $call['call_type'] ?? 'audio',
    ],
    'ice_candidates' => array_map(function($row){
        return ['id' => (int)$row['id'], 'candidate' => $row['candidate']];
    }, $candidates),
]);
