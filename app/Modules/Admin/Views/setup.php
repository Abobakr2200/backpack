<?php
require_once __DIR__ . '/../../../../config/env.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/session.php';

// الصفحة دي بتشتغل بس لو مفيش أي أدمن اتعمل قبل كده، فمينفعش حد يعمل
// أدمن جديد بعد ما يبقى الموقع شغال فعلاً حتى لو لقى الرابط بالصدفة
$conn = connectDB();
$adminCount = $conn->query("SELECT COUNT(*) c FROM admins")->fetch_assoc()['c'];

$error = $success = '';

if ($adminCount > 0) {
    $error = 'تم إعداد حساب الأدمن بالفعل. الصفحة دي بقت متعطلة لأسباب أمنية.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة، حاول تاني';
    } else {
        $setupKey = $_POST['setup_key'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!hash_equals((string)env('ADMIN_SETUP_KEY', ''), $setupKey)) {
            $error = 'مفتاح الإعداد غير صحيح';
        } elseif (strlen($username) < 3) {
            $error = 'اسم المستخدم لازم يكون 3 أحرف على الأقل';
        } elseif (strlen($password) < 8) {
            $error = 'كلمة المرور لازم تكون 8 أحرف على الأقل';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $hash);
            if ($stmt->execute()) {
                $success = 'تم إنشاء حساب الأدمن بنجاح! روح دلوقتي لصفحة تسجيل الدخول.';
            } else {
                $error = 'حدث خطأ (ممكن اسم المستخدم مكرر)';
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
  <title>إعداد حساب الأدمن الأول</title>
  <link rel="stylesheet" href="/public/assets/css/main.css">
</head>
<body>
<div style="max-width:420px;margin:60px auto;padding:0 16px">
  <div class="card" style="padding:24px">
    <h2 style="margin-bottom:16px">إعداد حساب الأدمن الأول</h2>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <p style="margin-top:14px">
        <a href="/app/Modules/Admin/Views/login.php" class="btn btn-primary">تسجيل دخول الأدمن</a>
      </p>
    <?php endif; ?>

    <?php if ($adminCount == 0 && !$success): ?>
    <form method="POST" class="auth-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-group">
        <label>مفتاح الإعداد السري (من ملف .env)</label>
        <input type="password" name="setup_key" required>
      </div>
      <div class="form-group">
        <label>اسم مستخدم الأدمن</label>
        <input type="text" name="username" required minlength="3">
      </div>
      <div class="form-group">
        <label>كلمة المرور</label>
        <input type="password" name="password" required minlength="8">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">إنشاء الحساب</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
