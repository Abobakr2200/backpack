<?php
/**
 * إرسال إشعارات Push حقيقية عبر الشبكة (Web Push Protocol).
 *
 * الفرق عن جدول notifications العادي:
 * - notifications: بيتقرا بالـ polling وقت ما الموقع مفتوح عند المستخدم فقط.
 * - WebPushSender: بيبعت الإشعار من السيرفر مباشرة لمتصفح/هاتف المستخدم
 *   حتى لو الموقع مقفول تمامًا (لازم يكون ثبّت الموقع كـ PWA أو فعّل
 *   الإشعارات من المتصفح على الأقل مرة واحدة).
 *
 * محتاج: composer install (مكتبة minishlink/web-push) + مفاتيح VAPID في .env
 * لو أي منهم ناقص، الدالة بترجع من غير ما تعمل حاجة (مافيش كراش للموقع).
 */

require_once __DIR__ . '/../../../../config/env.php';
require_once __DIR__ . '/../../../../config/logger.php';
require_once __DIR__ . '/../Models/PushSubscription.php';

class WebPushSender {

    private static function vendorAvailable(): bool {
        return is_file(__DIR__ . '/../../../../vendor/autoload.php');
    }

    private static function vapidReady(): bool {
        return (bool) env('VAPID_PUBLIC_KEY') && (bool) env('VAPID_PRIVATE_KEY');
    }

    /**
     * يبعت إشعار push لكل أجهزة/متصفحات مستخدم معين.
     *
     * @param int   $user_id  صاحب الإشعار
     * @param array $payload  ['title'=>, 'body'=>, 'icon'=>, 'url'=>, 'tag'=>, 'requireInteraction'=>bool]
     */
    public static function sendToUser(int $user_id, array $payload): void {
        if (!self::vendorAvailable() || !self::vapidReady()) {
            // إعدادات Push لسه مش متظبطة على السيرفر ده — تجاهل بهدوء
            return;
        }

        require_once __DIR__ . '/../../../../vendor/autoload.php';

        $subModel      = new PushSubscription();
        $subscriptions = $subModel->getByUser($user_id);
        if (!$subscriptions) return;

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
                    'publicKey'  => env('VAPID_PUBLIC_KEY'),
                    'privateKey' => env('VAPID_PRIVATE_KEY'),
                ],
            ]);
        } catch (\Throwable $e) {
            Logger::error('WebPush init failed', ['error' => $e->getMessage()]);
            return;
        }

        $body = json_encode([
            'title' => $payload['title'] ?? 'Backpack',
            'body'  => $payload['body']  ?? '',
            'icon'  => $payload['icon']  ?? '/public/assets/img/icon-192.png',
            'url'   => $payload['url']   ?? '/',
            'tag'   => $payload['tag']   ?? 'chat-ag',
            'requireInteraction' => !empty($payload['requireInteraction']),
            'renotify'  => !empty($payload['renotify']),
            'vibrate'   => $payload['vibrate']  ?? [200, 100, 200],
            'actions'   => $payload['actions']  ?? [],
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $row) {
            $webPush->queueNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
                ]),
                $body
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) continue;

            $endpoint = $report->getEndpoint();
            // لو الاشتراك بقى منتهي/غير صالح (المستخدم شال الموقع أو ألغى الإذن)، امسحه
            if ($report->isSubscriptionExpired()) {
                $subModel->deleteByEndpoint($endpoint);
            } else {
                Logger::error('WebPush send failed', ['endpoint' => $endpoint, 'reason' => $report->getReason()]);
            }
        }
    }
}
