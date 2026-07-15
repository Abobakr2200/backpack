<?php
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../Controllers/AuthController.php';

if (isLoggedIn()) {
    header("Location: /app/Modules/Posts/Views/feed.php");
    exit();
}

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة، حاول تاني';
    } else {
        $auth   = new AuthController();
        $result = $auth->register(
            $_POST['username']         ?? '',
            $_POST['phone']            ?? '',
            $_POST['password']         ?? '',
            $_POST['password_confirm'] ?? ''
        );
        if ($result['success']) {
            $success = 'تم إنشاء الحساب بنجاح! جاري التوجيه...';
            header("Refresh: 2; url=/app/Modules/Auth/Views/login.php");
        } else {
            $error = $result['message'];
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
  <title>Backpack</title>
  <meta name="description" content="أنشئ حساب جديد على Backpack وابدأ التواصل مع أصحابك فوراً.">
  <meta property="og:title" content="إنشاء حساب — Backpack">
  <meta property="og:description" content="أنشئ حساب جديد على Backpack وابدأ التواصل مع أصحابك فوراً.">
  <meta property="og:type" content="website">
  <meta property="og:image" content="/public/assets/img/icon-512.png">
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="manifest" href="/manifest.json">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="logo"><img src="/public/assets/img/icon-512.png" alt="Backpack"></div>
      <h1>إنشاء حساب جديد</h1>
      <p>انضم إلى Backpack برقم هاتفك</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= $success ?>
      </div>
    <?php endif; ?>

    <form method="POST" id="regForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <div class="form-group">
        <label for="username">اسم المستخدم</label>
        <input type="text" id="username" name="username"
               placeholder="الاسم الذي سيظهر للآخرين"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               required minlength="3" autocomplete="nickname">
      </div>

      <div class="form-group">
        <label for="phone">رقم الهاتف</label>
        <input type="tel" id="phone" name="phone"
               placeholder="مثال: 01012345678"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
               required inputmode="tel"
               pattern="[0-9+\-\s()]{10,15}">
        <small>أدخل رقم هاتفك المحمول (سيُستخدم لتسجيل الدخول)</small>
      </div>

      <div class="form-group">
        <label for="password">كلمة المرور</label>
        <div style="position:relative">
          <input type="password" id="password" name="password"
                 placeholder="٦ أحرف على الأقل"
                 required minlength="6" autocomplete="new-password">
          <button type="button" onclick="togglePwd('password','eye1')"
                  style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.9rem">
            <i id="eye1" class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label for="password_confirm">تأكيد كلمة المرور</label>
        <div style="position:relative">
          <input type="password" id="password_confirm" name="password_confirm"
                 placeholder="أعد كتابة كلمة المرور"
                 required minlength="6" autocomplete="new-password">
          <button type="button" onclick="togglePwd('password_confirm','eye2')"
                  style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.9rem">
            <i id="eye2" class="fas fa-eye"></i>
          </button>
        </div>
        <!-- مؤشر تطابق كلمة المرور -->
        <small id="matchMsg" style="display:none"></small>
      </div>

      <button type="submit" class="btn btn-primary" id="regBtn">
        <i class="fas fa-user-plus"></i> إنشاء الحساب
      </button>
    </form>

    <div class="auth-footer">
      لديك حساب؟ <a href="/app/Modules/Auth/Views/login.php">تسجيل الدخول</a>
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

    // مؤشر حقيقي لتطابق كلمة المرور
    const p1  = document.getElementById('password');
    const p2  = document.getElementById('password_confirm');
    const msg = document.getElementById('matchMsg');

    function checkMatch() {
      if (!p2.value) { msg.style.display = 'none'; return; }
      if (p1.value === p2.value) {
        msg.textContent = '✓ كلمتا المرور متطابقتان';
        msg.style.cssText = 'display:block;color:var(--success)';
      } else {
        msg.textContent = '✗ كلمتا المرور غير متطابقتين';
        msg.style.cssText = 'display:block;color:var(--danger)';
      }
    }

    p1.addEventListener('input', checkMatch);
    p2.addEventListener('input', checkMatch);

    document.getElementById('regForm').addEventListener('submit', function(e) {
      if (p1.value !== p2.value) {
        e.preventDefault();
        msg.textContent = '✗ كلمتا المرور غير متطابقتين — يرجى التصحيح';
        msg.style.cssText = 'display:block;color:var(--danger);font-weight:600';
        p2.focus();
        return;
      }
      const btn = document.getElementById('regBtn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري إنشاء الحساب...';
    });
  </script>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
