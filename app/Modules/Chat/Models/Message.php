<?php
require_once __DIR__ . '/../../../../config/database.php';

class Message {
    private $conn;

    public function __construct() {
        $this->conn = connectDB();
        $this->conn->set_charset('utf8mb4');
    }

    // إرسال رسالة
    public function sendMessage($sender_id, $receiver_id, $message_text, $file_path = null, $message_type = 'text', $reply_to_id = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO messages (sender_id, receiver_id, message_text, file_path, message_type, reply_to_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) return ['success' => false, 'message' => $this->conn->error];
        $reply_to_id = $reply_to_id ? (int)$reply_to_id : null;
        $stmt->bind_param("iisssi", $sender_id, $receiver_id, $message_text, $file_path, $message_type, $reply_to_id);
        if ($stmt->execute()) {
            return ['success' => true, 'message_id' => $this->conn->insert_id];
        }
        return ['success' => false, 'message' => $stmt->error];
    }

    // إرسال رسالة داخل جروب (conversation_id بدل receiver_id)
    // $message_type ممكن تكون 'system' لرسايل النظام (انضمام/مغادرة/تغيير اسم...)
    public function sendGroupMessage($conversation_id, $sender_id, $message_text, $file_path = null, $message_type = 'text', $reply_to_id = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO messages (conversation_id, sender_id, receiver_id, message_text, file_path, message_type, reply_to_id, created_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) return ['success' => false, 'message' => $this->conn->error];
        $reply_to_id = $reply_to_id ? (int)$reply_to_id : null;
        $stmt->bind_param("iisssi", $conversation_id, $sender_id, $message_text, $file_path, $message_type, $reply_to_id);
        if (!$stmt->execute()) {
            return ['success' => false, 'message' => $stmt->error];
        }
        $message_id = $this->conn->insert_id;

        $upd = $this->conn->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = ?");
        if ($upd) { $upd->bind_param('i', $conversation_id); $upd->execute(); }

        return ['success' => true, 'message_id' => $message_id];
    }

    // جلب رسائل جروب معين (مع بيانات المرسل، لأن الجروب فيه أكتر من مرسل)
    public function getConversationMessages($conversation_id, $limit = 60, $since_id = 0) {
        $replyCols = "m.reply_to_id, rm.message_text AS reply_text, rm.message_type AS reply_type,
                        ru.username AS reply_sender_name";
        $replyJoin = "LEFT JOIN messages rm ON rm.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = rm.sender_id";

        if ($since_id > 0) {
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.conversation_id, m.sender_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        u.username AS sender_name, u.profile_photo AS sender_photo,
                        $replyCols
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 $replyJoin
                 WHERE m.conversation_id = ? AND m.id > ?
                 ORDER BY m.id ASC
                 LIMIT 100"
            );
            if (!$stmt) return [];
            $stmt->bind_param("ii", $conversation_id, $since_id);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.conversation_id, m.sender_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        u.username AS sender_name, u.profile_photo AS sender_photo,
                        $replyCols
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 $replyJoin
                 WHERE m.conversation_id = ?
                 ORDER BY m.id DESC
                 LIMIT ?"
            );
            if (!$stmt) return [];
            $stmt->bind_param("ii", $conversation_id, $limit);
        }

        if (!$stmt->execute()) return [];
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($since_id == 0) $rows = array_reverse($rows);

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $reactions = $this->getReactionsForMessageIdsUnchecked($ids);
            foreach ($rows as &$row) {
                $row['reactions'] = $reactions[$row['id']] ?? [];
            }
            unset($row);
        }

        return $rows;
    }

    // نفس فكرة getReactionsForMessages بس من غير فلترة صلاحية (بنستخدمها بعد ما
    // نكون اتأكدنا أصلاً إن المستخدم عضو في الجروب في get_messages.php)
    public function getReactionsForMessageIdsUnchecked(array $message_ids) {
        $message_ids = array_values(array_unique(array_map('intval', $message_ids)));
        if (empty($message_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
        $types = str_repeat('i', count($message_ids));
        $stmt = $this->conn->prepare(
            "SELECT message_id, emoji, COUNT(*) AS cnt
             FROM message_reactions
             WHERE message_id IN ($placeholders)
             GROUP BY message_id, emoji
             ORDER BY MIN(id) ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param($types, ...$message_ids);
        if (!$stmt->execute()) return [];
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];
            if (!isset($out[$mid])) $out[$mid] = [];
            $out[$mid][] = ['emoji' => $r['emoji'], 'count' => (int)$r['cnt'], 'reacted_by_me' => false];
        }
        return $out;
    }

    // جلب الرسائل بين مستخدمين
    public function getMessages($user_id, $contact_id, $limit = 60, $offset = 0, $since_id = 0) {
        $replyCols = "m.reply_to_id, rm.message_text AS reply_text, rm.message_type AS reply_type,
                        ru.username AS reply_sender_name";
        $replyJoin = "LEFT JOIN messages rm ON rm.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = rm.sender_id";

        if ($since_id > 0) {
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.receiver_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        IF(m.read_at IS NOT NULL, 1, 0) AS is_read,
                        $replyCols
                 FROM messages m
                 $replyJoin
                 WHERE ((m.sender_id = ? AND m.receiver_id = ?)
                     OR (m.sender_id = ? AND m.receiver_id = ?))
                   AND m.id > ?
                 ORDER BY m.id ASC
                 LIMIT 100"
            );
            if (!$stmt) return [];
            $stmt->bind_param("iiiii", $user_id, $contact_id, $contact_id, $user_id, $since_id);
        } else {
            // آخر N رسالة مرتبة تصاعدياً
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.receiver_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        IF(m.read_at IS NOT NULL, 1, 0) AS is_read,
                        $replyCols
                 FROM messages m
                 $replyJoin
                 WHERE (m.sender_id = ? AND m.receiver_id = ?)
                    OR (m.sender_id = ? AND m.receiver_id = ?)
                 ORDER BY m.id DESC
                 LIMIT ?"
            );
            if (!$stmt) return [];
            $stmt->bind_param("iiiii", $user_id, $contact_id, $contact_id, $user_id, $limit);
        }

        if (!$stmt->execute()) return [];
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // لو جلبنا desc، نعكس الترتيب
        if ($since_id == 0) {
            $rows = array_reverse($rows);
        }

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $reactions = $this->getReactionsForMessages($ids, $user_id);
            foreach ($rows as &$row) {
                $row['reactions'] = $reactions[$row['id']] ?? [];
            }
            unset($row);
        }

        return $rows;
    }

    // تجميع تفاعلات مجموعة رسائل: لكل رسالة، مصفوفة [{emoji, count, reacted_by_me}]
    // بيرجع بس تفاعلات الرسائل اللي المستخدم الحالي طرف فيها (مرسل أو مستقبل)
    public function getReactionsForMessages(array $message_ids, $current_user_id) {
        $message_ids = array_values(array_unique(array_map('intval', $message_ids)));
        if (empty($message_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
        $types  = str_repeat('i', count($message_ids));
        $stmt = $this->conn->prepare(
            "SELECT r.message_id, r.emoji, COUNT(*) AS cnt,
                    SUM(CASE WHEN r.user_id = ? THEN 1 ELSE 0 END) AS mine
             FROM message_reactions r
             INNER JOIN messages m ON m.id = r.message_id
                 AND (m.sender_id = ? OR m.receiver_id = ?
                      OR (m.conversation_id IS NOT NULL AND m.conversation_id IN (
                          SELECT conversation_id FROM conversation_participants
                          WHERE user_id = ? AND left_at IS NULL
                      )))
             WHERE r.message_id IN ($placeholders)
             GROUP BY r.message_id, r.emoji
             ORDER BY MIN(r.id) ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iiii' . $types, $current_user_id, $current_user_id, $current_user_id, $current_user_id, ...$message_ids);
        if (!$stmt->execute()) return [];
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];
            if (!isset($out[$mid])) $out[$mid] = [];
            $out[$mid][] = [
                'emoji'         => $r['emoji'],
                'count'         => (int)$r['cnt'],
                'reacted_by_me' => (int)$r['mine'] > 0,
            ];
        }
        return $out;
    }

    // إضافة/تبديل/إزالة تفاعل على رسالة (تفاعل واحد بس لكل مستخدم في كل رسالة)
    // بيتأكد الأول إن الرسالة فعلاً جوه محادثة بتاعة المستخدم ده
    public function toggleReaction($message_id, $user_id, $emoji) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?
                OR (conversation_id IS NOT NULL AND conversation_id IN (
                    SELECT conversation_id FROM conversation_participants
                    WHERE user_id = ? AND left_at IS NULL
                )))"
        );
        if (!$stmt) return ['success' => false, 'message' => 'خطأ داخلي'];
        $stmt->bind_param('iiii', $message_id, $user_id, $user_id, $user_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'message' => 'الرسالة غير موجودة'];
        }

        $existing = $this->conn->prepare(
            "SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?"
        );
        $existing->bind_param('ii', $message_id, $user_id);
        $existing->execute();
        $current = $existing->get_result()->fetch_assoc();

        if ($current && $current['emoji'] === $emoji) {
            // نفس الإيموجي تاني = إلغاء التفاعل
            $del = $this->conn->prepare(
                "DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?"
            );
            $del->bind_param('ii', $message_id, $user_id);
            $del->execute();
            return ['success' => true, 'action' => 'removed'];
        }

        // إيموجي جديد أو مختلف = إضافة/استبدال (upsert)
        $up = $this->conn->prepare(
            "INSERT INTO message_reactions (message_id, user_id, emoji)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = NOW()"
        );
        if (!$up) return ['success' => false, 'message' => $this->conn->error];
        $up->bind_param('iis', $message_id, $user_id, $emoji);
        if (!$up->execute()) return ['success' => false, 'message' => $up->error];
        return ['success' => true, 'action' => 'set'];
    }

    // قائمة المحادثات - query بسيطة تعمل على كل MySQL
    public function getLastMessages($user_id) {
        // جيب كل المستخدمين اللي تحادثت معهم
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT
                CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_user_id
             FROM messages m
             WHERE m.sender_id = ? OR m.receiver_id = ?
             ORDER BY m.id DESC
             LIMIT 50"
        );
        if (!$stmt) return [];
        $stmt->bind_param("iii", $user_id, $user_id, $user_id);
        if (!$stmt->execute()) return [];
        $others = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($others)) return [];

        $conversations = [];
        foreach ($others as $row) {
            $other_id = (int)$row['other_user_id'];
            if ($other_id == $user_id) continue;

            // بيانات المستخدم
            $stmt2 = $this->conn->prepare(
                "SELECT id, username, profile_photo, status FROM users WHERE id = ?"
            );
            if (!$stmt2) continue;
            $stmt2->bind_param("i", $other_id);
            $stmt2->execute();
            $other = $stmt2->get_result()->fetch_assoc();
            if (!$other) continue;

            // آخر رسالة
            $stmt3 = $this->conn->prepare(
                "SELECT id, message_text, message_type, created_at, sender_id
                 FROM messages
                 WHERE (sender_id = ? AND receiver_id = ?)
                    OR (sender_id = ? AND receiver_id = ?)
                 ORDER BY id DESC
                 LIMIT 1"
            );
            if (!$stmt3) continue;
            $stmt3->bind_param("iiii", $user_id, $other_id, $other_id, $user_id);
            $stmt3->execute();
            $last = $stmt3->get_result()->fetch_assoc();
            if (!$last) continue;

            // عدد غير المقروءة
            $stmt4 = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM messages
                 WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL"
            );
            if (!$stmt4) { $unread = 0; }
            else {
                $stmt4->bind_param("ii", $other_id, $user_id);
                $stmt4->execute();
                $unread = (int)$stmt4->get_result()->fetch_assoc()['cnt'];
            }

            $conversations[] = [
                'user_id'           => $other['id'],
                'username'          => $other['username'],
                'profile_photo'     => $other['profile_photo'] ?: 'default.png',
                'status'            => $other['status'] ?: 'offline',
                'last_message'      => $last['message_text'],
                'message_type'      => $last['message_type'],
                'last_message_time' => $last['created_at'],
                'unread_count'      => $unread,
                'last_id'           => $last['id'],
            ];
        }

        // ترتيب حسب آخر رسالة
        usort($conversations, function($a, $b) {
            return $b['last_id'] - $a['last_id'];
        });

        return $conversations;
    }

    // تعليم الرسائل كمقروءة
    public function markAllAsRead($sender_id, $receiver_id) {
        $stmt = $this->conn->prepare(
            "UPDATE messages SET read_at = NOW()
             WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return false;
        $stmt->bind_param("ii", $sender_id, $receiver_id);
        return $stmt->execute();
    }

    // عدد الرسائل غير المقروءة
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM messages
             WHERE receiver_id = ? AND read_at IS NULL"
        );
        if (!$stmt) return 0;
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['cnt'];
    }

    // تعديل نص رسالة (بس رسايل النص بتاعت المرسل نفسه، مش صور/ملفات)
    public function editMessage($message_id, $user_id, $new_text) {
        $new_text = trim($new_text);
        if ($new_text === '') {
            return ['success' => false, 'message' => 'النص فارغ'];
        }

        $stmt = $this->conn->prepare(
            "SELECT id, message_type FROM messages WHERE id = ? AND sender_id = ?"
        );
        $stmt->bind_param('ii', $message_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return ['success' => false, 'message' => 'الرسالة غير موجودة'];
        }
        if ($row['message_type'] !== 'text') {
            return ['success' => false, 'message' => 'لا يمكن تعديل هذا النوع من الرسائل'];
        }

        $upd = $this->conn->prepare(
            "UPDATE messages SET message_text = ?, edited_at = NOW() WHERE id = ?"
        );
        $upd->bind_param('si', $new_text, $message_id);
        if ($upd->execute()) {
            return ['success' => true, 'message_text' => $new_text];
        }
        return ['success' => false, 'message' => $upd->error];
    }

    // بحث في رسائل محادثة معينة (فردية أو جروب) بكلمة معينة
    public function searchMessages($user_id, $contact_id, $conversation_id, $query, $limit = 50) {
        $query = trim($query);
        if ($query === '') return [];
        $like = '%' . $this->conn->real_escape_string($query) . '%';

        if ($conversation_id) {
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.message_text, m.message_type, m.created_at,
                        u.username AS sender_name
                 FROM messages m
                 LEFT JOIN users u ON u.id = m.sender_id
                 WHERE m.conversation_id = ? AND m.message_type = 'text' AND m.message_text LIKE ?
                 ORDER BY m.id DESC
                 LIMIT ?"
            );
            if (!$stmt) return [];
            $stmt->bind_param("isi", $conversation_id, $like, $limit);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.message_text, m.message_type, m.created_at
                 FROM messages m
                 WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                   AND m.message_type = 'text' AND m.message_text LIKE ?
                 ORDER BY m.id DESC
                 LIMIT ?"
            );
            if (!$stmt) return [];
            $stmt->bind_param("iiiisi", $user_id, $contact_id, $contact_id, $user_id, $like, $limit);
        }

        if (!$stmt->execute()) return [];
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // جلب دفعة رسائل حوالين رسالة معينة (لما المستخدم يدوس على نتيجة بحث
    // عشان يشوفها في سياقها، مش بس النص المقتطع)
    public function getMessagesAround($user_id, $contact_id, $conversation_id, $around_id, $before = 25, $after = 25) {
        $replyCols = "m.reply_to_id, rm.message_text AS reply_text, rm.message_type AS reply_type,
                        ru.username AS reply_sender_name";
        $replyJoin = "LEFT JOIN messages rm ON rm.id = m.reply_to_id
                 LEFT JOIN users ru ON ru.id = rm.sender_id";

        if ($conversation_id) {
            $olderStmt = $this->conn->prepare(
                "SELECT m.id, m.conversation_id, m.sender_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        u.username AS sender_name, u.profile_photo AS sender_photo, $replyCols
                 FROM messages m LEFT JOIN users u ON u.id = m.sender_id $replyJoin
                 WHERE m.conversation_id = ? AND m.id <= ?
                 ORDER BY m.id DESC LIMIT ?"
            );
            if (!$olderStmt) return [];
            $olderStmt->bind_param("iii", $conversation_id, $around_id, $before);

            $newerStmt = $this->conn->prepare(
                "SELECT m.id, m.conversation_id, m.sender_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        u.username AS sender_name, u.profile_photo AS sender_photo, $replyCols
                 FROM messages m LEFT JOIN users u ON u.id = m.sender_id $replyJoin
                 WHERE m.conversation_id = ? AND m.id > ?
                 ORDER BY m.id ASC LIMIT ?"
            );
            if (!$newerStmt) return [];
            $newerStmt->bind_param("iii", $conversation_id, $around_id, $after);
        } else {
            $olderStmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.receiver_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        IF(m.read_at IS NOT NULL, 1, 0) AS is_read, $replyCols
                 FROM messages m $replyJoin
                 WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                   AND m.id <= ?
                 ORDER BY m.id DESC LIMIT ?"
            );
            if (!$olderStmt) return [];
            $olderStmt->bind_param("iiiiii", $user_id, $contact_id, $contact_id, $user_id, $around_id, $before);

            $newerStmt = $this->conn->prepare(
                "SELECT m.id, m.sender_id, m.receiver_id, m.message_text,
                        m.file_path, m.message_type, m.created_at, m.edited_at,
                        IF(m.read_at IS NOT NULL, 1, 0) AS is_read, $replyCols
                 FROM messages m $replyJoin
                 WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                   AND m.id > ?
                 ORDER BY m.id ASC LIMIT ?"
            );
            if (!$newerStmt) return [];
            $newerStmt->bind_param("iiiiii", $user_id, $contact_id, $contact_id, $user_id, $around_id, $after);
        }

        if (!$olderStmt->execute() || !$newerStmt->execute()) return [];
        $older = array_reverse($olderStmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $newer = $newerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rows  = array_merge($older, $newer);

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $reactions = $conversation_id
                ? $this->getReactionsForMessageIdsUnchecked($ids)
                : $this->getReactionsForMessages($ids, $user_id);
            foreach ($rows as &$row) {
                $row['reactions'] = $reactions[$row['id']] ?? [];
            }
            unset($row);
        }

        return $rows;
    }

    // حذف رسالة
    public function deleteMessage($message_id, $user_id) {
        $stmt = $this->conn->prepare(
            "DELETE FROM messages WHERE id = ? AND sender_id = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param("ii", $message_id, $user_id);
        if (!$stmt->execute()) return false;
        // execute() بترجع true حتى لو معدلتش أي صف (مثلاً الرسالة مش بتاعة اليوزر ده)
        // فلازم نتأكد فعلياً إن صف اتمسح قبل ما نقول "نجح"
        return $stmt->affected_rows > 0;
    }
}
?>
