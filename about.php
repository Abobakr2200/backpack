<?php
/**
 * صفحة "من نحن" — صفحة عامة، مش محتاجة تسجيل دخول
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backpack</title>
  <meta name="description" content="تعرّف على Backpack، منصة التواصل الاجتماعي والمحادثات الفورية اللي بتقرّب الناس من بعضها.">
  <meta property="og:title" content="من نحن — Backpack">
  <meta property="og:description" content="تعرّف على Backpack، منصة التواصل الاجتماعي والمحادثات الفورية.">
  <meta property="og:type" content="website">
  <meta property="og:image" content="/public/assets/img/icon-512.png">
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#4f6ef7">
  <style>
    .static-page { max-width: 720px; margin: 0 auto; padding: 40px 20px 80px; }
    .static-page .brand-row { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
    .static-page .brand-row img { width: 48px; height: 48px; border-radius: var(--radius-sm); object-fit: cover; }
    .static-page .brand-row span { font-size: 1.4rem; font-weight: 800; color: var(--text-primary); }
    .static-page h1 { font-size: 1.6rem; margin-bottom: 14px; color: var(--text-primary); }
    .static-page h2 { font-size: 1.15rem; margin: 26px 0 10px; color: var(--text-primary); }
    .static-page p, .static-page li { color: var(--text-secondary); line-height: 1.9; font-size: .96rem; }
    .static-page ul { padding-inline-start: 20px; }
    .static-page .back-link { display: inline-block; margin-top: 30px; color: var(--brand); font-weight: 600; text-decoration: none; }
    .static-page .footer-links { margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); display: flex; gap: 16px; font-size: .85rem; }
    .static-page .footer-links a { color: var(--text-secondary); text-decoration: none; }
    .static-page .footer-links a:hover { color: var(--brand); }
  </style>
</head>
<body>
  <div class="static-page">
    <div class="brand-row">
      <img src="/public/assets/img/icon-512.png" alt="Backpack">
      <span>Backpack</span>
    </div>

    <h1>من نحن</h1>
    <p>
      Backpack منصة تواصل اجتماعي بسيطة وسريعة، هدفها إنها تجمع أصحابك ومحادثاتك ومنشوراتك
      في مكان واحد مريح. سواء كنت عايز تتكلم مع صحابك بالشات، تتابع أخبارهم في الفيد، أو تشارك
      لحظاتك، Backpack مصمم عشان يخليك متواصل من غير تعقيد.
    </p>

    <h2>إيه اللي تقدر تعمله على Backpack؟</h2>
    <ul>
      <li>محادثات فورية نصية وصوتية ومكالمات فيديو مع أصحابك.</li>
      <li>مشاركة منشورات وصور والتفاعل معاها بالإعجاب والتعليقات.</li>
      <li>متابعة الأصدقاء ومشاهدة آخر أخبارهم في فيد مخصص.</li>
      <li>إشعارات فورية عشان متفوتك حاجة.</li>
    </ul>

    <h2>خصوصيتك أولويتنا</h2>
    <p>
      بنحافظ على بياناتك وخصوصيتك بأقصى درجة أمان ممكنة. تقدر تقرأ تفاصيل أكتر في
      <a href="/privacy.php" style="color:var(--brand);font-weight:600">سياسة الخصوصية</a> بتاعتنا.
    </p>

    <a href="/app/Modules/Auth/Views/login.php" class="back-link"><i class="fas fa-arrow-right"></i> رجوع لتسجيل الدخول</a>

    <div class="footer-links">
      <a href="/about.php">من نحن</a>
      <a href="/privacy.php">سياسة الخصوصية</a>
      <a href="/app/Modules/Auth/Views/login.php">تسجيل الدخول</a>
    </div>
  </div>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
