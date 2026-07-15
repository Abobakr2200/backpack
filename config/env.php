<?php
/**
 * تحميل متغيرات البيئة من ملف .env
 * بديل بسيط لمكتبة vlucas/phpdotenv بدون الحاجة لـ composer
 *
 * ملحوظة مهمة: كتير من الاستضافات المجانية (زي InfinityFree) بتمنع
 * دالة putenv() لأسباب أمنية، فبنخزن القيم في مصفوفة داخلية بدل ما نعتمد
 * على putenv()/getenv() اللي ممكن تفشل بصمت من غير أي تحذير.
 */

function &_envStore(): array {
    static $store = [];
    return $store;
}

function loadEnv(string $path): void {
    if (!is_file($path)) {
        return;
    }

    $store = &_envStore();
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // تجاهل التعليقات والأسطر الفارغة
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);

        // إزالة علامات التنصيص لو موجودة
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($name === '') {
            continue;
        }

        // نخزن في مصفوفتنا الداخلية (المصدر الأساسي والموثوق)
        $store[$name] = $value;

        // ونحاول كمان نظبط $_ENV/$_SERVER وputenv() كطبقة توافق إضافية،
        // لكن من غير ما نعتمد عليهم لو فشلوا (بعض الاستضافات بتمنعهم)
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
        if (function_exists('putenv')) {
            @putenv("$name=$value");
        }
    }
}

// حمّل ملف .env من جذر المشروع (لو موجود)
loadEnv(__DIR__ . '/../.env');

/**
 * دالة مساعدة لقراءة متغير بيئة مع قيمة افتراضية.
 * بتدور بالترتيب: المصفوفة الداخلية (الأوثق) → $_ENV → $_SERVER → getenv()
 */
function env(string $key, $default = null) {
    $store = &_envStore();

    if (array_key_exists($key, $store)) {
        $value = $store[$key];
    } elseif (array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    } elseif (array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    } else {
        $fromGetenv = getenv($key);
        if ($fromGetenv === false) {
            return $default;
        }
        $value = $fromGetenv;
    }

    // تحويل القيم المنطقية النصية
    $lower = strtolower((string)$value);
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    if ($lower === 'null')  return null;
    return $value;
}

