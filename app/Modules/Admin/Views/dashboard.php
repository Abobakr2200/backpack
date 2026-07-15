<?php
require_once __DIR__ . '/../../../../config/admin_session.php';
require_once __DIR__ . '/../Models/Admin.php';

requireAdmin();

$model  = new AdminModel();
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$users = $model->getAllUsers($search, $limit, $offset);
$total = $model->countUsers($search);
$pages = max(1, ceil($total / $limit));
$stats = $model->getStats();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>لوحة تحكم الأدمن</title>
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <meta name="robots" content="noindex, nofollow">
  <style>
    .admin-table{width:100%;border-collapse:collapse;font-size:.875rem}
    .admin-table th,.admin-table td{padding:10px 12px;text-align:right;border-bottom:1px solid var(--border)}
    .admin-table th{background:var(--bg-surface);font-weight:700;color:var(--text-secondary)}
    .badge{padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600}
    .badge-banned{background:#fee2e2;color:#b91c1c}
    .badge-active{background:#dcfce7;color:#15803d}
    .stat-card{flex:1;min-width:120px;padding:16px;text-align:center}
    .stat-num{font-size:1.5rem;font-weight:800}
  </style>
</head>
<body>
<div class="app-shell">
  <header class="top-header">
    <span style="flex:1;font-size:1.05rem;font-weight:700"><i class="fas fa-shield-alt"></i> لوحة تحكم الأدمن</span>
    <span style="font-size:.85rem;color:var(--text-secondary);margin-left:10px"><?= htmlspecialchars($_SESSION['admin_username']) ?></span>
    <a href="/app/Modules/Admin/Views/logout.php" class="icon-btn danger" title="خروج"><i class="fas fa-sign-out-alt"></i></a>
  </header>

  <div class="page-main">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
      <div class="card stat-card"><div class="stat-num"><?= (int)$stats['total'] ?></div><div>إجمالي المستخدمين</div></div>
      <div class="card stat-card"><div class="stat-num" style="color:#15803d"><?= (int)$stats['online'] ?></div><div>متصل الآن</div></div>
      <div class="card stat-card"><div class="stat-num" style="color:#b91c1c"><?= (int)$stats['banned'] ?></div><div>محظورين</div></div>
      <div class="card stat-card"><div class="stat-num"><?= (int)$stats['today'] ?></div><div>سجّلوا النهارده</div></div>
    </div>

    <div id="alertBox"></div>

    <form method="GET" style="margin-bottom:14px;display:flex;gap:8px">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="بحث بالاسم أو رقم الهاتف..."
             style="flex:1;padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:.9rem">
      <button type="submit" class="btn btn-primary" style="width:auto;padding:10px 20px"><i class="fas fa-search"></i></button>
    </form>

    <div class="card" style="padding:0;overflow-x:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>#</th>
            <th>اسم المستخدم</th>
            <th>رقم الهاتف</th>
            <th>تاريخ التسجيل</th>
            <th>الحالة</th>
            <th>إجراء</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr data-uid="<?= (int)$u['id'] ?>">
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td dir="ltr" style="text-align:right"><?= htmlspecialchars($u['phone']) ?></td>
            <td><?= htmlspecialchars($u['created_at']) ?></td>
            <td>
              <?php if ($u['is_banned']): ?>
                <span class="badge badge-banned" title="<?= htmlspecialchars($u['ban_reason'] ?? '') ?>">محظور</span>
              <?php else: ?>
                <span class="badge badge-active">نشط</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['is_banned']): ?>
                <button class="btn btn-secondary unban-btn" style="width:auto;padding:6px 14px;font-size:.8rem">
                  <i class="fas fa-user-check"></i> إلغاء الحظر
                </button>
              <?php else: ?>
                <button class="btn ban-btn" style="width:auto;padding:6px 14px;font-size:.8rem;background:#fee2e2;color:#b91c1c">
                  <i class="fas fa-ban"></i> حظر
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($users)): ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-secondary)">مفيش نتائج</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:14px">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>"
           class="btn <?= $p == $page ? 'btn-primary' : 'btn-secondary' ?>"
           style="width:auto;padding:6px 12px;font-size:.8rem"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

function showAlert(msg, type) {
  var box = document.getElementById('alertBox');
  box.innerHTML = '<div class="alert alert-' + (type === 'error' ? 'error' : 'success') + '">' + msg + '</div>';
  setTimeout(function () { box.innerHTML = ''; }, 3000);
}

function callBanApi(uid, action, reason) {
  return fetch('/app/Modules/Admin/Api/ban_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: uid, action: action, reason: reason || '', csrf_token: window.CSRF_TOKEN })
  }).then(function (r) { return r.json(); });
}

document.querySelectorAll('.ban-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var row = btn.closest('tr');
    var uid = row.getAttribute('data-uid');
    var reason = prompt('سبب الحظر (اختياري):', '');
    if (reason === null) return; // المستخدم عمل إلغاء
    if (!confirm('هل أنت متأكد من حظر هذا المستخدم؟ هيتقفل حسابه فورًا حتى لو داخل دلوقتي.')) return;

    btn.disabled = true;
    callBanApi(uid, 'ban', reason)
      .then(function (data) {
        if (data.success) { location.reload(); }
        else { btn.disabled = false; showAlert(data.message || 'حدث خطأ', 'error'); }
      })
      .catch(function () { btn.disabled = false; showAlert('خطأ في الاتصال', 'error'); });
  });
});

document.querySelectorAll('.unban-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var row = btn.closest('tr');
    var uid = row.getAttribute('data-uid');
    if (!confirm('إلغاء حظر هذا المستخدم؟')) return;

    btn.disabled = true;
    callBanApi(uid, 'unban')
      .then(function (data) {
        if (data.success) { location.reload(); }
        else { btn.disabled = false; showAlert(data.message || 'حدث خطأ', 'error'); }
      })
      .catch(function () { btn.disabled = false; showAlert('خطأ في الاتصال', 'error'); });
  });
});
</script>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
