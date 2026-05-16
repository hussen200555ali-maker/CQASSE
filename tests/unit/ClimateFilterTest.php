<?php
/**
 * اختبارات فلاتر وحدة المناخ المدرسي
 * Climate Module Filter Tests
 * ====================================
 * يختبر:
 * - دوال filters.php (semesterValueToNameAr, buildSemesterSubquery, buildYearFilter, buildSchoolFilter)
 * - دقة استعلامات الفصل الدراسي
 * - صلاحية تضمين ملفات صفحة المناخ
 */

require_once __DIR__ . '/../../includes/filters.php';

final class ClimateFilterTest extends \PHPUnit\Framework\TestCase
{
    public function testSemesterValueToNameArReturnsFirstSemester(): void
    {
        $this->assertEquals('الفصل الدراسي الأول', semesterValueToNameAr(1));
    }

    public function testSemesterValueToNameArReturnsSecondSemester(): void
    {
        $this->assertEquals('الفصل الدراسي الثاني', semesterValueToNameAr(2));
    }

    public function testSemesterValueToNameArReturnsNullForInvalid(): void
    {
        $this->assertNull(semesterValueToNameAr(0));
        $this->assertNull(semesterValueToNameAr(3));
        $this->assertNull(semesterValueToNameAr(-1));
    }

    public function testBuildSemesterSubqueryWithValidValue(): void
    {
        $result = buildSemesterSubquery(1, 'cs');
        $this->assertStringContainsString('cs.semester_id IN', $result['sql']);
        $this->assertStringContainsString('الفصل الدراسي الأول', $result['params'][0]);
        $this->assertCount(1, $result['params']);
    }

    public function testBuildSemesterSubqueryWithZeroValue(): void
    {
        $result = buildSemesterSubquery(0, 'cs');
        $this->assertEquals('', $result['sql']);
        $this->assertCount(0, $result['params']);
    }

    public function testBuildSemesterSubqueryWithCustomAlias(): void
    {
        $result = buildSemesterSubquery(2, 'sc');
        $this->assertStringContainsString('sc.semester_id IN', $result['sql']);
        $this->assertStringContainsString('الفصل الدراسي الثاني', $result['params'][0]);
    }

    public function testBuildYearFilterWithValidId(): void
    {
        $result = buildYearFilter(1, 'cs');
        $this->assertStringContainsString('cs.academic_year_id = ?', $result['sql']);
        $this->assertEquals([1], $result['params']);
    }

    public function testBuildYearFilterWithZero(): void
    {
        $result = buildYearFilter(0, 'cs');
        $this->assertEquals('', $result['sql']);
        $this->assertCount(0, $result['params']);
    }

    public function testBuildSchoolFilterWithValidId(): void
    {
        $result = buildSchoolFilter(3, 'cs');
        $this->assertStringContainsString('cs.school_id = ?', $result['sql']);
        $this->assertEquals([3], $result['params']);
    }

    public function testBuildSchoolFilterWithZero(): void
    {
        $result = buildSchoolFilter(0, 'cs');
        $this->assertEquals('', $result['sql']);
        $this->assertCount(0, $result['params']);
    }

    public function testFilterPagesIncludeFiltersPhp(): void
    {
        // التحقق من أن ملفات الفلتر تحتوي على require_once filters.php
        $files = [
            __DIR__ . '/../../pages/climate/dashboard.php',
            __DIR__ . '/../../pages/climate/school.php',
            __DIR__ . '/../../pages/climate/index.php',
            __DIR__ . '/../../pages/climate/survey.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'filters.php',
                $content,
                "الملف $file لا يتضمن filters.php"
            );
        }
    }

    public function testFilterPagesUseHardcodedSemesterDropdown(): void
    {
        // التحقق من أن ملفات الفلتر لا تستخدم استعلام الفصول القديم
        $filterFiles = [
            __DIR__ . '/../../pages/climate/dashboard.php',
            __DIR__ . '/../../pages/climate/school.php',
            __DIR__ . '/../../pages/climate/index.php',
        ];
        foreach ($filterFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                'FROM semesters WHERE is_active = 1',
                $content,
                "الملف $file لا يزال يستخدم استعلام الفصول القديم"
            );
        }
    }

    public function testFormPagesUseHardcodedSemesterDropdown(): void
    {
        $formFiles = [
            __DIR__ . '/../../pages/climate/survey.php',
        ];
        foreach ($formFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                'FROM semesters WHERE is_active = 1',
                $content,
                "الملف $file لا يزال يستخدم استعلام الفصول القديم"
            );
        }
    }

    public function testSemesterDropDownValuesCorrect(): void
    {
        // التحقق من أن قيم القائمة المنسدلة صحيحة
        $filterFiles = [
            'dashboard.php' => file_get_contents(__DIR__ . '/../../pages/climate/dashboard.php'),
            'school.php'    => file_get_contents(__DIR__ . '/../../pages/climate/school.php'),
            'index.php'     => file_get_contents(__DIR__ . '/../../pages/climate/index.php'),
        ];
        foreach ($filterFiles as $name => $content) {
            $this->assertStringContainsString(
                'value="0"',
                $content,
                "الملف $name يفتقد value=\"0\" لفئة 'جميع الفصول'"
            );
            $this->assertStringContainsString(
                'value="1"',
                $content,
                "الملف $name يفتقد value=\"1\" للفصل الأول"
            );
            $this->assertStringContainsString(
                'value="2"',
                $content,
                "الملف $name يفتقد value=\"2\" للفصل الثاني"
            );
        }

        // نموذج الاستبيان يستخدم value=1,2 فقط (لا يوجد 0=الكل)
        $surveyContent = file_get_contents(__DIR__ . '/../../pages/climate/survey.php');
        $this->assertStringContainsString('value="1"', $surveyContent);
        $this->assertStringContainsString('value="2"', $surveyContent);
    }

    public function testFilterPagesDoNotUseSemesterDbQuery(): void
    {
        // التحقق من عدم وجود استعلام جلب الفصول من قاعدة البيانات
        $files = [
            __DIR__ . '/../../pages/climate/dashboard.php',
            __DIR__ . '/../../pages/climate/school.php',
            __DIR__ . '/../../pages/climate/index.php',
        ];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                '$stmt = $db->query("SELECT id, name_ar FROM semesters',
                $content,
                "الملف $file لا يزال يجلب الفصول من DB"
            );
        }
    }
}
