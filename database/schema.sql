-- ============================================================================
-- نظام التقييم الشامل لجودة التعليم المدرسي - EQAS
-- مخطط قاعدة البيانات النهائي
-- الإصدار: 3.3.0
-- تاريخ التحديث: 2026-05-16
-- إجمالي الجداول: 42 جدول + 1 عرض
--============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- Table: academic_years
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'اسم السنة الدراسية',
  `start_date` date NOT NULL COMMENT 'تاريخ البداية',
  `end_date` date NOT NULL COMMENT 'تاريخ النهاية',
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل هي السنة الحالية',
  `status` enum('planning','active','evaluation','closed') NOT NULL DEFAULT 'planning' COMMENT 'حالة السنة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السنة نشطة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  KEY `idx_academic_years_current` (`is_current`),
  KEY `idx_academic_years_status` (`status`),
  KEY `idx_academic_years_dates` (`start_date`,`end_date`),
  KEY `idx_academic_years_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول السنوات الدراسية';

-- -----------------------------------------------------------
-- Table: activity_logs
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `status` enum('completed','failed','pending') NOT NULL DEFAULT 'completed',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details_json`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_logs_user_action` (`user_id`,`action`),
  KEY `idx_act_logs_entity` (`entity_type`,`entity_id`),
  KEY `idx_act_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: alerts
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `alerts`;
CREATE TABLE `alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `alert_type` enum('academic','behavior','attendance','eqi','promotion','climate','teaching','system','reminder') NOT NULL,
  `severity` enum('info','warning','danger','critical') NOT NULL DEFAULT 'warning',
  `title_ar` varchar(200) NOT NULL,
  `message_ar` text NOT NULL,
  `threshold_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`threshold_json`)),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alerts_school` (`school_id`),
  KEY `idx_alerts_type_severity` (`alert_type`,`severity`),
  KEY `idx_alerts_read_resolved` (`is_read`,`is_resolved`),
  KEY `fk_alerts_student` (`student_id`),
  KEY `fk_alerts_user` (`user_id`),
  KEY `fk_alerts_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alerts_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alerts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: assessment_forms
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_forms`;
CREATE TABLE `assessment_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_key` enum('behavior_eval','skills_checklist','teaching_self','teaching_peer','teaching_admin','climate_survey','facility_checklist') NOT NULL COMMENT 'مفتاح النموذج',
  `name_ar` varchar(200) NOT NULL COMMENT 'اسم النموذج بالعربية',
  `name_en` varchar(200) DEFAULT NULL COMMENT 'اسم النموذج بالإنجليزية',
  `category` enum('behavior','skills','teaching','climate') NOT NULL COMMENT 'فئة النموذج',
  `target` enum('student','teacher','school') NOT NULL COMMENT 'هدف التقييم',
  `description` text DEFAULT NULL COMMENT 'وصف النموذج',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل نموذج نظام',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'استمارة افتراضية',
  `version` varchar(50) DEFAULT NULL COMMENT 'رقم الإصدار/الوصف',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل النموذج نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  KEY `idx_assessment_forms_key` (`form_key`),
  KEY `idx_assessment_forms_category` (`category`),
  KEY `idx_assessment_forms_target` (`target`),
  KEY `idx_assessment_forms_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول نماذج التقييم';

-- -----------------------------------------------------------
-- Table: assessment_responses
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_responses`;
CREATE TABLE `assessment_responses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL COMMENT 'رقم النموذج',
  `item_id` int(10) unsigned NOT NULL COMMENT 'رقم العنصر',
  `student_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم الطالب',
  `teacher_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المعلم',
  `school_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المدرسة',
  `class_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `numeric_value` decimal(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
  `text_value` text DEFAULT NULL COMMENT 'القيمة النصية',
  `responded_by` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستجيب',
  `response_date` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الاستجابة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الاستجابة نشطة',
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
  CONSTRAINT `fk_assessment_responses_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_responded_by` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assessment_responses_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول استجابات التقييم';

-- -----------------------------------------------------------
-- Table: assessment_structure
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_structure`;
CREATE TABLE `assessment_structure` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL=معيار, !=NULL=عنصر',
  `type` enum('criteria','item') NOT NULL,
  `name_ar` varchar(200) NOT NULL,
  `name_en` varchar(200) DEFAULT NULL,
  `text_ar` text DEFAULT NULL,
  `text_en` text DEFAULT NULL,
  `tool_type` enum('likert_5','frequency_5','satisfaction_5','yes_no','severity_4','counter','numeric_100','numeric_custom','rubric_4','text') DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 1.00,
  `is_inverse` tinyint(1) NOT NULL DEFAULT 0,
  `max_value` decimal(10,2) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_structure_form` (`form_id`),
  KEY `idx_structure_parent` (`parent_id`),
  KEY `idx_structure_type` (`type`),
  CONSTRAINT `fk_structure_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_structure_parent` FOREIGN KEY (`parent_id`) REFERENCES `assessment_structure` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: attachments
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attachments`;
CREATE TABLE `attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `school_id` int(10) unsigned DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attachments_school` (`school_id`),
  KEY `idx_attachments_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: attendance_daily
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `attendance_daily`;
CREATE TABLE `attendance_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `date` date NOT NULL COMMENT 'التاريخ',
  `period_number` tinyint(3) unsigned DEFAULT NULL COMMENT '?????? ?????????? (NULL = ????????)',
  `status` enum('present','absent','late','excused') NOT NULL COMMENT 'حالة الحضور',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `amendment_log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '?????? ??????????????????' CHECK (json_valid(`amendment_log`)),
  `recorded_by` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستخدم الذي سجل',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendance_daily` (`student_id`,`class_id`,`date`,`period_number`),
  KEY `idx_attendance_daily_class` (`class_id`),
  KEY `idx_attendance_daily_academic_year` (`academic_year_id`),
  KEY `idx_attendance_daily_semester` (`semester_id`),
  KEY `idx_attendance_daily_date` (`date`),
  KEY `idx_attendance_daily_status` (`status`),
  KEY `idx_attendance_daily_recorded_by` (`recorded_by`),
  KEY `idx_attendance_daily_active` (`is_active`),
  CONSTRAINT `fk_attendance_daily_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_daily_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_daily_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_daily_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_daily_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=60260 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الحضور اليومي';

-- -----------------------------------------------------------
-- Table: behavior_categories
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_categories`;
CREATE TABLE `behavior_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(100) NOT NULL COMMENT 'اسم الفئة بالعربية',
  `name_en` varchar(100) DEFAULT NULL COMMENT 'اسم الفئة بالإنجليزية',
  `type` enum('positive','negative') NOT NULL COMMENT 'نوع الفئة',
  `points` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط',
  `school_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المدرسة (فارغ = عام)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الفئة نشطة',
  PRIMARY KEY (`id`),
  KEY `idx_behavior_categories_type` (`type`),
  KEY `idx_behavior_categories_school` (`school_id`),
  KEY `idx_behavior_categories_active` (`is_active`),
  CONSTRAINT `fk_behavior_categories_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فئات السلوك';

-- -----------------------------------------------------------
-- Table: behavior_incidents
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_incidents`;
CREATE TABLE `behavior_incidents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `category_id` int(10) unsigned NOT NULL COMMENT 'رقم فئة السلوك',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `incident_date` date NOT NULL COMMENT 'تاريخ الحادثة',
  `description` text DEFAULT NULL COMMENT 'وصف الحادثة',
  `points` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط',
  `reported_by` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستخدم الذي أبلغ',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  KEY `idx_behavior_incidents_student` (`student_id`),
  KEY `idx_behavior_incidents_category` (`category_id`),
  KEY `idx_behavior_incidents_class` (`class_id`),
  KEY `idx_behavior_incidents_academic_year` (`academic_year_id`),
  KEY `idx_behavior_incidents_semester` (`semester_id`),
  KEY `idx_behavior_incidents_date` (`incident_date`),
  KEY `idx_behavior_incidents_reported_by` (`reported_by`),
  KEY `idx_behavior_incidents_active` (`is_active`),
  CONSTRAINT `fk_behavior_incidents_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_incidents_category` FOREIGN KEY (`category_id`) REFERENCES `behavior_categories` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_incidents_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_incidents_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_incidents_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_incidents_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5200 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول السلوكيات المسجلة';

-- -----------------------------------------------------------
-- Table: behavior_scores
-- جداول درجات السلوك المحسوبة لكل طالب
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `behavior_scores`;
CREATE TABLE `behavior_scores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `starting_points` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'النقاط الابتدائية',
  `earned_points` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط المكتسبة',
  `deducted_points` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'النقاط المخصومة',
  `current_percentage` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'النسبة الحالية',
  `is_passing` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السلوك مقبول',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  KEY `idx_behavior_scores_student` (`student_id`),
  KEY `idx_behavior_scores_class` (`class_id`),
  KEY `idx_behavior_scores_academic_year` (`academic_year_id`),
  KEY `idx_behavior_scores_semester` (`semester_id`),
  KEY `idx_behavior_scores_active` (`is_active`),
  CONSTRAINT `fk_behavior_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_scores_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_scores_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_behavior_scores_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول درجات السلوك';

-- -----------------------------------------------------------
-- Table: classes
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(100) NOT NULL COMMENT 'اسم الفصل بالعربية',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `grade_level_id` int(10) unsigned NOT NULL COMMENT 'رقم الصف الدراسي',
  `school_id` int(10) unsigned NOT NULL COMMENT 'رقم المدرسة',
  `teacher_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المعلم المشرف',
  `capacity` int(10) unsigned DEFAULT 30 COMMENT 'سعة الفصل',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الفصل نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  KEY `idx_classes_academic_year` (`academic_year_id`),
  KEY `idx_classes_grade` (`grade_level_id`),
  KEY `idx_classes_school` (`school_id`),
  KEY `idx_classes_teacher` (`teacher_id`),
  KEY `idx_classes_active` (`is_active`),
  KEY `idx_classes_school_grade_year` (`school_id`,`grade_level_id`,`academic_year_id`),
  CONSTRAINT `fk_classes_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_classes_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_classes_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفصول الدراسية (الشعب)';

-- -----------------------------------------------------------
-- Table: climate_items
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `climate_items`;
CREATE TABLE `climate_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` int(10) unsigned NOT NULL COMMENT 'رقم الاستبيان',
  `item_id` int(10) unsigned NOT NULL COMMENT 'رقم عنصر التقييم',
  `numeric_value` decimal(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
  `normalized_value` decimal(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_climate_survey_items` (`survey_id`,`item_id`),
  KEY `idx_climate_survey_items_item` (`item_id`),
  KEY `idx_climate_survey_items_active` (`is_active`),
  CONSTRAINT `fk_climate_survey_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_climate_survey_items_survey` FOREIGN KEY (`survey_id`) REFERENCES `climate_surveys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر استبيان المناخ';

-- -----------------------------------------------------------
-- Table: eqi_results
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `eqi_results`;
CREATE TABLE `eqi_results` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `academic_score` decimal(5,2) DEFAULT NULL,
  `teaching_score` decimal(5,2) DEFAULT NULL,
  `climate_score` decimal(5,2) DEFAULT NULL,
  `behavior_score` decimal(5,2) DEFAULT NULL,
  `skills_score` decimal(5,2) DEFAULT NULL,
  `attendance_score` decimal(5,2) DEFAULT NULL,
  `overall_eqi` decimal(5,2) DEFAULT NULL,
  `weights_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`weights_json`)),
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `challenge_index` decimal(5,2) DEFAULT NULL,
  `adjusted_eqi` decimal(5,2) DEFAULT NULL,
  `missing_dimensions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`missing_dimensions`)),
  `calculated_by` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eqi_results` (`school_id`,`academic_year_id`,`semester_id`),
  KEY `idx_eqi_results_overall` (`overall_eqi`),
  KEY `fk_eqi_results_year` (`academic_year_id`),
  KEY `fk_eqi_results_semester` (`semester_id`),
  KEY `fk_eqi_results_calculated_by` (`calculated_by`),
  CONSTRAINT `fk_eqi_results_calculated_by` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eqi_results_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eqi_results_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_eqi_results_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: evaluations
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE `evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `eval_type` enum('teaching_summary','behavior_score') NOT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `student_id` int(10) unsigned DEFAULT NULL,
  `class_id` int(10) unsigned DEFAULT NULL,
  `school_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `self_score` decimal(5,2) DEFAULT NULL,
  `peer_score` decimal(5,2) DEFAULT NULL,
  `admin_score` decimal(5,2) DEFAULT NULL,
  `combined_score` decimal(5,2) DEFAULT NULL,
  `adjusted_score` decimal(5,2) DEFAULT NULL,
  `starting_points` decimal(5,2) DEFAULT NULL,
  `earned_points` decimal(5,2) DEFAULT NULL,
  `deducted_points` decimal(5,2) DEFAULT NULL,
  `current_percentage` decimal(5,2) DEFAULT NULL,
  `is_passing` tinyint(1) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_evaluations_type` (`eval_type`),
  KEY `idx_evaluations_teacher` (`teacher_id`),
  KEY `idx_evaluations_student` (`student_id`),
  KEY `idx_evaluations_school_year` (`school_id`,`academic_year_id`,`semester_id`),
  KEY `fk_evaluations_class` (`class_id`),
  KEY `fk_evaluations_year` (`academic_year_id`),
  KEY `fk_evaluations_semester` (`semester_id`),
  CONSTRAINT `fk_evaluations_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`),
  CONSTRAINT `fk_evaluations_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_evaluations_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_evaluations_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_evaluations_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_evaluations_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=536 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: facility_items
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `facility_items`;
CREATE TABLE `facility_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `checklist_id` int(10) unsigned NOT NULL COMMENT 'رقم قائمة الفحص',
  `item_id` int(10) unsigned NOT NULL COMMENT 'رقم عنصر التقييم',
  `numeric_value` decimal(10,4) DEFAULT NULL COMMENT 'القيمة الرقمية',
  `normalized_value` decimal(5,2) DEFAULT NULL COMMENT 'القيمة الطبيعية (من 100)',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل العنصر نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_facility_checklist_items` (`checklist_id`,`item_id`),
  KEY `idx_facility_checklist_items_item` (`item_id`),
  KEY `idx_facility_checklist_items_active` (`is_active`),
  CONSTRAINT `fk_facility_checklist_items_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `facility_checklists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_facility_checklist_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر قائمة فحص المرافق';

-- -----------------------------------------------------------
-- Table: governorates
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `governorates`;
CREATE TABLE `governorates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(100) NOT NULL COMMENT 'اسم المحافظة بالعربية',
  `name_en` varchar(100) DEFAULT NULL COMMENT 'اسم المحافظة بالإنجليزية',
  `code` varchar(5) NOT NULL COMMENT 'رمز المحافظة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل المحافظة نشطة',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_governorates_code` (`code`),
  KEY `idx_governorates_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المحافظات اليمنية';

-- -----------------------------------------------------------
-- Table: grade_levels
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grade_levels`;
CREATE TABLE `grade_levels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(50) NOT NULL COMMENT 'اسم الصف بالعربية',
  `name_en` varchar(50) DEFAULT NULL COMMENT 'اسم الصف بالإنجليزية',
  `level_number` tinyint(3) unsigned NOT NULL COMMENT 'رقم الصف (1-12)',
  `stage` enum('basic','secondary') NOT NULL COMMENT 'المرحلة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الصف نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grade_levels_number` (`level_number`),
  KEY `idx_grade_levels_stage` (`stage`),
  KEY `idx_grade_levels_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المراحل الدراسية';

-- -----------------------------------------------------------
-- Table: grades
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `test_id` int(10) unsigned NOT NULL COMMENT 'رقم الاختبار',
  `academic_year_id` int(10) unsigned DEFAULT NULL,
  `semester_id` int(10) unsigned DEFAULT NULL,
  `grade_value` decimal(7,2) NOT NULL COMMENT 'قيمة الدرجة',
  `max_value` decimal(7,2) NOT NULL COMMENT 'القيمة القصوى',
  `percentage` decimal(7,2) NOT NULL COMMENT 'النسبة المئوية',
  `letter_grade` varchar(3) DEFAULT NULL COMMENT 'التقدير الحرفي',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `graded_by` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستخدم الذي رصد الدرجة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الدرجة نشطة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grades_student_test` (`student_id`,`test_id`),
  KEY `idx_grades_test` (`test_id`),
  KEY `idx_grades_percentage` (`percentage`),
  KEY `idx_grades_letter` (`letter_grade`),
  KEY `idx_grades_graded_by` (`graded_by`),
  KEY `idx_grades_active` (`is_active`),
  CONSTRAINT `fk_grades_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grades_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12052 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الدرجات';

-- -----------------------------------------------------------
-- Table: improvement_plans
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `improvement_plans`;
CREATE TABLE `improvement_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plan_type` enum('student','school','teacher') NOT NULL,
  `student_id` int(10) unsigned DEFAULT NULL,
  `school_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `trigger_type` enum('climate','facility','both','low_score','behavior') DEFAULT NULL,
  `weaknesses` text DEFAULT NULL,
  `goals` text DEFAULT NULL,
  `strategies` text DEFAULT NULL,
  `timeline` varchar(200) DEFAULT NULL,
  `responsible_user_id` int(10) unsigned DEFAULT NULL,
  `parent_involvement` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `progress` int(11) NOT NULL DEFAULT 0,
  `follow_up_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_imp_plans_type` (`plan_type`),
  KEY `idx_imp_plans_student` (`student_id`),
  KEY `idx_imp_plans_school` (`school_id`),
  KEY `idx_imp_plans_teacher` (`teacher_id`),
  KEY `idx_imp_plans_status` (`status`),
  KEY `fk_imp_plans_responsible` (`responsible_user_id`),
  KEY `fk_imp_plans_created_by` (`created_by`),
  KEY `fk_imp_plans_updated_by` (`updated_by`),
  CONSTRAINT `fk_imp_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_imp_plans_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_imp_plans_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_imp_plans_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_imp_plans_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_imp_plans_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: learning_outcomes
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `learning_outcomes`;
CREATE TABLE `learning_outcomes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` int(10) unsigned NOT NULL COMMENT 'رقم المادة',
  `code` varchar(20) NOT NULL COMMENT 'رمز مخرج التعلم',
  `description_ar` text NOT NULL COMMENT 'الوصف بالعربية',
  `description_en` text DEFAULT NULL COMMENT 'الوصف بالإنجليزية',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل مخرج التعلم نشط',
  PRIMARY KEY (`id`),
  KEY `idx_learning_outcomes_subject` (`subject_id`),
  KEY `idx_learning_outcomes_code` (`code`),
  KEY `idx_learning_outcomes_active` (`is_active`),
  CONSTRAINT `fk_learning_outcomes_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول مخرجات التعلم';

-- -----------------------------------------------------------
-- Table: notifications
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title_ar` varchar(200) NOT NULL,
  `message_ar` text NOT NULL,
  `related_entity_type` varchar(50) DEFAULT NULL,
  `related_entity_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  KEY `idx_notif_created` (`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: parent_students
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `parent_students`;
CREATE TABLE `parent_students` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `relationship` enum('father','mother','guardian','other') NOT NULL DEFAULT 'father',
  `ack_grade` tinyint(1) DEFAULT NULL,
  `ack_behavior` tinyint(1) DEFAULT NULL,
  `ack_attendance` tinyint(1) DEFAULT NULL,
  `ack_promotion` tinyint(1) DEFAULT NULL,
  `last_ack_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_parent_students` (`parent_id`,`student_id`),
  KEY `fk_parent_students_student` (`student_id`),
  CONSTRAINT `fk_parent_students_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_parent_students_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: permissions
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'اسم الصلاحية بالإنجليزية',
  `name_ar` varchar(200) NOT NULL COMMENT 'اسم الصلاحية بالعربية',
  `module` varchar(50) NOT NULL COMMENT 'الوحدة البرمجية',
  `description` text DEFAULT NULL COMMENT 'وصف الصلاحية',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الصلاحية نشطة',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permissions_name` (`name`),
  KEY `idx_permissions_module` (`module`),
  KEY `idx_permissions_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الصلاحيات';

-- -----------------------------------------------------------
-- Table: promotions
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `promotions`;
CREATE TABLE `promotions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `academic_score` decimal(5,2) DEFAULT NULL,
  `behavior_score` decimal(5,2) DEFAULT NULL,
  `attendance_score` decimal(5,2) DEFAULT NULL,
  `academic_pass` tinyint(1) DEFAULT NULL,
  `behavior_pass` tinyint(1) DEFAULT NULL,
  `attendance_pass` tinyint(1) DEFAULT NULL,
  `decision` enum('promoted','repeated','conditional','under_review') NOT NULL,
  `status` enum('proposed','approved','rejected') NOT NULL DEFAULT 'proposed',
  `override_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`override_json`)),
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `decided_by` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promotions` (`student_id`,`academic_year_id`,`semester_id`),
  KEY `fk_promotions_year` (`academic_year_id`),
  KEY `fk_promotions_semester` (`semester_id`),
  KEY `fk_promotions_approved_by` (`approved_by`),
  KEY `fk_promotions_decided_by` (`decided_by`),
  CONSTRAINT `fk_promotions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_promotions_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_promotions_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_promotions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promotions_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=520 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: role_permissions
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL COMMENT 'رقم الدور',
  `permission_id` int(10) unsigned NOT NULL COMMENT 'رقم الصلاحية',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الربط نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permissions` (`role_id`,`permission_id`),
  KEY `idx_role_permissions_permission` (`permission_id`),
  KEY `idx_role_permissions_active` (`is_active`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول صلاحيات الأدوار';

-- -----------------------------------------------------------
-- Table: roles
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'اسم الدور بالإنجليزية',
  `display_name_ar` varchar(100) NOT NULL COMMENT 'اسم الدور المعروض بالعربية',
  `display_name_en` varchar(100) DEFAULT NULL COMMENT 'اسم الدور المعروض بالإنجليزية',
  `rank` int(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب الدور',
  `description` text DEFAULT NULL COMMENT 'وصف الدور',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الدور نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`name`),
  KEY `idx_roles_active` (`is_active`),
  KEY `idx_roles_rank` (`rank`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الأدوار';

-- -----------------------------------------------------------
-- Table: school_improvement_plans
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_improvement_plans`;
CREATE TABLE `school_improvement_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `weaknesses` text DEFAULT NULL,
  `goals` text DEFAULT NULL,
  `strategies` text DEFAULT NULL,
  `timeline` varchar(200) DEFAULT NULL,
  `responsible_user_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `progress` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sip_school` (`school_id`),
  KEY `idx_sip_status` (`status`),
  KEY `fk_sip_year` (`academic_year_id`),
  KEY `fk_sip_semester` (`semester_id`),
  KEY `fk_sip_responsible` (`responsible_user_id`),
  KEY `fk_sip_created_by` (`created_by`),
  KEY `fk_sip_updated_by` (`updated_by`),
  CONSTRAINT `fk_sip_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sip_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sip_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_sip_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sip_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: schools
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `schools`;
CREATE TABLE `schools` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(200) NOT NULL COMMENT 'اسم المدرسة بالعربية',
  `name_en` varchar(200) DEFAULT NULL COMMENT 'اسم المدرسة بالإنجليزية',
  `reference_code` varchar(5) NOT NULL COMMENT 'رمز المرجعي الفريد',
  `address` text DEFAULT NULL COMMENT 'عنوان المدرسة',
  `governorate` varchar(100) DEFAULT NULL COMMENT 'المحافظة',
  `district` varchar(100) DEFAULT NULL COMMENT 'المديرية',
  `school_type` enum('basic','secondary','combined') NOT NULL DEFAULT 'combined' COMMENT 'نوع المدرسة',
  `principal_name` varchar(200) DEFAULT NULL COMMENT 'اسم مدير المدرسة',
  `phone` varchar(20) DEFAULT NULL COMMENT 'رقم الهاتف',
  `email` varchar(100) DEFAULT NULL COMMENT 'البريد الإلكتروني',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل المدرسة نشطة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schools_reference_code` (`reference_code`),
  KEY `idx_schools_governorate` (`governorate`),
  KEY `idx_schools_district` (`district`),
  KEY `idx_schools_type` (`school_type`),
  KEY `idx_schools_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المدارس';

-- -----------------------------------------------------------
-- Table: school_context
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `school_context`;
CREATE TABLE `school_context` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` int(10) unsigned NOT NULL COMMENT 'رقم المدرسة',
  `academic_year_id` int(10) unsigned DEFAULT NULL COMMENT 'ربط اختياري بسنة دراسية',
  `location_type` enum('urban','suburban','rural') DEFAULT NULL COMMENT 'نوع المنطقة',
  `total_students` int(10) unsigned DEFAULT NULL COMMENT 'إجمالي عدد الطلاب',
  `student_teacher_ratio` decimal(5,1) DEFAULT NULL COMMENT 'نسبة الطلاب إلى المعلمين',
  `socioeconomic_index` decimal(5,1) DEFAULT NULL COMMENT 'المؤشر الاقتصادي الاجتماعي (0-100)',
  `parent_education_level` decimal(5,1) DEFAULT NULL COMMENT 'مستوى تعليم أولياء الأمور (0-100)',
  `students_with_special_needs_pct` decimal(5,1) DEFAULT NULL COMMENT 'نسبة الطلاب ذوي الاحتياجات الخاصة (%)',
  `infrastructure_score` decimal(5,1) DEFAULT NULL COMMENT 'درجة جودة البنية التحتية (0-100)',
  `student_mobility_rate` decimal(5,1) DEFAULT NULL COMMENT 'معدل تنقل الطلاب (0-100)',
  `challenge_index` decimal(5,1) DEFAULT 0.0 COMMENT 'مؤشر التحدي السياقي المحسوب (0-100)',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'حالة التفعيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_school_context` (`school_id`),
  KEY `idx_school_context_school` (`school_id`),
  KEY `idx_school_context_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='السياق المدرسي لعوامل التعديل السياقي';

-- -----------------------------------------------------------
-- Table: semesters
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `semesters`;
CREATE TABLE `semesters` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `name_ar` varchar(50) NOT NULL COMMENT 'اسم الفصل الدراسي',
  `start_date` date NOT NULL COMMENT 'تاريخ البداية',
  `end_date` date NOT NULL COMMENT 'تاريخ النهاية',
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل هو الفصل الحالي',
  `lock_level` enum('none','soft','hard','admin_handled') NOT NULL DEFAULT 'none' COMMENT 'مستوى القفل',
  `locked_at` datetime DEFAULT NULL COMMENT 'تاريخ القفل',
  `locked_by` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستخدم الذي قفل',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الفصل نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_semester_year` (`academic_year_id`,`name_ar`),
  KEY `idx_semesters_academic_year` (`academic_year_id`),
  KEY `idx_semesters_current` (`is_current`),
  KEY `idx_semesters_dates` (`start_date`,`end_date`),
  KEY `idx_semesters_lock_level` (`lock_level`),
  KEY `idx_semesters_active` (`is_active`),
  KEY `idx_semesters_locked_by` (`locked_by`),
  CONSTRAINT `fk_semesters_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_semesters_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفصول الدراسية';

-- -----------------------------------------------------------
-- Table: student_enrollments
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `student_enrollments`;
CREATE TABLE `student_enrollments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `enrollment_date` date NOT NULL COMMENT 'تاريخ التسجيل',
  `status` enum('enrolled','transferred','dropped') NOT NULL DEFAULT 'enrolled' COMMENT 'حالة التسجيل',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل التسجيل نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_student_enrollments` (`student_id`,`class_id`,`academic_year_id`),
  KEY `idx_student_enrollments_class` (`class_id`),
  KEY `idx_student_enrollments_academic_year` (`academic_year_id`),
  KEY `idx_student_enrollments_status` (`status`),
  KEY `idx_student_enrollments_active` (`is_active`),
  CONSTRAINT `fk_student_enrollments_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_enrollments_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1513 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تسجيل الطلاب في الفصول';

-- -----------------------------------------------------------
-- Table: student_notes
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `student_notes`;
CREATE TABLE `student_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL COMMENT 'رقم الطالب',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `note_type` enum('general','academic','behavioral','attendance') NOT NULL DEFAULT 'general' COMMENT 'نوع الملاحظة',
  `title_ar` varchar(200) NOT NULL COMMENT 'عنوان الملاحظة بالعربية',
  `content_ar` text NOT NULL COMMENT 'محتوى الملاحظة بالعربية',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium' COMMENT 'الأولوية',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل الملاحظة مثبتة',
  `is_draft` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل هي مسودة',
  `attachment_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المرفق المرجعي',
  `created_by` int(10) unsigned NOT NULL COMMENT 'رقم المستخدم المنشئ',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الملاحظة نشطة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  KEY `idx_student_notes_student` (`student_id`),
  KEY `idx_student_notes_class` (`class_id`),
  KEY `idx_student_notes_academic_year` (`academic_year_id`),
  KEY `idx_student_notes_semester` (`semester_id`),
  KEY `idx_student_notes_type` (`note_type`),
  KEY `idx_student_notes_priority` (`priority`),
  KEY `idx_student_notes_created_by` (`created_by`),
  KEY `idx_student_notes_active` (`is_active`),
  CONSTRAINT `fk_student_notes_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_notes_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_notes_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_student_notes_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول ملاحظات الطلاب';

-- -----------------------------------------------------------
-- Table: students
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_number` varchar(20) NOT NULL COMMENT 'رقم الطالب',
  `full_name_ar` varchar(200) NOT NULL COMMENT 'الاسم الكامل بالعربية',
  `full_name_en` varchar(200) DEFAULT NULL COMMENT 'الاسم الكامل بالإنجليزية',
  `guardian_name` varchar(200) DEFAULT NULL COMMENT 'اسم ولي الأمر',
  `guardian_phone` varchar(20) DEFAULT NULL COMMENT 'هاتف ولي الأمر',
  `guardian_email` varchar(100) DEFAULT NULL COMMENT 'بريد ولي الأمر',
  `grade_level_id` int(10) unsigned NOT NULL COMMENT 'رقم الصف الدراسي',
  `school_id` int(10) unsigned NOT NULL COMMENT 'رقم المدرسة',
  `enrollment_date` date DEFAULT NULL COMMENT 'تاريخ التسجيل',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الطالب نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_students_number` (`student_number`),
  KEY `idx_students_name` (`full_name_ar`),
  KEY `idx_students_grade` (`grade_level_id`),
  KEY `idx_students_school` (`school_id`),
  KEY `idx_students_guardian_phone` (`guardian_phone`),
  KEY `idx_students_active` (`is_active`),
  CONSTRAINT `fk_students_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_students_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=613 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الطلاب';

-- -----------------------------------------------------------
-- Table: subject_grade_years
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subject_grade_years`;
CREATE TABLE `subject_grade_years` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` int(10) unsigned NOT NULL COMMENT 'رقم المادة',
  `grade_level_id` int(10) unsigned NOT NULL COMMENT 'رقم الصف الدراسي',
  `hours_per_week` tinyint(3) unsigned DEFAULT NULL COMMENT 'عدد الساعات أسبوعياً',
  `passing_grade` decimal(5,2) NOT NULL DEFAULT 50.00 COMMENT 'درجة النجاح',
  `weights_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{"quiz":20,"midterm":30,"final":50}' CHECK (json_valid(`weights_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل السجل نشط',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subject_grade_years` (`subject_id`,`grade_level_id`),
  KEY `idx_subject_grade_years_grade` (`grade_level_id`),
  KEY `idx_subject_grade_years_active` (`is_active`),
  CONSTRAINT `fk_subject_grade_years_grade` FOREIGN KEY (`grade_level_id`) REFERENCES `grade_levels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_subject_grade_years_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواد بالصفوف الدراسية';

-- -----------------------------------------------------------
-- Table: subject_teachers
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subject_teachers`;
CREATE TABLE `subject_teachers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` int(10) unsigned NOT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `classroom_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `school_id` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `semester_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subject_teacher_class_sem` (`subject_id`,`teacher_id`,`classroom_id`,`semester_id`),
  KEY `idx_subject_teachers_teacher` (`teacher_id`),
  KEY `idx_subject_teachers_classroom` (`classroom_id`),
  KEY `idx_subject_teachers_semester` (`semester_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5096 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: subjects
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(100) NOT NULL COMMENT 'اسم المادة بالعربية',
  `name_en` varchar(100) DEFAULT NULL COMMENT 'اسم المادة بالإنجليزية',
  `code` varchar(20) NOT NULL COMMENT 'رمز المادة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل المادة نشطة',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subjects_code` (`code`),
  KEY `idx_subjects_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواد الدراسية';

-- -----------------------------------------------------------
-- Table: survey_items
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `survey_items`;
CREATE TABLE `survey_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `numeric_value` decimal(10,4) DEFAULT NULL,
  `normalized_value` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_survey_items` (`survey_id`,`item_id`),
  KEY `idx_survey_items_item` (`item_id`),
  CONSTRAINT `fk_survey_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`),
  CONSTRAINT `fk_survey_items_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: surveys
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `surveys`;
CREATE TABLE `surveys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `survey_type` enum('climate','facility') NOT NULL,
  `school_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `respondent_type` enum('teacher','student','parent','admin','evaluator') DEFAULT NULL,
  `respondent_id` int(10) unsigned DEFAULT NULL,
  `evaluator_id` int(10) unsigned DEFAULT NULL,
  `survey_date` date NOT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','active','closed') DEFAULT 'draft',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_surveys_type` (`survey_type`),
  KEY `idx_surveys_school` (`school_id`),
  KEY `idx_surveys_year_sem` (`academic_year_id`,`semester_id`),
  KEY `fk_surveys_semester` (`semester_id`),
  KEY `fk_surveys_respondent` (`respondent_id`),
  KEY `fk_surveys_evaluator` (`evaluator_id`),
  KEY `fk_surveys_created_by` (`created_by`),
  CONSTRAINT `fk_surveys_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_surveys_evaluator` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_surveys_respondent` FOREIGN KEY (`respondent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_surveys_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_surveys_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  CONSTRAINT `fk_surveys_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: system_settings
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(50) NOT NULL COMMENT 'فئة الإعداد',
  `setting_key` varchar(100) NOT NULL COMMENT 'مفتاح الإعداد',
  `setting_value` text DEFAULT NULL COMMENT 'قيمة الإعداد',
  `value_type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string' COMMENT 'نوع القيمة',
  `name_ar` varchar(200) NOT NULL COMMENT 'اسم الإعداد بالعربية',
  `description_ar` text DEFAULT NULL COMMENT 'وصف الإعداد بالعربية',
  `is_system` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل إعداد نظام',
  `school_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المدرسة (للتجاوزات)',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الإعداد نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_settings` (`category`,`setting_key`,`school_id`),
  KEY `idx_system_settings_category` (`category`),
  KEY `idx_system_settings_key` (`setting_key`),
  KEY `idx_system_settings_school` (`school_id`),
  KEY `idx_system_settings_sort` (`sort_order`),
  KEY `idx_system_settings_active` (`is_active`),
  CONSTRAINT `fk_system_settings_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات النظام';

-- -----------------------------------------------------------
-- Table: teacher_assignments
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `teacher_assignments`;
CREATE TABLE `teacher_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `class_id` int(10) unsigned NOT NULL,
  `academic_year_id` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher_subj_class_year` (`teacher_id`,`subject_id`,`class_id`,`academic_year_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------
-- Table: tests
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `tests`;
CREATE TABLE `tests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` int(10) unsigned NOT NULL COMMENT 'رقم المادة',
  `class_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل',
  `assessment_type_id` int(10) unsigned NOT NULL COMMENT 'رقم نوع التقييم',
  `academic_year_id` int(10) unsigned NOT NULL COMMENT 'رقم السنة الدراسية',
  `semester_id` int(10) unsigned NOT NULL COMMENT 'رقم الفصل الدراسي',
  `title_ar` varchar(200) NOT NULL COMMENT 'عنوان الاختبار بالعربية',
  `max_grade` decimal(5,2) NOT NULL COMMENT 'الدرجة القصوى',
  `test_date` date DEFAULT NULL COMMENT 'تاريخ الاختبار',
  `status` enum('draft','published','locked') NOT NULL DEFAULT 'draft' COMMENT 'حالة الاختبار',
  `created_by` int(10) unsigned NOT NULL COMMENT 'رقم المستخدم المنشئ',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل الاختبار نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
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
  CONSTRAINT `fk_tests_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tests_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tests_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tests_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tests_type` FOREIGN KEY (`assessment_type_id`) REFERENCES `assessment_types` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1096 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الاختبارات';

-- -----------------------------------------------------------
-- Table: user_preferences
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `pref_type` enum('dashboard','notification','general') NOT NULL,
  `pref_key` varchar(100) NOT NULL,
  `pref_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pref_value`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_preferences` (`user_id`,`pref_type`,`pref_key`),
  CONSTRAINT `fk_user_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Table: users
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT 'اسم المستخدم',
  `password_hash` varchar(255) NOT NULL COMMENT 'كلمة المرور المشفرة',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ÅÌÈÇÑ ÊÛííÑ ßáãÉ ÇáãÑæÑ ÚäÏ Ãæá ÊÓÌíá ÏÎæá',
  `full_name_ar` varchar(200) NOT NULL COMMENT 'الاسم الكامل بالعربية',
  `full_name_en` varchar(200) DEFAULT NULL COMMENT 'الاسم الكامل بالإنجليزية',
  `email` varchar(100) DEFAULT NULL COMMENT 'البريد الإلكتروني',
  `phone` varchar(20) DEFAULT NULL COMMENT 'رقم الهاتف',
  `role_id` int(10) unsigned NOT NULL COMMENT 'رقم الدور',
  `school_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المدرسة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'هل المستخدم نشط',
  `last_login_at` datetime DEFAULT NULL COMMENT 'آخر تسجيل دخول',
  `login_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'عدد محاولات الدخول',
  `lockout_until` datetime DEFAULT NULL COMMENT 'تاريخ انتهاء القفل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_school` (`school_id`),
  KEY `idx_users_active` (`is_active`),
  KEY `idx_users_lockout` (`lockout_until`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المستخدمين';

-- -----------------------------------------------------------
-- Table: export_history
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `export_history`;
CREATE TABLE `export_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'رقم المستخدم',
  `export_type` varchar(50) NOT NULL COMMENT 'نوع التصدير',
  `file_name` varchar(255) NOT NULL COMMENT 'اسم الملف',
  `file_path` varchar(500) NOT NULL COMMENT 'مسار الملف',
  `file_size` int(10) unsigned DEFAULT 0 COMMENT 'حجم الملف بالبايت',
  `row_count` int(10) unsigned DEFAULT 0 COMMENT 'عدد الصفوف',
  `filters` json DEFAULT NULL COMMENT 'فلاتر التصدير',
  `status` enum('completed','failed','deleted') NOT NULL DEFAULT 'completed' COMMENT 'حالة التصدير',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`id`),
  KEY `idx_export_history_user` (`user_id`),
  KEY `idx_export_history_type` (`export_type`),
  KEY `idx_export_history_status` (`status`),
  KEY `idx_export_history_created` (`created_at`),
  CONSTRAINT `fk_export_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل ملفات التصدير';
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المستخدمين';

-- -----------------------------------------------------------
-- View: v_student_current
-- -----------------------------------------------------------
DROP VIEW IF EXISTS `v_student_current`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_student_current` AS select `s`.`id` AS `student_id`,`s`.`student_number` AS `student_number`,`s`.`full_name_ar` AS `full_name_ar`,`s`.`full_name_en` AS `full_name_en`,`s`.`guardian_name` AS `guardian_name`,`s`.`guardian_phone` AS `guardian_phone`,`s`.`grade_level_id` AS `grade_level_id`,`s`.`school_id` AS `school_id`,`gy`.`id` AS `grade_year_id`,`gy`.`status` AS `enrollment_status`,`se`.`class_id` AS `class_id`,`se`.`academic_year_id` AS `academic_year_id`,`c`.`name_ar` AS `class_name`,`gl`.`name_ar` AS `grade_name`,`gl`.`stage` AS `stage`,`sc`.`name_ar` AS `school_name`,`ay`.`name` AS `academic_year_name` from ((((((`students` `s` left join `grade_years` `gy` on(`s`.`id` = `gy`.`student_id` and `gy`.`is_active` = 1)) left join `student_enrollments` `se` on(`s`.`id` = `se`.`student_id` and `se`.`is_active` = 1)) left join `classes` `c` on(`se`.`class_id` = `c`.`id`)) left join `grade_levels` `gl` on(`s`.`grade_level_id` = `gl`.`id`)) left join `schools` `sc` on(`s`.`school_id` = `sc`.`id`)) left join `academic_years` `ay` on(`se`.`academic_year_id` = `ay`.`id`)) where `s`.`is_active` = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------
-- View: v_student_subject_summary
-- ملخص درجات الطالب لكل مادة (من الاختبارات المنشورة)
-- -----------------------------------------------------------
DROP VIEW IF EXISTS `v_student_subject_summary`;
CREATE VIEW `v_student_subject_summary` AS
SELECT 
    se.student_id,
    t.subject_id,
    t.class_id,
    t.academic_year_id,
    t.semester_id,
    SUM(g.grade_value) AS total_grade,
    SUM(g.max_value) AS max_grade,
    ROUND(SUM(g.grade_value) / NULLIF(SUM(g.max_value), 0) * 100, 2) AS percentage
FROM student_enrollments se
JOIN tests t ON se.class_id = t.class_id AND se.academic_year_id = t.academic_year_id
JOIN grades g ON t.id = g.test_id AND se.student_id = g.student_id
WHERE se.status = 'enrolled' AND g.is_active = 1 AND t.status = 'published'
GROUP BY se.student_id, t.subject_id, t.class_id, t.academic_year_id, t.semester_id;

-- -----------------------------------------------------------
-- View: v_semester_scores
-- متوسط درجات الطالب لكل فصل دراسي
-- -----------------------------------------------------------
DROP VIEW IF EXISTS `v_semester_scores`;
CREATE VIEW `v_semester_scores` AS
SELECT 
    student_id,
    academic_year_id,
    semester_id,
    AVG(percentage) AS gpa,
    ROUND(AVG(percentage), 2) AS total_percentage
FROM v_student_subject_summary
GROUP BY student_id, academic_year_id, semester_id;

-- -----------------------------------------------------------
-- View: student_subject_summary (بدون v_)
-- واجهة متوافقة مع الكود القديم - يضيف أعمدة محسوبة
-- -----------------------------------------------------------
DROP VIEW IF EXISTS `student_subject_summary`;
CREATE VIEW `student_subject_summary` AS
SELECT 
    ROW_NUMBER() OVER() AS id,
    vss.student_id,
    vss.subject_id,
    vss.class_id,
    vss.academic_year_id,
    vss.semester_id,
    vss.total_grade,
    vss.max_grade,
    vss.percentage,
    CASE 
        WHEN vss.percentage >= 95 THEN 'A+'
        WHEN vss.percentage >= 90 THEN 'A'
        WHEN vss.percentage >= 85 THEN 'B+'
        WHEN vss.percentage >= 80 THEN 'B'
        WHEN vss.percentage >= 75 THEN 'C+'
        WHEN vss.percentage >= 70 THEN 'C'
        WHEN vss.percentage >= 65 THEN 'D+'
        WHEN vss.percentage >= 60 THEN 'D'
        ELSE 'F'
    END AS letter_grade,
    CASE 
        WHEN vss.percentage >= 95 THEN 4.00
        WHEN vss.percentage >= 90 THEN 3.75
        WHEN vss.percentage >= 85 THEN 3.50
        WHEN vss.percentage >= 80 THEN 3.00
        WHEN vss.percentage >= 75 THEN 2.50
        WHEN vss.percentage >= 70 THEN 2.00
        WHEN vss.percentage >= 65 THEN 1.50
        WHEN vss.percentage >= 60 THEN 1.00
        ELSE 0.00
    END AS grade_point,
    0 AS rank_in_class,
    1 AS is_active,
    NOW() AS updated_at
FROM v_student_subject_summary vss;

-- -----------------------------------------------------------
-- View: v_attendance_summary
-- ملخص الحضور والغياب لكل طالب (من attendance_daily)
-- -----------------------------------------------------------
DROP VIEW IF EXISTS `v_attendance_summary`;
CREATE VIEW `v_attendance_summary` AS
SELECT 
    student_id,
    class_id,
    academic_year_id,
    semester_id,
    COUNT(*) AS total_days,
    SUM(status = 'present') AS present_days,
    SUM(status = 'absent') AS absent_days,
    SUM(status = 'late') AS late_days,
    SUM(status = 'excused') AS excused_days,
    ROUND(SUM(status = 'present') / COUNT(*) * 100, 2) AS attendance_rate,
    CASE WHEN SUM(status = 'present') / COUNT(*) * 100 < 75 THEN 1 ELSE 0 END AS is_below_threshold,
    1 AS is_active
FROM attendance_daily
WHERE is_active = 1 AND period_number IS NULL
GROUP BY student_id, class_id, academic_year_id, semester_id;
