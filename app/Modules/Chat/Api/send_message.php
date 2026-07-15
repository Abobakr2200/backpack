<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../Models/Conversation.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = [];

if (!verifyCsrf($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
    exit();
}

$sender_id       = getUserId();
$conversation_id = (int)($data['conversation_id'] ?? 0);
$receiver_id     = (int)($data['receiver_id']  ?? 0);
$message_text    = trim($data['message_text']  ?? '');
$file_path       = $data['file_path']           ?? null;
$message_type    = $data['message_type']        ?? 'text';
$reply_to_id     = !empty($data['reply_to_id']) ? (int)$data['reply_to_id'] : null;

if ($message_text === '' && !$file_path) {
    echo json_encode(['success' => false, 'message' => 'الرسالة فارغة']);
    exit();
}

$userModel = new User();

// ── رسالة جروب ──
if ($conversation_id) {
    $convModel = new Conversation();
    if (!$convModel->isParticipant($conversation_id, $sender_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'أنت لست عضواً في هذه المجموعة']);
        exit();
    }

    try {
        $model  = new Message();
        $result = $model->sendGroupMessage($conversation_id, $sender_id, $message_text, $file_path, $message_type, $reply_to_id);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);

        if (!empty($result['success'])) {
            require_once __DIR__ . '/../../Notifications/Helpers/WebPushSender.php';
            $info    = $convModel->getGroupInfo($conversation_id, $sender_id);
            $sender  = $userModel->getUserData($sender_id);
            $preview = ($message_type === 'text') ? mb_substr($message_text, 0, 100)
                     : (['image' => '📷 صورة', 'file' => '📎 ملف', 'voice' => '🎤 رسالة صوتية', 'location' => '📍 موقع'][$message_type] ?? 'رسالة جديدة');
            $groupTitle = $info['conversation']['title'] ?? 'مجموعة';

            foreach (($info['members'] ?? []) as $m) {
                if ((int)$m['id'] === (int)$sender_id) continue;
                WebPushSender::sendToUser((int)$m['id'], [
                    'title' => ($sender['username'] ?? 'رسالة جديدة') . ' في ' . $groupTitle,
                    'body'  => $preview,
                    'url'   => '/?group=' . $conversation_id,
                    'tag'   => 'chat-group-msg-' . $conversation_id,
                    'renotify' => true,
                    'vibrate'  => [200, 100, 200],
                    'actions'  => [
                        ['action' => 'open', 'title' => 'فتح المجموعة'],
                    ],
                ]);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ── رسالة فردية (زي ما هي بالظبط) ──
if (!$receiver_id) {
    echo json_encode(['success' => false, 'message' => 'receiver_id مطلوب']);
    exit();
}

// امنع الإرسال لو فيه حظر بين الطرفين في أي اتجاه
if ($userModel->isBlockedEitherWay($sender_id, $receiver_id)) {
    echo json_encode(['success' => false, 'message' => 'لا يمكن إرسال رسائل لهذا المستخدم']);
    exit();
}

try {
    $model  = new Message();
    $result = $model->sendMessage($sender_id, $receiver_id, $message_text, $file_path, $message_type, $reply_to_id);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

    if (!empty($result['success'])) {
        require_once __DIR__ . '/../../Notifications/Helpers/WebPushSender.php';
        $sender = $userModel->getUserData($sender_id);
        $preview = ($message_type === 'text') ? mb_substr($message_text, 0, 100)
                 : (['image' => '📷 صورة', 'file' => '📎 ملف', 'voice' => '🎤 رسالة صوتية', 'location' => '📍 موقع'][$message_type] ?? 'رسالة جديدة');
        WebPushSender::sendToUser($receiver_id, [
            'title' => $sender['username'] ?? 'رسالة جديدة',
            'body'  => $preview,
            'url'   => '/?chat=' . $sender_id,
            'tag'   => 'chat-ag-msg-' . $sender_id,
            'renotify' => true,
            'vibrate'  => [200, 100, 200],
            'actions'  => [
                ['action' => 'open', 'title' => 'فتح المحادثة'],
            ],
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
