<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class AcademicYearIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
    }

    public function testSemesterLifecycle(): void
    {
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'الفصل الأول', 'none', 1)");

        $stmt = $this->db->query("SELECT lock_level FROM semesters WHERE id = 1");
        $this->assertEquals('none', $stmt->fetchColumn());

        $this->assertFalse(isSemesterLocked($this->db, 1));
        $this->assertTrue(canEditSemester($this->db, 1, 'teacher'));

        $this->db->exec("UPDATE semesters SET lock_level = 'soft' WHERE id = 1");
        $this->assertTrue(isSemesterLocked($this->db, 1));
        $this->assertFalse(canEditSemester($this->db, 1, 'teacher'));
        $this->assertTrue(canEditSemester($this->db, 1, 'school_admin'));

        $this->db->exec("UPDATE semesters SET lock_level = 'hard' WHERE id = 1");
        $this->assertTrue(isSemesterLocked($this->db, 1));
        $this->assertFalse(canEditSemester($this->db, 1, 'school_admin'));
        $this->assertTrue(canEditSemester($this->db, 1, 'group_admin'));

        $this->db->exec("UPDATE semesters SET lock_level = 'admin_handled' WHERE id = 1");
        $this->assertFalse(canEditSemester($this->db, 1, 'group_admin'));
    }

    public function testSemesterStatsPerSchool(): void
    {
        $this->db->exec("INSERT INTO academic_years (id, name, is_active) VALUES (1, '2025-2026', 1)");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1), (2, 1, 'ف2', 1)");
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة أ', 'A'), (2, 'مدرسة ب', 'B')");
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id) VALUES (1, 'صف1', 1, 1), (2, 'صف2', 2, 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب1'), (2, 2, 'طالب2')");

        $stmt = $this->db->query("SELECT COUNT(*) FROM classes WHERE school_id = 1");
        $this->assertEquals(1, $stmt->fetchColumn());

        $stmt = $this->db->query("SELECT COUNT(*) FROM students WHERE school_id = 2");
        $this->assertEquals(1, $stmt->fetchColumn());
    }
}
