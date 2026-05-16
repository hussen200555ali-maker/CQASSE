<?php
/**
 * ============================================================
 * EQAS - الشريط الجانبي (مصفوفة صلاحيات مركزية)
 * نظام التقييم الشامل - القائمة الجانبية الكاملة
 * ============================================================
 * يتضمن جميع الأقسام مع التحكم في الصلاحيات حسب الدور:
 * - مدير النظام (group_admin): يرى كل شيء
 * - مدير المدرسة (school_admin): الإدارة، الطلاب، التقييمات، الإشراف
 * - المعلم (teacher): التقييمات، الأكاديمي، الحضور، السلوك، المهارات، التدريس
 * - ولي الأمر (parent): بيانات الأبناء، الإشعارات، الإقرارات
 *
 * مصفوفة الصلاحيات المركزية:
 * كل عنصر قائمة مُعرّف بمفتاح ومرتبط بقائمة الأدوار المسموحة
 * ============================================================
 */

// Current page and section detection
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Detect current section from URL path
$currentSection = '';
if (preg_match('#/pages/([^/]+)/#', $currentScript, $m)) {
    $currentSection = $m[1];
}
// Also detect for dashboard sub folder
if (preg_match('#/pages/dashboard/#', $currentScript)) {
    $currentSection = 'dashboard';
}
// Detect parent pages
if (preg_match('#/pages/parent/#', $currentScript)) {
    $currentSection = 'parent';
}

// User info
$userRole = $_SESSION['user']['role'] ?? 'guest';
$userName = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? 'مستخدم';
$userSchool = $_SESSION['user']['school_name'] ?? '';
$userInitials = mb_substr($userName, 0, 1, 'UTF-8');

$roleLabels = [
    'group_admin' => 'مدير النظام',
    'school_admin' => 'مدير المدرسة',
    'teacher' => 'معلم',
    'parent' => 'ولي أمر'
];
$userRoleLabel = $roleLabels[$userRole] ?? $userRole;

/**
 * Check if a sidebar menu link is active based on URL matching
 */
function isSidebarActive($sections, $currentSection, $currentScript) {
    if (!is_array($sections)) $sections = [$sections];
    foreach ($sections as $section) {
        if ($section === $currentSection) return true;
        if (strpos($currentScript, '/pages/' . $section . '/') !== false) return true;
    }
    return false;
}

/**
 * Check if a submenu should be expanded (has active child)
 */
function isSubmenuExpanded($sections, $currentSection, $currentScript) {
    return isSidebarActive($sections, $currentSection, $currentScript) ? 'show' : '';
}

/**
 * Generate active class for menu link
 */
function menuActiveClass($sections, $currentSection, $currentScript) {
    return isSidebarActive($sections, $currentSection, $currentScript) ? 'active' : '';
}

// ============================================================================
// مصفوفة الصلاحيات المركزية (Centralized Permissions Matrix)
// ============================================================================
// كل عنصر: 'menu_key' => ['group_admin', 'school_admin', ...]
// القائمة تحدد أي الأدوار يمكنها رؤية كل قسم

$menuPermissions = [
     // الرئيسية
     'dashboard'           => ['group_admin', 'school_admin', 'teacher', 'parent'],

     // الإدارة
     'users'               => ['group_admin'],
     'schools'             => ['group_admin'],
     'academic_years'      => ['group_admin', 'school_admin'],
     'students'            => ['group_admin', 'school_admin'],
     'enrollments'         => ['group_admin', 'school_admin'],

     // الإعدادات الهيكلية
     'setup'               => ['group_admin', 'school_admin'],

     // التقييمات
     'assessment'          => ['group_admin', 'school_admin', 'teacher'],
     'assessment_manage'   => ['group_admin', 'school_admin'],

     // التقييم الأكاديمي
     'academic_assessment' => ['group_admin', 'school_admin', 'teacher'],

     // الحضور
     'attendance'          => ['group_admin', 'school_admin', 'teacher'],
     'attendance_settings' => ['group_admin', 'school_admin'],

     // السلوك
     'behavior'            => ['group_admin', 'school_admin', 'teacher'],
     'behavior_override'   => ['group_admin', 'school_admin'],

     // المهارات
     'skills'              => ['group_admin', 'school_admin', 'teacher'],

     // التدريس
     'teaching'            => ['group_admin', 'school_admin', 'teacher'],
     'teaching_admin'      => ['group_admin', 'school_admin'],

     // الإشراف
     'supervision'         => ['group_admin', 'school_admin'],

     // البيئة المدرسية
     'climate'             => ['group_admin', 'school_admin'],
     'facility'            => ['group_admin', 'school_admin'],

     // المؤشرات
     'alerts'              => ['group_admin', 'school_admin', 'teacher'],
     'eqi'                 => ['group_admin', 'school_admin', 'teacher'],
     'eqi_settings'        => ['group_admin'],
     'alerts_settings'     => ['group_admin'],
     'permissions_matrix'  => ['group_admin'],

     // النظام
     'settings'            => ['group_admin', 'school_admin'],
     'system_restore'      => ['group_admin'],
 ];

/**
 * التحقق مما إذا كان الدور الحالي يملك صلاحية رؤية عنصر القائمة
 */
function canSeeMenu($menuKey, $userRole, $permissions) {
    $allowedRoles = $permissions[$menuKey] ?? [];
    return in_array($userRole, $allowedRoles);
}

// Role shortcuts for convenience
$isGroupAdmin = ($userRole === 'group_admin');
$isSchoolAdmin = ($userRole === 'school_admin');
$isTeacher = ($userRole === 'teacher');
$isParent = ($userRole === 'parent');
$isAdmin = ($isGroupAdmin || $isSchoolAdmin);
$isAdminOrTeacher = ($isAdmin || $isTeacher);
?>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <!-- Logo Area -->
    <div class="sidebar-logo">
        <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
        <h2>EQAS</h2>
        <div class="logo-subtitle">نظام التقييم الشامل لجودة التعليم</div>
    </div>

    <!-- User Info -->
    <div class="sidebar-user">
        <div class="sidebar-user-avatar"><?php echo htmlspecialchars($userInitials); ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></div>
            <div class="sidebar-user-role"><?php echo htmlspecialchars($userRoleLabel); ?></div>
            <?php if ($userSchool): ?>
                <div class="sidebar-user-role" style="font-size:10px; margin-top:1px; color:rgba(255,255,255,0.5);"><?php echo htmlspecialchars($userSchool); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav" aria-label="القائمة الرئيسية">
<?php if ($isGroupAdmin): ?>
<!-- ============================================================
     شريط القيادة الاستراتيجي - group_admin v2.0
     ============================================================ -->

<!-- 📊 لوحة القيادة -->
<div class="sidebar-section-title">لوحة القيادة</div>
<a href="<?php echo baseUrl('pages/dashboard/admin.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['dashboard'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-chart-pie"></i></span>
    <span class="menu-text">الرئيسية</span>
</a>

<!-- 🏫 الإدارة -->
<div class="sidebar-section-title">الإدارة</div>
<a href="<?php echo baseUrl('pages/schools/index.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['schools'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-school"></i></span>
    <span class="menu-text">المدارس</span>
</a>
<a href="<?php echo baseUrl('pages/users/index.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['users'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
    <span class="menu-text">المستخدمون</span>
</a>
<a href="<?php echo baseUrl('pages/academic/index.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['academic'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-calendar-alt"></i></span>
    <span class="menu-text">التقويم الأكاديمي</span>
</a>

<!-- 📈 التحليلات -->
<div class="sidebar-section-title">التحليلات</div>
<button class="sidebar-menu-link <?php echo menuActiveClass(['analytics'], $currentSection, $currentScript); ?>"
        data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['analytics'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-analytics-ga" id="btn-analytics-ga">
    <span class="menu-icon"><i class="fas fa-chart-line"></i></span>
    <span class="menu-text">مركز التحليلات</span>
    <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
</button>
<div class="sidebar-submenu <?php echo isSubmenuExpanded(['analytics'], $currentSection, $currentScript); ?>" id="submenu-analytics-ga" role="region" aria-labelledby="btn-analytics-ga">
    <a href="<?php echo baseUrl('pages/analytics/index.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && $currentPage === 'index') ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-th-large"></i></span>
        <span class="menu-text">لوحة التحليلات</span>
    </a>
    <a href="<?php echo baseUrl('pages/analytics/trends.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'trends') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-chart-area"></i></span>
        <span class="menu-text">اتجاهات الأداء</span>
    </a>
    <a href="<?php echo baseUrl('pages/analytics/school.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'school.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-school"></i></span>
        <span class="menu-text">تحليل المدرسة</span>
    </a>
    <a href="<?php echo baseUrl('pages/analytics/compare.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'compare.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-balance-scale"></i></span>
        <span class="menu-text">مقارنة المدارس</span>
    </a>
</div>
<a href="<?php echo baseUrl('pages/alerts/index.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['alerts'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-bell"></i></span>
    <span class="menu-text">الإنذارات الذكية</span>
</a>

<!-- 📊 مؤشر جودة التعليم -->
<div class="sidebar-section-title">مؤشر جودة التعليم</div>
<button class="sidebar-menu-link <?php echo menuActiveClass(['eqi'], $currentSection, $currentScript); ?>"
        data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['eqi'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-eqi-ga" id="btn-eqi-ga">
    <span class="menu-icon"><i class="fas fa-chart-bar"></i></span>
    <span class="menu-text">مؤشر الجودة (EQI)</span>
    <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
</button>
<div class="sidebar-submenu <?php echo isSubmenuExpanded(['eqi'], $currentSection, $currentScript); ?>" id="submenu-eqi-ga" role="region" aria-labelledby="btn-eqi-ga">
    <a href="<?php echo baseUrl('pages/eqi/dashboard.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'dashboard') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-chart-line"></i></span>
        <span class="menu-text">مركز القيادة</span>
    </a>
    <a href="<?php echo baseUrl('pages/eqi/index.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && $currentPage === 'index') ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
        <span class="menu-text">لوحة المؤشر</span>
    </a>
    <a href="<?php echo baseUrl('pages/eqi/calculate.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'calculate') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-calculator"></i></span>
        <span class="menu-text">حساب المؤشر</span>
    </a>
    <a href="<?php echo baseUrl('pages/eqi/compare.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'compare.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-balance-scale"></i></span>
        <span class="menu-text">مقارنة المؤشر</span>
    </a>
    <a href="<?php echo baseUrl('pages/eqi/report.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'report.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-file-alt"></i></span>
        <span class="menu-text">تقارير المؤشر</span>
    </a>
    <?php if (canSeeMenu('eqi_settings', $userRole, $menuPermissions)): ?>
    <a href="<?php echo baseUrl('pages/eqi/settings.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'settings.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-sliders-h"></i></span>
        <span class="menu-text">إعدادات الأوزان</span>
    </a>
    <?php endif; ?>
</div>

<!-- ⭐ الجودة والإشراف -->
<div class="sidebar-section-title">الجودة والإشراف</div>
<a href="<?php echo baseUrl('pages/climate/dashboard.php'); ?>"
   class="sidebar-menu-link <?php echo menuActiveClass(['climate'], $currentSection, $currentScript); ?>">
    <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
    <span class="menu-text">لوحة المناخ الاستراتيجية</span>
</a>
<a href="<?php echo baseUrl('pages/climate/school.php'); ?>"
   class="sidebar-menu-link <?php echo ($currentSection === 'climate' && strpos($currentScript, 'school.php') !== false) ? 'active' : ''; ?>">
    <span class="menu-icon"><i class="fas fa-school"></i></span>
    <span class="menu-text">تحليل مناخ المدرسة</span>
</a>
<a href="<?php echo baseUrl('pages/supervision/teaching_dashboard.php'); ?>"
   class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'teaching_dashboard') !== false) ? 'active' : ''; ?>">
    <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
    <span class="menu-text">مركز قيادة تقييم المعلمين</span>
</a>
<a href="<?php echo baseUrl('pages/supervision/improvement_plan.php'); ?>"
   class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'improvement_plan') !== false) ? 'active' : ''; ?>">
    <span class="menu-icon"><i class="fas fa-chart-line"></i></span>
    <span class="menu-text">خطط التحسين المدرسية</span>
</a>

<!-- ⚙️ السياسات والحوكمة -->
<div class="sidebar-section-title">السياسات والحوكمة</div>
<button class="sidebar-menu-link <?php echo menuActiveClass(['settings', 'backup_reports'], $currentSection, $currentScript); ?>"
        data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['settings', 'backup_reports'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-policies-ga" id="btn-policies-ga">
    <span class="menu-icon"><i class="fas fa-file-contract"></i></span>
    <span class="menu-text">سياسات المجموعة</span>
    <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
</button>
<div class="sidebar-submenu <?php echo isSubmenuExpanded(['settings', 'backup_reports'], $currentSection, $currentScript); ?>" id="submenu-policies-ga" role="region" aria-labelledby="btn-policies-ga">
    <a href="<?php echo baseUrl('pages/settings/group_policy.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'settings' && strpos($currentScript, 'group_policy') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-file-contract"></i></span>
        <span class="menu-text">سياسات المجموعة</span>
    </a>
    <a href="<?php echo baseUrl('pages/parent_ack/index.php'); ?>"
       class="sidebar-menu-link <?php echo menuActiveClass(['parent_ack'], $currentSection, $currentScript); ?>">
        <span class="menu-icon"><i class="fas fa-check-circle"></i></span>
        <span class="menu-text">إقرارات أولياء الأمور</span>
    </a>
</div>
<button class="sidebar-menu-link <?php echo menuActiveClass(['backup_reports', 'settings'], $currentSection, $currentScript); ?>"
        data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['backup_reports', 'settings'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-governance-ga" id="btn-governance-ga">
    <span class="menu-icon"><i class="fas fa-shield-alt"></i></span>
    <span class="menu-text">حوكمة النظام</span>
    <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
</button>
<div class="sidebar-submenu <?php echo isSubmenuExpanded(['backup_reports', 'settings'], $currentSection, $currentScript); ?>" id="submenu-governance-ga" role="region" aria-labelledby="btn-governance-ga">
    <a href="<?php echo baseUrl('pages/settings/index.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'settings' && $currentPage === 'index') ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-cog"></i></span>
        <span class="menu-text">إعدادات النظام</span>
    </a>
    <a href="<?php echo baseUrl('pages/backup_reports/index.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'backup_reports' && $currentPage === 'index') ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-database"></i></span>
        <span class="menu-text">النسخ الاحتياطي</span>
    </a>
    <a href="<?php echo baseUrl('pages/backup_reports/activity_log.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'backup_reports' && strpos($currentScript, 'activity_log') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-history"></i></span>
        <span class="menu-text">سجل النشاطات</span>
    </a>
    <a href="<?php echo baseUrl('pages/backup_reports/restore.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'backup_reports' && strpos($currentScript, 'restore.php') !== false) ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-undo-alt"></i></span>
        <span class="menu-text">استعادة النسخ</span>
    </a>
    <?php if (canSeeMenu('permissions_matrix', $userRole, $menuPermissions)): ?>
    <a href="<?php echo baseUrl('pages/permissions/matrix.php'); ?>"
       class="sidebar-menu-link <?php echo ($currentSection === 'permissions_matrix') ? 'active' : ''; ?>">
        <span class="menu-icon"><i class="fas fa-lock"></i></span>
        <span class="menu-text">مصفوفة الصلاحيات</span>
    </a>
    <?php endif; ?>
</div>

<?php else: ?>
        <!-- ════════════════════════════════════════════
             الرئيسية - Dashboard
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('dashboard', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">الرئيسية</div>
        <a href="<?php echo baseUrl('index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['dashboard'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-home"></i></span>
            <span class="menu-text">الرئيسية</span>
        </a>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             الإدارة - Admin
             ════════════════════════════════════════════ -->
        <?php
        $showAdmin = canSeeMenu('users', $userRole, $menuPermissions)
                  || canSeeMenu('schools', $userRole, $menuPermissions)
                  || canSeeMenu('academic_years', $userRole, $menuPermissions)
                  || canSeeMenu('students', $userRole, $menuPermissions)
                  || canSeeMenu('enrollments', $userRole, $menuPermissions);
        ?>
        <?php if ($showAdmin): ?>
        <div class="sidebar-section-title">الإدارة</div>

        <?php if (canSeeMenu('users', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/users/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['users'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
            <span class="menu-text">إدارة المستخدمين</span>
        </a>
        <?php endif; ?>

        <?php if (canSeeMenu('schools', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/schools/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['schools'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-school"></i></span>
            <span class="menu-text">إدارة المدارس</span>
        </a>
        <?php endif; ?>

        <?php if (canSeeMenu('academic_years', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/academic/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['academic'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-calendar-alt"></i></span>
            <span class="menu-text">السنوات الأكاديمية</span>
        </a>
        <?php endif; ?>

        <?php if (canSeeMenu('students', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/students/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['students'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-user-graduate"></i></span>
            <span class="menu-text">إدارة الطلاب</span>
        </a>
        <?php endif; ?>

        <?php if (canSeeMenu('enrollments', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/enrollments/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['enrollments'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-door-open"></i></span>
            <span class="menu-text">التسجيلات والشعب</span>
        </a>
        <?php endif; ?>

        <?php endif; /* $showAdmin */ ?>

        <!-- ════════════════════════════════════════════
             الإعدادات الهيكلية - Setup
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('setup', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">الإعدادات الهيكلية</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['setup'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['setup'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-setup" id="btn-setup">
            <span class="menu-icon"><i class="fas fa-cogs"></i></span>
            <span class="menu-text">الإعدادات الهيكلية</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['setup'], $currentSection, $currentScript); ?>" id="submenu-setup" role="region" aria-labelledby="btn-setup">
            <a href="<?php echo baseUrl('pages/setup/subjects.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'subjects') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-book"></i></span>
                <span class="menu-text">المواد الدراسية</span>
            </a>
            <a href="<?php echo baseUrl('pages/setup/weight_setup.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'weight_setup') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-balance-scale"></i></span>
                <span class="menu-text">توزيع الأوزان</span>
            </a>
            <a href="<?php echo baseUrl('pages/setup/sections.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'sections') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-layer-group"></i></span>
                <span class="menu-text">الفصول والشعب</span>
            </a>
            <a href="<?php echo baseUrl('pages/setup/teachers_assign.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'teachers_assign') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chalkboard-teacher"></i></span>
                <span class="menu-text">تعيين المعلمين</span>
            </a>
            <a href="<?php echo baseUrl('pages/setup/semester_setup.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'semester_setup') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-calendar-check"></i></span>
                <span class="menu-text">إعداد الفصل</span>
            </a>
            <a href="<?php echo baseUrl('pages/setup/school_info.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'setup' && strpos($currentScript, 'school_info') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-info-circle"></i></span>
                <span class="menu-text">بيانات المدرسة</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             التقييمات - Assessments
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('assessment', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">التقييمات</div>
        <a href="<?php echo baseUrl('pages/assessment/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['assessment'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span class="menu-text">لوحة التقييمات</span>
        </a>
        <?php if (canSeeMenu('assessment_manage', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/assessment/manage_forms.php'); ?>"
           class="sidebar-menu-link <?php echo ($currentSection === 'assessment' && strpos($currentScript, 'manage_forms') !== false) ? 'active' : ''; ?>">
            <span class="menu-icon"><i class="fas fa-file-alt"></i></span>
            <span class="menu-text">إدارة الاستمارات</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             التقييم الأكاديمي - Academic Assessment
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('academic_assessment', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">التقييم الأكاديمي</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['academic_assessment'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['academic_assessment'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-academic-assessment" id="btn-academic-assessment">
            <span class="menu-icon"><i class="fas fa-graduation-cap"></i></span>
            <span class="menu-text">التقييم الأكاديمي</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['academic_assessment'], $currentSection, $currentScript); ?>" id="submenu-academic-assessment" role="region" aria-labelledby="btn-academic-assessment">
            <a href="<?php echo baseUrl('pages/academic_assessment/grade_entry.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'grade_entry') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-pen"></i></span>
                <span class="menu-text">إدخال الدرجات</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/grade_edit.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'grade_edit') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-edit"></i></span>
                <span class="menu-text">تعديل الدرجات</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/summary.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'summary') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chart-bar"></i></span>
                <span class="menu-text">ملخص الدرجات</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/semester_calc.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'semester_calc') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-calculator"></i></span>
                <span class="menu-text">حساب نتائج الفصل</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/subjects.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'subjects') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-book-open"></i></span>
                <span class="menu-text">المواد الدراسية</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/weight_setup.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'weight_setup') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-balance-scale-right"></i></span>
                <span class="menu-text">توزيع الأوزان</span>
            </a>
            <a href="<?php echo baseUrl('pages/academic_assessment/tests.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'academic_assessment' && strpos($currentScript, 'tests') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-clipboard-list"></i></span>
                <span class="menu-text">إدارة الاختبارات</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             الحضور والانضباط - Attendance
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('attendance', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">الحضور والانضباط</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['attendance'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['attendance'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-attendance" id="btn-attendance">
            <span class="menu-icon"><i class="fas fa-user-clock"></i></span>
            <span class="menu-text">الحضور والانضباط</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['attendance'], $currentSection, $currentScript); ?>" id="submenu-attendance" role="region" aria-labelledby="btn-attendance">
            <a href="<?php echo baseUrl('pages/attendance/daily.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'daily') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-calendar-day"></i></span>
                <span class="menu-text">تسجيل الحضور</span>
            </a>
            <a href="<?php echo baseUrl('pages/attendance/period.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'period') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-clock"></i></span>
                <span class="menu-text">الحضور بالحصص</span>
            </a>
            <a href="<?php echo baseUrl('pages/attendance/amend.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'amend') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-eraser"></i></span>
                <span class="menu-text">تعديل الحضور</span>
            </a>
            <a href="<?php echo baseUrl('pages/attendance/summary.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'summary') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chart-pie"></i></span>
                <span class="menu-text">ملخص الحضور</span>
            </a>
            <a href="<?php echo baseUrl('pages/attendance/notifications.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'notifications') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-bell"></i></span>
                <span class="menu-text">إشعارات الغياب</span>
            </a>
            <?php if (canSeeMenu('attendance_settings', $userRole, $menuPermissions)): ?>
            <a href="<?php echo baseUrl('pages/attendance/settings.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'attendance' && strpos($currentScript, 'settings') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-sliders-h"></i></span>
                <span class="menu-text">إعدادات الحضور</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             السلوك - Behavior
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('behavior', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">السلوك</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['behavior'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['behavior'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-behavior" id="btn-behavior">
            <span class="menu-icon"><i class="fas fa-star-half-alt"></i></span>
            <span class="menu-text">السلوك</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['behavior'], $currentSection, $currentScript); ?>" id="submenu-behavior" role="region" aria-labelledby="btn-behavior">
            <a href="<?php echo baseUrl('pages/behavior/evaluate.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'behavior' && strpos($currentScript, 'evaluate') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-clipboard-check"></i></span>
                <span class="menu-text">التقييم السلوكي</span>
            </a>
            <a href="<?php echo baseUrl('pages/behavior/incident.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'behavior' && strpos($currentScript, 'incident') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-exclamation-triangle"></i></span>
                <span class="menu-text">تسجيل سلوك</span>
            </a>
            <a href="<?php echo baseUrl('pages/behavior/scores.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'behavior' && strpos($currentScript, 'scores') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-trophy"></i></span>
                <span class="menu-text">نقاط السلوك</span>
            </a>
            <a href="<?php echo baseUrl('pages/behavior/history.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'behavior' && strpos($currentScript, 'history') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-history"></i></span>
                <span class="menu-text">سجل السلوك</span>
            </a>
            <?php if (canSeeMenu('behavior_override', $userRole, $menuPermissions)): ?>
            <a href="<?php echo baseUrl('pages/behavior/override.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'behavior' && strpos($currentScript, 'override') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-exclamation-circle"></i></span>
                <span class="menu-text">تجاوز المعيار</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             المهارات - Skills
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('skills', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">المهارات</div>
        <a href="<?php echo baseUrl('pages/skills/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['skills'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-bullseye"></i></span>
            <span class="menu-text">المهارات</span>
        </a>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             التدريس - Teaching
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('teaching', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">التدريس</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['teaching'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['teaching'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-teaching" id="btn-teaching">
            <span class="menu-icon"><i class="fas fa-chalkboard"></i></span>
            <span class="menu-text">التدريس</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['teaching'], $currentSection, $currentScript); ?>" id="submenu-teaching" role="region" aria-labelledby="btn-teaching">
            <a href="<?php echo baseUrl('pages/teaching/self_index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'teaching' && strpos($currentScript, 'self') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-edit"></i></span>
                <span class="menu-text">التقييم الذاتي</span>
            </a>
            <a href="<?php echo baseUrl('pages/teaching/peer_index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'teaching' && strpos($currentScript, 'peer') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-friends"></i></span>
                <span class="menu-text">تقييم الأقران</span>
            </a>
            <?php if (canSeeMenu('teaching_admin', $userRole, $menuPermissions)): ?>
            <a href="<?php echo baseUrl('pages/teaching/admin_index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'teaching' && strpos($currentScript, 'admin') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-tie"></i></span>
                <span class="menu-text">التقييم الإشرافي</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             التقييمات الإشرافية - Supervision
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('supervision', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">التقييمات الإشرافية</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['supervision'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['supervision'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-supervision" id="btn-supervision">
            <span class="menu-icon"><i class="fas fa-eye"></i></span>
            <span class="menu-text">التقييمات الإشرافية</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['supervision'], $currentSection, $currentScript); ?>" id="submenu-supervision" role="region" aria-labelledby="btn-supervision">
            <a href="<?php echo baseUrl('pages/supervision/climate_survey.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'climate_survey') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-cloud-sun"></i></span>
                <span class="menu-text">استبيان المناخ</span>
            </a>
            <a href="<?php echo baseUrl('pages/supervision/facility_report.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'facility_report') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-building"></i></span>
                <span class="menu-text">تقرير المرافق</span>
            </a>
            <a href="<?php echo baseUrl('pages/supervision/teaching_school.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'teaching_school') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-school"></i></span>
                <span class="menu-text">تحليل أداء المعلمين</span>
            </a>
            <a href="<?php echo baseUrl('pages/supervision/improvement_plan.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'improvement_plan') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chart-line"></i></span>
                <span class="menu-text">خطة تحسين المدرسة</span>
            </a>
            <a href="<?php echo baseUrl('pages/supervision/development_plan.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'development_plan') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-seedling"></i></span>
                <span class="menu-text">خطة تطوير المعلم</span>
            </a>
            <?php if (hasPermission('group_admin')): ?>
            <a href="<?php echo baseUrl('pages/supervision/improvement_dashboard.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'supervision' && strpos($currentScript, 'improvement_dashboard') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="menu-text">لوحة متابعة الخطط</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             البيئة المدرسية - Climate & Facility
             ════════════════════════════════════════════ -->
        <?php
        $showEnvironment = canSeeMenu('climate', $userRole, $menuPermissions)
                        || canSeeMenu('facility', $userRole, $menuPermissions);
        ?>
        <?php if ($showEnvironment): ?>
        <div class="sidebar-section-title">البيئة المدرسية</div>
        <?php if (canSeeMenu('climate', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/climate/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['climate'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-cloud-sun-rain"></i></span>
            <span class="menu-text">المناخ المدرسي</span>
        </a>
        <?php endif; ?>
        <?php if (canSeeMenu('facility', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/facility/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['facility'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-building"></i></span>
            <span class="menu-text">المرافق</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             المؤشرات - Indicators
             ════════════════════════════════════════════ -->
        <?php if (canSeeMenu('alerts', $userRole, $menuPermissions) || canSeeMenu('eqi', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">المؤشرات</div>
        <?php if (canSeeMenu('alerts', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/alerts/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['alerts'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-bell"></i></span>
            <span class="menu-text">التنبيهات الذكية</span>
        </a>
        <?php endif; ?>
        <?php if (canSeeMenu('eqi', $userRole, $menuPermissions)): ?>
        <?php $isSchoolAdminEqi = ($userRole === 'school_admin' || $userRole === 'teacher'); ?>
        <?php if ($isSchoolAdminEqi): ?>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['eqi'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['eqi'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-eqi-sa" id="btn-eqi-sa">
            <span class="menu-icon"><i class="fas fa-chart-area"></i></span>
            <span class="menu-text">مؤشر جودة التعليم</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['eqi'], $currentSection, $currentScript); ?>" id="submenu-eqi-sa" role="region" aria-labelledby="btn-eqi-sa">
            <a href="<?php echo baseUrl('pages/eqi/index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && $currentPage === 'index') ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="menu-text">لوحة المؤشر</span>
            </a>
            <?php if ($userRole === 'school_admin'): ?>
            <a href="<?php echo baseUrl('pages/eqi/calculate.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'calculate') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-calculator"></i></span>
                <span class="menu-text">حساب المؤشر</span>
            </a>
            <a href="<?php echo baseUrl('pages/eqi/compare.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'compare.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-balance-scale"></i></span>
                <span class="menu-text">مقارنة المؤشر</span>
            </a>
            <a href="<?php echo baseUrl('pages/eqi/report.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'eqi' && strpos($currentScript, 'report.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-file-alt"></i></span>
                <span class="menu-text">تقارير المؤشر</span>
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <a href="<?php echo baseUrl('pages/eqi/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['eqi'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-chart-area"></i></span>
            <span class="menu-text">مؤشر جودة التعليم</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- التحليلات - Analytics -->
        <?php if (canSeeMenu('analytics', $userRole, $menuPermissions)): ?>
        <div class="sidebar-section-title">التحليلات</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['analytics'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['analytics'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-analytics" id="btn-analytics">
            <span class="menu-icon"><i class="fas fa-chart-line"></i></span>
            <span class="menu-text">التحليلات</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['analytics'], $currentSection, $currentScript); ?>" id="submenu-analytics" role="region" aria-labelledby="btn-analytics">
            <a href="<?php echo baseUrl('pages/analytics/index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'index.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-th-large"></i></span>
                <span class="menu-text">لوحة التحليلات</span>
            </a>
            <a href="<?php echo baseUrl('pages/analytics/trends.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'trends.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chart-area"></i></span>
                <span class="menu-text">الاتجاهات الزمنية</span>
            </a>
            <a href="<?php echo baseUrl('pages/analytics/school.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'school.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-school"></i></span>
                <span class="menu-text">تحليل المدرسة</span>
            </a>
            <a href="<?php echo baseUrl('pages/analytics/compare.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'analytics' && strpos($currentScript, 'compare.php') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-balance-scale"></i></span>
                <span class="menu-text">مقارنة المدارس</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- قرارات الترفيع - Promotion -->
        <?php if (canSeeMenu('promotion', $userRole, $menuPermissions)): ?>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['promotion'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['promotion'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-promotion" id="btn-promotion">
            <span class="menu-icon"><i class="fas fa-level-up-alt"></i></span>
            <span class="menu-text">قرارات الترفيع</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['promotion'], $currentSection, $currentScript); ?>" id="submenu-promotion" role="region" aria-labelledby="btn-promotion">
            <a href="<?php echo baseUrl('pages/promotion/index.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'promotion' && $currentPage === 'index') ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-list"></i></span>
                <span class="menu-text">قائمة الترفيع</span>
            </a>
            <?php if (canSeeMenu('promotion_approve', $userRole, $menuPermissions)): ?>
            <a href="<?php echo baseUrl('pages/promotion/batch_approve.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'promotion' && strpos($currentScript, 'batch_approve') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-check-double"></i></span>
                <span class="menu-text">الاعتماد الدفعي</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; /* indicators section */ ?>

        <!-- ════════════════════════════════════════════
             المخرجات - Outputs
             ════════════════════════════════════════════ -->
        <?php
        $showOutputs = canSeeMenu('grade_sheets', $userRole, $menuPermissions)
                    || canSeeMenu('export', $userRole, $menuPermissions)
                    || canSeeMenu('backup', $userRole, $menuPermissions);
        ?>
        <?php if ($showOutputs): ?>
        <div class="sidebar-section-title">المخرجات</div>
        <?php if (canSeeMenu('grade_sheets', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/grade_sheets/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['grade_sheets'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-file-invoice"></i></span>
            <span class="menu-text">كشوف الدرجات</span>
        </a>
        <?php endif; ?>
        <?php if (canSeeMenu('export', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/export/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['export'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-file-export"></i></span>
            <span class="menu-text">التصدير</span>
        </a>
        <?php endif; ?>
        <?php if (canSeeMenu('backup', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/backup_reports/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['backup_reports'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-database"></i></span>
            <span class="menu-text">النسخ الاحتياطي</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             بيانات الأبناء - Parent Portal (NEW)
             ════════════════════════════════════════════ -->
        <?php if ($isParent): ?>
        <div class="sidebar-section-title">بيانات الأبناء</div>
        <button class="sidebar-menu-link <?php echo menuActiveClass(['parent'], $currentSection, $currentScript); ?>"
                data-toggle="submenu" aria-expanded="<?php echo isSidebarActive(['parent'], $currentSection, $currentScript) ? 'true' : 'false'; ?>" aria-controls="submenu-parent" id="btn-parent">
            <span class="menu-icon"><i class="fas fa-child"></i></span>
            <span class="menu-text">بيانات الأبناء</span>
            <span class="menu-arrow"><i class="fas fa-chevron-left"></i></span>
        </button>
        <div class="sidebar-submenu <?php echo isSubmenuExpanded(['parent'], $currentSection, $currentScript); ?>" id="submenu-parent" role="region" aria-labelledby="btn-parent">
            <a href="<?php echo baseUrl('pages/parent/grades.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'parent' && strpos($currentScript, 'grades') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-chart-bar"></i></span>
                <span class="menu-text">الدرجات</span>
            </a>
            <a href="<?php echo baseUrl('pages/parent/behavior.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'parent' && strpos($currentScript, 'behavior') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-star-half-alt"></i></span>
                <span class="menu-text">السلوك</span>
            </a>
            <a href="<?php echo baseUrl('pages/parent/attendance.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'parent' && strpos($currentScript, 'attendance') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-clock"></i></span>
                <span class="menu-text">الحضور</span>
            </a>
            <a href="<?php echo baseUrl('pages/parent/skills.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'parent' && strpos($currentScript, 'skills') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-bullseye"></i></span>
                <span class="menu-text">المهارات</span>
            </a>
            <a href="<?php echo baseUrl('pages/parent/promotion.php'); ?>"
               class="sidebar-menu-link <?php echo ($currentSection === 'parent' && strpos($currentScript, 'promotion') !== false) ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-level-up-alt"></i></span>
                <span class="menu-text">قرارات الترفيع</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             التواصل - Communication
             ════════════════════════════════════════════ -->
        <div class="sidebar-section-title">التواصل</div>

        <!-- الإشعارات - Notifications -->
        <?php if (canSeeMenu('notifications', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/notifications/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['notifications'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-envelope"></i></span>
            <span class="menu-text">الإشعارات</span>
        </a>
        <?php endif; ?>

        <!-- الملاحظات - Notes -->
        <?php if (canSeeMenu('notes', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/notes/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['notes'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-sticky-note"></i></span>
            <span class="menu-text">الملاحظات</span>
        </a>
        <?php endif; ?>

        <!-- إقرار أولياء الأمور - Parent Acknowledgment -->
        <?php if (canSeeMenu('parent_ack', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/parent_ack/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['parent_ack'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-check-circle"></i></span>
            <span class="menu-text">إقرار أولياء الأمور</span>
        </a>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════
             النظام - System
             ════════════════════════════════════════════ -->
        <?php
        $showSystem = canSeeMenu('settings', $userRole, $menuPermissions)
                   || canSeeMenu('system_restore', $userRole, $menuPermissions);
        ?>
        <?php if ($showSystem): ?>
        <div class="sidebar-section-title">النظام</div>
        <?php if (canSeeMenu('settings', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/settings/index.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['settings'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-cog"></i></span>
            <span class="menu-text">الإعدادات</span>
        </a>
        <?php endif; ?>
        <?php if (canSeeMenu('system_restore', $userRole, $menuPermissions)): ?>
        <a href="<?php echo baseUrl('pages/system/restore.php'); ?>"
           class="sidebar-menu-link <?php echo menuActiveClass(['system'], $currentSection, $currentScript); ?>">
            <span class="menu-icon"><i class="fas fa-undo-alt"></i></span>
            <span class="menu-text">استعادة النسخ</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
<?php endif; ?>

    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <!-- Quick Links -->
        <div style="display:flex; gap:4px; margin-bottom:6px;">
            <a href="<?php echo baseUrl('login.php'); ?>" class="sidebar-logout-btn" style="flex:1; justify-content:center; color:rgba(255,255,255,0.6); font-size:11px; padding:4px;">
                <i class="fas fa-question-circle"></i> المساعدة
            </a>
            <a href="#" class="sidebar-logout-btn" style="flex:1; justify-content:center; color:rgba(255,255,255,0.6); font-size:11px; padding:4px;" onclick="printContent('main-content-area'); return false;">
                <i class="fas fa-print"></i> طباعة
            </a>
        </div>
        <!-- Logout -->
        <a href="<?php echo baseUrl('logout.php'); ?>" class="sidebar-logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>تسجيل الخروج</span>
        </a>
    </div>
</aside>
