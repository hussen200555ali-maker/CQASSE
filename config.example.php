<?php
/**
 * EQAS - نموذج ملف الإعدادات (Configuration Template)
 * نظام التقييم الشامل لجودة التعليم المدرسي
 *
 * الاستخدام:
 *   - التثبيت الموصى به: افتح معالج التثبيت setup.php وسينشئ config.php تلقائياً.
 *   - التثبيت اليدوي: انسخ هذا الملف إلى config.php ثم عدّل القيم أدناه.
 *
 * ⚠️ لا تُرفع config.php إلى Git أبداً — فهو يحتوي بيانات الاتصال وأسرار الجلسة.
 *     هذا النموذج (config.example.php) هو ما يُرفع فقط.
 *
 * ترتيب التضمين: config.php ? database.php ? helpers.php
 */

// [SEC-001] ترويسات الأمان - لا تُحذف
require_once __DIR__ . '/includes/security_headers.php';

// ============================================================================
// إعدادات البيئة
// ============================================================================
define('EQAS_ENV', 'development');       // development | production
define('EQAS_VERSION', '2.0.3');

// ============================================================================
// إعدادات قاعدة البيانات
// ============================================================================
// في الإنتاج: استخدم مستخدماً مخصّصاً (لا root) وكلمة مرور قوية، أو متغيرات بيئة.
define('DB_HOST', getenv('EQAS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('EQAS_DB_NAME') ?: 'eqas_db');
define('DB_USER', getenv('EQAS_DB_USER') ?: 'root');
define('DB_PASS', getenv('EQAS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// مسارات المشروع
// ============================================================================
define('EQAS_ROOT', __DIR__);
define('EQAS_INCLUDES', EQAS_ROOT . '/includes');
define('EQAS_PAGES', EQAS_ROOT . '/pages');
define('EQAS_ASSETS', EQAS_ROOT . '/assets');
define('EQAS_BACKUPS', EQAS_ROOT . '/backups');
define('EQAS_TEMP', EQAS_ROOT . '/temp');
define('EQAS_UPLOADS', EQAS_ROOT . '/uploads');

// ============================================================================
// روابط النظام
// ============================================================================
$eqasProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$eqasHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('EQAS_URL', $eqasProtocol . '://' . $eqasHost . '/cqasse');
define('EQAS_ASSETS_URL', EQAS_URL . '/assets');

// ============================================================================
// إعدادات الأمان
// ============================================================================
define('CSRF_ENABLED', true);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);       // 15 دقيقة بالثواني
define('SESSION_TIMEOUT', 3600);       // ساعة واحدة بالثواني
define('PASSWORD_MIN_LENGTH', 8);
// [SEC-002] ملح بصمة الجلسة - يجب تغييره في كل تثبيت
// أنشئ قيمة عشوائية 64 حرفاً على الأقل: openssl rand -hex 32
define('SESSION_FINGERPRINT_SALT', getenv('EQAS_SESSION_SALT') ?: 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING_64_CHARS_MINIMUM');

// ============================================================================
// إعدادات النسخ الاحتياطي
// ============================================================================
define('BACKUP_MAX_SIZE_MB', 50);
define('BACKUP_GZIP_ENABLED', true);

// ============================================================================
// إعدادات مؤشر جودة التعليم الافتراضية
// ============================================================================
define('EQI_REDISTRIBUTION', 'hybrid'); // hybrid | equal | proportional
define('ACADEMIC_PASS_RATE', 50);
define('BEHAVIOR_PASS_RATE', 60);
define('ATTENDANCE_PASS_RATE', 75);

// ============================================================================
// إعدادات عرض الأخطاء
// ============================================================================
if (EQAS_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ============================================================================
// المنطقة الزمنية
// ============================================================================
date_default_timezone_set('Asia/Aden');
