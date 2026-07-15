<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/Message.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

// موديل الجروبات (المحادثات الجماعية).
// المحادثات الفردية لسه بتتعامل من خلال Message مباشرة (sender_id/receiver_id)
// من غير أي تغيير، الكلاس ده مسؤول بس عن الجروبات.
class Conversation {
    private $conn;
    const MAX_TITLE_LEN   = 100;
    const MAX_MEMBERS     = 250; // حد أقصى معقول لأعضاء الجروب

    public function __construct() {
        $this->conn = connectDB();
        $this->conn->set_charset('utf8mb4');
    }

    // إنشاء جروب جديد: المُنشئ بيبقى owner، وباقي الأعضاء members
    public function createGroup($creator_id, $title, array $member_ids) {
        $title = trim($title);
        if ($title === '') {
            return ['success' => false, 'message' => 'اسم المجموعة مطلوب'];
        }
        $title = mb_substr($title, 0, self::MAX_TITLE_LEN);

        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        $member_ids = array_filter($member_ids, function($id) use ($creator_id) {
            return $id > 0 && $id !== (int)$creator_id;
        });

        if (count($member_ids) < 1) {
            return ['success' => false, 'message' => 'اختر عضو واحد على الأقل'];
        }
        if (count($member_ids) > self::MAX_MEMBERS - 1) {
            return ['success' => false, 'message' => 'عدد الأعضاء كبير جداً'];
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO conversations (title, created_by, created_at, last_message_at) VALUES (?, ?, NOW(), NOW())"
            );
            $stmt->bind_param('si', $title, $creator_id);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $conversation_id = $this->conn->insert_id;

            $partStmt = $this->conn->prepare(
                "INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())"
            );
            $ownerRole = 'owner';
            $partStmt->bind_param('iis', $conversation_id, $creator_id, $ownerRole);
            if (!$partStmt->execute()) throw new Exception($partStmt->error);

            $memberRole = 'member';
            foreach ($member_ids as $uid) {
                $partStmt2 = $this->conn->prepare(
                    "INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())"
                );
                $partStmt2->bind_param('iis', $conversation_id, $uid, $memberRole);
                if (!$partStmt2->execute()) throw new Exception($partStmt2->error);
            }

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'تعذر إنشاء المجموعة'];
        }

        // رسالة نظام: تم إنشاء المجموعة
        $msgModel = new Message();
        $msgModel->sendGroupMessage($conversation_id, $creator_id, json_encode([
            'event' => 'group_created',
        ], JSON_UNESCAPED_UNICODE), null, 'system');

        return ['success' => true, 'conversation_id' => $conversation_id];
    }

    // هل المستخدم ده عضو حالي (لسه ماغادرش) في الجروب ده؟
    public function isParticipant($conversation_id, $user_id) {
        $stmt = $this->conn->prepare(
            "SELECT id, role FROM conversation_participants
             WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $conversation_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: false;
    }

    // بيانات الجروب + أعضاؤه (بس لو الطالب عضو فيه)
    public function getGroupInfo($conversation_id, $requester_id) {
        if (!$this->isParticipant($conversation_id, $requester_id)) {
            return ['success' => false, 'message' => 'غير مصرح'];
        }

        $stmt = $this->conn->prepare("SELECT id, title, avatar, created_by, created_at FROM conversations WHERE id = ?");
        $stmt->bind_param('i', $conversation_id);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        if (!$conv) return ['success' => false, 'message' => 'المجموعة غير موجودة'];

        $mStmt = $this->conn->prepare(
            "SELECT u.id, u.username, u.profile_photo, u.status, cp.role, cp.joined_at
             FROM conversation_participants cp
             INNER JOIN users u ON u.id = cp.user_id
             WHERE cp.conversation_id = ? AND cp.left_at IS NULL
             ORDER BY FIELD(cp.role, 'owner', 'admin', 'member'), cp.joined_at ASC"
        );
        $mStmt->bind_param('i', $conversation_id);
        $mStmt->execute();
        $members = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($members as &$m) {
            $m['profile_photo'] = $m['profile_photo'] ?: 'default.png';
        }
        unset($m);

        return ['success' => true, 'conversation' => $conv, 'members' => $members];
    }

    // إضافة أعضاء جدد (أي عضو حالي يقدر يضيف، زي أغلب تطبيقات الشات البسيطة)
    public function addMembers($conversation_id, $actor_id, array $user_ids) {
        $actor = $this->isParticipant($conversation_id, $actor_id);
        if (!$actor) return ['success' => false, 'message' => 'غير مصرح'];

        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        $user_ids = array_filter($user_ids, function($id){ return $id > 0; });
        if (empty($user_ids)) return ['success' => false, 'message' => 'لا يوجد أعضاء لإضافتهم'];

        $added = [];
        foreach ($user_ids as $uid) {
            // لو كان عضو سابقاً وغادر، رجّعه بدل صف جديد
            $exists = $this->conn->prepare(
                "SELECT id, left_at FROM conversation_participants WHERE conversation_id = ? AND user_id = ?"
            );
            $exists->bind_param('ii', $conversation_id, $uid);
            $exists->execute();
            $row = $exists->get_result()->fetch_assoc();

            if ($row) {
                if ($row['left_at'] !== null) {
                    $reactivate = $this->conn->prepare(
                        "UPDATE conversation_participants SET left_at = NULL, joined_at = NOW(), role = 'member' WHERE id = ?"
                    );
                    $reactivate->bind_param('i', $row['id']);
                    $reactivate->execute();
                    $added[] = $uid;
                }
                continue; // عضو فعلاً، مافيش داعي نضيفه تاني
            }

            $ins = $this->conn->prepare(
                "INSERT INTO conversation_participants (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())"
            );
            $ins->bind_param('ii', $conversation_id, $uid);
            if ($ins->execute()) $added[] = $uid;
        }

        if (!empty($added)) {
            $userModel = new User();
            $names = array_map(function($uid) use ($userModel) {
                $u = $userModel->getUserData($uid);
                return $u['username'] ?? 'مستخدم';
            }, $added);

            $msgModel = new Message();
            $msgModel->sendGroupMessage($conversation_id, $actor_id, json_encode([
                'event' => 'members_added',
                'names' => $names,
            ], JSON_UNESCAPED_UNICODE), null, 'system');
        }

        return ['success' => true, 'added' => $added];
    }

    // إزالة عضو (owner/admin بس يقدروا يشيلوا غيرهم) أو مغادرة نفسه
    public function removeMember($conversation_id, $actor_id, $target_id) {
        $actor = $this->isParticipant($conversation_id, $actor_id);
        if (!$actor) return ['success' => false, 'message' => 'غير مصرح'];

        $isSelf = ((int)$actor_id === (int)$target_id);
        if (!$isSelf && !in_array($actor['role'], ['owner', 'admin'], true)) {
            return ['success' => false, 'message' => 'لا تملك صلاحية إزالة الأعضاء'];
        }

        $target = $this->isParticipant($conversation_id, $target_id);
        if (!$target) return ['success' => false, 'message' => 'العضو غير موجود في المجموعة'];

        $stmt = $this->conn->prepare(
            "UPDATE conversation_participants SET left_at = NOW() WHERE conversation_id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $conversation_id, $target_id);
        if (!$stmt->execute()) return ['success' => false, 'message' => 'حدث خطأ'];

        // لو المالك غادر، رقّي أقدم عضو نشط لمالك جديد عشان المجموعة تفضل ليها مسؤول
        if ($target['role'] === 'owner') {
            $next = $this->conn->prepare(
                "SELECT id FROM conversation_participants
                 WHERE conversation_id = ? AND left_at IS NULL
                 ORDER BY FIELD(role,'admin','member'), joined_at ASC LIMIT 1"
            );
            $next->bind_param('i', $conversation_id);
            $next->execute();
            $nextRow = $next->get_result()->fetch_assoc();
            if ($nextRow) {
                $promote = $this->conn->prepare("UPDATE conversation_participants SET role = 'owner' WHERE id = ?");
                $promote->bind_param('i', $nextRow['id']);
                $promote->execute();
            }
        }

        $userModel = new User();
        $u = $userModel->getUserData($target_id);
        $msgModel = new Message();
        $msgModel->sendGroupMessage($conversation_id, $actor_id, json_encode([
            'event' => $isSelf ? 'member_left' : 'member_removed',
            'name'  => $u['username'] ?? 'مستخدم',
        ], JSON_UNESCAPED_UNICODE), null, 'system');

        return ['success' => true];
    }

    // تغيير اسم الجروب
    public function renameGroup($conversation_id, $actor_id, $title) {
        $actor = $this->isParticipant($conversation_id, $actor_id);
        if (!$actor) return ['success' => false, 'message' => 'غير مصرح'];

        $title = trim($title);
        if ($title === '') return ['success' => false, 'message' => 'اسم المجموعة مطلوب'];
        $title = mb_substr($title, 0, self::MAX_TITLE_LEN);

        $stmt = $this->conn->prepare("UPDATE conversations SET title = ? WHERE id = ?");
        $stmt->bind_param('si', $title, $conversation_id);
        if (!$stmt->execute()) return ['success' => false, 'message' => 'حدث خطأ'];

        $userModel = new User();
        $u = $userModel->getUserData($actor_id);
        $msgModel = new Message();
        $msgModel->sendGroupMessage($conversation_id, $actor_id, json_encode([
            'event' => 'title_changed',
            'name'  => $u['username'] ?? 'مستخدم',
            'title' => $title,
        ], JSON_UNESCAPED_UNICODE), null, 'system');

        return ['success' => true, 'title' => $title];
    }

    // كل الجروبات النشطة اللي المستخدم عضو فيها، بنفس شكل بيانات المحادثات
    // الفردية عشان نقدر ندمجهم في نفس قائمة السايدبار في get_conversations.php
    public function getUserGroups($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT c.id, c.title, c.avatar, c.last_message_at, cp.last_read_message_id,
                    (SELECT COUNT(*) FROM conversation_participants cp2
                       WHERE cp2.conversation_id = c.id AND cp2.left_at IS NULL) AS member_count
             FROM conversations c
             INNER JOIN conversation_participants cp ON cp.conversation_id = c.id
             WHERE cp.user_id = ? AND cp.left_at IS NULL
             ORDER BY c.last_message_at DESC
             LIMIT 50"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($groups as $g) {
            $lastStmt = $this->conn->prepare(
                "SELECT m.id, m.message_text, m.message_type, m.created_at, m.sender_id, u.username AS sender_name
                 FROM messages m LEFT JOIN users u ON u.id = m.sender_id
                 WHERE m.conversation_id = ? ORDER BY m.id DESC LIMIT 1"
            );
            $lastStmt->bind_param('i', $g['id']);
            $lastStmt->execute();
            $last = $lastStmt->get_result()->fetch_assoc();

            $unreadStmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM messages
                 WHERE conversation_id = ? AND id > ? AND sender_id <> ?"
            );
            $lastRead = (int)$g['last_read_message_id'];
            $unreadStmt->bind_param('iii', $g['id'], $lastRead, $user_id);
            $unreadStmt->execute();
            $unread = (int)$unreadStmt->get_result()->fetch_assoc()['cnt'];

            $out[] = [
                'type'              => 'group',
                'conversation_id'   => (int)$g['id'],
                'title'             => $g['title'],
                'avatar'            => $g['avatar'],
                'member_count'      => (int)$g['member_count'],
                'last_message'      => $last['message_text'] ?? '',
                'message_type'      => $last['message_type'] ?? 'text',
                'last_sender_name'  => $last['sender_name'] ?? '',
                'last_sender_id'    => $last['sender_id'] ?? null,
                'last_message_time' => $last['created_at'] ?? $g['last_message_at'],
                'unread_count'      => $unread,
                'last_id'           => $last['id'] ?? 0,
            ];
        }
        return $out;
    }

    // تعليم آخر رسالة اتشافت في الجروب (لحساب غير المقروء)
    public function markRead($conversation_id, $user_id, $message_id) {
        $stmt = $this->conn->prepare(
            "UPDATE conversation_participants
             SET last_read_message_id = GREATEST(last_read_message_id, ?)
             WHERE conversation_id = ? AND user_id = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iii', $message_id, $conversation_id, $user_id);
        return $stmt->execute();
    }
}
