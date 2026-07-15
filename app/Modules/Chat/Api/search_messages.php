<?php
/**
 * بحث داخل رسائل محادثة معينة (فردية أو جروب) بكلمة معينة.
 * Params: receiver_id (فردي) أو conversation_id (جروب), q (نص البحث)
 */
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../Models/Conversation.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح', 'results' => []]);
    exit();
}

$user_id         = getUserId();
$conversation_id = (int)($_GET['conversation_id'] ?? 0);
$contact_id      = (int)($_GET['receiver_id'] ?? $_GET['contact_id'] ?? 0);
$q               = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit();
}

try {
    if ($conversation_id) {
        $convModel = new Conversation();
        if (!$convModel->isParticipant($conversation_id, $user_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'غير مصرح', 'results' => []]);
            exit();
        }
    } elseif (!$contact_id) {
        echo json_encode(['success' => false, 'message' => 'receiver_id مطلوب', 'results' => []]);
        exit();
    }

    $model   = new Message();
    $results = $model->searchMessages($user_id, $contact_id, $conversation_id, $q, 50);

    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'results' => []]);
}
