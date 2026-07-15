<?php
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../../../config/rate_limit.php';
require_once __DIR__ . '/../Models/User.php';

class AuthController {
    private $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    // تسجيل الدخول برقم الهاتف
    public function login($phone = null, $password = null) {
        $phone    = $phone    ?? $_POST['phone']    ?? '';
        $password = $password ?? $_POST['password'] ?? '';

        $phone = trim($phone);

        if (empty($phone) || empty($password)) {
            return ['success' => false, 'message' => 'يرجى إدخال رقم الهاتف وكلمة المرور'];
        }

        // تحقق من القفل قبل أي محاولة (حماية من Brute Force)
        $remaining = rateLimitSecondsRemaining($phone);
        if ($remaining > 0) {
            $minutes = ceil($remaining / 60);
            return ['success' => false, 'message' => "محاولات كتير خاطئة، حاول تاني بعد {$minutes} دقيقة"];
        }

        $result = $this->userModel->login($phone, $password);
        if ($result['success']) {
            rateLimitReset($phone);
            $_SESSION['user_id']  = $result['user_id'];
            $_SESSION['username'] = $result['username'];
            $_SESSION['phone']    = $result['phone'];
        } else {
            rateLimitRecordFailure($phone);
        }
        return $result;
    }

    // إنشاء حساب جديد برقم الهاتف
    public function register($username = null, $phone = null, $password = null, $password_confirm = null) {
        $username         = trim($username         ?? $_POST['username']         ?? '');
        $phone            = trim($phone            ?? $_POST['phone']            ?? '');
        $password         = $password              ?? $_POST['password']         ?? '';
        $password_confirm = $password_confirm      ?? $_POST['password_confirm'] ?? $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($phone) || empty($password)) {
            return ['success' => false, 'message' => 'جميع الحقول مطلوبة'];
        }

        if (strlen($username) < 3) {
            return ['success' => false, 'message' => 'اسم المستخدم قصير جداً (٣ أحرف على الأقل)'];
        }

        // التحقق من صيغة رقم الهاتف
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
            return ['success' => false, 'message' => 'رقم الهاتف غير صحيح'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'كلمة المرور يجب أن تكون ٦ أحرف على الأقل'];
        }

        if ($password !== $password_confirm) {
            return ['success' => false, 'message' => 'كلمتا المرور غير متطابقتين'];
        }

        return $this->userModel->register($username, $phone, $password);
    }
}
?>
