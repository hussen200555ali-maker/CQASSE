# توثيق نقاط النهاية المتأثرة - EQAS v2.0.1

> هذا المستند يوثّق نقاط النهاية (pages) المتأثرة بإصلاحات الإصدار 2.0.1.
> النظام لا يستخدم REST API بل يعتمد على صفحات PHP تقليدية مع نماذج HTML.

---

## 1. نقاط النهاية المتأثرة بالإصلاحات

### 1.1 محرك التقييم (Assessment Engine)

#### `includes/assessment_engine.php`
الملف الأساسي لمحرك التقييم الموحد. يحتوي على الدوال التالية المتأثرة:

| الدالة | التغيير | التأثير |
|--------|---------|---------|
| `normalizeResponse()` | تصحيح صيغة severity_4 | درجات التطبيع لمقياس الشدة أصبحت صحيحة |
| `getFormWithDetails()` | `assessment_categories` → `assessment_criteria`، `category_id` → `criteria_id` | استعلام جلب تفاصيل النموذج يعمل بشكل صحيح |
| `getForms()` | `form_type` → `form_key` في شرط التصفية | تصفية النماذج حسب النوع تعمل بشكل صحيح |
| `createForm()` | `$data['form_type']` → `$data['form_key']` | إنشاء نموذج جديد يحفظ مفتاح النوع بشكل صحيح |
| `createCategory()` | `assessment_categories` → `assessment_criteria` | إنشاء معيار جديد يحفظ في الجدول الصحيح |
| `createItem()` | `$data['category_id']` → `$data['criteria_id']` | إنشاء عنصر تقييم يربط بالمعيار الصحيح |
| `getAssessmentStats()` | `form_type` → `form_key` في GROUP BY | إحصائيات التقييمات مجمّعة بشكل صحيح |

#### مخطط الاستجابة (Response Schema) المتأثر:

```
normalizeResponse() - severity_4 tool:
  قبل: value=4 → normalized=0%   (خطأ - لا يوجد مشكلة يجب أن تكون 100%)
  بعد: value=4 → normalized=100% (صحيح)

  قبل: value=1 → normalized=100% (خطأ - شديد يجب أن تكون 0%)
  بعد: value=1 → normalized=0%   (صحيح)
```

### 1.2 صفحات التقييم

#### `pages/assessment/add_form.php`
صفحة إنشاء/تعديل نماذج التقييم.

| العنصر | التغيير | السطر |
|--------|---------|-------|
| متغير POST | `$_POST['form_type']` → `$_POST['form_key']` | 47 |
| عبارة UPDATE | `form_type` → `form_key` في SQL | 56-57 |
| مفتاح المصفوفة | `'form_type' => $formType` → `'form_key' => $formType` | 64 |
| حذف المعايير | `assessment_categories` → `assessment_criteria` في DELETE | 76 |
| مفتاح عنصر | `'criteria_id' => $catId` (كان صحيحاً) | 102 |
| حقل HTML | `name="form_type"` → `name="form_key"` | 157 |
| حقل HTML | القيمة المحددة تستخدم `$existingForm['form_key']` | 160 |

#### صفحات عرض التقييم (8 صفحات)
جميعها تحتوي على نفس نمط التغيير في استعلام JOIN:

| الصفحة | المسار | التغيير |
|--------|--------|---------|
| التقييم الذاتي | `pages/teaching/self_view.php:53` | `assessment_categories` → `assessment_criteria` |
| التقييم الإشرافي | `pages/teaching/admin_view.php:50` | `assessment_categories` → `assessment_criteria` |
| تقييم الأقران | `pages/teaching/peer_view.php:48` | `assessment_categories` → `assessment_criteria` |
| فحص المرافق | `pages/facility/view.php:60` | `assessment_categories` → `assessment_criteria` |
| قائمة المهارات | `pages/skills/view.php:53` | `assessment_categories` → `assessment_criteria` |
| استبيان المناخ | `pages/climate/view.php:51` | `assessment_categories` → `assessment_criteria` |
| التقييم السلوكي | `pages/behavior/view.php:62` | `assessment_categories` → `assessment_criteria` |

### 1.3 وظائف القفل

#### `includes/helpers.php`
الدوال المساعدة لإدارة قفل الفصول الدراسية:

| الدالة | التغيير | الوصف |
|--------|---------|-------|
| `currentSemesterId()` | `status='open'` → `lock_level='none'` | جلب الفصل المفتوح الحالي |
| `isSemesterLocked()` | عمود `status` → `lock_level`، قيم `soft_locked/hard_locked` → `soft/hard` | التحقق من حالة القفل |
| `canEditSemester()` | عمود `status` → `lock_level`، قيم `open/soft_locked/hard_locked` → `none/soft/hard` | التحقق من صلاحية التعديل |
| `getSemesterStatusLabel()` | مفاتيح المصفوفة محدّثة | ترجمة حالة القفل للعربية |

#### مخطط الاستجابة المتأثر:

```
isSemesterLocked() - قيم الإرجاع:
  lock_level='none'          → false (غير مقفل)
  lock_level='soft'          → true  (مقفل ناعم)
  lock_level='hard'          → true  (مقفل صلب)
  lock_level='admin_handled' → true  (معالجة إدارية)

canEditSemester() - مصفوفة الصلاحيات:
  lock_level='none'          → الجميع يمكنهم التعديل
  lock_level='soft'          → group_admin + school_admin فقط
  lock_level='hard'          → group_admin فقط
  lock_level='admin_handled' → لا أحد (حتى group_admin)
```

#### `pages/academic/lock.php`
صفحة إدارة قفل الفصول الدراسية:

| العنصر | التغيير | السطر |
|--------|---------|-------|
| عبارة SELECT | تجلب جميع أعمدة semesters | 44 |
| حماية admin_handled | منع تخفيض المستوى من admin_handled | 114 |
| عبارة UPDATE | `lock_level = ?, locked_at = ?, locked_by = ?` | 127 |
| تحديث academic_years | تحديث status إلى 'closed' عند القفل الإداري | 134 |
| حقل النموذج | `name="lock_level"` (كان صحيحاً) | 222 |
| عرض الحالة | `$lockDescriptions[$sem['lock_level']]` | 199-200 |

---

## 2. مخطط قاعدة البيانات المتأثر

### 2.1 جدول assessment_forms

```sql
-- العمود المتأثر:
`form_key` ENUM(...) NOT NULL COMMENT 'مفتاح النموذج'
-- كان يُشار إليه خطأً كـ form_type في الكود
```

### 2.2 جدول assessment_criteria

```sql
-- اسم الجدول الفعلي:
assessment_criteria (ليس assessment_categories)
-- العمود الرئيسي:
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT
-- كان يُشار إليه خطأً كـ category_id في الكود
```

### 2.3 جدول assessment_items

```sql
-- العمود المتأثر:
`criteria_id` INT UNSIGNED NOT NULL COMMENT 'رقم المعيار'
-- كان يُشار إليه خطأً كـ category_id في الكود
```

### 2.4 جدول semesters

```sql
-- الأعمدة المتعلقة بالقفل:
`lock_level` ENUM('none','soft','hard','admin_handled') NOT NULL DEFAULT 'none'
`locked_at` DATETIME DEFAULT NULL
`locked_by` INT UNSIGNED DEFAULT NULL
-- كان يُشار إليها خطأً كعمود status بقيم open/soft_locked/hard_locked
```

---

## 3. الإصدارات المتوافقة

| المكوّن | الإصدار الأدنى | الإصدار المُختبر |
|---------|---------------|-----------------|
| PHP | 7.4 | 7.4+ |
| MySQL | 5.7 | 8.0 |
| MariaDB | 10.3 | 10.6 |
| Chart.js | 4.x | 4.4 |
| TCPDF | 6.x | 6.7 |
| المتصفح | Chrome 80+ | Chrome 120+ |

---

*آخر تحديث: 2026-05-11 | الإصدار: 2.0.1*

---

## 4. نقطة نهاية AJAX

### 4.1 `api/check_username.php`

نقطة نهاية AJAX للتحقق من تفرد اسم المستخدم أثناء إنشاء/تعديل حساب.

**طريقة الطلب**: `GET`

**المعاملات**:

| المعامل | النوع | مطلوب | الوصف |
|---------|-------|-------|-------|
| `username` | string | نعم | اسم المستخدم للتحقق (3 أحرف كحد أدنى) |
| `exclude` | int | لا | معرف المستخدم لاستثنائه من البحث (للتعديل) |

**مخطط الاستجابة (Response Schema)**:

```json
{
  "available": true|false,
  "message": "اسم المستخدم متاح" | "اسم المستخدم مستخدم بالفعل" | "اسم المستخدم قصير جداً"
}
```

**رموز الحالة**:

| الرمز | السبب |
|-------|-------|
| 200 | نجاح |
| 401 | عدم تسجيل الدخول |
| 403 | الطلب ليس AJAX |

**الأمان**:
- فحص `isAjax()` لمنع الطلبات المباشرة
- فحص جلسة المستخدم (`$_SESSION['user']['id']`)
- عبارة مُحضّرة (prepared statement) لمنع SQL Injection

---

## 5. خريطة الصفحات حسب الوحدة والدور

### 5.1 مصفوفة الصلاحيات

| الوحدة | المسار | group_admin | school_admin | teacher | parent |
|--------|--------|:-----------:|:------------:|:-------:|:------:|
| الرئيسية | `index.php` | ✅ | ✅ | ✅ | ✅ |
| إدارة المستخدمين | `pages/users/` | ✅ | ❌ | ❌ | ❌ |
| إدارة المدارس | `pages/schools/` | ✅ | ❌ | ❌ | ❌ |
| السنوات الأكاديمية | `pages/academic/` | ✅ | ✅ | ❌ | ❌ |
| الإعدادات الهيكلية | `pages/setup/` | ✅ | ✅ | ❌ | ❌ |
| التقييمات | `pages/assessment/` | ✅ | ✅ | ✅ | ❌ |
| التقييم الأكاديمي | `pages/academic_assessment/` | ✅ | ✅ | ✅ | ❌ |
| الحضور والانضباط | `pages/attendance/` | ✅ | ✅ | ✅ | ❌ |
| السلوك | `pages/behavior/` | ✅ | ✅ | ✅ | ❌ |
| المهارات | `pages/skills/` | ✅ | ✅ | ✅ | ❌ |
| التدريس | `pages/teaching/` | ✅ | ✅ | ✅ | ❌ |
| التقييمات الإشرافية | `pages/supervision/` | ✅ | ✅ | ❌ | ❌ |
| البيئة المدرسية | `pages/climate/` | ✅ | ✅ | ❌ | ❌ |
| المرافق | `pages/facility/` | ✅ | ✅ | ❌ | ❌ |
| التنبيهات | `pages/alerts/` | ✅ | ✅ | ✅ | ❌ |
| مؤشر الجودة | `pages/eqi/` | ✅ | ✅ | ✅ | ❌ |
| قرارات الترفيع | `pages/promotion/` | ✅ | ✅ | ✅ | ❌ |
| كشوف الدرجات | `pages/grade_sheets/` | ✅ | ✅ | ❌ | ❌ |
| التصدير | `pages/export/` | ✅ | ✅ | ❌ | ❌ |
| النسخ الاحتياطي | `pages/backup_reports/` | ✅ | ✅ | ❌ | ❌ |
| الإشعارات | `pages/notifications/` | ✅ | ✅ | ✅ | ✅ |
| الملاحظات | `pages/notes/` | ✅ | ✅ | ✅ | ❌ |
| إقرار أولياء الأمور | `pages/parent_ack/` | ✅ | ✅ | ✅ | ✅ |
| الإعدادات | `pages/settings/` | ✅ | ✅ | ❌ | ❌ |
| استعادة النسخ | `pages/system/` | ✅ | ✅ | ❌ | ❌ |

### 5.2 ملاحظة على التنفيذ الحالي

التحقق من الصلاحيات يتم حالياً بطريقتين:
1. **القائمة الجانبية** (`includes/sidebar.php`): تخفي العناصر حسب الدور باستخدام `if ($isAdmin)` و `$isAdminOrTeacher`
2. **حماية الصفحات**: بعض الصفحات تستخدم `requireRole()` من `includes/session.php`، لكن 9 صفحات لا تحتوي على هذا الفحص (انظر README.md — المشكلة #2)

---

## 6. ملاحظات التوافق والترقية

### 6.1 من v2.0.0 إلى v2.0.1

لا يتطلب أي تغيير في قاعدة البيانات. جميع الإصلاحات في كود PHP فقط.

### 6.2 من v2.0.1 إلى v2.0.2

إضافة ملف `includes/security_headers.php` فقط. لا تغيير في قاعدة البيانات.

### 6.3 ترقية v2.0.3 (مخططة)

ملف `scripts/migrate_v2.0.3.sql` يضيف 7 جداول جديدة ويُعيد تسمية عدة جداول. راجع القسم "انحراف المخطط" في README.md لتفاصيل أهداف إعادة التسمية غير المنفذة.

### 6.4 تحذير: عدم تطابق setup.php

معالج التثبيت `setup.php` ينشئ 8 جداول مبسطة فقط، بينما المخطط الرسمي يحتوي على 71 جدول + 1 عرض. يُنصح باستخدام `database/schema.sql` للتثبيت الكامل ومعالج التثبيت للتقييم السريع فقط.

---

*آخر تحديث: 2026-05-11 | الإصدار: 2.0.1-doc*
