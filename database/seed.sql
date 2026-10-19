-- ============================================================================
-- نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
-- بيانات التهيئة الأولية الكاملة
-- الإصدار: 2.0.0
-- تاريخ الإنشاء: 2025
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 1. الأدوار (Roles)
-- ============================================================================

INSERT INTO `roles` (`id`, `name`, `display_name_ar`, `display_name_en`, `rank`, `description`, `is_active`, `created_at`) VALUES
(1, 'group_admin',  'مدير النظام',  'Group Admin',  100, 'إدارة كاملة للنظام - جميع الصلاحيات',                          1, NOW()),
(2, 'school_admin', 'مدير المدرسة', 'School Admin', 80,  'إدارة المدرسة - تقييمات - تقارير - إشعارات',                   1, NOW()),
(3, 'teacher',      'معلم',         'Teacher',      60,  'إدخال درجات - سلوك - حضور - مهارات - ملاحظات',                 1, NOW()),
(4, 'parent',       'ولي أمر',      'Parent',       40,  'عرض - إقرار - إشعارات فقط',                                     1, NOW());

-- ============================================================================
-- 2. المحافظات ة (Governorates - 22 محافظة)
-- ============================================================================

INSERT INTO `governorates` (`id`, `name_ar`, `name_en`, `code`, `is_active`) VALUES
(1,  'أمانة العاصمة',  'Amanat Al Asimah', 'AS', 1),
(2,  'عدن',            'Aden',             'AD', 1),
(3,  'تعز',            'Taiz',             'TZ', 1),
(4,  'الحديدة',        'Al Hudaydah',      'HD', 1),
(5,  'إب',             'Ibb',              'IB', 1),
(6,  'حضرموت',         'Hadhramaut',       'HW', 1),
(7,  'المهرة',         'Al Mahrah',        'MR', 1),
(8,  'شبوة',           'Shabwah',          'SH', 1),
(9,  'أبين',           'Abyan',            'AB', 1),
(10, 'لحج',            'Lahij',            'LJ', 1),
(11, 'الضالع',         'Ad Dali',          'DA', 1),
(12, 'البيضاء',        'Al Bayda',         'BA', 1),
(13, 'مأرب',           'Marib',            'MA', 1),
(14, 'صعدة',           'Sadah',            'SD', 1),
(15, 'عمران',          'Amran',            'AM', 1),
(16, 'حجة',            'Hajjah',           'HJ', 1),
(17, 'ذمار',           'Dhamar',           'DH', 1),
(18, 'ريمة',           'Raymah',           'RA', 1),
(19, 'سقطرى',          'Socotra',          'SQ', 1),
(20, 'صنعاء',          'Sanaa',            'SN', 1),
(21, 'الجوف',          'Al Jawf',          'JW', 1),
(22, 'المهرة',         'Al Mahrah',        'MH', 1);

-- ============================================================================
-- 3. المستخدم الإداري الافتراضي (Admin User)
-- كلمة المرور: Admin@123
-- التجزئة bcrypt بقوة 12
-- ============================================================================

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name_ar`, `email`, `phone`, `role_id`, `school_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$12$LJ3m4yKQbNPv6oqFHVAMXeOXw8rKJ5Ka1QfRYR0xJ7sVCmkzHJW6q', 'مدير النظام الرئيسي', 'admin@eqas.ye', '+9671000000', 1, NULL, 1, NOW(), NOW());

-- ============================================================================
-- 4. مدرسة نموذجية (Sample School)
-- ============================================================================

INSERT INTO `schools` (`id`, `name_ar`, `name_en`, `reference_code`, `address`, `governorate`, `district`, `school_type`, `principal_name`, `phone`, `email`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'مدرسة الأمل النموذجية', 'Al-Amal Model School', 'AM001', 'شارع الجمهورية - أمانة العاصمة', 'أمانة العاصمة', 'المعلا', 'combined', 'أحمد محمد العمري', '+9671234567', 'info@alamal.edu.ye', 1, NOW(), NOW());

-- ============================================================================
-- 5. السنة الدراسية النموذجية (Sample Academic Year 2025-2026)
-- ============================================================================

INSERT INTO `academic_years` (`id`, `name`, `start_date`, `end_date`, `is_current`, `status`, `is_active`, `created_at`) VALUES
(1, 'العام الدراسي 2025-2026', '2025-09-01', '2026-06-15', 1, 'active', 1, NOW());

-- ============================================================================
-- 6. الفصول الدراسية (Semesters)
-- ============================================================================

INSERT INTO `semesters` (`id`, `academic_year_id`, `name_ar`, `start_date`, `end_date`, `is_current`, `lock_level`, `locked_at`, `locked_by`, `is_active`, `created_at`) VALUES
(1, 1, 'الفصل الدراسي الأول',  '2025-09-01', '2026-01-31', 1, 'none', NULL, NULL, 1, NOW()),
(2, 1, 'الفصل الدراسي الثاني', '2026-02-01', '2026-06-15', 0, 'none', NULL, NULL, 1, NOW());

-- ============================================================================
-- 7. المراحل الدراسية (Grade Levels - 12 صف)
-- ============================================================================

INSERT INTO `grade_levels` (`id`, `name_ar`, `name_en`, `level_number`, `stage`, `is_active`) VALUES
(1,  'الصف الأول',       'Grade 1',   1,  'basic',     1),
(2,  'الصف الثاني',      'Grade 2',   2,  'basic',     1),
(3,  'الصف الثالث',      'Grade 3',   3,  'basic',     1),
(4,  'الصف الرابع',      'Grade 4',   4,  'basic',     1),
(5,  'الصف الخامس',      'Grade 5',   5,  'basic',     1),
(6,  'الصف السادس',      'Grade 6',   6,  'basic',     1),
(7,  'الصف السابع',      'Grade 7',   7,  'basic',     1),
(8,  'الصف الثامن',      'Grade 8',   8,  'basic',     1),
(9,  'الصف التاسع',      'Grade 9',   9,  'basic',     1),
(10, 'الصف العاشر',      'Grade 10',  10, 'secondary',  1),
(11, 'الصف الحادي عشر',  'Grade 11',  11, 'secondary',  1),
(12, 'الصف الثاني عشر',  'Grade 12',  12, 'secondary',  1);

-- ============================================================================
-- 8. المواد الدراسية (Subjects - المنهج )
-- ============================================================================

INSERT INTO `subjects` (`id`, `name_ar`, `name_en`, `code`, `is_active`) VALUES
(1,  'الرياضيات',         'Mathematics',          'MATH', 1),
(2,  'اللغة العربية',     'Arabic Language',      'ARAB', 1),
(3,  'اللغة الإنجليزية',  'English Language',     'ENG',  1),
(4,  'العلوم',            'Science',              'SCI',  1),
(5,  'التربية الإسلامية', 'Islamic Education',    'ISLM', 1),
(6,  'التاريخ',           'History',              'HIST', 1),
(7,  'الجغرافيا',         'Geography',            'GEOG', 1),
(8,  'الفيزياء',          'Physics',              'PHYS', 1),
(9,  'الكيمياء',          'Chemistry',            'CHEM', 1),
(10, 'الأحياء',           'Biology',              'BIOL', 1),
(11, 'الحاسوب',           'Computer Science',     'COMP', 1),
(12, 'التربية الفنية',    'Art Education',        'ART',  1);

-- ============================================================================
-- 9. جدول ربط المواد بالصفوف (subject_grade_years)
-- ============================================================================

-- الرياضيات: الصف 1-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(1,  1, 5, 50.00, 1), (1,  2, 5, 50.00, 1), (1,  3, 5, 50.00, 1),
(1,  4, 5, 50.00, 1), (1,  5, 5, 50.00, 1), (1,  6, 5, 50.00, 1),
(1,  7, 4, 50.00, 1), (1,  8, 4, 50.00, 1), (1,  9, 4, 50.00, 1),
(1, 10, 4, 50.00, 1), (1, 11, 4, 50.00, 1), (1, 12, 4, 50.00, 1);

-- اللغة العربية: الصف 1-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(2,  1, 6, 50.00, 1), (2,  2, 6, 50.00, 1), (2,  3, 6, 50.00, 1),
(2,  4, 6, 50.00, 1), (2,  5, 5, 50.00, 1), (2,  6, 5, 50.00, 1),
(2,  7, 5, 50.00, 1), (2,  8, 5, 50.00, 1), (2,  9, 5, 50.00, 1),
(2, 10, 4, 50.00, 1), (2, 11, 4, 50.00, 1), (2, 12, 4, 50.00, 1);

-- اللغة الإنجليزية: الصف 5-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(3,  5, 3, 50.00, 1), (3,  6, 3, 50.00, 1),
(3,  7, 4, 50.00, 1), (3,  8, 4, 50.00, 1), (3,  9, 4, 50.00, 1),
(3, 10, 4, 50.00, 1), (3, 11, 4, 50.00, 1), (3, 12, 4, 50.00, 1);

-- العلوم: الصف 1-9 (علوم عامة)
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(4,  1, 3, 50.00, 1), (4,  2, 3, 50.00, 1), (4,  3, 3, 50.00, 1),
(4,  4, 4, 50.00, 1), (4,  5, 4, 50.00, 1), (4,  6, 4, 50.00, 1),
(4,  7, 4, 50.00, 1), (4,  8, 4, 50.00, 1), (4,  9, 4, 50.00, 1);

-- التربية الإسلامية: الصف 1-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(5,  1, 3, 50.00, 1), (5,  2, 3, 50.00, 1), (5,  3, 3, 50.00, 1),
(5,  4, 3, 50.00, 1), (5,  5, 3, 50.00, 1), (5,  6, 3, 50.00, 1),
(5,  7, 2, 50.00, 1), (5,  8, 2, 50.00, 1), (5,  9, 2, 50.00, 1),
(5, 10, 2, 50.00, 1), (5, 11, 2, 50.00, 1), (5, 12, 2, 50.00, 1);

-- التاريخ: الصف 4-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(6,  4, 2, 50.00, 1), (6,  5, 2, 50.00, 1), (6,  6, 2, 50.00, 1),
(6,  7, 2, 50.00, 1), (6,  8, 2, 50.00, 1), (6,  9, 2, 50.00, 1),
(6, 10, 2, 50.00, 1), (6, 11, 2, 50.00, 1), (6, 12, 2, 50.00, 1);

-- الجغرافيا: الصف 4-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(7,  4, 2, 50.00, 1), (7,  5, 2, 50.00, 1), (7,  6, 2, 50.00, 1),
(7,  7, 2, 50.00, 1), (7,  8, 2, 50.00, 1), (7,  9, 2, 50.00, 1),
(7, 10, 2, 50.00, 1), (7, 11, 2, 50.00, 1), (7, 12, 2, 50.00, 1);

-- الفيزياء: الصف 10-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(8, 10, 4, 50.00, 1), (8, 11, 4, 50.00, 1), (8, 12, 4, 50.00, 1);

-- الكيمياء: الصف 10-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(9, 10, 4, 50.00, 1), (9, 11, 4, 50.00, 1), (9, 12, 4, 50.00, 1);

-- الأحياء: الصف 10-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(10, 10, 3, 50.00, 1), (10, 11, 3, 50.00, 1), (10, 12, 3, 50.00, 1);

-- الحاسوب: الصف 5-12
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(11,  5, 2, 50.00, 1), (11,  6, 2, 50.00, 1),
(11,  7, 2, 50.00, 1), (11,  8, 2, 50.00, 1), (11,  9, 2, 50.00, 1),
(11, 10, 2, 50.00, 1), (11, 11, 2, 50.00, 1), (11, 12, 2, 50.00, 1);

-- التربية الفنية: الصف 1-9
INSERT INTO `subject_grade_years` (`subject_id`, `grade_level_id`, `hours_per_week`, `passing_grade`, `is_active`) VALUES
(12,  1, 2, 50.00, 1), (12,  2, 2, 50.00, 1), (12,  3, 2, 50.00, 1),
(12,  4, 2, 50.00, 1), (12,  5, 2, 50.00, 1), (12,  6, 2, 50.00, 1),
(12,  7, 1, 50.00, 1), (12,  8, 1, 50.00, 1), (12,  9, 1, 50.00, 1);

-- ============================================================================
-- 10. أنواع التقييم (Assessment Types)
-- ============================================================================

INSERT INTO `assessment_types` (`id`, `name_ar`, `name_en`, `code`, `weight_default`, `sort_order`, `is_active`) VALUES
(1, 'شهري',         'Monthly',     'monthly',  20.00, 1, 1),
(2, 'واجب',         'Homework',    'homework', 10.00, 2, 1),
(3, 'اختبار قصير',  'Quiz',        'quiz',     10.00, 3, 1),
(4, 'نهائي',        'Final',       'final',    50.00, 4, 1),
(5, 'مشروع',        'Project',     'project',  10.00, 5, 1);

-- ============================================================================
-- 11. مقياس الدرجات (Grade Scale - A+ to F)
-- ============================================================================

INSERT INTO `grade_scale` (`id`, `min_value`, `max_value`, `letter`, `gpa_value`, `name_ar`, `sort_order`, `min_percentage`, `max_percentage`, `letter_grade`, `grade_point`, `description_ar`, `is_active`) VALUES
(1,  95.00, 100.00, 'A+', 4.00, 'أداء متميز مرتفع - يتجاوز التوقعات',      1,  95.00, 100.00, 'A+', 4.00, 'أداء متميز مرتفع - يتجاوز التوقعات',      1),
(2,  90.00,  94.99, 'A',  3.75, 'أداء متميز - يلبي جميع التوقعات',         2,  90.00,  94.99, 'A',  3.75, 'أداء متميز - يلبي جميع التوقعات',         1),
(3,  85.00,  89.99, 'B+', 3.50, 'أداء جيد جداً مرتفع - فوق المتوسط',       3,  85.00,  89.99, 'B+', 3.50, 'أداء جيد جداً مرتفع - فوق المتوسط',       1),
(4,  80.00,  84.99, 'B',  3.00, 'أداء جيد جداً - يلبي معظم التوقعات',      4,  80.00,  84.99, 'B',  3.00, 'أداء جيد جداً - يلبي معظم التوقعات',      1),
(5,  75.00,  79.99, 'C+', 2.50, 'أداء جيد مرتفع - أعلى من المتوسط',        5,  75.00,  79.99, 'C+', 2.50, 'أداء جيد مرتفع - أعلى من المتوسط',        1),
(6,  70.00,  74.99, 'C',  2.00, 'أداء جيد - يلبي الحد الأدنى المطلوب',      6,  70.00,  74.99, 'C',  2.00, 'أداء جيد - يلبي الحد الأدنى المطلوب',      1),
(7,  65.00,  69.99, 'D+', 1.50, 'أداء مقبول مرتفع - يحتاج تحسين',          7,  65.00,  69.99, 'D+', 1.50, 'أداء مقبول مرتفع - يحتاج تحسين',          1),
(8,  60.00,  64.99, 'D',  1.00, 'أداء مقبول - الحد الأدنى للنجاح',         8,  60.00,  64.99, 'D',  1.00, 'أداء مقبول - الحد الأدنى للنجاح',         1),
(9,  50.00,  59.99, 'D-', 0.50, 'أداء مقبول منخفض - يحتاج متابعة مكثفة',  9,  50.00,  59.99, 'D-', 0.50, 'أداء مقبول منخفض - يحتاج متابعة مكثفة',  1),
(10,  0.00,  49.99, 'F',  0.00, 'أداء غير مقبول - لا يلبي الحد الأدنى',   10,   0.00,  49.99, 'F',  0.00, 'أداء غير مقبول - لا يلبي الحد الأدنى',   1);

-- ============================================================================
-- 12. أوزان أبعاد مؤشر جودة التعليم (EQI Dimension Weights - 6 أبعاد)
-- ============================================================================

INSERT INTO `eqi_dimension_weights` (`id`, `dimension_name`, `dimension_name_ar`, `default_weight`, `is_active`) VALUES
(1, 'academic',   'البعد الأكاديمي',    35.00, 1),
(2, 'teaching',   'بعد التدريس',        25.00, 1),
(3, 'climate',    'بعد المناخ المدرسي', 15.00, 1),
(4, 'behavior',   'بعد السلوك',          10.00, 1),
(5, 'skills',     'بعد المهارات',        10.00, 1),
(6, 'attendance', 'بعد الحضور',           5.00, 1);

-- ============================================================================
-- 13. الصلاحيات (Permissions - 36 صلاحية)
-- ============================================================================

INSERT INTO `permissions` (`id`, `name`, `name_ar`, `module`, `description`, `is_active`) VALUES
-- صلاحيات المستخدمين
(1,  'users.view',           'عرض المستخدمين',        'users',      'القدرة على عرض قائمة المستخدمين وبياناتهم',       1),
(2,  'users.add',            'إضافة مستخدم',          'users',      'القدرة على إنشاء مستخدم جديد في النظام',          1),
(3,  'users.edit',           'تعديل مستخدم',          'users',      'القدرة على تعديل بيانات المستخدمين',              1),
(4,  'users.delete',         'حذف مستخدم',            'users',      'القدرة على حذف مستخدم من النظام',                 1),
(5,  'users.reset_password', 'إعادة تعيين كلمة المرور','users',     'القدرة على إعادة تعيين كلمة مرور المستخدم',       1),
-- صلاحيات المدارس
(6,  'schools.view',         'عرض المدارس',           'schools',    'القدرة على عرض قائمة المدارس وبياناتها',          1),
(7,  'schools.add',          'إضافة مدرسة',           'schools',    'القدرة على إنشاء مدرسة جديدة في النظام',          1),
(8,  'schools.edit',         'تعديل مدرسة',           'schools',    'القدرة على تعديل بيانات المدارس',                 1),
(9,  'schools.delete',       'حذف مدرسة',             'schools',    'القدرة على حذف مدرسة من النظام',                  1),
-- صلاحيات العام الدراسي
(10, 'academic.view',        'عرض العام الدراسي',     'academic',   'القدرة على عرض بيانات العام الدراسي والفصول',     1),
(11, 'academic.add',         'إضافة عام دراسي',       'academic',   'القدرة على إنشاء عام دراسي جديد',                 1),
(12, 'academic.edit',        'تعديل عام دراسي',       'academic',   'القدرة على تعديل بيانات العام الدراسي',            1),
(13, 'academic.lock',        'قفل عام دراسي',         'academic',   'القدرة على قفل العام الدراسي أو الفصل الدراسي',    1),
-- صلاحيات التقييمات
(14, 'assessment.view',      'عرض التقييمات',         'assessment', 'القدرة على عرض التقييمات ونماذج التقييم',         1),
(15, 'assessment.add',       'إضافة تقييم',           'assessment', 'القدرة على إنشاء تقييم جديد',                     1),
(16, 'assessment.edit',      'تعديل تقييم',           'assessment', 'القدرة على تعديل التقييمات القائمة',              1),
(17, 'assessment.delete',    'حذف تقييم',             'assessment', 'القدرة على حذف تقييم من النظام',                  1),
-- صلاحيات الدرجات
(18, 'grades.view',          'عرض الدرجات',           'grades',     'القدرة على عرض درجات الطلاب',                     1),
(19, 'grades.add',           'إدخال درجات',           'grades',     'القدرة على إدخال درجات جديدة للطلاب',             1),
(20, 'grades.edit',          'تعديل درجات',           'grades',     'القدرة على تعديل درجات الطلاب المسجلة',           1),
-- صلاحيات الحضور
(21, 'attendance.view',      'عرض الحضور',            'attendance', 'القدرة على عرض سجلات حضور الطلاب',                1),
(22, 'attendance.add',       'تسجيل حضور',            'attendance', 'القدرة على تسجيل حضور وغياب الطلاب',              1),
(23, 'attendance.edit',      'تعديل حضور',            'attendance', 'القدرة على تعديل سجلات الحضور المسجلة',           1),
-- صلاحيات السلوك
(24, 'behavior.view',        'عرض السلوك',            'behavior',   'القدرة على عرض تقييمات السلوك للطلاب',            1),
(25, 'behavior.add',         'إضافة تقييم سلوك',      'behavior',   'القدرة على إضافة تقييم سلوكي للطالب',             1),
(26, 'behavior.edit',        'تعديل تقييم سلوك',      'behavior',   'القدرة على تعديل تقييمات السلوك المسجلة',         1),
-- صلاحيات التقارير
(27, 'reports.view',         'عرض التقارير',          'reports',    'القدرة على عرض التقارير والإحصائيات',              1),
(28, 'reports.export',       'تصدير التقارير',        'reports',    'القدرة على تصدير التقارير بصيغ مختلفة',           1),
-- صلاحيات الإعدادات
(29, 'settings.view',        'عرض الإعدادات',         'settings',   'القدرة على عرض إعدادات النظام',                    1),
(30, 'settings.edit',        'تعديل الإعدادات',       'settings',   'القدرة على تعديل إعدادات النظام',                  1),
-- صلاحيات النسخ الاحتياطي
(31, 'backup.view',          'عرض النسخ الاحتياطية',  'backup',     'القدرة على عرض سجلات النسخ الاحتياطي',            1),
(32, 'backup.create',        'إنشاء نسخة احتياطية',   'backup',     'القدرة على إنشاء نسخة احتياطية جديدة',            1),
(33, 'backup.restore',       'استعادة نسخة احتياطية', 'backup',     'القدرة على استعادة نسخة احتياطية سابقة',          1),
-- صلاحيات الترقية
(34, 'promotion.view',       'عرض قرارات الترقية',    'promotion',  'القدرة على عرض قرارات ترقية الطلاب',              1),
(35, 'promotion.evaluate',   'تقييم الترقية',         'promotion',  'القدرة على تقييم أهلية الطالب للترقية',           1),
(36, 'promotion.approve',    'اعتماد الترقية',        'promotion',  'القدرة على اعتماد قرار ترقية الطالب',             1);

-- ============================================================================
-- 14. صلاحيات الأدوار (Role Permissions)
-- ============================================================================

-- مدير النظام (group_admin) - جميع الصلاحيات
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_active`) VALUES
(1,  1, 1, 1), (2,  1, 2, 1), (3,  1, 3, 1), (4,  1, 4, 1), (5,  1, 5, 1),
(6,  1, 6, 1), (7,  1, 7, 1), (8,  1, 8, 1), (9,  1, 9, 1),
(10, 1, 10, 1), (11, 1, 11, 1), (12, 1, 12, 1), (13, 1, 13, 1),
(14, 1, 14, 1), (15, 1, 15, 1), (16, 1, 16, 1), (17, 1, 17, 1),
(18, 1, 18, 1), (19, 1, 19, 1), (20, 1, 20, 1),
(21, 1, 21, 1), (22, 1, 22, 1), (23, 1, 23, 1),
(24, 1, 24, 1), (25, 1, 25, 1), (26, 1, 26, 1),
(27, 1, 27, 1), (28, 1, 28, 1),
(29, 1, 29, 1), (30, 1, 30, 1),
(31, 1, 31, 1), (32, 1, 32, 1), (33, 1, 33, 1),
(34, 1, 34, 1), (35, 1, 35, 1), (36, 1, 36, 1);

-- مدير المدرسة (school_admin) - إدارة المدرسة والتقييمات والتقارير
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_active`) VALUES
(37, 2, 1, 1), (38, 2, 2, 1), (39, 2, 3, 1),                          -- users: view, add, edit
(40, 2, 6, 1), (41, 2, 8, 1),                                           -- schools: view, edit
(42, 2, 10, 1), (43, 2, 12, 1), (44, 2, 13, 1),                        -- academic: view, edit, lock
(45, 2, 14, 1), (46, 2, 15, 1), (47, 2, 16, 1), (48, 2, 17, 1),       -- assessment: all
(49, 2, 18, 1), (50, 2, 19, 1), (51, 2, 20, 1),                        -- grades: all
(52, 2, 21, 1), (53, 2, 22, 1), (54, 2, 23, 1),                        -- attendance: all
(55, 2, 24, 1), (56, 2, 25, 1), (57, 2, 26, 1),                        -- behavior: all
(58, 2, 27, 1), (59, 2, 28, 1),                                         -- reports: all
(60, 2, 29, 1), (61, 2, 30, 1),                                         -- settings: all
(62, 2, 31, 1), (63, 2, 32, 1),                                         -- backup: view, create
(64, 2, 34, 1), (65, 2, 35, 1), (66, 2, 36, 1);                        -- promotion: all

-- المعلم (teacher) - الدرجات والحضور والتقييمات
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_active`) VALUES
(67, 3, 1, 1),                                                           -- users: view
(68, 3, 10, 1),                                                          -- academic: view
(69, 3, 14, 1), (70, 3, 15, 1), (71, 3, 16, 1),                        -- assessment: view, add, edit
(72, 3, 18, 1), (73, 3, 19, 1), (74, 3, 20, 1),                        -- grades: all
(75, 3, 21, 1), (76, 3, 22, 1), (77, 3, 23, 1),                        -- attendance: all
(78, 3, 24, 1), (79, 3, 25, 1), (80, 3, 26, 1),                        -- behavior: all
(81, 3, 27, 1),                                                          -- reports: view
(82, 3, 34, 1);                                                          -- promotion: view

-- ولي الأمر (parent) - عرض فقط
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_active`) VALUES
(83, 4, 18, 1),    -- grades: view
(84, 4, 21, 1),    -- attendance: view
(85, 4, 24, 1),    -- behavior: view
(86, 4, 27, 1),    -- reports: view
(87, 4, 34, 1);    -- promotion: view

-- ============================================================================
-- 15. فئات السلوك (Behavior Categories - 10+ فئة)
-- ============================================================================

INSERT INTO `behavior_categories` (`id`, `name_ar`, `name_en`, `type`, `points`, `school_id`, `is_active`) VALUES
-- سلوكيات إيجابية (عامة - school_id = NULL)
(1,  'المشاركة الفعالة',        'Active Participation',     'positive',  5.00, NULL, 1),
(2,  'المساعدة',                'Helping Others',           'positive',  3.00, NULL, 1),
(3,  'الالتزام بالوقت',         'Punctuality',              'positive',  3.00, NULL, 1),
(4,  'التميز الأكاديمي',        'Academic Excellence',      'positive',  5.00, NULL, 1),
(5,  'السلوك الحسن',            'Good Conduct',             'positive',  2.00, NULL, 1),
(6,  'النظافة والعناية',        'Cleanliness & Care',       'positive',  2.00, NULL, 1),
-- سلوكيات سلبية (عامة - school_id = NULL)
(7,  'التأخر',                  'Tardiness',                'negative', -2.00, NULL, 1),
(8,  'الغياب غير المبرر',       'Unexcused Absence',        'negative', -5.00, NULL, 1),
(9,  'الإزعاج',                'Disruption',               'negative', -3.00, NULL, 1),
(10, 'عدم أداء الواجب',        'Incomplete Homework',      'negative', -2.00, NULL, 1),
(11, 'السلوك العدواني',         'Aggressive Behavior',      'negative',-10.00, NULL, 1),
(12, 'الغش',                   'Cheating',                 'negative', -8.00, NULL, 1),
(13, 'إتلاف الممتلكات',         'Property Damage',          'negative', -5.00, NULL, 1);

-- ============================================================================
-- 16. مخرجات التعلم (Learning Outcomes - نماذج للمواد الرئيسية)
-- ============================================================================

-- الرياضيات
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(1,  1, 'MATH-LO1', 'يحل المسائل الحسابية المتعلقة بالأعداد والعمليات الحسابية بدقة',             'Solves arithmetic problems related to numbers and operations accurately',             1),
(2,  1, 'MATH-LO2', 'يستخدم المهارات الهندسية في القياس والتصميم وحل المشكلات',                  'Uses geometric skills in measurement, design and problem solving',                    1),
(3,  1, 'MATH-LO3', 'يحلل البيانات الإحصائية ويفسرها ويتخذ قرارات مبنية عليها',                  'Analyzes and interprets statistical data and makes data-driven decisions',             1),
(4,  1, 'MATH-LO4', 'يطبق المفاهيم الجبرية في تمثيل العلاقات وحل المعادلات',                     'Applies algebraic concepts to represent relationships and solve equations',           1);

-- اللغة العربية
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(5,  2, 'ARAB-LO1', 'يقرأ النصوص المختلفة قراءة جهرية صحيحة مع فهم المضمون',                     'Reads various texts aloud correctly with comprehension',                              1),
(6,  2, 'ARAB-LO2', 'يكتب موضوعات تعبيرية وإنشائية بلغة سليمة وأسلوب واضح',                     'Writes expressive and creative compositions with correct language and clear style',    1),
(7,  2, 'ARAB-LO3', 'يتحدث أمام الآخرين بلغة عربية فصيحة وبثقة ووضوح',                           'Speaks in fluent Arabic with confidence and clarity',                                1),
(8,  2, 'ARAB-LO4', 'يحلل النصوص الأدبية ويستنتج الأفكار والمشاعر والأحكام',                     'Analyzes literary texts and infers ideas, feelings and judgments',                    1);

-- اللغة الإنجليزية
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(9,  3, 'ENG-LO1', 'يفهم النصوص المكتوبة باللغة الإنجليزية ويستنتج المعنى من السياق',            'Understands English written texts and infers meaning from context',                   1),
(10, 3, 'ENG-LO2', 'يتواصل شفهياً باللغة الإنجليزية في مواقف حياتية متنوعة',                    'Communicates orally in English in various life situations',                           1),
(11, 3, 'ENG-LO3', 'يكتب فقرات ومقالات قصيرة باللغة الإنجليزية بقواعد سليمة',                   'Writes paragraphs and short essays in English with correct grammar',                  1),
(12, 3, 'ENG-LO4', 'يستخدم القواعد النحوية والصرفية بشكل صحيح في التواصل الكتابي',               'Uses grammatical and morphological rules correctly in written communication',         1);

-- العلوم
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(13, 4, 'SCI-LO1', 'يفهم المفاهيم العلمية الأساسية ويربطها بالظواهر الطبيعية',                   'Understands basic scientific concepts and relates them to natural phenomena',          1),
(14, 4, 'SCI-LO2', 'يطبق المنهج العلمي في الملاحظة والتجربة واستخلاص النتائج',                   'Applies scientific method in observation, experimentation and drawing conclusions',    1),
(15, 4, 'SCI-LO3', 'يحلل الظواهر العلمية ويفسرها باستخدام الأدلة والبراهين',                     'Analyzes and explains scientific phenomena using evidence and proofs',                 1),
(16, 4, 'SCI-LO4', 'يقدر دور العلم في حياته اليومية ويدرك أهمية الحفاظ على البيئة',              'Appreciates the role of science in daily life and recognizes environmental conservation', 1);

-- التربية الإسلامية
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(17, 5, 'ISLM-LO1', 'يحفظ أجزاء من القرآن الكريم ويتلوها تلاوة صحيحة وفق أحكام التجويد',        'Memorizes Quran portions and recites them correctly with tajweed rules',              1),
(18, 5, 'ISLM-LO2', 'يفهم أحكام العبادات الأساسية ويطبقها في حياته اليومية',                    'Understands basic worship rulings and applies them in daily life',                     1),
(19, 5, 'ISLM-LO3', 'يستنتج الدروس والعبر من السيرة النبوية وقصص الأنبياء',                     'Derives lessons from the Prophet''s biography and prophets'' stories',               1),
(20, 5, 'ISLM-LO4', 'يتحلى بالقيم والأخلاق الإسلامية في سلوكه وتعامله مع الآخرين',               'Adopts Islamic values and ethics in behavior and dealing with others',                1);

-- الفيزياء
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(21, 8, 'PHYS-LO1', 'يفهم القوانين والمبادئ الفيزيائية الأساسية ويربطها بالظواهر الطبيعية',       'Understands basic physics laws and principles and relates them to natural phenomena', 1),
(22, 8, 'PHYS-LO2', 'يجري التجارب المعملية بدقة ويحلل النتائج ويعممها',                          'Conducts lab experiments accurately and analyzes and generalizes results',            1),
(23, 8, 'PHYS-LO3', 'يحل المسائل الفيزيائية باستخدام المعادلات والقوانين المناسبة',               'Solves physics problems using appropriate equations and laws',                        1),
(24, 8, 'PHYS-LO4', 'يربط المفاهيم الفيزيائية بالتطبيقات التكنولوجية في الحياة المعاصرة',        'Connects physics concepts with technological applications in contemporary life',      1);

-- الكيمياء
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(25, 9, 'CHEM-LO1', 'يفهم تركيب المادة وخصائص العناصر والمركبات الكيميائية',                    'Understands matter composition and properties of chemical elements and compounds',    1),
(26, 9, 'CHEM-LO2', 'يكتب المعادلات الكيميائية ويزنها ويحسب كميات المواد المتفاعلة',             'Writes and balances chemical equations and calculates reactant quantities',           1),
(27, 9, 'CHEM-LO3', 'يجري التجارب الكيميائية بأمان ويرصد التغيرات ويستنتج النتائج',              'Conducts chemistry experiments safely, observes changes and draws conclusions',       1),
(28, 9, 'CHEM-LO4', 'يدرك أهمية الكيمياء في الصناعة والطب والحياة اليومية وأثرها على البيئة',    'Recognizes the importance of chemistry in industry, medicine and daily life',         1);

-- الأحياء
INSERT INTO `learning_outcomes` (`id`, `subject_id`, `code`, `description_ar`, `description_en`, `is_active`) VALUES
(29, 10, 'BIOL-LO1', 'يفهم بنية الخلية ووظائف الأعضاء والأنظمة الحيوية في الكائنات الحية',       'Understands cell structure, organ functions and biological systems in living organisms', 1),
(30, 10, 'BIOL-LO2', 'يصنف الكائنات الحية ويفهم العلاقات البيئية بينها',                         'Classifies living organisms and understands ecological relationships',               1),
(31, 10, 'BIOL-LO3', 'يستخدم المجهر والأدوات المخبرية في فحص العينات البيولوجية',                'Uses microscope and lab tools to examine biological specimens',                      1),
(32, 10, 'BIOL-LO4', 'يدرك أهمية المحافظة على التنوع الحيوي والبيئة في اليمن والعالم',            'Recognizes the importance of biodiversity and environmental conservation',           1);

-- ============================================================================
-- 17. إعدادات التنبيهات (Alert Settings - 10 أنواع)
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

-- ============================================================================
-- إعادة تفعيل فحص المفاتيح الأجنبية
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
