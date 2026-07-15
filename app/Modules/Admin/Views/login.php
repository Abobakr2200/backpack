<?php
require_once __DIR__ . '/../../../../config/admin_session.php';
require_once __DIR__ . '/../Models/Admin.php';

if (isAdminLoggedIn()) {
    header('Location: /app/Modules/Admin/Views/dashboard.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة، حاول تاني';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // نفس آلية الحماية من brute force المستخدمة في تسجيل دخول المستخدمين،
        // بمفتاح مختلف عشان منحطش نفس القفل على حسابات عادية بالغلط
        $rlKey = 'admin_' . $username;
        $remaining = rateLimitSecondsRemaining($rlKey);
        if ($remaining > 0) {
            $error = 'محاولات كتير خاطئة، حاول تاني بعد ' . ceil($remaining / 60) . ' دقيقة';
        } else {
            $model  = new AdminModel();
            $result = $model->login($username, $password);
            if ($result['success']) {
                rateLimitReset($rlKey);
                session_regenerate_id(true);
                $_SESSION['admin_id']       = $result['admin_id'];
                $_SESSION['admin_username']  = $result['username'];
                header('Location: /app/Modules/Admin/Views/dashboard.php');
                exit();
            } else {
                rateLimitRecordFailure($rlKey);
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>دخول الأدمن</title>
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <meta name="robots" content="noindex, nofollow">
</head>
<body>
<div style="max-width:380px;margin:80px auto;padding:0 16px">
  <div class="card" style="padding:24px">
    <h2 style="margin-bottom:16px;text-align:center"><i class="fas fa-shield-alt"></i> دخول الأدمن</h2>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-group">
        <label>اسم المستخدم</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="form-group">
        <label>كلمة المرور</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">دخول</button>
    </form>
  </div>
</div>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
