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

    // ========================================================================
    // TC-013: اختبار حسابات الأبعاد الفردية
    // ========================================================================

    /**
     * TC-013.1: calculateClimateScore مع بيانات موجودة
     */
    public function testCalculateClimateScoreWithData(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO climate_surveys (school_id, academic_year_id, semester_id, overall_score, is_active)
            VALUES (1, 1, 1, 75.0, 1), (1, 1, 1, 85.0, 1)");

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateClimateScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1);
        $this->assertEqualsWithDelta(80.0, $result, 0.01, 'متوسط 75+85 = 80');
    }

    /**
     * TC-013.2: calculateClimateScore بدون بيانات → null
     */
    public function testCalculateClimateScoreEmpty(): void
    {
        $this->initBasicData();
        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateClimateScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1);
        $this->assertNull($result);
    }

    /**
     * TC-013.3: calculateBehaviorScore مع بيانات
     */
    public function testCalculateBehaviorScoreWithData(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO behavior_scores (student_id, school_id, academic_year_id, semester_id, current_percentage, is_active)
            VALUES (1, 1, 1, 1, 90.0, 1), (2, 1, 1, 1, 70.0, 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES (2, 1, 'طالب 2', 1)");

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateBehaviorScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1);
        $this->assertEqualsWithDelta(80.0, $result, 0.01, 'متوسط 90+70 = 80');
    }

    /**
     * TC-013.4: calculateSkillsScore مع بيانات
     */
    public function testCalculateSkillsScoreWithData(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO skills_evaluations (student_id, school_id, academic_year_id, semester_id, score, is_active)
            VALUES (1, 1, 1, 1, 65.0, 1)");

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateSkillsScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1);
        $this->assertEqualsWithDelta(65.0, $result, 0.01);
    }

    /**
     * TC-013.5: calculateAttendanceScore مع بيانات
     */
    public function testCalculateAttendanceScoreWithData(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO attendance_summaries (student_id, school_id, academic_year_id, semester_id, attendance_rate, is_active)
            VALUES (1, 1, 1, 1, 95.0, 1)");

        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateAttendanceScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1);
        $this->assertEqualsWithDelta(95.0, $result, 0.01);
    }

    // ========================================================================
    // TC-014: اختبار getEQIHistory
    // ========================================================================

    /**
     * TC-014.1: تاريخ EQI يعيد السجلات بالترتيب
     */
    public function testGetEQIHistoryReturnsRecords(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO equi_results (school_id, academic_year_id, semester_id, overall_eqi, calculated_at, is_active)
            VALUES (1, 1, 1, 75.0, '2026-01-01', 1)");

        $history = $this->calculator->getEQIHistory(1, 10);
        $this->assertCount(1, $history);
        $this->assertEqualsWithDelta(75.0, (float)$history[0]['overall_eqi'], 0.01);
    }

    /**
     * TC-014.2: تاريخ EQI يعيد مصفوفة فارغة عند عدم وجود بيانات
     */
    public function testGetEQIHistoryEmpty(): void
    {
        $history = $this->calculator->getEQIHistory(999, 10);
        $this->assertEmpty($history);
    }

    // ========================================================================
    // TC-015: اختبار getRecommendations
    // ========================================================================

    /**
     * TC-015.1: توصيات لجميع الأبعاد
     */
    public function testGetRecommendationsReturnsAll(): void
    {
        $scores = [
            'academic' => 90.0,
            'teaching' => 75.0,
            'climate' => 55.0,
            'behavior' => 30.0,
            'skills' => 85.0,
            'attendance' => 95.0,
        ];
        $recs = $this->calculator->getRecommendations($scores);

        $this->assertCount(6, $recs);
        $this->assertEquals('ممتاز', $recs['academic']['rating']['text']);
        $this->assertEquals('جيد جداً', $recs['teaching']['rating']['text']);
        $this->assertEquals('مقبول', $recs['climate']['rating']['text']);
        $this->assertEquals('ضعيف', $recs['behavior']['rating']['text']);
        $this->assertStringContainsString('عاجلة', $recs['behavior']['recommendation']);
    }

    /**
     * TC-015.2: أبعاد فارغة → مصفوفة فارغة
     */
    public function testGetRecommendationsEmpty(): void
    {
        $recs = $this->calculator->getRecommendations([]);
        $this->assertEmpty($recs);
    }

    // ========================================================================
    // TC-016: اختبار checkDataAvailability
    // ========================================================================

    /**
     * TC-016.1: بيانات الأكاديمي متوفرة
     */
    public function testCheckDataAvailabilityAcademic(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO grades (student_id, school_id, academic_year_id, semester_id, grade_value, max_value, is_active)
            VALUES (1, 1, 1, 1, 80, 100, 1)");

        $checks = $this->calculator->checkDataAvailability(1, 1, 1);
        $this->assertTrue($checks['academic']['available']);
        $this->assertGreaterThan(0, $checks['academic']['count']);
    }

    /**
     * TC-016.2: جميع الأبعاد غير متوفرة
     */
    public function testCheckDataAvailabilityAllMissing(): void
    {
        $this->initBasicData();
        $checks = $this->calculator->checkDataAvailability(1, 1, 1);

        foreach ($checks as $key => $check) {
            $this->assertFalse($check['available'], "البعد $key يجب أن يكون غير متوفر");
        }
    }

    /**
     * TC-016.3: يحتوي على page و page_label لكل بُعد
     */
    public function testCheckDataAvailabilityHasPageLinks(): void
    {
        $this->initBasicData();
        $checks = $this->calculator->checkDataAvailability(1, 1, 1);

        foreach ($checks as $key => $check) {
            $this->assertArrayHasKey('page', $check, "البعد $key يجب أن يحتوي على page");
            $this->assertArrayHasKey('page_label', $check, "البعد $key يجب أن يحتوي على page_label");
        }
    }

    // ========================================================================
    // TC-017: مسار proxy للتدريس (calculateTeachingScore مع fallback)
    // ========================================================================

    /**
     * TC-017.1: proxy مفعّل عند عدم وجود teaching_evaluation_summary ووجود grades
     * يُختبر عبر calculate() لأن calculateTeachingScore خاص (private)
     */
    public function testTeachingScoreUsesProxyWhenNoEvalData(): void
    {
        // لا توجد teaching_evaluation_summary ولكن توجد grades
        $this->initBasicData();
        $this->db->exec("INSERT INTO grades (student_id, school_id, academic_year_id, semester_id, grade_value, max_value, is_active)
            VALUES (1, 1, 1, 1, 85, 100, 1)");

        $result = $this->calculator->calculate(1, 1, 1);

        $this->assertNotNull($result['eqi'], 'يجب حساب EQI');
        // بعد التدريس يجب أن يكون متاحاً (source_type = proxy)
        $this->assertArrayHasKey('teaching', $result['dimension_details']);
        $this->assertEquals('proxy', $result['dimension_details']['teaching']['source_type'],
            'مصدر التدريس يجب أن يكون proxy في ظل غياب تقييم التدريس');
    }

    /**
     * TC-017.2: مسار مباشر عند وجود teaching_evaluation_summary
     */
    public function testTeachingScoreDirectWhenEvalDataExists(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active)
            VALUES (1, 1, 1, 78.0, 1)");
        $this->db->exec("INSERT INTO users (id, username, full_name_ar, school_id, role) VALUES (1, 't1', 'م', 1, 'teacher')");

        $usedProxy = false;
        $ref = new ReflectionClass($this->calculator);
        $method = $ref->getMethod('calculateTeachingScore');
        $method->setAccessible(true);

        $result = $method->invoke($this->calculator, 1, 1, 1, $usedProxy);
        $this->assertNotNull($result);
        $this->assertFalse($usedProxy, 'يجب ألا يكون proxy مفعّلاً مع بيانات تقييم موجودة');
        $this->assertEqualsWithDelta(78.0, $result, 0.01);
    }

    // ========================================================================
    // TC-018: اختبار compareSchools
    // ========================================================================

    /**
     * TC-018.1: مقارنة مدرستين — تعتمد على equi_results (نتائج محسوبة مسبقاً)
     */
    public function testCompareSchoolsReturnsBoth(): void
    {
        $this->initBasicData();
        $this->db->exec("INSERT INTO schools (id, name_ar) VALUES (2, 'مدرسة النجاح')");

        // إدراج نتائج محسوبة مسبقاً
        $this->db->exec("INSERT INTO equi_results (school_id, academic_year_id, semester_id, overall_eqi, calculated_at, is_active)
            VALUES (1, 1, 1, 78.5, '2026-01-01', 1),
                   (2, 1, 1, 82.0, '2026-01-01', 1)");

        $comparisons = $this->calculator->compareSchools([1, 2], 1, 1);
        $this->assertCount(2, $comparisons);
        $this->assertEqualsWithDelta(78.5, (float)$comparisons[0]['overall_eqi'], 0.01);
        $this->assertEqualsWithDelta(82.0, (float)$comparisons[1]['overall_eqi'], 0.01);
    }

    // ========================================================================
    // TC-019: اختبار تحميل الأوزان مع استثناء DB
    // ========================================================================

    /**
     * TC-019.1: عند حدوث استثناء في DB، يعود للأوزان الافتراضية
     */
    public function testLoadWeightsFallbackOnException(): void
    {
        // نحذف الجدول لإحداث استثناء
        $this->db->exec("DROP TABLE IF EXISTS eqi_dimension_weights");

        $calc = new EQICalculator($this->db);
        $dims = $calc->getDimensions();

        $this->assertEqualsWithDelta(0.35, $dims['academic']['weight'], 0.001);
        $this->assertEqualsWithDelta(0.25, $dims['teaching']['weight'], 0.001);
        $this->assertEqualsWithDelta(0.15, $dims['climate']['weight'], 0.001);
    }

    // ========================================================================
    // دوال مساعدة
    // ========================================================================

    private function initBasicData(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code, school_type) VALUES (1, 'مدرسة اختبار', 'T001', 'primary')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active) VALUES (1, '2025-2026', 1)");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'الفصل الأول', 'none', 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES (1, 1, 'طالب اختبار', 1)");
    }
}
