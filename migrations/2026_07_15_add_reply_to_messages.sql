-- لازم تشغلي السطر ده مرة واحدة على قاعدة البيانات بتاعتك (عن طريق phpMyAdmin
-- أو أي أداة إدارة MySQL عندك) قبل ما تستخدمي ميزة "الرد على رسالة".
--
-- لو عندك عمود reply_to_id بالفعل، السطر التاني هيدي إيرور بسيط "Duplicate column"
-- ومينفعش يأثر على حاجة تانية - متقلقيش منه.

ALTER TABLE messages
  ADD COLUMN reply_to_id INT NULL DEFAULT NULL AFTER conversation_id,
  ADD INDEX idx_messages_reply_to_id (reply_to_id);
