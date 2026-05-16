-- ============================================
-- EQAS Migration v2.0.2 → v2.0.3
-- إضافة الجداول المفقودة حسب تقرير التدقيق
-- التاريخ: 2026-05-11
-- تراجع: احذف الجداول يدوياً أو استخدم backups/pre_v2.0.3/
-- ============================================

-- جدول المديريات
-- [إصلاح v2.0.3] إضافة name_ar, governorate_id, is_active لتطابق كود PHP
CREATE TABLE IF NOT EXISTS `districts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'الاسم بالإنجليزية أو المختصر',
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'الاسم بالعربية — تستخدمه add.php/edit.php',
    `governorate_id` INT UNSIGNED NULL COMMENT 'معرف المحافظة — تستخدمه add.php',
    `city_id` INT UNSIGNED NULL COMMENT 'معرف المدينة (قديم)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'حالة التفعيل — تستخدمه add.php/edit.php',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_districts_governorate` (`governorate_id`),
    INDEX `idx_districts_city` (`city_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بيانات أولية للمديريات (أمثلة — يجب استكمالها ببيانات اليمن الفعلية)
INSERT IGNORE INTO `districts` (`id`, `name`, `name_ar`, `governorate_id`, `city_id`, `is_active`) VALUES
(1, 'Al Mukalla', 'المكلا', NULL, NULL, 1),
(2, 'Sayun', 'سيئون', NULL, NULL, 1),
(3, 'Aden', 'عدن', NULL, NULL, 1),
(4, 'Sanaa', 'صنعاء', NULL, NULL, 1),
(5, 'Taiz', 'تعز', NULL, NULL, 1);
-- ⚠️ تنبيه: هذه بيانات أولية تجريبية فقط. يجب استكمالها بقائمة المديريات ة الكاملة.

-- جدول معلمي المواد
CREATE TABLE IF NOT EXISTS `subject_teachers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT UNSIGNED NOT NULL,
    `teacher_id` INT UNSIGNED NOT NULL,
    `classroom_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `school_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_subject_teacher_class_sem` (`subject_id`, `teacher_id`, `classroom_id`, `semester_id`),
    INDEX `idx_subject_teachers_teacher` (`teacher_id`),
    INDEX `idx_subject_teachers_classroom` (`classroom_id`),
    INDEX `idx_subject_teachers_semester` (`semester_id`),
    INDEX `idx_subject_teachers_school` (`school_id`),
    INDEX `idx_subject_teachers_year` (`academic_year_id`),
    INDEX `idx_subject_teachers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول بنود الإدارة التعليمية
CREATE TABLE IF NOT EXISTS `teaching_admin_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `criteria` TEXT NOT NULL,
    `weight` DECIMAL(5,2) DEFAULT 1.0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول تقييم المهارات
CREATE TABLE IF NOT EXISTS `skills_evaluations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `teacher_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `skill_id` INT UNSIGNED NOT NULL,
    `score` DECIMAL(5,2) NOT NULL,
    `comments` TEXT NULL,
    `evaluated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_skills_eval_student` (`student_id`),
    INDEX `idx_skills_eval_teacher` (`teacher_id`),
    INDEX `idx_skills_eval_semester` (`semester_id`),
    INDEX `idx_skills_eval_skill` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول بنود المهارات
CREATE TABLE IF NOT EXISTS `skills_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `skill_id` INT UNSIGNED NOT NULL,
    `item_text` TEXT NOT NULL,
    `max_score` DECIMAL(5,2) DEFAULT 5,
    `sort_order` INT DEFAULT 0,
    INDEX `idx_skills_items_skill` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول الامتحانات التعويضية
CREATE TABLE IF NOT EXISTS `makeup_exams` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `exam_id` INT UNSIGNED NOT NULL,
    `score` DECIMAL(5,2) NULL,
    `approved` TINYINT(1) DEFAULT 0,
    `exam_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_makeup_exams_student` (`student_id`),
    INDEX `idx_makeup_exams_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول أولياء الأمور
CREATE TABLE IF NOT EXISTS `parents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT UNSIGNED NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(150) NULL,
    `relationship` VARCHAR(50) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_parents_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إضافة أعمدة مفقودة لجدول districts إذا كان موجوداً بالفعل (من ترحيل Phase 1 القديم)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'districts' AND COLUMN_NAME = 'name_ar');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE districts ADD COLUMN name_ar VARCHAR(100) NOT NULL DEFAULT "" COMMENT "الاسم بالعربية" AFTER name',
    'SELECT "districts.name_ar already exists" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'districts' AND COLUMN_NAME = 'governorate_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE districts ADD COLUMN governorate_id INT UNSIGNED NULL COMMENT "معرف المحافظة" AFTER name_ar',
    'SELECT "districts.governorate_id already exists" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'districts' AND COLUMN_NAME = 'is_active');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE districts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT "حالة التفعيل" AFTER city_id',
    'SELECT "districts.is_active already exists" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- تحديث البيانات الأولية: ملء name_ar من name إذا كانت فارغة
UPDATE districts SET name_ar = name WHERE name_ar = '' OR name_ar IS NULL;

-- إضافة عمود district_id لجدول schools إذا لم يكن موجوداً
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = 'district_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE schools ADD COLUMN district_id INT UNSIGNED NULL AFTER city_id', 
    'SELECT "district_id already exists in schools" AS status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- إضافة عمود reference_code لجدول schools إذا لم يكن موجوداً
SET @col_exists2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = 'reference_code');
SET @sql2 = IF(@col_exists2 = 0, 
    'ALTER TABLE schools ADD COLUMN reference_code VARCHAR(50) NULL AFTER code', 
    'SELECT "reference_code already exists in schools" AS status');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- ============================================
-- ترحيل أسماء الجداول (Rename)
-- ينفّذ فقط إذا كان الجدول القديم موجوداً والجديد غير موجود
-- ============================================

-- behavior: الاسم النهائي هو behavior_scores (موحّد)
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_assessments');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_scores');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE behavior_assessments TO behavior_scores', 'SELECT "behavior_scores migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_evaluations');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_scores');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE behavior_evaluations TO behavior_scores', 'SELECT "behavior_scores from evaluations migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- behavior_items
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_assessment_items');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'behavior_items');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE behavior_assessment_items TO behavior_items', 'SELECT "behavior_items migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- climate_items
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'climate_survey_items');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'climate_items');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE climate_survey_items TO climate_items', 'SELECT "climate_items migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- facility_items
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_checklist_items');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_items');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE facility_checklist_items TO facility_items', 'SELECT "facility_items migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- activity_logs
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE activity_log TO activity_logs', 'SELECT "activity_logs migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- export_logs
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'export_log');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'export_logs');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE export_log TO export_logs', 'SELECT "export_logs migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- dashboard_widgets
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_preferences');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_widgets');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE dashboard_preferences TO dashboard_widgets', 'SELECT "dashboard_widgets migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- equi_results
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equi_calculations');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equi_results');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE equi_calculations TO equi_results', 'SELECT "equi_results migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- attendance_summaries
SET @old_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_summary');
SET @new_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_summaries');
SET @sql = IF(@old_exists > 0 AND @new_exists = 0, 'RENAME TABLE attendance_summary TO attendance_summaries', 'SELECT "attendance_summaries migration skipped" AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_notes → notes: مؤجل حتى مقارنة الهياكل
-- نفّذ يدوياً بعد التحقق:
-- RENAME TABLE student_notes TO notes;
-- أو أنشئ عرضاً: CREATE VIEW notes AS SELECT * FROM student_notes;

-- ============================================
-- [إصلاح v2.0.3] إنشاء الجداول المفقودة
-- إذا تم التثبيت من schema.sql مباشرة فإن الجداول بالأسماء القديمة موجودة
-- لكن RENAME لا ينفذ لأن الشرط يفشل، فلا تُنشأ الأسماء الجديدة
-- هذا القسم يضمن وجود الجداول بالأسماء الجديدة
-- ============================================

-- behavior_items (اسم جديد لـ behavior_assessment_items)
CREATE TABLE IF NOT EXISTS `behavior_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assessment_id` INT UNSIGNED NOT NULL COMMENT 'رقم تقييم السلوك',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_behavior_items` (`assessment_id`, `item_id`),
    KEY `idx_behavior_items_item` (`item_id`),
    KEY `idx_behavior_items_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر تقييم السلوك (اسم جديد)';

-- climate_items (اسم جديد لـ climate_survey_items)
CREATE TABLE IF NOT EXISTS `climate_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id` INT UNSIGNED NOT NULL COMMENT 'رقم الاستبيان',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_climate_items` (`survey_id`, `item_id`),
    KEY `idx_climate_items_item` (`item_id`),
    KEY `idx_climate_items_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر استبيان المناخ (اسم جديد)';

-- facility_items (اسم جديد لـ facility_checklist_items)
CREATE TABLE IF NOT EXISTS `facility_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `checklist_id` INT UNSIGNED NOT NULL COMMENT 'رقم قائمة الفحص',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_facility_items` (`checklist_id`, `item_id`),
    KEY `idx_facility_items_item` (`item_id`),
    KEY `idx_facility_items_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر قائمة فحص المرافق (اسم جديد)';

-- dashboard_widgets (اسم جديد لـ dashboard_preferences)
CREATE TABLE IF NOT EXISTS `dashboard_widgets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم',
    `widget_type` VARCHAR(50) NOT NULL COMMENT 'نوع الأداة',
    `title_ar` VARCHAR(200) NOT NULL COMMENT 'عنوان الأداة بالعربية',
    `config` JSON DEFAULT NULL COMMENT 'إعدادات الأداة',
    `position` INT NOT NULL DEFAULT 0 COMMENT 'الموقع',
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الأداة مرئية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    KEY `idx_dashboard_widgets_user` (`user_id`),
    KEY `idx_dashboard_widgets_widget` (`widget_type`),
    KEY `idx_dashboard_widgets_position` (`position`),
    KEY `idx_dashboard_widgets_visible` (`is_visible`),
    KEY `idx_dashboard_widgets_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول أدوات لوحة المعلومات (اسم جديد)';

-- attendance_summaries (اسم جديد لـ attendance_summary)
CREATE TABLE IF NOT EXISTS `attendance_summaries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `total_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'إجمالي الأيام',
    `present_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أيام الحضور',
    `absent_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أيام الغياب',
    `late_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أيام التأخير',
    `excused_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'أيام الغياب بعذر',
    `attendance_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة الحضور',
    `is_below_threshold` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل أقل من الحد المطلوب',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_attendance_summaries` (`student_id`, `class_id`, `academic_year_id`, `semester_id`),
    KEY `idx_attendance_summaries_class` (`class_id`),
    KEY `idx_attendance_summaries_academic_year` (`academic_year_id`),
    KEY `idx_attendance_summaries_semester` (`semester_id`),
    KEY `idx_attendance_summaries_rate` (`attendance_rate`),
    KEY `idx_attendance_summaries_below_threshold` (`is_below_threshold`),
    KEY `idx_attendance_summaries_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملخص الحضور (اسم جديد)';

-- export_logs (اسم جديد لـ export_log)
CREATE TABLE IF NOT EXISTS `export_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم',
    `export_type` VARCHAR(50) NOT NULL COMMENT 'نوع التصدير',
    `file_name` VARCHAR(255) NOT NULL COMMENT 'اسم الملف',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار الملف',
    `file_size` INT UNSIGNED DEFAULT NULL COMMENT 'حجم الملف بالبايت',
    `row_count` INT UNSIGNED DEFAULT NULL COMMENT 'عدد الصفوف',
    `filters` JSON DEFAULT NULL COMMENT 'فلاتر التصدير',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_export_logs_user` (`user_id`),
    KEY `idx_export_logs_type` (`export_type`),
    KEY `idx_export_logs_created` (`created_at`),
    KEY `idx_export_logs_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل التصدير (اسم جديد)';

-- نسخ البيانات من الجداول القديمة إلى الجديدة إذا كانت فارغة والقديمة موجودة
-- behavior_items
INSERT IGNORE INTO `behavior_items` (`id`, `assessment_id`, `item_id`, `numeric_value`, `normalized_value`, `notes`, `is_active`)
SELECT `id`, `assessment_id`, `item_id`, `numeric_value`, `normalized_value`, `notes`, `is_active`
FROM `behavior_assessment_items`
WHERE (SELECT COUNT(*) FROM `behavior_items`) = 0;

-- climate_items
INSERT IGNORE INTO `climate_items` (`id`, `survey_id`, `item_id`, `numeric_value`, `normalized_value`, `is_active`)
SELECT `id`, `survey_id`, `item_id`, `numeric_value`, `normalized_value`, `is_active`
FROM `climate_survey_items`
WHERE (SELECT COUNT(*) FROM `climate_items`) = 0;

-- facility_items
INSERT IGNORE INTO `facility_items` (`id`, `checklist_id`, `item_id`, `numeric_value`, `normalized_value`, `notes`, `is_active`)
SELECT `id`, `checklist_id`, `item_id`, `numeric_value`, `normalized_value`, `notes`, `is_active`
FROM `facility_checklist_items`
WHERE (SELECT COUNT(*) FROM `facility_items`) = 0;

-- dashboard_widgets
INSERT IGNORE INTO `dashboard_widgets` (`id`, `user_id`, `widget_type`, `title_ar`, `config`, `position`, `is_visible`, `is_active`, `created_at`, `updated_at`)
SELECT `id`, `user_id`, `widget_type`, `title_ar`, `config`, `position`, `is_visible`, `is_active`, `created_at`, `updated_at`
FROM `dashboard_preferences`
WHERE (SELECT COUNT(*) FROM `dashboard_widgets`) = 0;

-- attendance_summaries
INSERT IGNORE INTO `attendance_summaries` (`id`, `student_id`, `class_id`, `academic_year_id`, `semester_id`, `total_days`, `present_days`, `absent_days`, `late_days`, `excused_days`, `attendance_rate`, `is_below_threshold`, `is_active`, `updated_at`)
SELECT `id`, `student_id`, `class_id`, `academic_year_id`, `semester_id`, `total_days`, `present_days`, `absent_days`, `late_days`, `excused_days`, `attendance_rate`, `is_below_threshold`, `is_active`, `updated_at`
FROM `attendance_summary`
WHERE (SELECT COUNT(*) FROM `attendance_summaries`) = 0;

-- export_logs
INSERT IGNORE INTO `export_logs` (`id`, `user_id`, `export_type`, `file_name`, `file_path`, `file_size`, `row_count`, `filters`, `is_active`, `created_at`)
SELECT `id`, `user_id`, `export_type`, `file_name`, `file_path`, `file_size`, `row_count`, `filters`, `is_active`, `created_at`
FROM `export_log`
WHERE (SELECT COUNT(*) FROM `export_logs`) = 0;

-- -----------------------------------------------------------
-- v2.1.0: جدول السياق المدرسي (معامل التعديل السياقي)
-- -----------------------------------------------------------
SET @schema_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_context');
SET @sql_create = IF(@schema_exists = 0, '
CREATE TABLE `school_context` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `location_type` ENUM(''urban'',''rural'',''suburban'') DEFAULT NULL,
    `total_students` INT DEFAULT NULL,
    `student_teacher_ratio` DECIMAL(5,2) DEFAULT NULL,
    `socioeconomic_index` DECIMAL(5,2) DEFAULT NULL,
    `parent_education_level` DECIMAL(5,2) DEFAULT NULL,
    `students_with_special_needs_pct` DECIMAL(5,2) DEFAULT NULL,
    `infrastructure_score` DECIMAL(5,2) DEFAULT NULL,
    `student_mobility_rate` DECIMAL(5,2) DEFAULT NULL,
    `challenge_index` DECIMAL(5,2) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_school_context` (`school_id`, `academic_year_id`),
    KEY `idx_school_context_school` (`school_id`),
    KEY `idx_school_context_year` (`academic_year_id`),
    CONSTRAINT `fk_school_context_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_context_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
', 'SELECT 1');
PREPARE stmt FROM @sql_create;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
