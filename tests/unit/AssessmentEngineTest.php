<?php
/**
 * ============================================================
 * EQAS - اختبارات وحدة محرك التقييم
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 *
 * يختبر جميع دوال محرك التقييم:
 * - التطبيع (normalizeTool)
 * - التحقق (validateTool)
 * - حساب الدرجات (calculateScore)
 * - تصنيف الدرجات (getScoreRating)
 *
 * @version 2.0.1
 * @since 2026-05-11
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class AssessmentEngineTest extends TestCase
{
    private AssessmentEngine $engine;
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->engine = new AssessmentEngine($this->db);
    }

    // ========================================================================
    // TC-001: اختبار صيغة severity_4 (الإصلاح الأهم)
    // ========================================================================

    /**
     * TC-001.1: severity_4 مع value=1 يجب أن تعطي 0%
     */
    public function testSeverity4Value1GivesZero(): void
    {
        $result = $this->engine->normalizeTool('severity_4', 1);
        $this->assertEquals(0, $result, 'severity_4: value=1 يجب أن يعطي 0% (شديد = أسوأ)');
    }

    /**
     * TC-001.2: severity_4 مع value=2 يجب أن تعطي ~33.33%
     */
    public function testSeverity4Value2Gives33(): void
    {
        $result = $this->engine->normalizeTool('severity_4', 2);
        $this->assertEqualsWithDelta(33.33, $result, 0.01, 'severity_4: value=2 يجب أن يعطي ~33.33%');
    }

    /**
     * TC-001.3: severity_4 مع value=3 يجب أن تعطي ~66.67%
     */
    public function testSeverity4Value3Gives67(): void
    {
        $result = $this->engine->normalizeTool('severity_4', 3);
        $this->assertEqualsWithDelta(66.67, $result, 0.01, 'severity_4: value=3 يجب أن يعطي ~66.67%');
    }

    /**
     * TC-001.4: severity_4 مع value=4 يجب أن تعطي 100%
     */
    public function testSeverity4Value4Gives100(): void
    {
        $result = $this->engine->normalizeTool('severity_4', 4);
        $this->assertEquals(100, $result, 'severity_4: value=4 يجب أن يعطي 100% (لا يوجد = أفضل)');
    }

    /**
     * TC-001.5: التحقق من أن الصيغة القديمة المعكوسة لم تعد مستخدمة
     * الصيغة القديمة: ((4-value)/3)*100 كانت تعطي نتائج معكوسة
     */
    public function testSeverity4OldFormulaIsNotUsed(): void
    {
        // الصيغة القديمة كانت: ((4-value)/3)*100
        // value=1 كانت تعطي 100% بدلاً من 0% - هذا خطأ!
        $result = $this->engine->normalizeTool('severity_4', 1);
        $this->assertNotEquals(100, $result, 'الصيغة القديمة المعكوسة لم تعد مستخدمة');

        $result2 = $this->engine->normalizeTool('severity_4', 4);
        $this->assertNotEquals(0, $result2, 'الصيغة القديمة المعكوسة لم تعد مستخدمة');
    }

    /**
     * TC-001.6: severity_4 مع قيمة خارج النطاق
     */
    public function testSeverity4OutOfRange(): void
    {
        $result = $this->engine->normalizeTool('severity_4', 5);
        // يجب أن تكون ضمن 0-100 بفضل max/min
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(100, $result);
    }

    // ========================================================================
    // TC-002: اختبار التطبيع لجميع أدوات التقييم
    // ========================================================================

    /**
     * TC-002.1: likert_5 - value=1 يعطي 20%
     */
    public function testLikert5Value1(): void
    {
        $result = $this->engine->normalizeTool('likert_5', 1);
        $this->assertEquals(20, $result);
    }

    /**
     * TC-002.2: likert_5 - value=5 يعطي 100%
     */
    public function testLikert5Value5(): void
    {
        $result = $this->engine->normalizeTool('likert_5', 5);
        $this->assertEquals(100, $result);
    }

    /**
     * TC-002.3: likert_5 - value=3 يعطي 60%
     */
    public function testLikert5Value3(): void
    {
        $result = $this->engine->normalizeTool('likert_5', 3);
        $this->assertEquals(60, $result);
    }

    /**
     * TC-002.4: frequency_5 - value=1 يعطي 20%, value=5 يعطي 100%
     */
    public function testFrequency5Range(): void
    {
        $this->assertEquals(20, $this->engine->normalizeTool('frequency_5', 1));
        $this->assertEquals(100, $this->engine->normalizeTool('frequency_5', 5));
    }

    /**
     * TC-002.5: satisfaction_5 - value=1 يعطي 20%, value=5 يعطي 100%
     */
    public function testSatisfaction5Range(): void
    {
        $this->assertEquals(20, $this->engine->normalizeTool('satisfaction_5', 1));
        $this->assertEquals(100, $this->engine->normalizeTool('satisfaction_5', 5));
    }

    /**
     * TC-002.6: yes_no - value=0 يعطي 0%, value=1 يعطي 100%
     */
    public function testYesNoRange(): void
    {
        $this->assertEquals(0, $this->engine->normalizeTool('yes_no', 0));
        $this->assertEquals(100, $this->engine->normalizeTool('yes_no', 1));
    }

    /**
     * TC-002.7: counter - مع maxValue
     */
    public function testCounterWithMax(): void
    {
        $this->assertEquals(50, $this->engine->normalizeTool('counter', 5, 10));
        $this->assertEquals(100, $this->engine->normalizeTool('counter', 10, 10));
        $this->assertEquals(100, $this->engine->normalizeTool('counter', 15, 10)); // يتجاوز الحد
    }

    /**
     * TC-002.8: counter - بدون maxValue
     */
    public function testCounterWithoutMax(): void
    {
        $result = $this->engine->normalizeTool('counter', 7, null);
        $this->assertEquals(7, $result);
    }

    /**
     * TC-002.9: numeric_100 - القيمة كما هي
     */
    public function testNumeric100(): void
    {
        $this->assertEquals(0, $this->engine->normalizeTool('numeric_100', 0));
        $this->assertEquals(50, $this->engine->normalizeTool('numeric_100', 50));
        $this->assertEquals(100, $this->engine->normalizeTool('numeric_100', 100));
    }

    /**
     * TC-002.10: numeric_custom - مع maxValue
     */
    public function testNumericCustom(): void
    {
        $this->assertEquals(50, $this->engine->normalizeTool('numeric_custom', 25, 50));
        $this->assertEquals(100, $this->engine->normalizeTool('numeric_custom', 50, 50));
    }

    /**
     * TC-002.11: rubric_4 - value=1 يعطي 25%, value=4 يعطي 100%
     */
    public function testRubric4Range(): void
    {
        $this->assertEquals(25, $this->engine->normalizeTool('rubric_4', 1));
        $this->assertEquals(50, $this->engine->normalizeTool('rubric_4', 2));
        $this->assertEquals(75, $this->engine->normalizeTool('rubric_4', 3));
        $this->assertEquals(100, $this->engine->normalizeTool('rubric_4', 4));
    }

    /**
     * TC-002.12: text - لا درجة متوقعة
     */
    public function testTextReturnsNull(): void
    {
        $result = $this->engine->normalizeTool('text', 'أي نص');
        $this->assertNull($result, 'نوع text يجب أن يعيد null');
    }

    /**
     * TC-002.13: قيمة فارغة تعطي 0
     */
    public function testEmptyValueGivesZero(): void
    {
        $this->assertEquals(0, $this->engine->normalizeTool('likert_5', null));
        $this->assertEquals(0, $this->engine->normalizeTool('likert_5', ''));
    }

    /**
     * TC-002.14: القيم السلبية لا تتجاوز 0
     */
    public function testNegativeValueClamped(): void
    {
        $result = $this->engine->normalizeTool('numeric_100', -10);
        $this->assertEquals(0, $result); // max(0, min(100, -10)) = 0
    }

    // ========================================================================
    // TC-003: اختبار العناصر المعكوسة (isInverse)
    // ========================================================================

    /**
     * TC-003.1: likert_5 معكوس - value=5 يعطي 0%
     */
    public function testInverseLikert5(): void
    {
        $result = $this->engine->normalizeTool('likert_5', 5, null, true);
        $this->assertEquals(0, $result, 'likert_5 معكوس: value=5 يجب أن يعطي 0%');
    }

    /**
     * TC-003.2: likert_5 معكوس - value=1 يعطي 80%
     */
    public function testInverseLikert5Value1(): void
    {
        $result = $this->engine->normalizeTool('likert_5', 1, null, true);
        $this->assertEquals(80, $result, 'likert_5 معكوس: value=1 => 100-20=80%');
    }

    /**
     * TC-003.3: severity_4 لا يتأثر بـ isInverse (يعكس داخلياً بالفعل)
     */
    public function testSeverity4NotDoubleInverted(): void
    {
        $normal = $this->engine->normalizeTool('severity_4', 4, null, false);
        $inverse = $this->engine->normalizeTool('severity_4', 4, null, true);
        $this->assertEquals($normal, $inverse, 'severity_4 لا يجب أن يعكس مرتين');
    }

    // ========================================================================
    // TC-004: اختبار التحقق من المدخلات (validateTool)
    // ========================================================================

    /**
     * TC-004.1: likert_5 - قيم صالحة (1-5)
     */
    public function testValidateLikert5Valid(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->engine->validateTool('likert_5', $i);
            $this->assertTrue($result['valid'], "likert_5: value={$i} يجب أن يكون صالحاً");
        }
    }

    /**
     * TC-004.2: likert_5 - قيم غير صالحة (0, 6, نص)
     */
    public function testValidateLikert5Invalid(): void
    {
        $this->assertFalse($this->engine->validateTool('likert_5', 0)['valid']);
        $this->assertFalse($this->engine->validateTool('likert_5', 6)['valid']);
        $this->assertFalse($this->engine->validateTool('likert_5', 'abc')['valid']);
    }

    /**
     * TC-004.3: severity_4 - قيم صالحة (1-4)
     */
    public function testValidateSeverity4Valid(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $result = $this->engine->validateTool('severity_4', $i);
            $this->assertTrue($result['valid'], "severity_4: value={$i} يجب أن يكون صالحاً");
        }
    }

    /**
     * TC-004.4: severity_4 - قيمة خارج النطاق
     */
    public function testValidateSeverity4Invalid(): void
    {
        $this->assertFalse($this->engine->validateTool('severity_4', 0)['valid']);
        $this->assertFalse($this->engine->validateTool('severity_4', 5)['valid']);
    }

    /**
     * TC-004.5: yes_no - قيم صالحة
     */
    public function testValidateYesNoValid(): void
    {
        $this->assertTrue($this->engine->validateTool('yes_no', 1)['valid']);
        $this->assertTrue($this->engine->validateTool('yes_no', 0)['valid']);
        $this->assertTrue($this->engine->validateTool('yes_no', '1')['valid']);
        $this->assertTrue($this->engine->validateTool('yes_no', '0')['valid']);
    }

    /**
     * TC-004.6: counter - قيمة سالبة مرفوضة
     */
    public function testValidateCounterNegative(): void
    {
        $this->assertFalse($this->engine->validateTool('counter', -1)['valid']);
    }

    /**
     * TC-004.7: counter - تجاوز maxValue
     */
    public function testValidateCounterExceedsMax(): void
    {
        $result = $this->engine->validateTool('counter', 15, 10);
        $this->assertFalse($result['valid']);
    }

    /**
     * TC-004.8: numeric_100 - نطاق صحيح
     */
    public function testValidateNumeric100Valid(): void
    {
        $this->assertTrue($this->engine->validateTool('numeric_100', 0)['valid']);
        $this->assertTrue($this->engine->validateTool('numeric_100', 100)['valid']);
        $this->assertTrue($this->engine->validateTool('numeric_100', 50.5)['valid']);
    }

    /**
     * TC-004.9: numeric_100 - خارج النطاق
     */
    public function testValidateNumeric100Invalid(): void
    {
        $this->assertFalse($this->engine->validateTool('numeric_100', -1)['valid']);
        $this->assertFalse($this->engine->validateTool('numeric_100', 101)['valid']);
    }

    /**
     * TC-004.10: text - دائماً صالح
     */
    public function testValidateTextAlwaysValid(): void
    {
        $this->assertTrue($this->engine->validateTool('text', '')['valid']);
        $this->assertTrue($this->engine->validateTool('text', 'نص عربي')['valid']);
        $this->assertTrue($this->engine->validateTool('text', null)['valid']);
    }

    /**
     * TC-004.11: قيمة فارغة مرفوضة لغير text
     */
    public function testValidateEmptyRejectedForNonText(): void
    {
        $this->assertFalse($this->engine->validateTool('likert_5', '')['valid']);
        $this->assertFalse($this->engine->validateTool('severity_4', null)['valid']);
        $this->assertFalse($this->engine->validateTool('yes_no', '')['valid']);
    }

    // ========================================================================
    // TC-005: اختبار حساب الدرجات (calculateScore)
    // ========================================================================

    /**
     * TC-005.1: متوسط مرجح بسيط
     */
    public function testCalculateScoreSimple(): void
    {
        $responses = [
            ['weight' => 1, 'normalized' => 80],
            ['weight' => 1, 'normalized' => 60],
        ];
        $this->assertEquals(70, $this->engine->calculateScore($responses));
    }

    /**
     * TC-005.2: متوسط مرجح بأوزان مختلفة
     */
    public function testCalculateScoreWeighted(): void
    {
        $responses = [
            ['weight' => 2, 'normalized' => 80],
            ['weight' => 1, 'normalized' => 50],
        ];
        // (2*80 + 1*50) / (2+1) = 210/3 = 70
        $this->assertEquals(70, $this->engine->calculateScore($responses));
    }

    /**
     * TC-005.3: مصفوفة فارغة تعطي 0
     */
    public function testCalculateScoreEmpty(): void
    {
        $this->assertEquals(0, $this->engine->calculateScore([]));
    }

    /**
     * TC-005.4: تجاهل القيم null (نص حر)
     */
    public function testCalculateScoreSkipsNull(): void
    {
        $responses = [
            ['weight' => 1, 'normalized' => 100],
            ['weight' => 1, 'normalized' => null], // نص حر
            ['weight' => 1, 'normalized' => 50],
        ];
        // (1*100 + 1*50) / (1+1) = 75
        $this->assertEquals(75, $this->engine->calculateScore($responses));
    }

    /**
     * TC-005.5: وزن افتراضي = 1 عند عدم التحديد
     */
    public function testCalculateScoreDefaultWeight(): void
    {
        $responses = [
            ['normalized' => 80],
            ['normalized' => 60],
        ];
        $this->assertEquals(70, $this->engine->calculateScore($responses));
    }

    // ========================================================================
    // TC-006: اختبار تصنيف الدرجات (getScoreRating)
    // ========================================================================

    /**
     * TC-006.1: تصنيف الدرجات حسب النطاقات
     */
    public function testScoreRating(): void
    {
        $this->assertEquals('ممتاز', $this->engine->getScoreRating(95)['label']);
        $this->assertEquals('جيد جداً', $this->engine->getScoreRating(85)['label']);
        $this->assertEquals('جيد', $this->engine->getScoreRating(75)['label']);
        $this->assertEquals('مقبول', $this->engine->getScoreRating(60)['label']);
        $this->assertEquals('ضعيف', $this->engine->getScoreRating(50)['label']);
        $this->assertEquals('ضعيف جداً', $this->engine->getScoreRating(30)['label']);
    }

    // ========================================================================
    // TC-007: اختبار أسماء الجداول والأعمدة بعد الإصلاح
    // ========================================================================

    /**
     * TC-007.1: إنشاء نموذج يستخدم form_key (وليس form_type)
     */
    public function testCreateFormUsesFormKey(): void
    {
        $formId = $this->engine->createForm([
            'name_ar' => 'نموذج اختبار',
            'name_en' => 'Test Form',
            'form_key' => 'behavior_eval',
            'description' => 'وصف'
        ]);

        $this->assertIsNumeric($formId, 'createForm يجب أن يعيد معرف رقمي');

        // التحقق من أن form_key محفوظ
        $stmt = $this->db->prepare("SELECT form_key FROM assessment_forms WHERE id = ?");
        $stmt->execute([$formId]);
        $result = $stmt->fetchColumn();
        $this->assertEquals('behavior_eval', $result, 'form_key يجب أن يكون behavior_eval');
    }

    /**
     * TC-007.2: إنشاء فئة يستخدم جدول assessment_criteria
     */
    public function testCreateCategoryUsesCriteriaTable(): void
    {
        $formId = $this->engine->createForm([
            'name_ar' => 'نموذج اختبار',
            'form_key' => 'skills_checklist',
        ]);

        $categoryId = $this->engine->createCategory([
            'form_id' => $formId,
            'name_ar' => 'فئة اختبار',
            'sort_order' => 1
        ]);

        $this->assertIsNumeric($categoryId);

        // التحقق من حفظ البيانات في جدول assessment_criteria
        $stmt = $this->db->prepare("SELECT name_ar FROM assessment_criteria WHERE id = ?");
        $stmt->execute([$categoryId]);
        $result = $stmt->fetchColumn();
        $this->assertEquals('فئة اختبار', $result);
    }

    /**
     * TC-007.3: إنشاء عنصر يستخدم criteria_id (وليس category_id)
     */
    public function testCreateItemUsesCriteriaId(): void
    {
        $formId = $this->engine->createForm([
            'name_ar' => 'نموذج اختبار',
            'form_key' => 'teaching_self',
        ]);

        $criteriaId = $this->engine->createCategory([
            'form_id' => $formId,
            'name_ar' => 'فئة اختبار',
        ]);

        $itemId = $this->engine->createItem([
            'criteria_id' => $criteriaId,
            'form_id' => $formId,
            'text_ar' => 'عنصر اختبار',
            'tool_type' => 'likert_5',
            'weight' => 1.0,
            'is_inverse' => 0,
        ]);

        $this->assertIsNumeric($itemId);

        // التحقق من أن criteria_id محفوظ
        $stmt = $this->db->prepare("SELECT criteria_id FROM assessment_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $result = $stmt->fetchColumn();
        $this->assertEquals($criteriaId, $result, 'criteria_id يجب أن يكون محفوظاً بشكل صحيح');
    }

    /**
     * TC-007.4: getFormWithItems يستخدم assessment_criteria و criteria_id
     */
    public function testGetFormWithItemsUsesCorrectSchema(): void
    {
        // إنشاء نموذج كامل
        $formId = $this->engine->createForm([
            'name_ar' => 'نموذج كامل',
            'form_key' => 'climate_survey',
        ]);

        $criteriaId = $this->engine->createCategory([
            'form_id' => $formId,
            'name_ar' => 'مناخ الفصل',
        ]);

        $this->engine->createItem([
            'criteria_id' => $criteriaId,
            'form_id' => $formId,
            'text_ar' => 'هل يشعر الطلاب بالأمان؟',
            'tool_type' => 'likert_5',
        ]);

        // جلب النموذج مع عناصره
        $form = $this->engine->getFormWithItems($formId);

        $this->assertIsArray($form);
        $this->assertArrayHasKey('categories', $form);
        $this->assertCount(1, $form['categories']);
        $this->assertCount(1, $form['categories'][0]['items']);
        $this->assertEquals('هل يشعر الطلاب بالأمان؟', $form['categories'][0]['items'][0]['text_ar']);
    }

    // ========================================================================
    // TC-008: اختبار getToolLabel و getFormTypeOptions
    // ========================================================================

    /**
     * TC-008.1: أسماء الأدوات بالعربية
     */
    public function testGetToolLabel(): void
    {
        $this->assertEquals('مقياس ليكرت (5 مستويات)', $this->engine->getToolLabel('likert_5'));
        $this->assertEquals('مقياس الشدة (4 مستويات) - عكسي', $this->engine->getToolLabel('severity_4'));
        $this->assertEquals('نص حر', $this->engine->getToolLabel('text'));
    }

    /**
     * TC-008.2: أنواع النماذج
     */
    public function testGetFormTypeOptions(): void
    {
        $options = $this->engine->getFormTypeOptions();
        $this->assertArrayHasKey('behavior_eval', $options);
        $this->assertArrayHasKey('climate_survey', $options);
        $this->assertCount(7, $options);
    }
}
