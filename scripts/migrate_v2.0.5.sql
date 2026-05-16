-- ============================================
-- EQAS Migration v2.0.4 → v2.0.5
-- إصلاح هيكل جدول التنبيهات ومطابقة ENUM مع الكود
-- التاريخ: 2026-05-15
-- ============================================

-- 1. ترحيل البيانات الموجودة قبل تغيير ENUM
UPDATE `alerts` SET `alert_type` = 'academic' WHERE `alert_type` IN ('low_grade', 'failing_subject');
UPDATE `alerts` SET `alert_type` = 'behavior' WHERE `alert_type` IN ('poor_behavior', 'behavior_below_threshold');
UPDATE `alerts` SET `alert_type` = 'attendance' WHERE `alert_type` IN ('high_absence', 'consecutive_absence', 'attendance_below_threshold');
UPDATE `alerts` SET `alert_type` = 'eqi' WHERE `alert_type` = 'eqi_decline';
UPDATE `alerts` SET `alert_type` = 'promotion' WHERE `alert_type` = 'promotion_risk';

-- 2. إضافة notes إلى جدول alerts إذا لم يكن موجوداً
SET @dbname = DATABASE();
SET @exists_notes = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'notes');
SET @sql_notes = IF(@exists_notes = 0,
    'ALTER TABLE `alerts` ADD COLUMN `notes` TEXT NULL COMMENT ''ملاحظات الحل'' AFTER `message_ar`',
    'SELECT ''Column notes already exists'' AS status');
PREPARE stmt FROM @sql_notes;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. تغيير ENUM alert_type في جدول alerts إذا لم يكن مطابقاً للكود
SET @current_enum = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'alerts' AND COLUMN_NAME = 'alert_type');
SET @sql_enum = IF(INSTR(@current_enum, 'academic') = 0,
    'ALTER TABLE `alerts` MODIFY COLUMN `alert_type` ENUM(''academic'',''behavior'',''attendance'',''eqi'',''promotion'') NOT NULL COMMENT ''نوع التنبيه''',
    'SELECT ''alerts.alert_type ENUM already updated'' AS status');
PREPARE stmt FROM @sql_enum;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. تغيير ENUM alert_type في جدول alert_settings إذا لم يكن مطابقاً
SET @settings_enum = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'alert_settings' AND COLUMN_NAME = 'alert_type');
SET @sql_settings = IF(INSTR(@settings_enum, 'academic') = 0,
    'ALTER TABLE `alert_settings` MODIFY COLUMN `alert_type` ENUM(''academic'',''behavior'',''attendance'',''eqi'',''promotion'') NOT NULL COMMENT ''نوع التنبيه''',
    'SELECT ''alert_settings.alert_type ENUM already updated'' AS status');
PREPARE stmt FROM @sql_settings;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
