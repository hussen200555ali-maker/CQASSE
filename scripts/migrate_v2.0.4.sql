-- ============================================
-- EQAS Migration v2.0.3 → v2.0.4
-- إضافة الأعمدة المفقودة إلى جدول subject_teachers
-- التاريخ: 2026-05-14
-- ============================================

-- إضافة is_active إذا لم يكن موجوداً
SET @dbname = DATABASE();
SET @exists_is_active = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'subject_teachers' AND COLUMN_NAME = 'is_active');
SET @sql1 = IF(@exists_is_active = 0,
    'ALTER TABLE `subject_teachers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''هل السجل نشط'' AFTER `semester_id`',
    'SELECT ''Column is_active already exists'' AS status');
PREPARE stmt FROM @sql1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة academic_year_id إذا لم يكن موجوداً
SET @exists_year = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'subject_teachers' AND COLUMN_NAME = 'academic_year_id');
SET @sql2 = IF(@exists_year = 0,
    'ALTER TABLE `subject_teachers` ADD COLUMN `academic_year_id` INT UNSIGNED NOT NULL COMMENT ''رقم العام الدراسي'' AFTER `semester_id`',
    'SELECT ''Column academic_year_id already exists'' AS status');
PREPARE stmt FROM @sql2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة school_id إذا لم يكن موجوداً
SET @exists_school = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'subject_teachers' AND COLUMN_NAME = 'school_id');
SET @sql3 = IF(@exists_school = 0,
    'ALTER TABLE `subject_teachers` ADD COLUMN `school_id` INT UNSIGNED NOT NULL COMMENT ''رقم المدرسة'' AFTER `academic_year_id`',
    'SELECT ''Column school_id already exists'' AS status');
PREPARE stmt FROM @sql3;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
