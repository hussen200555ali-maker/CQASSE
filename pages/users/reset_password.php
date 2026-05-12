<?php
/**
 * ============================================================
 * إعادة تعيين كلمة مرور مستخدم
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إعادة تعيين كلمة المرور';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'إعادة تعيين كلمة المرور' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    setFlash('معرف المستخدم غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات المستخدم
$stmt = $db->prepare("SELECT u.*, r.display_name_ar AS role_name_ar FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('المستخدم غير موجود', 'danger');
    redirect('index.php');
}

$errors = [];

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect("reset_password.php?id={$userId}");
    }

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // التحقق من كلمة المرور
    if (empty($newPassword)) {
        $errors['new_password'] = 'كلمة المرور الجديدة مطلوبة';
    } elseif (mb_strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        $errors['new_password'] = 'كلمة المرور يجب أن تكون ' . PASSWORD_MIN_LENGTH . ' أحرف على الأقل';
    }

    if ($newPassword !== $confirmPassword) {
        $errors['confirm_password'] = 'كلمتا المرور غير متطابقتين';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([
                password_hash($newPassword, PASSWORD_BCRYPT),
                $userId
            ]);

            // تسجيل النشاط
            logActivity($db, $_SESSION['user']['id'], 'reset_password', "إعادة تعيين كلمة مرور المستخدم: {$user['username']}", 'user', $userId);

            setFlash('تم إعادة تعيين كلمة المرور بنجاح', 'success');
            redirect("view.php?id={$userId}");
        } catch (Exception $e) {
            error_log('Password Reset Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء إعادة تعيين كلمة المرور';
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🔑 إعادة تعيين كلمة المرور</h3>
        <a href="view.php?id=<?= $userId ?>" class="btn btn-light btn-sm">↩ العودة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <!-- معلومات المستخدم -->
        <div class="card mb-6" style="max-width:500px;">
            <div class="card-body" style="background:var(--background);">
                <div class="flex items-center gap-3">
                    <div style="width:48px;height:48px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:18px;">
                        <?= mb_substr($user['full_name_ar'], 0, 1, 'UTF-8') ?>
                    </div>
                    <div>
                        <div style="font-weight:600;"><?= e($user['full_name_ar']) ?></div>
                        <div class="text-sm text-secondary"><?= e($user['username']) ?> — <?= e($user['role_name_ar']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flash-message flash-warning mb-4" style="background:rgba(255,193,7,0.1);border:1px solid rgba(255,193,7,0.3);padding:var(--space-4);border-radius:var(--radius-md);">
            <span>⚠️ تحذير: سيتم تغيير كلمة مرور هذا المستخدم. تأكد من إبلاغ المستخدم بكلمة المرور الجديدة.</span>
        </div>

        <form method="POST" action="" data-validate style="max-width:500px;">
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">كلمة المرور الجديدة <span class="required">*</span></label>
                <input type="password" name="new_password" id="new_password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                       required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="أدخل كلمة المرور الجديدة">
                <?php if (isset($errors['new_password'])): ?>
                    <div class="invalid-feedback"><?= e($errors['new_password']) ?></div>
                <?php endif; ?>
                <!-- مؤشر قوة كلمة المرور -->
                <div class="mt-2">
                    <div class="flex gap-1">
                        <div id="strength-1" style="height:4px;flex:1;background:var(--border-light);border-radius:2px;"></div>
                        <div id="strength-2" style="height:4px;flex:1;background:var(--border-light);border-radius:2px;"></div>
                        <div id="strength-3" style="height:4px;flex:1;background:var(--border-light);border-radius:2px;"></div>
                        <div id="strength-4" style="height:4px;flex:1;background:var(--border-light);border-radius:2px;"></div>
                    </div>
                    <div id="strength-text" class="text-xs mt-1" style="color:var(--text-muted);"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">تأكيد كلمة المرور الجديدة <span class="required">*</span></label>
                <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                       required placeholder="أعد إدخال كلمة المرور الجديدة">
                <?php if (isset($errors['confirm_password'])): ?>
                    <div class="invalid-feedback"><?= e($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">🔑 إعادة تعيين كلمة المرور</button>
                <a href="view.php?id=<?= $userId ?>" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php
$pageJs = <<<JS
    const pwField = document.getElementById('new_password');
    if (pwField) {
        pwField.addEventListener('input', function() {
            const val = this.value;
            let score = 0;
            if (val.length >= 8) score++;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
            if (/\d/.test(val)) score++;
            if (/[^a-zA-Z0-9]/.test(val)) score++;
            const colors = ['var(--danger)', 'var(--warning)', 'var(--info)', 'var(--success)'];
            const labels = ['ضعيفة', 'متوسطة', 'جيدة', 'قوية'];
            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById('strength-' + i);
                if (bar) bar.style.background = i <= score ? colors[score - 1] : 'var(--border-light)';
            }
            const textEl = document.getElementById('strength-text');
            if (textEl) {
                textEl.textContent = val.length > 0 ? 'قوة كلمة المرور: ' + (labels[score - 1] || 'ضعيفة جداً') : '';
                textEl.style.color = score > 0 ? colors[score - 1] : 'var(--text-muted)';
            }
        });
    }
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
