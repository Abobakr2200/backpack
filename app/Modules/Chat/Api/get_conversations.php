<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../Models/Conversation.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح', 'conversations' => []]);
    exit();
}

try {
    $user_id = getUserId();

    $msgModel = new Message();
    $direct   = $msgModel->getLastMessages($user_id);
    foreach ($direct as &$d) { $d['type'] = 'direct'; }
    unset($d);

    $convModel = new Conversation();
    $groups    = $convModel->getUserGroups($user_id);

    $all = array_merge($direct, $groups);
    usort($all, function($a, $b) {
        $ta = $a['last_message_time'] ?? '';
        $tb = $b['last_message_time'] ?? '';
        if ($ta === $tb) return ($b['last_id'] ?? 0) - ($a['last_id'] ?? 0);
        return strcmp($tb, $ta);
    });

    echo json_encode(['success' => true, 'conversations' => $all], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'conversations' => []]);
}
