# تحصين أمني P0 — سجل التغييرات والتحقق

**التاريخ:** 2026-10-20
**الفرع:** `main`
**نقطة الرجوع:** وسم Git `pre-p0-hardening-2026-10-20` (عند `9b7835b`) + نسخة DB `backups/pre_p0_2026-10-20.sql`
**خطة الاستعادة:** `docs/rollback-plan.md`

هذه الدفعة تعالج ثغرات عالية الخطورة مكتشفة في الفحص الشامل، دون أي تغيير في مخطط
قاعدة البيانات. كل بند موثّق بالملف/الأسطر وبنوع التحقق.

---

## 1. IDOR — قراءة إقرارات أولياء أمور مدارس أخرى

**الملف:** `pages/parent_ack/acknowledge.php`

**المشكلة:** فرع الإدارة (غير ولي الأمر) كان يجلب أي طالب/إقرار دون فلترة `school_id`،
ما يسمح لـ `school_admin`/`teacher` في مدرسة (أ) بقراءة إقرارات طلاب مدرسة (ب) عبر
تمرير `student_id` مباشرة في الرابط.

**الإصلاح:**
- إضافة فلتر `AND s.school_id = ?` (متغير `$schoolScope`) لفرعي `targetParentId`
  والفرع الافتراضي، مُطبَّق لغير `group_admin` مع رفض الطلب إن كان `school_id` الجلسة = 0.
- نقل تضمين `includes/header.php` إلى ما بعد كل قرارات الصلاحية وإعادة التوجيه، لتضمن
  إعادة التوجيه ترويسة HTTP 302 نظيفة بدل إخراج القالب قبلها (كان القالب يُخرَج ثم
  يُنفَّذ `redirect()` بـ JS بسبب `headers_sent`).

**التحقق (حي):**
- `ALI` (school_admin للمدرسة 1) → `acknowledge.php?student_id=13` (طالب مدرسة 2):
  **302 → index.php** مع رسالة رفض.
- `admin` (group_admin) → نفس الطلب: **200** ويعرض النموذج.
- `ALI` → طالب مدرسته (student 1): **200** ويعرض النموذج.

---

## 2. تسجيل أولياء الأمور المفتوح + الربط التلقائي غير الموثّق

**الملف:** `pages/parents/register.php`

**المشكلات:**
1. الربط التلقائي لطلاب بحساب ولي أمر **قائم** بمجرد إدخال رقم جوال يطابق
   `students.guardian_phone` — أي انتحال حساب ولي أمر بمعرفة رقم جواله.
2. الحسابات الجديدة تُنشأ **مفعّلة** (`is_active = 1`) وتُعرض كلمة المرور على الشاشة.
3. لا يوجد حدّ لمحاولات التسجيل.

**الإصلاح:**
- **الحساب الجديد:** يُنشأ بـ `is_active = 0` (بانتظار اعتماد مدير المدرسة). لا يُعرض
  كلمة المرور؛ تُستخدم التي أدخلها المستخدم. رابط `parent_students` يبقى `is_active = 1`
  لكن لا وصول قبل تفعيل الحساب.
- **الحساب القائم:** لا ربط تلقائي. رسالة توجيهية، ويُسجَّل إشعار تدقيقي لمديري المدرسة
  (بدون ربط أي طالب).
- **Throttle:** 5 محاولات كحد أقصى كل 15 دقيقة لكل جلسة (`$_SESSION['parent_reg_attempts']`).
- **تحقق صيغة الجوال:** `preg_match('/^\+?[0-9\s\-]{7,20}$/')`.

**التحقق (حي):**
- تسجيل بالجوال `775515155` (مرتبط بحساب قائم في DB): **لم** يُربط أي طالب، ظهرت رسالة
  التوجيه، وأُنشئ إشعار تدقيقي.
- التسجيل بحساب جديد: `users.is_active = 0`، رابط الطالب `is_active = 1`، إشعار
  "ولي أمر بانتظار الاعتماد" لمدير المدرسة، ولا تسريب لكلمة المرور في الصفحة.
- محاولة دخول بحساب غير مفعّل: رُفضت (auth يشترط `is_active = 1`).
- تم تنظيف كل بيانات الاختبار من DB بعد التحقق.

---

## 3. فرض CSRF على واجهات API معدِّلة

**الملفات:** `api/link_parent.php`, `api/unlink_parent.php`, `api/notifications/mark_read.php`

**المشكلة:** واجهات POST تُغيّر بيانات (ربط/فصل ولي أمر، تعليم إشعار كمقروء) دون أي
تحقق CSRF — قابلة للاستغلال عبر CSRF.

**الإصلاح:** إضافة `if (!verifyCsrf()) { 403 }` بعد فحص الجلسة والصلاحية.
`verifyCsrf()` يقبل `csrf_token` من `$_POST` أو ترويسة `X-CSRF-Token`، و
`assets/js/main.js:setupAjaxDefaults()` يضيف الترويسة تلقائياً لكل طلب `fetch` غير GET،
لذا تعمل الواجهة دون تعديل المُنادين (`pages/parents/link.php`, `pages/students/view.php`,
`pages/users/add.php`, `assets/js/main.js`).

**التحقق (حي، جلسة group_admin):**
- `link_parent.php` بلا توكن → `403 {"message":"رمز الأمان غير صالح"}`.
- `link_parent.php` مع `X-CSRF-Token` صحيح → تجاوز فحص CSRF (وصل لتحقق الحقول).
- `mark_read.php` بلا توكن → `403`؛ مع توكن صحيح → يعمل منطقياً.

---

## 4. حماية توليد التقارير (vendor + CSRF)

**الملفات:** `includes/report_template.php`, `pages/reports/annual_report.php`,
`pages/reports/school_report.php`

**المشكلات:**
1. `report_template.php:13` كان يستدعي `vendor/autoload.php` مباشرة → Fatal Error مكشوف
   عند غياب الاعتماديات (لا يوجد `vendor/` في النسخة الحالية).
2. نموذجا التقارير (POST) بلا CSRF.

**الإصلاح:**
- استبدال الاستدعاء المباشر بفحص `is_file()` + `class_exists('TCPDF')` ورمي
  `RuntimeException` برسالة ودّية، مع تحميل `error_handler.php`.
- إضافة `csrfField()` في النموذجين وفحص `verifyCsrf()` في معالج POST، مع
  `error_handler.php` في أعلى الصفحتين.

**التحقق (حي):**
- POST `annual_report.php` بلا توكن → **302** (رفض).
- POST مع توكن صحيح → **500** صفحة عربية ودّية + رقم مرجعي `EQAS-XXXXXXXX`، بلا تسريب
  مسارات `vendor/` أو `TCPDF`. الخطأ مُسجَّل في `logs/app.log`.
- النموذج يعرض حقل `csrf_token` بنجاح.

---

## 5. معالج الأخطاء المركزي والسجلات

**الملف الجديد:** `includes/error_handler.php`

**القدرات:**
- تسجيل كل الأخطاء في `logs/app.log` (مع `error_log` مخصّص).
- `set_error_handler`: يسجّل الأخطاء؛ يحوّل `E_USER_ERROR`/`E_RECOVERABLE_ERROR` إلى
  استثناء (يمنع استمرار التنفيذ بعد `trigger_error` في `config.production.php`).
- `set_exception_handler`: صفحة خطأ عربية موحّدة + رقم مرجعي؛ التفاصيل تُعرض في
  `development` فقط.
- `register_shutdown_function`: يلتقط الأخطاء القاتلة (Fatal/Parse) ويعرض نفس الصفحة.

**التحميل:** أُضيف في نقاط الدخول: `config.example.php`, `config.production.php`,
`setup.php`, `login.php`, `index.php`, `change_password.php`, `logout.php`,
`includes/header.php`, `pages/parents/register.php`, `pages/reports/*` (لأنها لا تستخدم
`header.php` قبل معالج PDF).

**حماية السجل:** `logs/.htaccess` (deny all) + قاعدة `RewriteRule ^logs/ - [F,L]` في
`.htaccess` الجذر + `logs/*.log` في `.gitignore` مع إبقاء `logs/.htaccess` متتبَّعاً.

**التحقق (حي):**
- `E_USER_ERROR` في وضع production → صفحة ودّية + رقم مرجعي، بلا تسريب النص السري،
  والتسجيل في `logs/app.log` نجح.
- استثناء في وضع development → تُعرض التفاصيل.

---

## 6. إزالة كود تصحيح مكشوف

**الملف:** `pages/academic_assessment/grade_entry.php`

**المشكلة:** كتلة `?debug=1` كانت تطبع محتوى `$_SESSION` كاملاً وبيانات المستخدم/الدور/
المدرسة — تسريب معلومات (Information Disclosure).

**الإصلاح:** حذف الكتلة بالكامل (الأسطر 34-57 سابقاً).

---

## 7. إزالة كود ميت مضلِّل

**الملف:** `includes/permission_middleware.php`

**المشكلة:** `$PAGE_PERMISSIONS` + `applyAutoPermissions()` لم تكونا مستخدمتين (0 مراجع)،
وخريطتهما تخالف الصلاحيات الفعلية (مثل حصر `pages/users/` على `group_admin` بينما
`school_admin` يدير معلمي مدرسته).

**الإصلاح:** حذف الخريطة والدالة، مع تعليق معماري يوضّح أن الصلاحيات تُفرض داخل كل صفحة
عبر `hasPermission()`/`requireRole()`.

---

## التحقق الإجمالي

| الفحص | النتيجة |
|-------|---------|
| PHP lint (19 ملف متغيّر/جديد) | ✅ لا أخطاء |
| PHPUnit (`tests/phpunit.xml`) | ✅ 98 اختبار / 254 تأكيد — 0 فشل |
| فحوص حية (curl) | ✅ كما هو موثّق في كل بند |
| تغييرات مخطط DB | ❌ لا يوجد |

## ملاحظات متابعة (P1)

- ثغرات `parent_ack` المتعلقة بالمعلمين (يمكنهم عرض درجات الطلاب على مستوى المدرسة) —
  سياسة مقصودة حالياً؛ تُراجع في P1.
- دعم API بمصادقة بلا CSRF للواجهات للقراءة فقط (`check.php`, `count.php`, `preview.php`,
  `search_*.php`) — مقبول لأنها GET للقراءة.
- إعادة تفعيل حسابات أولياء الأمور: واجهة إدارية لاعتماد الحسابات المعلّقة في P1.
