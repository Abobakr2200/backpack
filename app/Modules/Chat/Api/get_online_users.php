<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'users' => []]);
    exit();
}

try {
    $model = new User();
    $users = $model->getAllUsers(getUserId());
    foreach ($users as &$u) {
        if (empty($u['profile_photo'])) $u['profile_photo'] = 'default.png';
    }
    echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'users' => []]);
}
