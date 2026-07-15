<?php

require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../../Auth/Models/User.php';

class MessageController {
    private $messageModel;
    private $userModel;

    public function __construct() {
        $this->messageModel = new Message();
        $this->userModel = new User();
    }

    // الحصول على الرسائل
    public function getMessages($user_id, $contact_id) {
        return $this->messageModel->getMessages($user_id, $contact_id);
    }

    // إرسال رسالة
    public function sendMessage($sender_id, $receiver_id, $message_text = '', $file_path = null, $message_type = 'text') {
        if (empty($message_text) && empty($file_path)) {
            return ['success' => false, 'message' => 'الرسالة فارغة'];
        }

        return $this->messageModel->sendMessage($sender_id, $receiver_id, $message_text, $file_path, $message_type);
    }

    // وضع علامة على كل رسائل محادثة معينة كمقروءة
    // (كانت بتنادي دالة اسمها markAsRead مش موجودة أصلاً في الموديل - كانت هتتسبب
    // في Fatal Error لو الكلاس ده اتستخدم فعلياً)
    public function markAsRead($sender_id, $receiver_id) {
        return $this->messageModel->markAllAsRead($sender_id, $receiver_id);
    }

    // الحصول على آخر الرسائل
    public function getLastMessages($user_id) {
        return $this->messageModel->getLastMessages($user_id);
    }

    // حذف رسالة
    public function deleteMessage($message_id, $user_id) {
        return $this->messageModel->deleteMessage($message_id, $user_id);
    }
}

?>
