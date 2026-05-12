# سجل الأخطاء (Bug Log)

| المعرف | التاريخ | الوصف | الملفات المتأثرة | الخطورة | الحالة | تاريخ الإصلاح |
|--------|---------|-------|------------------|---------|--------|---------------|
| BUG-01 | 2026-05-11 | `currentSemesterId()` تستخدم `lock_level = 'none'` بدلاً من `is_current = 1` — خطأ في المنطق | `includes/helpers.php:848` | عالية (حرجة) | ✅ تم الإصلاح | 2026-05-11 |
| BUG-02 | 2026-05-11 | `currentAcademicYearId()` تقرأ من `$_SESSION['current_academic_year_id']` بينما `auth.php:194` تخزن في `academic_year_id` — تباين مفتاح الجلسة | `includes/helpers.php:816-825` | متوسطة | ✅ تم الإصلاح | 2026-05-11 |
| BUG-03 | 2026-05-11 | ثغرة صلاحية: `semester_setup.php` تسمح لـ `school_admin` بالدخول وتنفيذ جميع الإجراءات (إنشاء، تعديل، قفل) | `pages/setup/semester_setup.php:14` | عالية (أمنية) | ✅ تم الإصلاح | 2026-05-11 |
| BUG-04 | 2026-05-11 | زر "تعيين الفصل الحالي" يظهر لـ `school_admin` رغم أنه إعداد عام — يمكنه تغيير `is_current` عبر الـ UI | `pages/academic/semesters.php:196` | متوسطة | ✅ تم الإصلاح | 2026-05-11 |
| BUG-05 | 2026-05-11 | إحصائيات الفصول (الشعب، التسجيلات، التقييمات) غير مفلترة حسب `school_id` — `school_admin` يرى بيانات جميع المدارس | `pages/academic/semesters.php:41,124,131` | متوسطة | ✅ تم الإصلاح | 2026-05-11 |
| BUG-06 | 2026-05-12 | `school_admin` لا يمكنه إدارة معلمي مدرسته — لا يوجد فلتر ولا إضافة | `pages/users/index.php`, `add.php` | عالية | ✅ تم الإصلاح | 2026-05-12 |
| BUG-07 | 2026-05-12 | لا يوجد إجبار لتغيير كلمة المرور عند أول تسجيل دخول للحسابات المولّدة | `login.php`, `change_password.php` | متوسطة | ✅ تم الإصلاح | 2026-05-12 |
| BUG-08 | 2026-05-12 | خطأ وقت التشغيل: `hasPermission()` تُستدعى قبل تضمين `header.php` في `add.php` → خطأ `undefined function` | `pages/users/add.php:16` | عالية | ✅ تم الإصلاح | 2026-05-12 |
| BUG-09 | 2026-05-12 | خطأ SQL: العمود `name_ar` غير موجود في جدول `roles` (اسمه `display_name_ar`) — يؤثر على 4 ملفات: `add.php`, `index.php`, `edit.php`, `import.php` | `pages/users/add.php:27`, `index.php:92`, `edit.php:42`, `import.php:25` | عالية | ✅ تم الإصلاح | 2026-05-12 |
| BUG-10 | 2026-05-12 | عدم تطابق بصمة الجلسة بعد تغيير كلمة المرور: `change_password.php` يحسب `password_hash_sig` من hash جديد (ملح مختلف) بينما قاعدة البيانات تحتوي hash آخر → تسجيل خروج تلقائي | `change_password.php:76` | متوسطة | ✅ تم الإصلاح | 2026-05-12 |
