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

### 6. معامل التعديل السياقي والاتجاه السنوي (2026-05-13)

**القرار:** إنشاء `school_context` لتخزين العوامل السياقية لكل مدرسة، وخدمة `ContextualAdjustmentService` لحساب `adjusted_sqi`.

**التصميم:**
- `school_context`: جدول مستقل (لا `school_id` واحد لكل مدرسة/عام)، يخزن 7 عوامل سياقية مع `challenge_index` محسوب
- `ContextualAdjustmentService`: يحسب `challenge_index` (0-100) من 7 عوامل مرجّحة، يطبق التعديل: `adjusted_sqi = raw_sqi + (challenge_index/100) × max_adjustment`
- التعديل محصور بين 0-100، و `max_adjustment` افتراضي 10 نقاط
- المدارس بدون سياق مسجل: `adjusted_sqi = raw_sqi` (بدون تعديل)

**الاتجاه السنوي:**
- مقارنة الفصل الحالي بنفس الفصل من العام السابق (باستخدام `name_ar` للمطابقة)
- يُعرض في dashboard كـ ▲X% / ▼Y% / → مستقر
- تغذية راجعة فقط عند وجود بيانات للعام السابق

**الملفات الجديدة:**
- `includes/contextual_adjustment.php` — خدمة التعديل السياقي
- `database/schema.sql` — إضافة جدول `school_context`
- `scripts/migrate_v2.0.3.sql` — إضافة أمر CREATE TABLE للترحيل

**الملفات المعدلة:**
- `pages/eqi/compare.php` — إضافة صف "المعدّل سياقياً" مع درجة التحدي
- `pages/dashboard/admin.php` — إصلاح استعلام EQI (اسم جدول/عمود خاطئ) + إضافة عمودي الاتجاه السنوي والمعدّل سياقياً

**bugs المصححة:**
- `pages/dashboard/admin.php` — استعلام EQI يستخدم `eqi_calculations` (خطأ) بدلاً من `equi_results`، و`eq.score` بدلاً من `overall_eqi`، وبدون فلترة فصل/عام

### 7. إعادة بناء لوحة القيادة الاستراتيجية (2026-05-15)

**القرار:** إعادة بناء `pages/group_admin/index.php` بالكامل لتصبح لوحة قيادة استراتيجية متكاملة لمدير المجموعة.

**التغييرات:**
- تحويل الصفحة لاستخدام القالب الموحد (`header.php` + `footer.php`)
- إضافة 3 فلاتر (عام دراسي ← فصل دراسي ← مدرسة) مع Reload كامل
- إضافة خلاصة تنفيذية تلقائية
- إضافة أسهم اتجاه EQI في بطاقات KPI
- رسم بياني خطي لاتجاه EQI عبر آخر 6 فصول
- رسم دائري لتوزيع المدارس حسب الأداء
- جدول أداء مدارس مع ألوان صفوف وروابط لـ `analytics/school.php`
- إضافة عمود "المعدّل سياقياً" عبر `ContextualAdjustmentService`
- إضافة اتجاه سنوي (▲/▼/→ مع نسبة مئوية)
- إضافة قسم "المدارس في خطر"
- إضافة آخر التنبيهات غير المقروءة
- إضافة خيار "جميع الفصول" مع متوسط سنوي
- استبعاد سنوات "تخطيط" من فلتر العام الدراسي

**الملفات المعدلة:**
- `pages/group_admin/index.php` — إعادة بناء كاملة (713 سطر)

**الإصلاحات الرئيسية:**
- `pages/group_admin/index.php`: COALESCE bug — تمرير `raw_eqi` (null) بدلاً من 0 لخدمة التعديل السياقي

### 8. وحدة المناخ المدرسي — لوحة القيادة والتحليل والتنبيهات (2026-05-15)

**القرار:** بناء مجموعة متكاملة لمدير المجموعة تشمل لوحة معلومات (`dashboard.php`)، تحليل مدرسة (`school.php`)، وتنبيهات مناخية في `AlertEngine`.

**الملفات الجديدة:**
- `pages/climate/dashboard.php` — لوحة معلومات للمجموعة: فلاتر (عام+فصل)، 4 KPI، Bar/Line charts، جدول المدارس مع الاتجاه السنوي، قسم "مدارس تستوجب التدخل"
- `pages/climate/school.php` — تحليل مدرسة واحدة: Radar Chart (3 أبعاد)، جدول الأبعاد، توصيات تلقائية، اتجاه زمني
- `scripts/migrate_v2.1.0.sql` — ALTER TABLE `climate_surveys` ADD `updated_at` + CREATE TABLE `climate_items`

**الملفات المعدلة:**
- `pages/climate/index.php` — `$expectedRespondents` أصبح ديناميكياً (يحسب من عدد المعلمين+الطلاب)
- `includes/alerts.php` — إضافة `checkClimateThresholds()` لقاعدتين: مناخ < 50% ومشاركة < 60%
- `includes/sidebar.php` — إضافة روابط dashboard.php و school.php تحت "الجودة والإشراف"
- `database/schema.sql` — إضافة `updated_at` إلى `climate_surveys`، تغيير اسم الجدول إلى `climate_items`
- `database/demo_seed.sql` — إضافة بيانات بذرية لـ `climate_items` و `respondent_type` للاستبيانات

**التصميم:**
- `dashboard.php`: صلاحية `group_admin` فقط؛ 4 بطاقات KPI ملونة حسب الأداء؛ Bar Chart مقارنة؛ Line Chart اتجاه عبر 6 فصول؛ جدول مع ▲/▼/→ سنوي؛ توصيات تلقائية حسب التباين والأداء
- `school.php`: صلاحية `group_admin` و `school_admin` (الأخير مقيد بمدرسته)؛ Radar Chart للأبعاد الثلاثة (البيئة المدرسية، العلاقات الاجتماعية، الأمن والسلامة)
- التنبيهات: نوع `climate` مضاف إلى `alertTypeLabels`، `typeMap`، و `oldToNew` في `AlertEngine`

**السيناريوهات المعالجة:**
- DS-01 إلى DS-05: جميع حالات البيانات (فارغ، جزئي، كامل، تاريخي)
- PL-01 إلى PL-04: مستويات الأداء مع ألوان وتوصيات
- EN-01 إلى EN-04: نسب المشاركة مع وسائل تحذيرية
- TM-01 إلى TM-04: حالات الاتجاه (نقطة واحدة، صاعد، هابط، مستقر)
- VR-01/VR-02: التباين بين المدارس
- Y-01/Y-02/S-01/S-06/ED-02: فلاتر القوائم المنسدلة
- `includes/helpers.php:getEqiRating()`: ربط بسياسات المجموعة — قراءة `policy_color_*` من `system_settings`
- `includes/helpers.php:getAvailableSemesters()`: إصلاح عمود `status` → `lock_level`
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

### 9. تطوير وحدة مؤشر جودة التعليم (EQI) — 6 مراحل (2026-05-16)

**القرار:** تطوير وحدة EQI كاملة بـ 6 مراحل: سد الفجوة التقنية، مركز القيادة، التعديل السياقي، التنبيهات، التدقيق، التوثيق.

**المرحلة 1 — سد الفجوة التقنية:**
- تعديل `calculateTeachingScore()` في `includes/eqi.php:349` لآلية احتياطية تدريجية: المحاولة الأولى من `teaching_evaluation_summary` (تقييمات فعلية)، والاحتياطي من متوسط درجات الطلاب (وكيل) مع تسجيل تحذير في `error_log`.
- تحديث `calculate()` لتعيين `source_type` بشكل ديناميكي (`direct` للتقييمات الفعلية، `proxy` للوكيل).

**المرحلة 2 — مركز قيادة EQI:**
- إنشاء `pages/eqi/dashboard.php` — لوحة قيادة إستراتيجية لـ `group_admin`:
  - فلاتر (عام + فصل)، خلاصة تنفيذية تلقائية
  - 4 بطاقات KPI (متوسط المؤشر، أفضل مدرسة، مدارس في الخطر، عدد النتائج)
  - رسم بياني شريطي (EQI لكل مدرسة) مع زر تبديل الخام/المعدّل سياقياً
  - رسم بياني خطي لاتجاه EQI عبر آخر 6 فصول
  - جدول أداء المدارس مع EQI خام/معدّل + اتجاه سنوي (▲/▼/→) + روابط للتفاصيل
  - قسم المدارس في الخطر (EQI < 50)
  - آخر التنبيهات غير المقروءة
  - زر تصدير CSV
- إضافة رابط "مركز القيادة" في `includes/sidebar.php:257` ضمن قائمة EQI

**المرحلة 3 — التعديل السياقي:**
- `pages/eqi/compare.php` كان يدعم التعديل السياقي مسبقاً (أعمدة "المعدّل سياقياً" و "التحدي")
- `pages/eqi/dashboard.php`: يدمج `ContextualAdjustmentService` مع زر تبديل في الرسم البياني الشريطي

**المرحلة 4 — التنبيهات الذكية:**
- إضافة `checkEQIThresholds()` في `includes/alerts.php:279` لـ `AlertEngine` مع 3 قواعد:
  - **القاعدة 1:** EQI < 50 → تنبيه منخفض (danger للـ 30-50، critical لأقل من 30)
  - **القاعدة 2:** انخفاض > 15% عن نفس الفصل من العام السابق → تنبيه تراجع حاد (danger)
  - **القاعدة 3:** زيادة > 10 نقاط عن أقرب فصل سابق → تنبيه تحسين كبير (info)
- دمج الاستدعاء في `runAllChecks()` و إضافة `eqi_thresholds` للمجموع الكلي

**المرحلة 5 — التدقيق:**
- دمج `pages/eqi/history.php` داخل `pages/eqi/view.php`: إضافة قسم "السجل التاريخي" مع رسم بياني خطي وجدول كامل
- تحويل `history.php` إلى redirect إلى `view.php` للتوافق مع الروابط القديمة
- تدقيق `pages/eqi/settings.php`: التحقق من صحة الأوزان موجود (عميل + خادم)

**المرحلة 6 — التوثيق:**
- إضافة تعليقات توثيق مصادر البيانات في `includes/eqi.php` (calculateTeachingScore, إلخ)
- تحديث `AGENTS.md` بقسم تطوير EQI (هذا القسم)

**الملفات الجديدة:**
- `pages/eqi/dashboard.php` — مركز قيادة EQI

**الملفات المعدلة:**
- `includes/eqi.php` — آلية احتياطية للتدريس + `source_type` ديناميكي
- `includes/alerts.php` — 3 قواعد EQI + `checkEQIThresholds()` + دمج في `runAllChecks()`
- `includes/sidebar.php` — رابط "مركز القيادة"
- `pages/eqi/view.php` — دمج السجل التاريخي مع رسم بياني + جدول
- `pages/eqi/history.php` — تحويل إلى redirect للـ view.php

### 10. نظام الصلاحيات وإدارة السياسات الموحدة (2026-05-16)

**القرار:** إنشاء مصفوفة صلاحيات تفاعلية لـ `group_admin` وصفحة سياسات المجموعة الموحدة.

**pages/permissions/matrix.php — مصفوفة الصلاحيات:**
- صلاحية `group_admin` فقط
- جدول تفاعلي (Checkbox) لربط الأدوار بالصلاحيات
- أزرار "تحديد الكل" / "إلغاء الكل" مع عداد التحديدات
- حفظ ذكي: `UPDATE` للصلاحيات الموجودة، `INSERT` للجديدة
- عرض الصلاحيات مجمعة حسب الفئة (users, schools, academic, eqi, climate, teaching, إلخ)

**pages/settings/group_policy.php — سياسات المجموعة:**
- صلاحية `group_admin` فقط
- ترميز لوني مخصص لـ EQI (أخضر/أصفر/أحمر) — يُقرأ في `getEqiRating()`
- نسب النجاح (أكاديمي/سلوكي/حضور)
- إعدادات الإشعارات العامة (بريد/SMS/تطبيق)

### 11. التحليلات الاستكشافية — وحدة متكاملة (2026-05-16)

**القرار:** بناء 6 صفحات تحليلات متكاملة تحت `pages/analytics/` مع فلاتر موحدة ورسوم بيانية.

**الملفات الجديدة:**
- `pages/analytics/index.php` — بوابة التحليلات: بطاقات اختيار للأدوات الـ 6
- `pages/analytics/school.php` — تحليل مدرسة: منتقي مدارس بصري، KPI (EQI خام/معدّل/اتجاه/طلاب/معلمين)، رسم EQI خطي، توزيع الطلاب، أداء الشعب والمواد
- `pages/analytics/class.php` — تحليل شعبة: توزيع درجات (دونات)، أداء مواد، اتجاه عبر الفصول، قائمة طلاب مع تلوين
- `pages/analytics/subject.php` — تحليل مادة: مقارنة عبر المدارس (شريطي) وتحليل داخل المدرسة (توزيع، شعب، اتجاه زمني)
- `pages/analytics/compare.php` — مقارنة حتى 6 مدارس: EQI خام/معدّل/تحدي، مواد متعددة، ملخص مجموعة
- `pages/analytics/trends.php` — اتجاهات زمنية: 4 تبويبات (EQI، حضور، نسبة نجاح، Cohort Analysis) مع رسوم وتوصيات ذكية

**التصميم:**
- كل الصفحات تستخدم `filter_helpers.php` مع `processAcademicFilters()` للفلاتر الموحدة
- دعم التعديل السياقي عبر `ContextualAdjustmentService`
- اتجاه سنوي (▲/▼/→) في كل الصفحات
- رسوم Chart.js (خطي/دونات/شريطي/رادار)
- توصيات تلقائية (أكثر من 30 حالة في trends.php)

**الملفات المساعدة الجديدة:**
- `assets/css/analytics.css` — 1349 سطر تنسيق مشترك لصفحات التحليلات (page-hero, filter-card, school-picker, kpi-stats-grid, stat-card, trend-badge, empty-state)
- `assets/css/app.css` — كلاسات مساعدة عامة (72 سطر، Tailwind-like)

### 12. التقارير — التقرير السنوي لمؤشرات الجودة (2026-05-16)

**القرار:** إنشاء محرك قوالب PDF وصفحة تقرير سنوي احترافية.

**الملفات الجديدة:**
- `includes/report_template.php` — محرك قوالب PDF (410 سطر): كلاس `ReportTemplate` يبني PDF عبر TCPDF مع RTL. يدعم: غلاف، جداول، بطاقات KPI، أشرطة تقدم، توصيات، ترقيم صفحات.
- `pages/reports/annual_report.php` — التقرير السنوي: غلاف، ملخص تنفيذي، إحصائيات، اتجاهات EQI، تصنيف المدارس، تعديل سياقي، توصيات. تصدير PDF مباشر.

### 13. الإشراف التربوي — لوحات تقييم المعلمين (2026-05-16)

**القرار:** بناء 4 لوحات متكاملة لتقييم أداء المعلمين بـ 3 أبعاد (ذاتي/أقران/إداري).

**الملفات الجديدة:**
- `pages/supervision/teaching_dashboard.php` — مركز قيادة (670 سطر): KPI (متوسط scores، عدد المعلمين/المدارس)، جدول أداء المدارس، رسوم بيانية، فلترة
- `pages/supervision/teaching_school.php` — تحليل مدرسة (538 سطر): رادار ثلاثي الأبعاد (ذاتي/أقران/إداري)، أداء حسب المادة، توصيات تلقائية
- `pages/supervision/teaching_teacher.php` — تفاصيل معلم (412 سطر): توزيع درجات، اتجاه زمني، خطط التطوير
- `pages/supervision/improvement_dashboard.php` — متابعة خطط التحسين (364 سطر): عرض موحد لـ `school_improvement_plans` مع فلاتر، جدول بالتقدم، رسوم بيانية

**التصميم:**
- `group_admin`: يرى كل المدارس
- `school_admin`: مقيد بمدرسته
- دمج التعديل السياقي
- 3 أنواع تقييم في رادار (Radar Chart)
- توصيات تلقائية عند انخفاض بُعد عن متوسط المجموعة

**الملفات المعدلة:**
- `pages/supervision/development_plan.php` — تحسينات
- `pages/supervision/improvement_plan.php` — تحسينات
- تم حذف `pages/supervision/teaching_eval.php` (مستبدل باللوحات الجديدة)

### 14. أدوات الفلترة الموحدة (2026-05-16)

**القرار:** إنشاء دوال فلترة موحدة لكل صفحات التحليلات والمناخ.

**الملفات الجديدة:**
- `includes/filter_helpers.php` — `processAcademicFilters()`: دوال موحّدة تعيد [AcademicYearId, SemesterId, allAcademicYears, allSemesters, semesterName]. تدعم "جميع السنوات" و "جميع الفصول".
- `includes/filters.php` — دوال منخفضة المستوى: `semesterValueToNameAr()`, `buildSemesterSubquery()`, `buildYearFilter()`, `buildSchoolFilter()` — ترجع شروط SQL مع باراميترات.

### 15. الإدارة الأكاديمية — أرشفة وتعديل الفصول (2026-05-16)

**القرار:** إنشاء أرشفة آمنة للسنوات الأكاديمية وتعديل تواريخ الفصول.

**الملفات الجديدة:**
- `pages/academic/delete.php` — أرشفة/استعادة سنة: يعرض 7 إحصائيات للبيانات المرتبطة، يتحقق من العوائق (سنة حالية، قفل إداري)، يحدّث `is_active`
- `pages/academic/edit_semester.php` — تعديل تواريخ الفصل: تحقق من عدم التداخل، عدم وجود قفل إداري، تسجيل النشاط

### 16. سكريبتات الترحيل — 6 إصدارات (2026-05-16)

**القرار:** سلسلة ترحيل من v2.0.3 إلى v3.1.0 لتوحيد بنية قاعدة البيانات.

**الملفات الجديدة:**
- `scripts/migrate_v2.0.4.sql` — إضافة أعمدة `is_active`, `academic_year_id`, `school_id` إلى `subject_teachers`
- `scripts/migrate_v2.0.5.sql` — ترحيل `alert_type` ENUM (توحيد القيم)، إضافة `notes` إلى `alerts`
- `scripts/migrate_v2.1.0.sql` — إضافة `updated_at` إلى `climate_surveys`، نقل `climate_survey_items` → `climate_items`
- `scripts/migrate_v2.2.0.sql` — إضافة `climate` و `teaching` إلى ENUM التنبيهات
- `scripts/migrate_v3.0.0.sql` — إعادة هيكلة تقييم التدريس: إلغاء 6 جداول قديمة، إنشاء `assessment_forms/criteria/items` موحّدة
- `scripts/migrate_v3.1.0.sql` — إصلاح خطط التحسين: توحيد أسماء الأعمدة، إنشاء `school_improvement_plans` مع FK

### 17. الاختبارات الجديدة (2026-05-16)

**الملفات الجديدة:**
- `tests/unit/ClimateFilterTest.php` — 14 اختباراً: `semesterValueToNameAr`, `buildSemesterSubquery`, `buildYearFilter`, `buildSchoolFilter`
- `tests/unit/TeachingEvaluationTest.php` — 10 اختبارات (TS-01 إلى TS-09): `calculateTeachingScore()`، `checkTeachingThresholds()`، حساب KPI
- `tests/e2e/climate-module.spec.js` — 20 اختبار E2E: صلاحيات المناخ، Dashboard، تحليل المدرسة، فلترة
- `tests/e2e/teaching-evaluation.spec.js` — 12 اختبار E2E: تقييم المعلمين، KPI، تنقل، رادار، صلاحيات

### 18. تحسينات عامة (2026-05-16)

**api/session/ping.php** — واجهة API للحفاظ على الجلسة: GET (ping عادي) و POST (تمديد المهلة)، ترجع `expires_in` بصيغة JSON.

**pages/students/search_ajax.php** — بحث AJAX للطلاب مع فلترة تلقائية حسب `school_id` للمستخدمين من نوع `school_admin`/`teacher`.

**الملفات المعدلة (تحسينات واسعة):**
- `includes/sidebar.php` — 398 سطر (إضافة روابط التحليلات، الإشراف، التقارير، الصلاحيات)
- `includes/alerts.php` — 573 سطر (إضافة قواعد تنبيه Teaching + Climate + EQI)
- `includes/eqi.php` — 246 سطر (تحسين calculateTeachingScore، إعادة هيكلة)
- `pages/eqi/calculate.php` — 424 سطر (إعادة بناء كاملة)
- `pages/eqi/index.php` — 607 سطر (إعادة بناء كاملة)
- `pages/eqi/compare.php` — 283 سطر (إضافة تعديل سياقي، فلاتر ديناميكية)
- `pages/dashboard/admin.php` — 343 سطر (إضافة EQI، التعديل السياقي)
- `pages/notifications/index.php` — 171 سطر (إعادة بناء)
- `pages/supervision/improvement_plan.php` — 106 سطر (تحسينات)
- `database/schema.sql` — 108 سطر (إضافة جداول وأعمدة جديدة)
- `login.php`, `logout.php` — تحسينات الجلسة
- `includes/auth.php`, `session.php` — تحسينات المصادقة
- `includes/header.php` — تحسينات القالب
- `assets/js/main.js` — 69 سطر (إضافة دوال JavaScript جديدة)

### 19. إعادة بناء sidebar بشكل كامل (2026-05-16)

**القرار:** إعادة بناء `includes/sidebar.php` ليدعم جميع الوحدات الجديدة مع نظام نقش متعدد المستويات.

**التصميم:**
- إضافة 6 أقسام جديدة: لوحة القيادة الإستراتيجية، مركز EQI، التحليلات، الإشراف التربوي، النظام والصلاحيات
- أيقونات FontAwesome لكل قسم
- روابط مشروطة حسب الصلاحية (`hasPermission()`)
- تمييز الرابط النشط (`active` class)
- نقش (Nested) للقوائم الفرعية (مثل EQI: إدارة، حساب، مقارنة، مركز قيادة، إلخ)

### 20. المراجعة الشاملة لوحدة EQI — إصلاحات وتحسينات واختبارات (2026-05-16)

**القرار:** مراجعة شاملة لكل ملفات EQI وإصلاح 6 مشاكل وإضافة 17 اختبار وحدة و 12 اختبار E2E.

**الإصلاحات:**
1. `pages/eqi/dashboard.php:659` — إزالة تحميل Chart.js المكرر (كان يُحمّل مرتين: في header.php و dashboard.php)
2. `pages/eqi/dashboard.php:198` — استبدال `COALESCE(eq.overall_eqi, NULL)` بـ `eq.overall_eqi` (COALESCE مع NULL لا فائدة منه)
3. `pages/eqi/dashboard.php:174-185` — المخطط الخطي أصبح يعتمد على السنة المختارة بدلاً من آخر 6 فصول دائماً
4. `pages/eqi/calculate.php:74-89` — إضافة حد أقصى 200 تركيبة للحساب الجماعي لمنع timeout/DoS
5. `pages/eqi/calculate.php` — إضافة حماية للدور: منع school_admin من تزييف طلب batch عبر POST
6. `pages/eqi/index.php:268,274` — إصلاح `reset()` على `array_filter` فارغ و `array_search` يُرجع `false`
7. `includes/sidebar.php:278` — تغليف رابط إعدادات الأوزان بـ `canSeeMenu('eqi_settings', ...)` للاتساق

**اختبارات الوحدة الجديدة (17 اختبار في `tests/unit/EQICalculatorTest.php`):**
- TC-013.1-5: `calculateClimateScore()`، `calculateBehaviorScore()`، `calculateSkillsScore()`، `calculateAttendanceScore()` — مع بيانات وبدون
- TC-014.1-2: `getEQIHistory()` — يعيد سجلات ويعيد مصفوفة فارغة عند عدم وجود بيانات
- TC-015.1-2: `getRecommendations()` — يعيد 6 توصيات ومصفوفة فارغة
- TC-016.1-3: `checkDataAvailability()` — أكاديمي متوفر، جميع الأبعاد غير متوفرة، يحتوي على page/page_label
- TC-017.1-2: مسار proxy للتدريس عبر `calculate()`— proxy عند غياب تقييم و direct عند وجوده
- TC-018.1: `compareSchools()` — مقارنة مدرستين
- TC-019.1: `loadWeights()` — استثناء DB يعود للأوزان الافتراضية

**اختبارات E2E الجديدة (12 اختبار في `tests/e2e/eqi-module.spec.js`):**
- EQ-01 إلى EQ-03: صلاحيات صفحات EQI للأدوار الثلاثة (13 صفحة × 3 أدوار)
- EQ-04: مركز القيادة — KPI موجودة
- EQ-05: فلتر جميع السنوات في dashboard
- EQ-06: نموذج الحساب موجود
- EQ-07: خيارات batch موجودة لـ group_admin
- EQ-08: school_admin لا يرى خيارات batch
- EQ-09: إرسال نموذج الحساب
- EQ-10: تحميل صفحة المقارنة
- EQ-11: تفاصيل المؤشر مع السجل التاريخي
- EQ-12: صفحة الأبعاد مع teacher و school_admin

**الملفات المعدلة:**
- `pages/eqi/dashboard.php` — إزالة Chart.js مكرر، إصلاح COALESCE، تحسين المخطط الخطي
- `pages/eqi/calculate.php` — حد أقصى للتركيبات + حماية الدور
- `pages/eqi/index.php` — إصلاح reset/array_search
- `includes/sidebar.php` — canSeeMenu لـ eqi_settings
- `tests/unit/EQICalculatorTest.php` — +17 اختبار
- `tests/e2e/eqi-module.spec.js` — ملف جديد (12 اختبار E2E)
- `AGENTS.md` — هذا القسم

### 21. إشعار مدير المدرسة بتعديل الحضور — تحسين وحدة الحضور (2026-05-22)

**القرار:** عند تعديل معلم لسجل حضور في `amend.php`، يُرسل إشعار (`notifications`) لمدير المدرسة فقط.

**التصميم:**
- لا يُستخدم `AlertEngine` ولا جدول `alerts` — إدراج مباشر في `notifications` فقط
- يُبحث عن `school_admin` في نفس `school_id` للمدرسة المعنية
- لا يصل لـ `group_admin` ولا ولي الأمر
- يستخدم دالة `attendanceStatusAr()` من `helpers.php` لترجمة الحالات إلى العربية
- جلب اسم الطالب عبر JOIN إضافي مع `students` في استعلام السجل

**محتوى الإشعار:**
- العنوان: "تعديل سجل حضور"
- الرسالة: "قام المعلم {اسمه} بتعديل حضور الطالب {اسمه} من (حالة) إلى (حالة). السبب: {السبب}"
- `type = 'info'` (معلوماتي)
- `related_entity_type = 'attendance_daily'`
- `created_at = NOW()`

**الملف المعدل:**
- `pages/attendance/amend.php` — تعديل استعلام SQL (JOIN مع students) + إضافة كود الإشعار بعد `$db->commit()`

### 22. تحديث بيانات الاستمارات الشامل — v4.0.0 (2026-05-17)

**القرار:** إعادة بناء وتوسيع بيانات جميع استمارات التقييم في `assessment_structure` لجعلها متكاملة وحقيقية ومعبرة عن احتياجات مدير المجموعة.

**إصلاح علة هامة:** تم اكتشاف أن `parent_id` في استمارات 1 و2 و4 تشير إلى IDs خاطئة (إزاحة +18). \
مثلاً: items 19-20 كان `parent_id=19` والصحيح `parent_id=1`. تم إصلاحها.

**الملخص الكمي:**

| الاستمارة | الحالة السابقة | الحالة الجديدة |
|-----------|---------------|----------------|
| سلوكي (1) | 6 أسئلة | **12 سؤالاً** (+ محور المسؤولية الشخصية) |
| مهارات (2) | 6 أسئلة | **16 سؤالاً** (+ محور المهارات التكنولوجية) |
| تقييم ذاتي (3) | سؤال واحد | **24 سؤالاً** — 5 محاور كاملة |
| أقران (4) | 4 أسئلة | **16 سؤالاً** (+ محوري الممارسات المهنية والإبداع) |
| إشرافي (5) | سؤالين "fdvd/fdf" | **23 سؤالاً** — 5 محاور كاملة |
| مناخ مدرسي (6) | 18 سؤالاً فارغاً | **24 سؤالاً** بكل النصوص |
| مرافق (7) | 4 أسئلة | **13 سؤالاً** (+ محور الأمن والسلامة) |
| رضا معلمين (14) | **معطل**، 13 سؤالاً فارغاً | **مفعّل**، 20 سؤالاً كاملة |
| رضا أولياء (17) | **معطل**، 12 سؤالاً فارغاً | **مفعّل**، 20 سؤالاً كاملة |

**الأوزان:** جميع المعايير وزعت بأوزان نسبية (مجموع 100 لكل استمارة) لتصبح جاهزة لخوارزميات التقييم المستقبلية.

**التنظيف:** تعطيل بيانات الاختبار (`form_id=22`) وترتيب `sort_order`.

**الملفات:**
- `scripts/migrate_v4.0.0.sql` — سكريبت الترحيل الكامل (قابل للتشغيل المتكرر)

### 22. دعم استمارات تقييم متعددة من نفس النوع (2026-05-17)

**القرار:** إزالة UNIQUE constraint من `assessment_forms.form_key`، إضافة `is_default` و `version` لدعم استمارات متعددة لكل نوع.

**التصميم:**
- `form_key`: لم يعد UNIQUE — يمكن وجود استمارات متعددة بنفس المفتاح
- `is_default`: TINYINT(1) DEFAULT 0 — تحدد الاستمارة الافتراضية لكل نوع
- `version`: VARCHAR(50) — للتفرقة بين الاستمارات (إصدار، مرحلة، فصل)

**آلية اختيار الاستمارة في صفحات التقييم (22 ملف):**
```sql
SELECT id FROM assessment_forms 
WHERE form_key = 'xxx' AND is_active = 1 
ORDER BY is_default DESC, created_at DESC 
LIMIT 1
```
- الافتراضية أولاً، ثم الأحدث تاريخياً

**واجهة الإدارة (`manage_forms.php`):**
- عرض النماذج مجمعة حسب النوع
- زر "تعيين كافتراضي" لكل استمارة (يُزيل الافتراضية عن بقية نفس النوع)
- شارة ⭐ افتراضي للتمييز
- عرض الإصدار (version)

**ملاحظة:** `form_key` لا يزال `ENUM` — لا يمكن إضافة أنواع جديدة دون تعديل الكود

**الملفات الجديدة:**
- `scripts/migrate_v3.4.0.sql` — ALTER TABLE + تعيين is_default

**الملفات المعدلة:**
- `database/schema.sql` — إزالة UNIQUE KEY، إضافة `is_default`, `version`
- `includes/assessment_engine.php` — إضافة `version` إلى INSERT
- `pages/assessment/add_form.php` — إزالة فحص التكرار، إضافة حقل `version`
- `pages/assessment/manage_forms.php` — إعادة بناء كاملة (عرض مجمع + إدارة افتراضي)
- `pages/assessment/view_form.php` — عرض `is_default` و `version`
- `includes/behavior_calculator.php` — ORDER BY is_default DESC, created_at DESC
- 18 ملف تقييم (climate, skills, behavior, facility, teaching/self, teaching/peer, teaching/admin) — نفس التغيير
- `tests/bootstrap.php` — إضافة `is_default`, `version` إلى schema الاختبار

### 23. إصلاح وحدة تقييم المهارات — الترحيل من skills_evaluations إلى evaluations (2026-05-17)

**القرار:** إصلاح وحدة تقييم المهارات (skills) للعمل مع الجداول الموجودة (`evaluations` + `assessment_responses`) بدلاً من `skills_evaluations` و `skills_items` القديمة.

**الإصلاحات:**
- `pages/skills/index.php`: استبدال 10 مراجع من `overall_score` → `combined_score`، واستبدال `evaluation_date` → `created_at` (لأن جدول `evaluations` يستخدم `combined_score` و `created_at` وليس `overall_score` / `evaluation_date`).
- `pages/skills/evaluate.php`: تحديث استعلام التقييمات السابقة من `skills_evaluations` إلى `evaluations` مع `eval_type = 'skills_checklist'`، واستخدام `e.combined_score as overall_score` للحفاظ على توافقية الأسماء.
- تم تشغيل اختبار Playwright شامل (إنشاء ← عرض ← تعديل) ونجح ✅

**الملفات المعدلة:**
- `pages/skills/index.php` — 10 إصلاحات مرجعية
- `pages/skills/evaluate.php` — تحديث استعلام التقييمات السابقة

**ملاحظة:** بقية الملفات (`view.php`, `edit.php`) كانت قد أُصلحت مسبقاً في الجلسة السابقة.

### 24. إصلاح climate_survey.php بعد دمج climate_surveys في surveys (2026-05-17)

**القرار:** إصلاح `pages/supervision/climate_survey.php` للعمل مع جدول `surveys` الجديد (بعد ترحيل v3.3.0) الذي يخزن استجابة واحدة لكل صف، بدلاً من `climate_surveys` القديم الذي كان يخزن بيانات مجمعة.

**الإصلاحات:**
- استبدال استعلام `SELECT * FROM surveys` باستعلام تجميعي (GROUP BY `respondent_type`) يحسب `COUNT(*) AS total_respondents` و `AVG(overall_score) AS avg_score` وحالة مركبة عبر `CASE WHEN`.
- استبدال `$survey['title']` بـ `$respondentLabels[$survey['respondent_type']]` لأن جدول `surveys` لا يحتوي على عمود `title`.
- إزالة أزرار الإجراءات (تفعيل/إغلاق) لأن معالج POST يُحوّل إلى الصفحة الجديدة (`pages/climate/survey.php`) ولا يعالج هذه الإجراءات — وبالتالي كان `$survey['id']` يُسبب خطأ key غير موجود بعد التجميع.

**الملفات المعدلة:**
- `pages/supervision/climate_survey.php` — استعلام SQL + إزالة أزرار الإجراءات الميتة

### 25. إنشاء تقرير المدرسة — school_report.php (2026-05-19)

**القرار:** إنشاء `pages/reports/school_report.php` لتوليد تقرير PDF مخصص لكل مدرسة. يحل مشكلة الرابط المكسور في `pages/dashboard/school_admin.php:775`.

**التصميم:**
- **الصلاحية:** `group_admin` + `school_admin` فقط (بدون تداخل)
- **group_admin:** يرى قائمة منسدلة لاختيار أي مدرسة
- **school_admin:** مقيد بـ `currentSchoolId()` تلقائياً — لا يرى قائمة اختيار، لا يمكنه التلاعب بـ `school_id` في POST
- **teacher:** ممنوع الدخول (403)

**فحص الصلاحية (مزدوج — POST و GET):**
```php
$userRole = $_SESSION['user']['role'] ?? '';
if ($userRole !== 'group_admin' && $userRole !== 'school_admin') {
    // 403 في POST، redirect في GET
}
```

**محتوى التقرير PDF (8 أقسام):**
1. غلاف: اسم المدرسة + السنة + المُعد
2. الملخص التنفيذي: 4 بطاقات KPI (طلاب، معلمين، شعب، مؤشر الجودة)
3. مؤشر جودة التعليم (EQI): الدرجة الكلية + الأبعاد الفرعية (5 أبعاد) + الاتجاه السنوي
4. الحضور والانضباط: نسبة الحضور + إحصائيات السلوك
5. التقييمات الإشرافية: عدد التقييمات + المتوسط
6. المناخ المدرسي: نتائج الاستبيانات
7. الأداء الأكاديمي: جدول المواد بالمتوسط + أفضل/أضعف مادة
8. التنبيهات المفتوحة: آخر 10 تنبيهات غير محلولة
9. التوصيات الذكية: 3-7 توصيات حسب الأداء

**البيانات:** 9 استعلامات محسّنة مع فلترة `school_id` في كل منها.
**محرك PDF:** يستخدم `ReportTemplate` الموجود في `includes/report_template.php`.

**الملفات الجديدة:**
- `pages/reports/school_report.php` — تقرير مدرسة PDF (450 سطر)

**لم يتم تعديل أي ملف موجود.**

### 26. إصلاح خطأ مسار config.php في التقرير السنوي — واختبار POST المباشر (2026-05-20)

**القرار:** إصلاح مسار تضمين `config.php` في `pages/reports/annual_report.php`، والتحقق من عدم إعادة التوجيه إلى `login.php` بعد إرسال النموذج.

**الخطأ:** السطر 10 كان يحاول تضمين `../../includes/config.php` (أي `includes/config.php`)، ولكن الملف موجود فعلياً في جذر المشروع (`config.php`). مما تسبب في Fatal Error عند تحميل الصفحة.

**الإصلاح:** تغيير المسار من:
```php
require_once __DIR__ . '/../../includes/config.php';
```
إلى:
```php
require_once __DIR__ . '/../../config.php';
```

**التحقق (Playwright — manually verified):**
- تحميل الصفحة: ✅ لا يوجد Fatal Error، النموذج يظهر مع القوائم المنسدلة
- إرسال النموذج (POST): ✅ لا إعادة توجيه إلى `login.php`، الصفحة تبقى على `annual_report.php`
- فحص PHPUnit: 257 اختبار، 0 فشل جديد (فقط فشلان سابقان غير مرتبطين)
- فحص PHP syntax: لا توجد أخطاء

**الملفات المعدلة:**
- `pages/reports/annual_report.php:10` — إصلاح مسار config.php

### 27. إلغاء جدول attendance_amendments والاعتماد على amendment_log JSON (2026-05-22)

**القرار:** إزالة كل الإشارات إلى `attendance_amendments` و `old_attendance_amendments` من الكود، والاعتماد على عمود `amendment_log` (LONGTEXT/json) الموجود مسبقاً في `attendance_daily`.

**السبب:** ميغرايشن v3.3.0 أعادت تسمية `attendance_amendments` وأضافت `amendment_log` إلى `attendance_daily` الهدف منها استبدال الجدول المنفصل، لكن كود PHP لم يُحدّث. الجدول الأصلي لم يكن موجوداً أصلاً في `database/schema.sql`.

**التصميم:**
- **الكتابة:** داخل الترانزاكشن مع `FOR UPDATE` لقفل الصف، إضافة إدخال JSON إلى `amendment_log` عبر `substr` لإزالة `]` وإلحاق الإدخال الجديد
- **القراءة:** `json_decode()` لكل سجل، دمج المصفوفات، `usort()` تنازلياً حسب `created_at`، ثم جلب أسماء المستخدمين من `users`
- بنية كل إدخال: `{old_status, new_status, reason, amended_by, created_at}`
- **بدون جداول جديدة، بدون تغيير schema، بدون تغيير واجهة المستخدم**

**الملف المعدل:**
- `pages/attendance/amend.php` — السطر 66-89 (INSERT → UPDATE)، السطر 209-247 (SELECT → json_decode)

### 28. تحصين أمني P0 — IDOR/CSRF/تسجيل أولياء الأمور/معالج أخطاء (2026-10-20)

**القرار:** تنفيذ دفعة تحصين P0 تعالج ثغرات عالية الخطورة، دون أي تغيير في مخطط قاعدة البيانات.
نقطة الرجوع: وسم `pre-p0-hardening-2026-10-20` + `backups/pre_p0_2026-10-20.sql` + `docs/rollback-plan.md`.
التوثيق التفصيلي: `docs/security-hardening-p0.md`.

**الإصلاحات:**
1. **IDOR** `pages/parent_ack/acknowledge.php` — إضافة فلتر `AND s.school_id = ?` لغير `group_admin`،
   ونقل تضمين `header.php` إلى بعد كل قرارات الصلاحية (لتصدر إعادة التوجيه 302 نظيفة).
2. **تسجيل أولياء الأمور** `pages/parents/register.php` — الحساب الجديد `is_active = 0` (بانتظار اعتماد
   مدير المدرسة)، إلغاء الربط التلقائي غير الموثّق للحساب القائم (يُسجَّل إشعار تدقيقي فقط)،
   throttle 5 محاولات/15د، تحقق صيغة الجوال، وعدم عرض كلمة المرور.
3. **CSRF** على `api/link_parent.php`, `api/unlink_parent.php`, `api/notifications/mark_read.php`
   عبر `verifyCsrf()` (يقبل التوكن من `$_POST` أو `X-CSRF-Token` الذي يضيفه `main.js` تلقائياً).
4. **التقارير** `includes/report_template.php` — فحص وجود `vendor/autoload.php` و`TCPDF` ورمي
   `RuntimeException` ودّي؛ وإضافة CSRF لنموذجي `annual_report.php` و`school_report.php`.
5. **معالج أخطاء مركزي** `includes/error_handler.php` — تسجيل في `logs/app.log`، صفحة خطأ عربية
   موحّدة + رقم مرجعي، منع تسريب التفاصيل في الإنتاج، تحويل `E_USER_ERROR` إلى استثناء،
   والتقاط الأخطاء القاتلة. يُحمَّل من نقاط الدخول + `header.php` + `config.*.php`.
6. **حماية السجلات** — `logs/.htaccess` (deny all) + `RewriteRule ^logs/ - [F,L]` + `logs/*.log` في `.gitignore`.
7. **حذف كود تصحيح** `pages/academic_assessment/grade_entry.php` — كتلة `?debug=1` التي تطبع الجلسة.
8. **حذف كود ميت** `includes/permission_middleware.php` — `$PAGE_PERMISSIONS` و`applyAutoPermissions()`
   (0 مراجع، وخريطة تخالف الصلاحيات الفعلية).

**الملفات الجديدة:**
- `includes/error_handler.php`
- `logs/.htaccess`
- `docs/security-hardening-p0.md`

**التحقق:** PHP lint (19 ملف) نظيف؛ PHPUnit 98/98؛ فحوص حية (curl) لكل بند موثّقة في الوثيقة الأمنية.
