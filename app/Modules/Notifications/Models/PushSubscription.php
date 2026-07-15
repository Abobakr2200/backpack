<?php

require_once __DIR__ . '/../../../../config/database.php';

class PushSubscription {
    private $conn;

    public function __construct() {
        $this->conn = connectDB();
        $this->conn->set_charset('utf8mb4');
    }

    // حفظ (أو تحديث) اشتراك Push لجهاز/متصفح معين
    public function save($user_id, $endpoint, $p256dh, $auth, $user_agent = null) {
        $hash = hash('sha256', $endpoint);
        $stmt = $this->conn->prepare(
            "INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                                     auth = VALUES(auth), user_agent = VALUES(user_agent)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('isssss', $user_id, $endpoint, $hash, $p256dh, $auth, $user_agent);
        return $stmt->execute();
    }

    // حذف اشتراك (لما المستخدم يعطّل الإشعارات، أو لما endpoint يبقى منتهي الصلاحية)
    public function deleteByEndpoint($endpoint) {
        $hash = hash('sha256', $endpoint);
        $stmt = $this->conn->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ?");
        if (!$stmt) return false;
        $stmt->bind_param('s', $hash);
        return $stmt->execute();
    }

    public function deleteById($id) {
        $stmt = $this->conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    // كل اشتراكات (أجهزة) مستخدم معين
    public function getByUser($user_id) {
        $stmt = $this->conn->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        if (!$stmt) return [];
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
