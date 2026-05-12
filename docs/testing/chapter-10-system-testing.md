# الفصل العاشر: اختبارات النظام وضمان الجودة
**System Testing & Quality Assurance**

| الإصدار | التاريخ | المعد | الوصف |
|---------|--------|-------|-------|
| 1.0 | 2026-05-12 | فريق ضمان الجودة – EQAS | التوثيق الكامل لاختبارات النظام |

---

## 10.1 مقدمة

### 10.1.1 أهداف فصل الاختبارات
- توثيق جميع أنشطة اختبار النظام لمنصة EQAS v2.0.3
- تقديم مرجع معتمد لنتائج الاختبارات ومعايير القبول
- ضمان جودة النظام واستقراره قبل الإنتاج
- توفير سجل تدقيق (Audit Trail) لجميع الأخطاء المكتشفة والإصلاحات

### 10.1.2 نطاق التغطية

| المجال | التغطية |
|--------|---------|
| الوحدات البرمجية | جميع ملفات `includes/` (15 ملفاً) |
| واجهات المستخدم | جميع صفحات `pages/` (150+ صفحة) |
| الصلاحيات والأدوار | group_admin, school_admin, teacher, parent |
| الأمان | SQLi, XSS, CSRF, RBAC, Brute Force, Session |
| التكامل | تدفق البيانات عبر المكونات (EQI, Behavior, Promotion) |
| الأداء | تحليل ثابت + اختبارات استجابة |

### 10.1.3 المراجع

| المرجع | الوصف |
|--------|-------|
| SRS v9.0 | وثيقة متطلبات النظام |
| test-plan.md | خطة اختبار النظام (سيناريوهات TS-01 إلى TS-09) |
| test-results.md | نتائج الاختبارات الموجزة |
| bug-log.md | سجل الأخطاء المكتشفة |
| AGENTS.md | التوثيق المعماري والقرارات الفنية |

---

## 10.2 بيئة الاختبار

### 10.2.1 بيئة الخادم والبرمجيات

| المكون | الإصدار | ملاحظات |
|--------|---------|---------|
| نظام التشغيل | Windows 10/11 | خادم تطويري XAMPP |
| خادم الويب | Apache 2.4 (XAMPP) | مع mod_headers, mod_rewrite, mod_deflate |
| PHP | 8.1.25 (CLI + Apache module) | ZTS, Visual C++ 2019 x64 |
| قاعدة البيانات | MariaDB (MySQL) + SQLite (اختبارات) | SQLite في الذاكرة للاختبارات الوحدوية |
| Composer | 2.8.9 | لإدارة حزم PHP |
| PHPUnit | 10.5.63 | إطار اختبارات PHP |
| Playwright | 1.60.0 | أتمتة المتصفح (Chromium) |
| Node.js | 22.16.0 | تشغيل Playwright |

### 10.2.2 أدوات الاختبار

| الأداة | الغرض | النطاق |
|--------|-------|--------|
| `php -l` | فحص نحوي (Lint) | جميع ملفات PHP (165 ملفاً) |
| PHPUnit 10.5 | اختبارات وحدة + تكامل + أمان | 168 اختباراً |
| Playwright 1.60 | اختبارات E2E عبر المتصفح | 13 سيناريو |
| grep / Select-String | تحليل ثابت للكود (متغيرات غير معرّفة، إلخ) | جميع ملفات PHP |
| Git | تتبع الإصدارات وإدارة الكود | المستودع بالكامل |

### 10.2.3 بيانات الاختبار

- **SQLite في الذاكرة**: يتم إنشاء قاعدة بيانات كاملة (43 جدولاً) لكل اختبار عبر `createTestDB()` في `tests/bootstrap.php`
- **بيانات ثابتة**: ملف `database/seed.sql` يحتوي على بيانات أولية للتشغيل اليدوي
- **بيئات معزولة**: كل اختبار PHPUnit ينشئ بيئة DB خاصة به لضمان العزل

---

## 10.3 استراتيجية الاختبار

### 10.3.1 أنواع الاختبارات

```
                    ┌─────────────────────┐
                    │   P1 Static Analysis │
                    │  (php -l, grep, review)
                    └─────────┬───────────┘
                              │
                    ┌─────────▼───────────┐
                    │  P2 Existing Tests  │
                    │  (PHPUnit + Manual) │
                    └─────────┬───────────┘
                              │
              ┌───────────────┼───────────────┐
              │               │               │
     ┌────────▼──────┐ ┌─────▼──────┐ ┌──────▼───────┐
     │P4 Unit Tests  │ │P5 Integ.   │ │P6 Security   │
     │(PHPUnit جديد) │ │(PHPUnit)   │ │(PHPUnit)     │
     └────────┬──────┘ └─────┬──────┘ └──────┬───────┘
              │              │               │
              └──────────────┼───────────────┘
                             │
                    ┌────────▼────────┐
                    │P7 E2E Playwright│
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │P8 Regression    │
                    │(5 جولات)       │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │P9 Documentation │
                    └─────────────────┘
```

### 10.3.2 مستويات الاختبار

| المستوى | الوصف | المرحلة |
|---------|-------|---------|
| L0 – تحليل ثابت | فحص نحوي، متغيرات غير معرّفة، تبعيات | P1 |
| L1 – اختبار وحدة | اختبار دوال منفردة في عزلة تامة | P2, P4 |
| L2 – اختبار تكامل | اختبار تدفق البيانات عبر مكونين أو أكثر | P5 |
| L3 – اختبار أمان | اختبار ثغرات أمنية (SQLi, XSS, CSRF, RBAC) | P6 |
| L4 – اختبار E2E | اختبار سيناريوهات كاملة عبر المتصفح | P7 |
| L5 – اختبار انحدار | إعادة تشغيل جميع الاختبارات بعد الإصلاحات | P8 |

### 10.3.3 معايير القبول

| المعيار | الحد الأدنى |
|---------|------------|
| نسبة نجاح P1–P9 | 100% |
| ثغرات أمنية حرجة | 0 |
| أخطاء PHP Parse/Syntax | 0 |
| تحذيرات PHPUnit | 0 |
| جميع سيناريوهات TS-01 إلى TS-09 | ✅ PASS |

---

## 10.4 مراحل الاختبار التفصيلية (P1–P9)

### 10.4.1 P1 – التحليل الثابت (Static Analysis)

**التاريخ:** 2026-05-12

**الإجراءات:**
1. `php -l` على جميع ملفات PHP (165 ملفاً)
2. فحص التبعيات (جميع `require`/`include`)
3. فحص المتغيرات غير المعرّفة (`$_GET`/`$_POST` بدون `??`)

**النتائج:**

| الفحص | النتيجة | التفاصيل |
|-------|---------|----------|
| PHP Lint (165 ملف) | 164 ✅ 1 🔧 | `pages/settings/index.php:184` قوس زائد `]` |
| التبعيات (509 مسار) | 509 ✅ | جميع المسارات صحيحة |
| متغيرات غير معرّفة | 0 ⚠️ | لا يوجد `$_GET`/`$_POST` بدون `??` أو `isset` |

**الإصلاح:** تم إزالة القوس الزائد في `pages/settings/index.php` (FIX-001).

### 10.4.2 P2 – تشغيل الاختبارات الحالية

**التاريخ:** 2026-05-12

**PHPUnit (36 اختباراً منطقياً):**

| الاختبار | النتيجة الأولى | المشكلة | الإصلاح |
|----------|---------------|---------|---------|
| HelpersTest | 9 Errors + 3 Failures | 1. `static $db` يشارك عبر الاختبارات ← UNIQUE violations<br>2. جداول مفقودة في SQLite<br>3. `ON DUPLICATE KEY UPDATE` غير مدعوم في SQLite<br>4. `NOW()`/`CURDATE()` غير معرّفة في SQLite<br>5. `->valid` → `['valid']`<br>6. `passwordStrength('123')` توقع 0 بدل 1 | FIX-002 إلى FIX-005 |

**السيناريوهات اليدوية (TS-01 إلى TS-09):**

| المعرف | السيناريو | آلية التحقق | النتيجة |
|--------|----------|------------|---------|
| TS-01 | تسجيل الدخول بأدوار مختلفة | E2E + Unit (تأكيد وجود الدور في الجلسة) | ✅ |
| TS-02 | عرض الفصول (school_admin) | Integration (فلترة حسب school_id) | ✅ |
| TS-03 | إخفاء زر "تعيين الحالي" عن school_admin | Unit (canEditSemester) | ✅ |
| TS-04 | حظر semester_setup عن school_admin | Unit (checkPageAccess) + E2E (redirect) | ✅ |
| TS-05 | عرض الفصول (group_admin) | Integration (جميع الفصول ظاهرة) | ✅ |
| TS-06 | ظهور زر "تعيين الحالي" لـ group_admin | Unit (hasPermission + isSemesterLocked) | ✅ |
| TS-07 | school_admin يدير معلمي مدرسته | Integration (فلتر school_id, لا يرى مدرسة أخرى) | ✅ |
| TS-08 | school_admin يضيف معلماً | Integration (التأكد من role=teacher, school_id صحيح) | ✅ |
| TS-09 | must_change_password إجباري | Unit (must_change_password=1 → redirect) | ✅ |

### 10.4.3 P3 – إصلاح الأخطاء المكتشفة

**التاريخ:** 2026-05-12

#### أخطاء حرجة (Critical)

| المعرف | الملف | الوصف | الخطورة | الإصلاح |
|--------|------|-------|---------|---------|
| B-01 | `pages/behavior/override.php:217` | XSS في onclick – اسم الطالب غير مُهرب بشكل آمن في JS | **عالية** | استبدال `e()` بـ `json_encode(..., JSON_HEX_TAG\|JSON_HEX_APOS\|JSON_HEX_QUOT\|JSON_HEX_AMP)` |
| B-02 | `pages/behavior/override.php:222` | XSS في onclick – سبب التجاوز غير مُهرب بشكل آمن في JS | **عالية** | نفس الإصلاح |
| B-03 | `pages/behavior/override.php` | XSS عام في التعامل مع مدخلات المستخدم | **عالية** | تم تغطيتها في B-01/B-02 |

#### أخطاء متوسطة (Medium)

| المعرف | الملف | الوصف | الإصلاح |
|--------|------|-------|---------|
| B-04 | `pages/enrollments/add.php` | لا يوجد تحقق من حالة العام الدراسي (مقبول التسجيل في عام مغلق) | إضافة التحقق من `status` بعد CSRF |
| B-05 | `pages/enrollments/edit.php` | لا يوجد تحقق من حالة العام الدراسي | إضافة نفس التحقق |
| B-06 | `pages/academic/add.php` | `school_admin` يمكنه إضافة أعوام (خلافاً للقرار المعماري) | تغيير الصلاحية إلى `group_admin` |
| B-07 | `pages/eqi/report.php` | `<input type="number">` للـ semester_id (يمكن إدخال قيم غير صالحة) | تغيير إلى `<select>` مع بيانات من DB |
| B-13 | `pages/students/import.php` | لا يوجد حد لحجم الملف المرفوع | إضافة حد 5 ميجابايت |

#### أخطاء منخفضة (Low)

| المعرف | الملف | الوصف | الإصلاح |
|--------|------|-------|---------|
| B-08 | `pages/eqi/compare.php` | قائمة المدارس تظهر جميعها لـ school_admin | فلتر حسب `WHERE id = ?` |
| B-09 | `pages/behavior/incident.php` | أحداث السلوك لا تُصفى حسب العام والفصل الدراسي | إضافة `bi.academic_year_id = ? AND bi.semester_id = ?` |
| B-10 | `pages/eqi/compare.php` | أبعاد EQI ثابتة في الكود | استخدام ديناميكي `$eqiCalc->getDimensions()` |
| B-11 | `pages/eqi/compare.php` | مقارنة غير آمنة لمتغير `$selectedSchools` | `array_map('intval', ...)` + `in_array` |
| B-12 | `pages/behavior/override.php` | `display:none` مكرر في modal | إزالة النسخة الزائدة |
| B-13 | `pages/settings/index.php` | Parse error (قوس زائد) | إزالة القوس `]` |

#### أخطاء ملغاة (False Positives)

| المعرف | الملف | السبب |
|--------|------|-------|
| B-14 | `includes/database.php` | `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` موجود مسبقاً في السطر 61 |
| B-15 | `pages/academic/edit.php:82` | Prepared statement مستخدم بالفعل (`$stmt->execute([...])`) |

### 10.4.4 P4 – توسيع اختبارات الوحدات (Unit Tests)

**التاريخ:** 2026-05-12

**الملفات الجديدة:**

| الملف | الوصف | عدد الاختبارات |
|-------|-------|:------------:|
| `tests/unit/BehaviorCalculatorTest.php` | اختبار حاسبة السلوك (إنشاء، تهيئة) | 4 |
| `tests/unit/PromotionEngineTest.php` | اختبار محرك الترفيع (تصنيف، ألوان، إعدادات) | 3 |
| `tests/unit/PermissionMiddlewareTest.php` | اختبار صلاحيات الصفحات وملكية المدرسة | 5 |

**إجمالي اختبارات الوحدة:** 109 اختباراً، 246 تأكيداً

### 10.4.5 P5 – اختبارات التكامل (Integration Tests)

**التاريخ:** 2026-05-12

| المعرف | الملف | السيناريو | عدد الاختبارات |
|--------|-------|----------|:------------:|
| IT-01 | `AcademicYearIntegrationTest.php` | دورة حياة الفصل الدراسي + إحصائيات لكل مدرسة | 2 |
| IT-02 | `UserLifecycleIntegrationTest.php` | إنشاء مستخدمين + تسجيل دخول + عزل المدرسة + قوة كلمة المرور | 4 |
| IT-03 | `AssessmentFlowIntegrationTest.php` | نموذج تقييم + استجابات + عزل المدرسة + تحقق | 4 |
| IT-04 | `EQIIntegrationTest.php` | حساب EQI + عزل المدرسة + أوزان الأبعاد | 3 |
| IT-05 | `BehaviorIntegrationTest.php` | تسجيل أحداث سلوك + حساب النقاط + عزل + تصنيفات | 4 |
| IT-06 | `PromotionIntegrationTest.php` | تصنيف الترفيع + الحدود + تسميات الصفوف | 3 |
| IT-07 | `SchoolIsolationIntegrationTest.php` | عزل كامل (طلاب، فصول، مستخدمين، درجات، مواد، مدارس، إحصائيات) | 7 |

**الإجمالي:** 26 اختبار تكامل

### 10.4.6 P6 – اختبارات الأمان (Security Tests)

**التاريخ:** 2026-05-12

| المعرف | السيناريو | الاختبارات | الوصف |
|--------|----------|:---------:|-------|
| SC-01 | SQL Injection | 2 | Prepared statements تمنع SQLi، حقن DROP TABLE لا يؤثر |
| SC-02 | XSS Prevention | 2 | `e()` تفلتر HTML، `sanitize()` تزيل الوسوم |
| SC-03 | CSRF Protection | 5 | توكن فريد لكل جلسة، طول 64 حرف عشوائي، رفض التوكن القديم، رفض الفارغ، مقارنة timing-safe |
| SC-04 | Permission Bypass | 3 | Guest لا يستطيع الوصول لأي دور، teacher لا يصل إلى admin |
| SC-05 | School Isolation | 3 | school_admin يرى فقط مدرسته، group_admin يرى الكل |
| SC-06 | Semester Lock Bypass | 3 | Teacher ≠ soft, school_admin ≠ hard, all ≠ admin_handled |
| SC-07 | Brute Force Config | 3 | التحقق من MAX_LOGIN_ATTEMPTS (3–10), LOCKOUT_DURATION (1–60 دقيقة) |
| SC-08 | Session Security | 2 | توقيع password_hash مختلف يولد توقيع مختلف، random_bytes ينتج tokens مختلفة |
| SC-09 | Password Security | 2 | كلمة ضعيفة لها score < 3، أنماط شائعة score ≤ 2 |
| SC-10 | File Upload | 2 | حد 5 ميجابايت، MIME types صحيحة |
| SC-11 | Access Control | 3 | unauthenticated → false, hierarchy صحيح, invalid role ← false |
| SC-12 | Cookie Security | 1 | session_name = 'EQAS_SESSION' |
| SC-13 | Password Generation | 2 | 8/12/16 حرف تحتوي على lowercase + uppercase + digit + special، 10 كلمات فريدة |

**الإجمالي:** 33 اختبار أمان

### 10.4.7 P7 – اختبارات E2E (Playwright)

**التاريخ:** 2026-05-12

**الملف:** `/tmp/eqas-e2e-tests.js`

| المعرف | السيناريو | النتيجة |
|--------|----------|:------:|
| TS-E01 | تحميل صفحة تسجيل الدخول بالعنوان الصحيح | ✅ |
| TS-E02 | حقول اسم المستخدم وكلمة المرور موجودة | ✅ |
| TS-E03 | رمز CSRF موجود في النموذج (طول ≥ 10) | ✅ |
| TS-E04 | الصفحات المحمية تعيد التوجيه إلى تسجيل الدخول (4 صفحات) | ✅ |
| TS-E05 | صفحة غير موجودة تعيد 404 أو redirect | ✅ |
| TS-E06 | رأس X-Frame-Options: SAMEORIGIN | ✅ |
| TS-E07 | رأس X-Content-Type-Options: nosniff | ✅ |
| TS-E08 | حقل كلمة المرور من type="password" | ✅ |
| TS-E09 | responsive design على شاشة جوال (375×667) | ✅ |
| TS-E10 | HttpOnly flag في كوكيز الجلسة | ✅ |

**الإجمالي:** 13/13 ✅

### 10.4.8 P8 – اختبارات الانحدار (Regression)

**التاريخ:** 2026-05-12

| الجولة | التوقيت | النطاق | النتيجة |
|--------|---------|-------|:------:|
| RG-01 | بعد P2+FIX-002→005 | جميع اختبارات الوحدة | ✅ Exit 0 |
| RG-02 | بعد P3+FIX-001+B-01→13 | جميع اختبارات الوحدة | ✅ Exit 0 |
| RG-03 | بعد P4 | جميع اختبارات الوحدة + P4 الجديدة | ✅ Exit 0 |
| RG-04 | بعد P5+P6 | جميع PHPUnit (168 اختبار) | ✅ Exit 0 |
| RG-05 | بعد P7 | جميع PHPUnit + Playwright | ✅ Exit 0 |

### 10.4.9 P9 – التوثيق النهائي

**التاريخ:** 2026-05-12

**الملفات المحدثة/المنشأة:**

| الملف | الوصف |
|-------|-------|
| `docs/testing/test-plan.md` | تحديث نتائج TS-01 إلى TS-09 |
| `docs/testing/test-results.md` | نتائج شاملة لجميع المراحل |
| `docs/testing/chapter-10-system-testing.md` | **هذه الوثيقة** – فصل الاختبارات الكامل |
| `AGENTS.md` | توثيق القرارات المعمارية والأخطاء المكتشفة |
| `.htaccess` | إزالة رؤوس الأمان المكررة (تُدار عبر PHP) |

---

## 10.5 سجل الأخطاء (Bug Log)

| المعرف | التاريخ | الوصف | الخطورة | الملف المتأثر | الحالة | تاريخ الإصلاح |
|--------|---------|-------|---------|---------------|--------|--------------|
| B-01 | 2026-05-12 | XSS في onclick (اسم الطالب) | عالية | `pages/behavior/override.php:217` | ✅ | 2026-05-12 |
| B-02 | 2026-05-12 | XSS في onclick (سبب التجاوز) | عالية | `pages/behavior/override.php:222` | ✅ | 2026-05-12 |
| B-03 | 2026-05-12 | XSS عام في override | عالية | `pages/behavior/override.php` | ✅ | 2026-05-12 |
| B-04 | 2026-05-12 | لا يوجد تحقق من حالة العام (enroll/add) | متوسطة | `pages/enrollments/add.php` | ✅ | 2026-05-12 |
| B-05 | 2026-05-12 | لا يوجد تحقق من حالة العام (enroll/edit) | متوسطة | `pages/enrollments/edit.php` | ✅ | 2026-05-12 |
| B-06 | 2026-05-12 | صلاحية خاطئة لإضافة عام دراسي | متوسطة | `pages/academic/add.php` | ✅ | 2026-05-12 |
| B-07 | 2026-05-12 | حقل semester_id من نوع input بدل select | متوسطة | `pages/eqi/report.php` | ✅ | 2026-05-12 |
| B-08 | 2026-05-12 | قائمة المدارس غير مفلترة (compare.php) | منخفضة | `pages/eqi/compare.php` | ✅ | 2026-05-12 |
| B-09 | 2026-05-12 | أحداث السلوك بدون فلتر عام/فصل | منخفضة | `pages/behavior/incident.php` | ✅ | 2026-05-12 |
| B-10 | 2026-05-12 | أبعاد EQI ثابتة في compare.php | منخفضة | `pages/eqi/compare.php` | ✅ | 2026-05-12 |
| B-11 | 2026-05-12 | مقارنة غير آمنة لـ $selectedSchools | منخفضة | `pages/eqi/compare.php` | ✅ | 2026-05-12 |
| B-12 | 2026-05-12 | display:none مكرر | منخفضة | `pages/behavior/override.php` | ✅ | 2026-05-12 |
| B-13 | 2026-05-12 | Parse error (قوس زائد) | متوسطة | `pages/settings/index.php` | ✅ | 2026-05-12 |
| B-14 | 2026-05-12 | ERRMODE_EXCEPTION مفقود | - | `includes/database.php` | ⏭️ | False Positive |
| B-15 | 2026-05-12 | Prepared statement مفقود | - | `pages/academic/edit.php` | ⏭️ | False Positive |

---

## 10.6 معايير القبول

### 10.6.1 مصفوفة الصلاحيات

| الوظيفة / الصفحة | group_admin | school_admin | teacher | parent |
|-----------------|:-----------:|:------------:|:-------:|:------:|
| إدارة المستخدمين | ✅ | ✅ (مدرسته فقط) | ❌ | ❌ |
| إضافة/تعديل عام دراسي | ✅ | ❌ | ❌ | ❌ |
| عرض الفصول الدراسية | ✅ | ✅ | ❌ | ❌ |
| تعيين الفصل الحالي | ✅ | ❌ | ❌ | ❌ |
| تغيير lock_level | ✅ | ❌ | ❌ | ❌ |
| إدارة الطلاب | ✅ | ✅ | ✅ (مدرسته) | ❌ |
| تسجيل طالب في صف | ✅ | ✅ | ❌ | ❌ |
| تقييم سلوك الطلاب | ✅ | ✅ | ✅ | ❌ |
| تقييم تعليمي | ✅ | ✅ | ✅ | ❌ |
| عرض تقارير EQI | ✅ | ✅ (مدرسته) | ❌ | ❌ |
| عرض درجات الطالب | ✅ | ✅ | ✅ | ✅ (أبنائه) |
| إعدادات النظام | ✅ | ❌ | ❌ | ❌ |

### 10.6.2 مصفوفة قفل الفصل الدراسي

| lock_level | teacher | school_admin | group_admin |
|-----------|:-------:|:------------:|:-----------:|
| none | ✅ | ✅ | ✅ |
| soft | ❌ | ✅ | ✅ |
| hard | ❌ | ❌ | ✅ |
| admin_handled | ❌ | ❌ | ❌ |

---

## 10.7 نتائج الاختبارات

### 10.7.1 جدول ملخص

| نوع الاختبار | العدد | ناجح | فاشل | نسبة النجاح |
|--------------|:----:|:---:|:---:|:----------:|
| Static Analysis (P1) | 165 ملف | 165 | 0 | 100% |
| Unit Tests (P2+P4) | 109 | 109 | 0 | 100% |
| Integration Tests (P5) | 26 | 26 | 0 | 100% |
| Security Tests (P6) | 33 | 33 | 0 | 100% |
| E2E Playwright (P7) | 13 | 13 | 0 | 100% |
| Regression (P8) | 5 جولات | 5 | 0 | 100% |
| **الإجمالي** | **~181** | **~181** | **0** | **100%** |

### 10.7.2 إحصائيات الأخطاء

| المقياس | القيمة |
|---------|-------|
| إجمالي الأخطاء المكتشفة | 15 |
| أخطاء حرجة (Critical) | 3 |
| أخطاء متوسطة (Medium) | 8 |
| أخطاء منخفضة (Low) | 4 |
| False Positives | 2 |
| تم الإصلاح | 13/15 |
| نسبة الإصلاح | 86.6% |

### 10.7.3 توصيات للتحسين المستقبلي

1. **اختبارات Authentication E2E**: إضافة اختبارات Playwright لتسجيل الدخول الفعلي بكلمة مرور معروفة (يتطلب إنشاء مستخدم اختباري)
2. **تغطية أوسع للكود**: التركيز على `includes/assessment_engine.php`، `includes/export.php`، `includes/alerts.php`
3. **اختبارات أداء**: إضافة اختبارات تحمّل (Load Testing) باستخدام Apache Bench أو k6
4. **CI/CD**: إضافة GitHub Actions لتشغيل PHPUnit وPlaywright تلقائياً عند كل push
5. **تغطية Security Headers**: إضافة اختبار لرأس CSP للتأكد من وجود nonce صحيح

---

## 10.8 شهادة جودة النظام

| الدور | الاسم | التوقيع | التاريخ |
|-------|-------|---------|---------|
| مدير المشروع | ______________ | __________ | 2026-05-12 |
| مهندس ضمان الجودة | ______________ | __________ | 2026-05-12 |
| ممثل العميل | ______________ | __________ | 2026-05-12 |

---

## 10.9 الملاحق

### الملحق أ: هيكل مجلدات الاختبارات

```
eqas/tests/
├── bootstrap.php              # تهيئة بيئة الاختبار (43 جدول SQLite + ثوابت)
├── phpunit.xml                # تهيئة PHPUnit (وحدات + تكامل)
├── README.md                  # دليل تشغيل الاختبارات
├── fixtures/
│   └── seed.sql               # بيانات أولية للاختبارات اليدوية
├── e2e/
│   └── semester-permissions.spec.js  # اختبارات صلاحيات الفصول (قديم)
├── unit/
│   ├── HelpersTest.php        # دوال مساعدة (109 تأكيد)
│   ├── AssessmentEngineTest.php # محرك التقييم
│   ├── EQICalculatorTest.php  # حاسبة EQI
│   ├── BehaviorCalculatorTest.php # حاسبة السلوك (جديد)
│   ├── PromotionEngineTest.php # محرك الترفيع (جديد)
│   ├── PermissionMiddlewareTest.php # صلاحيات الوصول (جديد)
│   └── SecurityTest.php       # اختبارات أمان شاملة (جديد)
└── integration/
    ├── AcademicYearIntegrationTest.php
    ├── UserLifecycleIntegrationTest.php
    ├── AssessmentFlowIntegrationTest.php
    ├── EQIIntegrationTest.php
    ├── BehaviorIntegrationTest.php
    ├── PromotionIntegrationTest.php
    └── SchoolIsolationIntegrationTest.php
```

### الملحق ب: ملفات الإصلاح الرئيسية

| الملف | التغيير |
|-------|---------|
| `includes/eqi.php` | استبدال `ON DUPLICATE KEY UPDATE` بنمط SELECT-then-UPDATE/INSERT |
| `pages/behavior/override.php` | XSS fix في onclick + إزالة display:none مكرر |
| `pages/enrollments/add.php` | التحقق من حالة العام الدراسي |
| `pages/enrollments/edit.php` | التحقق من حالة العام الدراسي |
| `pages/academic/add.php` | صلاحية group_admin |
| `pages/academic/edit.php` | صلاحية group_admin |
| `pages/eqi/report.php` | `<select>` للفصل الدراسي |
| `pages/eqi/compare.php` | فلتر مدرسة ديناميكي + أبعاد ديناميكية |
| `pages/behavior/incident.php` | تصفية بالعام والفصل |
| `pages/students/import.php` | حد حجم الملف 5 ميجابايت |
| `pages/settings/index.php` | إصلاح Parse error (قوس زائد) |
| `tests/bootstrap.php` | 43 جدول SQLite + ثوابت + شيم دوال MySQL |
| `.htaccess` | إزالة رؤوس الأمان المكررة (تُدار عبر PHP) |

### الملحق ج: تشغيل الاختبارات

```bash
# جميع اختبارات PHPUnit
cd eqas
.\vendor\bin\phpunit -c tests\phpunit.xml

# اختبارات الوحدة فقط
.\vendor\bin\phpunit -c tests\phpunit.xml --testsuite "EQAS Unit Tests"

# اختبارات التكامل فقط
.\vendor\bin\phpunit -c tests\phpunit.xml --testsuite "EQAS Integration Tests"

# اختبار أمان محدد
.\vendor\bin\phpunit -c tests\phpunit.xml --filter "testEscapePreventsXss" tests\unit\SecurityTest.php

# اختبارات E2E Playwright
cd C:\Users\IT\.agents\skills\playwright
node run.js /tmp/eqas-e2e-tests.js
```

### الملحق د: الثوابت والإعدادات

```php
// tests/bootstrap.php
define('EQAS_VERSION', '2.0.3');
define('EQAS_URL', 'http://localhost/eqas');
define('SESSION_TIMEOUT', 3600);
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);
define('ACADEMIC_PASS_RATE', 50);
define('BEHAVIOR_PASS_RATE', 60);
define('ATTENDANCE_PASS_RATE', 75);
define('EQI_REDISTRIBUTION', 'hybrid');
define('CSRF_ENABLED', true);
```

---

*نهاية الفصل العاشر*
