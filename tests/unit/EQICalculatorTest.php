<?php
/**
 * ============================================================
 * EQAS - اختبارات وحدة محرك حساب EQI الهجين
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 *
 * يختبر:
 * - إعادة توزيع الأوزان الهجين
 * - تصنيف المؤشر
 * - التوصيات
 * - التحقق من مجموع الأوزان
 *
 * @version 2.0.1
 * @since 2026-05-11
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class EQICalculatorTest extends TestCase
{
    private EQICalculator $calculator;
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->calculator = new EQICalculator($this->db);
    }

    // ========================================================================
    // TC-009: اختبار إعادة توزيع الأوزان الهجين
    // ========================================================================

    /**
     * TC-009.1: بدون أبعاد مفقودة = أوزان أصلية
     */
    public function testNoMissingDimensionsUsesOriginalWeights(): void
    {
        $available = ['academic', 'teaching', 'climate', 'behavior', 'skills', 'attendance'];
        $missing = [];

        // نستخدم Reflection للوصول للدالة الخاصة
        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('redistributeWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($this->calculator, $available, $missing);

        $this->assertEqualsWithDelta(0.35, $weights['academic'], 0.001);
        $this->assertEqualsWithDelta(0.25, $weights['teaching'], 0.001);
        $this->assertEqualsWithDelta(0.15, $weights['climate'], 0.001);
        $this->assertEqualsWithDelta(0.10, $weights['behavior'], 0.001);
        $this->assertEqualsWithDelta(0.10, $weights['skills'], 0.001);
        $this->assertEqualsWithDelta(0.05, $weights['attendance'], 0.001);
    }

    /**
     * TC-009.2: بعد واحد مفقود - إعادة توزيع صحيحة
     * مثال: attendance (5%) مفقود
     * - الوزن المفقود = 0.05
     * - 50% نسبي: حسب وزن كل بعد
     * - 50% متساوٍ: بالتساوي بين الأبعاد المتاحة
     */
    public function testOneMissingDimension(): void
    {
        $available = ['academic', 'teaching', 'climate', 'behavior', 'skills'];
        $missing = ['attendance'];

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('redistributeWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($this->calculator, $available, $missing);

        // مجموع الأوزان الجديدة يجب أن يساوي 1.0
        $total = array_sum($weights);
        $this->assertEqualsWithDelta(1.0, $total, 0.001, 'مجموع الأوزان بعد إعادة التوزيع يجب أن يساوي 1.0');

        // كل وزن يجب أن يكون أكبر من الوزن الأصلي
        foreach ($available as $dim) {
            $originalWeight = $this->getOriginalWeight($dim);
            $this->assertGreaterThan($originalWeight, $weights[$dim],
                "وزن {$dim} بعد إعادة التوزيع يجب أن يكون أكبر من الوزن الأصلي");
        }
    }

    /**
     * TC-009.3: ثلاثة أبعاد مفقودة
     */
    public function testThreeMissingDimensions(): void
    {
        $available = ['academic', 'teaching', 'climate'];
        $missing = ['behavior', 'skills', 'attendance'];

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('redistributeWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($this->calculator, $available, $missing);

        $total = array_sum($weights);
        $this->assertEqualsWithDelta(1.0, $total, 0.001);

        // academic (35%) يجب أن يستحوذ على أكبر حصة إضافية
        $academicIncrease = $weights['academic'] - 0.35;
        $teachingIncrease = $weights['teaching'] - 0.25;
        $this->assertGreaterThan($teachingIncrease, $academicIncrease,
            'academic يجب أن يستحوذ على حصة أكبر من teaching (لأن وزنه الأصلي أكبر)');
    }

    /**
     * TC-009.4: فقط بعد واحد متاح
     */
    public function testOnlyOneDimensionAvailable(): void
    {
        $available = ['academic'];
        $missing = ['teaching', 'climate', 'behavior', 'skills', 'attendance'];

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('redistributeWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($this->calculator, $available, $missing);

        // academic يجب أن يحصل على وزن 1.0 (كل الأوزان)
        $this->assertEqualsWithDelta(1.0, $weights['academic'], 0.001);
    }

    /**
     * TC-009.5: حساب يدوي للطريقة الهجينة
     * متاح: academic (0.35), teaching (0.25)
     * مفقود: climate (0.15), behavior (0.10), skills (0.10), attendance (0.05)
     * وزن مفقود = 0.40
     * وزن متاح = 0.60
     *
     * لـ academic:
     *   نسبي: (0.35/0.60) * (0.40 * 0.5) = 0.5833 * 0.20 = 0.1167
     *   متساوٍ: (0.40 * 0.5) / 2 = 0.10
     *   جديد: 0.35 + 0.1167 + 0.10 = 0.5667
     *
     * لـ teaching:
     *   نسبي: (0.25/0.60) * 0.20 = 0.4167 * 0.20 = 0.0833
     *   متساوٍ: 0.10
     *   جديد: 0.25 + 0.0833 + 0.10 = 0.4333
     */
    public function testHybridRedistributionManualCalculation(): void
    {
        $available = ['academic', 'teaching'];
        $missing = ['climate', 'behavior', 'skills', 'attendance'];

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('redistributeWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($this->calculator, $available, $missing);

        $this->assertEqualsWithDelta(1.0, array_sum($weights), 0.001);
        $this->assertEqualsWithDelta(0.5667, $weights['academic'], 0.01);
        $this->assertEqualsWithDelta(0.4333, $weights['teaching'], 0.01);
    }

    // ========================================================================
    // TC-010: اختبار تصنيف مؤشر الجودة
    // ========================================================================

    /**
     * TC-010.1: تصنيف ممتاز (85+)
     */
    public function testEQIRatingExcellent(): void
    {
        $rating = $this->calculator->getEQIRating(90);
        $this->assertEquals('ممتاز', $rating['text']);
        $this->assertEquals('excellent', $rating['class']);
    }

    /**
     * TC-010.2: تصنيف جيد جداً (70-84)
     */
    public function testEQIRatingVeryGood(): void
    {
        $rating = $this->calculator->getEQIRating(75);
        $this->assertEquals('جيد جداً', $rating['text']);
    }

    /**
     * TC-010.3: تصنيف مقبول (50-69)
     */
    public function testEQIRatingFair(): void
    {
        $rating = $this->calculator->getEQIRating(55);
        $this->assertEquals('مقبول', $rating['text']);
    }

    /**
     * TC-010.4: تصنيف ضعيف (0-49)
     */
    public function testEQIRatingPoor(): void
    {
        $rating = $this->calculator->getEQIRating(30);
        $this->assertEquals('ضعيف', $rating['text']);
    }

    /**
     * TC-010.5: الحدود الدقيقة للتصنيفات
     */
    public function testEQIRatingBoundaries(): void
    {
        $this->assertEquals('ممتاز', $this->calculator->getEQIRating(85)['text']);
        $this->assertEquals('جيد جداً', $this->calculator->getEQIRating(84.99)['text']);
        $this->assertEquals('جيد جداً', $this->calculator->getEQIRating(70)['text']);
        $this->assertEquals('مقبول', $this->calculator->getEQIRating(69.99)['text']);
        $this->assertEquals('مقبول', $this->calculator->getEQIRating(50)['text']);
        $this->assertEquals('ضعيف', $this->calculator->getEQIRating(49.99)['text']);
    }

    // ========================================================================
    // TC-011: اختبار حساب EQI مع بيانات كاملة
    // ========================================================================

    /**
     * TC-011.1: حساب EQI بدون بيانات
     */
    public function testCalculateWithNoData(): void
    {
        $result = $this->calculator->calculate(1, 1, 1);
        $this->assertNull($result['eqi']);
        $this->assertEmpty($result['available_dimensions']);
        $this->assertCount(6, $result['missing_dimensions']);
    }

    /**
     * TC-011.2: حساب EQI مع بيانات مدرسة واحدة
     */
    public function testCalculateWithData(): void
    {
        // إعداد البيانات
        $this->db->exec("INSERT INTO schools (id, name_ar, code, school_type) VALUES (1, 'مدرسة اختبار', 'T001', 'primary')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active) VALUES (1, '2025-2026', 1)");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'الفصل الأول', 'none', 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES (1, 1, 'طالب اختبار', 1)");

        // إدراج درجات
        $this->db->exec("CREATE TABLE IF NOT EXISTS grades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER, school_id INTEGER DEFAULT 1,
            academic_year_id INTEGER, semester_id INTEGER,
            grade_value REAL, max_value REAL DEFAULT 100,
            is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO grades (student_id, school_id, academic_year_id, semester_id, grade_value, max_value, is_active)
            VALUES (1, 1, 1, 1, 80, 100, 1)");

        // إدراج حضور
        $this->db->exec("CREATE TABLE IF NOT EXISTS attendance_summaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER, school_id INTEGER DEFAULT 1,
            academic_year_id INTEGER, semester_id INTEGER,
            attendance_rate REAL, is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO attendance_summaries (student_id, school_id, academic_year_id, semester_id, attendance_rate, is_active)
            VALUES (1, 1, 1, 1, 90, 1)");

        $result = $this->calculator->calculate(1, 1, 1);

        // يجب أن يكون EQI محسوباً
        $this->assertNotNull($result['eqi']);
        $this->assertGreaterThan(0, $result['eqi']);
    }

    // ========================================================================
    // TC-012: اختبار تحديث الأوزان
    // ========================================================================

    /**
     * TC-012.1: تحديث أوزان بمجموع 100% - نجاح
     */
    public function testUpdateWeightsValid(): void
    {
        $result = $this->calculator->updateWeights([
            'academic' => 35,
            'teaching' => 25,
            'climate' => 15,
            'behavior' => 10,
            'skills' => 10,
            'attendance' => 5,
        ]);

        $this->assertTrue($result['success']);
    }

    /**
     * TC-012.2: تحديث أوزان بمجموع غير 100% - فشل
     */
    public function testUpdateWeightsInvalidTotal(): void
    {
        $result = $this->calculator->updateWeights([
            'academic' => 50,
            'teaching' => 30,
            'climate' => 20,
            'behavior' => 10,
            'skills' => 10,
            'attendance' => 5,
        ]);

        $this->assertFalse($result['success']);
    }

    // ========================================================================
    // دوال مساعدة
    // ========================================================================

    private function getOriginalWeight(string $dim): float
    {
        $dimensions = $this->calculator->getDimensions();
        return $dimensions[$dim]['weight'];
    }
}
