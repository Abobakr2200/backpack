<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Notification.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'notifications' => [], 'unread_count' => 0]);
    exit();
}

try {
    $model         = new Notification();
    $user_id       = getUserId();
    $notifications = $model->getByUser($user_id);
    $unread_count  = $model->getUnreadCount($user_id);

    // تأكد من وجود profile_photo
    foreach ($notifications as &$n) {
        if (empty($n['profile_photo'])) $n['profile_photo'] = 'default.png';
    }

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'unread_count'  => (int)$unread_count,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'notifications' => [], 'unread_count' => 0]);
}
