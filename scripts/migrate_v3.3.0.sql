-- ============================================================================
-- EQAS Database Migration v3.3.0
-- From 89 tables → 37 tables (58% reduction)
-- ============================================================================
-- 
-- PHASE 1: Fix current issues
-- PHASE 2: Create new tables
-- PHASE 3: Migrate data
-- PHASE 4: (Code changes - separate)
-- PHASE 5: (Testing - separate)
-- PHASE 6: Drop old tables
--
-- IMPORTANT: Run full backup before executing!
-- mysqldump -u root eqas_db > backups/eqas_db_pre_v330.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- PHASE 1: Fix Current Issues
-- ============================================================================

-- 1.1 Remove duplicate eqi_calculations (equi_results already exists with same data)
-- Skip if already done
-- DROP TABLE IF EXISTS `eqi_calculations`;

-- 1.2 Remove unused semester_status_log
DROP TABLE IF EXISTS `semester_status_log`;

-- 1.3 Remove ghost tables (0 rows, no code references)
DROP TABLE IF EXISTS `semester_status_log`;
DROP TABLE IF EXISTS `grade_sheet_snapshots`;
DROP TABLE IF EXISTS `error_log_dedup`;
DROP TABLE IF EXISTS `school_skills_availability`;

-- ============================================================================
-- PHASE 2: Create New Tables
-- ============================================================================

-- -----------------------------------------------------------
-- 2.1 assessment_structure (merges assessment_criteria + assessment_items)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `assessment_structure`;
CREATE TABLE `assessment_structure` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = معيار، != NULL = عنصر تابع',
    `type` ENUM('criteria','item') NOT NULL,
    `name_ar` VARCHAR(200) NOT NULL,
    `name_en` VARCHAR(200) DEFAULT NULL,
    `text_ar` TEXT DEFAULT NULL COMMENT 'للعناصر فقط',
    `text_en` TEXT DEFAULT NULL,
    `tool_type` ENUM('likert_5','frequency_5','satisfaction_5','yes_no','severity_4','counter','numeric_100','numeric_custom','rubric_4','text') DEFAULT NULL COMMENT 'للعناصر فقط',
    `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    `is_inverse` TINYINT(1) NOT NULL DEFAULT 0,
    `max_value` DECIMAL(10,2) DEFAULT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_structure_form` (`form_id`),
    KEY `idx_structure_parent` (`parent_id`),
    KEY `idx_structure_type` (`type`),
    KEY `idx_structure_active` (`is_active`),
    CONSTRAINT `fk_structure_form` FOREIGN KEY (`form_id`) REFERENCES `assessment_forms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_structure_parent` FOREIGN KEY (`parent_id`) REFERENCES `assessment_structure` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='هيكل التقييم الموحد (معايير + عناصر)';

-- -----------------------------------------------------------
-- 2.2 surveys (merges climate_surveys + facility_checklists)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `surveys`;
CREATE TABLE `surveys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_type` ENUM('climate','facility') NOT NULL,
    `school_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `respondent_type` ENUM('teacher','student','parent','admin','evaluator') DEFAULT NULL,
    `respondent_id` INT UNSIGNED DEFAULT NULL,
    `evaluator_id` INT UNSIGNED DEFAULT NULL,
    `survey_date` DATE NOT NULL,
    `overall_score` DECIMAL(5,2) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('draft','active','closed') DEFAULT 'draft',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_surveys_type` (`survey_type`),
    KEY `idx_surveys_school` (`school_id`),
    KEY `idx_surveys_year_sem` (`academic_year_id`, `semester_id`),
    KEY `idx_surveys_date` (`survey_date`),
    KEY `idx_surveys_active` (`is_active`),
    CONSTRAINT `fk_surveys_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_surveys_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_surveys_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_surveys_respondent` FOREIGN KEY (`respondent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_surveys_evaluator` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_surveys_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استبيانات موحدة (مناخ + مرافق)';

-- -----------------------------------------------------------
-- 2.3 survey_items (merges climate_survey_items + facility_checklist_items)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `survey_items`;
CREATE TABLE `survey_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `numeric_value` DECIMAL(10,4) DEFAULT NULL,
    `normalized_value` DECIMAL(5,2) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_survey_items` (`survey_id`, `item_id`),
    KEY `idx_survey_items_item` (`item_id`),
    KEY `idx_survey_items_active` (`is_active`),
    CONSTRAINT `fk_survey_items_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_survey_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عناصر الاستبيانات الموحدة';

-- -----------------------------------------------------------
-- 2.4 evaluations (merges teaching_evaluation_summary + behavior_scores)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE `evaluations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eval_type` ENUM('teaching_summary','behavior_score') NOT NULL,
    `teacher_id` INT UNSIGNED DEFAULT NULL,
    `student_id` INT UNSIGNED DEFAULT NULL,
    `class_id` INT UNSIGNED DEFAULT NULL,
    `school_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `self_score` DECIMAL(5,2) DEFAULT NULL,
    `peer_score` DECIMAL(5,2) DEFAULT NULL,
    `admin_score` DECIMAL(5,2) DEFAULT NULL,
    `combined_score` DECIMAL(5,2) DEFAULT NULL,
    `adjusted_score` DECIMAL(5,2) DEFAULT NULL,
    `starting_points` DECIMAL(5,2) DEFAULT NULL COMMENT 'للسلوك فقط',
    `earned_points` DECIMAL(5,2) DEFAULT NULL COMMENT 'للسلوك فقط',
    `deducted_points` DECIMAL(5,2) DEFAULT NULL COMMENT 'للسلوك فقط',
    `current_percentage` DECIMAL(5,2) DEFAULT NULL COMMENT 'للسلوك فقط',
    `is_passing` TINYINT(1) DEFAULT NULL COMMENT 'للسلوك فقط',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_evaluations_type` (`eval_type`),
    KEY `idx_evaluations_teacher` (`teacher_id`),
    KEY `idx_evaluations_student` (`student_id`),
    KEY `idx_evaluations_school_year` (`school_id`, `academic_year_id`, `semester_id`),
    KEY `idx_evaluations_active` (`is_active`),
    CONSTRAINT `fk_evaluations_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluations_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluations_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluations_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluations_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_evaluations_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقييمات موحدة (تدريس + سلوك)';

-- -----------------------------------------------------------
-- 2.5 eqi_results_new (replacement for eqi_calculations, merges weights + context)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `eqi_results_new`;
CREATE TABLE `eqi_results_new` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `academic_score` DECIMAL(5,2) DEFAULT NULL,
    `teaching_score` DECIMAL(5,2) DEFAULT NULL,
    `climate_score` DECIMAL(5,2) DEFAULT NULL,
    `behavior_score` DECIMAL(5,2) DEFAULT NULL,
    `skills_score` DECIMAL(5,2) DEFAULT NULL,
    `attendance_score` DECIMAL(5,2) DEFAULT NULL,
    `overall_eqi` DECIMAL(5,2) DEFAULT NULL,
    `weights_json` JSON DEFAULT NULL COMMENT '{"academic":0.30,"teaching":0.25,...}',
    `context_json` JSON DEFAULT NULL COMMENT '{"location_type":"rural","ratio":25.5,...}',
    `challenge_index` DECIMAL(5,2) DEFAULT NULL,
    `adjusted_eqi` DECIMAL(5,2) DEFAULT NULL,
    `missing_dimensions` JSON DEFAULT NULL,
    `calculated_by` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_eqi_results_new` (`school_id`, `academic_year_id`, `semester_id`),
    KEY `idx_eqi_results_new_overall` (`overall_eqi`),
    KEY `idx_eqi_results_new_active` (`is_active`),
    CONSTRAINT `fk_eqi_results_new_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_results_new_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_results_new_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_eqi_results_new_calculated_by` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نتائج مؤشر جودة التعليم الموحد';

-- -----------------------------------------------------------
-- 2.6 alerts_new (merges alerts + alert_notifications + absence_notifications)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `alerts_new`;
CREATE TABLE `alerts_new` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'لإشعارات مستخدم محدد',
    `alert_type` ENUM('academic','behavior','attendance','eqi','promotion','climate','teaching','system','reminder') NOT NULL,
    `severity` ENUM('info','warning','danger','critical') NOT NULL DEFAULT 'warning',
    `title_ar` VARCHAR(200) NOT NULL,
    `message_ar` TEXT NOT NULL,
    `threshold_json` JSON DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME DEFAULT NULL,
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_by` INT UNSIGNED DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_new_school` (`school_id`),
    KEY `idx_alerts_new_type_severity` (`alert_type`, `severity`),
    KEY `idx_alerts_new_read_resolved` (`is_read`, `is_resolved`),
    CONSTRAINT `fk_alerts_new_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_alerts_new_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_alerts_new_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_alerts_new_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات موحدة';

-- -----------------------------------------------------------
-- 2.7 promotions (merges promotion_decisions + promotion_overrides)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `promotions`;
CREATE TABLE `promotions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `semester_id` INT UNSIGNED NOT NULL,
    `academic_score` DECIMAL(5,2) DEFAULT NULL,
    `behavior_score` DECIMAL(5,2) DEFAULT NULL,
    `attendance_score` DECIMAL(5,2) DEFAULT NULL,
    `academic_pass` TINYINT(1) DEFAULT NULL,
    `behavior_pass` TINYINT(1) DEFAULT NULL,
    `attendance_pass` TINYINT(1) DEFAULT NULL,
    `decision` ENUM('promoted','repeated','conditional','under_review') NOT NULL,
    `status` ENUM('proposed','approved','rejected') NOT NULL DEFAULT 'proposed',
    `override_json` JSON DEFAULT NULL COMMENT '{"criterion":"academic","reason":"...","approved_by":1}',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `decided_by` INT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_promotions` (`student_id`, `academic_year_id`, `semester_id`),
    KEY `idx_promotions_decision` (`decision`),
    KEY `idx_promotions_status` (`status`),
    KEY `idx_promotions_active` (`is_active`),
    CONSTRAINT `fk_promotions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_promotions_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotions_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_promotions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_promotions_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قرارات الترقية الموحدة';

-- -----------------------------------------------------------
-- 2.8 improvement_plans_new (merges improvement_plans + school_improvement_plans + teacher_development_plans)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `improvement_plans_new`;
CREATE TABLE `improvement_plans_new` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_type` ENUM('student','school','teacher') NOT NULL,
    `student_id` INT UNSIGNED DEFAULT NULL,
    `school_id` INT UNSIGNED DEFAULT NULL,
    `teacher_id` INT UNSIGNED DEFAULT NULL,
    `trigger_type` ENUM('climate','facility','both','low_score','behavior') DEFAULT NULL,
    `weaknesses` TEXT DEFAULT NULL,
    `goals` TEXT DEFAULT NULL,
    `strategies` TEXT DEFAULT NULL,
    `timeline` VARCHAR(200) DEFAULT NULL,
    `responsible_user_id` INT UNSIGNED DEFAULT NULL,
    `parent_involvement` TEXT DEFAULT NULL,
    `status` ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    `progress` INT NOT NULL DEFAULT 0,
    `follow_up_date` DATE DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_imp_plans_type` (`plan_type`),
    KEY `idx_imp_plans_student` (`student_id`),
    KEY `idx_imp_plans_school` (`school_id`),
    KEY `idx_imp_plans_teacher` (`teacher_id`),
    KEY `idx_imp_plans_status` (`status`),
    KEY `idx_imp_plans_active` (`is_active`),
    CONSTRAINT `fk_imp_plans_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_imp_plans_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_imp_plans_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_imp_plans_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_imp_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_imp_plans_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='خطط التحسين الموحدة';

-- -----------------------------------------------------------
-- 2.9 activity_logs_new (merges 6 log tables)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs_new`;
CREATE TABLE `activity_logs_new` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `details_json` JSON DEFAULT NULL COMMENT 'تفاصيل التغيير',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_act_logs_user_action` (`user_id`, `action`),
    KEY `idx_act_logs_entity` (`entity_type`, `entity_id`),
    KEY `idx_act_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل النشاط الموحد';

-- -----------------------------------------------------------
-- 2.10 user_preferences (merges dashboard_preferences + notification_preferences)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `pref_type` ENUM('dashboard','notification','general') NOT NULL,
    `pref_key` VARCHAR(100) NOT NULL,
    `pref_value` JSON DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_preferences` (`user_id`, `pref_type`, `pref_key`),
    KEY `idx_user_pref_type` (`pref_type`),
    KEY `idx_user_pref_active` (`is_active`),
    CONSTRAINT `fk_user_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفضيلات المستخدم الموحدة';

-- -----------------------------------------------------------
-- 2.11 parent_students_new (merges parent_students + parent_acknowledgments)
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `parent_students_new`;
CREATE TABLE `parent_students_new` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `relationship` ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'father',
    `ack_grade` TINYINT(1) DEFAULT NULL,
    `ack_behavior` TINYINT(1) DEFAULT NULL,
    `ack_attendance` TINYINT(1) DEFAULT NULL,
    `ack_promotion` TINYINT(1) DEFAULT NULL,
    `last_ack_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_parent_students_new` (`parent_id`, `student_id`),
    KEY `idx_parent_students_new_student` (`student_id`),
    KEY `idx_parent_students_new_active` (`is_active`),
    CONSTRAINT `fk_parent_students_new_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_parent_students_new_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='روابط أولياء الأمور الموحدة';

-- -----------------------------------------------------------
-- 2.12 Modify attendance_daily (add period_number)
-- -----------------------------------------------------------
ALTER TABLE `attendance_daily` 
    ADD COLUMN IF NOT EXISTS `period_number` TINYINT UNSIGNED DEFAULT NULL COMMENT 'رقم الحصة (NULL = يومي)' AFTER `date`,
    DROP INDEX IF EXISTS `uk_attendance_daily`,
    ADD UNIQUE KEY `uk_attendance_daily` (`student_id`, `class_id`, `date`, `period_number`);

-- Also add amendment_log JSON column
ALTER TABLE `attendance_daily` 
    ADD COLUMN IF NOT EXISTS `amendment_log` JSON DEFAULT NULL COMMENT 'سجل التعديلات' AFTER `notes`;

-- -----------------------------------------------------------
-- 2.13 Modify subject_grade_years (add weights_json)
-- -----------------------------------------------------------
ALTER TABLE `subject_grade_years`
    ADD COLUMN IF NOT EXISTS `weights_json` JSON DEFAULT NULL COMMENT '{"quiz":20,"midterm":30,"final":50}' AFTER `passing_grade`;

-- -----------------------------------------------------------
-- 2.14 Modify student_enrollments (merge grade_years + parent_students data)
-- -----------------------------------------------------------
ALTER TABLE `student_enrollments`
    ADD COLUMN IF NOT EXISTS `status` ENUM('enrolled','transferred','dropped','graduated') NOT NULL DEFAULT 'enrolled' COMMENT 'حالة التسجيل' AFTER `academic_year_id`;

-- ============================================================================
-- PHASE 3: Migrate Data
-- ============================================================================

-- -----------------------------------------------------------
-- 3.1 Migrate assessment_criteria → assessment_structure
-- -----------------------------------------------------------
INSERT INTO `assessment_structure` (id, form_id, parent_id, type, name_ar, name_en, text_ar, text_en, tool_type, weight, is_inverse, max_value, sort_order, is_active)
SELECT id, form_id, NULL, 'criteria', name_ar, name_en, NULL, NULL, NULL, weight, 0, NULL, sort_order, is_active
FROM `assessment_criteria`
WHERE id NOT IN (SELECT id FROM `assessment_structure`);

-- -----------------------------------------------------------
-- 3.2 Migrate assessment_items → assessment_structure
-- -----------------------------------------------------------
INSERT INTO `assessment_structure` (id, form_id, parent_id, type, name_ar, name_en, text_ar, text_en, tool_type, weight, is_inverse, max_value, sort_order, is_active)
SELECT id, form_id, criteria_id, 'item', LEFT(text_ar, 200), LEFT(text_en, 200), text_ar, text_en, tool_type, weight, is_inverse, max_value, sort_order, is_active
FROM `assessment_items`
WHERE id NOT IN (SELECT id FROM `assessment_structure`);

-- Reset auto_increment
ALTER TABLE `assessment_structure` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `assessment_structure`) as t);

-- -----------------------------------------------------------
-- 3.3 Migrate climate_surveys → surveys
-- -----------------------------------------------------------
INSERT INTO `surveys` (id, survey_type, school_id, academic_year_id, semester_id, respondent_type, respondent_id, evaluator_id, survey_date, overall_score, notes, status, is_active, created_by, created_at)
SELECT id, 'climate', school_id, academic_year_id, semester_id, respondent_type, respondent_id, NULL, survey_date, overall_score, notes, 'closed', is_active, respondent_id, NOW()
FROM `climate_surveys`
WHERE id NOT IN (SELECT id FROM `surveys`);

-- -----------------------------------------------------------
-- 3.4 Migrate facility_checklists → surveys
-- -----------------------------------------------------------
INSERT INTO `surveys` (id, survey_type, school_id, academic_year_id, semester_id, respondent_type, respondent_id, evaluator_id, survey_date, overall_score, notes, status, is_active, created_by, created_at)
SELECT id, 'facility', school_id, academic_year_id, semester_id, NULL, NULL, evaluator_id, evaluation_date, overall_score, notes, 'closed', is_active, NULL, NOW()
FROM `facility_checklists`
WHERE id NOT IN (SELECT id FROM `surveys`);

ALTER TABLE `surveys` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `surveys`) as t);

-- -----------------------------------------------------------
-- 3.5 Migrate climate_survey_items → survey_items
-- -----------------------------------------------------------
INSERT INTO `survey_items` (id, survey_id, item_id, numeric_value, normalized_value, is_active)
SELECT id, survey_id, item_id, numeric_value, normalized_value, is_active
FROM `climate_survey_items`
WHERE id NOT IN (SELECT id FROM `survey_items`);

-- -----------------------------------------------------------
-- 3.6 Migrate facility_checklist_items → survey_items
-- -----------------------------------------------------------
INSERT INTO `survey_items` (id, survey_id, item_id, numeric_value, normalized_value, is_active)
SELECT id, checklist_id as survey_id, item_id, numeric_value, normalized_value, is_active
FROM `facility_checklist_items`
WHERE id NOT IN (SELECT id FROM `survey_items`);

ALTER TABLE `survey_items` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `survey_items`) as t);

-- -----------------------------------------------------------
-- 3.7 Migrate teaching_evaluation_summary → evaluations
-- -----------------------------------------------------------
INSERT INTO `evaluations` (id, eval_type, teacher_id, student_id, class_id, school_id, academic_year_id, semester_id, self_score, peer_score, admin_score, combined_score, adjusted_score, is_active, created_at, updated_at)
SELECT id, 'teaching_summary', teacher_id, NULL, NULL, 
    (SELECT school_id FROM users WHERE id = tes.teacher_id LIMIT 1),
    academic_year_id, semester_id, self_score, peer_score, admin_score, combined_score, adjusted_score, is_active, created_at, created_at
FROM `teaching_evaluation_summary` tes
WHERE id NOT IN (SELECT id FROM `evaluations`);

-- -----------------------------------------------------------
-- 3.8 Migrate behavior_scores → evaluations
-- -----------------------------------------------------------
INSERT INTO `evaluations` (id, eval_type, teacher_id, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, starting_points, earned_points, deducted_points, current_percentage, is_passing, is_active, created_at, updated_at)
SELECT id, 'behavior_score', NULL, student_id, class_id,
    (SELECT school_id FROM classes WHERE id = bs.class_id LIMIT 1),
    academic_year_id, semester_id, current_percentage, starting_points, earned_points, deducted_points, current_percentage, is_passing, is_active, updated_at, updated_at
FROM `behavior_scores` bs
WHERE id NOT IN (SELECT id FROM `evaluations`);

ALTER TABLE `evaluations` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `evaluations`) as t);

-- -----------------------------------------------------------
-- 3.9 Migrate eqi_calculations → eqi_results_new
-- -----------------------------------------------------------
INSERT INTO `eqi_results_new` (id, school_id, academic_year_id, semester_id, academic_score, teaching_score, climate_score, behavior_score, skills_score, attendance_score, overall_eqi, missing_dimensions, calculated_by, is_active, created_at)
SELECT id, school_id, academic_year_id, semester_id, academic_score, teaching_score, climate_score, behavior_score, skills_score, attendance_score, overall_eqi, missing_dimensions, calculated_by, is_active, calculated_at
FROM `eqi_calculations`
WHERE id NOT IN (SELECT id FROM `eqi_results_new`);

-- Merge weights from eqi_dimension_weights
UPDATE `eqi_results_new` er SET er.weights_json = (
    SELECT JSON_OBJECT(
        'academic', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='academic'), 30),
        'teaching', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='teaching'), 25),
        'climate', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='climate'), 15),
        'behavior', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='behavior'), 10),
        'skills', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='skills'), 10),
        'attendance', COALESCE((SELECT default_weight FROM eqi_dimension_weights WHERE dimension_name='attendance'), 10)
    )
) WHERE er.weights_json IS NULL;

-- Merge context from school_context
UPDATE `eqi_results_new` er JOIN `school_context` sc ON er.school_id = sc.school_id AND er.academic_year_id = sc.academic_year_id
SET er.context_json = JSON_OBJECT(
    'location_type', sc.location_type,
    'total_students', sc.total_students,
    'student_teacher_ratio', sc.student_teacher_ratio,
    'socioeconomic_index', sc.socioeconomic_index,
    'parent_education_level', sc.parent_education_level,
    'special_needs_pct', sc.students_with_special_needs_pct,
    'infrastructure_score', sc.infrastructure_score,
    'mobility_rate', sc.student_mobility_rate
),
er.challenge_index = sc.challenge_index
WHERE er.context_json IS NULL;

ALTER TABLE `eqi_results_new` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `eqi_results_new`) as t);

-- -----------------------------------------------------------
-- 3.10 Migrate existing alerts → alerts_new
-- -----------------------------------------------------------
INSERT INTO `alerts_new` (id, school_id, student_id, user_id, alert_type, severity, title_ar, message_ar, is_read, read_at, is_resolved, resolved_by, resolved_at, notes, created_at)
SELECT id, school_id, student_id, NULL, alert_type, severity, title_ar, message_ar, is_read, read_at, is_resolved, resolved_by, resolved_at, notes, created_at
FROM `alerts`
WHERE id NOT IN (SELECT id FROM `alerts_new`);

-- Migrate alert_notifications → alerts_new (skipped: table doesn't exist in production)
-- Migrate absence_notifications → alerts_new (skipped: table doesn't exist in production)

ALTER TABLE `alerts_new` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `alerts_new`) as t);

-- -----------------------------------------------------------
-- 3.11 Migrate promotion_decisions → promotions
-- -----------------------------------------------------------
INSERT INTO `promotions` (id, student_id, academic_year_id, semester_id, academic_score, behavior_score, attendance_score, academic_pass, behavior_pass, attendance_pass, decision, status, approved_by, approved_at, rejection_reason, notes, decided_by, is_active, created_at)
SELECT id, student_id, academic_year_id, semester_id, academic_score, behavior_score, attendance_score, academic_pass, behavior_pass, attendance_pass, decision, status, approved_by, approved_at, rejection_reason, notes, decided_by, is_active, decided_at
FROM `promotion_decisions`
WHERE id NOT IN (SELECT id FROM `promotions`);

-- Migrate promotion_overrides → promotions.override_json
UPDATE `promotions` p JOIN `promotion_overrides` po ON p.student_id = po.student_id AND p.academic_year_id = po.academic_year_id AND p.semester_id = po.semester_id
SET p.override_json = JSON_OBJECT('criterion', po.criterion, 'reason', po.override_reason, 'approved_by', po.approved_by, 'approved_at', po.approved_at)
WHERE p.override_json IS NULL;

ALTER TABLE `promotions` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `promotions`) as t);

-- -----------------------------------------------------------
-- 3.12 Migrate improvement_plans → improvement_plans_new
-- -----------------------------------------------------------
INSERT INTO `improvement_plans_new` (plan_type, student_id, school_id, teacher_id, trigger_type, weaknesses, goals, strategies, timeline, responsible_user_id, parent_involvement, status, progress, follow_up_date, notes, created_by, updated_by, is_active, created_at, updated_at)
SELECT 'student', student_id, NULL, NULL, NULL, weaknesses, goals, strategies, timeline, responsible_user_id, parent_involvement, status, 0, follow_up_date, notes, created_by, updated_by, is_active, created_at, updated_at
FROM `improvement_plans`
WHERE NOT EXISTS (SELECT 1 FROM `improvement_plans_new` WHERE plan_type='student' AND student_id = `improvement_plans`.student_id AND created_at = `improvement_plans`.created_at);

-- Migrate school_improvement_plans → improvement_plans_new
INSERT INTO `improvement_plans_new` (plan_type, student_id, school_id, teacher_id, trigger_type, weaknesses, goals, strategies, timeline, responsible_user_id, parent_involvement, status, progress, follow_up_date, notes, created_by, updated_by, is_active, created_at, updated_at)
SELECT 'school', NULL, school_id, NULL, 'both', weaknesses, goals, strategies, timeline, responsible_user_id, NULL, status, progress, NULL, notes, created_by, updated_by, is_active, created_at, updated_at
FROM `school_improvement_plans`
WHERE NOT EXISTS (SELECT 1 FROM `improvement_plans_new` WHERE plan_type='school' AND school_id = `school_improvement_plans`.school_id AND created_at = `school_improvement_plans`.created_at);

-- Migrate teacher_development_plans → improvement_plans_new
INSERT INTO `improvement_plans_new` (plan_type, student_id, school_id, teacher_id, trigger_type, weaknesses, goals, strategies, timeline, responsible_user_id, parent_involvement, status, progress, follow_up_date, notes, created_by, updated_by, is_active, created_at, updated_at)
SELECT 'teacher', NULL, (SELECT school_id FROM users WHERE id = tdp.teacher_id LIMIT 1), teacher_id, 'low_score', weaknesses_ar, goals_ar, strategies_ar, timeline, created_by, NULL, status, 0, follow_up_date, NULL, created_by, NULL, is_active, created_at, updated_at
FROM `teacher_development_plans` tdp
WHERE NOT EXISTS (SELECT 1 FROM `improvement_plans_new` WHERE plan_type='teacher' AND teacher_id = tdp.teacher_id AND created_at = tdp.created_at);

-- -----------------------------------------------------------
-- 3.13 Migrate activity_logs → activity_logs_new
-- -----------------------------------------------------------
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, ip_address, user_agent, created_at)
SELECT user_id, action, entity_type, entity_id, description, ip_address, user_agent, created_at
FROM `activity_logs`;

-- Migrate role_change_log → activity_logs_new
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, details_json, created_at)
SELECT changed_by, 'role_change', 'users', user_id, 
    CONCAT('تغيير دور المستخدم من ', COALESCE((SELECT name FROM roles WHERE id = old_role_id), 'NULL'), ' إلى ', COALESCE((SELECT name FROM roles WHERE id = new_role_id), 'NULL')),
    JSON_OBJECT('old_role_id', old_role_id, 'new_role_id', new_role_id, 'reason', reason),
    created_at
FROM `role_change_log`;

-- Migrate grade_change_log → activity_logs_new
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, details_json, created_at)
SELECT changed_by, 'grade_change', 'grades', grade_id,
    CONCAT('تغيير الدرجة من ', old_value, ' إلى ', new_value),
    JSON_OBJECT('old_value', old_value, 'new_value', new_value, 'reason', reason),
    created_at
FROM `grade_change_log`;

-- Migrate promotion_change_log → activity_logs_new
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, details_json, created_at)
SELECT changed_by, 'promotion_change', 'promotions', decision_id,
    CONCAT('تغيير قرار الترقية من ', old_decision, ' إلى ', new_decision),
    JSON_OBJECT('old_decision', old_decision, 'new_decision', new_decision, 'reason', reason),
    created_at
FROM `promotion_change_log`;

-- Migrate export_log → activity_logs_new
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, details_json, created_at)
SELECT user_id, 'export', export_type, NULL, CONCAT('تصدير: ', file_name),
    JSON_OBJECT('file_name', file_name, 'file_size', file_size, 'row_count', row_count, 'filters', filters),
    created_at
FROM `export_logs`;

-- Migrate backup_logs → activity_logs_new
INSERT INTO `activity_logs_new` (user_id, action, entity_type, entity_id, description, details_json, created_at)
SELECT created_by, 'backup', backup_type, NULL, CONCAT('نسخ احتياطي: ', file_path),
    JSON_OBJECT('file_path', file_path, 'file_size', file_size, 'tables_count', tables_count, 'rows_count', rows_count),
    created_at
FROM `backup_logs`;

-- -----------------------------------------------------------
-- 3.14 Migrate dashboard_preferences → user_preferences
-- -----------------------------------------------------------
INSERT INTO `user_preferences` (user_id, pref_type, pref_key, pref_value, is_active, created_at)
SELECT user_id, 'dashboard', widget_type, config, is_visible, created_at
FROM `dashboard_widgets`;

-- -----------------------------------------------------------
-- 3.15 Migrate notification_preferences → user_preferences
-- -----------------------------------------------------------
INSERT INTO `user_preferences` (user_id, pref_type, pref_key, pref_value, is_active, created_at, updated_at)
SELECT user_id, 'notification', notification_type, JSON_OBJECT('enabled', is_enabled), is_active, created_at, updated_at
FROM `notification_preferences`;

-- -----------------------------------------------------------
-- 3.16 Migrate parent_students → parent_students_new
-- -----------------------------------------------------------
INSERT INTO `parent_students_new` (id, parent_id, student_id, relationship, ack_grade, ack_behavior, ack_attendance, ack_promotion, last_ack_at, is_active, created_at)
SELECT id, parent_id, student_id, relationship, NULL, NULL, NULL, NULL, NULL, is_active, created_at
FROM `parent_students`
WHERE id NOT IN (SELECT id FROM `parent_students_new`);

-- Migrate parent_acknowledgments → parent_students_new
UPDATE `parent_students_new` psn JOIN `parent_acknowledgments` pa ON psn.parent_id = pa.parent_id AND psn.student_id = pa.student_id
SET psn.ack_grade = CASE WHEN pa.ack_type = 'grade' THEN pa.is_acknowledged ELSE psn.ack_grade END,
    psn.ack_behavior = CASE WHEN pa.ack_type = 'behavior' THEN pa.is_acknowledged ELSE psn.ack_behavior END,
    psn.ack_attendance = CASE WHEN pa.ack_type = 'attendance' THEN pa.is_acknowledged ELSE psn.ack_attendance END,
    psn.ack_promotion = CASE WHEN pa.ack_type = 'promotion' THEN pa.is_acknowledged ELSE psn.ack_promotion END,
    psn.last_ack_at = pa.acknowledged_at
WHERE pa.is_acknowledged = 1;

ALTER TABLE `parent_students_new` AUTO_INCREMENT = (SELECT MAX(id) + 1 FROM (SELECT MAX(id) as id FROM `parent_students_new`) as t);

-- -----------------------------------------------------------
-- 3.17 Store assessment_types + subject_weight_distribution in system_settings
-- -----------------------------------------------------------
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, sort_order, is_active, created_at)
SELECT 'academic', 'assessment_types', 
    (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'name_ar', name_ar, 'name_en', name_en, 'code', code, 'weight_default', weight_default, 'sort_order', sort_order)) FROM assessment_types),
    'json', 'أنواع التقييم', 'قائمة أنواع التقييم الأكاديمي', 1, 100, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'assessment_types');

-- Store grade_scale in system_settings
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, sort_order, is_active, created_at)
SELECT 'academic', 'grade_scale',
    (SELECT JSON_ARRAYAGG(JSON_OBJECT('min_percentage', min_percentage, 'max_percentage', max_percentage, 'letter_grade', letter_grade, 'grade_point', grade_point, 'description_ar', description_ar)) FROM grade_scale),
    'json', 'مقياس الدرجات', 'جدول تحويل النسب إلى تقديرات', 1, 101, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'grade_scale');

-- Store attendance_settings in system_settings
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, school_id, sort_order, is_active, created_at)
SELECT 'attendance', CONCAT('settings_', school_id),
    JSON_OBJECT('min_attendance_rate', min_attendance_rate, 'late_count_as_absent', late_count_as_absent, 'consecutive_absence_alert', consecutive_absence_alert),
    'json', 'إعدادات الحضور', 'إعدادات الحضور للمدرسة', 0, school_id, 102, is_active, NOW()
FROM `attendance_settings`
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = CONCAT('settings_', attendance_settings.school_id));

-- Store alert_settings in system_settings
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, school_id, sort_order, is_active, created_at)
SELECT 'alerts', CONCAT('settings_', school_id, '_', alert_type),
    JSON_OBJECT('is_enabled', is_enabled, 'threshold_value', threshold_value, 'severity', severity),
    'json', 'إعدادات التنبيهات', CONCAT('إعدادات تنبيه ', alert_type), 0, school_id, 103, is_active, NOW()
FROM `alert_settings`
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = CONCAT('settings_', alert_settings.school_id, '_', alert_settings.alert_type));

-- Store school_settings in system_settings
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, school_id, sort_order, is_active, created_at)
SELECT 'school', CONCAT('override_', ss.setting_id),
    ss.override_value,
    'string', 'تجاوز إعداد المدرسة', 'إعداد خاص بالمدرسة', 0, ss.school_id, 104, ss.is_active, NOW()
FROM `school_settings` ss
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = CONCAT('override_', ss.setting_id));

-- Store behavior_categories in system_settings (global + per-school)
INSERT INTO `system_settings` (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, school_id, sort_order, is_active, created_at)
SELECT 'behavior', 
    CASE WHEN school_id IS NULL THEN 'categories_global' ELSE CONCAT('categories_', school_id) END,
    (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'name_ar', name_ar, 'name_en', name_en, 'type', type, 'points', points)) 
     FROM behavior_categories bc2 WHERE bc2.school_id <=> bc.school_id),
    'json', 'فئات السلوك', 'قائمة فئات السلوك', 0, school_id, 105, 1, NOW()
FROM (SELECT DISTINCT school_id FROM behavior_categories) bc
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = CASE WHEN bc.school_id IS NULL THEN 'categories_global' ELSE CONCAT('categories_', bc.school_id) END);

-- ============================================================================
-- PHASE 6: Rename Old Tables (safe deletion with rollback option)
-- ============================================================================

-- Rename old tables with old_ prefix (can be dropped later after verification)
RENAME TABLE IF EXISTS `assessment_criteria` TO `old_assessment_criteria`;
RENAME TABLE IF EXISTS `assessment_items` TO `old_assessment_items`;
RENAME TABLE IF EXISTS `climate_surveys` TO `old_climate_surveys`;
RENAME TABLE IF EXISTS `climate_survey_items` TO `old_climate_survey_items`;
RENAME TABLE IF EXISTS `facility_checklists` TO `old_facility_checklists`;
RENAME TABLE IF EXISTS `facility_checklist_items` TO `old_facility_checklist_items`;
RENAME TABLE IF EXISTS `teaching_evaluation_summary` TO `old_teaching_evaluation_summary`;
RENAME TABLE IF EXISTS `behavior_scores` TO `old_behavior_scores`;
RENAME TABLE IF EXISTS `eqi_calculations` TO `old_eqi_calculations`;
RENAME TABLE IF EXISTS `eqi_dimension_weights` TO `old_eqi_dimension_weights`;
RENAME TABLE IF EXISTS `school_context` TO `old_school_context`;
RENAME TABLE IF EXISTS `alerts` TO `old_alerts`;
RENAME TABLE IF EXISTS `alert_notifications` TO `old_alert_notifications`;
RENAME TABLE IF EXISTS `alert_settings` TO `old_alert_settings`;
RENAME TABLE IF EXISTS `absence_notifications` TO `old_absence_notifications`;
RENAME TABLE IF EXISTS `promotion_decisions` TO `old_promotion_decisions`;
RENAME TABLE IF EXISTS `promotion_overrides` TO `old_promotion_overrides`;
RENAME TABLE IF EXISTS `promotion_change_log` TO `old_promotion_change_log`;
RENAME TABLE IF EXISTS `improvement_plans` TO `old_improvement_plans`;
RENAME TABLE IF EXISTS `school_improvement_plans` TO `old_school_improvement_plans`;
RENAME TABLE IF EXISTS `teacher_development_plans` TO `old_teacher_development_plans`;
RENAME TABLE IF EXISTS `activity_logs` TO `old_activity_logs`;
RENAME TABLE IF EXISTS `role_change_log` TO `old_role_change_log`;
RENAME TABLE IF EXISTS `grade_change_log` TO `old_grade_change_log`;
RENAME TABLE IF EXISTS `export_logs` TO `old_export_logs`;
RENAME TABLE IF EXISTS `backup_logs` TO `old_backup_logs`;
RENAME TABLE IF EXISTS `dashboard_widgets` TO `old_dashboard_widgets`;
RENAME TABLE IF EXISTS `notification_preferences` TO `old_notification_preferences`;
RENAME TABLE IF EXISTS `parent_acknowledgments` TO `old_parent_acknowledgments`;
RENAME TABLE IF EXISTS `school_settings` TO `old_school_settings`;
RENAME TABLE IF EXISTS `attendance_period` TO `old_attendance_period`;
RENAME TABLE IF EXISTS `attendance_amendments` TO `old_attendance_amendments`;
RENAME TABLE IF EXISTS `attendance_settings` TO `old_attendance_settings`;
RENAME TABLE IF EXISTS `attendance_summaries` TO `old_attendance_summaries`;
RENAME TABLE IF EXISTS `student_subject_summary` TO `old_student_subject_summary`;
RENAME TABLE IF EXISTS `semester_scores` TO `old_semester_scores`;
RENAME TABLE IF EXISTS `grade_scale` TO `old_grade_scale`;
RENAME TABLE IF EXISTS `assessment_types` TO `old_assessment_types`;
RENAME TABLE IF EXISTS `subject_weight_distribution` TO `old_subject_weight_distribution`;
RENAME TABLE IF EXISTS `test_outcomes` TO `old_test_outcomes`;
RENAME TABLE IF EXISTS `learning_outcomes` TO `old_learning_outcomes`;
RENAME TABLE IF EXISTS `grade_years` TO `old_grade_years`;
RENAME TABLE IF EXISTS `parent_students` TO `old_parent_students`;
RENAME TABLE IF EXISTS `behavior_assessments` TO `old_behavior_assessments`;
RENAME TABLE IF EXISTS `behavior_assessment_items` TO `old_behavior_assessment_items`;
RENAME TABLE IF EXISTS `semester_status_log` TO `old_semester_status_log`;
RENAME TABLE IF EXISTS `school_skills_availability` TO `old_school_skills_availability`;
RENAME TABLE IF EXISTS `grade_sheet_snapshots` TO `old_grade_sheet_snapshots`;
RENAME TABLE IF EXISTS `error_log_dedup` TO `old_error_log_dedup`;

-- Rename new tables to final names
RENAME TABLE IF EXISTS `eqi_results_new` TO `eqi_results`;
RENAME TABLE IF EXISTS `alerts_new` TO `alerts`;
RENAME TABLE IF EXISTS `improvement_plans_new` TO `improvement_plans`;
RENAME TABLE IF EXISTS `activity_logs_new` TO `activity_logs`;
RENAME TABLE IF EXISTS `parent_students_new` TO `parent_students`;

-- ============================================================================
-- PHASE 7: Create Views
-- ============================================================================

DROP VIEW IF EXISTS `v_student_subject_summary`;
CREATE VIEW `v_student_subject_summary` AS
SELECT 
    sss.student_id,
    t.subject_id,
    t.class_id,
    t.academic_year_id,
    t.semester_id,
    SUM(g.grade_value) AS total_grade,
    SUM(g.max_value) AS max_grade,
    ROUND(SUM(g.grade_value) / NULLIF(SUM(g.max_value), 0) * 100, 2) AS percentage
FROM student_enrollments sss
JOIN tests t ON sss.class_id = t.class_id AND sss.academic_year_id = t.academic_year_id AND sss.semester_id = t.semester_id
JOIN grades g ON t.id = g.test_id AND sss.student_id = g.student_id
WHERE sss.status = 'enrolled' AND g.is_active = 1 AND t.status = 'published'
GROUP BY sss.student_id, t.subject_id, t.class_id, t.academic_year_id, t.semester_id;

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
    CASE WHEN SUM(status = 'present') / COUNT(*) * 100 < 75 THEN 1 ELSE 0 END AS is_below_threshold
FROM attendance_daily
WHERE is_active = 1 AND period_number IS NULL
GROUP BY student_id, class_id, academic_year_id, semester_id;

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

-- ============================================================================
-- Final: Re-enable foreign key checks
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Verification Queries (run these after migration)
-- ============================================================================
-- SELECT COUNT(*) as old_tables FROM information_schema.tables WHERE table_schema = 'eqas_db' AND table_name LIKE 'old_%';
-- SELECT COUNT(*) as new_tables FROM information_schema.tables WHERE table_schema = 'eqas_db' AND table_name NOT LIKE 'old_%' AND table_type = 'BASE TABLE';
-- SELECT 'assessment_structure' as tbl, COUNT(*) as cnt FROM assessment_structure UNION ALL SELECT 'surveys', COUNT(*) FROM surveys UNION ALL SELECT 'survey_items', COUNT(*) FROM survey_items UNION ALL SELECT 'evaluations', COUNT(*) FROM evaluations UNION ALL SELECT 'eqi_results', COUNT(*) FROM eqi_results UNION ALL SELECT 'alerts', COUNT(*) FROM alerts UNION ALL SELECT 'promotions', COUNT(*) FROM promotions UNION ALL SELECT 'improvement_plans', COUNT(*) FROM improvement_plans UNION ALL SELECT 'activity_logs', COUNT(*) FROM activity_logs UNION ALL SELECT 'user_preferences', COUNT(*) FROM user_preferences UNION ALL SELECT 'parent_students', COUNT(*) FROM parent_students;
