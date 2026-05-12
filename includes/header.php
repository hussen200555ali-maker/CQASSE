<?php
/**
 * ============================================================
 * EQAS - رأس الصفحة
 * نظام التقييم الشامل - القالب العلوي والتنقل
 * ============================================================
 * Include Order: database.php → helpers.php → session.php → auth.php
 * ترتيب الإدراج: قاعدة البيانات ← المساعدات ← الجلسة ← المصادقة
 * ============================================================
 */

// Include required files (CRITICAL ORDER: config → database → helpers → session → auth)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Page title
$pageTitle = $pageTitle ?? 'نظام التقييم الشامل';

// Current page for active menu detection
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Detect current section from URL path
$currentSection = '';
if (preg_match('#/pages/([^/]+)/#', $currentScript, $m)) {
    $currentSection = $m[1];
}

// Get user info
$currentUser = $_SESSION['user'] ?? null;
$userRole = $currentUser['role'] ?? 'guest';
$userName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'مستخدم';
$userSchool = $currentUser['school_name'] ?? '';

// Role labels
$roleLabels = [
    'group_admin' => 'مدير النظام',
    'school_admin' => 'مدير المدرسة',
    'teacher' => 'معلم',
    'parent' => 'ولي أمر'
];
$userRoleLabel = $roleLabels[$userRole] ?? $userRole;

// User initials for avatar
$userInitials = mb_substr($userName, 0, 1, 'UTF-8');

// Notification count
$notificationCount = 0;
if (isset($db) && isLoggedIn()) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user']['id'] ?? 0]);
        $notificationCount = $stmt->fetchColumn();
    } catch (Exception $e) {
        $notificationCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS">
    <meta name="author" content="EQAS">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <meta name="base-url" content="<?php echo defined('EQAS_URL') ? EQAS_URL : ''; ?>">

    <title><?php echo htmlspecialchars($pageTitle); ?> | نظام التقييم الشامل</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/img/favicon.svg">
    <link rel="alternate icon" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/img/favicon.png">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">

    <!-- Responsive Stylesheet -->
    <link rel="stylesheet" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/css/responsive.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>">

    <!-- Print Stylesheet -->
    <link rel="stylesheet" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/css/print.css" media="print">

    <!-- Google Fonts - Arabic Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons (local fallback if CDN fails) -->
    <link rel="stylesheet" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/css/all.min.css">

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
            <?php if (isset($GLOBALS['CSP_NONCE'])) echo 'nonce="' . htmlspecialchars($GLOBALS['CSP_NONCE']) . '"'; ?>></script>
    <?php if (isset($GLOBALS['CSP_NONCE'])): ?>
    <!-- CSP Nonce for inline scripts -->
    <meta name="csp-nonce" content="<?php echo htmlspecialchars($GLOBALS['CSP_NONCE']); ?>">
    <?php endif; ?>

    <!-- Page-specific CSS -->
    <?php if (isset($pageCSS)): ?>
    <link rel="stylesheet" href="<?php echo EQAS_ASSETS_URL ?? 'assets'; ?>/css/<?php echo htmlspecialchars($pageCSS); ?>">
    <?php endif; ?>

    <style>
        :root {
            /* EQAS Color System */
            --eqas-green: #1a5632;
            --eqas-green-light: #1e6b3e;
            --eqas-green-dark: #134425;
            --eqas-gold: #c8a951;
            --eqas-gold-light: #d4bb6e;
            --eqas-gold-dark: #b08f3a;
            --eqas-red: #e63946;
            --eqas-blue: #17a2b8;

            /* Sidebar */
            --sidebar-width: 270px;
            --sidebar-bg: #1a5632;
            --sidebar-text: #e8e8e8;
            --sidebar-hover-bg: rgba(255,255,255,0.08);
            --sidebar-active-bg: rgba(200,169,81,0.15);
            --sidebar-active-text: #c8a951;
            --sidebar-section-bg: rgba(0,0,0,0.12);

            /* Topbar */
            --topbar-height: 60px;
            --topbar-bg: rgba(255,255,255,0.85);
            --topbar-border: rgba(0,0,0,0.06);

            /* Transitions */
            --sidebar-transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);

            /* Glass */
            --glass-bg: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.18);
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, #0f3a20 0%, #1a5632 40%, #1e6b3e 100%);
            color: var(--sidebar-text);
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1040;
            transition: transform var(--sidebar-transition);
            display: flex;
            flex-direction: column;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.15);
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Sidebar Logo */
        .sidebar-logo {
            padding: 16px 20px;
            text-align: center;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .sidebar-logo .logo-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 6px;
            background: linear-gradient(135deg, #c8a951 0%, #d9c47a 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: #134425;
            box-shadow: 0 4px 14px rgba(200, 169, 81, 0.35);
        }
        .sidebar-logo h2 {
            margin: 0;
            font-size: 22px;
            color: #ffffff;
            letter-spacing: 4px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .sidebar-logo .logo-subtitle {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
            margin-top: 2px;
        }

        /* Sidebar User Info */
        .sidebar-user {
            padding: 12px 16px;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .sidebar-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #c8a951 0%, #d9c47a 100%);
            color: var(--eqas-green-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.2);
            box-shadow: 0 2px 8px rgba(200,169,81,0.3);
        }
        .sidebar-user-info {
            overflow: hidden;
        }
        .sidebar-user-name {
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-user-role {
            font-size: 11px;
            color: var(--eqas-gold);
        }

        /* Sidebar Navigation */
        .sidebar-nav {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px 0;
        }

        .sidebar-section-title {
            padding: 14px 18px 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.35);
            margin-top: 4px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 10px 18px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            font-size: 13px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: right;
            position: relative;
            border-right: 3px solid transparent;
            margin: 1px 0;
            border-radius: 0 8px 8px 0;
        }
        .sidebar-menu-link:hover {
            background: var(--sidebar-hover-bg);
            color: #fff;
            border-right-color: rgba(200,169,81,0.4);
            transform: translateX(-2px);
        }
        .sidebar-menu-link.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-text);
            border-right-color: var(--eqas-gold);
            padding-right: 15px;
            font-weight: 600;
        }
        .sidebar-menu-link.active::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 60%;
            background: linear-gradient(135deg, #c8a951 0%, #d9c47a 100%);
            border-radius: 0 3px 3px 0;
            box-shadow: 0 0 8px rgba(200,169,81,0.4);
        }
        .sidebar-menu-link .menu-icon {
            width: 30px;
            font-size: 15px;
            flex-shrink: 0;
            text-align: center;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-menu-link:hover .menu-icon {
            transform: scale(1.1);
        }
        .sidebar-menu-link .menu-text {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-menu-link .menu-arrow {
            font-size: 10px;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            margin-right: auto;
            margin-left: 4px;
            opacity: 0.5;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
        }
        .sidebar-menu-link:hover .menu-arrow {
            opacity: 0.8;
            background: rgba(255,255,255,0.1);
        }
        .sidebar-menu-link[aria-expanded="true"] .menu-arrow,
        .sidebar-submenu.show ~ .sidebar-menu-link .menu-arrow {
            transform: rotate(-90deg);
            opacity: 1;
            background: rgba(200,169,81,0.2);
            color: var(--eqas-gold);
        }

        /* Sidebar Submenu - Accordion */
        .sidebar-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
            background: var(--sidebar-section-bg);
            opacity: 0;
        }
        .sidebar-submenu.show {
            max-height: 600px;
            opacity: 1;
        }
        .sidebar-submenu .sidebar-menu-link {
            padding-right: 48px;
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            border-right: none;
        }
        .sidebar-submenu .sidebar-menu-link:hover {
            color: rgba(255,255,255,0.95);
            background: rgba(255,255,255,0.04);
            transform: translateX(-2px);
        }
        .sidebar-submenu .sidebar-menu-link.active {
            color: var(--eqas-gold);
            background: rgba(200,169,81,0.08);
        }
        .sidebar-submenu .sidebar-menu-link .menu-icon {
            width: 24px;
            font-size: 11px;
            opacity: 0.7;
        }
        .sidebar-submenu .sidebar-menu-link::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            margin-left: 8px;
            flex-shrink: 0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-submenu .sidebar-menu-link:hover::before {
            background: var(--eqas-gold);
            box-shadow: 0 0 6px rgba(200,169,81,0.4);
        }
        .sidebar-submenu .sidebar-menu-link.active::before {
            background: var(--eqas-gold);
            box-shadow: 0 0 8px rgba(200,169,81,0.5);
            width: 6px;
            height: 6px;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--glass-border);
            flex-shrink: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .sidebar-footer .sidebar-logout-btn {
            border-radius: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-footer .sidebar-logout-btn:hover {
            transform: translateX(-2px);
            box-shadow: 0 2px 8px rgba(255,107,107,0.2);
        }
        .sidebar-footer .sidebar-logout-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            color: #ff6b6b;
            text-decoration: none;
            font-size: 13px;
            border-radius: 6px;
            transition: background 0.2s;
            width: 100%;
        }
        .sidebar-footer .sidebar-logout-btn:hover {
            background: rgba(255,107,107,0.15);
        }

        /* Sidebar Overlay (Mobile) */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1035;
        }
        .sidebar-overlay.show {
            display: block;
        }

        /* Main Content Layout */
        .page-wrapper {
            margin-right: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-right var(--sidebar-transition);
        }

        /* Topbar */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            height: var(--topbar-height);
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 1px 8px rgba(0,0,0,0.04);
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Sidebar Toggle */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            color: #333;
            cursor: pointer;
            padding: 4px 8px;
        }

        /* Topbar Page Title */
        .topbar .page-title h1 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: #1a1a1a;
        }

        /* Breadcrumb */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            font-size: 12px;
            color: #888;
            gap: 4px;
        }
        .breadcrumb-nav a {
            color: var(--eqas-green);
            text-decoration: none;
        }
        .breadcrumb-nav a:hover {
            text-decoration: underline;
        }
        .breadcrumb-nav .separator {
            color: #ccc;
        }
        .breadcrumb-nav .current {
            color: #555;
        }

        /* Quick Search */
        .quick-search {
            position: relative;
        }
        .quick-search input {
            padding: 6px 32px 6px 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 13px;
            width: 200px;
            outline: none;
            transition: border-color 0.2s;
        }
        .quick-search input:focus {
            border-color: var(--eqas-green);
        }
        .quick-search i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 13px;
        }

        /* Topbar Items */
        .topbar-item {
            position: relative;
        }
        .notification-bell {
            cursor: pointer;
            font-size: 18px;
            color: #555;
            padding: 4px;
            transition: color 0.2s;
        }
        .notification-bell:hover {
            color: var(--eqas-green);
        }
        .notification-badge {
            position: absolute;
            top: -2px;
            left: -4px;
            background: var(--eqas-red);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }
        .user-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: 1px solid #ddd;
            border-radius: 20px;
            padding: 4px 12px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            transition: border-color 0.2s;
        }
        .user-btn:hover {
            border-color: var(--eqas-green);
        }
        .user-btn i.fa-user-circle {
            font-size: 22px;
            color: var(--eqas-green);
        }
        .user-btn i.fa-chevron-down {
            font-size: 10px;
            color: #999;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            min-width: 180px;
            z-index: 1050;
            overflow: hidden;
        }
        .dropdown-menu.show {
            display: block;
        }
        .dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            font-size: 13px;
            color: #333;
            text-decoration: none;
            transition: background 0.15s;
        }
        .dropdown-menu a:hover {
            background: #f5f5f5;
        }
        .dropdown-menu a.text-danger {
            color: var(--eqas-red);
        }
        .dropdown-divider {
            height: 1px;
            background: #eee;
        }

        /* Flash Messages */
        .flash-message {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            font-size: 14px;
            animation: slideDown 0.3s ease;
        }
        .flash-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .flash-success { background: #d4edda; color: #155724; border-bottom: 2px solid #28a745; }
        .flash-danger  { background: #f8d7da; color: #721c24; border-bottom: 2px solid #dc3545; }
        .flash-warning { background: #fff3cd; color: #856404; border-bottom: 2px solid #ffc107; }
        .flash-info    { background: #d1ecf1; color: #0c5460; border-bottom: 2px solid #17a2b8; }
        .flash-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }
        .flash-close:hover {
            opacity: 1;
        }
        @keyframes slideDown {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* AJAX Loading Indicator */
        .ajax-loading-bar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            justify-content: center;
            padding-top: 8px;
        }
        .ajax-loading-bar.active {
            display: flex;
        }
        .ajax-loading-bar > div {
            background: var(--eqas-green);
            color: #fff;
            padding: 4px 20px;
            border-radius: 0 0 8px 8px;
            font-size: 13px;
        }

        /* Main Content */
        .main-content-inner {
            flex: 1;
            padding: 20px;
        }

        /* Responsive - Handled by responsive.css */
    </style>
</head>
<body>
    <div class="page-wrapper">

        <!-- ============================================================
             SIDEBAR
             ============================================================ -->
        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay" id="sidebar-overlay"></div>

        <!-- ============================================================
             MAIN CONTENT AREA
             ============================================================ -->
        <div class="main-content" id="main-content">

            <!-- ============================================================
                 HEADER / TOPBAR
                 ============================================================ -->
            <header class="topbar" id="topbar">
                <div class="topbar-right">
                    <!-- Sidebar Toggle (Mobile) -->
                    <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="فتح القائمة">
                        <i class="fas fa-bars"></i>
                    </button>

                    <!-- Page Title & Breadcrumb -->
                    <div class="page-title">
                        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                        <?php if (isset($breadcrumb) && is_array($breadcrumb)): ?>
                        <nav class="breadcrumb-nav" aria-label="مسار التنقل">
                            <a href="<?php echo baseUrl('index.php'); ?>">الرئيسية</a>
                            <?php foreach ($breadcrumb as $label => $url): ?>
                                <span class="separator">/</span>
                                <?php if ($url): ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>"><?php echo htmlspecialchars($label); ?></a>
                                <?php else: ?>
                                    <span class="current"><?php echo htmlspecialchars($label); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="topbar-left">
                    <!-- Quick Search -->
                    <div class="quick-search">
                        <input type="text" placeholder="بحث سريع..." aria-label="بحث سريع" id="quickSearch">
                        <i class="fas fa-search"></i>
                    </div>

                    <!-- Notifications -->
                    <div class="topbar-item notification-bell" onclick="window.location.href='<?php echo baseUrl('pages/notifications/index.php'); ?>'">
                        <i class="fas fa-bell"></i>
                        <?php if ($notificationCount > 0): ?>
                        <span class="notification-badge"><?php echo $notificationCount > 99 ? '99+' : $notificationCount; ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- User Dropdown -->
                    <div class="topbar-item user-dropdown">
                        <button class="user-btn" type="button" aria-label="قائمة المستخدم" id="user-dropdown-toggle">
                            <i class="fas fa-user-circle"></i>
                            <span><?php echo htmlspecialchars($userName); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu" id="userMenu">
                            <div style="padding:12px;text-align:center;border-bottom:1px solid #eee;">
                                <div style="width:42px;height:42px;border-radius:50%;background:var(--eqas-gold);color:var(--eqas-green-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;margin:0 auto 6px;">
                                    <?php echo htmlspecialchars($userInitials); ?>
                                </div>
                                <div style="font-size:13px;font-weight:600;"><?php echo htmlspecialchars($userName); ?></div>
                                <div style="font-size:11px;color:#888;"><?php echo htmlspecialchars($userRoleLabel); ?></div>
                                <?php if ($userSchool): ?>
                                <div style="font-size:10px;color:#aaa;margin-top:2px;"><?php echo htmlspecialchars($userSchool); ?></div>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo baseUrl('pages/users/profile.php'); ?>"><i class="fas fa-user"></i> الملف الشخصي</a>
                            <a href="<?php echo baseUrl('pages/settings/index.php'); ?>"><i class="fas fa-cog"></i> الإعدادات</a>
                            <div class="dropdown-divider"></div>
                            <a href="<?php echo baseUrl('logout.php'); ?>" class="text-danger"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="flash-message flash-<?php echo htmlspecialchars($_SESSION['flash_type'] ?? 'info'); ?>" id="flashMessage">
                    <div class="flash-content">
                        <i class="fas fa-<?php
                            $ft = $_SESSION['flash_type'] ?? 'info';
                            echo $ft === 'success' ? 'check-circle' : ($ft === 'danger' ? 'exclamation-circle' : ($ft === 'warning' ? 'exclamation-triangle' : 'info-circle'));
                        ?>"></i>
                        <span><?php echo htmlspecialchars($_SESSION['flash_message']); ?></span>
                    </div>
                    <button class="flash-close" onclick="document.getElementById('flashMessage').style.display='none'">&times;</button>
                </div>
                <?php
                unset($_SESSION['flash_message'], $_SESSION['flash_type']);
                endif;
            ?>

            <!-- AJAX Loading Indicator -->
            <div class="ajax-loading-bar" id="ajax-loading">
                <div><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>
            </div>

            <!-- Main Content Area -->
            <main class="main-content-inner" id="main-content-area">
