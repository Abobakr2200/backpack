<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Call.php';
require_once __DIR__ . '/../../Auth/Models/User.php';
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

$caller_id  = getUserId();
$callee_id  = (int)($data['receiver_id'] ?? 0);
$offer_sdp  = (string)($data['offer'] ?? '');
$call_type  = (($data['call_type'] ?? 'audio') === 'video') ? 'video' : 'audio';

if (!$callee_id || $callee_id === $caller_id) {
    echo json_encode(['success' => false, 'message' => 'مستلم غير صالح']);
    exit();
}
if ($offer_sdp === '') {
    echo json_encode(['success' => false, 'message' => 'offer مطلوب']);
    exit();
}

Logger::info('call offer received', ['caller_id' => $caller_id, 'callee_id' => $callee_id, 'offer_len' => strlen($offer_sdp)]);

$userModel = new User();
if ($userModel->isBlockedEitherWay($caller_id, $callee_id)) {
    echo json_encode(['success' => false, 'message' => 'لا يمكن الاتصال بهذا المستخدم']);
    exit();
}

$model = new Call();

if ($model->isBusy($caller_id)) {
    echo json_encode(['success' => false, 'message' => 'لديك مكالمة أخرى بالفعل']);
    exit();
}
if ($model->isBusy($callee_id)) {
    echo json_encode(['success' => false, 'message' => 'المستخدم مشغول الآن', 'busy' => true]);
    exit();
}

$call_id = $model->start($caller_id, $callee_id, $offer_sdp, $call_type);
if (!$call_id) {
    echo json_encode(['success' => false, 'message' => 'تعذر بدء المكالمة']);
    exit();
}

// نبعت إشعار Push فوري للمستقبل (بيوصله حتى لو الموقع مقفول عنده تمامًا)
require_once __DIR__ . '/../../Notifications/Helpers/WebPushSender.php';
require_once __DIR__ . '/../../Auth/Models/User.php';
try {
    $callerUser = (new User())->getUserData($caller_id);
    $callTypeLabel = ($call_type === 'video') ? 'مكالمة فيديو' : 'مكالمة صوتية';
    WebPushSender::sendToUser($callee_id, [
        'title' => $callTypeLabel . ' واردة',
        'body'  => ($callerUser['username'] ?? 'شخص ما') . ' يتصل بك الآن',
        'icon'  => '/public/assets/img/icon-192.png',
        'tag'   => 'chat-ag-call-' . $call_id,
        'url'   => '/?chat=' . $caller_id,
        'requireInteraction' => true,
        'renotify' => true,
        'vibrate'  => [400, 200, 400, 200, 400],
        'actions'  => [
            ['action' => 'answer',  'title' => 'رد'],
            ['action' => 'dismiss', 'title' => 'تجاهل'],
        ],
    ]);
} catch (\Throwable $e) {}

echo json_encode(['success' => true, 'call_id' => $call_id, 'call_type' => $call_type]);
