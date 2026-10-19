<?php
/**
 * Generate complete Data Dictionary HTML for CQASSE
 * Simple style matching the existing data_dictionary.html format
 */
function col($n, $t, $c, $d) { return [$n,$t,$c,$d]; }

$schema = [
  // ═══ BFD 1 ═══
  1 => ['name' => 'إدارة المستخدمين والصلاحيات والنظام', 'tables' => [
    ['users', 'المستخدمون', 21, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمستخدم'),
      col('username','VARCHAR(50)','UNIQUE, NOT NULL','اسم المستخدم الفريد'),
      col('password_hash','VARCHAR(255)','NOT NULL','كلمة المرور المشفرة'),
      col('must_change_password','TINYINT(1)','NOT NULL','إجباري تغيير كلمة المرور عند تسجيل الدخول الأول'),
      col('full_name_ar','VARCHAR(200)','','الاسم الكامل بالعربية'),
      col('full_name_en','VARCHAR(200)','','الاسم الكامل بالإنجليزية'),
      col('email','VARCHAR(100)','','البريد الإلكتروني'),
      col('phone','VARCHAR(20)','','رقم الهاتف'),
      col('role_id','INT UNSIGNED','FK → roles.id','معرف دور المستخدم'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','معرف المدرسة (يمكن أن يكون NULL للمستخدمين غير المرتبطين بمدرسة)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
      col('last_login_at','DATETIME','NULLABLE','آخر مرة تسجيل دخول'),
      col('login_attempts','TINYINT(3)','NOT NULL, DEFAULT 0','عدد محاولات الدخول الفاشلة'),
      col('lockout_until','DATETIME','NULLABLE','وقت انتهاء الحظر بسبب المحاولات الفاشلة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
      col('updated_at','DATETIME','NOT NULL','تاريخ آخر تحديث'),
    ]],
    ['roles', 'الأدوار', 4, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للدور'),
      col('name','VARCHAR(50)','UNIQUE, NOT NULL','اسم الدور (مثال: group_admin, school_admin, teacher, parent)'),
      col('display_name_ar','VARCHAR(100)','','الاسم المعروض بالعربية'),
      col('display_name_en','VARCHAR(100)','','الاسم المعروض بالإنجليزية'),
      col('rank','INT','','ترتيب الدور (للأذونات)'),
      col('description','TEXT','','وصف الدور'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
    ]],
    ['permissions', 'الصلاحيات', 36, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للصلاحية'),
      col('name','VARCHAR(100)','UNIQUE, NOT NULL','اسم الصلاحية'),
      col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
      col('module','VARCHAR(50)','NOT NULL','الوحدة (users, schools, eqi, إلخ)'),
      col('description','TEXT','','وصف الصلاحية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
    ]],
    ['role_permissions', 'صلاحيات الأدوار (جسر M:N)', 87, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
      col('role_id','INT UNSIGNED','FK → roles.id','معرف الدور'),
      col('permission_id','INT UNSIGNED','FK → permissions.id','معرف الصلاحية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
    ]],
    ['activity_logs', 'سجل النشاطات', 94, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
      col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','معرف المستخدم (NULL للأنشطة النظامية)'),
      col('action','VARCHAR(100)','NOT NULL','نوع النشاط'),
      col('entity_type','VARCHAR(50)','','نوع الكيان المتأثر'),
      col('entity_id','INT UNSIGNED','','معرف الكيان المتأثر'),
      col('status','ENUM','NOT NULL','حالة النشاط (success, failure, pending)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
      col('description','TEXT','','وصف النشاط'),
      col('details_json','JSON','NULLABLE','تفاصيل إضافية بصيغة JSON'),
      col('ip_address','VARCHAR(45)','','عنوان IP'),
      col('user_agent','TEXT','','معلومات المتصفح'),
      col('created_at','DATETIME','NOT NULL','تاريخ النشاط'),
    ]],
    ['system_settings', 'إعدادات النظام', 138, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('category','VARCHAR(50)','NOT NULL','فئة الإعداد'),
      col('setting_key','VARCHAR(100)','NOT NULL','مفتاح الإعداد'),
      col('setting_value','TEXT','','قيمة الإعداد'),
      col('value_type','ENUM','NOT NULL','نوع القيمة (string, integer, boolean, json)'),
      col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
      col('description_ar','TEXT','','الوصف بالعربية'),
      col('is_system','TINYINT(1)','NOT NULL','هل هو إعداد نظامي'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة (NULL = عام)'),
      col('sort_order','INT','','ترتيب العرض'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
    ]],
    ['user_preferences', 'تفضيلات المستخدم', 20, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('user_id','INT UNSIGNED','FK → users.id','معرف المستخدم'),
      col('pref_type','ENUM','NOT NULL','نوع التفضيل (ui, notification, dashboard)'),
      col('pref_key','VARCHAR(100)','NOT NULL','مفتاح التفضيل'),
      col('pref_value','JSON','','قيمة التفضيل بصيغة JSON'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
    ]],
    ['export_history', 'سجل التصدير', 7, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','المستخدم الذي طلب التصدير'),
      col('export_type','VARCHAR(50)','NOT NULL','نوع التصدير (excel, csv, pdf)'),
      col('file_name','VARCHAR(255)','','اسم الملف المصدَّر'),
      col('file_path','VARCHAR(500)','','مسار الملف'),
      col('file_size','INT UNSIGNED','','حجم الملف بالبايت'),
      col('row_count','INT UNSIGNED','','عدد السجلات'),
      col('filters','JSON','','معايير التصدير'),
      col('status','ENUM','NOT NULL','الحالة (pending, completed, failed)'),
    ]],
  ]],
  // ═══ BFD 2 ═══
  2 => ['name' => 'البيانات الأساسية والتسجيل', 'tables' => [
    ['governorates', 'المحافظات', 22, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمحافظة'),
      col('name_ar','VARCHAR(100)','NOT NULL','اسم المحافظة بالعربية'),
      col('name_en','VARCHAR(100)','','اسم المحافظة بالإنجليزية'),
      col('code','VARCHAR(5)','UNIQUE, NOT NULL','رمز المحافظة'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
    ]],
    ['schools', 'المدارس', 4, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمدرسة'),
      col('name_ar','VARCHAR(200)','NOT NULL','اسم المدرسة بالعربية'),
      col('name_en','VARCHAR(200)','','اسم المدرسة بالإنجليزية'),
      col('reference_code','VARCHAR(5)','UNIQUE, NOT NULL','الرمز المرجعي للمدرسة'),
      col('address','TEXT','','عنوان المدرسة'),
      col('governorate','VARCHAR(100)','NOT NULL','المحافظة (نص، ليس FK)'),
      col('district','VARCHAR(100)','','المنطقة'),
      col('school_type','ENUM','NOT NULL','نوع المدرسة (basic, secondary, combined)'),
      col('principal_name','VARCHAR(200)','','اسم مدير المدرسة'),
      col('phone','VARCHAR(20)','','رقم الهاتف'),
      col('email','VARCHAR(100)','','البريد الإلكتروني'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
      col('updated_at','DATETIME','NOT NULL','تاريخ آخر تحديث'),
    ]],
    ['academic_years', 'السنوات الدراسية', 4, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name','VARCHAR(50)','NOT NULL','اسم السنة الدراسية'),
      col('start_date','DATE','NOT NULL','تاريخ البداية'),
      col('end_date','DATE','NOT NULL','تاريخ النهاية'),
      col('is_current','TINYINT(1)','NOT NULL','هل هي السنة الحالية'),
      col('status','ENUM','NOT NULL','الحالة (planning, active, evaluation, closed)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
    ]],
    ['semesters', 'الفصول الدراسية', 7, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('name_ar','VARCHAR(50)','NOT NULL','اسم الفصل بالعربية'),
      col('start_date','DATE','NOT NULL','تاريخ البداية'),
      col('end_date','DATE','NOT NULL','تاريخ النهاية'),
      col('is_current','TINYINT(1)','NOT NULL','هل هو الفصل الحالي'),
      col('lock_level','ENUM','NOT NULL','مستوى القفل'),
      col('locked_at','DATETIME','NULLABLE','تاريخ القفل'),
      col('locked_by','INT UNSIGNED','FK → users.id, NULLABLE','من قام بالقفل'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['grade_levels', 'المستويات الدراسية', 12, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name_ar','VARCHAR(50)','NOT NULL','الاسم بالعربية'),
      col('name_en','VARCHAR(50)','','الاسم بالإنجليزية'),
      col('level_number','TINYINT(3)','UNIQUE, NOT NULL','رقم المستوى (1-12)'),
      col('stage','ENUM','NOT NULL','المرحلة (basic, secondary)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['classes', 'الشعب الدراسية', 43, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name_ar','VARCHAR(100)','NOT NULL','اسم الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى الدراسي'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('teacher_id','INT UNSIGNED','FK → users.id, NULLABLE','معلم الصف (المشرف)'),
      col('capacity','INT UNSIGNED','','الطاقة الاستيعابية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['subjects', 'المواد الدراسية', 12, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name_ar','VARCHAR(100)','NOT NULL','اسم المادة بالعربية'),
      col('name_en','VARCHAR(100)','','اسم المادة بالإنجليزية'),
      col('code','VARCHAR(20)','UNIQUE, NOT NULL','رمز المادة'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['subject_grade_years', 'ربط المواد بالمستويات', 97, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
      col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى'),
      col('hours_per_week','TINYINT(3)','','عدد الحصص الأسبوعية'),
      col('passing_grade','DECIMAL(5,2)','','درجة النجاح'),
      col('weights_json','JSON','','أوزان التقويم بصيغة JSON'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['subject_weight_distribution', 'توزيع أوزان المواد', 15, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('subject_grade_year_id','INT UNSIGNED','FK → subject_grade_years.id','رابط المادة-المستوى'),
      col('assessment_type_id','INT UNSIGNED','FK → assessment_types.id','نوع التقييم'),
      col('weight','DECIMAL(5,2)','NOT NULL','الوزن النسبي'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['subject_teachers', 'تعيينات المعلمين للمواد', 98, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
      col('teacher_id','INT UNSIGNED','FK → users.id','المعلم'),
      col('classroom_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
    ]],
    ['teacher_assignments', 'تعيينات مبسطة للمعلمين', 3, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('teacher_id','INT UNSIGNED','FK → users.id','المعلم'),
      col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('is_active','TINYINT(1)','DEFAULT 1','الحالة'),
      col('created_at','DATETIME','DEFAULT CURRENT_TIMESTAMP','تاريخ الإنشاء'),
    ]],
    ['students', 'الطلاب', 549, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_number','VARCHAR(20)','UNIQUE, NOT NULL','رقم الطالب الفريد'),
      col('full_name_ar','VARCHAR(200)','NOT NULL','الاسم الكامل بالعربية'),
      col('full_name_en','VARCHAR(200)','','الاسم الكامل بالإنجليزية'),
      col('guardian_name','VARCHAR(200)','','اسم ولي الأمر'),
      col('guardian_phone','VARCHAR(20)','','رقم هاتف ولي الأمر'),
      col('guardian_email','VARCHAR(100)','','بريد ولي الأمر الإلكتروني'),
      col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى الدراسي'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('enrollment_date','DATE','','تاريخ التسجيل'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['student_enrollments', 'تسجيلات الطلاب في الشعب', 551, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('enrollment_date','DATE','','تاريخ التسجيل'),
      col('status','ENUM','NOT NULL','الحالة (enrolled, transferred, dropped)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['student_notes', 'ملاحظات الطلاب', 1, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('note_type','ENUM','NOT NULL','نوع الملاحظة (general, academic, behavioral, attendance)'),
      col('title_ar','VARCHAR(200)','NOT NULL','عنوان الملاحظة'),
      col('content_ar','TEXT','NOT NULL','محتوى الملاحظة'),
      col('priority','ENUM','NOT NULL','الأولوية (low, medium, high, urgent)'),
      col('is_pinned','TINYINT(1)','NOT NULL, DEFAULT 0','مثبتة'),
      col('is_draft','TINYINT(1)','NOT NULL, DEFAULT 0','مسودة'),
      col('attachment_id','INT UNSIGNED','NULLABLE','معرف المرفق (ليس FK)'),
      col('created_by','INT UNSIGNED','FK → users.id','منشئ الملاحظة'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
      col('updated_at','DATETIME','NOT NULL','تاريخ آخر تحديث'),
    ]],
    ['attachments', 'الملفات المرفقة', 0, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('file_name','VARCHAR(255)','NOT NULL','اسم الملف'),
      col('file_type','VARCHAR(100)','','نوع الملف'),
      col('file_path','VARCHAR(500)','NOT NULL','مسار الملف'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة'),
      col('file_size','INT UNSIGNED','','حجم الملف بالبايت'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
      col('updated_at','DATETIME','NOT NULL','تاريخ آخر تحديث'),
    ]],
  ]],
  // ═══ BFD 3 ═══
  3 => ['name' => 'إدارة الحضور والسلوك والمهارات', 'tables' => [
    ['attendance_daily', 'سجلات الحضور اليومي', 10278, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('date','DATE','NOT NULL','التاريخ'),
      col('period_number','TINYINT(3)','NULLABLE','رقم الحصة (NULL = يومي)'),
      col('status','ENUM','NOT NULL','الحالة (present, absent, late, excused)'),
      col('notes','TEXT','NULLABLE','ملاحظات'),
      col('amendment_log','JSON','NULLABLE','سجل التعديلات'),
      col('recorded_by','INT UNSIGNED','FK → users.id, NULLABLE','المسجل'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
    ]],
    ['behavior_categories', 'فئات السلوك', 16, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name_ar','VARCHAR(100)','NOT NULL','اسم الفئة بالعربية'),
      col('name_en','VARCHAR(100)','','اسم الفئة بالإنجليزية'),
      col('type','ENUM','NOT NULL','النوع (positive, negative)'),
      col('points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المقابلة'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة (NULL = عام)'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['behavior_incidents', 'حوادث السلوك', 210, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('category_id','INT UNSIGNED','FK → behavior_categories.id','فئة السلوك'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('incident_date','DATE','NOT NULL','تاريخ الحادثة'),
      col('description','TEXT','NULLABLE','وصف الحادثة'),
      col('points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط'),
      col('reported_by','INT UNSIGNED','FK → users.id, NULLABLE','المبلغ'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
    ]],
    ['behavior_scores', 'نتائج السلوك المحتسبة', 0, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('starting_points','DECIMAL(5,2)','NOT NULL, DEFAULT 100','نقطة البداية'),
      col('earned_points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المكتسبة'),
      col('deducted_points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المخصومة'),
      col('current_percentage','DECIMAL(5,2)','NOT NULL, DEFAULT 100','النسبة الحالية'),
      col('is_passing','TINYINT(1)','NOT NULL, DEFAULT 1','هل اجتاز'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
      col('updated_at','DATETIME','NOT NULL','تاريخ آخر تحديث'),
    ]],
  ]],
  // ═══ BFD 4 ═══
  4 => ['name' => 'التقييم الشامل وجودة التعليم', 'tables' => [
    ['assessment_types', 'أنواع التقييم', 5, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('name_ar','VARCHAR(100)','NOT NULL','الاسم بالعربية'),
      col('name_en','VARCHAR(100)','','الاسم بالإنجليزية'),
      col('description','TEXT','','الوصف'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['assessment_forms', 'استمارات التقييم', 8, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('form_key','ENUM','NOT NULL','المفتاح (behavior_eval, skills_checklist, teaching_self, إلخ)'),
      col('name_ar','VARCHAR(200)','NOT NULL','الاسم بالعربية'),
      col('name_en','VARCHAR(200)','','الاسم بالإنجليزية'),
      col('category','ENUM','NOT NULL','الفئة (behavior, skills, teaching, climate)'),
      col('target','ENUM','NOT NULL','الهدف (student, teacher, school)'),
      col('description','TEXT','','الوصف'),
      col('is_system','TINYINT(1)','NOT NULL','هل هي استمارة نظامية'),
      col('is_default','TINYINT(1)','DEFAULT 0','هل هي الاستمارة الافتراضية'),
      col('version','VARCHAR(50)','','الإصدار'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['assessment_structure', 'هيكل التقييم (معايير وفقرات)', 237, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('form_id','INT UNSIGNED','FK → assessment_forms.id','الاستمارة'),
      col('parent_id','INT UNSIGNED','FK → assessment_structure.id, NULLABLE','المعيار الأب (NULL = معيار رئيسي)'),
      col('type','ENUM','NOT NULL','النوع (criteria, item)'),
      col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
      col('name_en','VARCHAR(200)','','الاسم بالإنجليزية'),
      col('text_ar','TEXT','','النص بالعربية'),
      col('text_en','TEXT','','النص بالإنجليزية'),
      col('tool_type','ENUM','','نوع الأداة'),
      col('weight','DECIMAL(5,2)','','الوزن'),
      col('is_inverse','TINYINT(1)','','عكسي'),
      col('max_value','DECIMAL(10,2)','','القيمة القصوى'),
      col('sort_order','INT UNSIGNED','','ترتيب العرض'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['assessment_responses', 'إجابات التقييم (الجدول المركزي)', 116, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('form_id','INT UNSIGNED','FK → assessment_forms.id','الاستمارة'),
      col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
      col('student_id','INT UNSIGNED','FK → students.id, NULLABLE','الطالب'),
      col('teacher_id','INT UNSIGNED','FK → users.id, NULLABLE','المعلم'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة'),
      col('class_id','INT UNSIGNED','FK → classes.id, NULLABLE','الشعبة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
      col('text_value','TEXT','NULLABLE','القيمة النصية'),
      col('responded_by','INT UNSIGNED','FK → users.id','المجيب'),
      col('response_date','DATETIME','NOT NULL','تاريخ الإجابة'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['tests', 'الاختبارات', 106, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
      col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
      col('assessment_type_id','INT UNSIGNED','FK → assessment_types.id','نوع التقييم'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('title_ar','VARCHAR(200)','NOT NULL','عنوان الاختبار'),
      col('max_grade','DECIMAL(5,2)','NOT NULL','الدرجة العظمى'),
      col('test_date','DATE','','تاريخ الاختبار'),
      col('status','ENUM','NOT NULL','الحالة (draft, published, locked)'),
      col('created_by','INT UNSIGNED','FK → users.id','منشئ الاختبار'),
    ]],
    ['grades', 'درجات الطلاب', 2076, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('test_id','INT UNSIGNED','FK → tests.id','الاختبار'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
      col('grade_value','DECIMAL(7,2)','NOT NULL','قيمة الدرجة'),
      col('max_value','DECIMAL(7,2)','NOT NULL','الدرجة العظمى'),
      col('percentage','DECIMAL(7,2)','','النسبة المئوية'),
      col('letter_grade','VARCHAR(3)','','الدرجة الحرفية'),
      col('notes','TEXT','NULLABLE','ملاحظات'),
      col('graded_by','INT UNSIGNED','FK → users.id','المصحح'),
    ]],
    ['learning_outcomes', 'مخرجات التعلم', 37, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
      col('code','VARCHAR(20)','','رمز المخرج'),
      col('description_ar','TEXT','','الوصف بالعربية'),
      col('description_en','TEXT','','الوصف بالإنجليزية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['evaluations', 'نتائج التقييم الموحدة', 539, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('eval_type','ENUM','NOT NULL','نوع التقييم (teaching_summary, behavior_score)'),
      col('teacher_id','INT UNSIGNED','FK → users.id, NULLABLE','المعلم'),
      col('student_id','INT UNSIGNED','FK → students.id, NULLABLE','الطالب'),
      col('class_id','INT UNSIGNED','FK → classes.id, NULLABLE','الشعبة'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('self_score','DECIMAL(5,2)','NULLABLE','درجة التقييم الذاتي'),
      col('peer_score','DECIMAL(5,2)','NULLABLE','درجة تقييم الأقران'),
      col('admin_score','DECIMAL(5,2)','NULLABLE','درجة التقييم الإداري'),
      col('combined_score','DECIMAL(5,2)','NULLABLE','الدرجة المركبة'),
      col('adjusted_score','DECIMAL(5,2)','NULLABLE','الدرجة المعدلة'),
      col('starting_points','DECIMAL(5,2)','NULLABLE','نقطة البداية (للسلوك)'),
      col('earned_points','DECIMAL(5,2)','NULLABLE','النقاط المكتسبة'),
      col('deducted_points','DECIMAL(5,2)','NULLABLE','النقاط المخصومة'),
      col('current_percentage','DECIMAL(5,2)','NULLABLE','النسبة الحالية'),
      col('is_passing','TINYINT(1)','NULLABLE','هل اجتاز'),
    ]],
    ['surveys', 'الاستبيانات (مناخ + مرافق)', 7, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('survey_type','ENUM','NOT NULL','النوع (climate, facility)'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('respondent_type','ENUM','NOT NULL','نوع المستجيب'),
      col('respondent_id','INT UNSIGNED','FK → users.id, NULLABLE','المستجيب'),
      col('evaluator_id','INT UNSIGNED','FK → users.id, NULLABLE','المقيم'),
      col('survey_date','DATE','','تاريخ الاستبيان'),
      col('overall_score','DECIMAL(5,2)','NULLABLE','الدرجة الكلية'),
      col('notes','TEXT','NULLABLE','ملاحظات'),
      col('status','ENUM','NOT NULL','الحالة (draft, active, closed)'),
      col('created_by','INT UNSIGNED','FK → users.id','المنشئ'),
    ]],
    ['survey_items', 'إجابات فقرات الاستبيان', 13, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('survey_id','INT UNSIGNED','FK → surveys.id','الاستبيان'),
      col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
      col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
      col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['climate_items', 'فقرات المناخ المدرسي', 48, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('survey_id','INT UNSIGNED','FK → surveys.id','الاستبيان'),
      col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
      col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
      col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['facility_items', 'فقرات تقييم المرافق', 0, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('checklist_id','INT UNSIGNED','FK → surveys.id','قائمة التحقق'),
      col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
      col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
      col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
      col('notes','TEXT','NULLABLE','ملاحظات'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
    ['eqi_results', 'نتائج مؤشر جودة التعليم EQI', 7, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('academic_score','DECIMAL(5,2)','NULLABLE','درجة الأداء الأكاديمي'),
      col('teaching_score','DECIMAL(5,2)','NULLABLE','درجة جودة التدريس'),
      col('climate_score','DECIMAL(5,2)','NULLABLE','درجة المناخ المدرسي'),
      col('behavior_score','DECIMAL(5,2)','NULLABLE','درجة السلوك'),
      col('skills_score','DECIMAL(5,2)','NULLABLE','درجة المهارات'),
      col('attendance_score','DECIMAL(5,2)','NULLABLE','درجة الحضور'),
      col('overall_eqi','DECIMAL(5,2)','NULLABLE','مؤشر EQI الكلي'),
      col('weights_json','JSON','NULLABLE','الأوزان المستخدمة'),
      col('context_json','JSON','NULLABLE','السياق'),
      col('challenge_index','DECIMAL(5,2)','NULLABLE','مؤشر التحدي'),
      col('adjusted_eqi','DECIMAL(5,2)','NULLABLE','EQI المعدّل سياقياً'),
      col('missing_dimensions','JSON','NULLABLE','الأبعاد المفقودة'),
      col('calculated_by','INT UNSIGNED','FK → users.id','محسوب بواسطة'),
    ]],
    ['improvement_plans', 'خطط التحسين الموحدة', 3, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('plan_type','ENUM','NOT NULL','النوع (student, school, teacher)'),
      col('student_id','INT UNSIGNED','FK → students.id, NULLABLE','الطالب'),
      col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة'),
      col('teacher_id','INT UNSIGNED','FK → users.id, NULLABLE','المعلم'),
      col('trigger_type','ENUM','','نوع المحفز'),
      col('weaknesses','TEXT','','مواطن الضعف'),
      col('goals','TEXT','','الأهداف'),
      col('strategies','TEXT','','الاستراتيجيات'),
      col('timeline','VARCHAR(200)','','الجدول الزمني'),
      col('responsible_user_id','INT UNSIGNED','FK → users.id','المسؤول'),
      col('parent_involvement','TEXT','NULLABLE','مشاركة ولي الأمر'),
      col('status','ENUM','NOT NULL','الحالة (active, completed, cancelled)'),
      col('progress','INT','DEFAULT 0','نسبة التقدم'),
      col('follow_up_date','DATE','NULLABLE','تاريخ المتابعة'),
      col('created_by','INT UNSIGNED','FK → users.id','المنشئ'),
    ]],
    ['school_improvement_plans', 'خطط تحسين المدارس (قديم)', 2, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('weaknesses','TEXT','','مواطن الضعف'),
      col('goals','TEXT','','الأهداف'),
      col('strategies','TEXT','','الاستراتيجيات'),
      col('timeline','VARCHAR(200)','','الجدول الزمني'),
      col('responsible_user_id','INT UNSIGNED','FK → users.id','المسؤول'),
      col('status','ENUM','NOT NULL','الحالة'),
      col('progress','INT','DEFAULT 0','نسبة التقدم'),
      col('created_by','INT UNSIGNED','FK → users.id','المنشئ'),
    ]],
    ['promotions', 'قرارات الترفيع', 519, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('academic_score','DECIMAL(5,2)','NULLABLE','الدرجة الأكاديمية'),
      col('behavior_score','DECIMAL(5,2)','NULLABLE','درجة السلوك'),
      col('attendance_score','DECIMAL(5,2)','NULLABLE','درجة الحضور'),
      col('academic_pass','TINYINT(1)','NULLABLE','نجاح أكاديمي'),
      col('behavior_pass','TINYINT(1)','NULLABLE','نجاح سلوكي'),
      col('attendance_pass','TINYINT(1)','NULLABLE','نجاح حضور'),
      col('decision','ENUM','NOT NULL','القرار (promoted, repeated, conditional, under_review)'),
      col('status','ENUM','NOT NULL','الحالة (proposed, approved, rejected)'),
      col('override_json','JSON','NULLABLE','بيانات التجاوز'),
      col('approved_by','INT UNSIGNED','FK → users.id, NULLABLE','المعتمد'),
      col('decided_by','INT UNSIGNED','FK → users.id, NULLABLE','مصدر القرار'),
    ]],
    ['promotion_overrides', 'تجاوزات الترفيع', 0, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
      col('override_type','ENUM','NOT NULL','نوع التجاوز'),
      col('override_value','DECIMAL(5,2)','NULLABLE','قيمة التجاوز'),
      col('reason','TEXT','NOT NULL','السبب'),
      col('approved_by','INT UNSIGNED','FK → users.id','المعتمد'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
  ]],
  // ═══ BFD 5 ═══
  5 => ['name' => 'التقارير والتحليلات (المشاهد)', 'tables' => [
    ['v_student_current', 'الطلاب الحاليون (مشهد)', 0, [
      col('student_id','INT UNSIGNED','','معرف الطالب'),
      col('student_number','VARCHAR(20)','','رقم الطالب'),
      col('full_name_ar','VARCHAR(200)','','الاسم بالعربية'),
      col('full_name_en','VARCHAR(200)','','الاسم بالإنجليزية'),
      col('guardian_name','VARCHAR(200)','','اسم ولي الأمر'),
      col('guardian_phone','VARCHAR(20)','','هاتف ولي الأمر'),
      col('grade_level_id','INT UNSIGNED','','المستوى'),
      col('school_id','INT UNSIGNED','','المدرسة'),
      col('grade_year_id','INT UNSIGNED','','سنة التسجيل'),
      col('enrollment_status','ENUM','','حالة التسجيل'),
      col('class_id','INT UNSIGNED','','الشعبة'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('class_name','VARCHAR(100)','','اسم الشعبة'),
      col('grade_name','VARCHAR(50)','','اسم المستوى'),
      col('stage','ENUM','','المرحلة'),
      col('school_name','VARCHAR(200)','','اسم المدرسة'),
      col('academic_year_name','VARCHAR(50)','','اسم السنة'),
    ]],
    ['v_student_subject_summary', 'ملخص درجات الطالب لكل مادة (مشهد)', 0, [
      col('student_id','INT UNSIGNED','','الطالب'),
      col('subject_id','INT UNSIGNED','','المادة'),
      col('class_id','INT UNSIGNED','','الشعبة'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
      col('total_grade','DECIMAL(7,2)','','مجموع الدرجات'),
      col('max_grade','DECIMAL(7,2)','','مجموع الدرجات العظمى'),
      col('percentage','DECIMAL(5,2)','','النسبة المئوية'),
    ]],
    ['student_subject_summary', 'ملخص الطالب-المادة مع GPA (مشهد)', 0, [
      col('id','INT UNSIGNED','PK generated','المعرف (row_number)'),
      col('student_id','INT UNSIGNED','','الطالب'),
      col('subject_id','INT UNSIGNED','','المادة'),
      col('class_id','INT UNSIGNED','','الشعبة'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
      col('total_grade','DECIMAL(7,2)','','المجموع'),
      col('max_grade','DECIMAL(7,2)','','الدرجة العظمى'),
      col('percentage','DECIMAL(5,2)','','النسبة'),
      col('letter_grade','VARCHAR(3)','','الدرجة الحرفية'),
      col('grade_point','DECIMAL(3,2)','','نقطة GPA'),
      col('rank_in_class','INT UNSIGNED','','الترتيب في الشعبة'),
      col('is_active','TINYINT(1)','','نشط'),
      col('updated_at','DATETIME','','آخر تحديث'),
    ]],
    ['v_semester_scores', 'معدلات الفصل الدراسي (مشهد)', 0, [
      col('student_id','INT UNSIGNED','','الطالب'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
      col('gpa','DECIMAL(3,2)','','المعدل التراكمي'),
      col('total_percentage','DECIMAL(5,2)','','النسبة المئوية الكلية'),
    ]],
    ['v_attendance_summary', 'ملخص الحضور (مشهد)', 0, [
      col('student_id','INT UNSIGNED','','الطالب'),
      col('class_id','INT UNSIGNED','','الشعبة'),
      col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
      col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
      col('total_days','INT UNSIGNED','','إجمالي الأيام'),
      col('present_days','INT UNSIGNED','','أيام الحضور'),
      col('absent_days','INT UNSIGNED','','أيام الغياب'),
      col('late_days','INT UNSIGNED','','أيام التأخير'),
      col('excused_days','INT UNSIGNED','','أيام بعذر'),
      col('attendance_rate','DECIMAL(5,2)','','نسبة الحضور'),
      col('is_below_threshold','TINYINT(1)','','أقل من الحد الأدنى'),
      col('is_active','TINYINT(1)','','نشط'),
    ]],
  ]],
  // ═══ BFD 6 ═══
  6 => ['name' => 'التواصل وبوابة المستفيدين', 'tables' => [
    ['alerts', 'التنبيهات', 120, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
      col('student_id','INT UNSIGNED','FK → students.id, NULLABLE','الطالب'),
      col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','المستخدم'),
      col('alert_type','ENUM','NOT NULL','نوع التنبيه'),
      col('severity','ENUM','NOT NULL','الخطورة (info, warning, danger, critical)'),
      col('title_ar','VARCHAR(200)','NOT NULL','العنوان'),
      col('message_ar','TEXT','NOT NULL','الرسالة'),
      col('threshold_json','JSON','NULLABLE','معايير التنبيه'),
      col('is_read','TINYINT(1)','NOT NULL, DEFAULT 0','مقروء'),
      col('read_at','DATETIME','NULLABLE','تاريخ القراءة'),
      col('is_resolved','TINYINT(1)','NOT NULL, DEFAULT 0','محلول'),
      col('resolved_by','INT UNSIGNED','FK → users.id, NULLABLE','حل بواسطة'),
      col('resolved_at','DATETIME','NULLABLE','تاريخ الحل'),
      col('notes','TEXT','NULLABLE','ملاحظات'),
      col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
    ]],
    ['notifications', 'الإشعارات', 253, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('user_id','INT UNSIGNED','FK → users.id','المستخدم'),
      col('type','VARCHAR(50)','','نوع الإشعار'),
      col('title_ar','VARCHAR(200)','','العنوان'),
      col('message_ar','TEXT','','الرسالة'),
      col('related_entity_type','VARCHAR(50)','','نوع الكيان المرتبط'),
      col('related_entity_id','INT UNSIGNED','','معرف الكيان المرتبط'),
      col('is_read','TINYINT(1)','NOT NULL, DEFAULT 0','مقروء'),
      col('read_at','DATETIME','NULLABLE','تاريخ القراءة'),
    ]],
    ['parent_students', 'ربط ولي الأمر بالطالب', 3, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('parent_id','INT UNSIGNED','FK → users.id','ولي الأمر'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('relationship','ENUM','NOT NULL','صلة القرابة (father, mother, guardian, other)'),
      col('ack_grade','TINYINT(1)','DEFAULT 0','إقرار الدرجات'),
      col('ack_behavior','TINYINT(1)','DEFAULT 0','إقرار السلوك'),
      col('ack_attendance','TINYINT(1)','DEFAULT 0','إقرار الحضور'),
      col('ack_promotion','TINYINT(1)','DEFAULT 0','إقرار الترفيع'),
      col('last_ack_at','DATETIME','NULLABLE','آخر إقرار'),
    ]],
    ['parent_acknowledgments', 'إقرارات ولي الأمر', 0, [
      col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
      col('parent_id','INT UNSIGNED','FK → users.id','ولي الأمر'),
      col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
      col('relationship','ENUM','NOT NULL','صلة القرابة'),
      col('ack_grade','TINYINT(1)','DEFAULT 0','إقرار الدرجات'),
      col('ack_behavior','TINYINT(1)','DEFAULT 0','إقرار السلوك'),
      col('ack_attendance','TINYINT(1)','DEFAULT 0','إقرار الحضور'),
      col('ack_promotion','TINYINT(1)','DEFAULT 0','إقرار الترفيع'),
      col('last_ack_at','DATETIME','NULLABLE','آخر إقرار'),
      col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة'),
    ]],
  ]],
];

// ═══ BFD descriptions and requirement mappings ═══
$bfdInfo = [
  1 => [
    'desc' => 'تمثل هذه المجموعة العمود الفقري لأمن النظام وإدارة الهويات. تدير جميع المستخدمين بأدوارهم الأربعة (group_admin, school_admin, teacher, parent) مع نظام صلاحيات دقيق (RBAC) عبر 36 صلاحية موزعة على 8 وحدات وظيفية. تشمل إعدادات النظام العامة والقابلة للتخصيص لكل مدرسة، وسجل النشاطات (94 سجلاً) الذي يوثق كل عملية حرجة مع عنوان IP وتفاصيل المتصفح، وتفضيلات المستخدمين، وسجل التصدير. جميع متطلبات الأمان العشرة (NFR-S01~S10) تُنفَّذ ضمن جداول هذه المجموعة، مما يجعلها الطبقة الأمنية الأولى والأكثر أهمية في النظام.',
    'frs' => [
      ['FR-01', 'إدارة المستخدمين', 'إنشاء/تعديل/حذف المستخدمين بأدوار مختلفة'],
      ['FR-33', 'إدارة الصلاحيات', 'مصفوفة صلاحيات تفاعلية (checkbox) لربط الأدوار بالصلاحيات'],
      ['FR-34', 'سياسات المجموعة', 'تحديد سياسات المجموعة (ترميز EQI لوني، نسب نجاح، إشعارات)'],
      ['FR-31', 'تصدير البيانات', 'تصدير البيانات بصيغ XLSX وCSV مع تتبع سجل التصدير'],
      ['FR-37', 'سجل النشاط', 'تسجيل جميع العمليات الحرجة مع وقت وتفاصيل المستخدم وعنوان IP'],
      ['FR-38', 'إعدادات النظام', 'إعدادات عامة وفردية لكل مدرسة مع تصنيفات متعددة'],
    ],
    'nfrs' => [
      ['NFR-S01~S10', 'جميع متطلبات الأمان العشرة', 'تشفير، CSRF، XSS، SQLi، جلسات، رؤوس أمان، RBAC، إلخ'],
      ['NFR-M01', 'هيكلة MVC-like', '21 ملف includes + 176 ملف pages'],
      ['NFR-M02', 'نمط Singleton', 'اتصال موحد بقاعدة البيانات'],
      ['NFR-M04', 'توثيق الدوال', 'PHPDoc في جميع ملفات includes'],
    ],
  ],
  2 => [
    'desc' => 'تحتوي هذه المجموعة على الهيكل التنظيمي الكامل للنظام: المدارس (4 مدارس) والمحافظات (22 محافظة) للبيانات الجغرافية، التقويم الأكاديمي (4 سنوات و7 فصول دراسية) مع الفصل الحالي ومستويات القفل الإداري، المستويات الدراسية (12 مستوى) والمواد (12 مادة) والشعب (43 شعبة) مع ربط المعلمين والمواد، وبيانات الطلاب (549 طالباً) وتسجيلهم في الشعب (551 تسجيلاً). تمثل هذه المجموعة العمود الفقري للبيانات التي تعتمد عليها جميع المجموعات الأخرى، وتضمن عزل البيانات حسب school_id (NFR-SC01).',
    'frs' => [
      ['FR-02', 'إدارة المدارس', 'إضافة/تعديل/أرشفة بيانات المدارس مع إحصائيات شاملة'],
      ['FR-03', 'الأعوام والفصول الدراسية', 'إنشاء أعوام وفصول مع is_current و lock_level'],
      ['FR-04', 'إدارة الشعب', 'إنشاء شعب وربطها بالصفوف والمعلمين والمواد'],
      ['FR-05', 'بيانات الطلاب', 'تسجيل/تعديل/استيراد الطلاب دفعة CSV/Excel مع بحث AJAX'],
      ['FR-06', 'التسجيل في الشعب', 'تسجيل الطلاب في الشعب مع التحقق من حالة العام الدراسي'],
    ],
    'nfrs' => [
      ['NFR-P04', 'فهرسة شاملة', '80+ فهرس على كل المفاتيح الخارجية'],
      ['NFR-SC01', 'عزل البيانات حسب school_id', 'جميع الجداول الرئيسية تحتوي على school_id'],
      ['NFR-C04', 'توافق MySQL 8.0', 'جميع الاستعلامات متوافقة مع MySQL 8.0'],
    ],
  ],
  3 => [
    'desc' => 'تتولى هذه المجموعة تتبع الأداء اليومي للطلاب في ثلاثة مجالات حيوية: الحضور (10278 سجل حضور يومي مع تسجيل حسب الحصة وسجل تعديلات amendment_log)، السلوك (16 فئة سلوك إيجابي/سلبي و210 حوادث مسجلة بنظام النقاط)، ونتائج السلوك المحتسبة (behavior_scores) التي تحسب الدرجة السلوكية لكل طالب من 100 نقطة. توفر هذه المجموعة البيانات اللازمة لحساب بُعدي السلوك (10%) والحضور (5%) في محرك EQI.',
    'frs' => [
      ['FR-10', 'الحضور اليومي', 'تسجيل الحضور والغياب يومياً لكل طالب'],
      ['FR-11', 'الحضور حسب الحصة', 'تسجيل الحضور على مستوى كل حصة دراسية'],
      ['FR-12', 'تعديل الحضور', 'تعديل سجلات الحضور مع تسجيل سجل التعديل (amendment_log JSON)'],
      ['FR-13', 'الحوادث السلوكية', 'تسجيل حوادث سلوكية إيجابية وسلبية مصنفة'],
      ['FR-14', 'الدرجات السلوكية', 'حساب الدرجة السلوكية من 100 نقطة بناءً على الحوادث'],
      ['FR-15', 'تقييم المهارات', 'تقييم مهارات الطلاب عبر استمارات مخصصة'],
    ],
    'nfrs' => [
      ['NFR-P04', 'فهرسة شاملة', 'فهارس على student_id, class_id, date لتسريع استعلامات الحضور'],
    ],
  ],
  4 => [
    'desc' => 'تمثل هذه المجموعة قلب نظام الجودة — الأكبر حجماً بـ 17 جدولاً. تدير دورة التقييم الكاملة: أنواع التقييم (5 أنواع) والاستمارات (8 نماذج) وهياكل المعايير والفقرات (237 عنصراً في assessment_structure مع self-referencing parent_id)، الاختبارات (106 اختباراً) والدرجات (2076 درجة)، مخرجات التعلم (37 مخرجاً)، التقييمات الموحدة (539 تقييماً بثلاثة أبعاد: ذاتي/أقران/إداري)، استبيانات المناخ والمرافق، مؤشر EQI بستة أبعاد مرجّحة مع التعديل السياقي (7 عوامل) ومؤشر التحدي، خطط التحسين (5 خطط)، وقرارات الترفيع (519 قراراً). تعكس هذه المجموعة تعقيد نظام الجودة الشامل.',
    'frs' => [
      ['FR-07', 'إدارة الاختبارات', 'إنشاء اختبارات بأنواعها مع الوزن والدرجة العظمى وحالة النشر'],
      ['FR-08', 'إدخال الدرجات', 'إدخال/تعديل درجات الطلاب مع حساب النسبة والتقدير الأبجدي'],
      ['FR-09', 'حساب المعدلات', 'حساب GPA بمقياس 4.00 مع التقديرات الأبجدية ونظام الترحيل'],
      ['FR-16', 'نماذج التقييم', 'إنشاء نماذج متعددة من نفس النوع مع is_default و version'],
      ['FR-17', 'التقييم الذاتي', 'تقييم المعلمين لأدائهم ذاتياً'],
      ['FR-18', 'تقييم الأقران', 'تقييم المعلمين لزملائهم'],
      ['FR-19', 'التقييم الإداري', 'تقييم الإدارة لأداء المعلمين'],
      ['FR-20', 'استبيانات المناخ', 'استبيانات مناخ بثلاثة أبعاد (البيئة، العلاقات، السلامة)'],
      ['FR-21', 'تقييم المرافق', 'تقييم مرافق المدرسة عبر قوائم مراجعة'],
      ['FR-22', 'محرك EQI', 'حساب EQI من 6 أبعاد مرجّحة (أكاديمي 35%، تدريس 25%، إلخ)'],
      ['FR-23', 'التعديل السياقي', 'تعديل EQI تلقائي بـ 7 عوامل سياقية ومؤشر التحدي (0-100)'],
      ['FR-24', 'مقارنة المدارس', 'مقارنة أداء مدرستين أو أكثر في EQI الخام والمعدّل'],
      ['FR-26', 'محرك الترقية', 'تحديد ترقية الطلاب بـ 3 معايير: أكاديمي 50%، سلوكي 60%، حضور 75%'],
      ['FR-27', 'خطط التحسين', 'إنشاء خطط تحسين فردية ومؤسسية مع تتبع التقدم'],
    ],
    'nfrs' => [
      ['NFR-P02', 'حد 200 تركيبة', 'حد أقصى لعدد تركيبات حساب EQI الجماعي لكل طلب'],
      ['NFR-P03', 'حد 5 ميجابايت', 'الحد الأقصى للملفات المرفوعة في الاستيراد'],
      ['NFR-R03', 'معاملات قاعدة البيانات', 'استخدام transactions للعمليات الحرجة'],
      ['NFR-R05', 'مستويات القفل', '4 مستويات قفل للفصول الدراسية (none/soft/hard/admin_handled)'],
    ],
  ],
  5 => [
    'desc' => 'توفر هذه المجموعة 5 مشاهد (Views) معدّة مسبقاً للاستعلامات التحليلية الشائعة: ملخص الطالب الحالي (v_student_current) الذي يجمع بيانات الطالب مع التسجيل والشعبة والمدرسة في مشهد واحد، ملخص درجات الطالب لكل مادة (v_student_subject_summary) مع إصدار موسع (student_subject_summary) يضيف GPA والترتيب، معدلات الفصل الدراسي (v_semester_scores)، وملخص الحضور (v_attendance_summary) بنسب واحتساب الحد الأدنى. لا تخزن المشاهد بيانات بل تُشتق ديناميكياً من جداول BFD 2 و BFD 3 و BFD 4، مما يحسّن الأداء ويوحّد الوصول للبيانات التحليلية.',
    'frs' => [
      ['FR-28', 'لوحة القيادة', 'لوحة قيادة لمدير المجموعة تتضمن KPIs ورسوم بيانية واتجاهات EQI'],
      ['FR-29', 'لوحات التحليلات', '6 أدوات تحليلية (مدرسة، شعبة، مادة، اتجاهات، مقارنة، شامل)'],
      ['FR-30', 'التقارير PDF', 'تقارير سنوية PDF بغلاف وملخص تنفيذي و KPI واتجاهات وتوصيات'],
    ],
    'nfrs' => [
      ['NFR-P06', 'عروض قاعدة بيانات', '4 مشاهد (views) للتجميعات الشائعة لتحسين الأداء'],
      ['NFR-U06', 'مؤشرات اتجاه', 'مؤشرات (▲/▼/→) مع نسبة مئوية في لوحات التحليلات'],
    ],
  ],
  6 => [
    'desc' => 'تدير هذه المجموعة قنوات التواصل بين النظام والمستفيدين. تشمل التنبيهات الذكية (120 تنبيهاً) التي تُكتشف تلقائياً عبر 3 قواعد EQI (أقل من 50، تراجع 15% سنوياً، تحسن 10+ نقاط) وقاعدتي مناخ (مناخ < 50%، مشاركة < 60%)، مع تصنيف الشدة (info/warning/danger/critical). الإشعارات الداخلية (253 إشعاراً) ترسل للمستخدمين مع تفضيلات قابلة للتخصيص. بوابة ولي الأمر تربط أولياء الأمور بأبنائهم (3 روابط) مع نظام إقرارات يشمل 4 أنواع (درجات، سلوك، حضور، ترفيع). تمثل هذه المجموعة واجهة التفاعل الحيوية بين المدرسة والمجتمع.',
    'frs' => [
      ['FR-25', 'محرك التنبيهات', 'كشف تلقائي للمشكلات عند تجاوز العتبات مع تصنيف الشدة'],
      ['FR-32', 'نظام الإشعارات', 'إرسال إشعارات داخلية للمستخدمين مع تفضيلات قابلة للتخصيص'],
      ['FR-35', 'بوابة ولي الأمر', 'عرض درجات وسلوك وحضور ومهارات الأبناء مع إمكانية الإقرار'],
    ],
    'nfrs' => [
      ['NFR-R02', 'تسجيل العمليات', 'تسجيل جميع التنبيهات والإشعارات مع تفاصيل المستخدم والوقت'],
      ['NFR-U04', 'رسائل تنبيه', 'رسائل نجاح/خطأ/تحذير/معلومات بعد كل عملية'],
    ],
  ],
];

// ─── Generate HTML ────────────────────────────────
$totalTables = 0;
$totalViews = 0;
$totalCols = 0;
foreach ($schema as $bfd) {
  foreach ($bfd['tables'] as $t) {
    if (strpos($t[0], 'v_') === 0 || $t[0] === 'student_subject_summary') $totalViews++; else $totalTables++;
    $totalCols += count($t[3]);
  }
}

function reqTable($title, $rows, $typeLabel) {
  $h = '<div class="req-box"><h3>' . $title . '</h3>';
  $h .= '<table><thead><tr><th>المعرف</th><th>المتطلب</th><th>الوصف / ملاحظات</th></tr></thead><tbody>';
  foreach ($rows as $r) {
    $h .= '<tr><td style="white-space:nowrap;font-weight:bold;">' . $r[0] . '</td><td>' . $r[1] . '</td><td>' . $r[2] . '</td></tr>';
  }
  $h .= '</tbody></table></div>';
  return $h;
}

$html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قائمة بيانات نظام CQASSE</title>
    <style>
        body { font-family: "Traditional Arabic", "Arial", sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f9f9f9; color: #333; direction: rtl; }
        h1, h2, h3, h4 { color: #2c3e50; }
        .container { max-width: 1200px; margin: 0 auto; background-color: white; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .bfd-section { margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid #eee; }
        .bfd-title { color: white; padding: 10px; margin: -20px -20px 20px -20px; font-size: 1.5em; }
        .bfd-1 { background-color: #e74c3c; }
        .bfd-2 { background-color: #3498db; }
        .bfd-3 { background-color: #2ecc71; }
        .bfd-4 { background-color: #9b59b6; }
        .bfd-5 { background-color: #f1c40f; color: #333 !important; }
        .bfd-6 { background-color: #1abc9c; }
        .note { font-size: 0.9em; color: #7f8c8d; font-style: italic; }
        .tbl-name { font-weight: bold; color: #2c3e50; font-size: 1.2em; margin-bottom: 10px; display: block; }
        .view-label { display: inline-block; background: #f1c40f; color: #333; padding: 2px 10px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-right: 10px; }
        .stats { text-align: center; margin-bottom: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .stats span { margin: 0 15px; font-size: 1.1em; }
        .stats .num { font-weight: bold; color: #e74c3c; }
        .bfd-desc { background: #f0f4ff; border-right: 4px solid #3498db; padding: 15px 20px; margin-bottom: 20px; border-radius: 6px; line-height: 1.8; font-size: 1.05em; }
        .req-box { margin-bottom: 18px; }
        .req-box h3 { font-size: 1.1em; margin: 15px 0 10px; color: #2c3e50; }
        .req-box table { margin-bottom: 10px; }
        .req-box table th { background: #e8eaf6; }
        .req-box table td:first-child { font-weight: bold; white-space: nowrap; }
    </style>
</head>
<body>
<div class="container">
    <h1>قائمة بيانات نظام CQASSE</h1>
    <p>هذا المستند يحتوي على تفاصيل جميع الجداول والمشاهد في قاعدة بيانات نظام CQASSE (نظام التقييم الشامل لجودة التعليم المدرسي)، مصنفة حسب مجموعات الوظائف الست (BFD).</p>

    <div class="stats">
        <span>🗃️ <span class="num">' . $totalTables . '</span> جدول</span>
        <span>🔍 <span class="num">' . $totalViews . '</span> مشهد (VIEW)</span>
        <span>📊 <span class="num">' . $totalCols . '</span> عمود</span>
    </div>';

$idx = 0;
foreach ($schema as $bid => $bfd) {
  $html .= "\n    " . '<!-- BFD ' . $bid . ' -->';
  $html .= "\n" . '    <div class="bfd-section">';
  $html .= "\n" . '        <div class="bfd-title bfd-' . $bid . '">BFD ' . $bid . ': ' . $bfd['name'] . ' (' . count($bfd['tables']) . ')</div>';
  $html .= "\n";

  // Description
  $info = $bfdInfo[$bid] ?? ['desc'=>'', 'frs'=>[], 'nfrs'=>[]];
  $html .= "\n" . '        <div class="bfd-desc"><p>' . $info['desc'] . '</p></div>';
  $html .= "\n";

  // Requirements tables
  if (!empty($info['frs'])) {
    $html .= "\n" . '        ' . reqTable('📋 المتطلبات الوظيفية (FR) المرتبطة', $info['frs'], 'FR');
  }
  if (!empty($info['nfrs'])) {
    $html .= "\n" . '        ' . reqTable('🔒 المتطلبات غير الوظيفية (NFR) المرتبطة', $info['nfrs'], 'NFR');
  }

  $html .= "\n" . '        <h3 style="color:#2c3e50;border-bottom:2px solid #eee;padding-bottom:8px;margin-top:30px;">جداول المجموعة</h3>';
  $html .= "\n";

  foreach ($bfd['tables'] as $t) {
    $idx++;
    list($name, $comment, $rows, $cols) = $t;
    $isView = (strpos($name, 'v_') === 0 || $name === 'student_subject_summary');

    $html .= '        <h2>' . $idx . '. ' . $name . ' (' . $comment . ')</h2>';
    $html .= "\n" . '        <div class="tbl-name">' . $name . ($isView ? ' <span class="view-label">VIEW</span>' : '') . ' <span class="note">— ' . number_format($rows) . ' صف' . ($isView ? ' (لا يخزن بيانات)' : '') . '</span></div>';
    $html .= "\n" . '        <table>';
    $html .= "\n" . '            <thead><tr><th>العمود</th><th>نوع البيانات</th><th>القيد</th><th>الوصف</th></tr></thead>';
    $html .= "\n" . '            <tbody>';

    foreach ($cols as $c) {
      $html .= "\n" . '                <tr><td>' . $c[0] . '</td><td>' . $c[1] . '</td><td>' . $c[2] . '</td><td>' . $c[3] . '</td></tr>';
    }

    $html .= "\n" . '            </tbody>';
    $html .= "\n" . '        </table>';
    $html .= "\n";
  }

  $html .= '    </div>';
}

$html .= "\n" . '</div>';
$html .= "\n" . '</body>';
$html .= "\n" . '</html>';

file_put_contents(__DIR__ . '/../docs/data_dictionary.html', $html);
echo "✅ Complete: " . (__DIR__ . '/../docs/data_dictionary.html') . "\n";
echo "   Size: " . number_format(strlen($html)) . " bytes\n";
echo "   Tables: $totalTables, Views: $totalViews, Columns: $totalCols\n";
