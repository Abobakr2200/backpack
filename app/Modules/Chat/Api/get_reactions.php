<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Message.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'reactions' => []]);
    exit();
}

$user_id = getUserId();
$idsRaw  = $_GET['ids'] ?? '';
$ids     = array_filter(array_map('intval', explode(',', $idsRaw)));

// نحدد حد أقصى معقول لعدد الرسايل في الطلب الواحد
$ids = array_slice(array_values($ids), 0, 60);

if (empty($ids)) {
    echo json_encode(['success' => true, 'reactions' => []]);
    exit();
}

$model     = new Message();
$reactions = $model->getReactionsForMessages($ids, $user_id);

echo json_encode(['success' => true, 'reactions' => $reactions], JSON_UNESCAPED_UNICODE);
