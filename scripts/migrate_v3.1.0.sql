-- ============================================================================
-- EQAS v3.1.0 - إصلاح بنية خطط التحسين (Improvement Plans Schema Fix)
-- تاريخ الترحيل: 2026-05-16
--
-- التغييرات:
--   1. توحيد weak_areas → weaknesses في improvement_plans
--   2. إعادة تسمية responsible_teacher_id → responsible_user_id في improvement_plans
--   3. إنشاء جدول school_improvement_plans مع Foreign Keys (إذا لم يكن موجوداً)
--   4. إضافة عمود updated_by إلى school_improvement_plans (إذا كان موجوداً بدون)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 1. توحيد أسماء الحقول في improvement_plans
-- -----------------------------------------------------------

-- 1.1 إعادة تسمية weak_areas → weaknesses
-- التحقق من وجود العمود القديم قبل إعادة التسمية
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'improvement_plans'
    AND COLUMN_NAME = 'weak_areas'
);

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `improvement_plans` CHANGE COLUMN `weak_areas` `weaknesses` TEXT DEFAULT NULL COMMENT ''نقاط الضعف''',
    'SELECT ''عمود weak_areas غير موجود - تخطي'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1.2 إعادة تسمية responsible_teacher_id → responsible_user_id
SET @col_exists2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'improvement_plans'
    AND COLUMN_NAME = 'responsible_teacher_id'
);

SET @sql2 = IF(@col_exists2 > 0,
    'ALTER TABLE `improvement_plans` CHANGE COLUMN `responsible_teacher_id` `responsible_user_id` INT UNSIGNED DEFAULT NULL COMMENT ''رقم المستخدم المسؤول (معلم/مرشد)''',
    'SELECT ''عمود responsible_teacher_id غير موجود - تخطي'' AS info'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 1.3 إعادة تسمية مفتاح الفهرس
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'improvement_plans'
    AND INDEX_NAME = 'idx_improvement_plans_teacher'
);

SET @sql3 = IF(@idx_exists > 0,
    'ALTER TABLE `improvement_plans` DROP INDEX `idx_improvement_plans_teacher`, ADD INDEX `idx_improvement_plans_responsible` (`responsible_user_id`)',
    'SELECT ''فهرس idx_improvement_plans_teacher غير موجود - تخطي'' AS info'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 1.4 إعادة تسمية قيد Foreign Key
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'improvement_plans'
    AND CONSTRAINT_NAME = 'fk_improvement_plans_teacher'
);

SET @sql4 = IF(@fk_exists > 0,
    'ALTER TABLE `improvement_plans` DROP FOREIGN KEY `fk_improvement_plans_teacher`, ADD CONSTRAINT `fk_improvement_plans_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''قيد fk_improvement_plans_teacher غير موجود - تخطي'' AS info'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- -----------------------------------------------------------
-- 2. إنشاء جدول school_improvement_plans (إذا لم يكن موجوداً)
-- -----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `school_improvement_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `trigger_type` ENUM('climate','facility','both') NOT NULL DEFAULT 'both' COMMENT 'نوع المحفز',
    `weaknesses` TEXT DEFAULT NULL COMMENT 'نقاط الضعف',
    `goals` TEXT DEFAULT NULL COMMENT 'الأهداف',
    `strategies` TEXT DEFAULT NULL COMMENT 'الاستراتيجيات',
    `timeline` VARCHAR(200) DEFAULT NULL COMMENT 'الجدول الزمني',
    `responsible_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم المسؤول',
    `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active' COMMENT 'حالة الخطة',
    `progress` INT NOT NULL DEFAULT 0 COMMENT 'نسبة التقدم',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم المنشئ',
    `updated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم المحدّث',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الخطة نشطة',
    PRIMARY KEY (`id`),
    KEY `idx_school_improvement_school` (`school_id`),
    KEY `idx_school_improvement_status` (`status`),
    KEY `idx_school_improvement_responsible` (`responsible_user_id`),
    KEY `idx_school_improvement_created_by` (`created_by`),
    KEY `idx_school_improvement_active` (`is_active`),
    CONSTRAINT `fk_school_improvement_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_improvement_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_school_improvement_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_school_improvement_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول خطط تحسين المدرسة';

-- 2.1 إذا كان الجدول موجوداً بدون updated_by، أضفه
SET @col_exists3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'school_improvement_plans'
    AND COLUMN_NAME = 'updated_by'
);

SET @sql5 = IF(@col_exists3 = 0,
    'ALTER TABLE `school_improvement_plans` ADD COLUMN `updated_by` INT UNSIGNED DEFAULT NULL COMMENT ''رقم المستخدم المحدّث'' AFTER `created_by`, ADD KEY `idx_school_improvement_updated_by` (`updated_by`), ADD CONSTRAINT `fk_school_improvement_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''عمود updated_by موجود بالفعل - تخطي'' AS info'
);
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- 2.2 إذا كان الجدول موجوداً بعمود responsible (VARCHAR)، حوّله إلى responsible_user_id (FK)
SET @col_exists4 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'school_improvement_plans'
    AND COLUMN_NAME = 'responsible'
);

SET @sql6 = IF(@col_exists4 > 0,
    'ALTER TABLE `school_improvement_plans` DROP COLUMN `responsible`, ADD COLUMN `responsible_user_id` INT UNSIGNED DEFAULT NULL COMMENT ''رقم المستخدم المسؤول'' AFTER `timeline`, ADD KEY `idx_school_improvement_responsible` (`responsible_user_id`), ADD CONSTRAINT `fk_school_improvement_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''عمود responsible غير موجود - تخطي'' AS info'
);
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

-- -----------------------------------------------------------
-- 3. إضافة أعمدة الربط (NULL DEFAULT) - المرحلة 2
-- -----------------------------------------------------------

-- 3.1 إضافة school_improvement_plan_id إلى teacher_development_plans
SET @col_exists5 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'teacher_development_plans'
    AND COLUMN_NAME = 'school_improvement_plan_id'
);

SET @sql7 = IF(@col_exists5 = 0,
    'ALTER TABLE `teacher_development_plans` ADD COLUMN `school_improvement_plan_id` INT UNSIGNED DEFAULT NULL COMMENT ''رقم خطة تحسين المدرسة المرتبطة'' AFTER `school_id`, ADD KEY `idx_teacher_dev_school_plan` (`school_improvement_plan_id`), ADD CONSTRAINT `fk_teacher_dev_school_plan` FOREIGN KEY (`school_improvement_plan_id`) REFERENCES `school_improvement_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''عمود school_improvement_plan_id موجود في teacher_development_plans - تخطي'' AS info'
);
PREPARE stmt7 FROM @sql7;
EXECUTE stmt7;
DEALLOCATE PREPARE stmt7;

-- 3.2 إضافة school_improvement_plan_id إلى improvement_plans
SET @col_exists6 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'improvement_plans'
    AND COLUMN_NAME = 'school_improvement_plan_id'
);

SET @sql8 = IF(@col_exists6 = 0,
    'ALTER TABLE `improvement_plans` ADD COLUMN `school_improvement_plan_id` INT UNSIGNED DEFAULT NULL COMMENT ''رقم خطة تحسين المدرسة المرتبطة'' AFTER `decision_id`, ADD KEY `idx_improvement_school_plan` (`school_improvement_plan_id`), ADD CONSTRAINT `fk_improvement_school_plan` FOREIGN KEY (`school_improvement_plan_id`) REFERENCES `school_improvement_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT ''عمود school_improvement_plan_id موجود في improvement_plans - تخطي'' AS info'
);
PREPARE stmt8 FROM @sql8;
EXECUTE stmt8;
DEALLOCATE PREPARE stmt8;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- نهاية الترحيل v3.1.0
-- ============================================================================
