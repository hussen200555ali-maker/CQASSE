<?php
/**
 * ============================================================
 * EQAS - Middleware مركزي للصلاحيات
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * [SEC-005] v2.0.3: نظام صلاحيات مركزي
 *
 * يوفر طبقة حماية مركزية للتحقق من صلاحيات الوصول للصفحات.
 * يمكن استدعاؤه من header.php أو من كل صفحة على حدة.
 *
 * الاستخدام:
 *   require_once __DIR__ . '/permission_middleware.php';
 *   checkPageAccess('school_admin');  // يسمح فقط لمدير النظام ومدير المدرسة فما فوق
 *   checkPageAccess(['group_admin']);  // يسمح فقط لمدير النظام
 * ============================================================
 */

// منع التنفيذ المباشر
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

/**
 * التحقق من صلاحية الوصول للصفحة الحالية (بدون side-effects)
 *
 * @param string $userRole دور المستخدم الحالي
 * @param string|array $requiredRole الدور الأدنى المطلوب أو مصفوفة أدوار مسموحة
 * @return bool هل المستخدم مصرح له
 */
function hasPageAccess($userRole, $requiredRole) {
    // التسلسل الهرمي للأدوار
    $roleHierarchy = [
        'group_admin'  => 100,
        'school_admin' => 80,
        'teacher'      => 50,
        'parent'       => 30,
        'guest'        => 0
    ];

    $userLevel = $roleHierarchy[$userRole] ?? 0;

    if (empty($userRole)) {
        return false;
    }

    if (is_array($requiredRole)) {
        foreach ($requiredRole as $role) {
            $requiredLevel = $roleHierarchy[$role] ?? 0;
            if ($userLevel >= $requiredLevel) {
                return true;
            }
        }
        return false;
    }

    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    return $userLevel >= $requiredLevel;
}

/**
 * التحقق من صلاحية الوصول للصفحة الحالية مع redirect
 *
 * @param string|array $requiredRole الدور الأدنى المطلوب أو مصفوفة أدوار مسموحة
 * @param string $redirectUrl عنوان URL للإعادة (افتراضي: الصفحة الرئيسية)
 * @return bool هل المستخدم مصرح له
 */
function checkPageAccess($requiredRole, $redirectUrl = 'index.php') {
    // التأكد من تسجيل الدخول
    if (!isset($_SESSION['user']['role']) || empty($_SESSION['user']['role'])) {
        $_SESSION['flash_message'] = 'يرجى تسجيل الدخول للوصول إلى هذه الصفحة.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . baseUrl('login.php'));
        exit;
    }

    $userRole = $_SESSION['user']['role'];

    if (!hasPageAccess($userRole, $requiredRole)) {
        $_SESSION['flash_message'] = 'ليس لديك صلاحية الوصول إلى هذه الصفحة.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }

    return true;
}

/**
 * التحقق من أن المستخدم هو مدير النظام فقط
 *
 * @return void
 */
function requireGroupAdmin() {
    checkPageAccess(['group_admin']);
}

/**
 * التحقق من أن المستخدم مدير مدرسة فما فوق
 *
 * @return void
 */
function requireSchoolAdminOrAbove() {
    checkPageAccess('school_admin');
}

/**
 * التحقق من ملكية المدرسة (بدون side-effects)
 *
 * @param string $userRole دور المستخدم
 * @param int|null $userSchoolId معرف مدرسة المستخدم
 * @param int $targetSchoolId معرف المدرسة المستهدفة
 * @return bool هل المستخدم يملك الصلاحية للمدرسة
 */
function hasSchoolOwnership($userRole, $userSchoolId, $targetSchoolId) {
    if ($userRole === 'group_admin') {
        return true;
    }
    return $userSchoolId == $targetSchoolId;
}

/**
 * التحقق من ملكية المدرسة مع redirect
 *
 * @param int $targetSchoolId معرف المدرسة المستهدفة
 * @return bool هل المستخدم ينتمي للمدرسة
 */
function checkSchoolOwnership($targetSchoolId) {
    $userRole = $_SESSION['user']['role'] ?? '';
    $userSchoolId = $_SESSION['user']['school_id'] ?? null;

    // مدير النظام يمكنه الوصول لجميع المدارس
    if ($userRole === 'group_admin') {
        return true;
    }

    // المستخدمون الآخرون يجب أن ينتموا لنفس المدرسة
    if ($userSchoolId != $targetSchoolId) {
        $_SESSION['flash_message'] = 'ليس لديك صلاحية الوصول إلى بيانات هذه المدرسة.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: index.php');
        exit;
    }

    return true;
}

/*
 * ملاحظة معمارية (2026-10-20):
 * أُزيلت خريطة $PAGE_PERMISSIONS ودالة applyAutoPermissions() لأنهما لم تكونا
 * مستخدمتين في أي مكان، ولأن الخريطة القديمة تخالف الصلاحيات الفعلية
 * (مثل حصر pages/users/ على group_admin بينما school_admin يدير معلمي مدرسته).
 * تُفرض الصلاحيات داخل كل صفحة عبر hasPermission()/requireRole().
 */
