<?php
/**
 * نظام تسجيل بسيط للأخطاء والأحداث المهمة.
 * بيكتب في ملفات داخل storage/logs (محمية بـ .htaccess من الوصول المباشر).
 */

class Logger {
    private static function logDir(): string {
        $dir = __DIR__ . '/../storage/logs/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function write(string $level, string $message, array $context = []): void {
        $dir  = self::logDir();
        $file = $dir . date('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::write('error', $message, $context);
    }

    /** تسجيل استثناء بشكل موحّد مع تفاصيله */
    public static function exception(\Throwable $e, array $context = []): void {
        self::write('error', $e->getMessage(), array_merge($context, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]));
    }
}
