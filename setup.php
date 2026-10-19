<?php
/**
 * ============================================================
 * EQAS - Installation Wizard (6 Steps)
 * نظام التقييم الشامل - معالج التثبيت
 * ============================================================
 */

// Include session management (same path as all pages)
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/installer.php';

// [R-01] منع إعادة تشغيل المعالج بعد نجاح التثبيت.
// يعتمد على قفل التثبيت (temp/installed.lock) أو وجود EQAS_INSTALLED في config.php
// بدلاً من مجرّد وجود config.php — حتى يعمل التثبيت على نسخة جديدة تماماً.
$eqasInstalled = is_file(__DIR__ . '/temp/installed.lock');
if (!$eqasInstalled && is_file(__DIR__ . '/config.php')) {
    $eqasCfg = @file_get_contents(__DIR__ . '/config.php');
    $eqasInstalled = ($eqasCfg !== false && strpos($eqasCfg, 'EQAS_INSTALLED') !== false);
}
if ($eqasInstalled) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Current step
$step = isset($_GET['step']) ? max(1, min(6, (int)$_GET['step'])) : 1;

// Check if it's a POST request to advance steps
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'رمز الأمان غير صالح. يرجى تحديث الصفحة.';
    } else {
        // Process each step
        switch ($step) {
            case 1:
                // Welcome - just proceed
                $step = 2;
                break;

            case 2:
                // Database configuration
                $dbHost = trim($_POST['db_host'] ?? 'localhost');
                $dbName = trim($_POST['db_name'] ?? '');
                $dbUser = trim($_POST['db_user'] ?? '');
                $dbPass = $_POST['db_pass'] ?? '';

                if (empty($dbName) || empty($dbUser)) {
                    $error = 'يرجى ملء جميع الحقول المطلوبة.';
                } else {
                    // Test connection
                    try {
                        $pdo = new PDO(
                            "mysql:host={$dbHost};charset=utf8mb4",
                            $dbUser,
                            $dbPass,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );

                        // Create database if not exists
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $pdo->exec("USE `{$dbName}`");

                        // Save to session for later use
                        $_SESSION['setup_db'] = [
                            'host' => $dbHost,
                            'name' => $dbName,
                            'user' => $dbUser,
                            'pass' => $dbPass
                        ];

                        $step = 3;
                    } catch (PDOException $e) {
                        $error = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
                    }
                }
                break;

            case 3:
                // Admin account
                $adminUsername = trim($_POST['admin_username'] ?? '');
                $adminPassword = $_POST['admin_password'] ?? '';
                $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
                $adminEmail = trim($_POST['admin_email'] ?? '');
                $adminFullName = trim($_POST['admin_fullname'] ?? 'مدير النظام');

                if (empty($adminUsername) || empty($adminPassword) || empty($adminEmail)) {
                    $error = 'يرجى ملء جميع الحقول المطلوبة.';
                } elseif (($pwError = eqas_validate_admin_password($adminPassword)) !== null) {
                    $error = $pwError;
                } elseif ($adminPassword !== $adminPasswordConfirm) {
                    $error = 'كلمتا المرور غير متطابقتين.';
                } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'يرجى إدخال بريد إلكتروني صحيح.';
                } else {
                    $_SESSION['setup_admin'] = [
                        'username' => $adminUsername,
                        'password' => $adminPassword,
                        'email' => $adminEmail,
                        'full_name' => $adminFullName
                    ];
                    $step = 4;
                }
                break;

            case 4:
                // School information
                $schoolName = trim($_POST['school_name'] ?? '');
                $schoolType = trim($_POST['school_type'] ?? '');
                $schoolAddress = trim($_POST['school_address'] ?? '');
                $schoolGovernorate = trim($_POST['school_governorate'] ?? '');
                $schoolDirectorate = trim($_POST['school_directorate'] ?? '');

                if (empty($schoolName) || empty($schoolType) || empty($schoolGovernorate)) {
                    $error = 'يرجى ملء جميع الحقول المطلوبة.';
                } else {
                    $_SESSION['setup_school'] = [
                        'name' => $schoolName,
                        'type' => $schoolType,
                        'address' => $schoolAddress,
                        'governorate' => $schoolGovernorate,
                        'directorate' => $schoolDirectorate
                    ];
                    $step = 5;
                }
                break;

            case 5:
                // Academic year setup
                $yearName = trim($_POST['year_name'] ?? '');
                $yearStart = trim($_POST['year_start'] ?? '');
                $yearEnd = trim($_POST['year_end'] ?? '');

                if (empty($yearName) || empty($yearStart) || empty($yearEnd)) {
                    $error = 'يرجى ملء جميع الحقول المطلوبة.';
                } elseif (strtotime($yearEnd) <= strtotime($yearStart)) {
                    $error = 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.';
                } else {
                    $_SESSION['setup_year'] = [
                        'name' => $yearName,
                        'start' => $yearStart,
                        'end' => $yearEnd
                    ];

                    // Perform installation
                    $installResult = performInstallation();
                    if ($installResult === true) {
                        $step = 6;
                    } else {
                        $error = $installResult;
                    }
                }
                break;
        }
    }

    // Regenerate CSRF
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Error from POST
$error = $error ?? '';

/**
 * Perform the actual installation
 * يفوّض المنطق الفعلي إلى محرّك التثبيت القابل للاختبار (includes/installer.php)
 */
function performInstallation() {
    return eqas_perform_installation([
        'db'     => $_SESSION['setup_db']     ?? null,
        'admin'  => $_SESSION['setup_admin']  ?? null,
        'school' => $_SESSION['setup_school'] ?? null,
        'year'   => $_SESSION['setup_year']   ?? null,
    ]);
}

// Step labels
$stepLabels = [
    1 => 'مرحباً',
    2 => 'قاعدة البيانات',
    3 => 'حساب المدير',
    4 => 'معلومات المدرسة',
    5 => 'العام الأكاديمي',
    6 => 'اكتمل التثبيت'
];

// Yemeni governorates
$governorates = [
    'أمانة العاصمة', 'عدن', 'تعز', 'الحديدة', 'إب', 'ذمار', 'حضرموت',
    'المكلا', 'عمران', 'حجة', 'صنعاء', 'البيضاء', 'مأرب', 'لحج',
    'أبين', 'شبوة', 'صعدة', 'الجوف', 'المهرة', 'ريمة', 'سقطرى'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تثبيت النظام | EQAS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a5632 0%, #0f3a20 50%, #082615 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-wrapper {
            max-width: 750px;
            width: 100%;
            animation: fadeInUp 0.6s ease;
        }
        .setup-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .setup-logo .setup-logo-img {
            width: 100px;
            height: 100px;
            margin-bottom: 10px;
        }
        .setup-logo h1 {
            color: white;
            font-size: 28px;
            margin-bottom: 5px;
        }
        .setup-logo p {
            color: rgba(255,255,255,0.7);
            font-size: 14px;
        }
        .setup-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .setup-card-header {
            padding: 24px 30px;
            border-bottom: 1px solid #e9ecef;
            background: #fafafa;
        }
        .setup-card-body {
            padding: 30px;
        }
        .setup-card-footer {
            padding: 16px 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            background: #fafafa;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .setup-step-active { color: #1a5632; font-weight: 600; }
        .setup-step-completed { color: #28a745; }
        .setup-step-pending { color: #9ca3af; }
        .setup-step-number-active { background: #1a5632; color: white; }
        .setup-step-number-completed { background: #28a745; color: white; }
        .setup-step-number-pending { background: #e9ecef; color: #9ca3af; }
        .success-icon { font-size: 64px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="setup-wrapper">
        <!-- Logo -->
        <div class="setup-logo">
            <img src="assets/img/logo-login.svg" alt="CQASSE" class="setup-logo-img">
            <h1>نظام التقييم الشامل لجودة التعليم المدرسي</h1>
            <p>معالج التثبيت - CQASSE</p>
        </div>

        <div class="setup-card">
            <!-- Steps Progress -->
            <div class="setup-card-header">
                <div class="setup-steps">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                    <div class="setup-step <?php echo $i < $step ? 'completed' : ($i === $step ? 'active' : ''); ?>">
                        <?php if ($i < 6): ?>
                        <div class="setup-step-line"></div>
                        <?php endif; ?>
                        <div class="setup-step-number <?php echo $i < $step ? 'setup-step-number-completed' : ($i === $step ? 'setup-step-number-active' : 'setup-step-number-pending'); ?>">
                            <?php echo $i < $step ? '✓' : $i; ?>
                        </div>
                        <div class="setup-step-label <?php echo $i < $step ? 'setup-step-completed' : ($i === $step ? 'setup-step-active' : 'setup-step-pending'); ?>">
                            <?php echo $stepLabels[$i]; ?>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger m-0" style="border-radius:0; margin:0;">
                <span class="alert-icon">❌</span>
                <div class="alert-content">
                    <div class="alert-title">خطأ</div>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Step Content -->
            <div class="setup-card-body">
                <form method="POST" action="setup.php?step=<?php echo $step; ?>" id="setup-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <?php if ($step === 1): ?>
                    <!-- Step 1: Welcome / Requirements Check -->
                    <div class="text-center mb-6">
                        <div style="font-size:48px; margin-bottom:16px;">🎓</div>
                        <h2 class="mb-3">مرحباً بك في نظام التقييم الشامل</h2>
                        <p class="text-secondary">سيقوم هذا المعالج بتثبيت النظام خطوة بخطوة. يرجى التأكد من المتطلبات التالية قبل البدء.</p>
                    </div>

                    <h4 class="mb-4">فحص المتطلبات</h4>
                    <div class="requirement-item">
                        <span>إصدار PHP 7.4 أو أحدث</span>
                        <span class="requirement-status <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'pass' : 'fail'; ?>">
                            <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✓ ' . PHP_VERSION : '✗ ' . PHP_VERSION; ?>
                        </span>
                    </div>
                    <div class="requirement-item">
                        <span>امتداد PDO MySQL</span>
                        <span class="requirement-status <?php echo extension_loaded('pdo_mysql') ? 'pass' : 'fail'; ?>">
                            <?php echo extension_loaded('pdo_mysql') ? '✓ متوفر' : '✗ غير متوفر'; ?>
                        </span>
                    </div>
                    <div class="requirement-item">
                        <span>امتداد mbstring</span>
                        <span class="requirement-status <?php echo extension_loaded('mbstring') ? 'pass' : 'fail'; ?>">
                            <?php echo extension_loaded('mbstring') ? '✓ متوفر' : '✗ غير متوفر'; ?>
                        </span>
                    </div>
                    <div class="requirement-item">
                        <span>امتداد json</span>
                        <span class="requirement-status <?php echo extension_loaded('json') ? 'pass' : 'fail'; ?>">
                            <?php echo extension_loaded('json') ? '✓ متوفر' : '✗ غير متوفر'; ?>
                        </span>
                    </div>
                    <div class="requirement-item">
                        <span>صلاحية الكتابة على المجلد</span>
                        <span class="requirement-status <?php echo is_writable(__DIR__) ? 'pass' : 'fail'; ?>">
                            <?php echo is_writable(__DIR__) ? '✓ قابل للكتابة' : '✗ غير قابل للكتابة'; ?>
                        </span>
                    </div>
                    <div class="requirement-item">
                        <span>امتداد session</span>
                        <span class="requirement-status <?php echo extension_loaded('session') ? 'pass' : 'fail'; ?>">
                            <?php echo extension_loaded('session') ? '✓ متوفر' : '✗ غير متوفر'; ?>
                        </span>
                    </div>

                    <?php elseif ($step === 2): ?>
                    <!-- Step 2: Database Configuration -->
                    <h3 class="mb-4">إعدادات قاعدة البيانات</h3>
                    <p class="text-secondary mb-6">يرجى إدخال بيانات الاتصال بقاعدة البيانات MySQL.</p>

                    <div class="form-group">
                        <label class="form-label" for="db_host">مضيف قاعدة البيانات <span class="required">*</span></label>
                        <input type="text" class="form-control" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_SESSION['setup_db']['host'] ?? 'localhost'); ?>" required>
                        <span class="form-text">عادةً localhost أو 127.0.0.1</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_name">اسم قاعدة البيانات <span class="required">*</span></label>
                        <input type="text" class="form-control" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_SESSION['setup_db']['name'] ?? 'eqas_db'); ?>" required placeholder="eqas_db">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_user">اسم المستخدم <span class="required">*</span></label>
                        <input type="text" class="form-control" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_SESSION['setup_db']['user'] ?? 'root'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_pass">كلمة المرور</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($_SESSION['setup_db']['pass'] ?? ''); ?>">
                        <span class="form-text">اتركها فارغة إذا لم تكن هناك كلمة مرور</span>
                    </div>

                    <?php elseif ($step === 3): ?>
                    <!-- Step 3: Admin Account -->
                    <h3 class="mb-4">إنشاء حساب المدير</h3>
                    <p class="text-secondary mb-6">سيتم إنشاء حساب مدير النظام الافتراضي.</p>

                    <div class="form-group">
                        <label class="form-label" for="admin_fullname">الاسم الكامل <span class="required">*</span></label>
                        <input type="text" class="form-control" id="admin_fullname" name="admin_fullname" value="<?php echo htmlspecialchars($_SESSION['setup_admin']['full_name'] ?? 'مدير النظام'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_username">اسم المستخدم <span class="required">*</span></label>
                        <input type="text" class="form-control" id="admin_username" name="admin_username" value="<?php echo htmlspecialchars($_SESSION['setup_admin']['username'] ?? 'admin'); ?>" required data-rules="min:3">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_email">البريد الإلكتروني <span class="required">*</span></label>
                        <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($_SESSION['setup_admin']['email'] ?? ''); ?>" required data-rules="email">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password">كلمة المرور <span class="required">*</span></label>
                        <input type="password" class="form-control" id="admin_password" name="admin_password" required data-rules="min:8">
                        <span class="form-text">8 أحرف على الأقل</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password_confirm">تأكيد كلمة المرور <span class="required">*</span></label>
                        <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm" required>
                    </div>

                    <?php elseif ($step === 4): ?>
                    <!-- Step 4: School Information -->
                    <h3 class="mb-4">معلومات المدرسة</h3>
                    <p class="text-secondary mb-6">أدخل بيانات المدرسة الأساسية.</p>

                    <div class="form-group">
                        <label class="form-label" for="school_name">اسم المدرسة <span class="required">*</span></label>
                        <input type="text" class="form-control" id="school_name" name="school_name" value="<?php echo htmlspecialchars($_SESSION['setup_school']['name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="school_type">نوع المدرسة <span class="required">*</span></label>
                        <select class="form-control" id="school_type" name="school_type" required>
                            <option value="">-- اختر نوع المدرسة --</option>
                            <option value="primary" <?php echo (($_SESSION['setup_school']['type'] ?? '') === 'primary') ? 'selected' : ''; ?>>ابتدائية</option>
                            <option value="secondary" <?php echo (($_SESSION['setup_school']['type'] ?? '') === 'secondary') ? 'selected' : ''; ?>>ثانوية</option>
                            <option value="mixed" <?php echo (($_SESSION['setup_school']['type'] ?? '') === 'mixed') ? 'selected' : ''; ?>>مختلطة (ابتدائية + ثانوية)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="school_governorate">المحافظة <span class="required">*</span></label>
                        <select class="form-control" id="school_governorate" name="school_governorate" required>
                            <option value="">-- اختر المحافظة --</option>
                            <?php foreach ($governorates as $gov): ?>
                            <option value="<?php echo htmlspecialchars($gov); ?>" <?php echo (($_SESSION['setup_school']['governorate'] ?? '') === $gov) ? 'selected' : ''; ?>><?php echo htmlspecialchars($gov); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="school_directorate">المديرية</label>
                        <input type="text" class="form-control" id="school_directorate" name="school_directorate" value="<?php echo htmlspecialchars($_SESSION['setup_school']['directorate'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="school_address">العنوان</label>
                        <textarea class="form-control" id="school_address" name="school_address" rows="2"><?php echo htmlspecialchars($_SESSION['setup_school']['address'] ?? ''); ?></textarea>
                    </div>

                    <?php elseif ($step === 5): ?>
                    <!-- Step 5: Academic Year Setup -->
                    <h3 class="mb-4">إعداد العام الأكاديمي</h3>
                    <p class="text-secondary mb-6">حدد العام الأكاديمي الحالي للمدرسة.</p>

                    <div class="form-group">
                        <label class="form-label" for="year_name">اسم العام الأكاديمي <span class="required">*</span></label>
                        <input type="text" class="form-control" id="year_name" name="year_name" value="<?php echo htmlspecialchars($_SESSION['setup_year']['name'] ?? date('Y') . '/' . (date('Y') + 1)); ?>" required>
                        <span class="form-text">مثال: 2025/2026</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="year_start">تاريخ البدء <span class="required">*</span></label>
                        <input type="date" class="form-control" id="year_start" name="year_start" value="<?php echo htmlspecialchars($_SESSION['setup_year']['start'] ?? date('Y') . '-09-01'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="year_end">تاريخ الانتهاء <span class="required">*</span></label>
                        <input type="date" class="form-control" id="year_end" name="year_end" value="<?php echo htmlspecialchars($_SESSION['setup_year']['end'] ?? (date('Y') + 1) . '-06-30'); ?>" required>
                    </div>

                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <div class="alert-content">
                            سيتم تعيين هذا العام الأكاديمي كالعام النشط تلقائياً. يمكنك إضافة سنوات أخرى لاحقاً من لوحة التحكم.
                        </div>
                    </div>

                    <?php elseif ($step === 6): ?>
                    <!-- Step 6: Completion -->
                    <div class="text-center">
                        <div class="success-icon">🎉</div>
                        <h2 class="mb-3">تم التثبيت بنجاح!</h2>
                        <p class="text-secondary mb-6">تم تثبيت نظام التقييم الشامل لجودة التعليم المدرسي  بنجاح. يمكنك الآن تسجيل الدخول والبدء في استخدام النظام.</p>

                        <div class="card mb-6" style="text-align:right;">
                            <div class="card-header">
                                <h4 class="card-title mb-0">ملخص التثبيت</h4>
                            </div>
                            <div class="card-body">
                                <table style="width:100%;">
                                    <tr>
                                        <td class="font-semibold py-2" style="width:40%;">قاعدة البيانات:</td>
                                        <td class="py-2"><?php echo htmlspecialchars($_SESSION['setup_db']['name'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-semibold py-2">المدرسة:</td>
                                        <td class="py-2"><?php echo htmlspecialchars($_SESSION['setup_school']['name'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-semibold py-2">المحافظة:</td>
                                        <td class="py-2"><?php echo htmlspecialchars($_SESSION['setup_school']['governorate'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-semibold py-2">مدير النظام:</td>
                                        <td class="py-2"><?php echo htmlspecialchars($_SESSION['setup_admin']['username'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-semibold py-2">العام الأكاديمي:</td>
                                        <td class="py-2"><?php echo htmlspecialchars($_SESSION['setup_year']['name'] ?? ''); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="alert alert-warning">
                            <span class="alert-icon">⚠️</span>
                            <div class="alert-content">
                                <strong>مهم:</strong> يرجى حذف ملف setup.php بعد التثبيت لأسباب أمنية، والحفاظ على اسم المستخدم وكلمة المرور التي اخترتها في الخطوة السابقة.
                            </div>
                        </div>

                        <a href="login.php" class="btn btn-primary btn-lg" style="min-width:200px;">
                            تسجيل الدخول الآن ←
                        </a>
                    </div>
                    <?php endif; ?>

                </form>
            </div>

            <!-- Footer with navigation -->
            <?php if ($step < 6): ?>
            <div class="setup-card-footer">
                <?php if ($step > 1): ?>
                <a href="setup.php?step=<?php echo $step - 1; ?>" class="btn btn-light">→ السابق</a>
                <?php else: ?>
                <span></span>
                <?php endif; ?>

                <button type="submit" form="setup-form" class="btn btn-primary">
                    <?php echo $step === 5 ? 'تثبيت النظام ⚡' : 'التالي ←'; ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Version -->
        <div class="text-center mt-4" style="color:rgba(255,255,255,0.4); font-size:12px;">
            نظام التقييم الشامل لجودة التعليم المدرسي  - الإصدار 1.0.0
        </div>
    </div>

    <!-- Main JS -->
    <script src="assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password confirmation validation
        const passField = document.getElementById('admin_password');
        const passConfirmField = document.getElementById('admin_password_confirm');
        if (passField && passConfirmField) {
            passConfirmField.addEventListener('input', function() {
                if (this.value !== passField.value) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                    let feedback = this.parentElement.querySelector('.invalid-feedback');
                    if (!feedback) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        this.parentElement.appendChild(feedback);
                    }
                    feedback.textContent = 'كلمتا المرور غير متطابقتين';
                } else {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                }
            });
        }

        // Date validation
        const startDate = document.getElementById('year_start');
        const endDate = document.getElementById('year_end');
        if (startDate && endDate) {
            endDate.addEventListener('change', function() {
                if (new Date(this.value) <= new Date(startDate.value)) {
                    this.classList.add('is-invalid');
                    let feedback = this.parentElement.querySelector('.invalid-feedback');
                    if (!feedback) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        this.parentElement.appendChild(feedback);
                    }
                    feedback.textContent = 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء';
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        }

        // Form submission loading state
        const setupForm = document.getElementById('setup-form');
        if (setupForm) {
            setupForm.addEventListener('submit', function() {
                const submitBtn = this.closest('.setup-card')?.querySelector('[type="submit"]');
                if (submitBtn) {
                    toggleLoading(submitBtn, true);
                }
            });
        }
    });
    </script>
</body>
</html>
