<?php
/**
 * ============================================================
 * EQAS - Login Page
 * نظام التقييم الشامل - صفحة تسجيل الدخول
 * ============================================================
 */

// Start session (same name as session.php to avoid redirect loop)
session_name('EQAS_SESSION');
session_start();

// Include required files
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Error and success messages
$error = '';
$lockoutMessage = '';
$successMessage = '';

// Check for session messages
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if (isset($_SESSION['login_lockout'])) {
    $lockoutMessage = $_SESSION['login_lockout'];
    unset($_SESSION['login_lockout']);
}

if (isset($_SESSION['login_success'])) {
    $successMessage = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

if (isset($_SESSION['flash_message'])) {
    $successMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'رمز الأمان غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        // Validate inputs
        if (empty($username) || empty($password)) {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور.';
        } else {
            // Brute force protection
            $maxAttempts = 5;
            $lockoutTime = 15 * 60; // 15 minutes
            $attemptsKey = 'login_attempts_' . md5($username . '_' . ($_SERVER['REMOTE_ADDR'] ?? ''));

            // Get current attempts
            $attempts = $_SESSION[$attemptsKey] ?? 0;
            $lastAttemptTime = $_SESSION[$attemptsKey . '_time'] ?? 0;

            // Check lockout
            if ($attempts >= $maxAttempts && (time() - $lastAttemptTime) < $lockoutTime) {
                $remainingTime = ceil(($lockoutTime - (time() - $lastAttemptTime)) / 60);
                $lockoutMessage = "تم تجاوز الحد الأقصى لمحاولات تسجيل الدخول. يرجى المحاولة مرة أخرى بعد {$remainingTime} دقيقة.";
            } else {
                // Reset attempts if lockout period expired
                if ($attempts >= $maxAttempts && (time() - $lastAttemptTime) >= $lockoutTime) {
                    $_SESSION[$attemptsKey] = 0;
                    $attempts = 0;
                }

                // Attempt to authenticate
                try {
                    if (isset($db)) {
                        $stmt = $db->prepare("
                            SELECT u.*, r.name AS role_name, r.rank AS role_rank
                            FROM users u
                            JOIN roles r ON u.role_id = r.id
                            WHERE u.username = ? AND u.is_active = 1
                            LIMIT 1
                        ");
                        $stmt->execute([$username]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($user && password_verify($password, $user['password_hash'])) {
                            // التحقق من أن الحساب ليس مقفلاً
                            if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
                                $remainingMinutes = ceil((strtotime($user['lockout_until']) - time()) / 60);
                                $lockoutMessage = "الحساب مقفل مؤقتاً. حاول بعد {$remainingMinutes} دقيقة.";
                            } else {
                                // Login successful
                                $_SESSION['user_id'] = $user['id'];
                                $_SESSION['user'] = [
                                    'id' => $user['id'],
                                    'username' => $user['username'],
                                    'full_name' => $user['full_name_ar'],
                                    'role' => $user['role_name'],
                                    'role_rank' => $user['role_rank'],
                                    'school_id' => $user['school_id'] ?? null,
                                    'email' => $user['email'] ?? ''
                                ];
                                $_SESSION['school_id'] = $user['school_id'];
                                $_SESSION['last_activity'] = time();
                                $_SESSION['fingerprint'] = hash('sha256',
                                    $_SERVER['HTTP_USER_AGENT'] .
                                    ($_SERVER['REMOTE_ADDR'] ?? '') .
                                    (defined('SESSION_FINGERPRINT_SALT') ? SESSION_FINGERPRINT_SALT : 'EQAS_SecureSalt_2026_v203')
                                );
                                $_SESSION['password_hash_sig'] = hash('sha256', $user['password_hash']);
                                $_SESSION['regenerated_at'] = time();

                                // Reset login attempts in DB
                                $stmt = $db->prepare("UPDATE users SET login_attempts = 0, lockout_until = NULL, last_login_at = NOW() WHERE id = ?");
                                $stmt->execute([$user['id']]);

                                // Reset session-based attempts
                                unset($_SESSION[$attemptsKey]);
                                unset($_SESSION[$attemptsKey . '_time']);

                                // Set remember me cookie
                                if ($remember) {
                                    $token = bin2hex(random_bytes(32));
                                    setcookie('eqas_remember', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                                }

                                // Regenerate session ID for security
                                session_regenerate_id(true);

                                // تعيين سياق العام الدراسي الحالي
                                try {
                                    $stmt = $db->query("SELECT id FROM academic_years WHERE is_current = 1 AND is_active = 1 LIMIT 1");
                                    $year = $stmt->fetch();
                                    if ($year) {
                                        $_SESSION['academic_year_id'] = $year['id'];
                                    }
                                } catch (Exception $e) {}

                                // التحقق من إجبار تغيير كلمة المرور
                                if (!empty($user['must_change_password'])) {
                                    header('Location: change_password.php?force=1');
                                    exit;
                                }

                                // Redirect to dashboard
                                header('Location: index.php');
                                exit;
                            }
                        } else {
                            // Login failed - increment DB attempts
                            try {
                                $stmt = $db->prepare("UPDATE users SET login_attempts = login_attempts + 1 WHERE username = ?");
                                $stmt->execute([$username]);

                                $stmt = $db->prepare("SELECT login_attempts FROM users WHERE username = ?");
                                $stmt->execute([$username]);
                                $dbUser = $stmt->fetch();
                                if ($dbUser && $dbUser['login_attempts'] >= $maxAttempts) {
                                    $lockoutUntil = date('Y-m-d H:i:s', time() + $lockoutTime);
                                    $stmt = $db->prepare("UPDATE users SET lockout_until = ? WHERE username = ?");
                                    $stmt->execute([$lockoutUntil, $username]);
                                    $lockoutMessage = 'تم تجاوز الحد الأقصى لمحاولات تسجيل الدخول. يرجى المحاولة مرة أخرى بعد 15 دقيقة.';
                                }
                            } catch (Exception $e) {}

                            // Session-based tracking as backup
                            $_SESSION[$attemptsKey] = $attempts + 1;
                            $_SESSION[$attemptsKey . '_time'] = time();

                            $remaining = $maxAttempts - ($attempts + 1);
                            if ($remaining <= 0 && empty($lockoutMessage)) {
                                $lockoutMessage = 'تم تجاوز الحد الأقصى لمحاولات تسجيل الدخول. يرجى المحاولة مرة أخرى بعد 15 دقيقة.';
                            } elseif (empty($lockoutMessage)) {
                                $error = "اسم المستخدم أو كلمة المرور غير صحيحة. محاولات متبقية: {$remaining}";
                            }
                        }
                    } else {
                        // قاعدة البيانات غير متصلة - لا يمكن تسجيل الدخول
                        $error = 'لا يمكن الاتصال بقاعدة البيانات. يرجى التواصل مع مدير النظام أو تشغيل صفحة التثبيت.';
                    }
                } catch (Exception $e) {
                    $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
                    error_log('Login Error: ' . $e->getMessage());
                }
            }
        }
    }

    // Regenerate CSRF token after failed attempt
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="تسجيل الدخول - نظام التقييم الشامل لجودة التعليم المدرسي ">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

    <title>تسجيل الدخول | نظام التقييم الشامل</title>

    <!-- Login Stylesheet -->
    <link rel="stylesheet" href="assets/css/login.css">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
    <!-- Decorative Background Circles -->
    <div class="login-bg-circle"></div>
    <div class="login-bg-circle"></div>
    <div class="login-bg-circle"></div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="login-card">

            <!-- Login Header -->
            <div class="login-header">
                <div class="login-logo"></div>
                <h1 class="login-title">نظام التقييم الشامل</h1>
                <p class="login-subtitle">لجودة التعليم المدرسي </p>
            </div>

            <!-- Login Body / Form -->
            <div class="login-body">
                <!-- Success Message -->
                <?php if ($successMessage): ?>
                <div class="login-success show">
                    <span class="login-success-icon">✅</span>
                    <span class="login-success-text"><?php echo htmlspecialchars($successMessage); ?></span>
                </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if ($error): ?>
                <div class="login-error show" id="login-error">
                    <span class="login-error-icon">❌</span>
                    <div class="login-error-content">
                        <div class="login-error-title">خطأ في تسجيل الدخول</div>
                        <div class="login-error-text"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Lockout Message -->
                <?php if ($lockoutMessage): ?>
                <div class="login-lockout show" id="login-lockout">
                    <div class="login-lockout-icon">🔒</div>
                    <div class="login-lockout-title">تم تأمين الحساب</div>
                    <div class="login-lockout-text"><?php echo htmlspecialchars($lockoutMessage); ?></div>
                </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="login.php" id="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Username -->
                    <div class="login-form-group">
                        <label class="login-form-label" for="username">
                            اسم المستخدم
                            <span class="required">*</span>
                        </label>
                        <div class="login-input-wrapper">
                            <input type="text"
                                   class="login-form-control <?php echo (!empty($error) && empty($_POST['username'])) ? 'is-invalid' : ''; ?>"
                                   id="username"
                                   name="username"
                                   placeholder="أدخل اسم المستخدم"
                                   required
                                   autofocus
                                   autocomplete="username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            <span class="login-input-icon">👤</span>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="login-form-group">
                        <label class="login-form-label" for="password">
                            كلمة المرور
                            <span class="required">*</span>
                        </label>
                        <div class="login-input-wrapper">
                            <input type="password"
                                   class="login-form-control <?php echo (!empty($error) && empty($_POST['password'])) ? 'is-invalid' : ''; ?>"
                                   id="password"
                                   name="password"
                                   placeholder="أدخل كلمة المرور"
                                   required
                                   autocomplete="current-password">
                            <span class="login-input-icon">🔒</span>
                            <button type="button" class="password-toggle" aria-label="إظهار كلمة المرور">👁️</button>
                        </div>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="login-options">
                        <label class="login-checkbox">
                            <input type="checkbox" name="remember" id="remember" value="1">
                            <span class="login-checkbox-label">تذكرني</span>
                        </label>
                        <a href="#" class="login-forgot-link">نسيت كلمة المرور؟</a>
                    </div>

                    <!-- Login Button -->
                    <button type="submit" class="login-btn" id="login-btn">
                        تسجيل الدخول
                    </button>
                </form>
            </div>

            <!-- Login Footer -->
            <div class="login-footer">
                <p class="login-footer-text">
                    نظام التقييم الشامل لجودة التعليم المدرسي  (EQAS)
                    <br>
                    <a href="setup.php">تثبيت النظام</a> | <a href="#">الدعم الفني</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Version Info -->
    <div class="login-version">
        الإصدار 1.0.0 | © <?php echo date('Y'); ?> EQAS
    </div>

    <!-- Login Page JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const loginForm = document.getElementById('login-form');
        const loginBtn = document.getElementById('login-btn');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');

        // Form submission with loading state
        loginForm.addEventListener('submit', function() {
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
        });

        // Auto-focus username
        if (!usernameInput.value) {
            usernameInput.focus();
        } else {
            passwordInput.focus();
        }

        // Clear error on input
        const errorEl = document.getElementById('login-error');
        if (errorEl) {
            usernameInput.addEventListener('input', function() {
                errorEl.classList.remove('show');
                this.classList.remove('is-invalid');
            });
            passwordInput.addEventListener('input', function() {
                errorEl.classList.remove('show');
                this.classList.remove('is-invalid');
            });
        }

        // Password visibility toggle
        const toggleBtn = document.querySelector('.password-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                this.textContent = isPassword ? '🙈' : '👁️';
                this.setAttribute('aria-label', isPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            });
        }
    });
    </script>
</body>
</html>
