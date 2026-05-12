-- ==========================================================================
-- بيانات اختبارية لاختبارات النظام — EQAS
-- ==========================================================================
-- تحذير: هذه البيانات للاختبار فقط. لا تستخدم في الإنتاج.
-- ==========================================================================

-- مدرسة اختبارية
INSERT INTO `schools` (`id`, `name_ar`, `name_en`, `code`, `phone`, `address`, `is_active`, `created_at`) VALUES
(999, 'مدرسة الاختبار التجريبية', 'Test School', 'TST-999', '777777777', 'صنعاء - شارع الاختبار', 1, NOW())
ON DUPLICATE KEY UPDATE `name_ar` = VALUES(`name_ar`);

-- عام دراسي اختباري
INSERT INTO `academic_years` (`id`, `name`, `start_date`, `end_date`, `is_current`, `status`, `is_active`) VALUES
(999, 'عام اختباري 2026', '2026-01-01', '2026-12-31', 0, 'active', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- فصول دراسية اختبارية
INSERT INTO `semesters` (`id`, `academic_year_id`, `name_ar`, `start_date`, `end_date`, `is_current`, `lock_level`, `is_active`) VALUES
(998, 999, 'فصل اختباري أول', '2026-01-01', '2026-06-30', 1, 'none', 1),
(999, 999, 'فصل اختباري ثاني', '2026-07-01', '2026-12-31', 0, 'soft', 1)
ON DUPLICATE KEY UPDATE `name_ar` = VALUES(`name_ar`);

-- صفوف دراسية (Grade Levels)
INSERT INTO `grade_levels` (`id`, `name_ar`, `name_en`, `level`, `is_active`) VALUES
(999, 'الصف الأول', 'Grade 1', 1, 1)
ON DUPLICATE KEY UPDATE `name_ar` = VALUES(`name_ar`);

-- فصول (شعب) تابعة للمدرسة الاختبارية
INSERT INTO `classes` (`id`, `name_ar`, `academic_year_id`, `grade_level_id`, `school_id`, `capacity`, `is_active`) VALUES
(9991, 'شعبة أ', 999, 999, 999, 30, 1),
(9992, 'شعبة ب', 999, 999, 999, 30, 1)
ON DUPLICATE KEY UPDATE `name_ar` = VALUES(`name_ar`);

-- مستخدم school_admin اختباري (كلمة المرور: test1234)
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name_ar`, `email`, `role_id`, `school_id`, `is_active`) VALUES
(9991, 'test_school_admin', '$2y$10$cGtmPIJInHWpRpLZ3WLGNOx0Nxlqz6KrsORgBNIBWnTLiUptpDQry', 'مدير المدرسة الاختباري', 'school_admin@test.edu', 2, 999, 1)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);
