<?php
/**
 * EQAS Test Bootstrap
 * يجهز بيئة الاختبار بما في ذلك اتصال قاعدة البيانات الوهمي
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// تعريف ثوابت النظام
define('EQAS_VERSION', '2.0.3');
define('EQAS_URL', 'http://localhost/cqasse');
define('SESSION_TIMEOUT', 3600);
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);
define('ACADEMIC_PASS_RATE', 50);
define('BEHAVIOR_PASS_RATE', 60);
define('ATTENDANCE_PASS_RATE', 75);
define('EQI_REDISTRIBUTION', 'hybrid');
define('CSRF_ENABLED', true);
define('EQAS_ROOT', __DIR__ . '/..');
define('EQAS_INCLUDES', EQAS_ROOT . '/includes');

// تحميل الملفات المطلوبة
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/assessment_engine.php';
require_once __DIR__ . '/../includes/eqi.php';
require_once __DIR__ . '/../includes/permission_middleware.php';

/**
 * إنشاء اتصال PDO وهمي للاختبارات التي لا تحتاج قاعدة بيانات حقيقية
 * يستخدم SQLite في الذاكرة
 */
function createTestDB(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // توافق MySQL → SQLite: دالة NOW()
    $db->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); }, 0);
    $db->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); }, 0);
    $db->sqliteCreateFunction('VALUES', function($v) { return $v; }, 1);

    // إنشاء الجداول المطلوبة للاختبار
    $db->exec("CREATE TABLE IF NOT EXISTS assessment_forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_key TEXT NOT NULL,
        name_ar TEXT NOT NULL, name_en TEXT,
        category TEXT NOT NULL DEFAULT 'behavior',
        target TEXT NOT NULL DEFAULT 'student',
        description TEXT,
        is_system INTEGER DEFAULT 0,
        is_default INTEGER DEFAULT 0, version TEXT,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assessment_structure (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        parent_id INTEGER DEFAULT NULL,
        type TEXT NOT NULL DEFAULT 'criteria',
        name_ar TEXT, name_en TEXT,
        text_ar TEXT, text_en TEXT,
        tool_type TEXT DEFAULT NULL,
        weight REAL DEFAULT 1.0,
        is_inverse INTEGER DEFAULT 0,
        max_value REAL DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assessment_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER, item_id INTEGER, student_id INTEGER, teacher_id INTEGER,
        class_id INTEGER, academic_year_id INTEGER, semester_id INTEGER, school_id INTEGER,
        numeric_value REAL, text_value TEXT, responded_by INTEGER,
        response_date TEXT, is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS semesters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        academic_year_id INTEGER, name_ar TEXT, name_en TEXT,
        lock_level TEXT DEFAULT 'none', locked_at TEXT, locked_by INTEGER,
        is_active INTEGER DEFAULT 1, start_date TEXT, end_date TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS academic_years (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, start_date TEXT, end_date TEXT,
        status TEXT DEFAULT 'active',
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS schools (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_ar TEXT, name_en TEXT, code TEXT, reference_code TEXT, school_type TEXT,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS school_context (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER UNIQUE,
        academic_year_id INTEGER DEFAULT NULL,
        location_type TEXT, total_students INTEGER, student_teacher_ratio REAL,
        socioeconomic_index REAL, parent_education_level REAL,
        students_with_special_needs_pct REAL, infrastructure_score REAL,
        student_mobility_rate REAL, challenge_index REAL,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS eqi_dimension_weights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dimension_name TEXT UNIQUE, dimension_name_ar TEXT,
        default_weight REAL, is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS eqi_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        academic_score REAL, teaching_score REAL, climate_score REAL,
        behavior_score REAL, skills_score REAL, attendance_score REAL,
        overall_eqi REAL, weights_json TEXT, challenge_index REAL, adjusted_eqi REAL,
        calculated_by INTEGER, calculated_at TEXT,
        is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, academic_year_id, semester_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL DEFAULT '',
        setting_key TEXT UNIQUE, setting_value TEXT,
        value_type TEXT DEFAULT 'string',
        name_ar TEXT NOT NULL DEFAULT '', description_ar TEXT,
        is_system INTEGER DEFAULT 1,
        school_id INTEGER DEFAULT NULL,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS school_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, setting_id INTEGER, override_value TEXT,
        updated_by INTEGER, updated_at TEXT, is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, setting_id)
    )");

    // جداول الطلاب والتسجيلات
    $db->exec("CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name_ar TEXT
    )");
    $db->exec("INSERT OR IGNORE INTO roles (id, name, display_name_ar) VALUES
        (1, 'group_admin', 'مدير المجموعة'),
        (2, 'school_admin', 'مدير المدرسة'),
        (3, 'teacher', 'معلم'),
        (4, 'parent', 'ولي أمر'),
        (5, 'student', 'طالب')
    ");
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT, password_hash TEXT, full_name_ar TEXT,
        email TEXT, role TEXT, role_id INTEGER DEFAULT 1, school_id INTEGER,
        must_change_password INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, full_name_ar TEXT, student_number TEXT,
        gender TEXT, is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_ar TEXT, academic_year_id INTEGER, school_id INTEGER,
        grade_level_id INTEGER, teacher_id INTEGER, capacity INTEGER DEFAULT 30,
        is_active INTEGER DEFAULT 1
    )");

    // جداول الدرجات الأكاديمية
    $db->exec("CREATE TABLE IF NOT EXISTS grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER, semester_id INTEGER,
        grade_value REAL, max_value REAL DEFAULT 100,
        is_active INTEGER DEFAULT 1
    )");

    // جدول التنبيهات للاختبارات
    $db->exec("CREATE TABLE IF NOT EXISTS alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER NOT NULL DEFAULT 1,
        student_id INTEGER DEFAULT NULL,
        user_id INTEGER DEFAULT NULL,
        alert_type TEXT NOT NULL,
        severity TEXT NOT NULL DEFAULT 'warning',
        title_ar TEXT NOT NULL,
        message_ar TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        read_at TEXT DEFAULT NULL,
        is_resolved INTEGER DEFAULT 0,
        resolved_by INTEGER DEFAULT NULL,
        resolved_at TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول تسجيل الطلاب في الفصول
    $db->exec("CREATE TABLE IF NOT EXISTS student_enrollments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        class_id INTEGER NOT NULL,
        academic_year_id INTEGER NOT NULL DEFAULT 1,
        enrollment_date TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'enrolled',
        is_active INTEGER DEFAULT 1
    )");

    // جداول تقييم التدريس
    $db->exec("CREATE TABLE IF NOT EXISTS subject_teachers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER, subject_id INTEGER, class_id INTEGER,
        classroom_id INTEGER DEFAULT NULL,
        school_id INTEGER DEFAULT 1, academic_year_id INTEGER, is_active INTEGER DEFAULT 1
    )");
    // جدول ملخص تقييم التدريس
    $db->exec("CREATE TABLE IF NOT EXISTS teaching_evaluation_summary (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER NOT NULL,
        academic_year_id INTEGER NOT NULL,
        semester_id INTEGER NOT NULL,
        self_score REAL DEFAULT NULL,
        peer_score REAL DEFAULT NULL,
        admin_score REAL DEFAULT NULL,
        combined_score REAL DEFAULT NULL,
        adjusted_score REAL DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(teacher_id, academic_year_id, semester_id)
    )");

    // جداول المناخ المدرسي
    $db->exec("CREATE TABLE IF NOT EXISTS climate_surveys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER DEFAULT 1, academic_year_id INTEGER DEFAULT 1,
        semester_id INTEGER DEFAULT 1,
        overall_score REAL DEFAULT 0,
        tool_type TEXT, numeric_value REAL, is_active INTEGER DEFAULT 1
    )");

    // جداول السلوك
    $db->exec("CREATE TABLE IF NOT EXISTS behavior_scores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, class_id INTEGER,
        academic_year_id INTEGER, semester_id INTEGER, school_id INTEGER DEFAULT 1,
        starting_points REAL DEFAULT 100, earned_points REAL DEFAULT 0,
        deducted_points REAL DEFAULT 0, current_points REAL DEFAULT 100,
        current_percentage REAL DEFAULT 100,
        is_passing INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS behavior_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_ar TEXT, type TEXT, points REAL, is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS behavior_incidents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, class_id INTEGER, category_id INTEGER,
        school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        description TEXT, points REAL, recorded_by INTEGER, incident_date TEXT,
        reported_by INTEGER, is_active INTEGER DEFAULT 1
    )");

    // جداول المهارات
    $db->exec("CREATE TABLE IF NOT EXISTS skills_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER DEFAULT 1, semester_id INTEGER DEFAULT 1,
        score REAL DEFAULT 0,
        tool_type TEXT, numeric_value REAL, is_active INTEGER DEFAULT 1
    )");

    // جداول الحضور (v_attendance_summary view mocked as table for SQLite)
    $db->exec("CREATE TABLE IF NOT EXISTS v_attendance_summary (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER, semester_id INTEGER,
        attendance_rate REAL, is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS attendance_summaries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER, semester_id INTEGER,
        attendance_rate REAL, is_active INTEGER DEFAULT 1
    )");

    // جداول التقييم الموحدة
    $db->exec("CREATE TABLE IF NOT EXISTS evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        eval_type TEXT NOT NULL,
        teacher_id INTEGER, student_id INTEGER, class_id INTEGER,
        school_id INTEGER DEFAULT 1, academic_year_id INTEGER, semester_id INTEGER,
        self_score REAL, peer_score REAL, admin_score REAL,
        combined_score REAL, adjusted_score REAL,
        score REAL, current_percentage REAL,
        starting_points REAL, earned_points REAL, deducted_points REAL,
        is_passing INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS surveys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        survey_type TEXT NOT NULL,
        school_id INTEGER DEFAULT 1, academic_year_id INTEGER, semester_id INTEGER,
        respondent_type TEXT, overall_score REAL DEFAULT 0,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // جداول الترفيع
    $db->exec("CREATE TABLE IF NOT EXISTS promotions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        academic_score REAL, behavior_score REAL, attendance_score REAL,
        academic_pass INTEGER DEFAULT 1, behavior_pass INTEGER DEFAULT 1, attendance_pass INTEGER DEFAULT 1,
        decision TEXT, status TEXT DEFAULT 'proposed',
        approved_by INTEGER, approved_at TEXT,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS promotion_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        criterion TEXT, override_reason TEXT,
        approved_by INTEGER, approved_at TEXT,
        is_active INTEGER DEFAULT 1
    )");

    // جداول الإقرارات (موحدة في parent_students)
    $db->exec("CREATE TABLE IF NOT EXISTS parent_students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NOT NULL, student_id INTEGER NOT NULL,
        relationship TEXT DEFAULT 'father',
        ack_grade INTEGER DEFAULT NULL, ack_behavior INTEGER DEFAULT NULL,
        ack_attendance INTEGER DEFAULT NULL, ack_promotion INTEGER DEFAULT NULL,
        last_ack_at TEXT DEFAULT NULL,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // جداول التقييم الأكاديمي
    $db->exec("CREATE TABLE IF NOT EXISTS assessment_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_ar TEXT, name_en TEXT, code TEXT,
        weight_default REAL, sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");
    $db->exec("INSERT OR IGNORE INTO assessment_types (id, name_ar, name_en, code, weight_default, sort_order, is_active) VALUES
        (1, 'شهري', 'Monthly', 'monthly', 20.00, 1, 1),
        (2, 'واجب', 'Homework', 'homework', 10.00, 2, 1),
        (3, 'اختبار قصير', 'Quiz', 'quiz', 10.00, 3, 1),
        (4, 'نهائي', 'Final', 'final', 50.00, 4, 1),
        (5, 'مشروع', 'Project', 'project', 10.00, 5, 1)
    ");

    $db->exec("CREATE TABLE IF NOT EXISTS tests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_id INTEGER, class_id INTEGER, assessment_type_id INTEGER,
        academic_year_id INTEGER, semester_id INTEGER,
        title_ar TEXT, max_grade REAL, test_date TEXT,
        status TEXT DEFAULT 'draft', created_by INTEGER,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS subject_weight_distribution (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_grade_year_id INTEGER, assessment_type_id INTEGER,
        weight REAL, academic_year_id INTEGER,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS subject_grade_years (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_id INTEGER, grade_level_id INTEGER, academic_year_id INTEGER,
        is_active INTEGER DEFAULT 1
    )");

    // جداول EQI الجديدة
    $db->exec("CREATE TABLE IF NOT EXISTS eqi_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        academic_score REAL, teaching_score REAL, climate_score REAL,
        behavior_score REAL, skills_score REAL, attendance_score REAL,
        overall_eqi REAL, weights_json TEXT, challenge_index REAL, adjusted_eqi REAL,
        calculated_by INTEGER, calculated_at TEXT,
        is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, academic_year_id, semester_id)
    )");

    // alias for backward compatibility (tests use equi_results)
    $db->exec("CREATE TABLE IF NOT EXISTS equi_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        academic_score REAL, teaching_score REAL, climate_score REAL,
        behavior_score REAL, skills_score REAL, attendance_score REAL,
        overall_eqi REAL, weights_json TEXT, challenge_index REAL, adjusted_eqi REAL,
        calculated_by INTEGER, calculated_at TEXT,
        is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, academic_year_id, semester_id)
    )");

    // إدراج بيانات افتراضية
    $db->exec("INSERT INTO eqi_dimension_weights (dimension_name, dimension_name_ar, default_weight) VALUES
        ('academic', 'الأداء الأكاديمي', 35),
        ('teaching', 'جودة التدريس', 25),
        ('climate', 'المناخ المدرسي', 15),
        ('behavior', 'السلوك', 10),
        ('skills', 'المهارات', 10),
        ('attendance', 'الحضور', 5)
    ");

    return $db;
}
