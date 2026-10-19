-- ============================================================================
-- نظام التقييم الشامل لجودة التعليم المدرسي - EQAS
-- بيانات تجريبية اختيارية (Sample / Demo Data)
-- ============================================================================
-- ⚠️ هذا الملف اختياري — لا يُستورد تلقائياً في تثبيت الإنتاج.
--    يحتوي بيانات نموذجية (مستخدمين/مدرسة/عام دراسي/فصول) للتطوير والعرض.
--    بيانات التهيئة المرجعية (الأدوار، المحافظات، الصلاحيات، الدرجات...)
--    موجودة في database/seed.sql.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 1. المستخدم الإداري النموذجي (كلمة المرور: Admin@123 — يجب تغييرها)
-- ============================================================================
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name_ar`, `email`, `phone`, `role_id`, `school_id`, `is_active`, `must_change_password`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$12$LJ3m4yKQbNPv6oqFHVAMXeOXw8rKJ5Ka1QfRYR0xJ7sVCmkzHJW6q', 'مدير النظام الرئيسي', 'admin@eqas.ye', '+9671000000', 1, NULL, 1, 1, NOW(), NOW());

-- ============================================================================
-- 2. مدرسة نموذجية (Sample School)
-- ============================================================================
INSERT INTO `schools` (`id`, `name_ar`, `name_en`, `reference_code`, `address`, `governorate`, `district`, `school_type`, `principal_name`, `phone`, `email`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'مدرسة الأمل النموذجية', 'Al-Amal Model School', 'AM001', 'شارع الجمهورية - أمانة العاصمة', 'أمانة العاصمة', 'المعلا', 'combined', 'أحمد محمد العمري', '+9671234567', 'info@alamal.edu.ye', 1, NOW(), NOW());

-- ============================================================================
-- 3. السنة الدراسية والفصول النموذجية
-- ============================================================================
INSERT INTO `academic_years` (`id`, `name`, `start_date`, `end_date`, `is_current`, `status`, `is_active`, `created_at`) VALUES
(1, 'العام الدراسي 2025-2026', '2025-09-01', '2026-06-15', 1, 'active', 1, NOW());

INSERT INTO `semesters` (`id`, `academic_year_id`, `name_ar`, `start_date`, `end_date`, `is_current`, `lock_level`, `locked_at`, `locked_by`, `is_active`, `created_at`) VALUES
(1, 1, 'الفصل الدراسي الأول',  '2025-09-01', '2026-01-31', 1, 'none', NULL, NULL, 1, NOW()),
(2, 1, 'الفصل الدراسي الثاني', '2026-02-01', '2026-06-15', 0, 'none', NULL, NULL, 1, NOW());

-- ============================================================================
-- 4. إعدادات التنبيهات النموذجية (للمدرسة رقم 1)
-- ============================================================================
INSERT INTO `alert_settings` (`id`, `school_id`, `alert_type`, `is_enabled`, `threshold_value`, `severity`, `is_active`) VALUES
(1,  1, 'low_grade',                 1, 50.00, 'warning',  1),
(2,  1, 'failing_subject',           1, 50.00, 'danger',   1),
(3,  1, 'high_absence',              1, 25.00, 'danger',   1),
(4,  1, 'consecutive_absence',       1,  3.00, 'warning',  1),
(5,  1, 'poor_behavior',             1, 60.00, 'warning',  1),
(6,  1, 'eqi_decline',               0, 50.00, 'danger',   1),
(7,  1, 'promotion_risk',            1, 50.00, 'critical', 1),
(8,  1, 'attendance_below_threshold', 1, 15.00, 'info',    1),
(9,  1, 'behavior_below_threshold',  0, 50.00, 'warning',  1),
(10, 1, 'custom',                    0, NULL,  'info',     1);

SET FOREIGN_KEY_CHECKS = 1;
