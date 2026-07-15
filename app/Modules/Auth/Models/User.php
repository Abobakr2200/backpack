<?php

require_once __DIR__ . '/../../../../config/database.php';

class User {
    private $conn;

    public function __construct() {
        $this->conn = connectDB();
    }

    // تسجيل مستخدم جديد برقم الهاتف
    public function register($username, $phone, $password) {
        // التحقق من عدم وجود المستخدم مسبقاً
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE phone = ? OR username = ?");
        $stmt->bind_param("ss", $phone, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'المستخدم موجود بالفعل'];
        }

        // تشفير كلمة المرور
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->conn->prepare("INSERT INTO users (username, phone, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $phone, $hashedPassword);

        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'تم التسجيل بنجاح', 'user_id' => $this->conn->insert_id];
        } else {
            return ['success' => false, 'message' => 'حدث خطأ أثناء التسجيل'];
        }
    }

    // تسجيل الدخول برقم الهاتف
    public function login($phone, $password) {
        $stmt = $this->conn->prepare("SELECT id, username, phone, password, is_banned, ban_reason FROM users WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'رقم الهاتف غير موجود'];
        }

        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'كلمة المرور غير صحيحة'];
        }

        // نتحقق من الحظر بعد التأكد من صحة كلمة المرور (مش قبلها) عشان
        // ميبقاش فيه طريقة لأي حد يعرف إن رقم معين محظور من غير ما يعرف الباسورد
        if ((int)$user['is_banned'] === 1) {
            $reason = $user['ban_reason'] ? (' السبب: ' . $user['ban_reason']) : '';
            return ['success' => false, 'message' => 'تم حظر هذا الحساب.' . $reason];
        }

        // تحديث الحالة إلى متصل
        $this->updateStatus($user['id'], 'online');
        return [
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'user_id' => $user['id'],
            'username' => $user['username'],
            'phone' => $user['phone']
        ];
    }

    // الحصول على بيانات المستخدم
    public function getUserData($user_id) {
        $stmt = $this->conn->prepare("SELECT id, username, phone, email, bio, profile_photo, cover_photo, status, last_seen, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // تحديث بيانات الملف الشخصي
    public function updateProfile($user_id, $username = null, $bio = null, $email = null) {
        if ($username !== null && $username !== '') {
            $stmt = $this->conn->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->bind_param("si", $username, $user_id);
            $stmt->execute();
        }
        if ($bio !== null) {
            $stmt = $this->conn->prepare("UPDATE users SET bio = ? WHERE id = ?");
            $stmt->bind_param("si", $bio, $user_id);
            $stmt->execute();
        }
        if ($email !== null) {
            $stmt = $this->conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
        }
        return true;
    }

    // تغيير كلمة المرور (بعد التأكد من كلمة المرور الحالية)
    public function changePassword($user_id, $currentPassword, $newPassword) {
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return ['success' => false, 'message' => 'المستخدم غير موجود'];
        }

        if (!password_verify($currentPassword, $row['password'])) {
            return ['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة'];
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $hashed, $user_id);

        if ($upd->execute()) {
            return ['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح'];
        }
        return ['success' => false, 'message' => 'حدث خطأ أثناء تغيير كلمة المرور'];
    }

    // تحديث صورة الملف الشخصي
    public function updateProfilePhoto($user_id, $filename) {
        $stmt = $this->conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
        $stmt->bind_param("si", $filename, $user_id);
        return $stmt->execute();
    }

    // تحديث صورة الغلاف
    public function updateCoverPhoto($user_id, $filename) {
        $stmt = $this->conn->prepare("UPDATE users SET cover_photo = ? WHERE id = ?");
        $stmt->bind_param("si", $filename, $user_id);
        return $stmt->execute();
    }

    // تحديث حالة المستخدم
    public function updateStatus($user_id, $status) {
        $stmt = $this->conn->prepare("UPDATE users SET status = ?, last_seen = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $user_id);
        return $stmt->execute();
    }
// البحث عن المستخدمين
public function searchUsers($query, $exclude_user_id = 0) {
    $like = "%" . $query . "%";
    $exclude_user_id = (int)($exclude_user_id ?: 0);

    if ($exclude_user_id > 0) {
        $stmt = $this->conn->prepare(
            "SELECT id, username, profile_photo, status
             FROM users
             WHERE (username LIKE ? OR phone LIKE ?)
               AND id != ?
             ORDER BY status DESC, username ASC
             LIMIT 20"
        );
        if (!$stmt) return [];
        $stmt->bind_param("ssi", $like, $like, $exclude_user_id);
    } else {
        $stmt = $this->conn->prepare(
            "SELECT id, username, profile_photo, status
             FROM users
             WHERE username LIKE ? OR phone LIKE ?
             ORDER BY status DESC, username ASC
             LIMIT 20"
        );
        if (!$stmt) return [];
        $stmt->bind_param("ss", $like, $like);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        if (empty($r['profile_photo'])) $r['profile_photo'] = 'default.png';
    }
    return $rows;
}
    // الحصول على جميع المستخدمين
    public function getAllUsers($exclude_user_id = null) {
        if ($exclude_user_id) {
            $stmt = $this->conn->prepare("SELECT id, username, profile_photo, status, last_seen FROM users WHERE id != ? ORDER BY status DESC, last_seen DESC");
            $stmt->bind_param("i", $exclude_user_id);
        } else {
            $stmt = $this->conn->prepare("SELECT id, username, profile_photo, status, last_seen FROM users ORDER BY status DESC, last_seen DESC");
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // تحديث آخر وقت رؤية
    public function updateLastSeen($user_id) {
        $stmt = $this->conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }

    // متابعة مستخدم
    public function followUser($user_id, $follower_id) {
        $stmt = $this->conn->prepare("INSERT INTO followers (user_id, follower_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $follower_id);
        return $stmt->execute();
    }

    // إلغاء متابعة مستخدم
    public function unfollowUser($user_id, $follower_id) {
        $stmt = $this->conn->prepare("DELETE FROM followers WHERE user_id = ? AND follower_id = ?");
        $stmt->bind_param("ii", $user_id, $follower_id);
        return $stmt->execute();
    }

    // التحقق من المتابعة
    public function isFollowing($user_id, $follower_id) {
        $stmt = $this->conn->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
        $stmt->bind_param("ii", $user_id, $follower_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // الحصول على عدد المتابعين
    public function getFollowersCount($user_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM followers WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['count'];
    }

    // الحصول على عدد المتابعة
    public function getFollowingCount($user_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM followers WHERE follower_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['count'];
    }

    // الحصول على قائمة المتابعين
    public function getFollowers($user_id) {
        $stmt = $this->conn->prepare("SELECT u.id, u.username, u.profile_photo, u.status FROM followers f JOIN users u ON f.follower_id = u.id WHERE f.user_id = ? ORDER BY f.created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // حظر مستخدم
    public function blockUser($blocker_id, $blocked_id) {
        if ($blocker_id == $blocked_id) {
            return ['success' => false, 'message' => 'لا يمكنك حظر نفسك'];
        }
        $stmt = $this->conn->prepare("INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)");
        if (!$stmt) return ['success' => false, 'message' => 'حدث خطأ'];
        $stmt->bind_param("ii", $blocker_id, $blocked_id);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'تم حظر المستخدم'];
        }
        return ['success' => false, 'message' => 'حدث خطأ أثناء الحظر'];
    }

    // إلغاء حظر مستخدم
    public function unblockUser($blocker_id, $blocked_id) {
        $stmt = $this->conn->prepare("DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        if (!$stmt) return ['success' => false, 'message' => 'حدث خطأ'];
        $stmt->bind_param("ii", $blocker_id, $blocked_id);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'تم إلغاء الحظر'];
        }
        return ['success' => false, 'message' => 'حدث خطأ أثناء إلغاء الحظر'];
    }

    // هل user_a حاظر user_b؟
    public function isBlocked($blocker_id, $blocked_id) {
        $stmt = $this->conn->prepare("SELECT id FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $blocker_id, $blocked_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // هل فيه حظر في أي اتجاه بين المستخدمين (يمنع المحادثة تماماً)؟
    public function isBlockedEitherWay($user_a, $user_b) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM blocked_users
             WHERE (blocker_id = ? AND blocked_id = ?)
                OR (blocker_id = ? AND blocked_id = ?)
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param("iiii", $user_a, $user_b, $user_b, $user_a);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // قائمة المستخدمين المحظورين من طرفي
    public function getBlockedUsers($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT u.id, u.username, u.profile_photo
             FROM blocked_users b
             JOIN users u ON u.id = b.blocked_id
             WHERE b.blocker_id = ?
             ORDER BY b.created_at DESC"
        );
        if (!$stmt) return [];
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

?>
