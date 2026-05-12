<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class BehaviorIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة', 'S')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب'), (2, 1, 'طالب2')");
        $this->db->exec("INSERT INTO behavior_categories (id, name_ar, type, is_active) VALUES (1, 'سلوك إيجابي', 'positive', 1)");
        $this->db->exec("INSERT INTO behavior_categories (id, name_ar, type, is_active) VALUES (2, 'مخالفة', 'negative', 1)");
    }

    public function testBehaviorIncidentRecording(): void
    {
        $this->db->exec("INSERT INTO behavior_incidents (id, student_id, category_id, school_id, academic_year_id, semester_id, points, description, recorded_by, incident_date) VALUES 
            (1, 1, 1, 1, 1, 1, 10, 'سلوك حسن', 1, '2026-05-01')");

        $stmt = $this->db->query("SELECT bi.id, bc.name_ar AS category, bi.points FROM behavior_incidents bi 
            JOIN behavior_categories bc ON bi.category_id = bc.id WHERE bi.id = 1");
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($incident);
        $this->assertEquals('سلوك إيجابي', $incident['category']);
        $this->assertEquals(10, (int)$incident['points']);
    }

    public function testBehaviorScoreCalculation(): void
    {
        $this->db->exec("INSERT INTO behavior_incidents (id, student_id, category_id, school_id, academic_year_id, semester_id, points, recorded_by, incident_date) VALUES 
            (1, 1, 1, 1, 1, 1, 80, 1, '2026-05-01'),
            (2, 2, 1, 1, 1, 1, 40, 1, '2026-05-01')");

        $stmt = $this->db->query("SELECT student_id, SUM(points) as total FROM behavior_incidents GROUP BY student_id ORDER BY student_id");
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $scores);
        $this->assertEquals(80, (int)$scores[0]['total']);
        $this->assertEquals(40, (int)$scores[1]['total']);
    }

    public function testBehaviorSchoolIsolation(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (2, 'مدرسة ب', 'S2')");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (3, 2, 'طالب3')");
        $this->db->exec("INSERT INTO behavior_incidents (id, student_id, category_id, school_id, academic_year_id, semester_id, points, recorded_by, incident_date) VALUES 
            (1, 1, 1, 1, 1, 1, 50, 1, '2026-05-01'),
            (2, 3, 1, 2, 1, 1, 30, 1, '2026-05-01')");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM behavior_incidents WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testAlertTypeMapping(): void
    {
        $this->assertEquals('درجة منخفضة', getAlertTypeLabel('low_grade'));
        $this->assertEquals('سلوك سيء', getAlertTypeLabel('poor_behavior'));
        $this->assertEquals('خطر عدم الترفيع', getAlertTypeLabel('promotion_risk'));
        $this->assertEquals('مخصص', getAlertTypeLabel('custom'));
        $this->assertEquals('unknown_type', getAlertTypeLabel('unknown_type'));

        $this->assertEquals('معلومات', getSeverityLabel('info'));
        $this->assertEquals('تحذير', getSeverityLabel('warning'));
        $this->assertEquals('حرج', getSeverityLabel('critical'));
        $this->assertEquals('unknown', getSeverityLabel('unknown'));
    }
}
