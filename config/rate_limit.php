<?php
/**
 * حد بسيط لمحاولات تسجيل الدخول الفاشلة (Brute Force Protection)
 * بيخزن العدادات في ملفات مؤقتة، مفيش حاجة تتضاف لقاعدة البيانات
 */

define('RATE_LIMIT_DIR', __DIR__ . '/../storage/rate_limit/');
define('RATE_LIMIT_MAX_ATTEMPTS', 5);      // أقصى عدد محاولات فاشلة
define('RATE_LIMIT_LOCKOUT_SECONDS', 300); // القفل لمدة 5 دقايق بعد تجاوز الحد

function rateLimitKey(string $identifier): string {
    if (!is_dir(RATE_LIMIT_DIR)) {
        mkdir(RATE_LIMIT_DIR, 0755, true);
    }
    // نجمع رقم الهاتف + IP عشان نمنع تبديل واحد بس من تجاوز الحماية
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return RATE_LIMIT_DIR . md5($identifier . '|' . $ip) . '.json';
}

/**
 * هل الحساب/الـIP ده متقفل حالياً بسبب محاولات فاشلة كتير؟
 * ترجع عدد الثواني المتبقية للقفل، أو 0 لو مش متقفل
 */
function rateLimitSecondsRemaining(string $identifier): int {
    $file = rateLimitKey($identifier);
    if (!is_file($file)) return 0;

    $data = json_decode(file_get_contents($file), true);
    if (!$data) return 0;

    if ($data['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS) {
        $elapsed = time() - $data['first_attempt_at'];
        $remaining = RATE_LIMIT_LOCKOUT_SECONDS - $elapsed;
        return max(0, $remaining);
    }
    return 0;
}

/** تسجيل محاولة فاشلة */
function rateLimitRecordFailure(string $identifier): void {
    $file = rateLimitKey($identifier);
    $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;

    if (!$data || (time() - $data['first_attempt_at']) > RATE_LIMIT_LOCKOUT_SECONDS) {
        $data = ['attempts' => 0, 'first_attempt_at' => time()];
    }

    $data['attempts']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/** تصفير المحاولات بعد نجاح تسجيل الدخول */
function rateLimitReset(string $identifier): void {
    $file = rateLimitKey($identifier);
    if (is_file($file)) {
        @unlink($file);
    }
}
