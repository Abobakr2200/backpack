<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/logger.php';

// بيانات الاتصال تُقرأ الآن من ملف .env (خارج نطاق الكود المرفوع/المشارك)
// راجع ملف .env.example لمعرفة الحقول المطلوبة
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', ''));

// إنشاء اتصال بقاعدة البيانات
function connectDB() {
    // لو أي من بيانات الاتصال فاضية، غالباً ملف .env مش موجود على السيرفر
    // أو مرفوعش مع باقي الملفات (بعض برامج الـ FTP بتتجاهل الملفات
    // اللي بتبدأ بنقطة زي .env افتراضياً)
    if (DB_USER === '' || DB_NAME === '') {
        Logger::error('DB config missing', ['hint' => 'تأكد إن ملف .env مرفوع فعلاً على السيرفر في جذر المشروع']);
        http_response_code(500);
        die('إعدادات الخادم غير مكتملة (.env). راجع السيرفر.');
    }

    mysqli_report(MYSQLI_REPORT_OFF); // نتحكم في الأخطاء يدويًا بدل ما نسيبها تظهر للمستخدم

    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        // منسجّلش تفاصيل الاتصال للمستخدم، بس نسجلها في اللوج الداخلي
        Logger::error('DB connection failed', ['error' => $conn->connect_error]);
        http_response_code(500);
        die('حدث خطأ في الاتصال بالخادم، حاول لاحقاً');
    }

    // مهم جداً: من غير السطر ده، PHP بيتعامل مع الاتصال بـ latin1 افتراضياً
    // حتى لو الجدول نفسه utf8mb4 — ده اللي بيسبب ظهور العربي كرموز غريبة
    // زي "Ø§Ø¨Ø±Ø§ÙÙŠÙ…" بدل النص العربي الصحيح.
    if (!$conn->set_charset('utf8mb4')) {
        Logger::error('Failed to set utf8mb4 charset', ['error' => $conn->error]);
    }

    return $conn;
}
