-- ============================================================================
-- نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
-- مخطط قاعدة البيانات الكامل
-- الإصدار: 3.2.0
-- تاريخ الإنشاء: 2025
-- إجمالي الكائنات: 69 جدول + 1 عرض (v_student_current)
-- يشمل: governorates, permissions, role_permissions
-- تاريخ التحديث: 2026-05-16
-- تحديثات v3.2.0: إصلاح مركز الإشعارات (ENUM→VARCHAR, حذف جداول يتيمة, إضافة value_string)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- المرحلة 1: إدارة النظام (1.1-1.3)
-- Phase 1: System Management
-- ============================================================================

-- -----------------------------------------------------------
-- 1.1 جدول الأدوار
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL COMMENT 'اسم الدور بالإنجليزية',
    `display_name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم الدور المعروض بالعربية',
    `display_name_en` VARCHAR(100) DEFAULT NULL COMMENT 'اسم الدور المعروض بالإنجليزية',
    `rank` INT NOT NULL DEFAULT 0 COMMENT 'ترتيب الدور',
    `description` TEXT DEFAULT NULL COMMENT 'وصف الدور',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الدور نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_roles_name` (`name`),
    KEY `idx_roles_active` (`is_active`),
    KEY `idx_roles_rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الأدوار';

-- -----------------------------------------------------------
-- 1.2 جدول المدارس
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `schools`;
CREATE TABLE `schools` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم المدرسة بالعربية',
    `name_en` VARCHAR(200) DEFAULT NULL COMMENT 'اسم المدرسة بالإنجليزية',
    `reference_code` VARCHAR(5) NOT NULL COMMENT 'رمز المرجعي الفريد',
    `address` TEXT DEFAULT NULL COMMENT 'عنوان المدرسة',
    `governorate` VARCHAR(100) DEFAULT NULL COMMENT 'المحافظة',
    `district` VARCHAR(100) DEFAULT NULL COMMENT 'المديرية',
    `school_type` ENUM('basic','secondary','combined') NOT NULL DEFAULT 'combined' COMMENT 'نوع المدرسة',
    `principal_name` VARCHAR(200) DEFAULT NULL COMMENT 'اسم مدير المدرسة',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT 'رقم الهاتف',
    `email` VARCHAR(100) DEFAULT NULL COMMENT 'البريد الإلكتروني',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المدرسة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_schools_reference_code` (`reference_code`),
    KEY `idx_schools_governorate` (`governorate`),
    KEY `idx_schools_district` (`district`),
    KEY `idx_schools_type` (`school_type`),
    KEY `idx_schools_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المدارس';

-- -----------------------------------------------------------
-- 1.2 جدول المراحل الدراسية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_levels`;
CREATE TABLE `grade_levels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(50) NOT NULL COMMENT 'اسم الصف بالعربية',
    `name_en` VARCHAR(50) DEFAULT NULL COMMENT 'اسم الصف بالإنجليزية',
    `level_number` TINYINT UNSIGNED NOT NULL COMMENT 'رقم الصف (1-12)',
    `stage` ENUM('basic','secondary') NOT NULL COMMENT 'المرحلة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الصف نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grade_levels_number` (`level_number`),
    KEY `idx_grade_levels_stage` (`stage`),
    KEY `idx_grade_levels_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المراحل الدراسية';

-- -----------------------------------------------------------
-- 1.1 جدول المستخدمين
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL COMMENT 'اسم المستخدم',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'كلمة المرور المشفرة',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إجبار تغيير كلمة المرور عند أول تسجيل دخول',
    `full_name_ar` VARCHAR(200) NOT NULL COMMENT 'الاسم الكامل بالعربية',
    `full_name_en` VARCHAR(200) DEFAULT NULL COMMENT 'الاسم الكامل بالإنجليزية',
    `email` VARCHAR(100) DEFAULT NULL COMMENT 'البريد الإلكتروني',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT 'رقم الهاتف',
    `role_id` INT UNSIGNED NOT NULL COMMENT 'رقم الدور',
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المدرسة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المستخدم نشط',
    `last_login_at` DATETIME DEFAULT NULL COMMENT 'آخر تسجيل دخول',
    `login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'عدد محاولات الدخول',
    `lockout_until` DATETIME DEFAULT NULL COMMENT 'تاريخ انتهاء القفل',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    KEY `idx_users_email` (`email`),
    KEY `idx_users_role` (`role_id`),
    KEY `idx_users_school` (`school_id`),
    KEY `idx_users_active` (`is_active`),
    KEY `idx_users_lockout` (`lockout_until`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المستخدمين';

-- -----------------------------------------------------------
-- 1.1 جدول سجل تغيير الأدوار
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `role_change_log`;
CREATE TABLE `role_change_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم',
    `old_role_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الدور القديم',
    `new_role_id` INT UNSIGNED NOT NULL COMMENT 'رقم الدور الجديد',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب التغيير',
    `changed_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي قام بالتغيير',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_role_change_log_user` (`user_id`),
    KEY `idx_role_change_log_old_role` (`old_role_id`),
    KEY `idx_role_change_log_new_role` (`new_role_id`),
    KEY `idx_role_change_log_changed_by` (`changed_by`),
    KEY `idx_role_change_log_created` (`created_at`),
    CONSTRAINT `fk_role_change_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_role_change_log_old_role` FOREIGN KEY (`old_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_role_change_log_new_role` FOREIGN KEY (`new_role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_role_change_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل تغيير الأدوار';

-- -----------------------------------------------------------
-- جدول الطلاب
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_number` VARCHAR(20) NOT NULL COMMENT 'رقم الطالب',
    `full_name_ar` VARCHAR(200) NOT NULL COMMENT 'الاسم الكامل بالعربية',
    `full_name_en` VARCHAR(200) DEFAULT NULL COMMENT 'الاسم الكامل بالإنجليزية',
    `guardian_name` VARCHAR(200) DEFAULT NULL COMMENT 'اسم ولي الأمر',
    `guardian_phone` VARCHAR(20) DEFAULT NULL COMMENT 'هاتف ولي الأمر',
    `guardian_email` VARCHAR(100) DEFAULT NULL COMMENT 'بريد ولي الأمر',
    `grade_level_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف الدراسي',
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `enrollment_date` DATE DEFAULT NULL COMMENT 'تاريخ التسجيل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الطالب نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_students_number` (`student_number`),
    KEY `idx_students_name` (`full_name_ar`),
    KEY `idx_students_grade` (`grade_level_id`),
    KEY `idx_students_school` (`school_id`),
    KEY `idx_students_guardian_phone` (`guardian_phone`),
    KEY `idx_students_active` (`is_active`),
    CONSTRAINT `fk_students_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_students_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الطلاب';

-- -----------------------------------------------------------
-- 1.1 جدول علاقة أولياء الأمور بالطلاب
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `parent_students`;
CREATE TABLE `parent_students` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NOT NULL COMMENT 'رقم ولي الأمر (مستخدم)',
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `relationship` ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'father' COMMENT 'صلة القرابة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العلاقة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_parent_students` (`parent_id`, `student_id`),
    KEY `idx_parent_students_student` (`student_id`),
    KEY `idx_parent_students_active` (`is_active`),
    CONSTRAINT `fk_parent_students_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_parent_students_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول علاقة أولياء الأمور بالطلاب';

-- -----------------------------------------------------------
-- 1.3 جدول السنوات الدراسية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL COMMENT 'اسم السنة الدراسية',
    `start_date` DATE NOT NULL COMMENT 'تاريخ البداية',
    `end_date` DATE NOT NULL COMMENT 'تاريخ النهاية',
    `is_current` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هي السنة الحالية',
    `status` ENUM('planning','active','evaluation','closed') NOT NULL DEFAULT 'planning' COMMENT 'حالة السنة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السنة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_academic_years_current` (`is_current`),
    KEY `idx_academic_years_status` (`status`),
    KEY `idx_academic_years_dates` (`start_date`, `end_date`),
    KEY `idx_academic_years_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول السنوات الدراسية';

-- -----------------------------------------------------------
-- 1.3 جدول الفصول الدراسية (الأول والثاني)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `semesters`;
CREATE TABLE `semesters` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `name_ar` VARCHAR(50) NOT NULL COMMENT 'اسم الفصل الدراسي',
    `start_date` DATE NOT NULL COMMENT 'تاريخ البداية',
    `end_date` DATE NOT NULL COMMENT 'تاريخ النهاية',
    `is_current` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هو الفصل الحالي',
    `lock_level` ENUM('none','soft','hard','admin_handled') NOT NULL DEFAULT 'none' COMMENT 'مستوى القفل',
    `locked_at` DATETIME DEFAULT NULL COMMENT 'تاريخ القفل',
    `locked_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي قفل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الفصل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_semesters_academic_year` (`academic_year_id`),
    KEY `idx_semesters_current` (`is_current`),
    KEY `idx_semesters_dates` (`start_date`, `end_date`),
    KEY `idx_semesters_lock_level` (`lock_level`),
    KEY `idx_semesters_active` (`is_active`),
    KEY `idx_semesters_locked_by` (`locked_by`),
    CONSTRAINT `fk_semesters_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_semesters_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفصول الدراسية';

-- -----------------------------------------------------------
-- 1.3 جدول سجل حالات الفصول الدراسية
-- ⚠️ مؤجل: غير مستخدم حالياً، محفوظ للتوثيق أو الاستخدام المستقبلي
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `semester_status_log`;
CREATE TABLE `semester_status_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `old_status` VARCHAR(50) DEFAULT NULL COMMENT 'الحالة القديمة',
    `new_status` VARCHAR(50) NOT NULL COMMENT 'الحالة الجديدة',
    `changed_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي قام بالتغيير',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب التغيير',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_semester_status_log_semester` (`semester_id`),
    KEY `idx_semester_status_log_changed_by` (`changed_by`),
    KEY `idx_semester_status_log_created` (`created_at`),
    CONSTRAINT `fk_semester_status_log_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_semester_status_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل حالات الفصول الدراسية';

-- -----------------------------------------------------------
-- جدول الفصول (الشعب الدراسية)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم الفصل بالعربية',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `grade_level_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف الدراسي',
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `teacher_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المعلم المشرف',
    `capacity` INT UNSIGNED DEFAULT 30 COMMENT 'سعة الفصل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الفصل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_classes_academic_year` (`academic_year_id`),
    KEY `idx_classes_grade` (`grade_level_id`),
    KEY `idx_classes_school` (`school_id`),
    KEY `idx_classes_teacher` (`teacher_id`),
    KEY `idx_classes_active` (`is_active`),
    KEY `idx_classes_school_grade_year` (`school_id`, `grade_level_id`, `academic_year_id`),
    CONSTRAINT `fk_classes_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_classes_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_classes_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفصول الدراسية (الشعب)';

-- -----------------------------------------------------------
-- جدول تسجيل الطلاب في الفصول
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `student_enrollments`;
CREATE TABLE `student_enrollments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `enrollment_date` DATE NOT NULL COMMENT 'تاريخ التسجيل',
    `status` ENUM('enrolled','transferred','dropped') NOT NULL DEFAULT 'enrolled' COMMENT 'حالة التسجيل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل التسجيل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_enrollments` (`student_id`, `class_id`, `academic_year_id`),
    KEY `idx_student_enrollments_class` (`class_id`),
    KEY `idx_student_enrollments_academic_year` (`academic_year_id`),
    KEY `idx_student_enrollments_status` (`status`),
    KEY `idx_student_enrollments_active` (`is_active`),
    CONSTRAINT `fk_student_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_student_enrollments_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_enrollments_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تسجيل الطلاب في الفصول';

-- -----------------------------------------------------------
-- 1.2 جدول سنوات الصفوف الدراسية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_years`;
CREATE TABLE `grade_years` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `grade_level_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف الدراسي',
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `status` ENUM('enrolled','transferred','dropped','graduated') NOT NULL DEFAULT 'enrolled' COMMENT 'حالة الطالب',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grade_years` (`student_id`, `grade_level_id`, `academic_year_id`),
    KEY `idx_grade_years_grade_level` (`grade_level_id`),
    KEY `idx_grade_years_school` (`school_id`),
    KEY `idx_grade_years_academic_year` (`academic_year_id`),
    KEY `idx_grade_years_status` (`status`),
    KEY `idx_grade_years_active` (`is_active`),
    CONSTRAINT `fk_grade_years_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_years_grade_level` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_years_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_years_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سنوات الصفوف الدراسية';

-- ============================================================================
-- المرحلة 2: محرك التقييم (2.1)
-- Phase 2: Assessment Engine
-- ============================================================================

-- -----------------------------------------------------------
-- 2.1 جدول نماذج التقييم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_forms`;
CREATE TABLE `assessment_forms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_key` ENUM('behavior_eval','skills_checklist','teaching_self','teaching_peer','teaching_admin','climate_survey','facility_checklist') NOT NULL COMMENT 'مفتاح النموذج',
    `name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم النموذج بالعربية',
    `name_en` VARCHAR(200) DEFAULT NULL COMMENT 'اسم النموذج بالإنجليزية',
    `category` ENUM('behavior','skills','teaching','climate') NOT NULL COMMENT 'فئة النموذج',
    `target` ENUM('student','teacher','school') NOT NULL COMMENT 'هدف التقييم',
    `description` TEXT DEFAULT NULL COMMENT 'وصف النموذج',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل نموذج نظام',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل النموذج نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_assessment_forms_key` (`form_key`),
    KEY `idx_assessment_forms_category` (`category`),
    KEY `idx_assessment_forms_target` (`target`),
    KEY `idx_assessment_forms_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول نماذج التقييم';

-- -----------------------------------------------------------
-- 2.1 جدول معايير التقييم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_criteria`;
CREATE TABLE `assessment_criteria` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id` INT UNSIGNED NOT NULL COMMENT 'رقم النموذج',
    `name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم المعيار بالعربية',
    `name_en` VARCHAR(200) DEFAULT NULL COMMENT 'اسم المعيار بالإنجليزية',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
    `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'وزن المعيار',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المعيار نشط',
    PRIMARY KEY (`id`),
    KEY `idx_assessment_criteria_form` (`form_id`),
    KEY `idx_assessment_criteria_sort` (`sort_order`),
    KEY `idx_assessment_criteria_active` (`is_active`),
    CONSTRAINT `fk_assessment_criteria_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول معايير التقييم';

-- -----------------------------------------------------------
-- 2.1 جدول عناصر التقييم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_items`;
CREATE TABLE `assessment_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `criteria_id` INT UNSIGNED NOT NULL COMMENT 'رقم المعيار',
    `form_id` INT UNSIGNED NOT NULL COMMENT 'رقم النموذج',
    `text_ar` TEXT NOT NULL COMMENT 'نص العنصر بالعربية',
    `text_en` TEXT DEFAULT NULL COMMENT 'نص العنصر بالإنجليزية',
    `tool_type` ENUM('likert_5','frequency_5','satisfaction_5','yes_no','severity_4','counter','numeric_100','numeric_custom','rubric_4','text') NOT NULL COMMENT 'نوع الأداة',
    `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'وزن العنصر',
    `is_inverse` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل العنصر معكوس (لـ severity_4)',
    `max_value` DECIMAL(10,2) DEFAULT NULL COMMENT 'القيمة القصوى (لـ numeric_custom/counter)',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    KEY `idx_assessment_items_criteria` (`criteria_id`),
    KEY `idx_assessment_items_form` (`form_id`),
    KEY `idx_assessment_items_tool_type` (`tool_type`),
    KEY `idx_assessment_items_sort` (`sort_order`),
    KEY `idx_assessment_items_active` (`is_active`),
    CONSTRAINT `fk_assessment_items_criteria` FOREIGN KEY (`criteria_id`) REFERENCES `assessment_criteria` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_items_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر التقييم';

-- -----------------------------------------------------------
-- 2.1 جدول استجابات التقييم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_responses`;
CREATE TABLE `assessment_responses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id` INT UNSIGNED NOT NULL COMMENT 'رقم النموذج',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم العنصر',
    `student_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الطالب',
    `teacher_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المعلم',
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المدرسة',
    `class_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `text_value` TEXT DEFAULT NULL COMMENT 'القيمة النصية',
    `responded_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستجيب',
    `response_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الاستجابة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الاستجابة نشطة',
    PRIMARY KEY (`id`),
    KEY `idx_assessment_responses_form` (`form_id`),
    KEY `idx_assessment_responses_item` (`item_id`),
    KEY `idx_assessment_responses_student` (`student_id`),
    KEY `idx_assessment_responses_teacher` (`teacher_id`),
    KEY `idx_assessment_responses_school` (`school_id`),
    KEY `idx_assessment_responses_class` (`class_id`),
    KEY `idx_assessment_responses_academic_year` (`academic_year_id`),
    KEY `idx_assessment_responses_semester` (`semester_id`),
    KEY `idx_assessment_responses_responded_by` (`responded_by`),
    KEY `idx_assessment_responses_date` (`response_date`),
    KEY `idx_assessment_responses_active` (`is_active`),
    CONSTRAINT `fk_assessment_responses_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_assessment_responses_responded_by` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول استجابات التقييم';

-- ============================================================================
-- المرحلة 3: أنواع التقييم (2.2-2.7)
-- Phase 3: Assessment Types
-- ============================================================================

-- -----------------------------------------------------------
-- 2.2 جدول المواد الدراسية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم المادة بالعربية',
    `name_en` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المادة بالإنجليزية',
    `code` VARCHAR(20) NOT NULL COMMENT 'رمز المادة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المادة نشطة',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subjects_code` (`code`),
    KEY `idx_subjects_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواد الدراسية';

-- -----------------------------------------------------------
-- 2.2 جدول المواد بالصفوف الدراسية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subject_grade_years`;
CREATE TABLE `subject_grade_years` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL COMMENT 'رقم المادة',
    `grade_level_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف الدراسي',
    `hours_per_week` TINYINT UNSIGNED DEFAULT NULL COMMENT 'عدد الساعات أسبوعياً',
    `passing_grade` DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'درجة النجاح',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subject_grade_years` (`subject_id`, `grade_level_id`),
    KEY `idx_subject_grade_years_grade` (`grade_level_id`),
    KEY `idx_subject_grade_years_active` (`is_active`),
    CONSTRAINT `fk_subject_grade_years_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_grade_years_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواد بالصفوف الدراسية';

-- -----------------------------------------------------------
-- 2.1.1 جدول معلمي المواد
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subject_teachers`;
CREATE TABLE `subject_teachers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL COMMENT 'رقم المادة',
    `teacher_id` INT UNSIGNED NOT NULL COMMENT 'رقم المعلم',
    `classroom_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم العام الدراسي',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_subject_teachers_teacher` (`teacher_id`),
    KEY `idx_subject_teachers_classroom` (`classroom_id`),
    KEY `idx_subject_teachers_semester` (`semester_id`),
    KEY `idx_subject_teachers_school` (`school_id`),
    KEY `idx_subject_teachers_year` (`academic_year_id`),
    KEY `idx_subject_teachers_active` (`is_active`),
    CONSTRAINT `fk_subject_teachers_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_teachers_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_teachers_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_teachers_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_teachers_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول معلمي المواد - يربط المعلمين بالمواد والصفوف';

-- -----------------------------------------------------------
-- 2.2 جدول أنواع التقييم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_types`;
CREATE TABLE `assessment_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم نوع التقييم بالعربية',
    `name_en` VARCHAR(100) DEFAULT NULL COMMENT 'اسم نوع التقييم بالإنجليزية',
    `code` VARCHAR(20) NOT NULL COMMENT 'رمز نوع التقييم',
    `weight_default` DECIMAL(5,2) DEFAULT NULL COMMENT 'الوزن الافتراضي',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل النوع نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_assessment_types_code` (`code`),
    KEY `idx_assessment_types_sort` (`sort_order`),
    KEY `idx_assessment_types_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول أنواع التقييم';

-- -----------------------------------------------------------
-- 2.2 جدول توزيع أوزان المواد
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subject_weight_distribution`;
CREATE TABLE `subject_weight_distribution` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_grade_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم سجل المادة بالصف',
    `assessment_type_id` INT UNSIGNED NOT NULL COMMENT 'رقم نوع التقييم',
    `weight` DECIMAL(5,2) NOT NULL COMMENT 'الوزن',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_subject_weight_distribution` (`subject_grade_year_id`, `assessment_type_id`, `academic_year_id`),
    KEY `idx_subject_weight_distribution_type` (`assessment_type_id`),
    KEY `idx_subject_weight_distribution_academic_year` (`academic_year_id`),
    KEY `idx_subject_weight_distribution_active` (`is_active`),
    CONSTRAINT `fk_subject_weight_distribution_sgy` FOREIGN KEY (`subject_grade_year_id`) REFERENCES `subject_grade_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_weight_distribution_type` FOREIGN KEY (`assessment_type_id`) REFERENCES `assessment_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subject_weight_distribution_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول توزيع أوزان المواد';

-- -----------------------------------------------------------
-- 2.2 جدول الاختبارات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `tests`;
CREATE TABLE `tests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL COMMENT 'رقم المادة',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `assessment_type_id` INT UNSIGNED NOT NULL COMMENT 'رقم نوع التقييم',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `title_ar` VARCHAR(200) NOT NULL COMMENT 'عنوان الاختبار بالعربية',
    `max_grade` DECIMAL(5,2) NOT NULL COMMENT 'الدرجة القصوى',
    `test_date` DATE DEFAULT NULL COMMENT 'تاريخ الاختبار',
    `status` ENUM('draft','published','locked') NOT NULL DEFAULT 'draft' COMMENT 'حالة الاختبار',
    `created_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم المنشئ',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الاختبار نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_tests_subject` (`subject_id`),
    KEY `idx_tests_class` (`class_id`),
    KEY `idx_tests_type` (`assessment_type_id`),
    KEY `idx_tests_academic_year` (`academic_year_id`),
    KEY `idx_tests_semester` (`semester_id`),
    KEY `idx_tests_status` (`status`),
    KEY `idx_tests_created_by` (`created_by`),
    KEY `idx_tests_date` (`test_date`),
    KEY `idx_tests_active` (`is_active`),
    CONSTRAINT `fk_tests_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tests_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tests_type` FOREIGN KEY (`assessment_type_id`) REFERENCES `assessment_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tests_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tests_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الاختبارات';

-- -----------------------------------------------------------
-- 2.2 جدول الدرجات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `test_id` INT UNSIGNED NOT NULL COMMENT 'رقم الاختبار',
    `grade_value` DECIMAL(7,2) NOT NULL COMMENT 'قيمة الدرجة',
    `max_value` DECIMAL(7,2) NOT NULL COMMENT 'القيمة القصوى',
    `percentage` DECIMAL(7,2) NOT NULL COMMENT 'النسبة المئوية',
    `letter_grade` VARCHAR(3) DEFAULT NULL COMMENT 'التقدير الحرفي',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `graded_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي رصد الدرجة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الدرجة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grades_student_test` (`student_id`, `test_id`),
    KEY `idx_grades_test` (`test_id`),
    KEY `idx_grades_percentage` (`percentage`),
    KEY `idx_grades_letter` (`letter_grade`),
    KEY `idx_grades_graded_by` (`graded_by`),
    KEY `idx_grades_active` (`is_active`),
    CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_grades_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_grades_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الدرجات';

-- -----------------------------------------------------------
-- 2.2 جدول مقياس الدرجات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_scale`;
CREATE TABLE `grade_scale` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `min_percentage` DECIMAL(5,2) NOT NULL COMMENT 'الحد الأدنى للنسبة',
    `max_percentage` DECIMAL(5,2) NOT NULL COMMENT 'الحد الأقصى للنسبة',
    `letter_grade` VARCHAR(3) NOT NULL COMMENT 'التقدير الحرفي',
    `grade_point` DECIMAL(3,2) NOT NULL COMMENT 'نقطة التقدير',
    `description_ar` VARCHAR(100) DEFAULT NULL COMMENT 'الوصف بالعربية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    KEY `idx_grade_scale_letter` (`letter_grade`),
    KEY `idx_grade_scale_percentage` (`min_percentage`, `max_percentage`),
    KEY `idx_grade_scale_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول مقياس الدرجات';

-- -----------------------------------------------------------
-- 2.2 جدول مخرجات التعلم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `learning_outcomes`;
CREATE TABLE `learning_outcomes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL COMMENT 'رقم المادة',
    `code` VARCHAR(20) NOT NULL COMMENT 'رمز مخرج التعلم',
    `description_ar` TEXT NOT NULL COMMENT 'الوصف بالعربية',
    `description_en` TEXT DEFAULT NULL COMMENT 'الوصف بالإنجليزية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل مخرج التعلم نشط',
    PRIMARY KEY (`id`),
    KEY `idx_learning_outcomes_subject` (`subject_id`),
    KEY `idx_learning_outcomes_code` (`code`),
    KEY `idx_learning_outcomes_active` (`is_active`),
    CONSTRAINT `fk_learning_outcomes_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول مخرجات التعلم';

-- -----------------------------------------------------------
-- 2.2 جدول مخرجات التعلم بالاختبارات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `test_outcomes`;
CREATE TABLE `test_outcomes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `test_id` INT UNSIGNED NOT NULL COMMENT 'رقم الاختبار',
    `outcome_id` INT UNSIGNED NOT NULL COMMENT 'رقم مخرج التعلم',
    `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'الوزن',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_test_outcomes` (`test_id`, `outcome_id`),
    KEY `idx_test_outcomes_outcome` (`outcome_id`),
    KEY `idx_test_outcomes_active` (`is_active`),
    CONSTRAINT `fk_test_outcomes_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_test_outcomes_outcome` FOREIGN KEY (`outcome_id`) REFERENCES `learning_outcomes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول مخرجات التعلم بالاختبارات';

-- -----------------------------------------------------------
-- 2.2 جدول ملخص درجات الطالب بالمادة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `student_subject_summary`;
CREATE TABLE `student_subject_summary` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `subject_id` INT UNSIGNED NOT NULL COMMENT 'رقم المادة',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `total_grade` DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'مجموع الدرجات',
    `max_grade` DECIMAL(7,2) NOT NULL DEFAULT 100.00 COMMENT 'الدرجة القصوى',
    `percentage` DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'النسبة المئوية',
    `letter_grade` VARCHAR(3) DEFAULT NULL COMMENT 'التقدير الحرفي',
    `grade_point` DECIMAL(3,2) DEFAULT NULL COMMENT 'نقطة التقدير',
    `rank_in_class` INT UNSIGNED DEFAULT NULL COMMENT 'الترتيب في الفصل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_subject_summary` (`student_id`, `subject_id`, `class_id`, `academic_year_id`, `semester_id`),
    KEY `idx_student_subject_summary_subject` (`subject_id`),
    KEY `idx_student_subject_summary_class` (`class_id`),
    KEY `idx_student_subject_summary_academic_year` (`academic_year_id`),
    KEY `idx_student_subject_summary_semester` (`semester_id`),
    KEY `idx_student_subject_summary_percentage` (`percentage`),
    KEY `idx_student_subject_summary_rank` (`rank_in_class`),
    KEY `idx_student_subject_summary_active` (`is_active`),
    CONSTRAINT `fk_student_subject_summary_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_student_subject_summary_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_subject_summary_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_subject_summary_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_subject_summary_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملخص درجات الطالب بالمادة';

-- -----------------------------------------------------------
-- 2.2 جدول درجات الفصل الدراسي
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `semester_scores`;
CREATE TABLE `semester_scores` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `gpa` DECIMAL(3,2) DEFAULT NULL COMMENT 'المعدل التراكمي',
    `total_percentage` DECIMAL(7,2) DEFAULT NULL COMMENT 'النسبة الإجمالية',
    `rank_in_grade` INT UNSIGNED DEFAULT NULL COMMENT 'الترتيب في الصف',
    `carry_over_subjects` JSON DEFAULT NULL COMMENT 'المواد المحمولة (راسب فيها)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_semester_scores` (`student_id`, `academic_year_id`, `semester_id`),
    KEY `idx_semester_scores_academic_year` (`academic_year_id`),
    KEY `idx_semester_scores_semester` (`semester_id`),
    KEY `idx_semester_scores_gpa` (`gpa`),
    KEY `idx_semester_scores_rank` (`rank_in_grade`),
    KEY `idx_semester_scores_active` (`is_active`),
    CONSTRAINT `fk_semester_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_semester_scores_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_semester_scores_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول درجات الفصل الدراسي';

-- -----------------------------------------------------------
-- 2.2 جدول سجل تغيير الدرجات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_change_log`;
CREATE TABLE `grade_change_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grade_id` INT UNSIGNED NOT NULL COMMENT 'رقم الدرجة',
    `old_value` DECIMAL(7,2) NOT NULL COMMENT 'القيمة القديمة',
    `new_value` DECIMAL(7,2) NOT NULL COMMENT 'القيمة الجديدة',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب التغيير',
    `changed_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي قام بالتغيير',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_grade_change_log_grade` (`grade_id`),
    KEY `idx_grade_change_log_changed_by` (`changed_by`),
    KEY `idx_grade_change_log_created` (`created_at`),
    CONSTRAINT `fk_grade_change_log_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_change_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل تغيير الدرجات';

-- -----------------------------------------------------------
-- 2.7 جدول إعدادات الحضور
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_settings`;
CREATE TABLE `attendance_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `min_attendance_rate` DECIMAL(5,2) NOT NULL DEFAULT 75.00 COMMENT 'الحد الأدنى لنسبة الحضور',
    `late_count_as_absent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل يحسب التأخير كغياب',
    `consecutive_absence_alert` INT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'عدد أيام الغياب المتتالي للتنبيه',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_attendance_settings_school` (`school_id`),
    KEY `idx_attendance_settings_active` (`is_active`),
    CONSTRAINT `fk_attendance_settings_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات الحضور';

-- -----------------------------------------------------------
-- 2.7 جدول الحضور اليومي
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_daily`;
CREATE TABLE `attendance_daily` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `date` DATE NOT NULL COMMENT 'التاريخ',
    `status` ENUM('present','absent','late','excused') NOT NULL COMMENT 'حالة الحضور',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `recorded_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي سجل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_attendance_daily` (`student_id`, `class_id`, `date`),
    KEY `idx_attendance_daily_class` (`class_id`),
    KEY `idx_attendance_daily_academic_year` (`academic_year_id`),
    KEY `idx_attendance_daily_semester` (`semester_id`),
    KEY `idx_attendance_daily_date` (`date`),
    KEY `idx_attendance_daily_status` (`status`),
    KEY `idx_attendance_daily_recorded_by` (`recorded_by`),
    KEY `idx_attendance_daily_active` (`is_active`),
    CONSTRAINT `fk_attendance_daily_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_daily_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_daily_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_daily_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_daily_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الحضور اليومي';

-- -----------------------------------------------------------
-- 2.7 جدول الحضور بالحصص
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_period`;
CREATE TABLE `attendance_period` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `date` DATE NOT NULL COMMENT 'التاريخ',
    `period_number` TINYINT UNSIGNED NOT NULL COMMENT 'رقم الحصة',
    `status` ENUM('present','absent','late','excused') NOT NULL COMMENT 'حالة الحضور',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `recorded_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي سجل',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_attendance_period` (`student_id`, `class_id`, `date`, `period_number`),
    KEY `idx_attendance_period_class` (`class_id`),
    KEY `idx_attendance_period_date` (`date`),
    KEY `idx_attendance_period_status` (`status`),
    KEY `idx_attendance_period_recorded_by` (`recorded_by`),
    KEY `idx_attendance_period_active` (`is_active`),
    CONSTRAINT `fk_attendance_period_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_period_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_period_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الحضور بالحصص (للمدارس الثانوية)';

-- -----------------------------------------------------------
-- 2.7 جدول تعديلات الحضور
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_amendments`;
CREATE TABLE `attendance_amendments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_id` INT UNSIGNED NOT NULL COMMENT 'رقم سجل الحضور',
    `old_status` ENUM('present','absent','late','excused') NOT NULL COMMENT 'الحالة القديمة',
    `new_status` ENUM('present','absent','late','excused') NOT NULL COMMENT 'الحالة الجديدة',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب التعديل',
    `amended_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي عدّل',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_attendance_amendments_attendance` (`attendance_id`),
    KEY `idx_attendance_amendments_amended_by` (`amended_by`),
    KEY `idx_attendance_amendments_created` (`created_at`),
    CONSTRAINT `fk_attendance_amendments_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_daily` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_amendments_amended_by` FOREIGN KEY (`amended_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تعديلات الحضور';

-- -----------------------------------------------------------
-- 2.7 جدول ملخص الحضور
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_summary`;
CREATE TABLE `attendance_summary` (
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
    UNIQUE KEY `uk_attendance_summary` (`student_id`, `class_id`, `academic_year_id`, `semester_id`),
    KEY `idx_attendance_summary_class` (`class_id`),
    KEY `idx_attendance_summary_academic_year` (`academic_year_id`),
    KEY `idx_attendance_summary_semester` (`semester_id`),
    KEY `idx_attendance_summary_rate` (`attendance_rate`),
    KEY `idx_attendance_summary_below_threshold` (`is_below_threshold`),
    KEY `idx_attendance_summary_active` (`is_active`),
    CONSTRAINT `fk_attendance_summary_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_summary_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_summary_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_summary_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملخص الحضور';

-- -----------------------------------------------------------
-- 2.3 جدول فئات السلوك
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_categories`;
CREATE TABLE `behavior_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم الفئة بالعربية',
    `name_en` VARCHAR(100) DEFAULT NULL COMMENT 'اسم الفئة بالإنجليزية',
    `type` ENUM('positive','negative') NOT NULL COMMENT 'نوع الفئة',
    `points` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط',
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المدرسة (فارغ = عام)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الفئة نشطة',
    PRIMARY KEY (`id`),
    KEY `idx_behavior_categories_type` (`type`),
    KEY `idx_behavior_categories_school` (`school_id`),
    KEY `idx_behavior_categories_active` (`is_active`),
    CONSTRAINT `fk_behavior_categories_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فئات السلوك';

-- -----------------------------------------------------------
-- 2.3 جدول السلوكيات المسجلة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_incidents`;
CREATE TABLE `behavior_incidents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `category_id` INT UNSIGNED NOT NULL COMMENT 'رقم فئة السلوك',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `incident_date` DATE NOT NULL COMMENT 'تاريخ الحادثة',
    `description` TEXT DEFAULT NULL COMMENT 'وصف الحادثة',
    `points` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط',
    `reported_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي أبلغ',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_behavior_incidents_student` (`student_id`),
    KEY `idx_behavior_incidents_category` (`category_id`),
    KEY `idx_behavior_incidents_class` (`class_id`),
    KEY `idx_behavior_incidents_academic_year` (`academic_year_id`),
    KEY `idx_behavior_incidents_semester` (`semester_id`),
    KEY `idx_behavior_incidents_date` (`incident_date`),
    KEY `idx_behavior_incidents_reported_by` (`reported_by`),
    KEY `idx_behavior_incidents_active` (`is_active`),
    CONSTRAINT `fk_behavior_incidents_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_incidents_category` FOREIGN KEY (`category_id`) REFERENCES `behavior_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_incidents_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_incidents_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_incidents_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_incidents_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول السلوكيات المسجلة';

-- -----------------------------------------------------------
-- 2.3 جدول درجات السلوك
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_scores`;
CREATE TABLE `behavior_scores` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `starting_points` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'النقاط الابتدائية',
    `earned_points` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط المكتسبة',
    `deducted_points` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط المخصومة',
    `current_percentage` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT 'النسبة الحالية',
    `is_passing` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السلوك مقبول',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_behavior_scores` (`student_id`, `class_id`, `academic_year_id`, `semester_id`),
    KEY `idx_behavior_scores_class` (`class_id`),
    KEY `idx_behavior_scores_academic_year` (`academic_year_id`),
    KEY `idx_behavior_scores_semester` (`semester_id`),
    KEY `idx_behavior_scores_percentage` (`current_percentage`),
    KEY `idx_behavior_scores_passing` (`is_passing`),
    KEY `idx_behavior_scores_active` (`is_active`),
    CONSTRAINT `fk_behavior_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_scores_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_scores_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_scores_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول درجات السلوك';

-- -----------------------------------------------------------
-- 2.3 جدول تقييمات السلوك
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_assessments`;
CREATE TABLE `behavior_assessments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `form_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم نموذج التقييم',
    `overall_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة الكلية',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `evaluated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المقيّم',
    `evaluation_date` DATE NOT NULL COMMENT 'تاريخ التقييم',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل التقييم نشط',
    PRIMARY KEY (`id`),
    KEY `idx_behavior_assessments_student` (`student_id`),
    KEY `idx_behavior_assessments_class` (`class_id`),
    KEY `idx_behavior_assessments_academic_year` (`academic_year_id`),
    KEY `idx_behavior_assessments_semester` (`semester_id`),
    KEY `idx_behavior_assessments_form` (`form_id`),
    KEY `idx_behavior_assessments_evaluated_by` (`evaluated_by`),
    KEY `idx_behavior_assessments_date` (`evaluation_date`),
    KEY `idx_behavior_assessments_active` (`is_active`),
    CONSTRAINT `fk_behavior_assessments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessments_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessments_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessments_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessments_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessments_evaluated_by` FOREIGN KEY (`evaluated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تقييمات السلوك';

-- -----------------------------------------------------------
-- 2.3 جدول عناصر تقييم السلوك
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_assessment_items`;
CREATE TABLE `behavior_assessment_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assessment_id` INT UNSIGNED NOT NULL COMMENT 'رقم تقييم السلوك',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_behavior_assessment_items` (`assessment_id`, `item_id`),
    KEY `idx_behavior_assessment_items_item` (`item_id`),
    KEY `idx_behavior_assessment_items_active` (`is_active`),
    CONSTRAINT `fk_behavior_assessment_items_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `behavior_assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_behavior_assessment_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر تقييم السلوك';

-- -----------------------------------------------------------
-- 2.3 جدول تجاوزات الترقية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `promotion_overrides`;
CREATE TABLE `promotion_overrides` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `criterion` ENUM('academic','behavior','attendance') NOT NULL COMMENT 'معيار التجاوز',
    `override_reason` TEXT NOT NULL COMMENT 'سبب التجاوز',
    `approved_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي وافق',
    `approved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الموافقة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    KEY `idx_promotion_overrides_student` (`student_id`),
    KEY `idx_promotion_overrides_academic_year` (`academic_year_id`),
    KEY `idx_promotion_overrides_semester` (`semester_id`),
    KEY `idx_promotion_overrides_criterion` (`criterion`),
    KEY `idx_promotion_overrides_approved_by` (`approved_by`),
    KEY `idx_promotion_overrides_active` (`is_active`),
    CONSTRAINT `fk_promotion_overrides_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_overrides_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_overrides_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_overrides_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تجاوزات الترقية';

-- -----------------------------------------------------------
-- 2.4 جدول توفر المهارات بالمدرسة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_skills_availability`;
CREATE TABLE `school_skills_availability` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `grade_level_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصف الدراسي',
    `skill_name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم المهارة بالعربية',
    `skill_name_en` VARCHAR(200) DEFAULT NULL COMMENT 'اسم المهارة بالإنجليزية',
    `is_available` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المهارة متوفرة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    KEY `idx_school_skills_availability_school` (`school_id`),
    KEY `idx_school_skills_availability_grade` (`grade_level_id`),
    KEY `idx_school_skills_availability_available` (`is_available`),
    KEY `idx_school_skills_availability_active` (`is_active`),
    CONSTRAINT `fk_school_skills_availability_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_skills_availability_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول توفر المهارات بالمدرسة';

-- -----------------------------------------------------------
-- 2.5 جدول ملخص تقييم التدريس
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `teaching_evaluation_summary`;
CREATE TABLE `teaching_evaluation_summary` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL COMMENT 'رقم المعلم',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `self_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة التقييم الذاتي',
    `peer_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة تقييم الأقران',
    `admin_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة تقييم الإدارة',
    `combined_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة المجمعة (ذاتي*0.2+أقران*0.3+إدارة*0.5)',
    `adjusted_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة بعد التعديل السياقي',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_teaching_evaluation_summary` (`teacher_id`, `academic_year_id`, `semester_id`),
    KEY `idx_teaching_evaluation_summary_academic_year` (`academic_year_id`),
    KEY `idx_teaching_evaluation_summary_semester` (`semester_id`),
    KEY `idx_teaching_evaluation_summary_combined` (`combined_score`),
    KEY `idx_teaching_evaluation_summary_active` (`is_active`),
    CONSTRAINT `fk_teaching_evaluation_summary_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_teaching_evaluation_summary_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_teaching_evaluation_summary_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملخص تقييم التدريس';

-- -----------------------------------------------------------
-- 2.5 جدول خطط تطوير المعلمين
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `teacher_development_plans`;
CREATE TABLE `teacher_development_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL COMMENT 'رقم المعلم',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `weaknesses_ar` TEXT DEFAULT NULL COMMENT 'نقاط الضعف بالعربية',
    `goals_ar` TEXT DEFAULT NULL COMMENT 'الأهداف بالعربية',
    `strategies_ar` TEXT DEFAULT NULL COMMENT 'الاستراتيجيات بالعربية',
    `timeline` VARCHAR(200) DEFAULT NULL COMMENT 'الجدول الزمني',
    `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active' COMMENT 'حالة الخطة',
    `created_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم المنشئ',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الخطة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    KEY `idx_teacher_development_plans_teacher` (`teacher_id`),
    KEY `idx_teacher_development_plans_academic_year` (`academic_year_id`),
    KEY `idx_teacher_development_plans_semester` (`semester_id`),
    KEY `idx_teacher_development_plans_status` (`status`),
    KEY `idx_teacher_development_plans_created_by` (`created_by`),
    KEY `idx_teacher_development_plans_active` (`is_active`),
    CONSTRAINT `fk_teacher_development_plans_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_teacher_development_plans_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_teacher_development_plans_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_teacher_development_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول خطط تطوير المعلمين';

-- -----------------------------------------------------------
-- 2.6 جدول استبيانات المناخ المدرسي
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `climate_surveys`;
CREATE TABLE `climate_surveys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `respondent_type` ENUM('teacher','student','parent','admin') NOT NULL COMMENT 'نوع المستجيب',
    `respondent_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستجيب',
    `survey_date` DATE NOT NULL COMMENT 'تاريخ الاستبيان',
    `overall_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة الكلية',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الاستبيان نشط',
    PRIMARY KEY (`id`),
    KEY `idx_climate_surveys_school` (`school_id`),
    KEY `idx_climate_surveys_academic_year` (`academic_year_id`),
    KEY `idx_climate_surveys_semester` (`semester_id`),
    KEY `idx_climate_surveys_respondent_type` (`respondent_type`),
    KEY `idx_climate_surveys_respondent` (`respondent_id`),
    KEY `idx_climate_surveys_date` (`survey_date`),
    KEY `idx_climate_surveys_active` (`is_active`),
    CONSTRAINT `fk_climate_surveys_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_climate_surveys_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_climate_surveys_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_climate_surveys_respondent` FOREIGN KEY (`respondent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول استبيانات المناخ المدرسي';

-- -----------------------------------------------------------
-- 2.6 جدول عناصر استبيان المناخ
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `climate_survey_items`;
CREATE TABLE `climate_survey_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id` INT UNSIGNED NOT NULL COMMENT 'رقم الاستبيان',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_climate_survey_items` (`survey_id`, `item_id`),
    KEY `idx_climate_survey_items_item` (`item_id`),
    KEY `idx_climate_survey_items_active` (`is_active`),
    CONSTRAINT `fk_climate_survey_items_survey` FOREIGN KEY (`survey_id`) REFERENCES `climate_surveys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_climate_survey_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر استبيان المناخ';

-- -----------------------------------------------------------
-- 2.6 جدول قوائم فحص المرافق
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `facility_checklists`;
CREATE TABLE `facility_checklists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `evaluator_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المقيّم',
    `evaluation_date` DATE NOT NULL COMMENT 'تاريخ التقييم',
    `overall_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة الكلية',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل القائمة نشطة',
    PRIMARY KEY (`id`),
    KEY `idx_facility_checklists_school` (`school_id`),
    KEY `idx_facility_checklists_academic_year` (`academic_year_id`),
    KEY `idx_facility_checklists_semester` (`semester_id`),
    KEY `idx_facility_checklists_evaluator` (`evaluator_id`),
    KEY `idx_facility_checklists_date` (`evaluation_date`),
    KEY `idx_facility_checklists_active` (`is_active`),
    CONSTRAINT `fk_facility_checklists_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_facility_checklists_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_facility_checklists_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_facility_checklists_evaluator` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول قوائم فحص المرافق';

-- -----------------------------------------------------------
-- 2.6 جدول عناصر قائمة فحص المرافق
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `facility_checklist_items`;
CREATE TABLE `facility_checklist_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `checklist_id` INT UNSIGNED NOT NULL COMMENT 'رقم قائمة الفحص',
    `item_id` INT UNSIGNED NOT NULL COMMENT 'رقم عنصر التقييم',
    `numeric_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
    `normalized_value` DECIMAL(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_facility_checklist_items` (`checklist_id`, `item_id`),
    KEY `idx_facility_checklist_items_item` (`item_id`),
    KEY `idx_facility_checklist_items_active` (`is_active`),
    CONSTRAINT `fk_facility_checklist_items_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `facility_checklists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_facility_checklist_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر قائمة فحص المرافق';

-- ============================================================================
-- المرحلة 4: المؤشرات والقرارات (3.1-3.3)
-- Phase 4: Indicators & Decisions
-- ============================================================================

-- -----------------------------------------------------------
-- 3.1 جدول حسابات مؤشر جودة التعليم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `eqi_calculations`;
CREATE TABLE `eqi_calculations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `academic_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة البعد الأكاديمي',
    `teaching_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة بعد التدريس',
    `climate_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة بعد المناخ',
    `behavior_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة بعد السلوك',
    `skills_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة بعد المهارات',
    `attendance_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة بعد الحضور',
    `academic_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن البعد الأكاديمي',
    `teaching_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن بعد التدريس',
    `climate_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن بعد المناخ',
    `behavior_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن بعد السلوك',
    `skills_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن بعد المهارات',
    `attendance_weight` DECIMAL(5,4) DEFAULT NULL COMMENT 'وزن بعد الحضور',
    `overall_eqi` DECIMAL(5,2) DEFAULT NULL COMMENT 'مؤشر جودة التعليم الكلي',
    `missing_dimensions` JSON DEFAULT NULL COMMENT 'الأبعاد المفقودة',
    `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الحساب',
    `calculated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي حسب',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_eqi_calculations` (`school_id`, `academic_year_id`, `semester_id`),
    KEY `idx_eqi_calculations_academic_year` (`academic_year_id`),
    KEY `idx_eqi_calculations_semester` (`semester_id`),
    KEY `idx_eqi_calculations_overall` (`overall_eqi`),
    KEY `idx_eqi_calculations_calculated_by` (`calculated_by`),
    KEY `idx_eqi_calculations_active` (`is_active`),
    CONSTRAINT `fk_eqi_calculations_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_calculations_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_calculations_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_calculations_calculated_by` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول حسابات مؤشر جودة التعليم';

-- -----------------------------------------------------------
-- 3.1 جدول أوزان أبعاد مؤشر جودة التعليم
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `eqi_dimension_weights`;
CREATE TABLE `eqi_dimension_weights` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dimension_name` VARCHAR(50) NOT NULL COMMENT 'اسم البعد بالإنجليزية',
    `dimension_name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم البعد بالعربية',
    `default_weight` DECIMAL(7,4) NOT NULL COMMENT 'الوزن الافتراضي (0-100)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_eqi_dimension_weights_name` (`dimension_name`),
    KEY `idx_eqi_dimension_weights_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول أوزان أبعاد مؤشر جودة التعليم';

-- -----------------------------------------------------------
-- 3.2 جدول التنبيهات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `alerts`;
CREATE TABLE `alerts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `student_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الطالب',
    `alert_type` VARCHAR(50) NOT NULL COMMENT 'نوع التنبيه',
    `severity` ENUM('info','warning','danger','critical') NOT NULL DEFAULT 'warning' COMMENT 'مستوى الخطورة',
    `title_ar` VARCHAR(200) NOT NULL COMMENT 'عنوان التنبيه بالعربية',
    `message_ar` TEXT NOT NULL COMMENT 'نص التنبيه بالعربية',
    `notes` TEXT NULL COMMENT 'ملاحظات الحل',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل تمت القراءة',
    `read_at` DATETIME DEFAULT NULL COMMENT 'تاريخ القراءة',
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل تم الحل',
    `resolved_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي حل',
    `resolved_at` DATETIME DEFAULT NULL COMMENT 'تاريخ الحل',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_alerts_school` (`school_id`),
    KEY `idx_alerts_student` (`student_id`),
    KEY `idx_alerts_type` (`alert_type`),
    KEY `idx_alerts_severity` (`severity`),
    KEY `idx_alerts_read` (`is_read`),
    KEY `idx_alerts_resolved` (`is_resolved`),
    KEY `idx_alerts_resolved_by` (`resolved_by`),
    KEY `idx_alerts_created` (`created_at`),
    CONSTRAINT `fk_alerts_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_alerts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول التنبيهات';

-- -----------------------------------------------------------
-- 3.2 جدول إعدادات التنبيهات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `alert_settings`;
CREATE TABLE `alert_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `alert_type` ENUM('academic','behavior','attendance','eqi','promotion','climate','teaching') NOT NULL COMMENT 'نوع التنبيه',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل التنبيه مفعّل',
    `threshold_value` DECIMAL(7,2) DEFAULT NULL COMMENT 'قيمة الحد',
    `severity` ENUM('info','warning','danger','critical') NOT NULL DEFAULT 'warning' COMMENT 'مستوى الخطورة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_alert_settings` (`school_id`, `alert_type`),
    KEY `idx_alert_settings_type` (`alert_type`),
    KEY `idx_alert_settings_enabled` (`is_enabled`),
    KEY `idx_alert_settings_active` (`is_active`),
    CONSTRAINT `fk_alert_settings_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات التنبيهات';

-- -----------------------------------------------------------
-- 3.3 جدول قرارات الترقية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `promotion_decisions`;
CREATE TABLE `promotion_decisions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `academic_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة الأداء الأكاديمي',
    `behavior_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة السلوك',
    `attendance_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة الحضور',
    `academic_pass` TINYINT(1) DEFAULT NULL COMMENT 'هل اجتاز أكاديمياً',
    `behavior_pass` TINYINT(1) DEFAULT NULL COMMENT 'هل اجتاز سلوكياً',
    `attendance_pass` TINYINT(1) DEFAULT NULL COMMENT 'هل اجتاز حضورياً',
    `decision` ENUM('promoted','repeated','conditional','under_review') NOT NULL COMMENT 'القرار',
    `status` ENUM('proposed','approved','rejected') NOT NULL DEFAULT 'proposed' COMMENT 'حالة القرار',
    `approved_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي وافق',
    `approved_at` DATETIME DEFAULT NULL COMMENT 'تاريخ الموافقة',
    `rejection_reason` TEXT DEFAULT NULL COMMENT 'سبب الرفض',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `decided_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي اتخذ القرار',
    `decided_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ القرار',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل القرار نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_promotion_decisions` (`student_id`, `academic_year_id`, `semester_id`),
    KEY `idx_promotion_decisions_academic_year` (`academic_year_id`),
    KEY `idx_promotion_decisions_semester` (`semester_id`),
    KEY `idx_promotion_decisions_decision` (`decision`),
    KEY `idx_promotion_decisions_status` (`status`),
    KEY `idx_promotion_decisions_approved_by` (`approved_by`),
    KEY `idx_promotion_decisions_decided_by` (`decided_by`),
    KEY `idx_promotion_decisions_active` (`is_active`),
    CONSTRAINT `fk_promotion_decisions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_decisions_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_decisions_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_decisions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_decisions_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول قرارات الترقية';

-- -----------------------------------------------------------
-- 3.3 جدول سجل تغيير قرارات الترقية
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `promotion_change_log`;
CREATE TABLE `promotion_change_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `decision_id` INT UNSIGNED NOT NULL COMMENT 'رقم قرار الترقية',
    `old_decision` ENUM('promoted','repeated','conditional','under_review') NOT NULL COMMENT 'القرار القديم',
    `new_decision` ENUM('promoted','repeated','conditional','under_review') NOT NULL COMMENT 'القرار الجديد',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب التغيير',
    `changed_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم الذي قام بالتغيير',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_promotion_change_log_decision` (`decision_id`),
    KEY `idx_promotion_change_log_changed_by` (`changed_by`),
    KEY `idx_promotion_change_log_created` (`created_at`),
    CONSTRAINT `fk_promotion_change_log_decision` FOREIGN KEY (`decision_id`) REFERENCES `promotion_decisions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_promotion_change_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل تغيير قرارات الترقية';

-- ============================================================================
-- المرحلة 5: المخرجات (4.1-4.4)
-- Phase 5: Outputs
-- ============================================================================

-- -----------------------------------------------------------
-- 4.1 جدول تفضيلات لوحة المعلومات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `dashboard_preferences`;
CREATE TABLE `dashboard_preferences` (
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
    KEY `idx_dashboard_preferences_user` (`user_id`),
    KEY `idx_dashboard_preferences_widget` (`widget_type`),
    KEY `idx_dashboard_preferences_position` (`position`),
    KEY `idx_dashboard_preferences_visible` (`is_visible`),
    KEY `idx_dashboard_preferences_active` (`is_active`),
    CONSTRAINT `fk_dashboard_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تفضيلات لوحة المعلومات';

-- -----------------------------------------------------------
-- 4.2 جدول لقطات كشوف الدرجات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_sheet_snapshots`;
CREATE TABLE `grade_sheet_snapshots` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `snapshot_data` JSON NOT NULL COMMENT 'بيانات اللقطة',
    `generated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي أنتج',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل اللقطة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_grade_sheet_snapshots_class` (`class_id`),
    KEY `idx_grade_sheet_snapshots_academic_year` (`academic_year_id`),
    KEY `idx_grade_sheet_snapshots_semester` (`semester_id`),
    KEY `idx_grade_sheet_snapshots_generated_by` (`generated_by`),
    KEY `idx_grade_sheet_snapshots_active` (`is_active`),
    CONSTRAINT `fk_grade_sheet_snapshots_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_sheet_snapshots_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_sheet_snapshots_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_grade_sheet_snapshots_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول لقطات كشوف الدرجات';

-- -----------------------------------------------------------
-- 4.3 جدول ملاحظات الطلاب
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `student_notes`;
CREATE TABLE `student_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `class_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'رقم السنة الدراسية',
    `semester_id` INT UNSIGNED NOT NULL COMMENT 'رقم الفصل الدراسي',
    `note_type` ENUM('general','academic','behavioral','attendance') NOT NULL DEFAULT 'general' COMMENT 'نوع الملاحظة',
    `title_ar` VARCHAR(200) NOT NULL COMMENT 'عنوان الملاحظة بالعربية',
    `content_ar` TEXT NOT NULL COMMENT 'محتوى الملاحظة بالعربية',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium' COMMENT 'الأولوية',
    `created_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم المنشئ',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الملاحظة نشطة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    KEY `idx_student_notes_student` (`student_id`),
    KEY `idx_student_notes_class` (`class_id`),
    KEY `idx_student_notes_academic_year` (`academic_year_id`),
    KEY `idx_student_notes_semester` (`semester_id`),
    KEY `idx_student_notes_type` (`note_type`),
    KEY `idx_student_notes_priority` (`priority`),
    KEY `idx_student_notes_created_by` (`created_by`),
    KEY `idx_student_notes_active` (`is_active`),
    CONSTRAINT `fk_student_notes_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_student_notes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_notes_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_notes_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_student_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملاحظات الطلاب';

-- -----------------------------------------------------------
-- 4.4 جدول سجل التصدير
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `export_log`;
CREATE TABLE `export_log` (
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
    KEY `idx_export_log_user` (`user_id`),
    KEY `idx_export_log_type` (`export_type`),
    KEY `idx_export_log_created` (`created_at`),
    KEY `idx_export_log_active` (`is_active`),
    CONSTRAINT `fk_export_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل التصدير';

-- ============================================================================
-- المرحلة 6: التكامل والنظام (5.1-5.3) + جداول النظام
-- Phase 6: Integration & System Tables
-- ============================================================================

-- -----------------------------------------------------------
-- 5.1 جدول الإشعارات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم',
    `type` VARCHAR(50) NOT NULL COMMENT 'نوع الإشعار',
    `title_ar` VARCHAR(200) NOT NULL COMMENT 'عنوان الإشعار بالعربية',
    `message_ar` TEXT NOT NULL COMMENT 'نص الإشعار بالعربية',
    `related_entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'نوع الكيان المرتبط',
    `related_entity_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الكيان المرتبط',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل تمت القراءة',
    `read_at` DATETIME DEFAULT NULL COMMENT 'تاريخ القراءة',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user` (`user_id`),
    KEY `idx_notifications_type` (`type`),
    KEY `idx_notifications_read` (`is_read`),
    KEY `idx_notifications_entity` (`related_entity_type`, `related_entity_id`),
    KEY `idx_notifications_created` (`created_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الإشعارات';

-- -----------------------------------------------------------
-- 5.1 جدول تفضيلات الإشعارات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `notification_preferences`;
CREATE TABLE `notification_preferences` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم',
    `notification_type` VARCHAR(50) NOT NULL COMMENT 'نوع الإشعار',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإشعار مفعّل',
    `value_string` VARCHAR(255) DEFAULT NULL COMMENT 'قيمة نصية (للساعات والتكرار)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_preferences` (`user_id`, `notification_type`),
    KEY `idx_notification_preferences_type` (`notification_type`),
    KEY `idx_notification_preferences_enabled` (`is_enabled`),
    KEY `idx_notification_preferences_active` (`is_active`),
    CONSTRAINT `fk_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تفضيلات الإشعارات';

-- -----------------------------------------------------------
-- 5.2 جدول إقرارات أولياء الأمور
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `parent_acknowledgments`;
CREATE TABLE `parent_acknowledgments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NOT NULL COMMENT 'رقم ولي الأمر (مستخدم)',
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `ack_type` ENUM('grade','behavior','attendance','promotion') NOT NULL COMMENT 'نوع الإقرار',
    `entity_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الكيان المرتبط',
    `is_acknowledged` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل تم الإقرار',
    `acknowledged_at` DATETIME DEFAULT NULL COMMENT 'تاريخ الإقرار',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_parent_acknowledgments_parent` (`parent_id`),
    KEY `idx_parent_acknowledgments_student` (`student_id`),
    KEY `idx_parent_acknowledgments_type` (`ack_type`),
    KEY `idx_parent_acknowledgments_acknowledged` (`is_acknowledged`),
    KEY `idx_parent_acknowledgments_active` (`is_active`),
    CONSTRAINT `fk_parent_acknowledgments_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_parent_acknowledgments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إقرارات أولياء الأمور';

-- -----------------------------------------------------------
-- 5.3 جدول إعدادات النظام
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category` VARCHAR(50) NOT NULL COMMENT 'فئة الإعداد',
    `setting_key` VARCHAR(100) NOT NULL COMMENT 'مفتاح الإعداد',
    `setting_value` TEXT DEFAULT NULL COMMENT 'قيمة الإعداد',
    `value_type` ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string' COMMENT 'نوع القيمة',
    `name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم الإعداد بالعربية',
    `description_ar` TEXT DEFAULT NULL COMMENT 'وصف الإعداد بالعربية',
    `is_system` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل إعداد نظام',
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المدرسة (للتجاوزات)',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_system_settings` (`category`, `setting_key`, `school_id`),
    KEY `idx_system_settings_category` (`category`),
    KEY `idx_system_settings_key` (`setting_key`),
    KEY `idx_system_settings_school` (`school_id`),
    KEY `idx_system_settings_sort` (`sort_order`),
    KEY `idx_system_settings_active` (`is_active`),
    CONSTRAINT `fk_system_settings_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات النظام';

-- -----------------------------------------------------------
-- 5.3 جدول إعدادات المدرسة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_settings`;
CREATE TABLE `school_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `setting_id` INT UNSIGNED NOT NULL COMMENT 'رقم الإعداد',
    `override_value` TEXT DEFAULT NULL COMMENT 'قيمة التجاوز',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
    `updated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي حدّث',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_school_settings` (`school_id`, `setting_id`),
    KEY `idx_school_settings_setting` (`setting_id`),
    KEY `idx_school_settings_active` (`is_active`),
    KEY `idx_school_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_school_settings_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_settings_setting` FOREIGN KEY (`setting_id`) REFERENCES `system_settings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات المدرسة';

-- -----------------------------------------------------------
-- جدول سجل النشاط
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم',
    `action` VARCHAR(100) NOT NULL COMMENT 'الإجراء',
    `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'نوع الكيان',
    `entity_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم الكيان',
    `description` TEXT DEFAULT NULL COMMENT 'الوصف',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'معلومات المتصفح',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    KEY `idx_activity_logs_user` (`user_id`),
    KEY `idx_activity_logs_action` (`action`),
    KEY `idx_activity_logs_entity` (`entity_type`, `entity_id`),
    KEY `idx_activity_logs_created` (`created_at`),
    CONSTRAINT `fk_activity_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل النشاط';

-- -----------------------------------------------------------
-- جدول سجل الأخطاء المكررة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `error_log_dedup`;
CREATE TABLE `error_log_dedup` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `error_hash` VARCHAR(32) NOT NULL COMMENT 'هاش الخطأ',
    `error_message` TEXT NOT NULL COMMENT 'رسالة الخطأ',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار الملف',
    `line_number` INT UNSIGNED DEFAULT NULL COMMENT 'رقم السطر',
    `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'عدد التكرارات',
    `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'أول ظهور',
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'آخر ظهور',
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل تم الحل',
    `resolved_at` DATETIME DEFAULT NULL COMMENT 'تاريخ الحل',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_error_log_dedup_hash` (`error_hash`),
    KEY `idx_error_log_dedup_file` (`file_path`),
    KEY `idx_error_log_dedup_resolved` (`is_resolved`),
    KEY `idx_error_log_dedup_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل الأخطاء المكررة';

-- -----------------------------------------------------------
-- جدول سجل النسخ الاحتياطي
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `backup_logs`;
CREATE TABLE `backup_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المدرسة',
    `backup_type` ENUM('full','pre_operation','manual','pre_restore') NOT NULL COMMENT 'نوع النسخة',
    `file_path` VARCHAR(500) NOT NULL COMMENT 'مسار الملف',
    `file_size` BIGINT UNSIGNED DEFAULT NULL COMMENT 'حجم الملف بالبايت',
    `tables_count` INT UNSIGNED DEFAULT NULL COMMENT 'عدد الجداول',
    `rows_count` INT UNSIGNED DEFAULT NULL COMMENT 'عدد الصفوف',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم المنشئ',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
    PRIMARY KEY (`id`),
    KEY `idx_backup_logs_school` (`school_id`),
    KEY `idx_backup_logs_type` (`backup_type`),
    KEY `idx_backup_logs_created_by` (`created_by`),
    KEY `idx_backup_logs_created` (`created_at`),
    KEY `idx_backup_logs_active` (`is_active`),
    CONSTRAINT `fk_backup_logs_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_backup_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل النسخ الاحتياطي';

-- -----------------------------------------------------------
-- جدول خطط التحسين (للطلاب المشروطين)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `improvement_plans`;
CREATE TABLE `improvement_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL COMMENT 'رقم الطالب',
    `decision_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم قرار الترقية',
    `weaknesses` TEXT DEFAULT NULL COMMENT 'نقاط الضعف',
    `goals` TEXT DEFAULT NULL COMMENT 'الأهداف',
    `strategies` TEXT DEFAULT NULL COMMENT 'الاستراتيجيات',
    `timeline` TEXT DEFAULT NULL COMMENT 'الجدول الزمني',
    `responsible_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم المسؤول (معلم/مرشد)',
    `parent_involvement` TEXT DEFAULT NULL COMMENT 'مشاركة ولي الأمر',
    `follow_up_date` DATE DEFAULT NULL COMMENT 'تاريخ المتابعة',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active' COMMENT 'حالة الخطة',
    `created_by` INT UNSIGNED NOT NULL COMMENT 'رقم المستخدم المنشئ',
    `updated_by` INT UNSIGNED DEFAULT NULL COMMENT 'رقم المستخدم الذي حدّث',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الخطة نشطة',
    PRIMARY KEY (`id`),
    KEY `idx_improvement_plans_student` (`student_id`),
    KEY `idx_improvement_plans_decision` (`decision_id`),
    KEY `idx_improvement_plans_responsible` (`responsible_user_id`),
    KEY `idx_improvement_plans_status` (`status`),
    KEY `idx_improvement_plans_created_by` (`created_by`),
    KEY `idx_improvement_plans_updated_by` (`updated_by`),
    KEY `idx_improvement_plans_follow_up` (`follow_up_date`),
    KEY `idx_improvement_plans_active` (`is_active`),
    CONSTRAINT `fk_improvement_plans_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_improvement_plans_decision` FOREIGN KEY (`decision_id`) REFERENCES `promotion_decisions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_improvement_plans_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_improvement_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_improvement_plans_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول خطط التحسين للطلاب';

-- -----------------------------------------------------------
-- جدول خطط تحسين المدرسة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_improvement_plans`;
CREATE TABLE `school_improvement_plans` (
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

-- ============================================================================
-- العرض: بيانات الطلاب الحالية
-- View: Student Current Data
-- ============================================================================

DROP VIEW IF EXISTS `v_student_current`;
CREATE OR REPLACE VIEW `v_student_current` AS
SELECT
    s.id AS student_id,
    s.student_number,
    s.full_name_ar,
    s.full_name_en,
    s.guardian_name,
    s.guardian_phone,
    s.grade_level_id,
    s.school_id,
    gy.id AS grade_year_id,
    gy.status AS enrollment_status,
    se.class_id,
    se.academic_year_id,
    c.name_ar AS class_name,
    gl.name_ar AS grade_name,
    gl.stage,
    sc.name_ar AS school_name,
    ay.name AS academic_year_name
FROM students s
LEFT JOIN grade_years gy ON s.id = gy.student_id AND gy.is_active = 1
LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
LEFT JOIN classes c ON se.class_id = c.id
LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
LEFT JOIN schools sc ON s.school_id = sc.id
LEFT JOIN academic_years ay ON se.academic_year_id = ay.id
WHERE s.is_active = 1;

-- -----------------------------------------------------------
-- جدول المحافظات ة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `governorates`;
CREATE TABLE `governorates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar` VARCHAR(100) NOT NULL COMMENT 'اسم المحافظة بالعربية',
    `name_en` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المحافظة بالإنجليزية',
    `code` VARCHAR(5) NOT NULL COMMENT 'رمز المحافظة',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل المحافظة نشطة',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_governorates_code` (`code`),
    KEY `idx_governorates_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المحافظات ة';

-- -----------------------------------------------------------
-- جدول الصلاحيات
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL COMMENT 'اسم الصلاحية بالإنجليزية',
    `name_ar` VARCHAR(200) NOT NULL COMMENT 'اسم الصلاحية بالعربية',
    `module` VARCHAR(50) NOT NULL COMMENT 'الوحدة البرمجية',
    `description` TEXT DEFAULT NULL COMMENT 'وصف الصلاحية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الصلاحية نشطة',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_permissions_name` (`name`),
    KEY `idx_permissions_module` (`module`),
    KEY `idx_permissions_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الصلاحيات';

-- -----------------------------------------------------------
-- جدول صلاحيات الأدوار
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` INT UNSIGNED NOT NULL COMMENT 'رقم الدور',
    `permission_id` INT UNSIGNED NOT NULL COMMENT 'رقم الصلاحية',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل الربط نشط',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_role_permissions` (`role_id`, `permission_id`),
    KEY `idx_role_permissions_permission` (`permission_id`),
    KEY `idx_role_permissions_active` (`is_active`),
    CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول صلاحيات الأدوار';

-- -----------------------------------------------------------
-- جدول السياق المدرسي (معامل التعديل السياقي)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_context`;
CREATE TABLE `school_context` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL COMMENT 'رقم المدرسة',
    `academic_year_id` INT UNSIGNED NOT NULL COMMENT 'العام الدراسي',
    `location_type` ENUM('urban','rural','suburban') DEFAULT NULL COMMENT 'نوع المنطقة',
    `total_students` INT DEFAULT NULL COMMENT 'إجمالي الطلاب',
    `student_teacher_ratio` DECIMAL(5,2) DEFAULT NULL COMMENT 'نسبة الطلاب إلى المعلمين',
    `socioeconomic_index` DECIMAL(5,2) DEFAULT NULL COMMENT 'المستوى الاقتصادي الاجتماعي (0-100)',
    `parent_education_level` DECIMAL(5,2) DEFAULT NULL COMMENT 'مستوى تعليم أولياء الأمور (0-100)',
    `students_with_special_needs_pct` DECIMAL(5,2) DEFAULT NULL COMMENT 'نسبة الطلاب ذوي الاحتياجات الخاصة',
    `infrastructure_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'درجة البنية التحتية (0-100)',
    `student_mobility_rate` DECIMAL(5,2) DEFAULT NULL COMMENT 'معدل تنقل الطلاب (%)',
    `challenge_index` DECIMAL(5,2) DEFAULT NULL COMMENT 'مؤشر التحدي السياقي المحسوب',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_school_context` (`school_id`, `academic_year_id`),
    KEY `idx_school_context_school` (`school_id`),
    KEY `idx_school_context_year` (`academic_year_id`),
    KEY `idx_school_context_active` (`is_active`),
    CONSTRAINT `fk_school_context_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_school_context_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='السياق المدرسي لمعامل التعديل السياقي';

-- ============================================================================
-- إعادة تفعيل فحص المفاتيح الأجنبية
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
