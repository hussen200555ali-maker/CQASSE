-- ============================================================
-- الترقيع v2.2.0: إضافة climate + teaching إلى ENUM التنبيهات
-- ============================================================

-- 1. تعديل ENUM في جدول alerts
ALTER TABLE `alerts` MODIFY COLUMN `alert_type`
    ENUM('academic','behavior','attendance','eqi','promotion','climate','teaching')
    NOT NULL COMMENT 'نوع التنبيه';

-- 2. تعديل ENUM في جدول alert_settings (إن وجد)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_settings'
                   AND COLUMN_NAME = 'alert_type');
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `alert_settings` MODIFY COLUMN `alert_type`
     ENUM(''academic'',''behavior'',''attendance'',''eqi'',''promotion'',''climate'',''teaching'')
     NOT NULL COMMENT ''نوع التنبيه''',
    'SELECT "alert_settings table or column not found" AS status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
