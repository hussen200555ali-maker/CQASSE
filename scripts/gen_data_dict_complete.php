<?php
/**
 * Generate complete Data Dictionary HTML for CQASSE
 * Uses hardcoded schema based on earlier research
 */

$database = 'eqas_db';

// ─── Complete schema data ─────────────────────────
// Each table: name, comment, rows, columns[], uniques[], isView
function table($name, $comment, $rows, $columns, $uniques = [], $isView = false) {
    return compact('name','comment','rows','columns','uniques','isView');
}
function col($name, $type, $constraints = '', $desc = '') {
    return compact('name','type','constraints','desc');
}

$schema = [
    // ═══ BFD 1: إدارة المستخدمين والصلاحيات والنظام ═══
    1 => [
        'name' => 'إدارة المستخدمين والصلاحيات والنظام',
        'color' => '#e74c3c',
        'tables' => [
            table('users', 'المستخدمون', 21, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمستخدم'),
                col('username','VARCHAR(50)','UNIQUE, NOT NULL','اسم المستخدم الفريد'),
                col('password_hash','VARCHAR(255)','NOT NULL','كلمة المرور المشفرة (bcrypt)'),
                col('must_change_password','TINYINT(1)','NOT NULL, DEFAULT 0','إجباري تغيير كلمة المرور عند أول تسجيل دخول'),
                col('full_name_ar','VARCHAR(200)','','الاسم الكامل بالعربية'),
                col('full_name_en','VARCHAR(200)','','الاسم الكامل بالإنجليزية'),
                col('email','VARCHAR(100)','','البريد الإلكتروني'),
                col('phone','VARCHAR(20)','','رقم الهاتف'),
                col('role_id','INT UNSIGNED','FK → roles.id','معرف دور المستخدم'),
                col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','معرف المدرسة (NULL للمستخدمين غير المرتبطين بمدرسة)'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('last_login_at','DATETIME','NULLABLE','آخر مرة سجل فيها دخول'),
                col('login_attempts','TINYINT(3)','NOT NULL, DEFAULT 0','عدد محاولات الدخول الفاشلة'),
                col('lockout_until','DATETIME','NULLABLE','وقت انتهاء حظر الحساب'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
                col('updated_at','DATETIME','NOT NULL, ON UPDATE','تاريخ آخر تحديث'),
            ], ['uk_username' => ['username']]),

            table('roles', 'الأدوار', 4, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للدور'),
                col('name','VARCHAR(50)','UNIQUE, NOT NULL','اسم الدور (group_admin, school_admin, teacher, parent)'),
                col('display_name_ar','VARCHAR(100)','','الاسم المعروض بالعربية'),
                col('display_name_en','VARCHAR(100)','','الاسم المعروض بالإنجليزية'),
                col('rank','INT','','ترتيب الدور في التسلسل الهرمي للصلاحيات'),
                col('description','TEXT','','وصف الدور'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
            ], ['name' => ['name']]),

            table('permissions', 'الصلاحيات', 36, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للصلاحية'),
                col('name','VARCHAR(100)','UNIQUE, NOT NULL','اسم الصلاحية'),
                col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
                col('module','VARCHAR(50)','NOT NULL','الوحدة التي تنتمي إليها الصلاحية (users, schools, eqi, إلخ)'),
                col('description','TEXT','','وصف الصلاحية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['name' => ['name']]),

            table('role_permissions', 'صلاحيات الأدوار (جسر M:N)', 87, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('role_id','INT UNSIGNED','FK → roles.id','معرف الدور'),
                col('permission_id','INT UNSIGNED','FK → permissions.id','معرف الصلاحية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_role_permission' => ['role_id','permission_id']]),

            table('activity_logs', 'سجل النشاطات', 94, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','معرف المستخدم (NULL للأنشطة النظامية)'),
                col('action','VARCHAR(100)','NOT NULL','نوع النشاط'),
                col('entity_type','VARCHAR(50)','','نوع الكيان المتأثر (مستخدم، مدرسة، طالب، إلخ)'),
                col('entity_id','INT UNSIGNED','','معرف الكيان المتأثر'),
                col('status','ENUM','NOT NULL','حالة النشاط (success, failure, pending)'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('description','TEXT','','وصف النشاط'),
                col('details_json','JSON','NULLABLE','تفاصيل إضافية بصيغة JSON'),
                col('ip_address','VARCHAR(45)','','عنوان IP للمستخدم'),
                col('user_agent','TEXT','','معلومات متصفح المستخدم'),
                col('created_at','DATETIME','NOT NULL','تاريخ النشاط'),
            ], []),

            table('system_settings', 'إعدادات النظام', 138, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للإعداد'),
                col('category','VARCHAR(50)','NOT NULL','فئة الإعداد (system, notification, policy, إلخ)'),
                col('setting_key','VARCHAR(100)','NOT NULL','مفتاح الإعداد'),
                col('setting_value','TEXT','','قيمة الإعداد'),
                col('value_type','ENUM','NOT NULL','نوع القيمة (string, integer, boolean, json)'),
                col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
                col('description_ar','TEXT','','الوصف بالعربية'),
                col('is_system','TINYINT(1)','NOT NULL','هل هو إعداد نظامي (لا يمكن حذفه)'),
                col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','معرف المدرسة (NULL = إعداد عام)'),
                col('sort_order','INT','','ترتيب العرض'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_setting' => ['category','setting_key','school_id']]),

            table('user_preferences', 'تفضيلات المستخدم', 20, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للتفضيل'),
                col('user_id','INT UNSIGNED','FK → users.id','معرف المستخدم'),
                col('pref_type','ENUM','NOT NULL','نوع التفضيل (ui, notification, dashboard)'),
                col('pref_key','VARCHAR(100)','NOT NULL','مفتاح التفضيل'),
                col('pref_value','JSON','','قيمة التفضيل بصيغة JSON'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_user_pref' => ['user_id','pref_type','pref_key']]),

            table('export_history', 'سجل التصدير', 7, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد لسجل التصدير'),
                col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','معرف المستخدم الذي طلب التصدير'),
                col('export_type','VARCHAR(50)','NOT NULL','نوع التصدير (excel, csv, pdf)'),
                col('file_name','VARCHAR(255)','','اسم الملف المصدَّر'),
                col('file_path','VARCHAR(500)','','مسار الملف المصدَّر'),
                col('file_size','INT UNSIGNED','','حجم الملف بالبايت'),
                col('row_count','INT UNSIGNED','','عدد السجلات في التصدير'),
                col('filters','JSON','','معايير التصدير بصيغة JSON'),
                col('status','ENUM','NOT NULL','حالة التصدير (pending, completed, failed)'),
            ], []),
        ]
    ],

    // ═══ BFD 2: البيانات الأساسية والتسجيل ═══
    2 => [
        'name' => 'البيانات الأساسية والتسجيل',
        'color' => '#3498db',
        'tables' => [
            table('governorates', 'المحافظات', 22, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمحافظة'),
                col('name_ar','VARCHAR(100)','NOT NULL','اسم المحافظة بالعربية'),
                col('name_en','VARCHAR(100)','','اسم المحافظة بالإنجليزية'),
                col('code','VARCHAR(5)','UNIQUE, NOT NULL','رمز المحافظة'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['code' => ['code']]),

            table('schools', 'المدارس', 4, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمدرسة'),
                col('name_ar','VARCHAR(200)','NOT NULL','اسم المدرسة بالعربية'),
                col('name_en','VARCHAR(200)','','اسم المدرسة بالإنجليزية'),
                col('reference_code','VARCHAR(5)','UNIQUE, NOT NULL','الرمز المرجعي للمدرسة'),
                col('address','TEXT','','عنوان المدرسة'),
                col('governorate','VARCHAR(100)','NOT NULL','المحافظة (نص، ليس FK)'),
                col('district','VARCHAR(100)','','المنطقة (نص، ليس FK)'),
                col('school_type','ENUM(basic,secondary,combined)','NOT NULL','نوع المدرسة'),
                col('principal_name','VARCHAR(200)','','اسم مدير المدرسة'),
                col('phone','VARCHAR(20)','','رقم هاتف المدرسة'),
                col('email','VARCHAR(100)','','البريد الإلكتروني للمدرسة'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
                col('updated_at','DATETIME','NOT NULL, ON UPDATE','تاريخ آخر تحديث'),
            ], ['reference_code' => ['reference_code']]),

            table('academic_years', 'السنوات الدراسية', 4, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسنة الدراسية'),
                col('name','VARCHAR(50)','NOT NULL','اسم السنة الدراسية'),
                col('start_date','DATE','NOT NULL','تاريخ البداية'),
                col('end_date','DATE','NOT NULL','تاريخ النهاية'),
                col('is_current','TINYINT(1)','NOT NULL','هل هي السنة الحالية'),
                col('status','ENUM(planning,active,evaluation,closed)','NOT NULL','حالة السنة الدراسية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
            ], []),

            table('semesters', 'الفصول الدراسية', 7, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للفصل الدراسي'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية التابعة'),
                col('name_ar','VARCHAR(50)','NOT NULL','اسم الفصل بالعربية'),
                col('start_date','DATE','NOT NULL','تاريخ البداية'),
                col('end_date','DATE','NOT NULL','تاريخ النهاية'),
                col('is_current','TINYINT(1)','NOT NULL','هل هو الفصل الحالي'),
                col('lock_level','ENUM','NOT NULL','مستوى القفل (مفتوح، مغلق للتعديل، مغلق بالكامل)'),
                col('locked_at','DATETIME','NULLABLE','تاريخ القفل'),
                col('locked_by','INT UNSIGNED','FK → users.id, NULLABLE','من قام بالقفل'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_semester' => ['academic_year_id','name_ar']]),

            table('grade_levels', 'المستويات الدراسية', 12, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمستوى'),
                col('name_ar','VARCHAR(50)','NOT NULL','الاسم بالعربية'),
                col('name_en','VARCHAR(50)','','الاسم بالإنجليزية'),
                col('level_number','TINYINT(3)','UNIQUE, NOT NULL','رقم المستوى (1-12)'),
                col('stage','ENUM(basic,secondary)','NOT NULL','المرحلة (أساسي/ثانوي)'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['level_number' => ['level_number']]),

            table('classes', 'الشعب الدراسية', 43, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للشعبة'),
                col('name_ar','VARCHAR(100)','NOT NULL','اسم الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى الدراسي'),
                col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
                col('teacher_id','INT UNSIGNED','FK → users.id, NULLABLE','معلم الصف (المشرف)'),
                col('capacity','INT UNSIGNED','','الطاقة الاستيعابية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('subjects', 'المواد الدراسية', 12, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمادة'),
                col('name_ar','VARCHAR(100)','NOT NULL','اسم المادة بالعربية'),
                col('name_en','VARCHAR(100)','','اسم المادة بالإنجليزية'),
                col('code','VARCHAR(20)','UNIQUE, NOT NULL','رمز المادة'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['code' => ['code']]),

            table('subject_grade_years', 'ربط المواد بالمستويات', 97, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
                col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى الدراسي'),
                col('hours_per_week','TINYINT(3)','','عدد الحصص الأسبوعية'),
                col('passing_grade','DECIMAL(5,2)','','درجة النجاح'),
                col('weights_json','JSON','','أوزان التقويم بصيغة JSON'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_sgy' => ['subject_id','grade_level_id']]),

            table('subject_weight_distribution', 'توزيع أوزان المواد', 15, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('subject_grade_year_id','INT UNSIGNED','FK → subject_grade_years.id','رابط المادة-المستوى'),
                col('assessment_type_id','INT UNSIGNED','FK → assessment_types.id','نوع التقييم'),
                col('weight','DECIMAL(5,2)','NOT NULL','الوزن النسبي'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_swd' => ['subject_grade_year_id','assessment_type_id','academic_year_id']]),

            table('subject_teachers', 'تعيينات المعلمين للمواد', 98, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
                col('teacher_id','INT UNSIGNED','FK → users.id','المعلم'),
                col('classroom_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
            ], ['uk_subject_teacher' => ['subject_id','teacher_id','classroom_id','semester_id']]),

            table('teacher_assignments', 'تعيينات مبسطة للمعلمين', 3, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('teacher_id','INT UNSIGNED','FK → users.id','المعلم'),
                col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('is_active','TINYINT(1)','DEFAULT 1','الحالة'),
                col('created_at','DATETIME','DEFAULT CURRENT_TIMESTAMP','تاريخ الإنشاء'),
            ], ['uk_teacher_asgn' => ['teacher_id','subject_id','class_id','academic_year_id']]),

            table('students', 'الطلاب', 549, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للطالب'),
                col('student_number','VARCHAR(20)','UNIQUE, NOT NULL','رقم الطالب الفريد'),
                col('full_name_ar','VARCHAR(200)','NOT NULL','الاسم الكامل بالعربية'),
                col('full_name_en','VARCHAR(200)','','الاسم الكامل بالإنجليزية'),
                col('guardian_name','VARCHAR(200)','','اسم ولي الأمر'),
                col('guardian_phone','VARCHAR(20)','','رقم هاتف ولي الأمر'),
                col('guardian_email','VARCHAR(100)','','بريد ولي الأمر الإلكتروني'),
                col('grade_level_id','INT UNSIGNED','FK → grade_levels.id','المستوى الدراسي'),
                col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
                col('enrollment_date','DATE','','تاريخ التسجيل'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['student_number' => ['student_number']]),

            table('student_enrollments', 'تسجيلات الطلاب في الشعب', 551, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للتسجيل'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('enrollment_date','DATE','','تاريخ التسجيل'),
                col('status','ENUM(enrolled,transferred,dropped)','NOT NULL','حالة التسجيل'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], ['uk_enrollment' => ['student_id','class_id','academic_year_id']]),

            table('student_notes', 'ملاحظات الطلاب', 1, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للملاحظة'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('note_type','ENUM(general,academic,behavioral,attendance)','NOT NULL','نوع الملاحظة'),
                col('title_ar','VARCHAR(200)','NOT NULL','عنوان الملاحظة'),
                col('content_ar','TEXT','NOT NULL','محتوى الملاحظة'),
                col('priority','ENUM(low,medium,high,urgent)','NOT NULL','الأولوية'),
                col('is_pinned','TINYINT(1)','NOT NULL, DEFAULT 0','مثبتة'),
                col('is_draft','TINYINT(1)','NOT NULL, DEFAULT 0','مسودة'),
                col('attachment_id','INT UNSIGNED','NULLABLE','معرف المرفق (ليس FK)'),
                col('created_by','INT UNSIGNED','FK → users.id','منشئ الملاحظة'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
                col('updated_at','DATETIME','NOT NULL, ON UPDATE','تاريخ آخر تحديث'),
            ], []),

            table('attachments', 'الملفات المرفقة', 0, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للمرفق'),
                col('file_name','VARCHAR(255)','NOT NULL','اسم الملف'),
                col('file_type','VARCHAR(100)','','نوع الملف'),
                col('file_path','VARCHAR(500)','NOT NULL','مسار الملف'),
                col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة'),
                col('file_size','INT UNSIGNED','','حجم الملف بالبايت'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
                col('updated_at','DATETIME','NOT NULL, ON UPDATE','تاريخ آخر تحديث'),
            ], []),
        ]
    ],

    // ═══ BFD 3: إدارة الحضور والسلوك والمهارات ═══
    3 => [
        'name' => 'إدارة الحضور والسلوك والمهارات',
        'color' => '#2ecc71',
        'tables' => [
            table('attendance_daily', 'سجلات الحضور اليومي', 10278, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('date','DATE','NOT NULL','التاريخ'),
                col('period_number','TINYINT(3) UNSIGNED','NULLABLE','رقم الحصة (NULL = يومي)'),
                col('status','ENUM(present,absent,late,excused)','NOT NULL','حالة الحضور'),
                col('notes','TEXT','NULLABLE','ملاحظات'),
                col('amendment_log','JSON','NULLABLE','سجل التعديلات'),
                col('recorded_by','INT UNSIGNED','FK → users.id, NULLABLE','المسجل'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
            ], ['uk_attendance' => ['student_id','class_id','date','period_number']]),

            table('behavior_categories', 'فئات السلوك', 16, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للفئة'),
                col('name_ar','VARCHAR(100)','NOT NULL','اسم الفئة بالعربية'),
                col('name_en','VARCHAR(100)','','اسم الفئة بالإنجليزية'),
                col('type','ENUM(positive,negative)','NOT NULL','نوع السلوك (إيجابي/سلبي)'),
                col('points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المقابلة'),
                col('school_id','INT UNSIGNED','FK → schools.id, NULLABLE','المدرسة (NULL = عام)'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('behavior_incidents', 'حوادث السلوك', 210, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للحادثة'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('category_id','INT UNSIGNED','FK → behavior_categories.id','فئة السلوك'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('incident_date','DATE','NOT NULL','تاريخ الحادثة'),
                col('description','TEXT','NULLABLE','وصف الحادثة'),
                col('points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط'),
                col('reported_by','INT UNSIGNED','FK → users.id, NULLABLE','المبلغ'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('created_at','DATETIME','NOT NULL','تاريخ الإنشاء'),
            ], []),

            table('behavior_scores', 'نتائج السلوك المحتسبة', 0, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للسجل'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('starting_points','DECIMAL(5,2)','NOT NULL, DEFAULT 100','نقطة البداية'),
                col('earned_points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المكتسبة'),
                col('deducted_points','DECIMAL(5,2)','NOT NULL, DEFAULT 0','النقاط المخصومة'),
                col('current_percentage','DECIMAL(5,2)','NOT NULL, DEFAULT 100','النسبة الحالية'),
                col('is_passing','TINYINT(1)','NOT NULL, DEFAULT 1','هل اجتاز'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
                col('updated_at','DATETIME','NOT NULL, ON UPDATE','تاريخ آخر تحديث'),
            ], []),
        ]
    ],

    // ═══ BFD 4: التقييم الشامل وجودة التعليم ═══
    4 => [
        'name' => 'التقييم الشامل وجودة التعليم',
        'color' => '#9b59b6',
        'tables' => [
            table('assessment_types', 'أنواع التقييم', 5, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد لنوع التقييم'),
                col('name_ar','VARCHAR(100)','NOT NULL','الاسم بالعربية'),
                col('name_en','VARCHAR(100)','','الاسم بالإنجليزية'),
                col('description','TEXT','','الوصف'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('assessment_forms', 'استمارات التقييم', 8, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للاستمارة'),
                col('form_key','ENUM(...)','NOT NULL','المفتاح (behavior_eval, skills_checklist, teaching_self, إلخ)'),
                col('name_ar','VARCHAR(200)','NOT NULL','الاسم بالعربية'),
                col('name_en','VARCHAR(200)','','الاسم بالإنجليزية'),
                col('category','ENUM(behavior,skills,teaching,climate)','NOT NULL','الفئة'),
                col('target','ENUM(student,teacher,school)','NOT NULL','الهدف'),
                col('description','TEXT','','الوصف'),
                col('is_system','TINYINT(1)','NOT NULL','هل هي استمارة نظامية'),
                col('is_default','TINYINT(1)','DEFAULT 0','هل هي الاستمارة الافتراضية'),
                col('version','VARCHAR(50)','','الإصدار'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('assessment_structure', 'هيكل التقييم (معايير وفقرات)', 237, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('form_id','INT UNSIGNED','FK → assessment_forms.id','الاستمارة'),
                col('parent_id','INT UNSIGNED','FK → assessment_structure.id, NULLABLE','المعيار الأب (Self-ref: NULL = معيار)'),
                col('type','ENUM(criteria,item)','NOT NULL','النوع (معيار/فقرة)'),
                col('name_ar','VARCHAR(200)','','الاسم بالعربية'),
                col('name_en','VARCHAR(200)','','الاسم بالإنجليزية'),
                col('text_ar','TEXT','','النص بالعربية'),
                col('text_en','TEXT','','النص بالإنجليزية'),
                col('tool_type','ENUM','','نوع الأداة'),
                col('weight','DECIMAL(5,2)','','الوزن'),
                col('is_inverse','TINYINT(1)','','عكسي (كلما زادت القيمة سلباً)'),
                col('max_value','DECIMAL(10,2)','','القيمة القصوى'),
                col('sort_order','INT UNSIGNED','','ترتيب العرض'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('assessment_responses', 'إجابات التقييم (الجدول المركزي)', 116, [
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
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('tests', 'الاختبارات', 106, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للاختبار'),
                col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
                col('class_id','INT UNSIGNED','FK → classes.id','الشعبة'),
                col('assessment_type_id','INT UNSIGNED','FK → assessment_types.id','نوع التقييم'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('title_ar','VARCHAR(200)','NOT NULL','عنوان الاختبار'),
                col('max_grade','DECIMAL(5,2)','NOT NULL','الدرجة العظمى'),
                col('test_date','DATE','','تاريخ الاختبار'),
                col('status','ENUM(draft,published,locked)','NOT NULL','الحالة'),
                col('created_by','INT UNSIGNED','FK → users.id','منشئ الاختبار'),
            ], []),

            table('grades', 'درجات الطلاب', 2076, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد للدرجة'),
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
            ], ['uk_grade' => ['student_id','test_id']]),

            table('learning_outcomes', 'مخرجات التعلم', 37, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('subject_id','INT UNSIGNED','FK → subjects.id','المادة'),
                col('code','VARCHAR(20)','','رمز المخرج'),
                col('description_ar','TEXT','','الوصف بالعربية'),
                col('description_en','TEXT','','الوصف بالإنجليزية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('evaluations', 'نتائج التقييم الموحدة', 539, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('eval_type','ENUM(teaching_summary,behavior_score)','NOT NULL','نوع التقييم'),
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
            ], []),

            table('surveys', 'الاستبيانات (مناخ + مرافق)', 7, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('survey_type','ENUM(climate,facility)','NOT NULL','نوع الاستبيان'),
                col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('respondent_type','ENUM','NOT NULL','نوع المستجيب'),
                col('respondent_id','INT UNSIGNED','FK → users.id, NULLABLE','المستجيب'),
                col('evaluator_id','INT UNSIGNED','FK → users.id, NULLABLE','المقيم'),
                col('survey_date','DATE','','تاريخ الاستبيان'),
                col('overall_score','DECIMAL(5,2)','NULLABLE','الدرجة الكلية'),
                col('notes','TEXT','NULLABLE','ملاحظات'),
                col('status','ENUM(draft,active,closed)','NOT NULL','الحالة'),
                col('created_by','INT UNSIGNED','FK → users.id','المنشئ'),
            ], []),

            table('survey_items', 'إجابات فقرات الاستبيان', 13, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('survey_id','INT UNSIGNED','FK → surveys.id','الاستبيان'),
                col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
                col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
                col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('climate_items', 'فقرات المناخ المدرسي (قديم)', 48, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('survey_id','INT UNSIGNED','FK → surveys.id','الاستبيان'),
                col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
                col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
                col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('facility_items', 'فقرات تقييم المرافق (قديم)', 0, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('checklist_id','INT UNSIGNED','FK → surveys.id','قائمة التحقق'),
                col('item_id','INT UNSIGNED','FK → assessment_structure.id','الفقرة'),
                col('numeric_value','DECIMAL(10,4)','NULLABLE','القيمة الرقمية'),
                col('normalized_value','DECIMAL(5,2)','NULLABLE','القيمة المعيارية'),
                col('notes','TEXT','NULLABLE','ملاحظات'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),

            table('eqi_results', 'نتائج مؤشر جودة التعليم EQI', 7, [
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
            ], ['uk_eqi' => ['school_id','academic_year_id','semester_id']]),

            table('improvement_plans', 'خطط التحسين الموحدة', 3, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('plan_type','ENUM(student,school,teacher)','NOT NULL','نوع الخطة'),
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
                col('status','ENUM(active,completed,cancelled)','NOT NULL','الحالة'),
                col('progress','INT','DEFAULT 0','نسبة التقدم'),
                col('follow_up_date','DATE','NULLABLE','تاريخ المتابعة'),
                col('created_by','INT UNSIGNED','FK → users.id','المنشئ'),
                col('updated_by','INT UNSIGNED','FK → users.id','معدّل الخطة'),
            ], []),

            table('school_improvement_plans', 'خطط تحسين المدارس (قديم)', 2, [
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
                col('updated_by','INT UNSIGNED','FK → users.id','معدّل الخطة'),
            ], []),

            table('promotions', 'قرارات الترفيع', 519, [
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
                col('decision','ENUM(promoted,repeated,conditional,under_review)','NOT NULL','القرار'),
                col('status','ENUM(proposed,approved,rejected)','NOT NULL','الحالة'),
                col('override_json','JSON','NULLABLE','بيانات التجاوز'),
                col('approved_by','INT UNSIGNED','FK → users.id, NULLABLE','المعتمد'),
                col('decided_by','INT UNSIGNED','FK → users.id, NULLABLE','مصدر القرار'),
            ], ['uk_promotion' => ['student_id','academic_year_id','semester_id']]),

            table('promotion_overrides', 'تجاوزات الترفيع', 0, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('academic_year_id','INT UNSIGNED','FK → academic_years.id','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','FK → semesters.id','الفصل الدراسي'),
                col('override_type','ENUM','NOT NULL','نوع التجاوز'),
                col('override_value','DECIMAL(5,2)','NULLABLE','قيمة التجاوز'),
                col('reason','TEXT','','السبب'),
                col('approved_by','INT UNSIGNED','FK → users.id','المعتمد'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),
        ]
    ],

    // ═══ BFD 5: التقارير والتحليلات (المشاهد) ═══
    5 => [
        'name' => 'التقارير والتحليلات (المشاهد)',
        'color' => '#f39c12',
        'tables' => [
            table('v_student_current', 'الطلاب الحاليون (مشهد)', 0, [
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
            ], [], true),

            table('v_student_subject_summary', 'ملخص درجات الطالب لكل مادة (مشهد)', 0, [
                col('student_id','INT UNSIGNED','','الطالب'),
                col('subject_id','INT UNSIGNED','','المادة'),
                col('class_id','INT UNSIGNED','','الشعبة'),
                col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
                col('total_grade','DECIMAL(7,2)','','مجموع الدرجات'),
                col('max_grade','DECIMAL(7,2)','','مجموع الدرجات العظمى'),
                col('percentage','DECIMAL(5,2)','','النسبة المئوية'),
            ], [], true),

            table('student_subject_summary', 'ملخص الطالب-المادة مع GPA (مشهد)', 0, [
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
            ], [], true),

            table('v_semester_scores', 'معدلات الفصل الدراسي (مشهد)', 0, [
                col('student_id','INT UNSIGNED','','الطالب'),
                col('academic_year_id','INT UNSIGNED','','السنة الدراسية'),
                col('semester_id','INT UNSIGNED','','الفصل الدراسي'),
                col('gpa','DECIMAL(3,2)','','المعدل التراكمي'),
                col('total_percentage','DECIMAL(5,2)','','النسبة المئوية الكلية'),
            ], [], true),

            table('v_attendance_summary', 'ملخص الحضور (مشهد)', 0, [
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
            ], [], true),
        ]
    ],

    // ═══ BFD 6: التواصل وبوابة المستفيدين ═══
    6 => [
        'name' => 'التواصل وبوابة المستفيدين',
        'color' => '#1abc9c',
        'tables' => [
            table('alerts', 'التنبيهات', 120, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('school_id','INT UNSIGNED','FK → schools.id','المدرسة'),
                col('student_id','INT UNSIGNED','FK → students.id, NULLABLE','الطالب'),
                col('user_id','INT UNSIGNED','FK → users.id, NULLABLE','المستخدم'),
                col('alert_type','ENUM(...)','NOT NULL','نوع التنبيه'),
                col('severity','ENUM(info,warning,danger,critical)','NOT NULL','الخطورة'),
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
            ], []),

            table('notifications', 'الإشعارات', 253, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('user_id','INT UNSIGNED','FK → users.id','المستخدم'),
                col('type','VARCHAR(50)','','نوع الإشعار'),
                col('title_ar','VARCHAR(200)','','العنوان'),
                col('message_ar','TEXT','','الرسالة'),
                col('related_entity_type','VARCHAR(50)','','نوع الكيان المرتبط'),
                col('related_entity_id','INT UNSIGNED','','معرف الكيان المرتبط'),
                col('is_read','TINYINT(1)','NOT NULL, DEFAULT 0','مقروء'),
                col('read_at','DATETIME','NULLABLE','تاريخ القراءة'),
            ], []),

            table('parent_students', 'ربط ولي الأمر بالطالب', 3, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('parent_id','INT UNSIGNED','FK → users.id','ولي الأمر'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('relationship','ENUM(father,mother,guardian,other)','NOT NULL','صلة القرابة'),
                col('ack_grade','TINYINT(1)','DEFAULT 0','إقرار الدرجات'),
                col('ack_behavior','TINYINT(1)','DEFAULT 0','إقرار السلوك'),
                col('ack_attendance','TINYINT(1)','DEFAULT 0','إقرار الحضور'),
                col('ack_promotion','TINYINT(1)','DEFAULT 0','إقرار الترفيع'),
                col('last_ack_at','DATETIME','NULLABLE','آخر إقرار'),
            ], ['uk_parent_student' => ['parent_id','student_id']]),

            table('parent_acknowledgments', 'إقرارات ولي الأمر', 0, [
                col('id','INT UNSIGNED','PK, AUTO_INCREMENT','المعرف الفريد'),
                col('parent_id','INT UNSIGNED','FK → users.id','ولي الأمر'),
                col('student_id','INT UNSIGNED','FK → students.id','الطالب'),
                col('relationship','ENUM(father,mother,guardian,other)','NOT NULL','صلة القرابة'),
                col('ack_grade','TINYINT(1)','DEFAULT 0','إقرار الدرجات'),
                col('ack_behavior','TINYINT(1)','DEFAULT 0','إقرار السلوك'),
                col('ack_attendance','TINYINT(1)','DEFAULT 0','إقرار الحضور'),
                col('ack_promotion','TINYINT(1)','DEFAULT 0','إقرار الترفيع'),
                col('last_ack_at','DATETIME','NULLABLE','آخر إقرار'),
                col('is_active','TINYINT(1)','NOT NULL, DEFAULT 1','الحالة: نشط/غير نشط'),
            ], []),
        ]
    ],
];

// ═══════════════════════════════════════════════════
// HTML GENERATION
// ═══════════════════════════════════════════════════

echo "Building HTML...\n";

$html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قاموس بيانات نظام CQASSE</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Traditional Arabic", "Arial", sans-serif;
            line-height: 1.7;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            direction: rtl;
            color: #333;
        }
        h1 {
            text-align: center;
            color: #fff;
            font-size: 2.2em;
            margin: 10px 0 5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .subtitle {
            text-align: center;
            color: rgba(255,255,255,0.85);
            margin-bottom: 30px;
            font-size: 1.1em;
        }
        .stat-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-bottom: 30px;
        }
        .stat-pill {
            padding: 8px 18px;
            border-radius: 20px;
            color: #fff;
            font-size: 0.9em;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .bfd-section {
            background: #fff;
            border-radius: 16px;
            margin-bottom: 40px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            overflow: hidden;
        }
        .bfd-header {
            padding: 16px 24px;
            margin: -20px -20px 25px -20px;
            color: #fff;
            font-size: 1.5em;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .bfd-count { font-size: 0.65em; opacity: 0.9; }
        .tbl-card {
            border: 1px solid #e8e8e8;
            border-radius: 12px;
            margin-bottom: 28px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .tbl-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .tbl-header {
            background: #f8f9fa;
            padding: 14px 18px;
            font-weight: bold;
            font-size: 1.1em;
            border-bottom: 3px solid #3498db;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .tbl-meta { font-size: 0.8em; color: #7f8c8d; font-weight: normal; }
        .tbl-comment {
            padding: 10px 18px;
            background: #fef9e7;
            border-bottom: 1px solid #eee;
            color: #856404;
            font-size: 0.95em;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.93em;
        }
        th {
            background: #f1f3f5;
            padding: 10px 14px;
            text-align: right;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
        }
        td {
            padding: 8px 14px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        tr:hover { background: #f8f9fa; }
        .row-pk { background: #fff8e1; }
        .row-fk { background: #e8f5e9; }
        .row-pk.row-fk { background: #fff3e0; }
        code {
            background: #e9ecef;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.9em;
            color: #d63384;
            font-family: "Consolas", "Courier New", monospace;
        }
        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 5px;
            font-size: 0.78em;
            margin: 1px 2px;
            font-weight: bold;
        }
        .ba-pk { background: #ffc107; color: #333; }
        .ba-fk { background: #28a745; color: #fff; }
        .ba-nn { background: #dc3545; color: #fff; }
        .ba-nu { background: #6c757d; color: #fff; }
        .ba-df { background: #6f42c1; color: #fff; }
        .ba-ai { background: #e67e22; color: #fff; }
        .ba-vi { background: #17a2b8; color: #fff; }
        .unique-box {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            padding: 10px 16px;
            border-top: 0;
            font-size: 0.9em;
            color: #004085;
        }
        .view-box {
            background: #fff3cd;
            border-bottom: 1px solid #ffc107;
            padding: 10px 16px;
            font-size: 0.9em;
            color: #856404;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: rgba(255,255,255,0.7);
            font-size: 0.9em;
        }
        @media print {
            body { background: #fff; padding: 10px; }
            .bfd-section { break-inside: avoid; }
            .stat-bar { display: none; }
            h1 { color: #333; text-shadow: none; }
            .subtitle { color: #666; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 قاموس بيانات نظام CQASSE</h1>
    <p class="subtitle">نظام التقييم الشامل لجودة التعليم المدرسي — توثيق كامل لقاعدة البيانات</p>
';

// Stats
$totalTables = 0;
$totalCols = 0;
foreach ($schema as $bfd) {
    $totalTables += count($bfd['tables']);
    foreach ($bfd['tables'] as $t) $totalCols += count($t['columns']);
}

$html .= '<div class="stat-bar">';
foreach ($schema as $bid => $bfd) {
    $html .= '<span class="stat-pill" style="background:' . $bfd['color'] . ';">BFD ' . $bid . ': ' . count($bfd['tables']) . '</span>';
}
$html .= '<span class="stat-pill" style="background:#636e72;">المجموع: ' . $totalTables . ' جدول/مشهد</span>';
$html .= '<span class="stat-pill" style="background:#636e72;">' . $totalCols . ' عموداً</span>';
$html .= '</div>';

// ─── Generate sections ─────────────────────────────
foreach ($schema as $bid => $bfd) {
    $html .= '<div class="bfd-section">
        <div class="bfd-header" style="background:' . $bfd['color'] . ';">
            <span>📂 BFD ' . $bid . ' — ' . $bfd['name'] . '</span>
            <span class="bfd-count">' . count($bfd['tables']) . ' جدولاً</span>
        </div>';

    $idx = 0;
    foreach ($bfd['tables'] as $tbl) {
        $idx++;
        $isView = !empty($tbl['isView']);
        $rows = $tbl['rows'];

        $html .= '<div class="tbl-card">
            <div class="tbl-header" style="border-bottom-color:' . $bfd['color'] . ';">
                <span>' . $idx . '. <code style="font-size:1.05em;background:transparent;color:#2c3e50;">' . $tbl['name'] . '</code>
                    <span class="tbl-meta">' . ($isView ? '🔍 VIEW' : '🗄️ TABLE') . ' — ' . number_format($rows) . ' صف</span>
                </span>
                <span class="tbl-meta">' . count($tbl['columns']) . ' عموداً' . ($isView ? ' (مشهد)' : '') . '</span>
            </div>';

        if (!empty($tbl['comment'])) {
            $html .= '<div class="tbl-comment">📝 ' . $tbl['comment'] . '</div>';
        }

        if ($isView) {
            $html .= '<div class="view-box">🔍 هذا الكائن هو VIEW (مشهد) — لا يخزن بيانات بل يُشتق من استعلام SQL على جداول أخرى.</div>';
        }

        $html .= '<table>
            <thead><tr>
                <th style="width:22%;">العمود</th>
                <th style="width:22%;">نوع البيانات</th>
                <th style="width:22%;">القيود</th>
                <th style="width:34%;">الوصف</th>
            </tr></thead>
            <tbody>';

        foreach ($tbl['columns'] as $col) {
            $isPK = strpos($col['constraints'], 'PK') !== false;
            $isFK = strpos($col['constraints'], 'FK') !== false;
            $isAI = strpos($col['constraints'], 'AUTO_INCREMENT') !== false;
            $isNullable = strpos($col['constraints'], 'NULLABLE') !== false;
            $isNotNull = strpos($col['constraints'], 'NOT NULL') !== false;
            $isUnique = strpos($col['constraints'], 'UNIQUE') !== false;

            $rowClass = '';
            if ($isPK && $isFK) $rowClass = 'row-pk row-fk';
            elseif ($isPK) $rowClass = 'row-pk';
            elseif ($isFK) $rowClass = 'row-fk';

            $badges = [];
            if ($isPK) $badges[] = '<span class="badge ba-pk">PK</span>';
            if ($isFK) $badges[] = '<span class="badge ba-fk">FK</span>';
            if ($isAI) $badges[] = '<span class="badge ba-ai">AI</span>';
            if ($isNotNull && !$isPK) $badges[] = '<span class="badge ba-nn">NOT NULL</span>';
            if ($isNullable) $badges[] = '<span class="badge ba-nu">NULL</span>';
            if ($isUnique && !$isPK) $badges[] = '<span class="badge" style="background:#17a2b8;color:#fff;">UNIQUE</span>';

            // Extract DEFAULT if present
            if (preg_match('/DEFAULT\s+(\S+)/', $col['constraints'], $m)) {
                $badges[] = '<span class="badge ba-df">=' . htmlspecialchars($m[1]) . '</span>';
            }

            $html .= '<tr class="' . $rowClass . '">
                <td><code>' . htmlspecialchars($col['name']) . '</code></td>
                <td>' . htmlspecialchars($col['type']) . '</td>
                <td>' . implode(' ', $badges) . '</td>
                <td>' . htmlspecialchars($col['desc']) . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        // UNIQUE constraints
        if (!empty($tbl['uniques'])) {
            $html .= '<div class="unique-box">🔑 <strong>القيود الفريدة (UNIQUE):</strong><br>';
            foreach ($tbl['uniques'] as $name => $cols) {
                $html .= '🔹 <code>' . htmlspecialchars($name) . '</code>: (<code>' . implode('</code>, <code>', $cols) . '</code>)<br>';
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // tbl-card
    }
    $html .= '</div>'; // bfd-section
}

$html .= '<div class="footer">
    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.2);margin:20px 0;">
    تم توليد قاموس البيانات هذا تلقائياً من قاعدة البيانات — نظام CQASSE v4.0.0
</div>
</div>
</body>
</html>';

// ─── Write file ────────────────────────────────────
$path = __DIR__ . '/../docs/data_dictionary_complete.html';
file_put_contents($path, $html);

echo "✅ COMPLETE\n";
echo "📄 Path: " . realpath($path) . "\n";
echo "📏 Size: " . number_format(strlen($html)) . " bytes\n";
echo "📊 Tables/Views: " . $totalTables . "\n";
echo "📊 Columns: " . $totalCols . "\n";
