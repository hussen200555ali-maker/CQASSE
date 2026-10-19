-- ============================================================
-- بيانات اختبار: تنبيهات للمعلم فاطمة أحمد الذماري (teacher_id = 13)
-- ============================================================

-- 1. إنشاء طلاب جدد في شعبة المعلم (شعبة 3-1 مثلاً)
INSERT INTO students (id, student_number, full_name_ar, full_name_en, gender, dob, grade_level_id, school_id, is_active, created_at)
VALUES 
(9001, 'ST-9001', 'أحمد محمد علي', 'Ahmed Mohamed Ali', 'male', '2015-03-15', 3, 3, 1, NOW()),
(9002, 'ST-9002', 'سارة إبراهيم حسن', 'Sara Ibrahim Hassan', 'female', '2015-05-20', 3, 3, 1, NOW()),
(9003, 'ST-9003', 'خالد يوسف سعيد', 'Khaled Yusuf Said', 'male', '2015-08-10', 3, 3, 1, NOW()),
(9004, 'ST-9004', 'منى عبد الله كامل', 'Mona Abdullah Kamel', 'female', '2015-01-25', 3, 3, 1, NOW()),
(9005, 'ST-9005', 'عمر سمير راشد', 'Omer Samir Rashid', 'male', '2015-07-30', 3, 3, 1, NOW())
ON DUPLICATE KEY UPDATE full_name_ar = VALUES(full_name_ar);

-- 2. قيد الطلاب في الشعبة (شعبة المعلم - الصف الثالث أ)
INSERT INTO student_enrollments (id, student_id, class_id, academic_year_id, semester_id, status, is_active, created_at)
VALUES 
(9001, 9001, 31, 2025, 2, 'enrolled', 1, NOW()),
(9002, 9002, 31, 2025, 2, 'enrolled', 1, NOW()),
(9003, 9003, 31, 2025, 2, 'enrolled', 1, NOW()),
(9004, 9004, 31, 2025, 2, 'enrolled', 1, NOW()),
(9005, 9005, 31, 2025, 2, 'enrolled', 1, NOW())
ON DUPLICATE KEY UPDATE status = 'enrolled';

-- 3. إنشاء درجات منخفضة (أكاديمي < 50%)
-- درجات لأحمد محمد علي (ID 9001) - ضعيفة جداً
INSERT INTO grades (student_id, subject_id, test_id, grade_value, max_value, academic_year_id, semester_id, is_active, created_at)
VALUES 
(9001, 1, 101, 20, 100, 2025, 2, 1, NOW()),  -- رياضيات: 20/100
(9001, 2, 102, 25, 100, 2025, 2, 1, NOW()),  -- علوم: 25/100
(9001, 3, 103, 15, 100, 2025, 2, 1, NOW())   -- لغة عربية: 15/100
ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value);

-- درجات لسارة إبراهيم حسن (ID 9002) - ضعيفة
INSERT INTO grades (student_id, subject_id, test_id, grade_value, max_value, academic_year_id, semester_id, is_active, created_at)
VALUES 
(9002, 1, 101, 35, 100, 2025, 2, 1, NOW()),  -- رياضيات: 35/100
(9002, 2, 102, 40, 100, 2025, 2, 1, NOW()),  -- علوم: 40/100
(9002, 3, 103, 30, 100, 2025, 2, 1, NOW())   -- لغة عربية: 30/100
ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value);

-- درجات لخالد يوسف سعيد (ID 9003) - جيدة (لا تنبيه)
INSERT INTO grades (student_id, subject_id, test_id, grade_value, max_value, academic_year_id, semester_id, is_active, created_at)
VALUES 
(9003, 1, 101, 85, 100, 2025, 2, 1, NOW()),
(9003, 2, 102, 80, 100, 2025, 2, 1, NOW()),
(9003, 3, 103, 90, 100, 2025, 2, 1, NOW())
ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value);

-- 4. إنشاء سجلات حضور منخفضة (< 75%)
-- أحمد: غياب متكرر (60%)
INSERT INTO attendance_records (student_id, class_id, attendance_date, period_id, status, recorded_by, is_active, created_at)
VALUES 
(9001, 31, '2025-05-10', 1, 'absent', 13, 1, NOW()),
(9001, 31, '2025-05-11', 1, 'absent', 13, 1, NOW()),
(9001, 31, '2025-05-12', 1, 'absent', 13, 1, NOW()),
(9001, 31, '2025-05-13', 1, 'late', 13, 1, NOW()),
(9001, 31, '2025-05-14', 1, 'present', 13, 1, NOW()),
(9001, 31, '2025-05-15', 1, 'absent', 13, 1, NOW()),
(9001, 31, '2025-05-17', 1, 'present', 13, 1, NOW()),
(9001, 31, '2025-05-18', 1, 'present', 13, 1, NOW()),
(9001, 31, '2025-05-19', 1, 'present', 13, 1, NOW())
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- سارة: غياب كثير (65%)
INSERT INTO attendance_records (student_id, class_id, attendance_date, period_id, status, recorded_by, is_active, created_at)
VALUES 
(9002, 31, '2025-05-10', 1, 'present', 13, 1, NOW()),
(9002, 31, '2025-05-11', 1, 'absent', 13, 1, NOW()),
(9002, 31, '2025-05-12', 1, 'present', 13, 1, NOW()),
(9002, 31, '2025-05-13', 1, 'absent', 13, 1, NOW()),
(9002, 31, '2025-05-14', 1, 'present', 13, 1, NOW()),
(9002, 31, '2025-05-15', 1, 'absent', 13, 1, NOW()),
(9002, 31, '2025-05-17', 1, 'present', 13, 1, NOW()),
(9002, 31, '2025-05-18', 1, 'present', 13, 1, NOW()),
(9002, 31, '2025-05-19', 1, 'present', 13, 1, NOW())
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- 5. إنشاء تقييمات سلوكية منخفضة (< 60%)
-- أحمد: سلوك ضعيف
INSERT INTO behavior_evaluations (student_id, evaluator_id, class_id, academic_year_id, semester_id, total_score, is_active, created_at)
VALUES 
(9001, 13, 31, 2025, 2, 25, 1, NOW())
ON DUPLICATE KEY UPDATE total_score = VALUES(total_score);

-- سارة: سلوك مقبول低于60
INSERT INTO behavior_evaluations (student_id, evaluator_id, class_id, academic_year_id, semester_id, total_score, is_active, created_at)
VALUES 
(9002, 13, 31, 2025, 2, 55, 1, NOW())
ON DUPLICATE KEY UPDATE total_score = VALUES(total_score);

-- خالد: سلوك جيد (لا تنبيه)
INSERT INTO behavior_evaluations (student_id, evaluator_id, class_id, academic_year_id, semester_id, total_score, is_active, created_at)
VALUES 
(9003, 13, 31, 2025, 2, 85, 1, NOW())
ON DUPLICATE KEY UPDATE total_score = VALUES(total_score);

-- 6. ربط ولي الأمر بالطلاب (للاختبار)
INSERT INTO parent_students (parent_id, student_id, relationship, is_active, created_at)
VALUES 
(100, 9001, 'father', 1, NOW()),
(101, 9002, 'mother', 1, NOW())
ON DUPLICATE KEY UPDATE is_active = 1;

-- 7. إنشاء تنبيهات مباشرة للاختبار (اختياري)
INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at)
VALUES 
-- تنبيهات أكاديمية
(3, 9001, 'academic', 'critical', '🚨 تراجع أكاديمي حرج', 'الطالب أحمد محمد علي يحصل على معدل 20% في الرياضيات - أقل من حد النجاح (50%)', 0, 0, NOW()),
(3, 9002, 'academic', 'danger', '⚠️ تراجع أكاديمي', 'الطالب سارة إبراهيم حسن تحصل على معدل 35% في مادة العلوم', 0, 0, NOW()),
-- تنبيهات حضور
(3, 9001, 'attendance', 'warning', '📋 معدل حضور منخفض', 'الطالب أحمد محمد علي لديه نسبة حضور 60% - أقل من الحد المطلوب (75%)', 0, 0, NOW()),
(3, 9002, 'attendance', 'info', '📋 حضور منخفض', 'الطالب سارة إبراهيم حسن لديها نسبة حضور 65%', 0, 0, NOW()),
-- تنبيهات سلوك
(3, 9001, 'behavior', 'danger', '⚠️ تقييم سلوكي منخفض', 'الطالب أحمد محمد علي حصل على 25% في التقييم السلوكي - أقل من الحد المطلوب (60%)', 0, 0, NOW()),
(3, 9002, 'behavior', 'warning', '⚠️ سلوك يحتاج متابعة', 'الطالب سارة إبراهيم حسن حصلت على 55% في التقييم السلوكي', 0, 0, NOW());

SELECT 'تم إنشاء بيانات الاختبار بنجاح!' AS result;