<?php
/**
 * ============================================================
 * EQAS - اختبارات وحدة الدوال المساعدة
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 *
 * يختبر:
 * - الأمان (XSS, CSRF, sanitize)
 * - التحقق من البيانات (inRange, clamp)
 * - القفل (isSemesterLocked, canEditSemester)
 * - الأدوار (hasPermission)
 * - التواريخ (formatDateAr)
 * - كلمات المرور (passwordStrength, generatePassword)
 * - المتوسط المرجح (weightedAverage)
 * - ترجمة الحالات (getSemesterStatusLabel)
 *
 * @version 2.0.1
 * @since 2026-05-11
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class HelpersTest extends TestCase
{
    // ========================================================================
    // TC-013: اختبارات الأمان - XSS
    // ========================================================================

    /**
     * TC-013.1: دالة e() تنظم نصوص HTML
     */
    public function testEscapeHtml(): void
    {
        $this->assertEquals('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
        $this->assertEquals('&amp;', e('&'));
        $this->assertEquals('&quot;test&quot;', e('"test"'));
    }

    /**
     * TC-013.2: دالة e() تتعامل مع null
     */
    public function testEscapeNull(): void
    {
        $this->assertEquals('', e(null));
    }

    /**
     * TC-013.3: دالة sanitize() تنظف المدخلات
     */
    public function testSanitize(): void
    {
        $this->assertEquals('text', sanitize('<b>text</b>'));
        $this->assertEquals('1', sanitize('  1  '));
    }

    /**
     * TC-013.4: دالة sanitize() تتعامل مع المصفوفات
     */
    public function testSanitizeArray(): void
    {
        $input = ['<b>a</b>', ' <c> '];
        $result = sanitize($input);
        $this->assertEquals('a', $result[0]);
        $this->assertEquals('', $result[1]); // strip_tags يزيل <c>
    }

    // ========================================================================
    // TC-014: اختبارات CSRF
    // ========================================================================

    /**
     * TC-014.1: csrfField() ينتج حقل HTML صحيح
     */
    public function testCsrfFieldOutput(): void
    {
        $_SESSION = [];
        $html = csrfField();

        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="', $html);
    }

    /**
     * TC-014.2: csrfField() ينشئ token بطول 64 حرف
     */
    public function testCsrfTokenLength(): void
    {
        $_SESSION = [];
        csrfField();

        $this->assertEquals(64, strlen($_SESSION['csrf_token']));
        $this->assertTrue(ctype_xdigit($_SESSION['csrf_token']));
    }

    /**
     * TC-014.3: verifyCsrf() يتحقق من التوافق
     */
    public function testVerifyCsrfValid(): void
    {
        $_SESSION = ['csrf_token' => 'abc123'];
        $_POST = ['csrf_token' => 'abc123'];
        $_SERVER = [];

        $this->assertTrue(verifyCsrf());
    }

    /**
     * TC-014.4: verifyCsrf() يرفض التوكن غير المطابق
     */
    public function testVerifyCsrfInvalid(): void
    {
        $_SESSION = ['csrf_token' => 'abc123'];
        $_POST = ['csrf_token' => 'wrong'];
        $_SERVER = [];

        $this->assertFalse(verifyCsrf());
    }

    /**
     * TC-014.5: verifyCsrf() يرفض التوكن الفارغ
     */
    public function testVerifyCsrfEmpty(): void
    {
        $_SESSION = ['csrf_token' => 'abc123'];
        $_POST = [];
        $_SERVER = [];

        $this->assertFalse(verifyCsrf());
    }

    /**
     * TC-014.6: verifyCsrf() يقبل HTTP_X_CSRF_TOKEN
     */
    public function testVerifyCsrfHeader(): void
    {
        $_SESSION = ['csrf_token' => 'abc123'];
        $_POST = [];
        $_SERVER = ['HTTP_X_CSRF_TOKEN' => 'abc123'];

        $this->assertTrue(verifyCsrf());
    }

    // ========================================================================
    // TC-015: اختبارات التحقق من البيانات
    // ========================================================================

    /**
     * TC-015.1: inRange - قيم ضمن النطاق
     */
    public function testInRange(): void
    {
        $this->assertTrue(inRange(5, 1, 10));
        $this->assertTrue(inRange(1, 1, 10));
        $this->assertTrue(inRange(10, 1, 10));
    }

    /**
     * TC-015.2: inRange - قيم خارج النطاق
     */
    public function testOutOfRange(): void
    {
        $this->assertFalse(inRange(0, 1, 10));
        $this->assertFalse(inRange(11, 1, 10));
        $this->assertFalse(inRange('abc', 1, 10));
    }

    /**
     * TC-015.3: clamp - تقييد القيم
     */
    public function testClamp(): void
    {
        $this->assertEquals(5, clamp(5, 1, 10));
        $this->assertEquals(1, clamp(-5, 1, 10));
        $this->assertEquals(10, clamp(15, 1, 10));
        $this->assertEquals(1, clamp('abc', 1, 10)); // default = min
    }

    // ========================================================================
    // TC-016: اختبارات القفل - isSemesterLocked و canEditSemester
    // ========================================================================

    /**
     * TC-016.1: isSemesterLocked - حالة none = غير مقفل
     */
    public function testIsSemesterLockedNone(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل مفتوح', 'none', 1)");

        $this->assertFalse(isSemesterLocked($db, 1));
    }

    /**
     * TC-016.2: isSemesterLocked - حالة soft = مقفل
     */
    public function testIsSemesterLockedSoft(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل ناعم', 'soft', 1)");

        $this->assertTrue(isSemesterLocked($db, 1));
    }

    /**
     * TC-016.3: isSemesterLocked - حالة hard = مقفل
     */
    public function testIsSemesterLockedHard(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل صلب', 'hard', 1)");

        $this->assertTrue(isSemesterLocked($db, 1));
    }

    /**
     * TC-016.4: isSemesterLocked - حالة admin_handled = مقفل
     */
    public function testIsSemesterLockedAdminHandled(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'معالجة إدارية', 'admin_handled', 1)");

        $this->assertTrue(isSemesterLocked($db, 1));
    }

    /**
     * TC-016.5: canEditSemester - none = الجميع يمكنهم التعديل
     */
    public function testCanEditSemesterNone(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل', 'none', 1)");

        $this->assertTrue(canEditSemester($db, 1, 'teacher'));
        $this->assertTrue(canEditSemester($db, 1, 'school_admin'));
        $this->assertTrue(canEditSemester($db, 1, 'group_admin'));
    }

    /**
     * TC-016.6: canEditSemester - soft = فقط الإداريون
     */
    public function testCanEditSemesterSoft(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل', 'soft', 1)");

        $this->assertFalse(canEditSemester($db, 1, 'teacher'));
        $this->assertFalse(canEditSemester($db, 1, 'parent'));
        $this->assertTrue(canEditSemester($db, 1, 'school_admin'));
        $this->assertTrue(canEditSemester($db, 1, 'group_admin'));
    }

    /**
     * TC-016.7: canEditSemester - hard = فقط مدير النظام
     */
    public function testCanEditSemesterHard(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل', 'hard', 1)");

        $this->assertFalse(canEditSemester($db, 1, 'teacher'));
        $this->assertFalse(canEditSemester($db, 1, 'school_admin'));
        $this->assertTrue(canEditSemester($db, 1, 'group_admin'));
    }

    /**
     * TC-016.8: canEditSemester - admin_handled = لا أحد
     */
    public function testCanEditSemesterAdminHandled(): void
    {
        $db = createTestDB();
        $db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'فصل', 'admin_handled', 1)");

        $this->assertFalse(canEditSemester($db, 1, 'teacher'));
        $this->assertFalse(canEditSemester($db, 1, 'school_admin'));
        $this->assertFalse(canEditSemester($db, 1, 'group_admin'));
    }

    // ========================================================================
    // TC-017: اختبارات ترجمة حالات القفل
    // ========================================================================

    /**
     * TC-017.1: getSemesterStatusLabel - القيم الصحيحة
     */
    public function testGetSemesterStatusLabel(): void
    {
        $this->assertEquals('مفتوح', getSemesterStatusLabel('none'));
        $this->assertEquals('قفل ناعم', getSemesterStatusLabel('soft'));
        $this->assertEquals('قفل صلب', getSemesterStatusLabel('hard'));
        $this->assertEquals('معالجة إدارية', getSemesterStatusLabel('admin_handled'));
    }

    /**
     * TC-017.2: getSemesterStatusLabel - القيم القديمة لم تعد مستخدمة
     */
    public function testGetSemesterStatusLabelOldValues(): void
    {
        // القيم القديمة لا يجب أن تعطي ترجمة صحيحة
        $this->assertNotEquals('مفتوح', getSemesterStatusLabel('open'));
        $this->assertNotEquals('قفل ناعم', getSemesterStatusLabel('soft_locked'));
        $this->assertNotEquals('قفل صلب', getSemesterStatusLabel('hard_locked'));
    }

    // ========================================================================
    // TC-018: اختبارات الأدوار
    // ========================================================================

    /**
     * TC-018.1: hasPermission - التسلسل الهرمي
     */
    public function testHasPermissionHierarchy(): void
    {
        $_SESSION['user']['role'] = 'group_admin';
        $this->assertTrue(hasPermission('group_admin'));
        $this->assertTrue(hasPermission('school_admin'));
        $this->assertTrue(hasPermission('teacher'));
        $this->assertTrue(hasPermission('parent'));

        $_SESSION['user']['role'] = 'school_admin';
        $this->assertFalse(hasPermission('group_admin'));
        $this->assertTrue(hasPermission('school_admin'));
        $this->assertTrue(hasPermission('teacher'));

        $_SESSION['user']['role'] = 'teacher';
        $this->assertFalse(hasPermission('school_admin'));
        $this->assertTrue(hasPermission('teacher'));
        $this->assertFalse(hasPermission('group_admin'));

        $_SESSION['user']['role'] = 'parent';
        $this->assertFalse(hasPermission('teacher'));
        $this->assertTrue(hasPermission('parent'));
    }

    // ========================================================================
    // TC-019: اختبارات كلمات المرور
    // ========================================================================

    /**
     * TC-019.1: passwordStrength - كلمة ضعيفة
     */
    public function testPasswordStrengthWeak(): void
    {
        $result = passwordStrength('123');
        $this->assertEquals(1, $result['score']);
    }

    /**
     * TC-019.2: passwordStrength - كلمة قوية
     */
    public function testPasswordStrengthStrong(): void
    {
        $result = passwordStrength('MyP@ssw0rd2026!');
        $this->assertGreaterThanOrEqual(5, $result['score']);
    }

    /**
     * TC-019.3: generatePassword - طول صحيح
     */
    public function testGeneratePasswordLength(): void
    {
        $password = generatePassword(12);
        $this->assertEquals(12, strlen($password));
    }

    /**
     * TC-019.4: generatePassword - تحتوي على أنواع مختلفة
     */
    public function testGeneratePasswordDiversity(): void
    {
        $password = generatePassword(16);
        $this->assertMatchesRegularExpression('/[a-z]/', $password, 'يجب أن تحتوي على أحرف صغيرة');
        $this->assertMatchesRegularExpression('/[A-Z]/', $password, 'يجب أن تحتوي على أحرف كبيرة');
        $this->assertMatchesRegularExpression('/[0-9]/', $password, 'يجب أن تحتوي على أرقام');
        $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password, 'يجب أن تحتوي على رموز خاصة');
    }

    // ========================================================================
    // TC-020: اختبارات المتوسط المرجح
    // ========================================================================

    /**
     * TC-020.1: weightedAverage - حساب صحيح
     */
    public function testWeightedAverage(): void
    {
        $values = [
            ['value' => 80, 'weight' => 0.35],
            ['value' => 70, 'weight' => 0.25],
        ];
        $result = weightedAverage($values);
        // (80*0.35 + 70*0.25) / (0.35+0.25) = (28+17.5)/0.6 = 75.83
        $this->assertEqualsWithDelta(75.83, $result, 0.01);
    }

    /**
     * TC-020.2: weightedAverage - مصفوفة فارغة
     */
    public function testWeightedAverageEmpty(): void
    {
        $this->assertNull(weightedAverage([]));
    }

    // ========================================================================
    // TC-021: اختبارات تنسيق الملفات
    // ========================================================================

    /**
     * TC-021.1: formatFileSize - أحجام مختلفة
     */
    public function testFormatFileSize(): void
    {
        $this->assertEquals('0 B', formatFileSize(0));
        $this->assertEquals('1 KB', formatFileSize(1024));
        $this->assertEquals('1 MB', formatFileSize(1048576));
    }

    // ========================================================================
    // TC-022: اختبارات التواريخ
    // ========================================================================

    /**
     * TC-022.1: formatDateAr - تنسيق قصير
     */
    public function testFormatDateArShort(): void
    {
        $result = formatDateAr('2026-05-11', 'short');
        $this->assertEquals('2026/05/11', $result);
    }

    /**
     * TC-022.2: formatDateAr - تاريخ فارغ
     */
    public function testFormatDateArEmpty(): void
    {
        $this->assertEquals('', formatDateAr(''));
        $this->assertEquals('', formatDateAr(null));
    }

    /**
     * TC-022.3: isValidDate
     */
    public function testIsValidDate(): void
    {
        $this->assertTrue(isValidDate('2026-05-11'));
        $this->assertFalse(isValidDate('2026-13-01'));
        $this->assertFalse(isValidDate(''));
        $this->assertFalse(isValidDate('not-a-date'));
    }

    // ========================================================================
    // TC-023: اختبارات EQI Rating (helpers)
    // ========================================================================

    /**
     * TC-023.1: getEqiRating - تصنيفات
     */
    public function testGetEqiRating(): void
    {
        $this->assertEquals('ممتاز', getEqiRating(90)[0]);
        $this->assertEquals('جيد جداً', getEqiRating(75)[0]);
        $this->assertEquals('مقبول', getEqiRating(55)[0]);
        $this->assertEquals('ضعيف', getEqiRating(30)[0]);
    }

    // ========================================================================
    // TC-024: اختبارات الترجمات
    // ========================================================================

    /**
     * TC-024.1: getRoleLabel - ترجمة الأدوار
     */
    public function testGetRoleLabel(): void
    {
        $this->assertEquals('مدير النظام', getRoleLabel('group_admin'));
        $this->assertEquals('مدير المدرسة', getRoleLabel('school_admin'));
        $this->assertEquals('معلم', getRoleLabel('teacher'));
        $this->assertEquals('ولي أمر', getRoleLabel('parent'));
    }

    /**
     * TC-024.2: getToolTypeLabel - ترجمة أنواع الأدوات
     */
    public function testGetToolTypeLabel(): void
    {
        $this->assertEquals('مقياس ليكرت (5 مستويات)', getToolTypeLabel('likert_5'));
        $this->assertEquals('مقياس الشدة (عكسي)', getToolTypeLabel('severity_4'));
        $this->assertEquals('نص حر', getToolTypeLabel('text'));
    }
}
