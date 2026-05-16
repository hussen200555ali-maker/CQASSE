-- ============================================================================
-- EQAS v3.2.0 - إصلاح مركز الإشعارات
-- ============================================================================
-- التغييرات:
-- 1. تغيير alert_type من ENUM إلى VARCHAR (لدعم القيم الجديدة)
-- 2. تغيير notifications.type من ENUM إلى VARCHAR (لتوحيد القيم)
-- 3. حذف جدول alert_notifications (غير مستخدم، الكود يكتب مباشرة في notifications)
-- 4. حذف جدول absence_notifications (غير مستخدم)
-- 5. إضافة عمود value_string إلى notification_preferences
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. تغيير alert_type من ENUM إلى VARCHAR
ALTER TABLE `alerts`
    MODIFY `alert_type` VARCHAR(50) NOT NULL COMMENT 'نوع التنبيه';

-- 2. تغيير notifications.type من ENUM إلى VARCHAR
ALTER TABLE `notifications`
    MODIFY `type` VARCHAR(50) NOT NULL COMMENT 'نوع الإشعار';

-- 3. حذف جدول alert_notifications (مهجور)
DROP TABLE IF EXISTS `alert_notifications`;

-- 4. حذف جدول absence_notifications (مهجور)
DROP TABLE IF EXISTS `absence_notifications`;

-- 5. إضافة عمود value_string إلى notification_preferences
ALTER TABLE `notification_preferences`
    ADD COLUMN `value_string` VARCHAR(255) DEFAULT NULL COMMENT 'قيمة نصية (للساعات والتكرار)'
    AFTER `is_enabled`;

SET FOREIGN_KEY_CHECKS = 1;
