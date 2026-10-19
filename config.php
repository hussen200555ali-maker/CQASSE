<?php
/**
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ملف الإعدادات الرئيسي
 *
 * يحتوي هذا الملف على جميع إعدادات النظام الأساسية بما في ذلك:
 * - إعدادات البيئة
 * - إعدادات قاعدة البيانات
 * - مسارات الملفات
 * - عناوين URL
 * - إعدادات الأمان
 * - إعدادات النسخ الاحتياطي
 * - إعدادات مؤشر جودة التعليم
 *
 * ترتيب التحميل: config.php → database.php → helpers.php
 */

// [إصلاح v2.0.3] تحذير الإنتاج
$serverName = $_SERVER['SERVER_NAME'] ?? '';
$isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1']) 
           || preg_match('/^192\.168\./', $serverName);
if (!$isLocal && !getenv('EQAS_DEV_MODE')) {
    trigger_error('⚠️ WARNING: Using development config in production! Use config.production.php instead.', E_USER_WARNING);
}

// [SEC-001] رؤوس الأمان المركزية - يجب تحميلها أولاً قبل أي مخرجات
require_once __DIR__ . '/includes/security_headers.php';

// ============================================================================
// إعدادات البيئة
// ============================================================================
define('EQAS_ENV', 'development');
define('EQAS_VERSION', '2.0.3');

// ============================================================================
// إعدادات قاعدة البيانات
// ============================================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'eqas_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// مسارات الملفات
// ============================================================================
define('EQAS_ROOT', __DIR__);
define('EQAS_INCLUDES', EQAS_ROOT . '/includes');
define('EQAS_PAGES', EQAS_ROOT . '/pages');
define('EQAS_ASSETS', EQAS_ROOT . '/assets');
define('EQAS_BACKUPS', EQAS_ROOT . '/backups');
define('EQAS_TEMP', EQAS_ROOT . '/temp');
define('EQAS_UPLOADS', EQAS_ROOT . '/uploads');

// ============================================================================
// عناوين URL
// ============================================================================
$eqasProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$eqasHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('EQAS_URL', $eqasProtocol . '://' . $eqasHost . '/eqas');
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
// استخدم قيمة عشوائية 64 حرفاً على الأقل: openssl rand -hex 32
define('SESSION_FINGERPRINT_SALT', 'eac133542bb160125a8d75b2868ab1ab38741c5f4c2c55b951e0d919f8e8d438');

// ============================================================================
// إعدادات النسخ الاحتياطي
// ============================================================================
define('BACKUP_MAX_SIZE_MB', 50);
define('BACKUP_GZIP_ENABLED', true);

// ============================================================================
// إعدادات مؤشر جودة التعليم الافتراضية
// ============================================================================
define('EQI_REDISTRIBUTION', 'hybrid'); // hybrid (50% نسبي + 50% متساوٍ) أو equal أو proportional
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
