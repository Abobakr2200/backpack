<?php
/**
 * ولّد مفاتيح VAPID (عمومي + خاص) اللازمة لإشعارات Push.
 *
 * طريقة الاستخدام:
 *   1) composer install   (لازم يتنفذ مرة على جهازك أو أي مكان فيه composer)
 *   2) php generate_vapid_keys.php
 *   3) انسخ القيم اللي هتطبع وحطها في ملف .env بتاعك على السيرفر:
 *        VAPID_PUBLIC_KEY=...
 *        VAPID_PRIVATE_KEY=...
 *
 * ملحوظة: نفّذ السكربت ده مرة واحدة بس وخزّن المفاتيح. لو غيّرتهم بعد كده
 * كل الاشتراكات القديمة (الأجهزة اللي فعّلت الإشعارات قبل كده) هتوقف
 * وهيحتاجوا يفعّلوا الإشعارات تاني.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "═══════════════════════════════════════════\n";
echo "  انسخ السطرين دول في ملف .env على السيرفر\n";
echo "═══════════════════════════════════════════\n\n";
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n\n";
echo "لا تشارك الـ PRIVATE KEY مع حد ولا ترفعه على Git.\n";
