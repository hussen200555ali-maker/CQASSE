<?php
/**
 * ============================================================
 * EQAS - المصادقة والأمان
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * نظام المصادقة الكامل مع الحماية من هجمات القوة الغاشمة:
 * - تسجيل الدخول والخروج
 * - حماية من محاولات تسجيل الدخول المتكررة
 * - قفل الحساب المؤقت بعد محاولات فاشلة
 * - التحقق من صلاحية المستخدم في كل طلب
 * - تغيير كلمة المرور بأمان
 * - تسجيل نشاط المصادقة
 * ============================================================
 */

// ============================================================================
// التحقق من المصادقة في كل صفحة محمية
// ============================================================================
$currentFile = basename($_SERVER['PHP_SELF']);
$publicPages = ['login.php', 'setup.php', 'logout.php'];

if (!in_array($currentFile, $publicPages)) {
    // التحقق من تسجيل الدخول
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        // غير مسجل الدخول - إعادة توجيه
        $_SESSION['flash_message'] = 'يرجى تسجيل الدخول للوصول إلى هذه الصفحة.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . baseUrl('login.php'));
        exit;
    }

    // التحقق من سلامة بيانات الجلسة
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role'])) {
        // جلسة تالفة - تدمير وإعادة توجيه
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_message'] = 'حدث خطأ في الجلسة. يرجى تسجيل الدخول مرة أخرى.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . baseUrl('login.php'));
        exit;
    }

    // التحقق من أن المستخدم لا يزال موجوداً ونشطاً في قاعدة البيانات
    // يتم تنفيذ هذا التحقق كل طلب لضمان إبطال الجلسة فور تعطيل الحساب
    if (isset($db) && $db !== null) {
        try {
            $stmt = $db->prepare("SELECT id, is_active, password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !$user['is_active']) {
                // المستخدم غير موجود أو معطل - تدمير الجلسة
                session_unset();
                session_destroy();
                session_start();
                $_SESSION['flash_message'] = 'تم تعطيل حسابك. يرجى التواصل مع مدير النظام.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: ' . baseUrl('login.php'));
                exit;
            }

            // التحقق من أن كلمة المرور لم تتغير منذ تسجيل الدخول
            // (إلغاء الجلسات عند تغيير كلمة المرور)
            if (isset($_SESSION['password_hash_sig'])) {
                $currentSig = hash('sha256', $user['password_hash']); // [إصلاح v2.0.3] MD5 → SHA256
                if ($_SESSION['password_hash_sig'] !== $currentSig) {
                    // كلمة المرور تغيرت - إنهاء الجلسة
                    session_unset();
                    session_destroy();
                    session_start();
                    $_SESSION['flash_message'] = 'تم تغيير كلمة المرور. يرجى تسجيل الدخول مرة أخرى.';
                    $_SESSION['flash_type'] = 'info';
                    header('Location: ' . baseUrl('login.php'));
                    exit;
                }
            }
        } catch (Exception $e) {
            // خطأ في قاعدة البيانات - الاستمرار ببيانات الجلسة
            error_log('EQAS Auth DB Check Error: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// دوال المصادقة الرئيسية
// ============================================================================

/**
 * تسجيل الدخول
 * تتحقق من بيانات المستخدم وتحمي من هجمات القوة الغاشمة
 *
 * @param string $username اسم المستخدم
 * @param string $password كلمة المرور
 * @param bool $remember تذكرني (غير مُفعّل حالياً)
 * @return array نتيجة العملية [success, message, user?]
 */
function login($username, $password, $remember = false) {
    global $db;

    // التحقق من المدخلات
    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'يرجى إدخال اسم المستخدم وكلمة المرور.'];
    }

    // التحقق من قفل الحساب (حماية القوة الغاشمة)
    if (isLockedOut($username)) {
        return [
            'success' => false,
            'message' => 'تم قفل الحساب مؤقتاً بسبب محاولات فاشلة متعددة. يرجى المحاولة لاحقاً.'
        ];
    }

    try {
        // البحث عن المستخدم مع بيانات الدور
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.rank as role_rank
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.username = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // التحقق من وجود المستخدم وصحة كلمة المرور
        if (!$user || !password_verify($password, $user['password_hash'])) {
            // تسجيل المحاولة الفاشلة
            incrementLoginAttempts($username);
            return [
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة.'
            ];
        }

        // التحقق من أن الحساب ليس مقفلاً مؤقتاً
        if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
            $remainingMinutes = ceil((strtotime($user['lockout_until']) - time()) / 60);
            return [
                'success' => false,
                'message' => 'الحساب مقفل. حاول بعد ' . $remainingMinutes . ' دقيقة.'
            ];
        }

        // ═══════════════════════════════════════════
        // تسجيل دخول ناجح
        // ═══════════════════════════════════════════

        // إعادة ضبط محاولات الدخول الفاشلة
        resetLoginAttempts($username);

        // حفظ بيانات المستخدم في الجلسة
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'id'          => $user['id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name_ar'],
            'role'        => $user['role_name'],
            'role_rank'   => $user['role_rank'],
            'school_id'   => $user['school_id']
        ];
        $_SESSION['school_id'] = $user['school_id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['fingerprint'] = hash('sha256', // [إصلاح v2.0.3] MD5 → SHA256
            $_SERVER['HTTP_USER_AGENT'] .
            ($_SERVER['REMOTE_ADDR'] ?? '') .
            (defined('SESSION_FINGERPRINT_SALT') ? SESSION_FINGERPRINT_SALT : 'EQAS_SecureSalt_2026_v203') // [إصلاح v2.0.3] MD5 → SHA256
        );
        $_SESSION['password_hash_sig'] = hash('sha256', $user['password_hash']); // [إصلاح v2.0.3] MD5 → SHA256
        $_SESSION['regenerated_at'] = time();

        // تجديد معرف الجلسة لأسباب أمنية
        session_regenerate_id(true);

        // تحديث آخر تسجيل دخول في قاعدة البيانات
        $db->prepare("
            UPDATE users
            SET last_login_at = NOW(),
                login_attempts = 0,
                lockout_until = NULL
            WHERE id = ?
        ")->execute([$user['id']]);

        // تعيين سياق العام الدراسي الحالي
        try {
            $stmt = $db->query("
                SELECT id FROM academic_years
                WHERE is_current = 1 AND is_active = 1
                LIMIT 1
            ");
            $year = $stmt->fetch();
            if ($year) {
                $_SESSION['academic_year_id'] = $year['id'];
            }
        } catch (Exception $e) {
            // جدول العام الدراسي قد لا يكون موجوداً بعد الإعداد
        }

        // تسجيل النشاط
        logActivity($db, $user['id'], 'login', 'تسجيل دخول ناجح من IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));

        return [
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح.',
            'user'    => $user
        ];
    } catch (Exception $e) {
        error_log('EQAS Login Error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.'
        ];
    }
}

/**
 * تسجيل الخروج
 * يدمر الجلسة ويعيد توجيه المستخدم لصفحة الدخول
 *
 * @param bool $redirect هل يتم إعادة التوجيه تلقائياً
 * @return void
 */
function logout($redirect = true) {
    // تسجيل نشاط الخروج
    if (isset($_SESSION['user_id'])) {
        global $db;
        try {
            logActivity($db, $_SESSION['user_id'], 'logout', 'تسجيل خروج');
        } catch (Exception $e) {
            // تجاهل الأخطاء أثناء الخروج
        }
    }

    // حفظ رسالة الفلاش قبل تدمير الجلسة
    $flashMessage = 'تم تسجيل الخروج بنجاح.';
    $flashType = 'success';

    // تدمير الجلسة بالكامل
    $_SESSION = [];

    // حذف كوكي الجلسة
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
    session_start();

    // تعيين رسالة الفلاش في الجلسة الجديدة
    $_SESSION['flash_message'] = $flashMessage;
    $_SESSION['flash_type'] = $flashType;

    // إعادة التوجيه لصفحة الدخول
    if ($redirect) {
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

// ============================================================================
// دوال حماية القوة الغاشمة
// ============================================================================

/**
 * التحقق من قفل الحساب بسبب محاولات فاشلة متعددة
 *
 * @param string $username اسم المستخدم
 * @return bool هل الحساب مقفل
 */
function isLockedOut($username) {
    global $db;

    $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;

    try {
        $stmt = $db->prepare("
            SELECT login_attempts, lockout_until
            FROM users
            WHERE username = ? AND is_active = 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        // التحقق من قفل مؤقت
        if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
            return true;
        }

        // التحقق من عدد المحاولات
        if ($user['login_attempts'] >= $maxAttempts) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        error_log('EQAS Lockout Check Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * زيادة عداد محاولات تسجيل الدخول الفاشلة
 * يقفل الحساب تلقائياً عند تجاوز الحد المسموح
 *
 * @param string $username اسم المستخدم
 * @return void
 */
function incrementLoginAttempts($username) {
    global $db;

    try {
        // زيادة عداد المحاولات
        $stmt = $db->prepare("
            UPDATE users
            SET login_attempts = login_attempts + 1
            WHERE username = ?
        ");
        $stmt->execute([$username]);

        // التحقق من تجاوز الحد المسموح
        $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        $lockoutDuration = defined('LOCKOUT_DURATION') ? LOCKOUT_DURATION : 900; // 15 دقيقة

        $stmt = $db->prepare("SELECT login_attempts FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $user['login_attempts'] >= $maxAttempts) {
            // قفل الحساب مؤقتاً
            $lockoutUntil = date('Y-m-d H:i:s', time() + $lockoutDuration);
            $stmt = $db->prepare("
                UPDATE users
                SET lockout_until = ?
                WHERE username = ?
            ");
            $stmt->execute([$lockoutUntil, $username]);

            // تسجيل الحدث الأمني
            error_log("EQAS Security: Account locked for username: {$username} until {$lockoutUntil}");
        }
    } catch (Exception $e) {
        error_log('EQAS Increment Login Attempts Error: ' . $e->getMessage());
    }
}

/**
 * إعادة ضبط عداد محاولات تسجيل الدخول الفاشلة
 * يستدعى بعد تسجيل الدخول الناجح
 *
 * @param string $username اسم المستخدم
 * @return void
 */
function resetLoginAttempts($username) {
    global $db;

    try {
        $stmt = $db->prepare("
            UPDATE users
            SET login_attempts = 0, lockout_until = NULL
            WHERE username = ?
        ");
        $stmt->execute([$username]);
    } catch (Exception $e) {
        error_log('EQAS Reset Login Attempts Error: ' . $e->getMessage());
    }
}

/**
 * الحصول على عدد محاولات تسجيل الدخول المتبقية
 *
 * @param string $username اسم المستخدم
 * @return int عدد المحاولات المتبقية
 */
function getRemainingAttempts($username) {
    global $db;

    $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;

    try {
        $stmt = $db->prepare("SELECT login_attempts FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $user['login_attempts']);
    } catch (Exception $e) {
        return $maxAttempts;
    }
}

// ============================================================================
// دوال إدارة كلمة المرور
// ============================================================================

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * تغيير كلمة المرور
 * تتحقق من كلمة المرور الحالية قبل التغيير
 *
 * @param int $userId معرف المستخدم
 * @param string $oldPassword كلمة المرور الحالية
 * @param string $newPassword كلمة المرور الجديدة
 * @return array نتيجة العملية [success, message]
 */
function changePassword($userId, $oldPassword, $newPassword) {
    global $db;

    // التحقق من الحد الأدنى لطول كلمة المرور
    $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
    if (mb_strlen($newPassword) < $minLength) {
        return [
            'success' => false,
            'message' => 'كلمة المرور يجب أن تكون ' . $minLength . ' أحرف على الأقل.'
        ];
    }

    try {
        // جلب كلمة المرور الحالية
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'المستخدم غير موجود.'];
        }

        // التحقق من كلمة المرور الحالية
        if (!password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة.'];
        }

        // التحقق من أن كلمة المرور الجديدة مختلفة
        if (password_verify($newPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية.'];
        }

        // تشفير كلمة المرور الجديدة
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        // تحديث كلمة المرور في قاعدة البيانات
        $stmt = $db->prepare("
            UPDATE users
            SET password_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newHash, $userId]);

        // تحديث بصمة كلمة المرور في الجلسة
        $_SESSION['password_hash_sig'] = hash('sha256', $newHash); // [إصلاح v2.0.3] MD5 → SHA256

        // تسجيل النشاط
        logActivity($db, $userId, 'password_change', 'تغيير كلمة المرور');

        return ['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح.'];
    } catch (Exception $e) {
        error_log('EQAS Change Password Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ أثناء تغيير كلمة المرور.'];
    }
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * إعادة تعيين كلمة المرور (للمدير)
 * لا تتطلب كلمة المرور الحالية
 *
 * @param int $userId معرف المستخدم
 * @param string $newPassword كلمة المرور الجديدة
 * @return array نتيجة العملية [success, message]
 */
function resetPassword($userId, $newPassword) {
    global $db;

    $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;
    if (mb_strlen($newPassword) < $minLength) {
        return [
            'success' => false,
            'message' => 'كلمة المرور يجب أن تكون ' . $minLength . ' أحرف على الأقل.'
        ];
    }

    try {
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare("
            UPDATE users
            SET password_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newHash, $userId]);

        // تسجيل النشاط
        logActivity($db, $_SESSION['user_id'] ?? null, 'password_reset', 'إعادة تعيين كلمة مرور للمستخدم رقم ' . $userId);

        return ['success' => true, 'message' => 'تم إعادة تعيين كلمة المرور بنجاح.'];
    } catch (Exception $e) {
        error_log('EQAS Reset Password Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ أثناء إعادة تعيين كلمة المرور.'];
    }
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * التحقق من صلاحية كلمة المرور المؤقتة
 * تستخدم عند إنشاء حساب جديد أو إعادة التعيين
 *
 * @param string $hash التجزئة المخزنة
 * @param string $password كلمة المرور المدخلة
 * @return bool هل كلمة المرور صحيحة
 */
function verifyPassword($hash, $password) {
    return password_verify($password, $hash);
}

/** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
/**
 * إنشاء تجزئة كلمة مرور آمنة
 *
 * @param string $password كلمة المرور
 * @return string التجزئة
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}
