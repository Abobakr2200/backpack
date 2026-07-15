<?php
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Controllers/AuthController.php';

if (isLoggedIn()) {
    header("Location: /app/Modules/Posts/Views/feed.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة، حاول تاني';
    } else {
        $auth   = new AuthController();
        $result = $auth->login($_POST['phone'] ?? '', $_POST['password'] ?? '');
        if ($result['success']) {
            session_regenerate_id(true); // يمنع Session Fixation بعد تسجيل الدخول
            header("Location: /");
            exit();
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backpack</title>
  <meta name="description" content="سجّل دخولك على Backpack، منصة التواصل الاجتماعي والمحادثات الفورية.">
  <meta property="og:title" content="Backpack">
  <meta property="og:description" content="منصة التواصل الاجتماعي والمحادثات الفورية.">
  <meta property="og:type" content="website">
  <meta property="og:image" content="/public/assets/img/icon-512.png">
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#4f6ef7">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="logo"><img src="/public/assets/img/icon-512.png" alt="Backpack"></div>
      <h1>Backpack</h1>
      <p>سجّل دخولك برقم هاتفك</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-group">
        <label for="phone">رقم الهاتف</label>
        <input type="tel" id="phone" name="phone"
               placeholder="مثال: 01012345678"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
               required autofocus
               inputmode="tel">
      </div>
      <div class="form-group">
        <label for="password">كلمة المرور</label>
        <div style="position:relative">
          <input type="password" id="password" name="password"
                 placeholder="••••••••" required>
          <button type="button" onclick="togglePwd('password','eyeIcon1')"
                  style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.9rem">
            <i id="eyeIcon1" class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" id="loginBtn">
        <i class="fas fa-sign-in-alt"></i> تسجيل الدخول
      </button>
    </form>

    <div class="auth-footer">
      ليس لديك حساب؟ <a href="/app/Modules/Auth/Views/register.php">إنشاء حساب جديد</a>
    </div>

    <div style="text-align:center;margin-top:18px;font-size:.78rem;display:flex;justify-content:center;gap:14px">
      <a href="/about.php" style="color:var(--text-secondary)">من نحن</a>
      <a href="/privacy.php" style="color:var(--text-secondary)">سياسة الخصوصية</a>
    </div>
  </div>

  <script src="/public/assets/js/app.js"></script>
  <script>
    function togglePwd(fieldId, iconId) {
      const f = document.getElementById(fieldId);
      const i = document.getElementById(iconId);
      if (f.type === 'password') { f.type = 'text'; i.className = 'fas fa-eye-slash'; }
      else                       { f.type = 'password'; i.className = 'fas fa-eye'; }
    }

    document.getElementById('loginForm').addEventListener('submit', function() {
      const btn = document.getElementById('loginBtn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحقق...';
    });
  </script>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
