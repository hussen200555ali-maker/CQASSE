<?php
/**
 * ============================================================
 * EQAS - Secure Logout
 * نظام التقييم الشامل - تسجيل الخروج الآمن
 * ============================================================
 */

// Start session (same name as session.php)
session_name('EQAS_SESSION');
session_start();

// Clear remember me cookie
if (isset($_COOKIE['eqas_remember'])) {
    // Unset the cookie with same parameters used to set it
    setcookie('eqas_remember', '', time() - 3600, '/', '', false, true);
    unset($_COOKIE['eqas_remember']);
}

// Destroy session completely
$_SESSION = array();

// Delete session cookie
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

// Destroy the session
session_destroy();

// Start new session for flash message
session_start();
$_SESSION['flash_message'] = 'تم تسجيل الخروج بنجاح.';
$_SESSION['flash_type'] = 'success';

// Regenerate session ID
session_regenerate_id(true);

// Redirect to login page
header('Location: login.php');
exit;
