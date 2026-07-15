<?php
require_once __DIR__ . '/../../../../config/database.php';

class TypingStatus {
    private $conn;

    // بعد كام ثانية من آخر ping نعتبر إن المستخدم "بطّل يكتب" حتى لو مبعتش
    // إشعار صريح بإنه وقف (مثلاً لو قفل التاب فجأة)
    const TTL_SECONDS = 6;

    public function __construct() {
        $this->conn = connectDB();
        $this->conn->set_charset('utf8mb4');
    }

    // المستخدم بيكتب دلوقتي لمين (upsert)
    public function ping($user_id, $receiver_id) {
        $stmt = $this->conn->prepare(
            "INSERT INTO typing_status (user_id, receiver_id, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE receiver_id = VALUES(receiver_id), updated_at = NOW()"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $user_id, $receiver_id);
        return $stmt->execute();
    }

    // المستخدم وقف يكتب (بيمسح الصف بتاعه)
    public function stop($user_id) {
        $stmt = $this->conn->prepare("DELETE FROM typing_status WHERE user_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('i', $user_id);
        return $stmt->execute();
    }

    // هل peer_id بيكتب لـ my_id دلوقتي؟
    public function isTypingToMe($peer_id, $my_id) {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM typing_status
             WHERE user_id = ? AND receiver_id = ?
               AND updated_at >= (NOW() - INTERVAL " . self::TTL_SECONDS . " SECOND)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $peer_id, $my_id);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }
}
