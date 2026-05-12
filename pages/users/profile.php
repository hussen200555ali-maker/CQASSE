<?php
/**
 * ============================================================
 * الملف الشخصي للمستخدم الحالي
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'الملف الشخصي';
$breadcrumb = [
    'الملف الشخصي' => null
];

require_once __DIR__ . '/../../includes/header.php';

requireLogin();

$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3

// جلب بيانات المستخدم
$stmt = $db->prepare("SELECT u.*, r.display_name_ar AS role_name_ar, s.name_ar AS school_name
                      FROM users u
                      LEFT JOIN roles r ON u.role_id = r.id
                      LEFT JOIN schools s ON u.school_id = s.id
                      WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('خطأ في بيانات المستخدم', 'danger');
    redirect('../../logout.php');
}

$errors = [];
$successMsg = '';

// معالجة تحديث البيانات الشخصية
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('profile.php');
    }

    $full_name_ar = trim($_POST['full_name_ar'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($full_name_ar)) {
        $errors['full_name_ar'] = 'الاسم الكامل مطلوب';
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'البريد الإلكتروني غير صحيح';
    }

    // التحقق من تفرد البريد
    if (!empty($email) && empty($errors['email'])) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $errors['email'] = 'البريد الإلكتروني مستخدم بالفعل';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE users SET full_name_ar = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$full_name_ar, $email ?: null, $phone ?: null, $userId]);

            // تحديث الجلسة
            $_SESSION['user']['full_name'] = $full_name_ar;

            logActivity($db, $userId, 'update_profile', 'تحديث البيانات الشخصية', 'user', $userId);
            setFlash('تم تحديث البيانات الشخصية بنجاح', 'success');
            redirect('profile.php');
        } catch (Exception $e) {
            $errors['general'] = 'حدث خطأ أثناء التحديث';
        }
    }
}

// معالجة تغيير كلمة المرور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('profile.php');
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

    // التحقق من كلمة المرور الحالية
    if (empty($currentPassword)) {
        $errors['current_password'] = 'كلمة المرور الحالية مطلوبة';
    } elseif (!password_verify($currentPassword, $user['password_hash'])) {
        $errors['current_password'] = 'كلمة المرور الحالية غير صحيحة';
    }

    if (empty($newPassword)) {
        $errors['new_password'] = 'كلمة المرور الجديدة مطلوبة';
    } elseif (mb_strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        $errors['new_password'] = 'كلمة المرور يجب أن تكون ' . PASSWORD_MIN_LENGTH . ' أحرف على الأقل';
    }

    if ($newPassword !== $confirmNewPassword) {
        $errors['confirm_new_password'] = 'كلمتا المرور غير متطابقتين';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

            logActivity($db, $userId, 'change_password', 'تغيير كلمة المرور', 'user', $userId);
            setFlash('تم تغيير كلمة المرور بنجاح', 'success');
            redirect('profile.php');
        } catch (Exception $e) {
            $errors['general'] = 'حدث خطأ أثناء تغيير كلمة المرور';
        }
    }
}

// ألوان شارات الدور
$roleBadgeClass = [
    'group_admin' => 'badge-danger',
    'school_admin' => 'badge-primary',
    'teacher' => 'badge-success',
    'parent' => 'badge-warning'
];
$badgeClass = $roleBadgeClass[$user['role_name'] ?? ''] ?? 'badge-secondary';
?>

<div class="grid grid-2" style="gap:var(--space-6);">
    <!-- بطاقة المعلومات الشخصية -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">👤 الملف الشخصي</h3>
        </div>
        <div class="card-body">
            <!-- صورة المستخدم الافتراضية -->
            <div class="text-center mb-6">
                <div style="width:80px;height:80px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:32px;margin:0 auto var(--space-3);">
                    <?= mb_substr($user['full_name_ar'], 0, 1, 'UTF-8') ?>
                </div>
                <h4 style="margin-bottom:var(--space-1);"><?= e($user['full_name_ar']) ?></h4>
                <span class="badge <?= $badgeClass ?>"><?= e($user['role_name_ar'] ?? getRoleLabel($user['role_name'] ?? '')) ?></span>
                <?php if ($user['school_name']): ?>
                    <div class="text-sm text-secondary mt-2"><?= e($user['school_name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- نموذج تحديث البيانات -->
            <form method="POST" action="" data-validate>
                <?= csrfField() ?>

                <div class="form-group">
                    <label class="form-label">الاسم الكامل <span class="required">*</span></label>
                    <input type="text" name="full_name_ar" class="form-control <?= isset($errors['full_name_ar']) ? 'is-invalid' : '' ?>"
                           value="<?= e($user['full_name_ar']) ?>" required>
                    <?php if (isset($errors['full_name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['full_name_ar']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">اسم المستخدم</label>
                    <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
                    <div class="form-text">لا يمكن تغيير اسم المستخدم</div>
                </div>

                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($user['email'] ?? '') ?>">
                    <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= e($user['phone'] ?? '') ?>">
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">💾 حفظ التعديلات</button>
            </form>
        </div>
    </div>

    <!-- تغيير كلمة المرور -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔑 تغيير كلمة المرور</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" data-validate>
                <?= csrfField() ?>

                <div class="form-group">
                    <label class="form-label">كلمة المرور الحالية <span class="required">*</span></label>
                    <input type="password" name="current_password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>"
                           required placeholder="أدخل كلمة المرور الحالية">
                    <?php if (isset($errors['current_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['current_password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">كلمة المرور الجديدة <span class="required">*</span></label>
                    <input type="password" name="new_password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                           required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="أدخل كلمة المرور الجديدة">
                    <?php if (isset($errors['new_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['new_password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">تأكيد كلمة المرور الجديدة <span class="required">*</span></label>
                    <input type="password" name="confirm_new_password" class="form-control <?= isset($errors['confirm_new_password']) ? 'is-invalid' : '' ?>"
                           required placeholder="أعد إدخال كلمة المرور الجديدة">
                    <?php if (isset($errors['confirm_new_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['confirm_new_password']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" name="change_password" class="btn btn-primary">🔑 تغيير كلمة المرور</button>
            </form>

            <div class="divider" style="margin:var(--space-6) 0;"></div>

            <!-- معلومات إضافية -->
            <div>
                <h5 class="mb-3">معلومات الحساب</h5>
                <table style="width:100%;font-size:var(--font-size-sm);">
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);color:var(--text-secondary);">تاريخ الإنشاء</td>
                        <td style="padding:var(--space-2);"><?= formatDateAr($user['created_at'], 'medium') ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);color:var(--text-secondary);">آخر تسجيل دخول</td>
                        <td style="padding:var(--space-2);"><?= $user['last_login_at'] ? formatDateAr($user['last_login_at'], 'medium') : '—' ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);color:var(--text-secondary);">عنوان IP الأخير</td>
                        <td style="padding:var(--space-2);"><?= e($user['last_login_ip'] ?? '—') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
