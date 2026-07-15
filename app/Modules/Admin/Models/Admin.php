<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/logger.php';

class AdminModel {
    private $conn;

    public function __construct() {
        $this->conn = connectDB();
    }

    /** تسجيل دخول الأدمن */
    public function login(string $username, string $password): array {
        $stmt = $this->conn->prepare("SELECT id, username, password FROM admins WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if (!$admin || !password_verify($password, $admin['password'])) {
            Logger::warning('Admin login failed', ['username' => $username]);
            return ['success' => false, 'message' => 'بيانات الدخول غير صحيحة'];
        }

        $upd = $this->conn->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $upd->bind_param("i", $admin['id']);
        $upd->execute();

        Logger::info('Admin login success', ['admin_id' => $admin['id']]);
        return ['success' => true, 'admin_id' => $admin['id'], 'username' => $admin['username']];
    }

    /** كل المستخدمين مع إمكانية البحث */
    public function getAllUsers(string $search = '', int $limit = 50, int $offset = 0): array {
        if ($search !== '') {
            $like = '%' . $search . '%';
            $stmt = $this->conn->prepare(
                "SELECT id, username, phone, email, status, is_banned, ban_reason, banned_at, created_at
                 FROM users
                 WHERE username LIKE ? OR phone LIKE ?
                 ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bind_param("ssii", $like, $like, $limit, $offset);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id, username, phone, email, status, is_banned, ban_reason, banned_at, created_at
                 FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bind_param("ii", $limit, $offset);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function countUsers(string $search = ''): int {
        if ($search !== '') {
            $like = '%' . $search . '%';
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM users WHERE username LIKE ? OR phone LIKE ?");
            $stmt->bind_param("ss", $like, $like);
        } else {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM users");
        }
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /** حظر مستخدم على مستوى الموقع كله */
    public function banUser(int $admin_id, int $user_id, string $reason): array {
        $stmt = $this->conn->prepare(
            "UPDATE users SET is_banned = 1, ban_reason = ?, banned_at = NOW(), banned_by = ? WHERE id = ?"
        );
        $stmt->bind_param("sii", $reason, $admin_id, $user_id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            return ['success' => false, 'message' => 'تعذر حظر المستخدم'];
        }

        $log = $this->conn->prepare(
            "INSERT INTO ban_logs (admin_id, user_id, action, reason) VALUES (?, ?, 'ban', ?)"
        );
        $log->bind_param("iis", $admin_id, $user_id, $reason);
        $log->execute();

        Logger::info('User banned', ['user_id' => $user_id, 'admin_id' => $admin_id, 'reason' => $reason]);
        return ['success' => true, 'message' => 'تم حظر المستخدم'];
    }

    /** إلغاء حظر مستخدم */
    public function unbanUser(int $admin_id, int $user_id): array {
        $stmt = $this->conn->prepare(
            "UPDATE users SET is_banned = 0, ban_reason = NULL, banned_at = NULL, banned_by = NULL WHERE id = ?"
        );
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            return ['success' => false, 'message' => 'تعذر إلغاء الحظر'];
        }

        $log = $this->conn->prepare(
            "INSERT INTO ban_logs (admin_id, user_id, action) VALUES (?, ?, 'unban')"
        );
        $log->bind_param("ii", $admin_id, $user_id);
        $log->execute();

        Logger::info('User unbanned', ['user_id' => $user_id, 'admin_id' => $admin_id]);
        return ['success' => true, 'message' => 'تم إلغاء الحظر'];
    }

    /** إحصائيات سريعة للوحة الأدمن */
    public function getStats(): array {
        $total    = $this->conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
        $banned   = $this->conn->query("SELECT COUNT(*) c FROM users WHERE is_banned = 1")->fetch_assoc()['c'];
        $online   = $this->conn->query("SELECT COUNT(*) c FROM users WHERE status = 'online'")->fetch_assoc()['c'];
        $today    = $this->conn->query("SELECT COUNT(*) c FROM users WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];
        return compact('total', 'banned', 'online', 'today');
    }
}
