-- ============================================================
-- الترحيل إلى v3.4.0: دعم استمارات تقييم متعددة من نفس النوع
-- ============================================================
-- التاريخ: 2026-05-17
-- الوصف: إزالة UNIQUE constraint من form_key، إضافة is_default و version
-- ============================================================

SET @dbname = DATABASE();

-- 1. إزالة UNIQUE constraint وإضافة INDEX عادي
ALTER TABLE `assessment_forms`
  DROP INDEX `uk_assessment_forms_key`,
  ADD INDEX `idx_assessment_forms_key` (`form_key`);

-- 2. إضافة أعمدة is_default و version
SET @exists_default = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'assessment_forms' AND COLUMN_NAME = 'is_default');
SET @sql_default = IF(@exists_default = 0,
    'ALTER TABLE `assessment_forms` ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''استمارة افتراضية'' AFTER `is_system`',
    'SELECT ''Column is_default already exists'' AS status');
PREPARE stmt1 FROM @sql_default;
EXECUTE stmt1;
DROP PREPARE stmt1;

SET @exists_version = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'assessment_forms' AND COLUMN_NAME = 'version');
SET @sql_version = IF(@exists_version = 0,
    'ALTER TABLE `assessment_forms` ADD COLUMN `version` VARCHAR(50) DEFAULT NULL COMMENT ''رقم الإصدار/الوصف'' AFTER `is_default`',
    'SELECT ''Column version already exists'' AS status');
PREPARE stmt2 FROM @sql_version;
EXECUTE stmt2;
DROP PREPARE stmt2;

-- 3. تعيين أول استمارة من كل نوع كافتراضية
UPDATE `assessment_forms` af
SET `is_default` = 1
WHERE `id` = (
    SELECT `id` FROM (
        SELECT MIN(`id`) as `id` FROM `assessment_forms` af2
        WHERE af2.`form_key` = af.`form_key`
    ) AS `tmp`
);
