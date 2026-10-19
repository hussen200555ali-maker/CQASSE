-- ============================================================================
-- الترحيل إلى v4.1.0: إلغاء ربط school_context بالسنة الدراسية
-- ============================================================================
-- الهدف: جعل عوامل السياق المدرسي غير مرتبطة بفترة زمنية
-- بحيث يتم تعديلها يدوياً من قبل مدير المجموعة عند الحاجة.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. حذف المفتاح الفريد القديم (school_id, academic_year_id)
ALTER TABLE `school_context` DROP INDEX `uk_school_context`;

-- 2. دمج السجلات المكررة: الاحتفاظ بأحدث سجل لكل مدرسة
DELETE t1 FROM `school_context` t1
INNER JOIN `school_context` t2 
WHERE t1.`school_id` = t2.`school_id` 
  AND t1.`id` < t2.`id`
  AND t1.`academic_year_id` IS NOT NULL
  AND t2.`academic_year_id` IS NOT NULL;

-- 3. جعل academic_year_id يقبل NULL
ALTER TABLE `school_context` MODIFY `academic_year_id` INT(10) UNSIGNED DEFAULT NULL;

-- 4. إضافة مفتاح فريد جديد على school_id فقط
ALTER TABLE `school_context` ADD UNIQUE KEY `uk_school_context` (`school_id`);

SET FOREIGN_KEY_CHECKS = 1;
