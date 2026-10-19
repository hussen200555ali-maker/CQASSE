<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class TeacherAccessIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة أ', 'A'), (2, 'مدرسة ب', 'B')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active, status) VALUES (1, '2025-2026', 1, 'active'), (2, '2024-2025', 1, 'active')");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'ف1', 1), (2, 1, 'ف2', 1)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS subjects (id INTEGER PRIMARY KEY AUTOINCREMENT, name_ar TEXT, is_active INTEGER DEFAULT 1)");
        $this->db->exec("INSERT OR IGNORE INTO subjects (id, name_ar) VALUES (1, 'رياضيات'), (2, 'علوم'), (3, 'لغة عربية')");

        // Recreate subject_teachers with classroom_id column (bootstrap uses class_id)
        $this->db->exec("DROP TABLE IF EXISTS subject_teachers");
        $this->db->exec("CREATE TABLE subject_teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER, subject_id INTEGER, classroom_id INTEGER,
            school_id INTEGER DEFAULT 1, academic_year_id INTEGER,
            semester_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1
        )");

        $this->db->exec("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES 
            (1, 'tch1', 'hash', 'معلم 1', 'teacher', 1, 1),
            (2, 'tch2', 'hash', 'معلم 2', 'teacher', 1, 1),
            (3, 'tch3', 'hash', 'معلم 3', 'teacher', 2, 1)");

        // Classes in school 1: class 1 (tch1 homeroom), class 2 (tch1 subject), class 3 (tch1 subject)
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id, teacher_id, is_active) VALUES 
            (1, 'صف 1-أ', 1, 1, 1, 1),
            (2, 'صف 1-ب', 1, 1, null, 1),
            (3, 'صف 1-ج', 1, 1, null, 1)");

        // Classes in school 2
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id, teacher_id, is_active) VALUES 
            (4, 'صف 2-أ', 2, 1, null, 1)");

        // subject_teachers: tch1 teaches math in class 2, tch1 teaches science in class 3
        $this->db->exec("INSERT INTO subject_teachers (id, teacher_id, subject_id, classroom_id, school_id, academic_year_id, is_active) VALUES 
            (1, 1, 1, 2, 1, 1, 1),
            (2, 1, 2, 3, 1, 1, 1)");

        $_SESSION['academic_year_id'] = 1;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Helper: simulate the access check pattern used in view/edit files
     */
    private function simulateAccessCheck(int $teacherId, int $schoolId, int $classId): bool
    {
        $allowedIds = getTeacherAssignedClassIds($this->db, $teacherId, $schoolId);
        return in_array($classId, $allowedIds);
    }

    // ========================================================================
    // BEH-01: Behavior data filtered by teacher's assigned classes
    // ========================================================================

    public function testBehaviorRecordsFilteredByTeacherClass(): void
    {
        // Insert behavior evaluations for class 1 (homeroom of tch1), class 2 (subject), class 3 (subject)
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES 
            (1, 1, 'طالب أ'), (2, 1, 'طالب ب'), (3, 1, 'طالب ج')");
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, is_active) VALUES 
            (1, 'behavior_score', 1, 1, 1, 1, 1, 80, 1),
            (2, 'behavior_score', 2, 2, 1, 1, 1, 90, 1),
            (3, 'behavior_score', 3, 3, 1, 1, 1, 70, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $ph = implode(',', array_fill(0, count($allowedIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM evaluations WHERE eval_type = 'behavior_score' AND class_id IN ($ph) AND is_active = 1");
        $stmt->execute($allowedIds);
        $this->assertEquals(3, (int)$stmt->fetchColumn(), 'tch1 should see all 3 behavior records across assigned classes');

        // tch2 has no assignments in school 1
        $allowedIds2 = getTeacherAssignedClassIds($this->db, 2, 1);
        $this->assertEmpty($allowedIds2, 'tch2 has no class assignments');
    }

    // ========================================================================
    // BEH-02: Teacher denied access to behavior evaluation in unassigned class
    // ========================================================================

    public function testTeacherDeniedBehaviorInUnassignedClass(): void
    {
        $this->assertFalse($this->simulateAccessCheck(1, 1, 999), 'Non-existent class should be denied');
        $this->assertFalse($this->simulateAccessCheck(2, 1, 1), 'tch2 should be denied class 1');
    }

    // ========================================================================
    // BEH-03: Teacher granted access to behavior evaluation in assigned class
    // ========================================================================

    public function testTeacherGrantedBehaviorInAssignedClass(): void
    {
        // tch1 is homeroom of class 1 AND subject teacher in class 2 and 3
        $this->assertTrue($this->simulateAccessCheck(1, 1, 1), 'tch1 should access class 1 (homeroom)');
        $this->assertTrue($this->simulateAccessCheck(1, 1, 2), 'tch1 should access class 2 (subject teacher)');
        $this->assertTrue($this->simulateAccessCheck(1, 1, 3), 'tch1 should access class 3 (subject teacher)');
    }

    // ========================================================================
    // SKL-01: Skills data filtered by teacher's assigned classes
    // ========================================================================

    public function testSkillsEvaluationsFilteredByTeacherClass(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES 
            (1, 1, 'طالب أ'), (2, 1, 'طالب ب')");
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, is_active) VALUES 
            (1, 'skills_checklist', 1, 1, 1, 1, 1, 85, 1),
            (2, 'skills_checklist', 2, 2, 1, 1, 1, 75, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $ph = implode(',', array_fill(0, count($allowedIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM evaluations WHERE eval_type = 'skills_checklist' AND class_id IN ($ph) AND is_active = 1");
        $stmt->execute($allowedIds);
        $this->assertEquals(2, (int)$stmt->fetchColumn(), 'tch1 should see skills for both assigned classes');
    }

    // ========================================================================
    // SKL-02: Teacher denied skills evaluation in unassigned class
    // ========================================================================

    public function testTeacherDeniedSkillsInUnassignedClass(): void
    {
        $this->assertFalse($this->simulateAccessCheck(2, 1, 2), 'tch2 has no assignments, should be denied');
        $this->assertFalse($this->simulateAccessCheck(1, 1, 999), 'Non-existent class should be denied');
    }

    // ========================================================================
    // ACA-01: Tests (academic assessment) filtered by teacher's assigned classes
    // ========================================================================

    public function testAcademicTestsFilteredByTeacherClass(): void
    {
        $this->db->exec("INSERT INTO tests (id, subject_id, class_id, assessment_type_id, academic_year_id, semester_id, title_ar, max_grade, status, created_by, is_active) VALUES 
            (1, 1, 1, 1, 1, 1, 'اختبار 1', 100, 'draft', 1, 1),
            (2, 1, 2, 1, 1, 1, 'اختبار 2', 100, 'draft', 1, 1),
            (3, 1, 3, 1, 1, 1, 'اختبار 3', 100, 'draft', 1, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $ph = implode(',', array_fill(0, count($allowedIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tests WHERE class_id IN ($ph) AND is_active = 1");
        $stmt->execute($allowedIds);
        $this->assertEquals(3, (int)$stmt->fetchColumn(), 'tch1 should see tests for all 3 assigned classes');
    }

    // ========================================================================
    // ACA-02: Teacher denied creating test in unassigned class
    // ========================================================================

    public function testTeacherDeniedTestInUnassignedClass(): void
    {
        $this->assertFalse($this->simulateAccessCheck(2, 1, 1), 'tch2 has no assignments');
    }

    // ========================================================================
    // ACA-03: Teacher denied creating test for unassigned subject even in assigned class
    // ========================================================================

    public function testTeacherDeniedTestForUnassignedSubject(): void
    {
        // tch1 has class 2 (subject_id=1 math), check subject_id=3 (arabic) is denied
        $classId = 2;
        $teacherId = 1;
        $subjectId = 3; // arabic — tch1 NOT assigned

        $allowedIds = getTeacherAssignedClassIds($this->db, $teacherId, 1);
        $this->assertTrue(in_array($classId, $allowedIds), 'tch1 should have class 2');

        // Subject-level check: the pattern from tests.php
        $check = $this->db->prepare("SELECT 1 FROM subject_teachers WHERE teacher_id = ? AND classroom_id = ? AND subject_id = ? AND academic_year_id = ? AND is_active = 1");
        $check->execute([$teacherId, $classId, $subjectId, 1]);
        $this->assertFalse((bool)$check->fetch(), 'tch1 should NOT have subject 3 in class 2');
    }

    // ========================================================================
    // ATT-01: Attendance data filtered by teacher's assigned classes
    // ========================================================================

    public function testAttendanceFilteredByTeacherClass(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES 
            (1, 1, 'طالب أ'), (2, 1, 'طالب ب')");
        $this->db->exec("INSERT INTO attendance_summaries (id, student_id, school_id, academic_year_id, semester_id, attendance_rate, is_active) VALUES 
            (1, 1, 1, 1, 1, 95, 1),
            (2, 2, 1, 1, 1, 85, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        // Attendance summaries don't have class_id directly — index.php filters by class via student_enrollments
        $this->assertCount(3, $allowedIds, 'tch1 has 3 assigned classes');
    }

    // ========================================================================
    // ATT-02: Teacher denied recording attendance in unassigned class
    // ========================================================================

    public function testTeacherDeniedAttendanceInUnassignedClass(): void
    {
        $this->assertFalse($this->simulateAccessCheck(2, 1, 1), 'tch2 has no assignments — attendance denied');
    }

    // ========================================================================
    // MIX-01: Teacher sees classes from both subject_teachers AND homeroom
    // ========================================================================

    public function testTeacherSeesUnionOfBothSources(): void
    {
        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        sort($allowedIds);
        // Class 1 (homeroom) + Class 2 (subject math) + Class 3 (subject science)
        $this->assertEquals([1, 2, 3], $allowedIds, 'tch1 should see union of homeroom and subject classes');
    }

    // ========================================================================
    // ISO-01: Teacher from school 1 cannot see school 2 classes
    // ========================================================================

    public function testTeacherIsolatedToOwnSchool(): void
    {
        // tch3 is in school 2 — should only see class 4
        $allowedIds = getTeacherAssignedClassIds($this->db, 3, 2);
        $this->assertEmpty($allowedIds, 'tch3 in school 2 has no assignments — empty');

        // Assign tch3 to class 4 in school 2
        $this->db->exec("INSERT INTO subject_teachers (id, teacher_id, subject_id, classroom_id, school_id, academic_year_id, is_active) VALUES 
            (3, 3, 1, 4, 2, 1, 1)");
        $allowedIds = getTeacherAssignedClassIds($this->db, 3, 2);
        $this->assertEquals([4], $allowedIds, 'tch3 should see only class 4 in school 2');

        // tch3 cannot access school 1 classes even with same IDs
        $this->assertFalse($this->simulateAccessCheck(3, 2, 1), 'tch3 cannot access school 1 class 1');
    }

    // ========================================================================
    // EMPTY-01: Teacher with no assignments gets no data
    // ========================================================================

    public function testTeacherWithNoAssignmentsSeesNothing(): void
    {
        $allowedIds = getTeacherAssignedClassIds($this->db, 2, 1);
        $this->assertEmpty($allowedIds, 'tch2 has no assignments');

        // Simulate the query patterns used in index files
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM classes WHERE id IN ($ph)");
            $stmt->execute($allowedIds);
            $count = (int)$stmt->fetchColumn();
        } else {
            $count = 0;
        }
        $this->assertEquals(0, $count, 'tch2 should see 0 classes');
    }

    // ========================================================================
    // PERM-01: behavior edit.php guard — teacher cannot edit other class
    // ========================================================================

    public function testBehaviorEditGuardDeniesOtherClass(): void
    {
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب')");
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, is_active) VALUES 
            (1, 'behavior_score', 1, 999, 1, 1, 1, 80, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $this->assertNotContains(999, $allowedIds, 'tch1 should NOT have class 999');

        // Simulate the pattern from behavior/edit.php
        $stmt = $this->db->prepare("SELECT class_id FROM evaluations WHERE id = ? AND is_active = 1");
        $stmt->execute([1]);
        $eval = $stmt->fetch();
        $this->assertNotEmpty($eval, 'evaluation 1 exists');
        $this->assertFalse(in_array((int)$eval['class_id'], $allowedIds), 'Guard should deny access');
    }

    // ========================================================================
    // PERM-02: API get_students.php — teacher sees only own class students
    // ========================================================================

    public function testApiGetStudentsFilteredByTeacherClass(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS student_enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER, class_id INTEGER, academic_year_id INTEGER,
            school_id INTEGER DEFAULT 1, status TEXT DEFAULT 'enrolled',
            is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES 
            (1, 1, 'طالب 1', 1), (2, 1, 'طالب 2', 1), (3, 1, 'طالب 3', 1)");
        $this->db->exec("INSERT INTO student_enrollments (id, student_id, class_id, academic_year_id, school_id, status, is_active) VALUES 
            (1, 1, 1, 1, 1, 'enrolled', 1),
            (2, 2, 2, 1, 1, 'enrolled', 1),
            (3, 3, 3, 1, 1, 'enrolled', 1)");

        // Simulate API pattern: teacher sees students only in allowed classes
        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $this->assertCount(3, $allowedIds, 'tch1 has 3 assigned classes');

        $ph = implode(',', array_fill(0, count($allowedIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se WHERE se.class_id IN ($ph) AND se.is_active = 1");
        $stmt->execute($allowedIds);
        $this->assertEquals(3, (int)$stmt->fetchColumn(), 'tch1 sees students from all assigned classes');
    }
}
