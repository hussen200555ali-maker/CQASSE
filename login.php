<?php
/**
 * ============================================================
 * EQAS - Login Page
 * نظام التقييم الشامل - صفحة تسجيل الدخول
 * ============================================================
 */

// Include required files (CRITICAL ORDER: database → helpers → session)
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/session.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ' . baseUrl('index.php'));
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
                                $_SESSION['session_nonce'] = bin2hex(random_bytes(16));
                                $_SESSION['fingerprint'] = hash('sha256',
                                    ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') .
                                    $_SESSION['session_nonce'] .
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
                                    header('Location: ' . baseUrl('change_password.php?force=1'));
                                    exit;
                                }

                                // Redirect to dashboard
                                header('Location: ' . baseUrl('index.php'));
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
    <meta name="description" content="تسجيل الدخول - نظام التقييم الشامل لجودة التعليم المدرسي">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

    <title>تسجيل الدخول | نظام التقييم الشامل</title>

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="assets/css/all.min.css?v=6.5.1">
    <!-- Login Stylesheet -->
    <link rel="stylesheet" href="assets/css/login.css?v=<?php echo filemtime(__DIR__ . '/assets/css/login.css'); ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
</head>
<body>
    <!-- ===== Background Analytics Grid & Decorations ===== -->
    <div class="bg-grid"></div>

    <!-- Floating analytics icons -->
    <div class="bg-icon bg-icon-1"><i class="fas fa-chart-bar"></i></div>
    <div class="bg-icon bg-icon-2"><i class="fas fa-chart-line"></i></div>
    <div class="bg-icon bg-icon-3"><i class="fas fa-chart-pie"></i></div>
    <div class="bg-icon bg-icon-4"><i class="fas fa-clipboard-check"></i></div>
    <div class="bg-icon bg-icon-5"><i class="fas fa-check-double"></i></div>
    <div class="bg-icon bg-icon-6"><i class="fas fa-gauge-high"></i></div>

    <!-- ===== Version Bar ===== -->
    <div class="version-bar">
        <span class="version-badge">v4.0.0</span>
        <span class="version-sep">|</span>
        <span class="version-env">بيئة تطوير</span>
        <span class="version-sep">|</span>
        <span class="version-copy">&copy; <?php echo date('Y'); ?> CQASSE</span>
    </div>

    <!-- ===== Main Wrapper ===== -->
    <div class="login-wrapper">

        <!-- ===== Login Container (left: form, right: standards) ===== -->
        <div class="login-container">

            <!-- ===== LEFT: Brand + Form ===== -->
            <div class="login-main">

                <!-- Brand -->
                <div class="login-brand">
                    <img src="assets/img/logo-login.svg" alt="CQASSE" class="login-seal" width="100" height="100">
                    <h1 class="login-title">نظام التقييم الشامل</h1>
                    <p class="login-subtitle">لجودة التعليم المدرسي</p>
                    <div class="brand-divider"></div>
                </div>

                <!-- Messages -->
                <?php if ($successMessage): ?>
                <div class="msg msg-success show" id="msg-success">
                    <i class="fas fa-check-circle msg-icon"></i>
                    <span><?php echo htmlspecialchars($successMessage); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="msg msg-error show" id="msg-error">
                    <i class="fas fa-exclamation-circle msg-icon"></i>
                    <div class="msg-body">
                        <div class="msg-title">خطأ في تسجيل الدخول</div>
                        <div class="msg-text"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($lockoutMessage): ?>
                <div class="msg msg-lockout show" id="msg-lockout">
                    <i class="fas fa-shield-halved msg-icon"></i>
                    <div class="msg-body">
                        <div class="msg-title">تم تأمين الحساب</div>
                        <div class="msg-text"><?php echo htmlspecialchars($lockoutMessage); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" action="login.php" class="login-form" id="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Username -->
                    <div class="form-group">
                        <label class="form-label" for="username">اسم المستخدم <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="text"
                                   class="form-control <?php echo (!empty($error) && empty($_POST['username'])) ? 'is-invalid' : ''; ?>"
                                   id="username" name="username"
                                   placeholder="أدخل اسم المستخدم"
                                   required autofocus
                                   autocomplete="username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            <i class="fas fa-user input-icon"></i>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label class="form-label" for="password">كلمة المرور <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password"
                                   class="form-control <?php echo (!empty($error) && empty($_POST['password'])) ? 'is-invalid' : ''; ?>"
                                   id="password" name="password"
                                   placeholder="أدخل كلمة المرور"
                                   required
                                   autocomplete="current-password">
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="pwd-toggle" aria-label="إظهار كلمة المرور" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Options -->
                    <div class="form-options">
                        <label class="form-checkbox">
                            <input type="checkbox" name="remember" id="remember" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">تذكرني</span>
                        </label>
                        <a href="#" class="forgot-link">نسيت كلمة المرور؟</a>
                    </div>

                    <div style="text-align:center;margin-bottom:12px;">
                        <a href="pages/parents/register.php" style="font-size:13px;color:#667eea;text-decoration:none;">
                            🔗 تسجيل ولي أمر جديد
                        </a>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="login-btn" id="login-btn">
                        <span class="btn-text">تسجيل الدخول</span>
                        <i class="fas fa-arrow-left btn-arrow"></i>
                    </button>
                </form>

                <!-- Help links -->
                <div class="login-links">
                    <a href="setup.php" class="link-item"><i class="fas fa-cog"></i> تثبيت النظام</a>
                    <span class="link-sep">|</span>
                    <a href="#" class="link-item"><i class="fas fa-headset"></i> الدعم الفني</a>
                </div>

            </div><!-- /login-main -->

            <!-- ===== RIGHT: Standards Scale ===== -->
            <div class="standards-panel">
                <div class="standards-header">
                    <i class="fas fa-ruler-combined"></i>
                    <span>معايير الجودة</span>
                </div>

                <div class="standards-list">
                    <!-- 1. Academic -->
                    <div class="standard-item" data-pct="35">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-graduation-cap"></i></span>
                            <span class="std-name">الأداء الأكاديمي</span>
                            <span class="std-pct">35%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="35"></div>
                        </div>
                    </div>

                    <!-- 2. Teaching -->
                    <div class="standard-item" data-pct="25">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-chalkboard"></i></span>
                            <span class="std-name">جودة التدريس</span>
                            <span class="std-pct">25%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="25"></div>
                        </div>
                    </div>

                    <!-- 3. Climate -->
                    <div class="standard-item" data-pct="15">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-school"></i></span>
                            <span class="std-name">المناخ المدرسي</span>
                            <span class="std-pct">15%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="15"></div>
                        </div>
                    </div>

                    <!-- 4. Behavior -->
                    <div class="standard-item" data-pct="10">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-star"></i></span>
                            <span class="std-name">السلوك</span>
                            <span class="std-pct">10%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="10"></div>
                        </div>
                    </div>

                    <!-- 5. Skills -->
                    <div class="standard-item" data-pct="10">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-bullseye"></i></span>
                            <span class="std-name">المهارات</span>
                            <span class="std-pct">10%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="10"></div>
                        </div>
                    </div>

                    <!-- 6. Attendance -->
                    <div class="standard-item" data-pct="5">
                        <div class="std-top">
                            <span class="std-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="std-name">الحضور</span>
                            <span class="std-pct">5%</span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill" style="width:0%" data-width="5"></div>
                        </div>
                    </div>
                </div>

                <div class="standards-footer">
                    <i class="fas fa-balance-scale"></i>
                    <span>مؤشر جودة التعليم (EQI)</span>
                </div>
            </div><!-- /standards-panel -->

        </div><!-- /login-container -->

        <!-- ===== Tagline Footer ===== -->
        <div class="tagline-bar">
            <span class="tagline-text">يكشف الحقيقة</span>
            <span class="tagline-dot">♦</span>
            <span class="tagline-text">يبني الواقع</span>
            <span class="tagline-dot">♦</span>
            <span class="tagline-text">يرتقي بالتعليم والمجتمع</span>
        </div>

    </div><!-- /login-wrapper -->

    <!-- ===== JavaScript ===== -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const loginForm = document.getElementById('login-form');
        const loginBtn = document.getElementById('login-btn');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');

        /* ─── Animate standards bars on load ─── */
        const fillBars = document.querySelectorAll('.std-fill');
        fillBars.forEach(function(bar, index) {
            var delay = 400 + (index * 180);
            var target = bar.getAttribute('data-width');
            setTimeout(function() {
                bar.style.width = target + '%';
                bar.parentElement.classList.add('filled');
            }, delay);
        });

        /* ─── Form submission with loading state ─── */
        loginForm.addEventListener('submit', function() {
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
        });

        /* ─── Auto-focus ─── */
        if (!usernameInput.value) {
            usernameInput.focus();
        } else {
            passwordInput.focus();
        }

        /* ─── Clear error on input ─── */
        var errorEl = document.getElementById('msg-error');
        if (errorEl) {
            function clearError() {
                errorEl.classList.remove('show');
                usernameInput.classList.remove('is-invalid');
                passwordInput.classList.remove('is-invalid');
            }
            usernameInput.addEventListener('input', clearError);
            passwordInput.addEventListener('input', clearError);
        }

        /* ─── Password visibility toggle ─── */
        var toggleBtn = document.querySelector('.pwd-toggle');
        var toggleIcon = toggleBtn ? toggleBtn.querySelector('i') : null;
        if (toggleBtn && toggleIcon) {
            toggleBtn.addEventListener('click', function() {
                var isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                toggleIcon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
                toggleBtn.setAttribute('aria-label', isPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            });
        }

        /* ─── Golden ripple on button click ─── */
        if (loginBtn) {
            loginBtn.addEventListener('click', function(e) {
                if (this.disabled) return;
                var ripple = document.createElement('span');
                ripple.className = 'ripple-effect';
                var rect = this.getBoundingClientRect();
                var x = e.clientX - rect.left;
                var y = e.clientY - rect.top;
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                this.appendChild(ripple);
                setTimeout(function() { ripple.remove(); }, 700);
            });
        }

        /* ─── Lockout countdown ─── */
        var lockoutEl = document.getElementById('msg-lockout');
        if (lockoutEl) {
            var textEl = lockoutEl.querySelector('.msg-text');
            var match = textEl ? textEl.textContent.match(/(\d+)\s*دقيقة/) : null;
            if (match) {
                var minutes = parseInt(match[1], 10);
                var totalSeconds = minutes * 60;
                var timerEl = document.createElement('div');
                timerEl.className = 'lockout-timer';
                lockoutEl.querySelector('.msg-body').appendChild(timerEl);
                var interval = setInterval(function() {
                    var m = Math.floor(totalSeconds / 60);
                    var s = totalSeconds % 60;
                    timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
                    if (totalSeconds <= 0) clearInterval(interval);
                    totalSeconds--;
                }, 1000);
            }
        }
    });
    </script>
</body>
</html>
