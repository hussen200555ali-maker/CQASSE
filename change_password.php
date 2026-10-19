<?php
/**
 * ============================================================
 * تغيير كلمة المرور الإجباري
 * نظام التقييم الشامل لجودة التعليم المدرسي - EQAS
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/security_headers.php';

$pdo = Database::getInstance()->getConnection();

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user']['id'];
$isForced = isset($_GET['force']);

// جلب بيانات المستخدم
$stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// إذا لم يكن مطلوباً تغيير كلمة المرور وليس في وضع force
if (!$isForced && empty($user['must_change_password'])) {
    header('Location: index.php');
    exit;
}

$errors = [];

// معالجة تغيير كلمة المرور
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_new_password'] ?? '';

    // التحقق من كلمة المرور الحالية
    if (empty($currentPassword)) {
        $errors['current_password'] = 'كلمة المرور الحالية مطلوبة';
    } else {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($currentPassword, $hash)) {
            $errors['current_password'] = 'كلمة المرور الحالية غير صحيحة';
        }
    }

    if (empty($newPassword)) {
        $errors['new_password'] = 'كلمة المرور الجديدة مطلوبة';
    } elseif (mb_strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        $errors['new_password'] = 'كلمة المرور يجب أن تكون ' . PASSWORD_MIN_LENGTH . ' أحرف على الأقل';
    }

    if ($newPassword !== $confirmPassword) {
        $errors['confirm_new_password'] = 'كلمتا المرور غير متطابقتين';
    }

    if ($currentPassword === $newPassword) {
        $errors['new_password'] = 'كلمة المرور الجديدة يجب أن تختلف عن الحالية';
    }

    if (empty($errors)) {
        try {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newHash, $userId]);

            $_SESSION['password_hash_sig'] = hash('sha256', $newHash);
            $_SESSION['flash_message'] = 'تم تغيير كلمة المرور بنجاح';
            $_SESSION['flash_type'] = 'success';
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'حدث خطأ أثناء تغيير كلمة المرور';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="تغيير كلمة المرور - نظام التقييم الشامل لجودة التعليم المدرسي">
    <title>تغيير كلمة المرور | نظام التقييم الشامل</title>

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

        <!-- ===== Login Container (left: form, right: password policy) ===== -->
        <div class="login-container" style="max-width:860px;">

            <!-- ===== LEFT: Brand + Form ===== -->
            <div class="login-main">

                <!-- Brand -->
                <div class="login-brand">
                    <img src="assets/img/logo-login.svg" alt="CQASSE" class="login-seal" width="100" height="100">
                    <h1 class="login-title">تغيير كلمة المرور</h1>
                    <?php if ($isForced): ?>
                    <p class="login-subtitle" style="color:#dc3545;font-weight:700;">
                        <i class="fas fa-shield-halved" style="margin-left:4px;"></i>يجب تغيير كلمة المرور قبل المتابعة
                    </p>
                    <?php else: ?>
                    <p class="login-subtitle">تأمين حسابك بكلمة مرور قوية</p>
                    <?php endif; ?>
                    <div class="brand-divider"></div>
                </div>

                <!-- Messages -->
                <?php if (!empty($errors['general'])): ?>
                <div class="msg msg-error show">
                    <i class="fas fa-exclamation-circle msg-icon"></i>
                    <div class="msg-body">
                        <div class="msg-title">خطأ في التحديث</div>
                        <div class="msg-text"><?php echo htmlspecialchars($errors['general']); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" action="" class="login-form" id="change-password-form" autocomplete="off">

                    <!-- Current Password -->
                    <div class="form-group">
                        <label class="form-label" for="current_password">كلمة المرور الحالية <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password"
                                   class="form-control <?php echo isset($errors['current_password']) ? 'is-invalid' : ''; ?>"
                                   id="current_password" name="current_password"
                                   placeholder="أدخل كلمة المرور الحالية"
                                   required autofocus
                                   autocomplete="current-password">
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="pwd-toggle" aria-label="إظهار كلمة المرور" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['current_password'])): ?>
                            <div class="invalid-feedback" style="color:#dc3545;font-size:12px;margin-top:4px;display:block;">
                                <i class="fas fa-exclamation-circle" style="margin-left:3px;"></i><?php echo htmlspecialchars($errors['current_password']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label class="form-label" for="new_password">كلمة المرور الجديدة <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password"
                                   class="form-control <?php echo isset($errors['new_password']) ? 'is-invalid' : ''; ?>"
                                   id="new_password" name="new_password"
                                   placeholder="أدخل كلمة المرور الجديدة"
                                   required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                                   autocomplete="new-password">
                            <i class="fas fa-key input-icon"></i>
                            <button type="button" class="pwd-toggle" aria-label="إظهار كلمة المرور" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['new_password'])): ?>
                            <div class="invalid-feedback" style="color:#dc3545;font-size:12px;margin-top:4px;display:block;">
                                <i class="fas fa-exclamation-circle" style="margin-left:3px;"></i><?php echo htmlspecialchars($errors['new_password']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group">
                        <label class="form-label" for="confirm_new_password">تأكيد كلمة المرور الجديدة <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password"
                                   class="form-control <?php echo isset($errors['confirm_new_password']) ? 'is-invalid' : ''; ?>"
                                   id="confirm_new_password" name="confirm_new_password"
                                   placeholder="أعد إدخال كلمة المرور الجديدة"
                                   required
                                   autocomplete="new-password">
                            <i class="fas fa-check-double input-icon"></i>
                            <button type="button" class="pwd-toggle" aria-label="إظهار كلمة المرور" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['confirm_new_password'])): ?>
                            <div class="invalid-feedback" style="color:#dc3545;font-size:12px;margin-top:4px;display:block;">
                                <i class="fas fa-exclamation-circle" style="margin-left:3px;"></i><?php echo htmlspecialchars($errors['confirm_new_password']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="login-btn" id="submit-btn">
                        <span class="btn-text">تغيير كلمة المرور</span>
                        <i class="fas fa-arrow-left btn-arrow"></i>
                    </button>
                </form>

                <!-- Back link -->
                <div class="login-links">
                    <a href="index.php" class="link-item"><i class="fas fa-arrow-right"></i> العودة إلى لوحة القيادة</a>
                </div>

            </div><!-- /login-main -->

            <!-- ===== RIGHT: Password Requirements Panel ===== -->
            <div class="standards-panel" id="password-policy-panel">
                <div class="standards-header">
                    <i class="fas fa-shield-halved"></i>
                    <span>متطلبات كلمة المرور</span>
                </div>

                <div class="standards-list" id="requirements-list">
                    <!-- 1. Length -->
                    <div class="standard-item requirement-item" data-req="length">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-text-height"></i></span>
                            <span class="std-name"><?php echo PASSWORD_MIN_LENGTH; ?> أحرف على الأقل</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>

                    <!-- 2. Uppercase -->
                    <div class="standard-item requirement-item" data-req="uppercase">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-font"></i></span>
                            <span class="std-name">حرف كبير (A-Z)</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>

                    <!-- 3. Lowercase -->
                    <div class="standard-item requirement-item" data-req="lowercase">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-font"></i></span>
                            <span class="std-name">حرف صغير (a-z)</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>

                    <!-- 4. Number -->
                    <div class="standard-item requirement-item" data-req="number">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-hashtag"></i></span>
                            <span class="std-name">رقم (0-9)</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>

                    <!-- 5. Special -->
                    <div class="standard-item requirement-item" data-req="special">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-star"></i></span>
                            <span class="std-name">رمز خاص (!@#$%^&amp;*)</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>

                    <!-- 6. Match -->
                    <div class="standard-item requirement-item" data-req="match">
                        <div class="std-top">
                            <span class="std-icon req-icon"><i class="fas fa-check-double"></i></span>
                            <span class="std-name">تطابق التأكيد</span>
                            <span class="std-pct req-status"><i class="fas fa-circle"></i></span>
                        </div>
                        <div class="std-track">
                            <div class="std-fill req-fill" style="width:0%" data-width="100"></div>
                        </div>
                    </div>
                </div>

                <!-- Strength meter footer -->
                <div class="standards-footer" id="strength-footer">
                    <i class="fas fa-shield" id="strength-icon"></i>
                    <span id="strength-label">أدخل كلمة مرور جديدة للبدء</span>
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
        var form = document.getElementById('change-password-form');
        var submitBtn = document.getElementById('submit-btn');

        var currentPwd = document.getElementById('current_password');
        var newPwd = document.getElementById('new_password');
        var confirmPwd = document.getElementById('confirm_new_password');

        var reqItems = document.querySelectorAll('.requirement-item');
        var strengthLabel = document.getElementById('strength-label');
        var strengthIcon = document.getElementById('strength-icon');

        /* ─── Password visibility toggles ─── */
        var toggleBtns = document.querySelectorAll('.pwd-toggle');
        toggleBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrap = this.closest('.input-wrap');
                var input = wrap.querySelector('input[type="password"], input[type="text"]');
                var icon = this.querySelector('i');
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                icon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
                this.setAttribute('aria-label', isPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            });
        });

        /* ─── Live requirements checker ─── */
        function checkRequirements() {
            var val = newPwd.value;
            var confirmVal = confirmPwd.value;
            var results = {
                length: val.length >= <?php echo PASSWORD_MIN_LENGTH; ?>,
                uppercase: /[A-Z]/.test(val),
                lowercase: /[a-z]/.test(val),
                number: /[0-9]/.test(val),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(val),
                match: val.length > 0 && val === confirmVal
            };

            reqItems.forEach(function(item) {
                var req = item.getAttribute('data-req');
                var passed = results[req];
                var icon = item.querySelector('.req-status i');
                var fill = item.querySelector('.req-fill');
                var track = item.querySelector('.std-track');
                var name = item.querySelector('.std-name');

                if (passed) {
                    icon.className = 'fas fa-check-circle';
                    icon.style.color = '#28a745';
                    fill.style.width = '100%';
                    fill.style.background = 'linear-gradient(90deg, #28a745, #34ce57)';
                    track.classList.add('filled');
                    name.style.color = '#28a745';
                } else {
                    icon.className = 'fas fa-circle';
                    icon.style.color = '#adb5bd';
                    fill.style.width = '0%';
                    fill.style.background = '';
                    track.classList.remove('filled');
                    name.style.color = '';
                }
            });

            /* ─── Strength meter ─── */
            var passedCount = Object.values(results).filter(Boolean).length;
            var total = Object.keys(results).length;

            if (val.length === 0) {
                strengthLabel.textContent = 'أدخل كلمة مرور جديدة للبدء';
                strengthLabel.style.color = '#6c757d';
                strengthIcon.className = 'fas fa-shield';
                strengthIcon.style.color = '#6c757d';
                return;
            }

            if (passedCount === total) {
                var strengthLevels = [
                    { min: 8, label: 'قوية جداً', icon: 'fa-shield-halved', color: '#1a5632' },
                    { min: 6, label: 'قوية', icon: 'fa-shield', color: '#2d7a4a' },
                    { min: 0, label: 'متوسطة', icon: 'fa-shield', color: '#c8a951' }
                ];
                var match = strengthLevels.find(function(s) { return val.length >= s.min; }) || strengthLevels[2];
                strengthLabel.textContent = 'قوة كلمة المرور: ' + match.label;
                strengthLabel.style.color = match.color;
                strengthIcon.className = 'fas ' + match.icon;
                strengthIcon.style.color = match.color;
            } else {
                strengthLabel.textContent = passedCount + ' من ' + total + ' متطلبات fulfilled';
                strengthLabel.style.color = '#dc3545';
                strengthIcon.className = 'fas fa-exclamation-triangle';
                strengthIcon.style.color = '#dc3545';
            }
        }

        newPwd.addEventListener('input', checkRequirements);
        confirmPwd.addEventListener('input', checkRequirements);

        /* ─── Animate requirement items on load ─── */
        function animateRequirements() {
            reqItems.forEach(function(item, index) {
                var delay = 300 + (index * 120);
                setTimeout(function() {
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, delay);
            });
        }

        /* Set initial state for animation */
        reqItems.forEach(function(item) {
            item.style.opacity = '0';
            item.style.transform = 'translateX(10px)';
            item.style.transition = 'opacity 0.5s cubic-bezier(0.22,1,0.36,1), transform 0.5s cubic-bezier(0.22,1,0.36,1)';
        });
        animateRequirements();

        /* ─── Clear errors on input ─── */
        var allInputs = [currentPwd, newPwd, confirmPwd];
        allInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
                var feedback = this.closest('.form-group').querySelector('.invalid-feedback');
                if (feedback) { feedback.style.display = 'none'; }
            });
        });

        /* ─── Form submission with loading state ─── */
        form.addEventListener('submit', function() {
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });

        /* ─── Golden ripple on button click ─── */
        submitBtn.addEventListener('click', function(e) {
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

        /* ─── Auto-focus ─── */
        if (currentPwd) { currentPwd.focus(); }
    });
    </script>
</body>
</html>
