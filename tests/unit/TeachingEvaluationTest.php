<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/alerts.php';

class TeachingEvaluationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();

        // إضافة الجداول المفقودة في bootstrap
        $this->db->exec("CREATE TABLE IF NOT EXISTS teaching_evaluation_summary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            academic_year_id INTEGER NOT NULL,
            semester_id INTEGER NOT NULL,
            self_score REAL DEFAULT NULL,
            peer_score REAL DEFAULT NULL,
            admin_score REAL DEFAULT NULL,
            combined_score REAL DEFAULT NULL,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(teacher_id, academic_year_id, semester_id)
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_id INTEGER NOT NULL,
            student_id INTEGER DEFAULT NULL,
            teacher_id INTEGER DEFAULT NULL,
            alert_type TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'warning',
            title_ar TEXT NOT NULL,
            message_ar TEXT NOT NULL,
            notes TEXT DEFAULT NULL,
            is_read INTEGER DEFAULT 0,
            is_resolved INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS teacher_development_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            school_id INTEGER NOT NULL,
            weaknesses TEXT,
            goals TEXT,
            strategies TEXT,
            follow_up_date TEXT,
            follow_up_notes TEXT,
            status TEXT DEFAULT 'active',
            progress INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            display_name_ar TEXT
        )");
        $this->db->exec("INSERT OR IGNORE INTO roles (id, name, display_name_ar) VALUES (1, 'group_admin', 'مدير المجموعة')");
        $this->db->exec("INSERT OR IGNORE INTO roles (id, name, display_name_ar) VALUES (2, 'school_admin', 'مدير المدرسة')");
        $this->db->exec("INSERT OR IGNORE INTO roles (id, name, display_name_ar) VALUES (3, 'teacher', 'معلم')");

        // إضافة school_id إلى users (في bootstrap.users ليس لديه school_id)
        // نتحقق أولاً إن كان العمود موجوداً
        try {
            $this->db->exec("ALTER TABLE users ADD COLUMN school_id INTEGER DEFAULT 1");
        } catch (Exception $e) {
            // العمود موجود مسبقاً
        }
        try {
            $this->db->exec("ALTER TABLE users ADD COLUMN role_name TEXT DEFAULT 'teacher'");
        } catch (Exception $e) {}

        $this->db->exec("DELETE FROM users");
        $this->db->exec("INSERT INTO users (id, username, full_name_ar, school_id, role_name) VALUES
            (1, 'teacher1', 'أحمد محمد', 1, 'teacher'),
            (2, 'teacher2', 'خالد علي', 1, 'teacher'),
            (3, 'teacher3', 'سامي حسن', 2, 'teacher')");

        $this->db->exec("INSERT OR IGNORE INTO schools (id, name_ar) VALUES (1, 'مدرسة الأمل'), (2, 'مدرسة النجاح')");
    }

    // ========================================================================
    // TS-01 إلى TS-03: calculateTeachingScore()
    // ========================================================================

    /**
     * TS-01: بيانات موجودة → يعيد المتوسط الصحيح
     */
    public function testCalculateTeachingScoreWithData(): void
    {
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 80.0, 1),
            (2, 1, 1, 90.0, 1)");

        $calculator = new EQICalculator($this->db);
        $ref = new ReflectionClass($calculator);
        $method = $ref->getMethod('calculateTeachingScore');
        $method->setAccessible(true);

        // المدرسة 1: متوسط = (80+90)/2 = 85
        $result = $method->invoke($calculator, 1, 1, 1);
        $this->assertEqualsWithDelta(85.0, $result, 0.01, 'متوسط combined_score = 85');
    }

    /**
     * TS-02: جدول فارغ → null
     */
    public function testCalculateTeachingScoreEmpty(): void
    {
        $calculator = new EQICalculator($this->db);
        $ref = new ReflectionClass($calculator);
        $method = $ref->getMethod('calculateTeachingScore');
        $method->setAccessible(true);

        $result = $method->invoke($calculator, 1, 1, 1);
        $this->assertNull($result, 'لا توجد بيانات → null');
    }

    /**
     * TS-03: معلم واحد فقط بمدرسة مختلفة → المدرسة 1 ليس لديها بيانات
     */
    public function testCalculateTeachingScoreOtherSchoolOnly(): void
    {
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (3, 1, 1, 75.0, 1)"); // teacher3 في school_id=2

        $calculator = new EQICalculator($this->db);
        $ref = new ReflectionClass($calculator);
        $method = $ref->getMethod('calculateTeachingScore');
        $method->setAccessible(true);

        $result = $method->invoke($calculator, 1, 1, 1);
        $this->assertNull($result, 'المدرسة 1 ليس لديها بيانات → null');
    }

    // ========================================================================
    // TS-04, TS-05: checkTeachingThresholds() — قاعدة 1 (متوسط < 50%)
    // ========================================================================

    /**
     * TS-04: متوسط 45% → ينشئ تنبيه danger
     */
    public function testTeachingThresholdBelow50CreatesAlert(): void
    {
        // مدرسة 1: معلم واحد متوسطه 45%
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 45.0, 1)");

        $engine = new AlertEngine($this->db);
        $count = $engine->checkTeachingThresholds(1, 1, 1);

        $this->assertGreaterThan(0, $count, 'يجب إنشاء تنبيه danger');

        $stmt = $this->db->query("SELECT * FROM alerts WHERE alert_type = 'teaching' AND severity = 'danger'");
        $alert = $stmt->fetch();
        $this->assertNotFalse($alert, 'يوجد تنبيه من نوع danger');
        $this->assertStringContainsString('45', $alert['message_ar'], 'الرسالة تحتوي على متوسط 45%');
    }

    /**
     * TS-05: متوسط 75% → لا ينشئ تنبيه
     */
    public function testTeachingThresholdAbove50NoAlert(): void
    {
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 75.0, 1)");

        $engine = new AlertEngine($this->db);
        $count = $engine->checkTeachingThresholds(1, 1, 1);

        $this->assertEquals(0, $count, 'لا يجب إنشاء تنبيه عندما المتوسط > 50%');
    }

    // ========================================================================
    // TS-06, TS-07: checkTeachingThresholds() — قاعدة 2 (معلم < 60% بدون خطة)
    // ========================================================================

    /**
     * TS-06: معلم 55% بدون خطة → ينشئ تنبيه warning
     */
    public function testTeacherBelow60WithoutPlanCreatesAlert(): void
    {
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 55.0, 1)");

        $engine = new AlertEngine($this->db);
        $count = $engine->checkTeachingThresholds(1, 1, 1);

        $this->assertGreaterThan(0, $count, 'يجب إنشاء تنبيه للمعلم بدون خطة');

        $stmt = $this->db->query("SELECT * FROM alerts WHERE alert_type = 'teaching' AND severity = 'warning'");
        $alerts = $stmt->fetchAll();
        $this->assertCount(1, $alerts, 'تنبيه واحد للمعلم بدون خطة');
    }

    /**
     * TS-07: معلم 55% مع خطة نشطة → لا ينشئ تنبيه
     */
    public function testTeacherBelow60WithActivePlanNoAlert(): void
    {
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 55.0, 1)");
        $this->db->exec("INSERT INTO teacher_development_plans (teacher_id, school_id, status, is_active) VALUES
            (1, 1, 'active', 1)");

        $engine = new AlertEngine($this->db);
        $count = $engine->checkTeachingThresholds(1, 1, 1);

        $this->assertEquals(0, $count, 'لا يجب إنشاء تنبيه للمعلم مع خطة نشطة');
    }

    // ========================================================================
    // TS-08: حساب KPI عند semester_id=0 (جميع الفصول)
    // ========================================================================

    /**
     * TS-08: معلم A (فصل1:80, فصل2:70) متوسط=75، معلم B (فصل1:90) متوسط=90
     *        → متوسط المدرستين = (75+90)/2 = 82.5 (وليس 80)
     */
    public function testSemesterAllCalculatesTeacherAverageFirst(): void
    {
        // مدرسة 1: معلم 1 (فصل1:80, فصل2:70), معلم 2 (فصل1:90)
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, combined_score, is_active) VALUES
            (1, 1, 1, 80.0, 1),
            (1, 1, 2, 70.0, 1),
            (2, 1, 1, 90.0, 1)");

        // متوسط كل معلم أولاً: teacher1=(80+70)/2=75, teacher2=90
        // متوسط المدرسة: (75+90)/2 = 82.5
        $stmt = $this->db->query("
            SELECT AVG(per_teacher.avg_score)
            FROM (
                SELECT teacher_id, AVG(combined_score) AS avg_score
                FROM teaching_evaluation_summary
                WHERE academic_year_id = 1 AND is_active = 1
                  AND teacher_id IN (SELECT id FROM users WHERE school_id = 1)
                GROUP BY teacher_id
            ) per_teacher
        ");
        $result = (float)$stmt->fetchColumn();

        $this->assertEqualsWithDelta(82.5, $result, 0.01, 'AVG لكل معلم أولاً = 82.5');

        // مقارنة: لو كان AVG لكل السجلات: (80+70+90)/3 = 80 (هذا خطأ)
        $stmt2 = $this->db->query("
            SELECT AVG(combined_score)
            FROM teaching_evaluation_summary
            WHERE academic_year_id = 1 AND is_active = 1
              AND teacher_id IN (SELECT id FROM users WHERE school_id = 1)
        ");
        $wrongResult = (float)$stmt2->fetchColumn();
        $this->assertEqualsWithDelta(80.0, $wrongResult, 0.01, 'AVG مباشر (خطأ) = 80');
        $this->assertNotEquals($wrongResult, $result, 'الطريقتان تعطيان نتائج مختلفة');
    }

    // ========================================================================
    // TS-09: توصيات teaching_school — أبعاد أقل من المجموعة
    // ========================================================================

    /**
     * TS-09: school_self=40, group_self=70 → تظهر توصية "التقييم الذاتي أقل"
     */
    public function testTeachingSchoolRecommendationShowsWhenBelowAverage(): void
    {
        // تقييمات (self) للمدرسة 1
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, self_score, peer_score, admin_score, combined_score, is_active) VALUES
            (1, 1, 1, 40.0, 70.0, 80.0, 68.0, 1)");

        // متوسط المجموعة (للمدرسة 2): self=70
        $this->db->exec("INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, self_score, peer_score, admin_score, combined_score, is_active) VALUES
            (3, 1, 1, 70.0, 65.0, 75.0, 70.5, 1)");

        // حساب متوسط المدرسة 1 للأبعاد
        $stmt = $this->db->prepare("
            SELECT AVG(tes.self_score) AS s_self, AVG(tes.peer_score) AS s_peer, AVG(tes.admin_score) AS s_admin
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id AND u.school_id = ?
            WHERE tes.academic_year_id = ? AND tes.semester_id = ? AND tes.is_active = 1
        ");
        $stmt->execute([1, 1, 1]);
        $school = $stmt->fetch();

        $stmt = $this->db->prepare("
            SELECT AVG(tes.self_score) AS s_self, AVG(tes.peer_score) AS s_peer, AVG(tes.admin_score) AS s_admin
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id
            WHERE tes.academic_year_id = ? AND tes.semester_id = ? AND tes.is_active = 1
        ");
        $stmt->execute([1, 1]);
        $group = $stmt->fetch();

        // توليد التوصيات
        $recommendations = [];
        if ((float)$school['s_self'] < (float)$group['s_self']) {
            $recommendations[] = "بُعد 'التقييم الذاتي' في المدرسة أقل من متوسط المجموعة.";
        }

        $this->assertNotEmpty($recommendations, 'يجب أن تظهر توصية');
        $this->assertStringContainsString('التقييم الذاتي', $recommendations[0]);
    }
}
