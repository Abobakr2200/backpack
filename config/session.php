<?php

// إعدادات كوكي الجلسة قبل بدء السيشن (أهم حاجة تتعمل قبل session_start)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps, // الكوكي ميتبعتش غير على HTTPS لو الموقع شغال عليه
        'httponly' => true,     // يمنع الجافاسكريبت من قراءة كوكي السيشن (حماية من XSS)
        'samesite' => 'Lax',    // يقلل مخاطر CSRF من مواقع خارجية
    ]);
}

session_start();

// تحديد مدة الجلسة (30 دقيقة)
$session_timeout = 30 * 60;

// التحقق من انتهاء الجلسة
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header("Location: /app/Modules/Auth/Views/login.php");
    exit();
}

$_SESSION['last_activity'] = time();

// دالة للتحقق من تسجيل الدخول
function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // نتأكد إن المستخدم مش محظور - بنعمل الفحص ده مرة واحدة بس لكل طلب
    // (مش مرة لكل نداء لـ isLoggedIn) عشان ميبقاش فيه ضغط زيادة على الداتابيز
    static $checked = false;
    static $banned  = false;

    if (!$checked) {
        $checked = true;
        require_once __DIR__ . '/database.php';
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT is_banned FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            // لو المستخدم اتمسح من الداتابيز خالص أو محظور، نعتبره محظور/مش مسموح
            $banned = !$row || (int)$row['is_banned'] === 1;
        }
    }

    if ($banned) {
        // نفصل الجلسة فورًا حتى لو كان داخل بالفعل من قبل ما يتحظر
        session_unset();
        session_destroy();
        return false;
    }

    return true;
}

// دالة للحصول على معرف المستخدم
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

// دالة لتسجيل الخروج
function logout() {
    session_unset();
    session_destroy();
    header("Location: /app/Modules/Auth/Views/login.php");
    exit();
}

/**
 * توليد/إرجاع توكن CSRF الخاص بالجلسة الحالية
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * التحقق من توكن CSRF المُرسل مع الطلب.
 * بيقبل التوكن من: هيدر X-CSRF-Token، أو حقل csrf_token في POST،
 * أو حقل csrf_token في JSON body (لو الكولر بعت $data وسابها).
 */
function verifyCsrf(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * يوقف الطلب فوراً برسالة JSON لو التوكن غلط أو ناقص.
 * تُستخدم في بداية أي API بيغيّر بيانات (POST/PUT/DELETE).
 */
function requireCsrf(): void {
    $token = $_POST['csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    // لو الطلب جايه بصيغة JSON، دور على التوكن جوه الـ body كمان
    if (!$token) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['csrf_token'])) {
            $token = $data['csrf_token'];
        }
    }

    if (!verifyCsrf($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح (CSRF)']);
        exit();
    }
}
