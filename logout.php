<?php
/**
 * ============================================================
 * EQAS - Secure Logout
 * نظام التقييم الشامل - تسجيل الخروج الآمن
 * ============================================================
 */

// Central error handler
require_once __DIR__ . '/includes/error_handler.php';

// Include config for EQAS_URL constant
require_once __DIR__ . '/config.php';

// Include session management (same path as all pages)
require_once __DIR__ . '/includes/session.php';

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

// Redirect to login page using absolute URL
$loginUrl = defined('EQAS_URL') ? EQAS_URL . '/login.php' : 'login.php';
header('Location: ' . $loginUrl);
exit;
