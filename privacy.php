<?php
/**
 * صفحة سياسة الخصوصية — صفحة عامة، مش محتاجة تسجيل دخول
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Backpack</title>
  <meta name="description" content="سياسة الخصوصية الخاصة بمنصة Backpack: إزاي بنجمع بياناتك ونستخدمها ونحميها.">
  <meta property="og:title" content="سياسة الخصوصية — Backpack">
  <meta property="og:description" content="إزاي بنجمع بياناتك ونستخدمها ونحميها على Backpack.">
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
    .static-page h1 { font-size: 1.6rem; margin-bottom: 6px; color: var(--text-primary); }
    .static-page .updated { font-size: .8rem; color: var(--text-secondary); margin-bottom: 20px; }
    .static-page h2 { font-size: 1.1rem; margin: 24px 0 8px; color: var(--text-primary); }
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

    <h1>سياسة الخصوصية</h1>
    <p class="updated">آخر تحديث: <?= date('d/m/Y') ?></p>

    <p>
      خصوصيتك مهمة بالنسبالنا. الصفحة دي بتشرح إزاي منصة Backpack بتجمع بياناتك،
      بتستخدمها، وبتحميها وقت استخدامك للموقع والتطبيق.
    </p>

    <h2>١. البيانات اللي بنجمعها</h2>
    <ul>
      <li>بيانات الحساب: اسم المستخدم، رقم الهاتف، والبريد الإلكتروني (لو موجود).</li>
      <li>محتوى بتنشئه: المنشورات، الصور، الرسائل، والتعليقات.</li>
      <li>بيانات تقنية: نوع الجهاز والمتصفح، وسجلات الدخول الأساسية لأغراض الأمان.</li>
    </ul>

    <h2>٢. إزاي بنستخدم بياناتك</h2>
    <ul>
      <li>تشغيل الخدمات الأساسية زي الشات، الفيد، والإشعارات.</li>
      <li>حماية حسابك ومنع الاستخدام غير المصرح به.</li>
      <li>تحسين تجربة استخدامك للمنصة باستمرار.</li>
    </ul>

    <h2>٣. مشاركة البيانات</h2>
    <p>
      مبنبيعش ولا نأجّر بياناتك الشخصية لأي طرف تالت. بياناتك بتتشارك بس في الحدود
      اللازمة لتشغيل الخدمة (زي مزوّد الاستضافة) أو لو القانون يطلب كده.
    </p>

    <h2>٤. أمان بياناتك</h2>
    <p>
      بنستخدم إجراءات حماية زي تشفير كلمات المرور وحماية الجلسات لتقليل مخاطر الوصول
      غير المصرح به. مع ذلك، محدش يقدر يضمن أمان مطلق بنسبة ١٠٠٪ على الإنترنت.
    </p>

    <h2>٥. حقوقك</h2>
    <ul>
      <li>تقدر تعدّل بيانات حسابك أو تحذف منشوراتك في أي وقت.</li>
      <li>تقدر تطلب حذف حسابك بالكامل وبياناته المرتبطة بيه.</li>
    </ul>

    <h2>٦. التواصل معنا</h2>
    <p>لو عندك أي استفسار عن سياسة الخصوصية، تقدر تتواصل معانا من خلال صفحة الدعم داخل التطبيق.</p>

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
