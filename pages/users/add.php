<?php
/**
 * ============================================================
 * إضافة مستخدم جديد
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إضافة مستخدم جديد';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'إضافة مستخدم' => null
];

require_once __DIR__ . '/../../includes/header.php';

$isSchoolAdmin = hasPermission('school_admin') && !hasPermission('group_admin');

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// جلب الأدوار والمدارس
$roles = $db->query("SELECT id, name, display_name_ar AS name_ar FROM roles WHERE is_active = 1 ORDER BY id")->fetchAll();
$schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// إعدادات مسبقة لـ school_admin
$teacherRoleId = 0;
$schoolAdminSchoolId = currentSchoolId();
foreach ($roles as $r) {
    if ($r['name'] === 'teacher') { $teacherRoleId = $r['id']; break; }
}

$errors = [];
$old = [];
$generatedPassword = '';
$showCredentials = false;
$showParentLinking = false;
$newParentUserId = 0;

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect('add.php');
    }

    $old = [
        'username' => trim($_POST['username'] ?? ''),
        'full_name_ar' => trim($_POST['full_name_ar'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'role_id' => $isSchoolAdmin ? $teacherRoleId : ($_POST['role_id'] ?? ''),
        'school_id' => $isSchoolAdmin ? $schoolAdminSchoolId : ($_POST['school_id'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if ($isSchoolAdmin) {
        // school_admin: توليد كلمة مرور عشوائية
        $password = generatePassword(10);
        $generatedPassword = $password;
        $confirmPassword = $password;
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
    }

    if (empty($old['username'])) {
        $errors['username'] = 'اسم المستخدم مطلوب';
    } elseif (mb_strlen($old['username']) < 3) {
        $errors['username'] = 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل';
    }

    if (empty($errors['username'])) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$old['username']]);
        if ($stmt->fetch()) {
            $errors['username'] = 'اسم المستخدم مستخدم بالفعل';
        }
    }

    if (empty($old['full_name_ar'])) $errors['full_name_ar'] = 'الاسم الكامل مطلوب';

    if (!$isSchoolAdmin) {
        if (empty($password)) {
            $errors['password'] = 'كلمة المرور مطلوبة';
        } elseif (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'كلمة المرور يجب أن تكون ' . PASSWORD_MIN_LENGTH . ' أحرف على الأقل';
        }

        if ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'كلمتا المرور غير متطابقتين';
        }
    }

    if (!empty($old['email']) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'البريد الإلكتروني غير صحيح';
    }

    if (!empty($old['email']) && empty($errors['email'])) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'البريد الإلكتروني مستخدم بالفعل';
        }
    }

    if (!$isSchoolAdmin) {
        if (empty($old['role_id'])) $errors['role_id'] = 'الدور مطلوب';

        $roleName = '';
        foreach ($roles as $r) {
            if ($r['id'] == $old['role_id']) { $roleName = $r['name']; break; }
        }
        if (in_array($roleName, ['school_admin', 'teacher', 'parent']) && empty($old['school_id'])) {
            $errors['school_id'] = 'المدرسة مطلوبة لهذا الدور';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name_ar, email, phone, role_id, school_id, must_change_password, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())");
            $stmt->execute([
                $old['username'],
                password_hash($password, PASSWORD_BCRYPT),
                $old['full_name_ar'],
                $old['email'] ?: null,
                $old['phone'] ?: null,
                $old['role_id'],
                $old['school_id'] ?: null,
                $old['is_active']
            ]);

            $userId = $db->lastInsertId();
            logActivity($db, $_SESSION['user']['id'], 'create_user', "إضافة مستخدم جديد: {$old['username']}", 'user', $userId);

            // التحقق مما إذا كان المستخدم الجديد ولي أمر
            $newUserRoleName = '';
            $newUserParentId = 0;
            if (!$isSchoolAdmin) {
                foreach ($roles as $r) {
                    if ($r['id'] == $old['role_id']) { $newUserRoleName = $r['name']; break; }
                }
            }
            if ($newUserRoleName === 'parent') {
                $newUserParentId = $userId;
            }

            if ($isSchoolAdmin) {
                $showCredentials = true;
                $generatedPassword = $password;
            } else {
                if ($newUserRoleName === 'parent') {
                    $showParentLinking = true;
                    $newParentUserId = $userId;
                } else {
                    setFlash('تم إضافة المستخدم بنجاح', 'success');
                }
            }
        } catch (Exception $e) {
            error_log('User Add Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء إضافة المستخدم. يرجى المحاولة مرة أخرى.';
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">إضافة مستخدم جديد</h3>
        <a href="index.php" class="btn btn-light">↩ العودة للقائمة</a>
    </div>
    <div class="card-body">
        <?php if ($showCredentials && !empty($generatedPassword)): ?>
            <div class="alert alert-warning mb-4" style="border:2px solid var(--warning);padding:20px;border-radius:var(--radius-md);">
                <h4 style="margin:0 0 12px 0;color:var(--warning);">✅ تم إنشاء الحساب بنجاح</h4>
                <p style="margin-bottom:16px;">يجب تسليم بيانات الدخول التالية إلى المعلم فوراً. لن تُعرض مرة أخرى.</p>
                <div style="background:var(--background);padding:16px;border-radius:var(--radius-md);direction:ltr;text-align:left;font-family:monospace;font-size:14px;">
                    <strong>اسم المستخدم:</strong> <?= e($old['username']) ?><br>
                    <strong>كلمة المرور:</strong> <span style="font-weight:bold;color:var(--danger);"><?= e($generatedPassword) ?></span>
                </div>
                <p style="margin-top:12px;font-size:13px;color:var(--text-muted);">
                    ⚠️ سيُطلب من المستخدم تغيير كلمة المرور عند أول تسجيل دخول.
                </p>
                <a href="index.php" class="btn btn-primary mt-3">↩ العودة للقائمة</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <?php if ($showParentLinking && $newParentUserId > 0): ?>
        <div class="card mb-4" style="border:2px solid var(--success);">
            <div class="card-header">
                <h4 class="card-title">✅ تم إنشاء الحساب بنجاح</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-4">
                    تم إنشاء حساب ولي الأمر <strong><?= e($old['full_name_ar'] ?? '') ?></strong> بنجاح.<br>
                    <strong>اسم المستخدم:</strong> <code style="font-size:14px;"><?= e($old['username'] ?? '') ?></code>
                    يمكنك الآن ربطه بأحد الطلاب:
                </div>
                <input type="hidden" id="newParentUserId" value="<?= $newParentUserId ?>">
                <input type="hidden" id="newParentUserId" value="<?= $newParentUserId ?>">
                <div class="grid grid-2 gap-3" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="position:relative;">
                        <label class="form-label">ابحث عن طالب</label>
                        <input type="text" id="addStudentSearch" class="form-control"
                               placeholder="اسم الطالب أو رقمه..." autocomplete="off"
                               oninput="addSearchStudents(this.value)">
                        <div id="addStudentResults" style="position:absolute;top:100%;right:0;left:0;z-index:60;background:#fff;border:1px solid #cbd5e1;border-radius:6px;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);display:none;"></div>
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;">
                        <button class="btn btn-primary" id="addLinkBtn" onclick="addLinkParent()" disabled style="width:100%;">
                            🔗 ربط بالطالب
                        </button>
                    </div>
                </div>
                <div id="addLinkMessage" class="mt-3" style="display:none;"></div>
                <div style="margin-top:12px;">
                    <a href="index.php" class="btn btn-light">↩ الذهاب للقائمة</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$showCredentials): ?>
        <form method="POST" action="" data-validate>
            <?= csrfField() ?>

            <?php if ($isSchoolAdmin): ?>
            <div class="alert alert-info mb-4">
                سيتم إنشاء حساب معلم جديد تابع لمدرستك. كلمة المرور ستُولّد تلقائياً.
            </div>
            <?php endif; ?>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">اسم المستخدم <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['username'] ?? '') ?>" required minlength="3"
                           placeholder="أدخل اسم المستخدم" id="username-field">
                    <?php if (isset($errors['username'])): ?>
                        <div class="invalid-feedback"><?= e($errors['username']) ?></div>
                    <?php endif; ?>
                    <div id="username-status" class="form-text"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">الاسم الكامل <span class="required">*</span></label>
                    <input type="text" name="full_name_ar" class="form-control <?= isset($errors['full_name_ar']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['full_name_ar'] ?? '') ?>" required
                           placeholder="أدخل الاسم الكامل بالعربية">
                    <?php if (isset($errors['full_name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['full_name_ar']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['email'] ?? '') ?>" placeholder="example@domain.com">
                    <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= e($old['phone'] ?? '') ?>" placeholder="777123456">
                </div>
            </div>

            <?php if ($isSchoolAdmin): ?>
                <!-- school_admin: كلمة المرور تُولّد تلقائياً -->
                <div class="form-group">
                    <div class="alert alert-info">
                        <strong>ملاحظة:</strong> كلمة المرور ستُولّد تلقائياً. سيُطلب من المستخدم تغييرها عند أول تسجيل دخول.
                    </div>
                </div>
            <?php else: ?>
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">كلمة المرور <span class="required">*</span></label>
                    <input type="password" name="password" id="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           required minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="أدخل كلمة المرور">
                    <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
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
                    <label class="form-label">تأكيد كلمة المرور <span class="required">*</span></label>
                    <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                           required placeholder="أعد إدخال كلمة المرور">
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['confirm_password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isSchoolAdmin): ?>
                <!-- school_admin: دور ثابت (معلم) ومدرسة ثابتة -->
                <input type="hidden" name="role_id" value="<?= $teacherRoleId ?>">
                <input type="hidden" name="school_id" value="<?= $schoolAdminSchoolId ?>">
            <?php else: ?>
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">الدور <span class="required">*</span></label>
                    <select name="role_id" id="role-select" class="form-control <?= isset($errors['role_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر الدور</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>" data-role-name="<?= e($role['name']) ?>" <?= ($old['role_id'] ?? '') == $role['id'] ? 'selected' : '' ?>>
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
                            <option value="<?= $school['id'] ?>" <?= ($old['school_id'] ?? '') == $school['id'] ? 'selected' : '' ?>>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['school_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['school_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= !isset($old['is_active']) || $old['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">مستخدم نشط</label>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ المستخدم</button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php
$pageJs = <<<JS
    // التحقق من تفرد اسم المستخدم عبر AJAX
    const usernameField = document.getElementById('username-field');
    const usernameStatus = document.getElementById('username-status');
    let usernameTimer = null;

    if (usernameField) {
        usernameField.addEventListener('input', function() {
            clearTimeout(usernameTimer);
            const value = this.value.trim();
            if (value.length < 3) {
                usernameStatus.textContent = '';
                usernameField.classList.remove('is-valid', 'is-invalid');
                return;
            }
            usernameTimer = setTimeout(function() {
                fetch('../../api/check_username.php?username=' + encodeURIComponent(value), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.available) {
                        usernameStatus.textContent = '✓ اسم المستخدم متاح';
                        usernameStatus.style.color = 'var(--success)';
                        usernameField.classList.remove('is-invalid');
                        usernameField.classList.add('is-valid');
                    } else {
                        usernameStatus.textContent = '✗ اسم المستخدم مستخدم بالفعل';
                        usernameStatus.style.color = 'var(--danger)';
                        usernameField.classList.remove('is-valid');
                        usernameField.classList.add('is-invalid');
                    }
                })
                .catch(() => { usernameStatus.textContent = ''; });
            }, 500);
        });
    }

    // إظهار/إخفاء حقل المدرسة بناءً على الدور
    const roleSelect = document.getElementById('role-select');
    const schoolGroup = document.getElementById('school-field-group');

    function toggleSchoolField() {
        if (!roleSelect || !schoolGroup) return;
        const selected = roleSelect.options[roleSelect.selectedIndex];
        const roleName = selected ? selected.dataset.roleName : '';
        if (['school_admin', 'teacher', 'parent'].includes(roleName)) {
            schoolGroup.style.display = 'block';
        } else {
            schoolGroup.style.display = 'none';
            document.getElementById('school-select').value = '';
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', toggleSchoolField);
        toggleSchoolField();
    }

    // ========== ربط ولي الأمر الجديد بطالب ==========
    let addSelectedStudentId = null;
    let addStudentTimer = null;

    function addSearchStudents(q) {
        clearTimeout(addStudentTimer);
        if (q.length < 2) { document.getElementById('addStudentResults').style.display = 'none'; return; }
        addStudentTimer = setTimeout(() => {
            fetch('../../api/search_students.php?mode=general&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(r => r.json())
                .then(data => {
                    const box = document.getElementById('addStudentResults');
                    const students = data.students || [];
                    if (students.length === 0) { box.style.display = 'none'; return; }
                    box.innerHTML = students.map(s =>
                        '<div style="cursor:pointer;padding:8px 12px;border-bottom:1px solid #e2e8f0;transition:background .15s;" onclick="addSelectStudent(' + s.id + ',\'' + s.full_name_ar.replace(/'/g,"\\'") + '\',\'' + (s.student_number || '') + '\')">' +
                        '<strong>' + s.full_name_ar + '</strong>' +
                        (s.student_number ? ' <span style="color:#64748b;font-size:12px;">(' + s.student_number + ')</span>' : '') +
                        (s.grade_name ? ' <span style="color:#94a3b8;font-size:11px;">' + s.grade_name + '</span>' : '') +
                        '</div>'
                    ).join('');
                    box.style.display = 'block';
                });
        }, 300);
    }

    function addSelectStudent(id, name, number) {
        addSelectedStudentId = id;
        document.getElementById('addStudentSearch').value = name + (number ? ' (' + number + ')' : '');
        document.getElementById('addStudentResults').style.display = 'none';
        document.getElementById('addLinkBtn').disabled = false;
    }

    function addLinkParent() {
        const parentId = document.getElementById('newParentUserId').value;
        if (!addSelectedStudentId || !parentId) return;
        const btn = document.getElementById('addLinkBtn');
        btn.disabled = true;
        btn.textContent = '⏳ جاري الربط...';

        const formData = new FormData();
        formData.append('parent_id', parentId);
        formData.append('student_id', addSelectedStudentId);
        formData.append('relationship', 'father');

        fetch('../../api/link_parent.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                const msg = document.getElementById('addLinkMessage');
                if (data.success) {
                    msg.style.display = 'block';
                    msg.className = 'flash-message flash-success mt-3';
                    msg.innerHTML = '<span>✅ ' + data.message + '</span>';
                    btn.disabled = true;
                    btn.textContent = '✓ تم الربط';
                    document.getElementById('addStudentSearch').disabled = true;
                } else {
                    msg.style.display = 'block';
                    msg.className = 'flash-message flash-danger mt-3';
                    msg.innerHTML = '<span>❌ ' + data.message + '</span>';
                    btn.disabled = false;
                    btn.textContent = '🔗 ربط بالطالب';
                }
            })
            .catch(() => {
                const msg = document.getElementById('addLinkMessage');
                msg.style.display = 'block';
                msg.className = 'flash-message flash-danger mt-3';
                msg.innerHTML = '<span>❌ حدث خطأ في الاتصال</span>';
                btn.disabled = false;
                btn.textContent = '🔗 ربط بالطالب';
            });
    }

    // إخفاء نتائج البحث عند النقر خارجها
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#addStudentResults') && !e.target.closest('#addStudentSearch')) {
            const box = document.getElementById('addStudentResults');
            if (box) box.style.display = 'none';
        }
    });

    // مؤشر قوة كلمة المرور
    const passwordField = document.getElementById('password');
    if (passwordField) {
        passwordField.addEventListener('input', function() {
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
