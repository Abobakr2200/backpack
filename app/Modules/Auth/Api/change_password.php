<?php
/**
 * تغيير كلمة المرور - endpoint حقيقي (فورم عادي POST مش JSON)
 * قبل كده الفورم في edit_profile.php كان بيعمل POST مباشر لـ AuthController.php
 * وهو كلاس PHP عادي مش endpoint، فمكانش بيشتغل خالص.
 */
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../../../config/rate_limit.php';
require_once __DIR__ . '/../Models/User.php';

if (!isLoggedIn()) {
    header("Location: /app/Modules/Auth/Views/login.php");
    exit();
}

$redirect = '/app/Modules/Profile/Views/edit_profile.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $redirect");
    exit();
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    header("Location: $redirect?pwd_err=" . urlencode('انتهت صلاحية الجلسة، حاول تاني'));
    exit();
}

$user_id          = getUserId();
$currentPassword  = $_POST['current_password'] ?? '';
$newPassword      = $_POST['new_password'] ?? '';
$confirmPassword  = $_POST['confirm_password'] ?? '';

// حماية من محاولات تخمين كلمة المرور الحالية (Brute Force) — نفس آلية تسجيل الدخول
$rl_key = 'pwd_change_' . $user_id;
$remaining = rateLimitSecondsRemaining($rl_key);
if ($remaining > 0) {
    $minutes = ceil($remaining / 60);
    header("Location: $redirect?pwd_err=" . urlencode("محاولات كتير خاطئة، حاول تاني بعد {$minutes} دقيقة"));
    exit();
}

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    header("Location: $redirect?pwd_err=" . urlencode('يرجى ملء جميع حقول كلمة المرور'));
    exit();
}

if (strlen($newPassword) < 6) {
    header("Location: $redirect?pwd_err=" . urlencode('كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل'));
    exit();
}

if ($newPassword !== $confirmPassword) {
    header("Location: $redirect?pwd_err=" . urlencode('كلمتا المرور الجديدتان غير متطابقتين'));
    exit();
}

$userModel = new User();
$result    = $userModel->changePassword($user_id, $currentPassword, $newPassword);

if ($result['success']) {
    rateLimitReset($rl_key);
    header("Location: $redirect?pwd_ok=" . urlencode($result['message']));
} else {
    // نسجل المحاولة كفشل بس لو السبب إن كلمة المرور الحالية غلط
    // (مش لو الخطأ كان حاجة تانية زي طول كلمة السر مثلاً - دي بنتحقق منها فوق أصلاً)
    rateLimitRecordFailure($rl_key);
    header("Location: $redirect?pwd_err=" . urlencode($result['message']));
}
exit();
