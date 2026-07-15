<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Notification.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false]);
    exit();
}

try {
    $model = new Notification();
    $model->markAsRead(getUserId());
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
