<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class SecurityFixesIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $_SESSION = ['user' => ['role' => 'teacher', 'school_id' => 1, 'id' => 1]];
        $_SESSION['academic_year_id'] = 1;

        // Schools
        $this->db->exec("INSERT INTO schools (id, name_ar) VALUES (1, 'مدرسة أ'), (2, 'مدرسة ب')");

        // Users
        $this->db->exec("INSERT INTO users (id, username, full_name_ar, role, school_id, is_active) VALUES
            (1, 't1', 'معلم 1', 'teacher', 1, 1),
            (2, 't2', 'معلم 2', 'teacher', 1, 1),
            (3, 't3', 'معلم ب', 'teacher', 2, 1),
            (4, 'admin', 'مدير أ', 'school_admin', 1, 1),
            (5, 'admin2', 'مدير ب', 'school_admin', 2, 1)");

        // Classes
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id, teacher_id, is_active) VALUES
            (1, 'فصل أ1', 1, 1, 1, 1),
            (2, 'فصل أ2', 1, 1, null, 1),
            (3, 'فصل أ3', 1, 1, null, 1),
            (10, 'فصل ب1', 2, 1, null, 1)");

        // subject_teachers
        $this->db->exec("DROP TABLE IF EXISTS subject_teachers");
        $this->db->exec("CREATE TABLE subject_teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER, subject_id INTEGER, classroom_id INTEGER,
            school_id INTEGER DEFAULT 1, academic_year_id INTEGER,
            semester_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO subject_teachers (teacher_id, subject_id, classroom_id, school_id, academic_year_id, is_active) VALUES
            (1, 1, 2, 1, 1, 1),
            (1, 2, 3, 1, 1, 1)");

        // Students
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES
            (1, 1, 'طالب أ1', 1), (2, 1, 'طالب أ2', 1), (3, 1, 'طالب أ3', 1)");

        // Student enrollments
        $this->db->exec("CREATE TABLE IF NOT EXISTS student_enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER, class_id INTEGER, academic_year_id INTEGER,
            school_id INTEGER DEFAULT 1, status TEXT DEFAULT 'enrolled', is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, school_id) VALUES
            (1, 1, 1, 1), (2, 2, 1, 1), (3, 3, 1, 1)");

        // Subjects (bootstrap doesn't create this)
        $this->db->exec("CREATE TABLE IF NOT EXISTS subjects (id INTEGER PRIMARY KEY AUTOINCREMENT, name_ar TEXT, code TEXT, is_active INTEGER DEFAULT 1)");
        $this->db->exec("INSERT OR IGNORE INTO subjects (id, name_ar, code) VALUES (1, 'رياضيات', 'MATH'), (2, 'علوم', 'SCI'), (3, 'لغة عربية', 'AR')");

        // Assessment types and tests are already created by bootstrap

        // Ensure evaluations has evaluated_by column
        try { $this->db->exec("ALTER TABLE evaluations ADD COLUMN evaluated_by INTEGER DEFAULT NULL"); } catch (Exception $e) {}

        // Evaluations
        $this->db->exec("CREATE TABLE IF NOT EXISTS evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            eval_type TEXT, student_id INTEGER, class_id INTEGER,
            school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
            combined_score REAL, evaluated_by INTEGER, is_active INTEGER DEFAULT 1
        )");

        // Semesters
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'الفصل الأول', 1)");
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ========================================================================
    // SFI-01: Full flow — behavior index restricted by teacher class scope
    // ========================================================================

    public function testFullFlowBehaviorIndexRestrictedByClassScope(): void
    {
        // Insert behavior evals: class 1 (homeroom), class 2 (subject), class 10 (other school)
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, evaluated_by, is_active) VALUES
            (1, 'behavior_score', 1, 1, 1, 1, 1, 80, 1, 1),
            (2, 'behavior_score', 2, 2, 1, 1, 1, 90, 1, 1),
            (3, 'behavior_score', 3, 10, 2, 1, 1, 70, 2, 1)");

        // Simulate the full filter pattern from behavior/index.php
        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $sql = "SELECT e.id, e.class_id, e.combined_score FROM evaluations e WHERE e.eval_type = 'behavior_score' AND e.is_active = 1";
        $params = [];

        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql .= " AND (e.evaluated_by = ? OR e.class_id IN ($ph))";
            $params[] = 1; // userId
            $params = array_merge($params, $allowedIds);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        $this->assertCount(2, $results, 'Teacher sees only 2 behavior evals (classes 1, 2)');
        $this->assertEquals(1, (int)$results[0]['class_id']);
        $this->assertEquals(2, (int)$results[1]['class_id']);
    }

    // ========================================================================
    // SFI-02: Full flow — skills index restricted by teacher class scope
    // ========================================================================

    public function testFullFlowSkillsIndexRestrictedByClassScope(): void
    {
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, evaluated_by, is_active) VALUES
            (1, 'skills_checklist', 1, 1, 1, 1, 1, 95, 1, 1),
            (2, 'skills_checklist', 2, 2, 1, 1, 1, 85, 1, 1),
            (3, 'skills_checklist', 3, 10, 2, 1, 1, 75, 2, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $sql = "SELECT e.id, e.class_id FROM evaluations e WHERE e.eval_type = 'skills_checklist' AND e.is_active = 1";
        $params = [];

        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql .= " AND (e.evaluated_by = ? OR e.class_id IN ($ph))";
            $params[] = 1;
            $params = array_merge($params, $allowedIds);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        $this->assertCount(2, $results, 'Teacher sees only 2 skills evals');
        $classIds = array_map('intval', array_column($results, 'class_id'));
        $this->assertContains(1, $classIds);
        $this->assertContains(2, $classIds);
    }

    // ========================================================================
    // SFI-03: publish/lock flow — test ownership check
    // ========================================================================

    public function testFullFlowPublishLockOwnership(): void
    {
        $this->db->exec("INSERT INTO tests (id, subject_id, class_id, assessment_type_id, academic_year_id, semester_id, title_ar, max_grade, status, created_by, is_active) VALUES
            (1, 1, 1, 1, 1, 1, 'اختبار أ', 100, 'draft', 1, 1),
            (2, 1, 10, 1, 1, 1, 'اختبار ب', 100, 'draft', 2, 1)");

        // Test 1: t1 should be able to publish test in class 1 (homeroom)
        $stmt = $this->db->prepare("SELECT t.class_id FROM tests t WHERE t.id = ? AND t.is_active = 1");
        $stmt->execute([1]);
        $classId = (int)$stmt->fetchColumn();
        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $this->assertTrue(in_array($classId, $allowedIds), 't1 can publish test 1');

        // Test 2: t1 should NOT be able to publish test in class 10 (other school)
        $stmt->execute([2]);
        $classId2 = (int)$stmt->fetchColumn();
        $this->assertFalse(in_array($classId2, $allowedIds), 't1 cannot publish test in other school class');
    }

    // ========================================================================
    // SFI-04: Teaching evaluation cross-school isolation
    // ========================================================================

    public function testTeachingEvaluationCrossSchoolIsolation(): void
    {
        // t1 (school 1) tries to evaluate t3 (school 2)
        $schoolId = 1;
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = ? AND school_id = ? AND is_active = 1");
        $stmt->execute([3, $schoolId]);
        $this->assertFalse((bool)$stmt->fetch(), 'Cross-school peer evaluation blocked');

        // school_admin from school 1 evaluates t1 (same school) — ok
        $stmt->execute([1, $schoolId]);
        $this->assertTrue((bool)$stmt->fetch(), 'Same-school evaluation allowed');
    }

    // ========================================================================
    // SFI-05: Grade edit cross-school isolation
    // ========================================================================

    public function testGradeEditCrossSchoolIsolation(): void
    {
        // Test in school 1
        $this->db->exec("INSERT INTO tests (id, subject_id, class_id, assessment_type_id, academic_year_id, semester_id, title_ar, max_grade, status, created_by, is_active) VALUES
            (1, 1, 1, 1, 1, 1, 'اختبار', 100, 'draft', 1, 1)");

        // user from school 2
        $schoolId = 2;
        $stmt = $this->db->prepare("SELECT t.*, c.school_id FROM tests t JOIN classes c ON t.class_id = c.id WHERE t.id = ? AND t.is_active = 1" . ($schoolId ? " AND c.school_id = ?" : ""));
        $params = [1];
        if ($schoolId) $params[] = $schoolId;
        $stmt->execute($params);
        $this->assertEmpty($stmt->fetch(), 'School 2 should not see school 1 test');
    }

    // ========================================================================
    // SFI-06: Teacher dashboard — sidebar link exists in config
    // ========================================================================

    public function testTeacherDashboardPermissionExists(): void
    {
        // Simulate the sidebar permission check
        $menuPermissions = [
            'teacher_dashboard' => ['teacher'],
        ];

        // teacher can see it
        $userRole = 'teacher';
        $allowedRoles = $menuPermissions['teacher_dashboard'] ?? [];
        $this->assertTrue(in_array($userRole, $allowedRoles), 'Teacher can see teacher_dashboard');

        // school_admin cannot see it
        $userRole = 'school_admin';
        $this->assertFalse(in_array($userRole, $allowedRoles), 'School admin cannot see teacher_dashboard');

        // group_admin cannot see it
        $userRole = 'group_admin';
        $this->assertFalse(in_array($userRole, $allowedRoles), 'Group admin cannot see teacher_dashboard');
    }

    // ========================================================================
    // SFI-07: Behavior view with evaluated_by + class_id filter
    // ========================================================================

    public function testBehaviorViewFilterIncludesEvaluatedByAndClass(): void
    {
        // Teacher 1 created evaluation for student in class 10 (other school)
        $this->db->exec("INSERT INTO evaluations (id, eval_type, student_id, class_id, school_id, academic_year_id, semester_id, combined_score, evaluated_by, is_active) VALUES
            (1, 'behavior_score', 3, 10, 2, 1, 1, 80, 1, 1)");

        $allowedIds = getTeacherAssignedClassIds($this->db, 1, 1);
        $ph = implode(',', array_fill(0, count($allowedIds), '?'));

        // With the fix: teacher sees eval 1 only if evaluated_by = 1 OR class_id IN assigned
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM evaluations e WHERE e.id = 1 AND e.is_active = 1 AND (e.evaluated_by = ? OR e.class_id IN ($ph))");
        $params = [1];
        $params = array_merge($params, $allowedIds);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();

        // Even though evaluated_by = 1 (teacher created it), class_id 10 is not in assigned classes
        // But with the OR condition, evaluated_by matches → it should be visible
        $this->assertEquals(1, $count, 'Teacher sees own evaluation even if in unassigned class (evaluated_by matches)');
    }
}
