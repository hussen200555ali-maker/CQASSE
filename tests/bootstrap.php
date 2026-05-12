<?php
/**
 * EQAS Test Bootstrap
 * يجهز بيئة الاختبار بما في ذلك اتصال قاعدة البيانات الوهمي
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// تعريف ثوابت النظام
define('EQAS_VERSION', '2.0.3');
define('EQAS_URL', 'http://localhost/eqas');
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
        name_ar TEXT, name_en TEXT, form_key TEXT, description TEXT,
        is_active INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assessment_criteria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER, name_ar TEXT, name_en TEXT, sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assessment_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        criteria_id INTEGER, form_id INTEGER, text_ar TEXT, text_en TEXT,
        tool_type TEXT, weight REAL DEFAULT 1.0, is_inverse INTEGER DEFAULT 0,
        max_value REAL, sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1
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
        name_ar TEXT, name_en TEXT, code TEXT, school_type TEXT,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS eqi_dimension_weights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dimension_name TEXT UNIQUE, dimension_name_ar TEXT,
        default_weight REAL, is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS equi_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, academic_year_id INTEGER, semester_id INTEGER,
        academic_score REAL, teaching_score REAL, climate_score REAL,
        behavior_score REAL, skills_score REAL, attendance_score REAL,
        overall_eqi REAL, calculated_by INTEGER, calculated_at TEXT,
        is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, academic_year_id, semester_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE, setting_value TEXT, value_type TEXT DEFAULT 'string',
        category TEXT, description_ar TEXT, sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS school_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id INTEGER, setting_id INTEGER, override_value TEXT,
        updated_by INTEGER, updated_at TEXT, is_active INTEGER DEFAULT 1,
        UNIQUE(school_id, setting_id)
    )");

    // جداول الطلاب والتسجيلات
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT, password_hash TEXT, full_name_ar TEXT,
        email TEXT, role TEXT, school_id INTEGER,
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

    // جداول تقييم التدريس
    $db->exec("CREATE TABLE IF NOT EXISTS subject_teachers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER, subject_id INTEGER, class_id INTEGER,
        school_id INTEGER DEFAULT 1, academic_year_id INTEGER, is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS teaching_self_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER DEFAULT 1, semester_id INTEGER DEFAULT 1,
        overall_score REAL DEFAULT 0,
        tool_type TEXT, numeric_value REAL, is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS teaching_peer_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER, evaluatee_id INTEGER DEFAULT 0, evaluator_id INTEGER DEFAULT 0,
        school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER DEFAULT 1, semester_id INTEGER DEFAULT 1,
        overall_score REAL DEFAULT 0,
        tool_type TEXT, numeric_value REAL, is_active INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS teaching_admin_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER, evaluatee_id INTEGER DEFAULT 0,
        school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER DEFAULT 1, semester_id INTEGER DEFAULT 1,
        overall_score REAL DEFAULT 0,
        tool_type TEXT, numeric_value REAL, is_active INTEGER DEFAULT 1
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

    // جداول الحضور
    $db->exec("CREATE TABLE IF NOT EXISTS attendance_summaries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER, school_id INTEGER DEFAULT 1,
        academic_year_id INTEGER, semester_id INTEGER,
        attendance_rate REAL, is_active INTEGER DEFAULT 1
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
