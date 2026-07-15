<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Models/Post.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'posts' => []]);
    exit();
}

$q = trim($_GET['q'] ?? $_GET['query'] ?? '');
if ($q === '') {
    echo json_encode(['success' => true, 'posts' => []]);
    exit();
}

try {
    $model = new Post();
    $posts = $model->searchPosts($q, 8);
    foreach ($posts as &$p) {
        if (empty($p['profile_photo'])) $p['profile_photo'] = 'default.png';
    }
    echo json_encode(['success' => true, 'posts' => $posts], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'posts' => [], 'error' => $e->getMessage()]);
}
