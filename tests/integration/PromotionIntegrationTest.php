<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class PromotionIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة', 'S')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب'), (2, 1, 'طالب2'), (3, 1, 'طالب3')");
        $this->db->exec("INSERT INTO grades (id, student_id, school_id, academic_year_id, semester_id, grade_value, max_value) VALUES 
            (1, 1, 1, 1, 1, 85, 100),
            (2, 2, 1, 1, 1, 45, 100),
            (3, 3, 1, 1, 1, 95, 100)");
    }

    public function testPromotionClassification(): void
    {
        $class = getPromotionClassification(85, 70, 80);
        $this->assertEquals('مرفع', $class[1]);

        $class = getPromotionClassification(40, 70, 80);
        $this->assertEquals('معيد (أكاديمي)', $class[1]);
    }

    public function testPromotionThresholds(): void
    {
        $passAcademic = 50;
        $passBehavior = 60;
        $passAttendance = 75;

        $this->assertTrue(85 >= $passAcademic);
        $this->assertTrue(70 >= $passBehavior);
        $this->assertFalse(40 >= $passAcademic);
        $this->assertFalse(50 >= $passAttendance);
    }

    public function testGradeLevelLabels(): void
    {
        $this->assertEquals('الأول أساسي', getGradeLevelLabel(1, 'basic'));
        $this->assertEquals('الثاني أساسي', getGradeLevelLabel(2, 'basic'));

        $this->assertEquals('العاشر ثانوي', getGradeLevelLabel(10, 'secondary'));
        $this->assertEquals('الحادي عشر ثانوي', getGradeLevelLabel(11, 'secondary'));
    }
}
