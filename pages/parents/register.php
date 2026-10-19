<?php
/**
 * ============================================================
 * EQAS - تسجيل ولي أمر جديد (تسجيل ذاتي)
 * نظام التقييم الشامل - صفحة تسجيل أولياء الأمور
 * ============================================================
 * الصفحة عامة (لا تتطلب تسجيل دخول)
 * آلية العمل:
 * 1. يقوم ولي الأمر بإدخال رقم جواله
 * 2. النظام يبحث عن طلاب بنفس رقم الجوال في حقل guardian_phone
 * 3. إذا وُجد تطابق: يتم إنشاء الحساب وربطه بالطلاب تلقائياً
 * 4. إذا لم يُوجد: رسالة خطأ
 * ============================================================
 */

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';

// إذا كان مسجلاً دخوله بالفعل
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = false;
$createdUser = null;
$linkedStudents = [];
$generatedPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'رمز الأمان غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $fullNameAr = trim($_POST['full_name_ar'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $confirm    = $_POST['confirm_password'] ?? '';

        // التحقق من المدخلات
        if (empty($fullNameAr)) {
            $error = 'الاسم الكامل مطلوب';
        } elseif (empty($phone)) {
            $error = 'رقم الجوال مطلوب';
        } elseif (empty($password)) {
            $error = 'كلمة المرور مطلوبة';
        } elseif (mb_strlen($password) < 8) {
            $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
        } elseif ($password !== $confirm) {
            $error = 'كلمتا المرور غير متطابقتين';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صحيح';
        }

        if (empty($error)) {
            try {
                // البحث عن الطلاب بنفس رقم الجوال
                $stmt = $db->prepare("SELECT id, full_name_ar, student_number, school_id FROM students WHERE guardian_phone = ? AND is_active = 1");
                $stmt->execute([$phone]);
                $matchedStudents = $stmt->fetchAll();

                if (empty($matchedStudents)) {
                    $error = 'رقم الجوال غير مسجل لأي طالب في النظام. يرجى التواصل مع المدرسة للتسجيل.';
                } else {
                    // التحقق من عدم وجود حساب مسبق بنفس الجوال
                    $stmt = $db->prepare("SELECT id, username FROM users WHERE phone = ? AND role_id = 4 AND is_active = 1");
                    $stmt->execute([$phone]);
                    $existingParent = $stmt->fetch();

                    if ($existingParent) {
                        // يوجد حساب مسبق — ربط بالطلاب الجدد إذا لم يكونوا مرتبطين
                        $parentId = $existingParent['id'];

                        // ربط بالطلاب الجدد (غير المرتبطين)
                        foreach ($matchedStudents as $student) {
                            $checkStmt = $db->prepare("SELECT id FROM parent_students WHERE parent_id = ? AND student_id = ?");
                            $checkStmt->execute([$parentId, $student['id']]);
                            if (!$checkStmt->fetch()) {
                                $linkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship, is_active) VALUES (?, ?, 'father', 1)");
                                $linkStmt->execute([$parentId, $student['id']]);
                                $linkedStudents[] = $student;
                            }
                        }

                        if (!empty($linkedStudents)) {
                            // إشعار مدراء المدرسة
                            $schoolIds = array_unique(array_column($matchedStudents, 'school_id'));
                            $notifiedAdmins = [];
                            foreach ($schoolIds as $sid) {
                                $adminStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND role_id = 2 AND is_active = 1");
                                $adminStmt->execute([$sid]);
                                $adminIds = $adminStmt->fetchAll(\PDO::FETCH_COLUMN);
                                foreach ($adminIds as $adminId) {
                                    if (!in_array($adminId, $notifiedAdmins)) {
                                        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read) VALUES (?, 'parent_link', ?, ?, NULL, NULL, 0)");
                                        $notifStmt->execute([$adminId, 'ربط ولي أمر', "تم ربط ولي الأمر \"{$fullNameAr}\" بـ " . count($linkedStudents) . " طالب (حساب قائم)"]);
                                        $notifiedAdmins[] = $adminId;
                                    }
                                }
                            }

                            $success = true;
                            $createdUser = ['username' => $existingParent['username'], 'is_existing' => true];
                        } else {
                            $error = 'جميع الطلاب المرتبطين بهذا الرقم مسجلون مسبقاً على حسابك. يمكنك تسجيل الدخول.';
                        }
                    } else {
                        // إنشاء حساب جديد
                        $db->beginTransaction();

                        $username = 'parent_' . str_replace([' ', '-', '+'], '', $phone);
                        // التأكد من تفرد اسم المستخدم
                        $baseUsername = $username;
                        $suffix = 1;
                        while (true) {
                            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                            $checkStmt->execute([$username]);
                            if (!$checkStmt->fetch()) break;
                            $username = $baseUsername . '_' . $suffix;
                            $suffix++;
                        }

                        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                        $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name_ar, email, phone, role_id, school_id, must_change_password, is_active, created_at) VALUES (?, ?, ?, ?, ?, 4, NULL, 0, 1, NOW())");
                        $stmt->execute([$username, $passwordHash, $fullNameAr, $email ?: null, $phone]);
                        $parentId = $db->lastInsertId();

                        // ربط الطلاب
                        foreach ($matchedStudents as $student) {
                            $linkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship, is_active) VALUES (?, ?, 'father', 1)");
                            $linkStmt->execute([$parentId, $student['id']]);
                            $linkedStudents[] = $student;
                        }

                        logActivity($db, $parentId, 'self_register_parent', "تسجيل ذاتي لولي الأمر: {$fullNameAr} - ربط بـ " . count($matchedStudents) . " طالب", 'user', $parentId);

                        // إشعار مدراء المدرسة
                        $schoolIds = array_unique(array_column($matchedStudents, 'school_id'));
                        foreach ($schoolIds as $sid) {
                            $adminStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND role_id = 2 AND is_active = 1");
                            $adminStmt->execute([$sid]);
                            $adminIds = $adminStmt->fetchAll(\PDO::FETCH_COLUMN);
                            foreach ($adminIds as $adminId) {
                                $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read) VALUES (?, 'parent_link', ?, ?, NULL, NULL, 0)");
                                $notifStmt->execute([$adminId, 'تسجيل ولي أمر جديد', "تم تسجيل ولي أمر جديد \"{$fullNameAr}\" وربطه بـ " . count($matchedStudents) . " طالب. رقم الجوال: {$phone}"]);
                            }
                        }

                        $db->commit();

                        $success = true;
                        $createdUser = ['username' => $username, 'password' => $password, 'is_existing' => false];
                    }
                }
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Parent Registration Error: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء إنشاء الحساب. يرجى المحاولة مرة أخرى.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل ولي أمر جديد</title>
    <link rel="stylesheet" href="../../assets/css/app.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .register-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.15);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
        }
        .register-header {
            padding: 30px 30px 20px;
            text-align: center;
        }
        .register-header h1 { font-size: 22px; color: #1e293b; margin-bottom: 6px; }
        .register-header p { font-size: 14px; color: #64748b; }
        .register-body { padding: 0 30px 30px; }
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: #334155; margin-bottom: 4px;
        }
        .form-label .required { color: #ef4444; }
        .form-control {
            width: 100%; padding: 10px 14px; font-size: 14px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            transition: border-color .2s, box-shadow .2s;
            outline: none; font-family: inherit;
        }
        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .form-control.is-invalid { border-color: #ef4444; }
        .invalid-feedback { font-size: 12px; color: #ef4444; margin-top: 4px; }
        .btn-primary {
            width: 100%; padding: 12px; font-size: 15px; font-weight: 600;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff; border: none; border-radius: 8px;
            cursor: pointer; transition: opacity .2s;
        }
        .btn-primary:hover { opacity: .9; }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
        .alert {
            padding: 14px 16px; border-radius: 8px; font-size: 14px;
            margin-bottom: 16px;
        }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .alert-info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .success-credentials {
            background: #f8fafc; padding: 14px; border-radius: 8px;
            font-family: monospace; font-size: 14px; margin: 12px 0;
            direction: ltr; text-align: left;
        }
        .student-list {
            margin: 12px 0; padding: 0; list-style: none;
        }
        .student-list li {
            padding: 8px 12px; background: #f1f5f9;
            border-radius: 6px; margin-bottom: 4px;
            font-size: 13px;
        }
        .login-link {
            display: block; text-align: center; margin-top: 16px;
            color: #6366f1; text-decoration: none; font-size: 14px;
        }
        .login-link:hover { text-decoration: underline; }
        .divider {
            border: none; border-top: 1px solid #e2e8f0;
            margin: 16px 0;
        }
        .password-hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-header">
            <h1>🔗 تسجيل ولي أمر</h1>
            <p>أدخل بياناتك للربط بأبنائك المسجلين في النظام</p>
        </div>
        <div class="register-body">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✅ تم إنشاء الحساب بنجاح وربطك بـ <?= count($linkedStudents) ?> طالب.
                </div>

                <?php if (!$createdUser['is_existing']): ?>
                <div class="alert alert-info">
                    <strong>بيانات الدخول:</strong>
                </div>
                <div class="success-credentials">
                    <strong>اسم المستخدم:</strong> <?= e($createdUser['username']) ?><br>
                    <strong>كلمة المرور:</strong> <span style="color:#dc2626;font-weight:bold;"><?= e($createdUser['password']) ?></span>
                </div>
                <p style="font-size:13px;color:#64748b;margin:8px 0;">
                    ⚠️ احتفظ ببيانات الدخول. يمكنك تغيير كلمة المرور بعد تسجيل الدخول.
                </p>
                <?php else: ?>
                <div class="alert alert-info">
                    تم ربط حسابك الحالي (<strong><?= e($createdUser['username']) ?></strong>) بالطلاب الجدد.
                </div>
                <?php endif; ?>

                <div style="margin:16px 0;">
                    <strong style="font-size:14px;">الطلاب المرتبطون:</strong>
                    <ul class="student-list">
                        <?php foreach ($linkedStudents as $s): ?>
                        <li>👤 <?= e($s['full_name_ar']) ?> (<?= e($s['student_number']) ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <a href="../../login.php" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">
                    🚪 تسجيل الدخول
                </a>
                <a href="register.php" class="login-link">تسجيل ولي أمر آخر</a>

            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?= e($error) ?></div>
                <?php endif; ?>

                <div class="alert alert-info">
                    💡 أدخل رقم الجوال المسجل في المدرسة للربط التلقائي بأبنائك.
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label class="form-label">الاسم الكامل <span class="required">*</span></label>
                        <input type="text" name="full_name_ar" class="form-control"
                               value="<?= e($_POST['full_name_ar'] ?? '') ?>" required
                               placeholder="الاسم كما هو في المدرسة">
                    </div>

                    <div class="form-group">
                        <label class="form-label">رقم الجوال <span class="required">*</span></label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= e($_POST['phone'] ?? '') ?>" required dir="ltr"
                               placeholder="777123456">
                    </div>

                    <div class="form-group">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($_POST['email'] ?? '') ?>" dir="ltr"
                               placeholder="example@domain.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">كلمة المرور <span class="required">*</span></label>
                        <input type="password" name="password" class="form-control"
                               required minlength="8" placeholder="أدخل كلمة المرور">
                        <div class="password-hint">8 أحرف على الأقل</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">تأكيد كلمة المرور <span class="required">*</span></label>
                        <input type="password" name="confirm_password" class="form-control"
                               required placeholder="أعد إدخال كلمة المرور">
                    </div>

                    <button type="submit" class="btn-primary">📝 تسجيل</button>
                </form>

                <hr class="divider">
                <a href="../../login.php" class="login-link">لديك حساب بالفعل؟ تسجيل دخول</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>