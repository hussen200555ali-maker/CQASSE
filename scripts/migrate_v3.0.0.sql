-- ============================================================================
-- EQAS v3.0.0 - إعادة هيكلة تقييم التدريس (Teaching Evaluation Refactor)
-- تاريخ الترحيل: 2026-05-16
--
-- التغييرات:
--   1. إلغاء الجداول الستة القديمة (teaching_self_evaluations, teaching_self_items,
--      teaching_peer_evaluations, teaching_peer_items, teaching_admin_evaluations,
--      teaching_admin_items)
--   2. جميع بيانات تقييم التدريس تُخزَّن في assessment_responses الجدول الموحد
--   3. تعريفات نماذج التقييم (forms/criteria/items) مضمّنة (idempotent)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 1. إضافة الأعمدة المفقودة إلى teaching_evaluation_summary (للتثبيتات القديمة)
-- -----------------------------------------------------------
ALTER TABLE `teaching_evaluation_summary`
    ADD COLUMN `adjusted_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'الدرجة بعد التعديل السياقي' AFTER `combined_score`,
    ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تحديث' AFTER `created_at`;

-- -----------------------------------------------------------
-- 2. ضمان وجود نماذج تقييم التدريس (idempotent)
-- -----------------------------------------------------------
INSERT IGNORE INTO `assessment_forms` (`id`, `form_key`, `name_ar`, `name_en`, `category`, `target`, `description`, `is_system`, `is_active`) VALUES
(3, 'teaching_self',    'التقييم الذاتي للمعلم',  'Teacher Self-Evaluation',       'teaching', 'teacher', 'تقييم المعلم لنفسه', 1, 1),
(4, 'teaching_peer',    'تقييم الأقران',          'Peer Evaluation',               'teaching', 'teacher', 'تقييم المعلم من قبل زملائه', 1, 1),
(5, 'teaching_admin',   'تقييم إداري للمعلم',     'Admin Teaching Evaluation',     'teaching', 'teacher', 'تقييم المعلم من قبل الإدارة', 1, 1);

-- -----------------------------------------------------------
-- 2. ضمان وجود معايير التقييم (idempotent)
-- -----------------------------------------------------------
INSERT IGNORE INTO `assessment_criteria` (`id`, `form_id`, `name_ar`, `name_en`, `sort_order`, `weight`, `is_active`) VALUES
-- التقييم الذاتي (form_id=3)
(7,  3, 'التخطيط والإعداد',   'Planning & Preparation',   1, 1.00, 1),
(8,  3, 'التنفيذ الصفي',      'Classroom Execution',      2, 1.00, 1),
-- تقييم الأقران (form_id=4)
(9,  4, 'التعاون المهني',     'Professional Collaboration',   1, 1.00, 1),
(10, 4, 'المساهمة في المدرسة', 'School Contribution',        2, 1.00, 1),
-- تقييم إداري (form_id=5)
(11, 5, 'جودة التدريس',       'Teaching Quality',        1, 1.00, 1),
(12, 5, 'الالتزام المهني',    'Professional Commitment', 2, 1.00, 1);

-- -----------------------------------------------------------
-- 3. ضمان وجود عناصر التقييم (idempotent)
-- -----------------------------------------------------------
INSERT IGNORE INTO `assessment_items` (`id`, `criteria_id`, `form_id`, `text_ar`, `tool_type`, `weight`, `sort_order`, `is_active`) VALUES
-- التقييم الذاتي - التخطيط والإعداد
(13, 7, 3, 'أخطط للدروس بشكل جيد ومنظم',               'likert_5', 1.00, 1, 1),
(14, 7, 3, 'أستخدم وسائل تعليمية متنوعة',              'likert_5', 1.00, 2, 1),
-- التقييم الذاتي - التنفيذ الصفي
(15, 8, 3, 'أشرك جميع الطلاب في الدرس',                'likert_5', 1.00, 3, 1),
(16, 8, 3, 'أستخدم أساليب تقييم متنوعة',               'likert_5', 1.00, 4, 1),
-- تقييم الأقران - التعاون المهني
(17, 9, 4, 'يتعاون مع الزملاء في تطوير المناهج',        'likert_5', 1.00, 1, 1),
(18, 9, 4, 'يشارك الخبرات والموارد التعليمية',          'likert_5', 1.00, 2, 1),
-- تقييم الأقران - المساهمة في المدرسة
(19, 10,4, 'يساهم في الأنشطة المدرسية واللاصفية',      'likert_5', 1.00, 3, 1),
(20, 10,4, 'يحافظ على سمعة المدرسة',                   'likert_5', 1.00, 4, 1),
-- التقييم الإداري - جودة التدريس
(21, 11,5, 'جودة المحتوى العلمي المقدم',               'likert_5', 1.00, 1, 1),
(22, 11,5, 'تنوع استراتيجيات التدريس',                 'likert_5', 1.00, 2, 1),
-- التقييم الإداري - الالتزام المهني
(23, 12,5, 'الالتزام بالدوام الرسمي',                  'likert_5', 1.00, 3, 1),
(24, 12,5, 'المشاركة في اجتماعات المدرسة',             'likert_5', 1.00, 4, 1);

-- -----------------------------------------------------------
-- 4. إلغاء الجداول الستة القديمة
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `teaching_self_items`;
DROP TABLE IF EXISTS `teaching_self_evaluations`;
DROP TABLE IF EXISTS `teaching_peer_items`;
DROP TABLE IF EXISTS `teaching_peer_evaluations`;
DROP TABLE IF EXISTS `teaching_admin_items`;
DROP TABLE IF EXISTS `teaching_admin_evaluations`;

SET FOREIGN_KEY_CHECKS = 1;
