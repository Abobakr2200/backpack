<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../Helpers/WebPushSender.php';

class Notification {
    private $conn;

    public function __construct() {
        $this->conn = connectDB();
    }

    // إضافة إشعار جديد
    public function add($user_id, $sender_id, $type, $post_id = null) {
        // لا ترسل إشعاراً لنفسك
        if ($user_id == $sender_id) return true;

        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, sender_id, type, post_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisi", $user_id, $sender_id, $type, $post_id);
        $ok = $stmt->execute();

        if ($ok) {
            $this->sendPush($user_id, $sender_id, $type, $post_id);
        }

        return $ok;
    }

    // يبعت إشعار Push حقيقي (بيوصل حتى لو الموقع مقفول تمامًا)
    private function sendPush($user_id, $sender_id, $type, $post_id) {
        try {
            $stmt = $this->conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $sender_id);
            $stmt->execute();
            $sender = $stmt->get_result()->fetch_assoc();
            $name   = $sender['username'] ?? 'شخص ما';

            $labels = [
                'like'    => $name . ' أعجب بمنشورك',
                'comment' => $name . ' علّق على منشورك',
                'follow'  => $name . ' بدأ متابعتك',
            ];
            $body = $labels[$type] ?? ($name . ' تفاعل معك');
            $url  = $post_id ? '/app/Modules/Posts/Views/feed.php#post-' . (int)$post_id
                              : '/app/Modules/Profile/Views/profile.php?id=' . (int)$sender_id;

            WebPushSender::sendToUser((int)$user_id, [
                'title' => 'Backpack',
                'body'  => $body,
                'url'   => $url,
                'tag'   => 'chat-ag-notif-' . $type,
            ]);
        } catch (\Throwable $e) {}
    }


    // الحصول على إشعارات المستخدم
    public function getByUser($user_id, $limit = 20) {
        $stmt = $this->conn->prepare("
            SELECT n.*, u.username, u.profile_photo 
            FROM notifications n
            JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // وضع علامة كمقروءة
    public function markAsRead($user_id) {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }

    // الحصول على عدد الإشعارات غير المقروءة
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['unread'];
    }
}
?>
