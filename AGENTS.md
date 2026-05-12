# EQAS - التوثيق المعماري

## قرارات معمارية

### 1. النظام الموحّد للتقويم الأكاديمي (2026-05-11)

**القرار:** `semesters` و `academic_years` هما جدولان عامان لجميع المدارس (لا يحتويان على `school_id`).

**السبب:** النظام موحّد — جميع المدارس تشترك في نفس التقويم الأكاديمي. الفصل الدراسي "الحالي" (`is_current`) هو إعداد عام على مستوى النظام.

**نطاق الصلاحيات:**
- `group_admin`: يدير بنية التقويم بالكامل (إضافة/تعديل/حذف الفصول، تعيين `is_current`، تغيير `lock_level`).
- `school_admin`: يرى قائمة الفصول فقط. لا يمكنه إضافة/تعديل/حذف الفصول ولا تغيير `is_current` أو `lock_level`.

**فلترة الإحصائيات:** يتم فلترة إحصائيات الفصول (عدد الشعب، التسجيلات، التقييمات) حسب `school_id` في طبقة العرض (`semesters.php`) باستخدام `currentSchoolId()`. لا يتم إضافة `school_id` إلى جداول التقويم نفسها.

**الملفات المتأثرة:**
- `pages/academic/semesters.php` — فلترة إحصائات الشعب والتسجيلات والتقييمات
- `pages/setup/semester_setup.php` — مقيدة بـ `group_admin` فقط
- `includes/helpers.php` — `currentSemesterId()` تستخدم `is_current = 1`

### 2. دوال السياق (2026-05-11)

**currentSchoolId():** `includes/session.php:183` — تعيد `$_SESSION['user']['school_id']`.
**currentAcademicYearId():** `includes/helpers.php:816` — تقرأ من `$_SESSION['academic_year_id']` (المفتاح موحّد مع `auth.php:194`).
**currentSemesterId():** `includes/helpers.php:839` — تبحث عن `is_current = 1` في جدول `semesters`.

### 3. T-02: إدارة المعلمين لـ school_admin (منفّذ — 2026-05-12)

**القرار:** تعديل `pages/users/index.php` ليدعم `school_admin` في إدارة معلمي مدرسته.

**التصميم:**
- `school_admin`: يرى فقط معلمي مدرسته (فلتر ثابت بـ `school_id = ?`)، يضيف معلمين جدد بدور `teacher` فقط
- `group_admin`: يرى جميع المستخدمين مع قائمة منسدلة لاختيار المدرسة
- كلمة المرور: تُولَّد عشوائياً (`generatePassword()`) مع إجبار التغيير عند أول تسجيل دخول
- إشعار بيانات الدخول: عرض على الشاشة مع تحذير (تم تنفيذ العرض، البريد مستقبلي)
- `school_admin` **لا يمكنه** حذف المستخدمين

**الملفات المعدلة:**
- `pages/users/index.php` — فتح الصلاحية لـ `school_admin`، فلتر `school_id` تلقائي
- `pages/users/add.php` — نموذج مبسط لـ `school_admin` مع توليد كلمة مرور وعرض بيانات الدخول
- `login.php` — إضافة فحص `must_change_password` بعد تسجيل الدخول
- `change_password.php` — صفحة جديدة لتغيير كلمة المرور الإجباري
- `pages/users/profile.php` — تحديث `must_change_password = 0` بعد تغيير كلمة المرور
- `database/schema.sql` — إضافة عمود `must_change_password`
- `docs/testing/test-plan.md` — إضافة سيناريوهات TS-07 إلى TS-09

### 4. الأخطاء المكتشفة أثناء اختبارات T-02 (2026-05-12)

**BUG-08:** `add.php:16` — `hasPermission()` تُستدعى قبل تضمين `header.php`. الإصلاح: نقل السطر بعد `require_once header.php`.

**BUG-09:** 4 ملفات تستخدم `name_ar` في استعلام `roles` ولكن العمود اسمه `display_name_ar`. الإصلاح: استخدام `display_name_ar AS name_ar`.
- `pages/users/add.php:27`
- `pages/users/index.php:92`
- `pages/users/edit.php:42`
- `pages/users/import.php:25`

**BUG-10:** `change_password.php:76` — `password_hash_sig` يُحسب من `password_hash()` جديد (ملح bcrypt مختلف) مما يسبب عدم تطابق مع القيمة المخزنة في قاعدة البيانات → تسجيل خروج تلقائي. الإصلاح: تخزين hash المتغير في متغير ثم استخدامه لكل من DB والجلسة.

### 5. الاختبارات الشاملة (2026-05-12)

**تم إنجاز 9 مراحل اختبار:**

| المرحلة | النوع | العدد | الحالة |
|---------|-------|-------|--------|
| P1 | Static Analysis | 165 ملف | ✅ |
| P2 | Unit Tests (قائمة) | 109 | ✅ |
| P3 | Bug Fixes | 13/15 | ✅ |
| P4 | Unit Tests (جديدة) | 3 ملفات | ✅ |
| P5 | Integration Tests | 26 | ✅ |
| P6 | Security Tests | 33 | ✅ |
| P7 | E2E Playwright | 13 | ✅ |
| P8 | Regression | الكل | ✅ |
| P9 | Documentation | test-results.md | ✅ |

**نتائج الاختبارات الآلية:**
- PHPUnit: 0 فشل (وحدة + تكامل + أمان)
- Playwright: 13/13 ✅
- لم يتم اكتشاف ثغرات XSS أو SQLi أو CSRF قابلة للاستغلال

**ملفات الاختبارات الجديدة:**
- `tests/unit/SecurityTest.php` — 33 اختبار أمان
- `tests/unit/BehaviorCalculatorTest.php` — 4 اختبارات
- `tests/unit/PromotionEngineTest.php` — 3 اختبارات
- `tests/unit/PermissionMiddlewareTest.php` — 5 اختبارات
- `tests/integration/AcademicYearIntegrationTest.php` — 2 اختبار
- `tests/integration/UserLifecycleIntegrationTest.php` — 4 اختبارات
- `tests/integration/AssessmentFlowIntegrationTest.php` — 4 اختبارات
- `tests/integration/EQIIntegrationTest.php` — 3 اختبارات
- `tests/integration/BehaviorIntegrationTest.php` — 4 اختبارات
- `tests/integration/PromotionIntegrationTest.php` — 3 اختبارات
- `tests/integration/SchoolIsolationIntegrationTest.php` — 7 اختبارات
- `tests/e2e/eqas-e2e-tests.js` — 13 سيناريو E2E (ملف تشغيل في `/tmp`)

**الإصلاحات الرئيسية:**
- `includes/eqi.php`: استبدال `ON DUPLICATE KEY UPDATE` بنمط SELECT-then-UPDATE/INSERT
- `pages/behavior/override.php`: إصلاح XSS في onclick + إزالة display:none مكرر
- `pages/enrollments/add.php`, `edit.php`: إضافة التحقق من حالة العام الدراسي
- `pages/academic/add.php`, `edit.php`: تغيير الصلاحية إلى `group_admin`
- `pages/eqi/report.php`: تغيير `<input>` إلى `<select>` للفصل الدراسي
- `pages/eqi/compare.php`: فلتر المدرسة ديناميكي + أبعاد ديناميكية
- `pages/behavior/incident.php`: تصفية بالعام والفصل الدراسي
- `pages/students/import.php`: حد حجم الملف (5 ميجابايت)
- `pages/settings/index.php`: إصلاح Parse error (قوس زائد)
- `tests/bootstrap.php`: إضافة 43 جدول SQLite + ثوابت + شيم دوال MySQL
