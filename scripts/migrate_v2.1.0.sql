-- ============================================================================
-- EQAS v2.1.0 - وحدة المناخ المدرسي (Climate Module Enhancement)
-- تاريخ الترحيل: 2026-05-15
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 1. إضافة عمود updated_at إلى climate_surveys
-- -----------------------------------------------------------
ALTER TABLE `climate_surveys`
    ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تعديل'
    AFTER `is_active`;

-- -----------------------------------------------------------
-- 2. إنشاء جدول climate_items (الاسم الجديد) إذا لم يكن موجوداً
-- -----------------------------------------------------------
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
    KEY `idx_climate_items_active` (`is_active`),
    CONSTRAINT `fk_climate_items_survey` FOREIGN KEY (`survey_id`) REFERENCES `climate_surveys` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_climate_items_item` FOREIGN KEY (`item_id`) REFERENCES `assessment_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول عناصر استبيان المناخ (الاسم الجديد)';

-- -----------------------------------------------------------
-- 3. نقل البيانات من الجدول القديم إذا كان موجوداً والجديد فارغ
-- -----------------------------------------------------------
INSERT IGNORE INTO `climate_items` (`survey_id`, `item_id`, `numeric_value`, `normalized_value`, `is_active`)
SELECT `survey_id`, `item_id`, `numeric_value`, `normalized_value`, `is_active`
FROM `climate_survey_items`
WHERE NOT EXISTS (SELECT 1 FROM `climate_items` WHERE `climate_items`.`survey_id` = `climate_survey_items`.`survey_id` AND `climate_items`.`item_id` = `climate_survey_items`.`item_id`);

SET FOREIGN_KEY_CHECKS = 1;
