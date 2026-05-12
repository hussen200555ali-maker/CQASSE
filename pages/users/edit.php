<?php
/**
 * ============================================================
 * تعديل بيانات مستخدم
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل مستخدم';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'تعديل مستخدم' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// جلب معرف المستخدم
$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    setFlash('معرف المستخدم غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات المستخدم
$stmt = $db->prepare("SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('المستخدم غير موجود', 'danger');
    redirect('index.php');
}

// جلب الأدوار والمدارس
$roles = $db->query("SELECT id, name, display_name_ar AS name_ar FROM roles WHERE is_active = 1 ORDER BY id")->fetchAll();
$schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

$errors = [];

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect("edit.php?id={$userId}");
    }

    $username = trim($_POST['username'] ?? '');
    $full_name_ar = trim($_POST['full_name_ar'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role_id = $_POST['role_id'] ?? '';
    $school_id = $_POST['school_id'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // التحقق من الحقول المطلوبة
    if (empty($username)) $errors['username'] = 'اسم المستخدم مطلوب';
    if (empty($full_name_ar)) $errors['full_name_ar'] = 'الاسم الكامل مطلوب';
    if (empty($role_id)) $errors['role_id'] = 'الدور مطلوب';

    // التحقق من تفرد اسم المستخدم (باستثناء المستخدم الحالي)
    if (empty($errors['username'])) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $userId]);
        if ($stmt->fetch()) {
            $errors['username'] = 'اسم المستخدم مستخدم بالفعل';
        }
    }

    // التحقق من تفرد البريد الإلكتروني
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'البريد الإلكتروني غير صحيح';
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $errors['email'] = 'البريد الإلكتروني مستخدم بالفعل';
            }
        }
    }

    // التحقق من كلمة المرور (اختيارية عند التعديل)
    if (!empty($password)) {
        if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'كلمة المرور يجب أن تكون ' . PASSWORD_MIN_LENGTH . ' أحرف على الأقل';
        }
        if ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'كلمتا المرور غير متطابقتين';
        }
    }

    // التحقق من المدرسة
    $roleName = '';
    foreach ($roles as $r) {
        if ($r['id'] == $role_id) { $roleName = $r['name']; break; }
    }
    if (in_array($roleName, ['school_admin', 'teacher', 'parent']) && empty($school_id)) {
        $errors['school_id'] = 'المدرسة مطلوبة لهذا الدور';
    }

    if (empty($errors)) {
        try {
            // تحديث البيانات الأساسية
            if (!empty($password)) {
                $stmt = $db->prepare("UPDATE users SET username=?, full_name_ar=?, email=?, phone=?, role_id=?, school_id=?, is_active=?, password_hash=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([
                    $username, $full_name_ar, $email ?: null, $phone ?: null,
                    $role_id, $school_id ?: null, $is_active,
                    password_hash($password, PASSWORD_BCRYPT), $userId
                ]);
            } else {
                $stmt = $db->prepare("UPDATE users SET username=?, full_name_ar=?, email=?, phone=?, role_id=?, school_id=?, is_active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([
                    $username, $full_name_ar, $email ?: null, $phone ?: null,
                    $role_id, $school_id ?: null, $is_active, $userId
                ]);
            }

            // تسجيل النشاط
            logActivity($db, $_SESSION['user']['id'], 'edit_user', "تعديل بيانات المستخدم: {$username}", 'user', $userId);

            setFlash('تم تحديث بيانات المستخدم بنجاح', 'success');
            redirect("view.php?id={$userId}");
        } catch (Exception $e) {
            error_log('User Edit Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء تحديث البيانات. يرجى المحاولة مرة أخرى.';
        }
    }

    // تحديث بيانات النموذج في حالة وجود أخطاء
    $user['username'] = $username;
    $user['full_name_ar'] = $full_name_ar;
    $user['email'] = $email;
    $user['phone'] = $phone;
    $user['role_id'] = $role_id;
    $user['school_id'] = $school_id;
    $user['is_active'] = $is_active;
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تعديل بيانات المستخدم: <?= e($user['full_name_ar']) ?></h3>
        <div class="flex gap-2">
            <a href="view.php?id=<?= $userId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
            <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <form method="POST" action="" data-validate>
            <?= csrfField() ?>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">اسم المستخدم <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                           value="<?= e($user['username']) ?>" required>
                    <?php if (isset($errors['username'])): ?>
                        <div class="invalid-feedback"><?= e($errors['username']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">الاسم الكامل <span class="required">*</span></label>
                    <input type="text" name="full_name_ar" class="form-control <?= isset($errors['full_name_ar']) ? 'is-invalid' : '' ?>"
                           value="<?= e($user['full_name_ar']) ?>" required>
                    <?php if (isset($errors['full_name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['full_name_ar']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
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
            </div>

            <div class="divider"></div>
            <p class="text-sm text-secondary mb-4">اترك حقول كلمة المرور فارغة إذا لا تريد تغييرها</p>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">كلمة المرور الجديدة</label>
                    <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="اتركه فارغاً للإبقاء">
                    <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                    <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                           placeholder="اتركه فارغاً للإبقاء">
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['confirm_password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">الدور <span class="required">*</span></label>
                    <select name="role_id" id="role-select" class="form-control <?= isset($errors['role_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر الدور</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>" data-role-name="<?= e($role['name']) ?>" <?= $user['role_id'] == $role['id'] ? 'selected' : '' ?>>
                                <?= e($role['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['role_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['role_id']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group" id="school-field-group" style="display:none;">
                    <label class="form-label">المدرسة <span class="required">*</span></label>
                    <select name="school_id" id="school-select" class="form-control <?= isset($errors['school_id']) ? 'is-invalid' : '' ?>">
                        <option value="">اختر المدرسة</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>" <?= $user['school_id'] == $school['id'] ? 'selected' : '' ?>>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['school_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['school_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= $user['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">مستخدم نشط</label>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="view.php?id=<?= $userId ?>" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php
$pageJs = <<<JS
    const roleSelect = document.getElementById('role-select');
    const schoolGroup = document.getElementById('school-field-group');
    function toggleSchoolField() {
        if (!roleSelect || !schoolGroup) return;
        const selected = roleSelect.options[roleSelect.selectedIndex];
        const roleName = selected ? selected.dataset.roleName : '';
        schoolGroup.style.display = ['school_admin', 'teacher', 'parent'].includes(roleName) ? 'block' : 'none';
    }
    if (roleSelect) { roleSelect.addEventListener('change', toggleSchoolField); toggleSchoolField(); }
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
