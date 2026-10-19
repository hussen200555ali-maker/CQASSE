<?php
/**
 * ============================================================
 * EQAS - محوّه لوحة التحكم
 * نظام التقييم الشامل - موجه حسب الدور
 * ============================================================
 * يعيد توجيه المستخدم إلى لوحة التحكم المناسبة لدوره:
 * - مدير النظام (group_admin) → pages/dashboard/admin.php
 * - مدير المدرسة (school_admin) → pages/dashboard/school_admin.php
 * - المعلم (teacher) → pages/dashboard/teacher.php
 * - ولي الأمر (parent) → pages/dashboard/parent.php
 * - غير ذلك → pages/dashboards/index.php
 * ============================================================
 */

// Central error handler
require_once __DIR__ . '/includes/error_handler.php';

// Include required files for session and auth check
// (database → helpers → session → auth order is critical)
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    $_SESSION['flash_message'] = 'يرجى تسجيل الدخول للوصول إلى النظام.';
    $_SESSION['flash_type'] = 'warning';
    redirect(baseUrl('login.php'));
}

// Get user role
$role = $_SESSION['user']['role'] ?? '';

// Route to appropriate dashboard based on role
switch ($role) {
    case 'group_admin':
        redirect(baseUrl('pages/dashboard/admin.php'));
        break;

    case 'school_admin':
        redirect(baseUrl('pages/dashboard/school_admin.php'));
        break;

    case 'teacher':
        redirect(baseUrl('pages/dashboard/teacher.php'));
        break;

    case 'parent':
        redirect(baseUrl('pages/dashboard/parent.php'));
        break;

    default:
        // Unknown role - redirect to generic dashboard or login
        $_SESSION['flash_message'] = 'دور المستخدم غير معروف. يرجى التواصل مع مدير النظام.';
        $_SESSION['flash_type'] = 'danger';
        redirect(baseUrl('login.php'));
        break;
}
