<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class SchoolIsolationIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة أ', 'A'), (2, 'مدرسة ب', 'B')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1)");
    }

    public function testStudentsAreSchoolScoped(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب أ'), (2, 2, 'طالب ب')");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmtAll = $this->db->query("SELECT COUNT(*) FROM students");
        $this->assertEquals(2, $stmtAll->fetchColumn());
    }

    public function testClassesAreSchoolScoped(): void
    {
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id) VALUES (1, 'صف أ', 1, 1), (2, 'صف ب', 2, 1)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testUsersAreSchoolScoped(): void
    {
        $this->db->exec("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES 
            (1, 'admin_a', 'hash', 'مشرف أ', 'school_admin', 1, 1),
            (2, 'admin_b', 'hash', 'مشرف ب', 'school_admin', 2, 1)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'school_admin'");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testGradesAreSchoolScoped(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب'), (2, 2, 'طالب2')");
        $this->db->exec("INSERT INTO grades (id, student_id, school_id, academic_year_id, semester_id, grade_value, max_value) VALUES 
            (1, 1, 1, 1, 1, 80, 100),
            (2, 2, 2, 1, 1, 90, 100)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM grades WHERE school_id = ?");
        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testSubjectTeachersAreSchoolScoped(): void
    {
        $this->db->exec("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES 
            (1, 't1', 'hash', 'م1', 'teacher', 1, 1),
            (2, 't2', 'hash', 'م2', 'teacher', 2, 1)");
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id) VALUES (1, 'صف', 1, 1), (2, 'صف2', 2, 1)");
        $this->db->exec("INSERT INTO subject_teachers (id, teacher_id, class_id, school_id, academic_year_id) VALUES 
            (1, 1, 1, 1, 1),
            (2, 2, 2, 2, 1)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM subject_teachers WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testGetAvailableSchoolsRespectsRole(): void
    {
        $schoolsForGroupAdmin = getAvailableSchools($this->db, 'group_admin', 1);
        $this->assertCount(2, $schoolsForGroupAdmin);

        $schoolsForSchoolAdmin = getAvailableSchools($this->db, 'school_admin', 1);
        $this->assertCount(1, $schoolsForSchoolAdmin);
        $this->assertEquals(1, (int)$schoolsForSchoolAdmin[0]['id']);
    }

    public function testSchoolStatsByDirectQuery(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب1'), (2, 1, 'طالب2'), (3, 2, 'طالب3')");
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id) VALUES (1, 'صف1', 1, 1), (2, 'صف2', 1, 1), (3, 'صف3', 2, 1)");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(2, $stmt->fetchColumn());

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ?");
        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }
}
