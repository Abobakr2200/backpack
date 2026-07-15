<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../Models/TypingStatus.php';
require_once __DIR__ . '/../Models/Conversation.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح', 'messages' => []]);
    exit();
}

$user_id         = getUserId();
$conversation_id = (int)($_GET['conversation_id'] ?? 0);
$contact_id      = (int)($_GET['receiver_id'] ?? $_GET['contact_id'] ?? 0);
$since_id        = (int)($_GET['since'] ?? 0);
$around_id       = (int)($_GET['around'] ?? 0);

try {
    if ($conversation_id) {
        // رسائل جروب: لازم يتأكد إنه عضو الأول
        $convModel = new Conversation();
        if (!$convModel->isParticipant($conversation_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'غير مصرح', 'messages' => []]);
            exit();
        }
        $model    = new Message();
        $messages = $around_id
            ? $model->getMessagesAround($user_id, 0, $conversation_id, $around_id)
            : $model->getConversationMessages($conversation_id, 60, $since_id);

        echo json_encode([
            'success'     => true,
            'messages'    => $messages,
            'peer_typing' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!$contact_id) {
        echo json_encode(['success' => false, 'message' => 'receiver_id مطلوب', 'messages' => []]);
        exit();
    }

    $model    = new Message();
    $messages = $around_id
        ? $model->getMessagesAround($user_id, $contact_id, 0, $around_id)
        : $model->getMessages($user_id, $contact_id, 60, 0, $since_id);

    $typingModel = new TypingStatus();
    $peer_typing = $typingModel->isTypingToMe($contact_id, $user_id);

    echo json_encode([
        'success'     => true,
        'messages'    => $messages,
        'peer_typing' => $peer_typing,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'messages' => []]);
}
