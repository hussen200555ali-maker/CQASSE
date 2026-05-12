<?php
/**
 * ============================================================
 * EQAS - إدارة الجلسات الآمنة
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * إدارة جلسات PHP مع ميزات أمنية متقدمة:
 * - انتهاء صلاحية الجلسة التلقائي
 * - بصمة الجلسة لمنع اختطاف الجلسة
 * - تجديد معرف الجلسة
 * - إعدادات أمنية مُحسّنة للكوكيز
 * - دوال مساعدة للتحقق من تسجيل الدخول والأدوار
 * ============================================================
 */

// ============================================================================
// بدء الجلسة الآمنة
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // إعدادات أمان الكوكيز
    ini_set('session.cookie_httponly', 1);          // منع الوصول عبر JavaScript
    ini_set('session.use_strict_mode', 1);           // رفض معرفات الجلسة غير المهيأة
    ini_set('session.cookie_samesite', 'Lax');       // حماية من CSRF
    ini_set('session.use_only_cookies', 1);          // استخدام الكوكيز فقط
    ini_set('session.gc_maxlifetime', 7200);         // أقصى عمر للجلسة (ساعتان)

    // تفعيل الكوكي الآمن في بيئة HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }

    // ضبط اسم الجلسة
    session_name('EQAS_SESSION');

    // بدء الجلسة
    session_start();
}

// ============================================================================
// التحقق من انتهاء صلاحية الجلسة
// ============================================================================
$sessionTimeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600; // ساعة واحدة افتراضياً

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $sessionTimeout) {
    // انتهت صلاحية الجلسة - تدمير وإعادة توجيه
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['flash_message'] = 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . (defined('EQAS_URL') ? EQAS_URL : '') . '/login.php');
    exit;
}

// تحديث وقت آخر نشاط
$_SESSION['last_activity'] = time();

// ============================================================================
// التحقق من بصمة الجلسة (منع اختطاف الجلسة)
// ============================================================================
if (isset($_SESSION['fingerprint'])) {
    // [إصلاح v2.0.3] md5 → sha256 + توحيد SALT مع auth.php
    $currentFingerprint = hash('sha256',
        $_SERVER['HTTP_USER_AGENT'] .
        ($_SERVER['REMOTE_ADDR'] ?? '') .
        (defined('SESSION_FINGERPRINT_SALT') ? SESSION_FINGERPRINT_SALT : 'EQAS_SecureSalt_2026_v203')
    );

    // مقارنة البصمة المحفوظة بالحالية
    if ($_SESSION['fingerprint'] !== $currentFingerprint) {
        // بصمة مختلفة - نشاط مشبوه - تدمير الجلسة
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_message'] = 'تم الكشف عن نشاط مشبوه. يرجى تسجيل الدخول مرة أخرى.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . (defined('EQAS_URL') ? EQAS_URL : '') . '/login.php');
        exit;
    }
} else {
    // إنشاء بصمة جديدة للمستخدم
    // [إصلاح v2.0.3] md5 → sha256 + توحيد SALT مع auth.php
    $_SESSION['fingerprint'] = hash('sha256',
        $_SERVER['HTTP_USER_AGENT'] .
        ($_SERVER['REMOTE_ADDR'] ?? '') .
        (defined('SESSION_FINGERPRINT_SALT') ? SESSION_FINGERPRINT_SALT : 'EQAS_SecureSalt_2026_v203')
    );
}

// ============================================================================
// تجديد معرف الجلسة بشكل دوري (كل 30 دقيقة)
// ============================================================================
if (isset($_SESSION['regenerated_at'])) {
    $regenInterval = 1800; // 30 دقيقة
    if ((time() - $_SESSION['regenerated_at']) > $regenInterval) {
        regenerateSession();
    }
} elseif (isset($_SESSION['user_id'])) {
    // تعيين وقت التجديد عند أول تسجيل دخول
    $_SESSION['regenerated_at'] = time();
}

// ============================================================================
// دوال مساعدة للجلسة
// ============================================================================

/**
 * طلب تسجيل الدخول - إعادة توجيه إن لم يكن المستخدم مسجلاً
 * تستخدم في بداية الصفحات المحمية
 *
 * @return void
 */
function requireLogin() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = 'يرجى تسجيل الدخول للوصول إلى هذه الصفحة.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

/**
 * طلب دور محدد - إعادة توجيه إن لم يكن لدى المستخدم الصلاحية
 *
 * @param string|array $roles دور أو مصفوفة أدوار مسموحة
 * @return void
 */
function requireRole($roles) {
    // التأكد من تسجيل الدخول أولاً
    requireLogin();

    // تحويل الدور المفرد إلى مصفوفة
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    // التحقق من دور المستخدم
    $userRole = $_SESSION['user']['role'] ?? '';

    if (!in_array($userRole, $roles)) {
        $_SESSION['flash_message'] = 'ليس لديك صلاحية الوصول إلى هذه الصفحة.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . baseUrl('index.php'));
        exit;
    }
}

/**
 * التحقق من تسجيل الدخول
 *
 * @return bool هل المستخدم مسجل الدخول
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * الحصول على بيانات المستخدم الحالي من الجلسة
 *
 * @return array|null بيانات المستخدم أو null
 */
function currentUser() {
    return $_SESSION['user'] ?? null;
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * الحصول على معرف المستخدم الحالي
 *
 * @return int|null معرف المستخدم
 */
function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * الحصول على معرف المدرسة الحالية من الجلسة
 * يدعم مفتاحَي الجلسة: user.school_id و school_id
 *
 * @return int|null معرف المدرسة
 */
function currentSchoolId() {
    return $_SESSION['user']['school_id'] ?? $_SESSION['school_id'] ?? null;
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * تعيين سياق المدرسة في الجلسة
 *
 * @param int $schoolId معرف المدرسة
 * @return void
 */
function setSchoolContext($schoolId) {
    $_SESSION['school_id'] = $schoolId;
}

/**
 * تعيين سياق العام الدراسي في الجلسة
 *
 * @param int $yearId معرف العام الدراسي
 * @return void
 */
function setAcademicYearContext($yearId) {
    $_SESSION['academic_year_id'] = $yearId;
}

/**
 * تجديد معرف الجلسة بأمان
 * يحافظ على بيانات الجلسة أثناء تجديد المعرف
 *
 * @return void
 */
function regenerateSession() {
    // حفظ البيانات الحالية
    $oldData = $_SESSION;

    // تجديد معرف الجلسة مع حذف القديم
    session_regenerate_id(true);

    // استعادة البيانات (session_regenerate_id قد يفرغ المصفوفة في بعض الإصدارات)
    $_SESSION = array_merge($_SESSION, $oldData);

    // تسجيل وقت التجديد
    $_SESSION['regenerated_at'] = time();
}

/**
 * الحصول على رسالة الفلاش وعرضها لمرة واحدة
 * تحذف الرسالة بعد قراءتها
 *
 * @return array|null رسالة الفلاش [message, type] أو null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $flash = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return $flash;
    }
    return null;
}

/**
 * التحقق من وجود رسالة فلاش
 *
 * @return bool هل توجد رسالة فلاش
 */
function hasFlashMessage() {
    return isset($_SESSION['flash_message']) && !empty($_SESSION['flash_message']);
}

/**
 * تعيين متغير مؤقت في الجلسة (يدعم البيانات المعقدة)
 *
 * @param string $key مفتاح المتغير
 * @param mixed $value القيمة
 * @return void
 */
function setSessionTemp($key, $value) {
    $_SESSION['temp'][$key] = $value;
}

/**
 * الحصول على متغير مؤقت من الجلسة وحذفه
 *
 * @param string $key مفتاح المتغير
 * @param mixed $default القيمة الافتراضية
 * @return mixed القيمة أو القيمة الافتراضية
 */
function getSessionTemp($key, $default = null) {
    if (isset($_SESSION['temp'][$key])) {
        $value = $_SESSION['temp'][$key];
        unset($_SESSION['temp'][$key]);
        return $value;
    }
    return $default;
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * تدمير الجلسة بالكامل وتنظيف البيانات
 *
 * @return void
 */
function destroySession() {
    // حذف بيانات الجلسة
    $_SESSION = [];

    // حذف كوكي الجلسة إن وجد
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // تدمير الجلسة
    session_destroy();
}
