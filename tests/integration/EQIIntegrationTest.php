<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class EQIIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة', 'S')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1)");
        $this->db->exec("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES (1, 'group', 'hash', 'admin', 'group_admin', 1, 1)");
    }

    public function testEqiCalculationAndRetrieval(): void
    {
        $this->db->exec("INSERT INTO equi_results (school_id, academic_year_id, semester_id, academic_score, teaching_score, climate_score, behavior_score, skills_score, attendance_score, overall_eqi, calculated_by, calculated_at) 
            VALUES (1, 1, 1, 85.5, 78.0, 90.0, 70.0, 88.0, 95.0, 83.2, 1, '2026-05-11')");

        $stmt = $this->db->query("SELECT * FROM equi_results WHERE school_id = 1 AND academic_year_id = 1 AND semester_id = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($result);
        $this->assertEqualsWithDelta(83.2, (float)$result['overall_eqi'], 0.01);
        $this->assertEqualsWithDelta(85.5, (float)$result['academic_score'], 0.01);

        $rating = getEqiRating((float)$result['overall_eqi']);
        $this->assertEquals('جيد جداً', $rating[0]);

        $excellent = getEqiRating(90);
        $this->assertEquals('ممتاز', $excellent[0]);
    }

    public function testEqiSchoolIsolation(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (2, 'مدرسة ب', 'S2')");

        $this->db->exec("INSERT INTO equi_results (school_id, academic_year_id, semester_id, academic_score, teaching_score, climate_score, behavior_score, skills_score, attendance_score, overall_eqi, calculated_by, calculated_at) VALUES 
            (1, 1, 1, 80, 75, 85, 70, 80, 90, 79.5, 1, '2026-05-11'),
            (2, 1, 1, 60, 55, 65, 50, 60, 70, 59.2, 1, '2026-05-11')");

        $stmt = $this->db->prepare("SELECT overall_eqi FROM equi_results WHERE school_id = ?");
        $stmt->execute([2]);
        $this->assertEqualsWithDelta(59.2, (float)$stmt->fetchColumn(), 0.01);
    }

    public function testDimensionWeightsRetrieval(): void
    {
        $this->db->exec("DELETE FROM eqi_dimension_weights");
        $this->db->exec("INSERT INTO eqi_dimension_weights (id, dimension_name, dimension_name_ar, default_weight, is_active) VALUES 
            (1, 'academic', 'أكاديمي', 35, 1),
            (2, 'teaching', 'تعليم', 25, 1),
            (3, 'climate', 'مناخ', 15, 1),
            (4, 'behavior', 'سلوك', 10, 1),
            (5, 'skills', 'مهارات', 10, 1),
            (6, 'attendance', 'حضور', 5, 1)");

        $stmt = $this->db->query("SELECT dimension_name, default_weight FROM eqi_dimension_weights WHERE is_active = 1 ORDER BY id");
        $weights = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(6, $weights);

        $totalWeight = array_sum(array_column($weights, 'default_weight'));
        $this->assertEqualsWithDelta(100, $totalWeight, 1);
    }
}
