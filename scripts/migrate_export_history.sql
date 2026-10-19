-- ============================================================================
-- EQAS Database Migration - إضافة جدول export_history المفقود
-- ============================================================================
-- هذا السكريبت يُشغَّل على قواعد البيانات الحالية التي تفتقد جدول export_history
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- إضافة جدول export_history (سجل ملفات التصدير)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `export_history` (
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

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
