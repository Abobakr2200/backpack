<?php
/**
 * جلسة الأدمن — منفصلة تماماً عن جلسة المستخدم العادي.
 * بتستخدم مفتاح سيشن مختلف ($_SESSION['admin_id']) عشان حتى لو حصل
 * أي خلل في منطق المستخدمين العاديين، ميأثرش على صلاحيات الأدمن.
 */

require_once __DIR__ . '/session.php'; // بيبدأ السيشن ويجهز csrfToken/verifyCsrf
require_once __DIR__ . '/rate_limit.php';

function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin_id']);
}

function getAdminId() {
    return $_SESSION['admin_id'] ?? null;
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: /app/Modules/Admin/Views/login.php');
        exit();
    }
}

/** لاستخدامه في الـ API endpoints بدل requireAdmin() (بيرجع JSON مش redirect) */
function requireAdminApi(): void {
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'غير مصرح']);
        exit();
    }
}

function adminLogout(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    header('Location: /app/Modules/Admin/Views/login.php');
    exit();
}
