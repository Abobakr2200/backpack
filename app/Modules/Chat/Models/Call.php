<?php
require_once __DIR__ . '/../../../../config/database.php';

class Call {
    private $conn;

    // لو المكالمة فضلت بترن أكتر من كده من غير رد، تعتبر "فاتت"
    const RING_TIMEOUT_SECONDS = 45;

    public function __construct() {
        $this->conn = connectDB();
        $this->conn->set_charset('utf8mb4');
    }

    // بدء مكالمة جديدة (المتصل بيبعت الـ offer بتاعه)
    public function start($caller_id, $callee_id, $offer_sdp, $call_type = 'audio') {
        $call_type = ($call_type === 'video') ? 'video' : 'audio';
        $stmt = $this->conn->prepare(
            "INSERT INTO calls (caller_id, callee_id, call_type, status, offer_sdp, started_at)
             VALUES (?, ?, ?, 'ringing', ?, NOW())"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iiss', $caller_id, $callee_id, $call_type, $offer_sdp);
        if (!$stmt->execute()) return false;
        return $this->conn->insert_id;
    }

    // هل المستخدم ده مشغول دلوقتي (بيرن أو في مكالمة شغالة)؟
    public function isBusy($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM calls
             WHERE (caller_id = ? OR callee_id = ?)
               AND status IN ('ringing','accepted')
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $user_id, $user_id);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function getById($call_id) {
        $stmt = $this->conn->prepare("SELECT * FROM calls WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $call_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    // مكالمة واردة (بترن) للمستخدم ده لسه ما انتهتش صلاحيتها
    public function getIncomingFor($user_id) {
        $this->expireStaleRinging();
        $stmt = $this->conn->prepare(
            "SELECT c.*, u.username AS caller_name, u.profile_photo AS caller_photo
             FROM calls c
             JOIN users u ON u.id = c.caller_id
             WHERE c.callee_id = ? AND c.status = 'ringing'
             ORDER BY c.id DESC LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    // حوّل أي مكالمة فضلت بترن كتير من غير رد لـ "فاتت"
    public function expireStaleRinging() {
        $this->conn->query(
            "UPDATE calls SET status = 'missed', ended_at = NOW()
             WHERE status = 'ringing'
               AND started_at < (NOW() - INTERVAL " . self::RING_TIMEOUT_SECONDS . " SECOND)"
        );
    }

    // المستقبل رد على المكالمة (بيبعت answer)
    public function accept($call_id, $callee_id, $answer_sdp) {
        $stmt = $this->conn->prepare(
            "UPDATE calls SET status = 'accepted', answer_sdp = ?, answered_at = NOW()
             WHERE id = ? AND callee_id = ? AND status = 'ringing'"
        );
        if (!$stmt) return false;
        $stmt->bind_param('sii', $answer_sdp, $call_id, $callee_id);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    // إنهاء/رفض/إلغاء المكالمة (حسب حالتها الحالية ومين اللي بينهيها)
    // بيرجع الحالة النهائية اللي اتسجلت، أو null لو مفيش تغيير حصل
    public function end($call_id, $user_id) {
        $call = $this->getById($call_id);
        if (!$call) return null;
        if ((int)$call['caller_id'] !== (int)$user_id && (int)$call['callee_id'] !== (int)$user_id) {
            return null;
        }

        if ($call['status'] === 'ringing') {
            // لسه بترن: المستقبل اللي بيقفل = رفض، المتصل اللي بيقفل = إلغاء
            $newStatus = ((int)$call['callee_id'] === (int)$user_id) ? 'rejected' : 'cancelled';
            $stmt = $this->conn->prepare(
                "UPDATE calls SET status = ?, ended_at = NOW() WHERE id = ? AND status = 'ringing'"
            );
            if (!$stmt) return null;
            $stmt->bind_param('si', $newStatus, $call_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) return $newStatus;
            return null;
        }

        if ($call['status'] === 'accepted') {
            $stmt = $this->conn->prepare(
                "UPDATE calls
                 SET status = 'ended', ended_at = NOW(),
                     duration = TIMESTAMPDIFF(SECOND, answered_at, NOW())
                 WHERE id = ? AND status = 'accepted'"
            );
            if (!$stmt) return null;
            $stmt->bind_param('i', $call_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) return 'ended';
            return null;
        }

        return null; // خلصت أو اتلغت خلاص من قبل
    }

    // إضافة ICE candidate بعتها أحد الطرفين
    public function addIceCandidate($call_id, $user_id, $candidate) {
        $stmt = $this->conn->prepare(
            "INSERT INTO call_ice_candidates (call_id, user_id, candidate) VALUES (?, ?, ?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iis', $call_id, $user_id, $candidate);
        return $stmt->execute();
    }

    // مرشحات الطرف التاني بعد id معين (عشان الاستقطاب/polling)
    public function getIceCandidatesSince($call_id, $other_user_id, $since_id) {
        $stmt = $this->conn->prepare(
            "SELECT id, candidate FROM call_ice_candidates
             WHERE call_id = ? AND user_id = ? AND id > ?
             ORDER BY id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iii', $call_id, $other_user_id, $since_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
