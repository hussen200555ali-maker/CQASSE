<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class AssessmentFlowIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة', 'S')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1)");
        $this->db->exec("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES (1, 't1', 'hash', 'معلم', 'teacher', 1, 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب')");
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id) VALUES (1, 'صف1', 1, 1)");
    }

    public function testAssessmentFormAndResponseLifecycle(): void
    {
        $this->db->exec("INSERT INTO assessment_forms (id, name_ar, form_key, category, target, is_active) VALUES (1, 'نموذج تقييم', 'test_form', 'behavior', 'student', 1)");
        $this->db->exec("INSERT INTO assessment_structure (id, form_id, type, name_ar, is_active) VALUES (1, 1, 'criteria', 'المعيار الأول', 1)");
        $this->db->exec("INSERT INTO assessment_structure (id, form_id, parent_id, type, text_ar, tool_type, weight, max_value, is_active) VALUES 
            (2, 1, 1, 'item', 'البند الأول', 'likert_5', 1.0, 5, 1)");

        $this->db->exec("INSERT INTO assessment_responses (form_id, item_id, student_id, teacher_id, class_id, academic_year_id, semester_id, school_id, numeric_value, responded_by) VALUES 
            (1, 2, 1, 1, 1, 1, 1, 1, 4, 1)");

        $stmt = $this->db->query("SELECT r.numeric_value, i.text_ar FROM assessment_responses r 
            JOIN assessment_structure i ON r.item_id = i.id WHERE r.id = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($result);
        $this->assertEquals(4, (int)$result['numeric_value']);
        $this->assertEquals('البند الأول', $result['text_ar']);
    }

    public function testAssessmentSchoolIsolation(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (2, 'مدرسة أخرى', 'S2')");
        $this->db->exec("INSERT INTO assessment_forms (id, name_ar, form_key, category, target, is_active) VALUES (1, 'نموذج', 'f1', 'behavior', 'student', 1)");
        $this->db->exec("INSERT INTO assessment_structure (id, form_id, parent_id, type, text_ar, tool_type, weight, max_value, is_active) VALUES (1, 1, NULL, 'item', 'بند', 'likert_5', 1.0, 5, 1)");

        $this->db->exec("INSERT INTO assessment_responses (form_id, item_id, student_id, teacher_id, class_id, academic_year_id, semester_id, school_id, numeric_value, responded_by) VALUES 
            (1, 1, 1, 1, 1, 1, 1, 1, 4, 1)");
        $this->db->exec("INSERT INTO assessment_responses (form_id, item_id, student_id, teacher_id, class_id, academic_year_id, semester_id, school_id, numeric_value, responded_by) VALUES 
            (1, 1, 1, 1, 1, 1, 1, 2, 3, 1)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM assessment_responses WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testAssessmentResponseValidation(): void
    {
        $this->db->exec("INSERT INTO assessment_forms (id, name_ar, form_key, category, target, is_active) VALUES (1, 'نموذج', 'f1', 'behavior', 'student', 1)");
        $this->db->exec("INSERT INTO assessment_structure (id, form_id, parent_id, type, text_ar, tool_type, weight, max_value, is_active) VALUES 
            (1, 1, NULL, 'item', 'بند', 'likert_5', 1.0, 5, 1)");

        $stmt = $this->db->prepare("SELECT count(*) FROM assessment_structure WHERE id = ? AND is_active = 1");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt->execute([999]);
        $this->assertEquals(0, $stmt->fetchColumn());
    }
}
