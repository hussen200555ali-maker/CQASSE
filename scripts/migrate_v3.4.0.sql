-- ============================================================
-- الترحيل إلى v3.4.0: دعم استمارات تقييم متعددة من نفس النوع
-- ============================================================
-- التاريخ: 2026-05-17
-- الوصف: إزالة UNIQUE constraint من form_key، إضافة is_default و version
-- ============================================================

SET @dbname = DATABASE();

-- 1. إزالة UNIQUE constraint وإضافة INDEX عادي (قابل للتشغيل المتكرر)
SET @has_unique = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'assessment_forms' AND INDEX_NAME = 'uk_assessment_forms_key');
SET @sql_unique = IF(@has_unique > 0,
    'ALTER TABLE `assessment_forms` DROP INDEX `uk_assessment_forms_key`',
    'SELECT ''UNIQUE uk_assessment_forms_key already removed'' AS status');
PREPARE stmt_unique FROM @sql_unique;
EXECUTE stmt_unique;
DROP PREPARE stmt_unique;

SET @has_index = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'assessment_forms' AND INDEX_NAME = 'idx_assessment_forms_key');
SET @sql_index = IF(@has_index = 0,
    'ALTER TABLE `assessment_forms` ADD INDEX `idx_assessment_forms_key` (`form_key`)',
    'SELECT ''INDEX idx_assessment_forms_key already exists'' AS status');
PREPARE stmt_index FROM @sql_index;
EXECUTE stmt_index;
DROP PREPARE stmt_index;

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

-- 3. تعيين أول استمارة من كل نوع كافتراضية (وتصفير البقية)
UPDATE `assessment_forms` af
JOIN (
    SELECT `form_key`, MIN(`id`) AS `mid`
    FROM `assessment_forms`
    GROUP BY `form_key`
) t ON t.`form_key` = af.`form_key`
SET af.`is_default` = (af.`id` = t.`mid`);
