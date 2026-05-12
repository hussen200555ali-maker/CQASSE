# سجل التغييرات - EQAS

جميع التغييرات الجوهرية في مشروع نظام التقييم الشامل لجودة التعليم المدرسي  ستُوثّق في هذا الملف.

تنسيق التاريخ: ISO 8601 (`YYYY-MM-DD`)
تنسيق الإصدارات: [Semantic Versioning](https://semver.org/lang/ar/) (`Major.Minor.Patch`)

---

## [2.0.3] - 2026-05-11

### إصلاحات أمنية وبنيوية — فحص شامل

#### FIX-008: صيغة severity_4 في JavaScript (2 ملفات متبقية)
- **الوصف**: صيغة حساب severity_4 في behavior/edit.php و behavior/evaluate.php كانت لا تزال تستخدم الصيغة القديمة المعكوسة `((4-value)/3)*100`
- **الملفات المُصلحة**: pages/behavior/edit.php, pages/behavior/evaluate.php
- **التغيير**: `((4-value)/3)*100` → `((value-1)/3)*100`
- **التأثير**: معاينة الدرجات السلوكية في الوقت الفعلي كانت تظهر نتائج معكوسة

#### FIX-009: حذف ملفات .tmp المؤقتة (81 ملف)
- **الوصف**: ملفات .tmp تحتوي على كود ما قبل الإصلاح (ثغرات أمنية قديمة)
- **الإجراء**: حذف جميع 81 ملف .tmp + إضافة `.tmp` لقائمة الرفض في .htaccess
- **التأثير**: منع كشف كود ضعيف عبر الخادم

#### FIX-010: جداول الترحيل المفقودة (6 جداول)
- **الوصف**: migrate_v2.0.3.sql يعتمد على RENAME فقط، فإذا لم تكن الجداول القديمة موجودة لا تُنشأ الجديدة
- **الإجراء**: إضافة CREATE TABLE IF NOT EXISTS لـ: behavior_items, climate_items, facility_items, dashboard_widgets, attendance_summaries, export_logs + نسخ البيانات من الجداول القديمة
- **التأثير**: ضمان وجود الجداول بأسمائها الجديدة بغض النظر عن طريقة التثبيت

#### FIX-011: manage_forms.php يستخدم form_type القديم
- **الملف**: pages/assessment/manage_forms.php
- **التغيير**: `$_GET['form_type']` → `$_GET['form_key']` و `name="form_type"` → `name="form_key"`
- **التأثير**: تصفية نماذج التقييم تعمل بشكل صحيح

#### FIX-012: حذف Database.php المكرر
- **الإجراء**: نقل includes/Database.php إلى backups (تعارض أسماء مع database.php)
- **التأثير**: منع خطأ فادح "Cannot declare class Database"

#### FIX-013: فحص صلاحيات صفحات الملاحظات
- **الملفات**: pages/notes/add.php (بدون أي مصادقة!)، pages/notes/index.php
- **الإجراء**: إضافة hasPermission('school_admin') بعد require_once header.php
- **التأثير**: منع وصول غير المصرح لهم لبيانات الطلاب والمعلمين

#### FIX-014: MD5 → SHA256 في بصمة الجلسة
- **الملف**: includes/auth.php
- **التغيير**: 4 استدعاءات `md5()` → `hash('sha256', ...)`
- **الملح**: `'eqas_salt_2024'` → `'EQAS_SecureSalt_2026_v203'`
- **التأثير**: تقوية أمان بصمة الجلسة وتوقيع كلمة المرور (سيؤدي لإنهاء جميع الجلسات الحالية)

#### FIX-015: تحديث رأس schema.sql
- **التغيير**: "73 جدول" → "71 جدول + 1 عرض (v_student_current)" (تصحيح العدد الفعلي)

#### SEC-002: ملح بصمة الجلسة الآمن
- **الملف**: config.php — إضافة `SESSION_FINGERPRINT_SALT` بملح عشوائي 64 حرفاً
- **الملف**: config.production.php — استخدام متغيرات البيئة مع فحص تلقائي للقيم الافتراضية
- **التأثير**: بصمة الجلسة لم تعد تعتمد على ملح معروف مضمّن في الكود المصدري

#### SEC-003: حماية بيانات الاعتماد في بيئة الإنتاج
- **الملف**: config.production.php
- **التغيير**: استخدام `getenv()` لقراءة بيانات DB من متغيرات البيئة
- **الإضافة**: فحص تلقائي يُصدر `E_USER_ERROR` إذا اكتُشفت بيانات اعتماد افتراضية (root/كلمة مرور فارغة)
- **التأثير**: يمنع نشر النظام ببيانات اعتماد غير آمنة

#### SEC-004: تحسين CSP مع nonce
- **الملف**: includes/security_headers.php
- **التغيير**: إضافة `nonce` عشوائي لكل طلب في رأس CSP (`script-src 'nonce-xxx'`, `style-src 'nonce-xxx'`)
- **الإضافة**: `base-uri 'self'` و `form-action 'self'` لمنع اختطاف النماذج
- **الملف**: includes/header.php — إضافة nonce لسكربت Chart.js و meta tag للصفحات
- **التأثير**: المتصفحات الحديثة ستتجاهل `unsafe-inline` عند وجود nonce، مما يعزز حماية XSS

#### SEC-005: Middleware مركزي للصلاحيات
- **ملف جديد**: includes/permission_middleware.php
- **المحتوى**: دوال `checkPageAccess()`, `requireGroupAdmin()`, `requireSchoolAdminOrAbove()`, `checkSchoolOwnership()`
- **الإضافة**: خريطة صلاحيات `$PAGE_PERMISSIONS` تحدد الدور الأدنى لكل مسار صفحة
- **الإضافة**: دالة `applyAutoPermissions()` للتطبيق التلقائي من header.php

#### FIX-018: تضارب بصمة الجلسة بين auth.php و session.php
- **الوصف**: `auth.php` يستخدم `hash('sha256')` مع ملح `'EQAS_SecureSalt_2026_v203'`، لكن `session.php` يستخدم `md5()` مع ملح `'eqas_salt_2024'`. البصمة لن تتطابق أبداً!
- **الملف**: includes/session.php
- **التغيير**: `md5()` → `hash('sha256')` + توحيد الملح مع auth.php
- **التأثير**: حرج — كان يمنع تسجيل الدخول تماماً (بصمة لا تتطابق = تدمير الجلسة فوراً)

#### FIX-019: إعادة كتابة setup.php بالكامل
- **الوصف**: setup.php كان ينشئ 9 جداول مبسطة غير متوافقة مع schema.sql (71 جدول). أسماء أعمدة مختلفة، أنواع بيانات مختلفة، جداول مفقودة
- **الملف**: setup.php
- **التغييرات**:
  - استيراد schema.sql الكامل بدلاً من جداول مبسطة
  - إدراج بيانات أساسية (محافظات، أدوار، مراحل دراسية)
  - أسماء الأعمدة تتطابق مع schema.sql (`password_hash`, `role_id`, `full_name_ar`, إلخ)
  - إنشاء config.php متكامل مع جميع الثوابت الأمنية
  - ملح جلسة عشوائي فريد لكل تثبيت
  - تحويل أنواع المدارس (primary→basic, mixed→combined)
  - إدخال فصل دراسي أول وبيانات أساسية
- **التأثير**: التثبيت عبر setup.php يُنتج الآن قاعدة بيانات متوافقة مع كود النظام

#### FIX-020: إضافة requireLogin() لصفحة تفضيلات الإشعارات
- **الملف**: pages/notifications/preferences.php
- **التغيير**: إضافة `requireLogin()` مع تعليق يوضح أن الصفحة شخصية
- **التأثير**: توحيد نمط التحقق عبر جميع الصفحات

#### FIX-021: إزالة مرجع بيانات الاعتماد من README
- **الملف**: README.md
- **التغيير**: استبدال مرجع `DEFAULT_CREDENTIALS.md` بنص يوضح عدم وجود بيانات دخول افتراضية
- **التأثير**: منع كشف أي بيانات اعتماد افتراضية

### إضافات رئيسية — إغلاق فجوات CRUD

#### FEAT-001: وحدة إدارة الطلاب الكاملة (students/)
- **ملفات جديدة** (6 ملفات، ~1,667 سطر):
  - `pages/students/index.php` — قائمة الطلاب مع بحث وفلاتر وترقيم صفحات
  - `pages/students/add.php` — إضافة طالب جديد مع توليد تلقائي لرقم الطالب
  - `pages/students/edit.php` — تعديل بيانات طالب (رقم الطالب للقراءة فقط)
  - `pages/students/view.php` — عرض تفاصيل الطالب وأولياء الأمور والتسجيلات
  - `pages/students/import.php` — استيراد CSV مع معاينة وتحقق صفّي
  - `pages/students/toggle.php` — تفعيل/تعطيل حساب طالب
- **الصلاحيات**: `group_admin` + `school_admin` (محدود بمدرسته)
- **الأمان**: CSRF, XSS, تسجيل نشاط, تحقق من تفرد رقم الطالب

#### FEAT-002: وحدة التسجيلات والشعب (enrollments/)
- **ملفات جديدة** (3 ملفات + 1 API):
  - `pages/enrollments/index.php` — قائمة التسجيلات مع 4 بطاقات إحصائية
  - `pages/enrollments/add.php` — تسجيل فردي/دفعة مع بحث AJAX وبطاقات سعة
  - `pages/enrollments/edit.php` — تعديل حالة التسجيل ونقل بين الشعب
  - `api/search_students.php` — نقطة نهاية AJAX للبحث عن طلاب
- **ميزات**: تسجيل دفعي مع Select All, فحص سعة الفصل, نقل بين الشعب مع سبب

#### FEAT-003: صفحات التعديل المفقودة (6 ملفات)
- `pages/behavior/edit.php` — تعديل التقييم السلوكي (المُقيّم الأصلي أو admin)
- `pages/skills/edit.php` — تعديل تقييم المهارات
- `pages/teaching/self_edit.php` — تعديل التقييم الذاتي (المعلم نفسه فقط)
- `pages/teaching/peer_edit.php` — تعديل تقييم الأقران (المُقيّم الأصلي فقط)
- `pages/notes/edit.php` — تعديل الملاحظات (منشئ الملاحظة أو admin)
- `pages/climate/edit.php` — تعديل استبيان المناخ (group_admin فقط)
- **حماية**: فحص قفل الفصل الدراسي قبل السماح بالتعديل

#### FEAT-004: بوابة ولي الأمر (5 ملفات)
- `pages/parent/grades.php` — درجات الأبناء مع متوسطات المواد
- `pages/parent/behavior.php` — سلوك الأبناء مع اتجاهات ومؤشرات بصرية
- `pages/parent/attendance.php` — حضور الأبناء مع عرض تقويمي وتنبيهات
- `pages/parent/skills.php` — مهارات الأبناء مع مقارنة بمتوسط الفصل
- `pages/parent/promotion.php` — قرارات الترفيع مع خطط التحسين

### تحسينات الصلاحيات

#### PERM-001: مصفوفة صلاحيات مركزية في الشريط الجانبي
- **الملف**: `includes/sidebar.php` — إعادة هيكلة كاملة
- **التغيير**: استبدال شروط if المتناثرة بمصفوفة `$menuPermissions` مركزية
- **الميزة**: إضافة/تعديل صلاحية عنصر قائمة = تعديل سطر واحد في المصفوفة
- **الدالة الجديدة**: `canSeeMenu($menuKey, $userRole, $permissions)`

#### PERM-002: السماح لمدير المدرسة بإدارة السنوات الأكاديمية
- **الملفات**: `pages/academic/add.php`, `pages/academic/edit.php`
- **التغيير**: `hasPermission('group_admin')` → `hasPermission('school_admin')`
- **السبب**: مدراء المدارس يحتاجون لإنشاء وتعديل السنوات الأكاديمية

#### PERM-003: توسيع صلاحيات مدير المدرسة
- إدارة الطلاب والتسجيلات (كانت مفقودة بالكامل)
- رؤية وتعديل التقييمات الإشرافية
- رؤية البيئة المدرسية والمناخ والمرافق
- رؤية المخرجات وكشوف الدرجات والتصدير
- الوصول للإعدادات

### تحديثات التوثيق

#### DOC-005: تحديث رأس schema.sql
- تحديث رقم الإصدار إلى 2.0.3
- إضافة وصف الجداول الجديدة

#### DOC-006: تحديث README.md
- إضافة وحدات الطلاب والتسجيلات وبوابة ولي الأمر
- تحديث هيكل الملفات
- تحديث قسم الأدوار والصلاحيات
- تحديث الإصدار إلى 2.0.3

#### DOC-007: إضافة إصدار v2.0.3 في CHANGELOG.md

---

## [2.0.1-doc] - 2026-05-11

### تحديثات التوثيق

#### DOC-001: تحديث رأس schema.sql
- **الملف**: `database/schema.sql` (الأسطر 1-8)
- **التغيير**: تحديث سطر الإجمالي من "71 جدول" إلى "71 جدول + 1 عرض (v_student_current)" مع إضافة سطر منفصل يوضح الجداول المضافة
- **السبب**: العرض `v_student_current` (السطر 1749) لم يكن مُوثّقاً في رأس الملف

#### DOC-002: إنشاء مخطط ERD جديد
- **الملف الجديد**: `download/eqas_erd_v2.0.1.png` (5793×3877 بكسل)
- **المحتوى**: مخطط شامل لـ 71 جدول + 1 عرض منظّم في 10 وحدات لونية
- **الميزات**:
  - تسميات عربية لأسماء الوحدات + أسماء جداول بالإنجليزية
  - خطوط متصلة للعلاقات داخل الوحدة، ومنحنيات متقطعة للعلاقات بين الوحدات
  - العرض `v_student_current` بإطار متقطع أصفر
  - مفتاح ألوان + صندوق إحصائيات
  - 73 علاقة مفتاح أجنبي مرسومة

#### DOC-003: تحديث README.md — قسم Schema Drift
- **الملف**: `README.md`
- **الإضافات**:
  - قسم "انحراف المخطط (Schema Drift)" يوثّق التباين بين 4 مصادر لتعريف قاعدة البيانات
  - جدول أهداف إعادة التسمية في migration وحالة تنفيذها (8 عمليات: 1 منفذة، 7 غير منفذة)
  - جدول API نقاط النهاية مع الإشارة إلى `docs/api_endpoints_v2.0.1.md`
- **التحديثات**:
  - تحديث وصف `schema.sql` من "71 جدول" إلى "71 جدول + 1 عرض"

#### DOC-004: تحديث توثيق API
- **الملف**: `docs/api_endpoints_v2.0.1.md`
- **الإضافات**:
  - قسم 4: وثائق نقطة نهاية `api/check_username.php` (AJAX endpoint)
  - قسم 5: خريطة الصفحات حسب الوحدة والدور المطلوب (21 وحدة)
  - قسم 6: ملاحظات التوافق والترقية

---

## [2.0.2] - 2026-05-11

### الأمان

#### SEC-001: إضافة رؤوس HTTP الأمنية المركزية
- **ملف جديد**: `includes/security_headers.php` — رؤوس أمان مركزية تُحمَّل تلقائياً مع كل طلب PHP
- **تحديث**: `config.php` — إضافة `require_once` لملف الرؤوس الأمنية في بداية الملف (قبل أي مخرجات)
- **تحديث**: `.htaccess` — تحديث رؤوس الأمان لتشمل جميع الرؤوس المطلوبة

#### الرؤوس المُضافة (8 رؤوس أمنية):

| الرأس | الوظيفة | القيمة |
|-------|---------|--------|
| `X-Frame-Options` | منع Clickjacking | `SAMEORIGIN` |
| `Content-Security-Policy` | منع XSS وحقن الأكواد | سياسة شاملة تشمل CDN موثوق |
| `X-Content-Type-Options` | منع MIME Sniffing | `nosniff` |
| `X-XSS-Protection` | حماية XSS (متصفحات قديمة) | `1; mode=block` |
| `Referrer-Policy` | التحكم في معلومات الإحالة | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | تعطيل صلاحيات غير مستخدمة | `geolocation=(), camera=(), microphone=()` |
| `Strict-Transport-Security` | إجبار HTTPS | `max-age=31536000; includeSubDomains` |
| `Cache-Control` + `Pragma` | منع التخزين المؤقت | `no-store, no-cache, must-revalidate` |

#### ميزات أمان إضافية في security_headers.php:
- فحص `headers_sent()` قبل إرسال الرؤوس لمنع الأخطاء
- تسجيل تحذير في سجل الأخطاء إذا كانت المخرجات بدأت قبل الرؤوس
- منع التنفيذ المباشر للملف عبر فحص `PHP_SELF`
- HSTS يُفعّل فقط في بيئة HTTPS
- CSP تتضمن نطاقات CDN الموثوقة (jsdelivr, cloudflare)

#### تحديثات أخرى:
- تحديث `EQAS_VERSION` من `1.0.0` إلى `2.0.2` في `config.php`
- إضافة تعليق ترتيب التحميل في `config.php`

---

## [2.0.1] - 2026-05-11

### إصلاح أخطاء حرجة (5)

#### الخطأ #1: صيغة severity_4 معكوسة
- **الملف**: `includes/assessment_engine.php` (السطر 462)
- **الوصف**: صيغة التطبيع لمقياس الشدة (severity_4) كانت معكوسة. المقياس عكسي بطبيعته حيث القيمة 4 = لا يوجد مشكلة (أفضل) والقيمة 1 = شديد (أسوأ). الصيغة القديمة كانت تنتج نتائج مقلوبة تماماً.
- **قبل الإصلاح**: `$normalized = ((4 - (float)$value) / 3) * 100;`
  - القيمة 1 (شديد) → 100% ❌ (يجب أن تكون 0%)
  - القيمة 4 (لا يوجد) → 0% ❌ (يجب أن تكون 100%)
- **بعد الإصلاح**: `$normalized = (((float)$value - 1) / 3) * 100;`
  - القيمة 1 (شديد) → 0% ✅
  - القيمة 2 (متوسط) → 33.33% ✅
  - القيمة 3 (خفيف) → 66.67% ✅
  - القيمة 4 (لا يوجد) → 100% ✅
- **التأثير**: مؤشر EQI كان يحسب درجات مقلوبة لأي تقييم يستخدم مقياس الشدة (severity_4)
- **مرجع الكود**: `// [إصلاح 2026-05-11] كانت الصيغة ((4-value)/3)*100 معكوسة - تصحح إلى ((value-1)/3)*100`

#### الخطأ #2: عدم تطابق اسم الجدول assessment_categories
- **الملفات المتأثرة**:
  - `includes/assessment_engine.php` (4 مواقع)
  - `pages/assessment/add_form.php` (2 موقع)
  - `pages/teaching/self_view.php` (1 موقع)
  - `pages/teaching/admin_view.php` (1 موقع)
  - `pages/teaching/peer_view.php` (1 موقع)
  - `pages/facility/view.php` (1 موقع)
  - `pages/skills/view.php` (1 موقع)
  - `pages/climate/view.php` (1 موقع)
  - `pages/behavior/view.php` (1 موقع)
- **الوصف**: الكود كان يشير لجدول `assessment_categories` الذي لا وجود له في مخطط قاعدة البيانات. الجدول الفعلي هو `assessment_criteria`.
- **قبل الإصلاح**: `JOIN assessment_categories ac ON ...`
- **بعد الإصلاح**: `JOIN assessment_criteria ac ON ai.criteria_id = ac.id`
- **التأثير**: خطأ SQL "Table doesn't exist" عند تنفيذ أي استعلام يتضمن JOIN مع جدول المعايير
- **مرجع الكود**: `// [إصلاح 2026-05-11] assessment_categories → assessment_criteria (تطابق schema)`

#### الخطأ #3: عدم تطابق أسماء الأعمدة (category_id / form_type)
- **الملفات المتأثرة**:
  - `includes/assessment_engine.php` (6 مواقع)
  - `pages/assessment/add_form.php` (5 مواقع)
- **الوصف**: الكود كان يستخدم أسماء أعمدة لا تتطابق مع مخطط قاعدة البيانات. الأعمدة الفعلية هي `criteria_id` (وليس `category_id`) و `form_key` (وليس `form_type`). كما أن مفاتيح المصفوفات PHP ونموذج HTML لم تكن متطابقة مع أسماء الأعمدة.
- **التغييرات**:
  | الموقع | القديم | الجديد |
  |--------|--------|--------|
  | engine.php:918 (createItem) | `$data['category_id']` | `$data['criteria_id']` |
  | engine.php:867 (createForm) | `$data['form_type']` | `$data['form_key']` |
  | add_form.php:64 | `'form_type' => $formType` | `'form_key' => $formType` |
  | add_form.php:47 | `$_POST['form_type']` | `$_POST['form_key']` |
  | add_form.php:157 | `name="form_type"` | `name="form_key"` |
  | engine.php SQL | `category_id` | `criteria_id` |
  | engine.php SQL | `form_type` | `form_key` |
- **التأثير**: إدخال قيم NULL في قاعدة البيانات بسبب عدم تطابق مفاتيح المصفوفات بين المُستدعي والدالة
- **مرجع الكود**: `// [إصلاح 2026-05-11] category_id → criteria_id و form_type → form_key (تطابق schema)`

#### الخطأ #4: وظائف القفل تستخدم عمود وقيم خاطئة
- **الملف**: `includes/helpers.php` (الأسطر 849-928)
- **الوصف**: دوال إدارة قفل الفصول الدراسية (`isSemesterLocked()`, `canEditSemester()`, `getSemesterStatusLabel()`) كانت تستخدم عمود `status` بدلاً من `lock_level`، وقيم ENUM خاطئة (`soft_locked`/`hard_locked`/`open` بدلاً من `soft`/`hard`/`none`). مخطط قاعدة البيانات يعرّف العمود كـ `lock_level ENUM('none','soft','hard','admin_handled')`.
- **التغييرات**:
  | الدالة | القديم | الجديد |
  |--------|--------|--------|
  | `currentSemesterId()` | `status = 'open'` | `lock_level = 'none'` |
  | `isSemesterLocked()` | `SELECT status FROM semesters` | `SELECT lock_level FROM semesters` |
  | `isSemesterLocked()` | `in_array($status, ['soft_locked','hard_locked'])` | `in_array($lockLevel, ['soft','hard','admin_handled'])` |
  | `canEditSemester()` | `SELECT status` | `SELECT lock_level` |
  | `canEditSemester()` | `$status === 'open'` | `$lockLevel === 'none'` |
  | `canEditSemester()` | `$status === 'soft_locked'` | `$lockLevel === 'soft'` |
  | `canEditSemester()` | `$status === 'hard_locked'` | `$lockLevel === 'hard'` |
  | `getSemesterStatusLabel()` | `'open' => 'مفتوح'` | `'none' => 'مفتوح'` |
  | `getSemesterStatusLabel()` | `'soft_locked' => '...'` | `'soft' => 'قفل ناعم'` |
  | `getSemesterStatusLabel()` | `'hard_locked' => '...'` | `'hard' => 'قفل صلب'` |
- **التأثير**: جميع فحوصات القفل كانت تفشل بصمت (العمود غير موجود = القيمة فارغة = false)، مما يعني أن النظام لم يكن يتحقق فعلياً من حالة قفل الفصول الدراسية
- **مرجع الكود**: `// [إصلاح 2026-05-11] عمود status → lock_level والقيم soft_locked/hard_locked → soft/hard`

#### الخطأ #5: عبارة UPDATE في lock.php تشير لأعمدة غير موجودة
- **الملف**: `pages/academic/lock.php` (الأسطر 89-134)
- **الوصف**: عبارة UPDATE في صفحة إدارة الأقفال كانت تشير لأعمدة غير موجودة في جدول `semesters`. الجدول يحتوي فقط على الأعمدة `lock_level`, `locked_at`, `locked_by` المتعلقة بالقفل.
- **بعد الإصلاح**:
  ```sql
  UPDATE semesters SET lock_level = ?, locked_at = ?, locked_by = ? WHERE id = ?
  ```
- **حماية إضافية**: تمت إضافة فحص لمنع تخفيض مستوى القفل من `admin_handled` إلى مستوى أدنى
- **التأثير**: صفحة إدارة الأقفال كانت غير فعالة تماماً (خطأ SQL عند محاولة تحديث حالة القفل)
- **مرجع الكود**: تعليقات الإصلاح في الأسطر 89, 114, 127, 134

### إصلاحات إضافية مكتشفة أثناء الاختبار

#### إصلاح #6: مفاتيح مصفوفات PHP غير متطابقة في createItem/createForm
- **الملفات**: `includes/assessment_engine.php`, `pages/assessment/add_form.php`
- **الوصف**: أثناء الاختبار المنطقي تبين أن `$data['category_id']` و `$data['form_type']` لم تكن متطابقة مع ما يرسله المُستدعي (`criteria_id` و `form_key`). هذا يعني أن الدوال كانت تقرأ قيم NULL من المصفوفة.
- **الحالة**: تم الإصلاح في نفس الإصدار

#### إصلاح #7: حقل نموذج HTML و POST غير متطابقين
- **الملف**: `pages/assessment/add_form.php`
- **الوصف**: حقل HTML `name="form_type"` ومتغير POST `$_POST['form_type']` لم يكونا متطابقين مع اسم العمود `form_key` في قاعدة البيانات.
- **الحالة**: تم الإصلاح في نفس الإصدار

### تحديثات التوثيق
- تحديث رأس `database/schema.sql`: 68 جدول → 71 جدول، الإصدار 2.0.0 → 2.0.1
- تحديث `README.md`: إضافة قسم المشاكل المعروفة، تحديث نسبة المطابقة إلى 95%
- إنشاء `CHANGELOG.md`: توثيق جميع التغييرات

### اختبارات
- اختبار منطقي شامل: 36/36 اختبار ناجح (100%)
- اختبار رياضي لصيغة severity_4: 4/4 قيم صحيحة
- اختبار منطقي لوظائف القفل: 13/13 سيناريو صحيح
- فحص توافق المُستدعي والمُستدعى: 2/2 متطابق

---

## [2.0.0] - 2025

### إضافات
- بناء النظام الكامل (128 ملف، ~63,747 سطر)
- 71 جدول في قاعدة البيانات
- 4 أدوار: مدير النظام، مدير المدرسة، المعلم، ولي الأمر
- محرك تقييم موحد (4 جداول + 10 أدوات)
- مؤشر جودة التعليم (EQI) بخوارزمية هجينة
- 3 مستويات نسخ احتياطي
- 96 إعداد نظام في 20 فئة
- حماية CSRF, bcrypt, حماية القوة الغاشمة
