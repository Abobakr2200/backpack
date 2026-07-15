<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'blocked' => false]);
    exit();
}

$user_id   = getUserId();
$target_id = (int)($_GET['user_id'] ?? 0);

if (!$target_id) {
    echo json_encode(['success' => false, 'blocked' => false]);
    exit();
}

$model = new User();
echo json_encode([
    'success' => true,
    'blocked' => $model->isBlocked($user_id, $target_id), // إحنا اللي حاظرين هو
]);
