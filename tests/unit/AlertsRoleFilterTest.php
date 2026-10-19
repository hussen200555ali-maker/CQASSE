<?php
/**
 * ============================================================
 * EQAS - اختبار تصفية التنبيهات حسب الدور
 * AlertEngine Role-Based Filtering Test
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';

class AlertsRoleFilterTest {
    private $db;
    private $alertEngine;
    private $passed = 0;
    private $failed = 0;

    public function __construct($db) {
        $this->db = $db;
        $this->alertEngine = new AlertEngine($db);
    }

    /**
     * RUN ALL TESTS
     */
    public function runAll() {
        echo "\n========== اختبار تصفية التنبيهات حسب الدور ==========\n\n";

        $this->testGroupAdminFilter();
        $this->testSchoolAdminFilter();
        $this->testTeacherFilter();
        $this->testParentFilter();
        $this->testCanViewAlertType();
        $this->testApplyRoleFilter();
        $this->testUnreadCountGroupAdmin();
        $this->testUnreadCountSchoolAdmin();
        $this->testAlertStatsGroupAdmin();
        $this->testAlertStatsSchoolAdmin();
        $this->testUnreadCountTeacher();
        $this->testUnreadCountParent();

        echo "\n========== النتيجة ==========\n";
        echo "✅ نجحت: {$this->passed}\n";
        echo "❌ فشلت: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * ALERT-ROLE-01: مدير المجموعة يرى تنبيهات استراتيجية فقط
     */
    private function testGroupAdminFilter() {
        echo "Test ALERT-ROLE-01: فلتر مدير المجموعة\n";

        $filter = $this->alertEngine->getRoleBasedFilter('group_admin', 1);

        // يجب أن يرى أنواع استراتيجية فقط
        $expectedTypes = ['eqi', 'climate', 'teaching', 'improvement_plan'];
        
        if ($filter['alert_types'] === $expectedTypes) {
            echo "  ✅ PASS: يرى تنبيهات استراتيجية فقط\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: لم يرى أنواع استراتيجية فقط\n";
            echo "     Expected: " . implode(', ', $expectedTypes) . "\n";
            echo "     Got: " . implode(', ', $filter['alert_types']) . "\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-02: مدير المدرسة يرى كل التنبيهات
     */
    private function testSchoolAdminFilter() {
        echo "Test ALERT-ROLE-02: فلتر مدير المدرسة\n";

        $filter = $this->alertEngine->getRoleBasedFilter('school_admin', 1);

        // يجب أن يرى كل الأنواع (all)
        if ($filter['alert_types'] === 'all') {
            echo "  ✅ PASS: يرى كل التنبيهات\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: لم يرَ كل التنبيهات\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-03: المعلم يرى تنبيهات تشغيلية فقط
     */
    private function testTeacherFilter() {
        echo "Test ALERT-ROLE-03: فلتر المعلم\n";

        $filter = $this->alertEngine->getRoleBasedFilter('teacher', 5);

        // يجب أن يرى أنواع تشغيلية فقط
        $expectedTypes = ['academic', 'behavior', 'attendance', 'promotion'];
        
        if ($filter['alert_types'] === $expectedTypes) {
            echo "  ✅ PASS: يرى تنبيهات تشغيلية فقط\n";
            
            // التحقق من وجود شرط فلترة الطلاب حسب الشعبة
            if (strpos(implode(' ', $filter['conditions']), 'teacher_id') !== false) {
                echo "  ✅ PASS: يحتوي على فلتر الشعبة\n";
                $this->passed++;
            } else {
                echo "  ❌ FAIL: لا يحتوي على فلتر الشعبة\n";
                $this->failed++;
            }
        } else {
            echo "  ❌ FAIL: لم يرى أنواع تشغيلية فقط\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-04: ولي الأمر يرى تنبيهات أبنائه فقط
     */
    private function testParentFilter() {
        echo "Test ALERT-ROLE-04: فلتر ولي الأمر\n";

        $filter = $this->alertEngine->getRoleBasedFilter('parent', 10);

        // يجب أن يرى أنواع تشغيلية فقط
        $expectedTypes = ['academic', 'behavior', 'attendance', 'promotion'];
        
        if ($filter['alert_types'] === $expectedTypes) {
            echo "  ✅ PASS: يرى تنبيهات تشغيلية فقط\n";
            
            // التحقق من وجود فلتر parent_students
            if (strpos(implode(' ', $filter['conditions']), 'parent_students') !== false) {
                echo "  ✅ PASS: يحتوي على فلتر الأبناء\n";
                $this->passed++;
            } else {
                echo "  ❌ FAIL: لا يحتوي على فلتر الأبناء\n";
                $this->failed++;
            }
        } else {
            echo "  ❌ FAIL: لم يرى أنواع تشغيلية فقط\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-05: التحقق من canViewAlertType
     */
    private function testCanViewAlertType() {
        echo "Test ALERT-ROLE-05: التحقق من أنواع التنبيهات لكل دور\n";

        // group_admin يرى نوع eqi (استراتيجي)
        if ($this->alertEngine->canViewAlertType('group_admin', 'eqi')) {
            echo "  ✅ PASS: group_admin يرى eqi\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: group_admin لا يرى eqi\n";
            $this->failed++;
        }

        // group_admin لا يرى نوع academic (تشغيلي)
        if (!$this->alertEngine->canViewAlertType('group_admin', 'academic')) {
            echo "  ✅ PASS: group_admin لا يرى academic\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: group_admin يرى academic\n";
            $this->failed++;
        }

        // teacher يرى نوع academic (تشغيلي)
        if ($this->alertEngine->canViewAlertType('teacher', 'academic')) {
            echo "  ✅ PASS: teacher يرى academic\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: teacher لا يرى academic\n";
            $this->failed++;
        }

        // teacher لا يرى نوع eqi (استراتيجي)
        if (!$this->alertEngine->canViewAlertType('teacher', 'eqi')) {
            echo "  ✅ PASS: teacher لا يرى eqi\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: teacher يرى eqi\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-07: getUnreadCount لمدير المجموعة — تنبيهات استراتيجية فقط
     */
    public function testUnreadCountGroupAdmin() {
        echo "Test ALERT-ROLE-07: UnreadCount لمدير المجموعة\n";

        // تنظيف بيانات الاختبارات السابقة
        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM users WHERE id >= 100");

        // الأدوار موجودة مسبقاً من الـ bootstrap: group_admin=1, school_admin=2, teacher=3, parent=4
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (100, 'ga', 'group_admin', 1, NULL)");
        $this->db->exec("INSERT INTO alerts (school_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 'eqi', 'warning', 'EQI Alert', 'test', 0, 0),
            (1, 'academic', 'danger', 'Academic Alert', 'test', 0, 0),
            (1, 'climate', 'info', 'Climate Alert', 'test', 0, 0),
            (1, 'attendance', 'warning', 'Attendance Alert', 'test', 0, 0)");

        $count = $this->alertEngine->getUnreadCount(100);

        // group_admin يرى فقط الاستراتيجية: eqi + climate = 2
        if ($count === 2) {
            echo "  ✅ PASS: يرى 2 تنبيه استراتيجي فقط (eqi, climate)\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: العدد المتوقع 2 ولكن النتيجة $count\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-08: getUnreadCount لمدير المدرسة — كل التنبيهات
     */
    public function testUnreadCountSchoolAdmin() {
        echo "Test ALERT-ROLE-08: UnreadCount لمدير المدرسة\n";

        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM users WHERE id >= 100");
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (101, 'sa', 'school_admin', 2, 1)");
        $this->db->exec("INSERT INTO alerts (school_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 'eqi', 'warning', 'EQI Alert', 'test', 0, 0),
            (1, 'academic', 'danger', 'Academic Alert', 'test', 0, 0)");

        $count = $this->alertEngine->getUnreadCount(101);

        // school_admin يرى كل الأنواع = 2 (بشرط تكون في نفس المدرسة)
        if ($count === 2) {
            echo "  ✅ PASS: يرى 2 تنبيه (eqi + academic)\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: العدد المتوقع 2 ولكن النتيجة $count\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-09: getAlertStats لمدير المجموعة — أنواع استراتيجية فقط
     */
    public function testAlertStatsGroupAdmin() {
        echo "Test ALERT-ROLE-09: AlertStats لمدير المجموعة\n";

        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM users WHERE id >= 100");
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (102, 'ga2', 'group_admin', 1, NULL)");
        $this->db->exec("INSERT INTO alerts (school_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 'eqi', 'warning', 'EQI', 'test', 0, 0),
            (2, 'teaching', 'info', 'Teaching', 'test', 0, 0),
            (1, 'academic', 'danger', 'Academic', 'test', 0, 0)");

        $stats = $this->alertEngine->getAlertStats(null, 'group_admin', 102);

        // يجب أن يكون المجموع 2 (eqi + teaching فقط)
        // و academic غير موجود في الإحصائيات
        if ($stats['total'] === 2) {
            echo "  ✅ PASS: إجمالي التنبيهات الاستراتيجية = 2\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: المتوقع 2 ولكن النتيجة {$stats['total']}\n";
            $this->failed++;
        }

        if (isset($stats['by_type']['eqi']) && isset($stats['by_type']['teaching']) && !isset($stats['by_type']['academic'])) {
            echo "  ✅ PASS: by_type يحتوي على eqi و teaching فقط\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: by_type لا يحتوي على الأنواع الاستراتيجية فقط\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-10: getAlertStats لمدير المدرسة — كل الأنواع
     */
    public function testAlertStatsSchoolAdmin() {
        echo "Test ALERT-ROLE-10: AlertStats لمدير المدرسة\n";

        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM users WHERE id >= 100");
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (103, 'sa2', 'school_admin', 2, 1)");
        $this->db->exec("INSERT INTO alerts (school_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 'eqi', 'warning', 'EQI', 'test', 0, 0),
            (1, 'academic', 'danger', 'Academic', 'test', 0, 0)");

        $stats = $this->alertEngine->getAlertStats(1, 'school_admin', 103);

        // school_admin يرى كل الأنواع = 2
        if ($stats['total'] === 2) {
            echo "  ✅ PASS: إجمالي التنبيهات = 2\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: المتوقع 2 ولكن النتيجة {$stats['total']}\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-11: getUnreadCount للمعلم — تنبيهات تشغيلية فقط
     */
    public function testUnreadCountTeacher() {
        echo "Test ALERT-ROLE-11: UnreadCount للمعلم\n";

        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM student_enrollments");
        $this->db->exec("DELETE FROM subject_teachers");
        $this->db->exec("DELETE FROM classes");
        $this->db->exec("DELETE FROM students");
        $this->db->exec("DELETE FROM users WHERE id >= 100");
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (104, 'tch', 'teacher', 3, 1)");
        // إنشاء فصل وطالب وتسجيل
        $this->db->exec("INSERT INTO classes (id, name_ar, school_id, teacher_id) VALUES (50, 'شعبة أ', 1, 104)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, student_number) VALUES (60, 1, 'طالب اختبار', 'STU60')");
        $this->db->exec("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, status, is_active) VALUES (60, 50, 1, 'enrolled', 1)");
        $this->db->exec("INSERT INTO subject_teachers (teacher_id, class_id, classroom_id, school_id, academic_year_id, is_active) VALUES (104, 50, 50, 1, 1, 1)");
        // تنبيهات تشغيلية + استراتيجية — المعلم يرى التشغيلية فقط
        $this->db->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 60, 'academic', 'danger', 'Academic', 'test', 0, 0),
            (1, 60, 'eqi', 'warning', 'EQI', 'test', 0, 0)");

        $count = $this->alertEngine->getUnreadCount(104);

        // teacher يرى فقط التشغيلية = 1
        if ($count === 1) {
            echo "  ✅ PASS: يرى تنبيهاً تشغيلياً واحداً فقط\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: العدد المتوقع 1 ولكن النتيجة $count\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-12: getUnreadCount لولي الأمر — تنبيهات تشغيلية فقط
     */
    public function testUnreadCountParent() {
        echo "Test ALERT-ROLE-12: UnreadCount لولي الأمر\n";

        $this->db->exec("DELETE FROM alerts");
        $this->db->exec("DELETE FROM parent_students");
        $this->db->exec("DELETE FROM students");
        $this->db->exec("DELETE FROM users WHERE id >= 100");
        $this->db->exec("INSERT INTO users (id, username, role, role_id, school_id) VALUES (105, 'par', 'parent', 4, 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, student_number) VALUES (61, 1, 'طالب 2', 'STU61')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id, relationship, is_active) VALUES (105, 61, 'father', 1)");
        // تنبيه تشغيلي + استراتيجي مرتبط بابن ولي الأمر
        $this->db->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved) VALUES
            (1, 61, 'attendance', 'warning', 'غياب', 'test', 0, 0),
            (1, 61, 'teaching', 'info', 'Teaching', 'test', 0, 0)");

        $count = $this->alertEngine->getUnreadCount(105);

        // parent يرى فقط التشغيلية = 1 (attendance فقط)
        if ($count === 1) {
            echo "  ✅ PASS: يرى تنبيهاً تشغيلياً واحداً فقط\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: العدد المتوقع 1 ولكن النتيجة $count\n";
            $this->failed++;
        }
    }

    /**
     * ALERT-ROLE-06: اختبار applyRoleFilter
     */
    private function testApplyRoleFilter() {
        echo "Test ALERT-ROLE-06: تطبيق الفلتر على الاستعلام\n";

        $filters = [
            'school_id' => 1,
            'alert_type' => '',
            'severity' => '',
            'where' => [],
            'params' => [],
        ];

        // تطبيق فلتر المعلم
        $filtered = $this->alertEngine->applyRoleFilter($filters, 'teacher', 5, 2025);

        // التحقق من إضافة الشرط
        if (!empty($filtered['where'])) {
            echo "  ✅ PASS: تم إضافة شروط الفلترة\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: لم يتم إضافة شروط الفلترة\n";
            $this->failed++;
        }

        // التحقق من إضافة المعاملات (أياً كان اسمها)
        if (!empty($filtered['params'])) {
            echo "  ✅ PASS: تم إضافة معاملات الدور\n";
            $this->passed++;
        } else {
            echo "  ❌ FAIL: لم يتم إضافة معاملات الدور\n";
            $this->failed++;
        }
    }
}

// تشغيل الاختبار
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0])) {
    require_once __DIR__ . '/../../includes/alerts.php';

    // إنشاء قاعدة بيانات SQLite في الذاكرة حقيقية للاختبارات
    $testDb = createTestDB();
    $test = new AlertsRoleFilterTest($testDb);
    $success = $test->runAll();
    exit($success ? 0 : 1);
}