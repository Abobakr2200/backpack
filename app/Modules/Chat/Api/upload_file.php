<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../../../config/image_helper.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

// حماية CSRF: أي POST لازم يجيب التوكن الصحيح
requireCsrf();

$user_id = getUserId();
$upload_dir = __DIR__ . '/../../../../public/uploads/';

// إنشاء مجلد المستخدم إذا لم يكن موجوداً
$user_upload_dir = $upload_dir . $user_id . '/';
if (!is_dir($user_upload_dir)) {
    mkdir($user_upload_dir, 0755, true);
}

// التحقق من وجود ملف وعدم وجود خطأ في الرفع
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'لا يوجد ملف صالح']);
    exit();
}

$file      = $_FILES['file'];
$file_size = $file['size'];
$file_tmp  = $file['tmp_name'];

// التحقق من حجم الملف (الحد الأقصى 50 MB)
if ($file_size > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'حجم الملف كبير جداً']);
    exit();
}

// ⚠️ أهم تعديل: بنتجاهل تماماً $_FILES['file']['type'] و اسم الملف الأصلي
// لأنهم بيتبعتوا من المتصفح وسهل جداً تتزوّر (attacker يقدر يبعت ملف .php
// ويقول عنه إنه image/jpeg). بدل كده بنفحص المحتوى الحقيقي للملف على السيرفر.

// خريطة: نوع MIME الحقيقي (من محتوى الملف) => الامتداد المسموح بيه
$allowed_mime_to_ext = [
    'image/jpeg'                                                            => 'jpg',
    'image/png'                                                             => 'png',
    'image/gif'                                                             => 'gif',
    'image/webp'                                                            => 'webp',
    'application/pdf'                                                       => 'pdf',
    'application/msword'                                                    => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel'                                              => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'     => 'xlsx',
    'text/plain'                                                            => 'txt',
    'application/zip'                                                       => 'zip',
    // رسائل صوتية (المتصفح بيسجلها بصيغ مختلفة حسب الجهاز)
    'audio/webm'                                                            => 'weba',
    'audio/ogg'                                                             => 'oga',
    'audio/mp4'                                                             => 'm4a',
    'audio/mpeg'                                                            => 'mp3',
    'audio/aac'                                                             => 'aac',
    'audio/wav'                                                             => 'wav',
    // بعض المتصفحات بتصنف الصوت المسجّل webm-only كـ video/webm لأن
    // الحاوية (container) نفسها webm سواء للصوت أو الفيديو
    'video/webm'                                                            => 'weba',
];

// finfo يفحص "magic bytes" الفعلية جوه الملف، مش مجرد Header بيبعته المتصفح
$finfo = new finfo(FILEINFO_MIME_TYPE);
$real_mime = $finfo->file($file_tmp);

// ملفات Office الحديثة (docx/xlsx) هي عملياً أرشيف zip من الداخل،
// فـ finfo أحياناً بيرجعها application/zip. الاسم الأصلي هنا بيُستخدم فقط
// كتلميح لاختيار الصيغة الصحيحة (لأن الملف أصلاً اتأكد إنه zip حقيقي بالفعل،
// مش لأننا بنثق في الاسم كمصدر أمان أساسي).
$zip_mime_by_ext = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
if ($real_mime === 'application/zip') {
    $orig_ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (isset($zip_mime_by_ext[$orig_ext])) {
        $real_mime = $zip_mime_by_ext[$orig_ext];
    }
}

if ($real_mime === false || !isset($allowed_mime_to_ext[$real_mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'نوع الملف غير مسموح']);
    exit();
}

// لو الملف مصنّف كصورة، نتأكد كمان إنه فعلاً صورة قابلة للفتح (طبقة حماية إضافية)
$is_image = str_starts_with($real_mime, 'image/');
if ($is_image && @getimagesize($file_tmp) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ملف الصورة تالف أو غير صالح']);
    exit();
}

$is_audio = str_starts_with($real_mime, 'audio/') || $real_mime === 'video/webm';

// اسم الملف النهائي والامتداد مبنيين على الفحص الحقيقي مش أي مدخل من المستخدم
$safe_extension   = $allowed_mime_to_ext[$real_mime];
$unique_filename  = bin2hex(random_bytes(16)) . '.' . $safe_extension;
$destination      = $user_upload_dir . $unique_filename;

$saved = false;

// لو الملف صورة، نحاول نضغطها/نصغّرها عشان تبقى خفيفة وتحمّل بسرعة في الشات.
// إعادة الترميز دي كمان بتشيل أي بيانات زيادة مدسوسة جوه ملف الصورة (طبقة حماية إضافية).
if ($is_image) {
    $saved = compressImage($file_tmp, $real_mime, $destination, 1600, 75);
}

// لو مش صورة، أو الضغط فشل (مثلاً GD مش متاحة على الاستضافة)، ننقل الملف الأصلي زي ما هو
if (!$saved) {
    $saved = move_uploaded_file($file_tmp, $destination);
}

if ($saved) {
    $file_path = 'public/uploads/' . $user_id . '/' . $unique_filename;
    echo json_encode([
        'success'    => true,
        'file_path'  => $file_path,
        'message'    => 'تم رفع الملف بنجاح',
        'is_image'   => $is_image,
        'is_audio'   => $is_audio,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطأ في رفع الملف']);
}
