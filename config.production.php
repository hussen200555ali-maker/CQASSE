<?php
/**
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ملف الإعدادات الخاص بالإنتاج
 *
 * ⚠️  هذا هو ملف الإعدادات الخاص ببيئة الإنتاج (Production).
 * ⚠️  يجب استخدام هذا الملف بدلاً من config.php على الخوادم الحية (Live Servers).
 * ⚠️  لا تستخدم config.php في بيئة الإنتاج أبدًا — فهو مصمم للتطوير فقط.
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
 * ترتيب التحميل: config.production.php → database.php → helpers.php
 */

// [SEC-001] رؤوس الأمان المركزية - يجب تحميلها أولاً قبل أي مخرجات
require_once __DIR__ . '/includes/security_headers.php';

// ============================================================================
// إعدادات البيئة
// ============================================================================
define('EQAS_ENV', 'production');
define('EQAS_VERSION', '2.0.3');

// ============================================================================
// إعدادات قاعدة البيانات
// ============================================================================
// 🔴 تحذير حرج: يجب تغيير جميع القيم الافتراضية قبل النشر!
// 🔴 لن يعمل النظام بهذه القيم في بيئة الإنتاج
define('DB_HOST', getenv('EQAS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('EQAS_DB_NAME') ?: 'eqas_db');
define('DB_USER', getenv('EQAS_DB_USER') ?: 'eqas_app');
define('DB_PASS', getenv('EQAS_DB_PASS') ?: ''); // ⚠️ فارغ = غير آمن!
define('DB_CHARSET', 'utf8mb4');

// [SEC-003] فحص بيانات الاعتماد الافتراضية
if (DB_USER === 'root' || DB_PASS === '') {
    trigger_error(
        '🔴 CRITICAL: Default DB credentials detected in production! ' .
        'Set EQAS_DB_USER and EQAS_DB_PASS environment variables. ' .
        'Never use root/empty password in production.',
        E_USER_ERROR
    );
}

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
define('EQAS_URL', 'https://your-production-domain.com/cqasse');
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
// استخدم: openssl rand -hex 32
// 🔴 تحذير: القيمة الافتراضية أدناه هي ملح معروف - يجب تغييرها!
define('SESSION_FINGERPRINT_SALT', getenv('EQAS_SESSION_SALT') ?: 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING_64_CHARS_MINIMUM');

if (SESSION_FINGERPRINT_SALT === 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING_64_CHARS_MINIMUM') {
    trigger_error(
        '🔴 CRITICAL: Default SESSION_FINGERPRINT_SALT detected! ' .
        'Generate a secure salt: openssl rand -hex 32 ' .
        'Then set EQAS_SESSION_SALT environment variable.',
        E_USER_ERROR
    );
}

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
error_reporting(0);
ini_set('display_errors', 0);

// ============================================================================
// المنطقة الزمنية
// ============================================================================
date_default_timezone_set('Asia/Aden');
