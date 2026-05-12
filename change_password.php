<?php
/**
 * ============================================================
 * تغيير كلمة المرور الإجباري
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
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

$pageTitle = 'تغيير كلمة المرور';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | EQAS</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-bg-circle"></div>
    <div class="login-bg-circle"></div>
    <div class="login-bg-circle"></div>

    <div class="login-container">
        <div class="login-card" style="max-width:480px;">
            <div class="login-header">
                <div class="login-logo"></div>
                <h1 class="login-title">تغيير كلمة المرور</h1>
                <?php if ($isForced): ?>
                <p class="login-subtitle" style="color:var(--warning);font-weight:bold;">
                    ⚠️ يجب تغيير كلمة المرور قبل المتابعة
                </p>
                <?php endif; ?>
            </div>

            <div class="login-body">
                <?php if (!empty($errors['general'])): ?>
                    <div class="flash-message flash-danger mb-4"><?= e($errors['general']) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="login-form-group">
                        <label class="login-form-label">كلمة المرور الحالية</label>
                        <div class="login-input-wrapper">
                            <input type="password" name="current_password" class="login-form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" required placeholder="أدخل كلمة المرور الحالية">
                            <?php if (isset($errors['current_password'])): ?>
                                <div class="invalid-feedback"><?= e($errors['current_password']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="login-form-group">
                        <label class="login-form-label">كلمة المرور الجديدة</label>
                        <div class="login-input-wrapper">
                            <input type="password" name="new_password" class="login-form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="أدخل كلمة المرور الجديدة">
                            <?php if (isset($errors['new_password'])): ?>
                                <div class="invalid-feedback"><?= e($errors['new_password']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="login-form-group">
                        <label class="login-form-label">تأكيد كلمة المرور الجديدة</label>
                        <div class="login-input-wrapper">
                            <input type="password" name="confirm_new_password" class="login-form-control <?= isset($errors['confirm_new_password']) ? 'is-invalid' : '' ?>" required placeholder="أعد إدخال كلمة المرور الجديدة">
                            <?php if (isset($errors['confirm_new_password'])): ?>
                                <div class="invalid-feedback"><?= e($errors['confirm_new_password']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">🔑 تغيير كلمة المرور</button>
                </form>
            </div>

            <div class="login-footer">
                <p class="login-footer-text">
                    نظام التقييم الشامل لجودة التعليم المدرسي (EQAS)
                </p>
            </div>
        </div>
    </div>
</body>
</html>
