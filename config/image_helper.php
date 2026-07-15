<?php
/**
 * دوال مساعدة لمعالجة الصور المرفوعة:
 * - تصغير الأبعاد لو كبيرة جداً
 * - إعادة ضغطها (تقليل الجودة شوية) عشان تبقى خفيفة وتحمّل بسرعة
 * - إعادة الترميز من الصفر (مش مجرد نسخ) بيشيل أي بيانات زيادة مدسوسة
 *   جوه ملف الصورة، وده طبقة حماية إضافية فوق فحص finfo
 *
 * لو مكتبة GD مش متاحة على الاستضافة، الدوال بترجع false وبيتم استخدام
 * الملف الأصلي زي ما هو (بعد ما يكون اتأكد بالفعل إنه صورة حقيقية بفحص finfo).
 */

/**
 * يضغط/يصغّر صورة وبيحفظها في المسار المطلوب.
 *
 * @param string $srcPath      مسار الملف المؤقت (tmp_name)
 * @param string $realMime     نوع الصورة الحقيقي (من finfo)
 * @param string $destPath     مسار الحفظ النهائي
 * @param int    $maxDimension أقصى عرض/ارتفاع (بالبكسل)
 * @param int    $quality      جودة JPEG/WEBP (0-100)
 * @return bool                true لو نجح الضغط والحفظ، false لو فشل (لازم تستخدم الملف الأصلي)
 */
function compressImage(string $srcPath, string $realMime, string $destPath, int $maxDimension = 1600, int $quality = 75): bool {
    if (!extension_loaded('gd')) {
        return false;
    }

    $image = null;
    switch ($realMime) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($srcPath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($srcPath);
            }
            break;
        case 'image/gif':
            // الـ GIF المتحركة بتفقد الحركة لو اتعاد ترميزها بالطريقة دي،
            // فبنسيبها زي ما هي من غير ضغط
            return false;
        default:
            return false;
    }

    if (!$image) {
        return false;
    }

    $width  = imagesx($image);
    $height = imagesy($image);

    // تصغير الأبعاد لو أكبر من الحد المسموح (مع الحفاظ على النسبة)
    if ($width > $maxDimension || $height > $maxDimension) {
        if ($width >= $height) {
            $newWidth  = $maxDimension;
            $newHeight = (int)round($height * ($maxDimension / $width));
        } else {
            $newHeight = $maxDimension;
            $newWidth  = (int)round($width * ($maxDimension / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // الحفاظ على الشفافية لو PNG
        if ($realMime === 'image/png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    $ok = false;
    switch ($realMime) {
        case 'image/jpeg':
            $ok = imagejpeg($image, $destPath, $quality);
            break;
        case 'image/png':
            // مقياس ضغط PNG من 0 (بدون ضغط) لحد 9 (أقصى ضغط)
            $ok = imagepng($image, $destPath, 8);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) {
                $ok = imagewebp($image, $destPath, $quality);
            }
            break;
    }

    imagedestroy($image);
    return (bool)$ok;
}
