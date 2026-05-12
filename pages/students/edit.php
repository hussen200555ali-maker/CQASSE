<?php
/**
 * ============================================================
 * تعديل بيانات طالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل بيانات طالب';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => 'index.php',
    'تعديل طالب' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// تحديد نطاق المدرسة
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
$isGroupAdmin = ($userRole === 'group_admin');

// جلب معرف الطالب
$studentId = intval($_GET['id'] ?? 0);
if (!$studentId) {
    setFlash('معرف الطالب غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات الطالب
$stmt = $db->prepare("SELECT s.*, gl.name_ar AS grade_name, sc.name_ar AS school_name
                      FROM students s
                      LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                      LEFT JOIN schools sc ON s.school_id = sc.id
                      WHERE s.id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('الطالب غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من أن مدير المدرسة يعدل طلاب مدرسته فقط
if (!$isGroupAdmin && $student['school_id'] != $userSchoolId) {
    setFlash('ليس لديك صلاحية لتعديل طلاب مدرسة أخرى', 'danger');
    redirect('index.php');
}

// جلب المدارس (مدير النظام يرى الكل، مدير المدرسة يرى مدرسته فقط)
if ($isGroupAdmin) {
    $schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = ? AND is_active = 1");
    $stmt->execute([$userSchoolId]);
    $schools = $stmt->fetchAll();
}

// جلب المراحل الدراسية
$gradeLevels = $db->query("SELECT id, name_ar, level_number, stage FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();

$errors = [];

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect("edit.php?id={$studentId}");
    }

    $full_name_ar = trim($_POST['full_name_ar'] ?? '');
    $full_name_en = trim($_POST['full_name_en'] ?? '');
    $guardian_name = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $guardian_email = trim($_POST['guardian_email'] ?? '');
    $grade_level_id = $_POST['grade_level_id'] ?? '';
    $school_id = $_POST['school_id'] ?? '';
    $enrollment_date = trim($_POST['enrollment_date'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // التحقق من الحقول المطلوبة
    if (empty($full_name_ar)) $errors['full_name_ar'] = 'الاسم الكامل بالعربية مطلوب';
    if (empty($grade_level_id)) $errors['grade_level_id'] = 'المرحلة الدراسية مطلوبة';
    if (empty($school_id)) $errors['school_id'] = 'المدرسة مطلوبة';

    // التحقق من بريد ولي الأمر
    if (!empty($guardian_email) && !filter_var($guardian_email, FILTER_VALIDATE_EMAIL)) {
        $errors['guardian_email'] = 'البريد الإلكتروني لولي الأمر غير صحيح';
    }

    // التحقق من تاريخ التسجيل
    if (!empty($enrollment_date) && !isValidDate($enrollment_date)) {
        $errors['enrollment_date'] = 'تاريخ التسجيل غير صحيح';
    }

    // التحقق من أن مدير المدرسة يعدل طلاب مدرسته فقط
    if (!$isGroupAdmin && $school_id != $userSchoolId) {
        $errors['school_id'] = 'يمكنك تعديل طلاب مدرستك فقط';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE students SET full_name_ar=?, full_name_en=?, guardian_name=?, guardian_phone=?, guardian_email=?, grade_level_id=?, school_id=?, enrollment_date=?, is_active=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([
                $full_name_ar,
                $full_name_en ?: null,
                $guardian_name ?: null,
                $guardian_phone ?: null,
                $guardian_email ?: null,
                $grade_level_id,
                $school_id,
                $enrollment_date ?: null,
                $is_active,
                $studentId
            ]);

            // تسجيل النشاط
            logActivity($db, $_SESSION['user']['id'], 'edit_student', "تعديل بيانات الطالب: {$full_name_ar} ({$student['student_number']})", 'student', $studentId);

            setFlash('تم تحديث بيانات الطالب بنجاح', 'success');
            redirect("view.php?id={$studentId}");
        } catch (Exception $e) {
            error_log('Student Edit Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء تحديث البيانات. يرجى المحاولة مرة أخرى.';
        }
    }

    // تحديث بيانات النموذج في حالة وجود أخطاء
    $student['full_name_ar'] = $full_name_ar;
    $student['full_name_en'] = $full_name_en;
    $student['guardian_name'] = $guardian_name;
    $student['guardian_phone'] = $guardian_phone;
    $student['guardian_email'] = $guardian_email;
    $student['grade_level_id'] = $grade_level_id;
    $student['school_id'] = $school_id;
    $student['enrollment_date'] = $enrollment_date;
    $student['is_active'] = $is_active;
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تعديل بيانات الطالب: <?= e($student['full_name_ar']) ?></h3>
        <div class="flex gap-2">
            <a href="view.php?id=<?= $studentId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
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
                    <label class="form-label">رقم الطالب</label>
                    <input type="text" class="form-control" value="<?= e($student['student_number']) ?>" readonly disabled
                           style="background:var(--surface);cursor:not-allowed;">
                    <div class="form-text">لا يمكن تعديل رقم الطالب</div>
                </div>

                <div class="form-group">
                    <label class="form-label">الاسم الكامل بالعربية <span class="required">*</span></label>
                    <input type="text" name="full_name_ar" class="form-control <?= isset($errors['full_name_ar']) ? 'is-invalid' : '' ?>"
                           value="<?= e($student['full_name_ar']) ?>" required
                           placeholder="أدخل الاسم الكامل بالعربية">
                    <?php if (isset($errors['full_name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['full_name_ar']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل بالإنجليزية</label>
                    <input type="text" name="full_name_en" class="form-control"
                           value="<?= e($student['full_name_en'] ?? '') ?>" dir="ltr"
                           placeholder="Full name in English">
                </div>

                <div class="form-group">
                    <label class="form-label">اسم ولي الأمر</label>
                    <input type="text" name="guardian_name" class="form-control"
                           value="<?= e($student['guardian_name'] ?? '') ?>"
                           placeholder="أدخل اسم ولي الأمر">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">هاتف ولي الأمر</label>
                    <input type="text" name="guardian_phone" class="form-control"
                           value="<?= e($student['guardian_phone'] ?? '') ?>" dir="ltr"
                           placeholder="777123456">
                </div>

                <div class="form-group">
                    <label class="form-label">بريد ولي الأمر</label>
                    <input type="email" name="guardian_email" class="form-control <?= isset($errors['guardian_email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($student['guardian_email'] ?? '') ?>" dir="ltr"
                           placeholder="guardian@example.com">
                    <?php if (isset($errors['guardian_email'])): ?>
                        <div class="invalid-feedback"><?= e($errors['guardian_email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">المرحلة الدراسية <span class="required">*</span></label>
                    <select name="grade_level_id" class="form-control <?= isset($errors['grade_level_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر المرحلة الدراسية</option>
                        <?php
                        $currentStage = '';
                        foreach ($gradeLevels as $gl):
                            if ($gl['stage'] !== $currentStage):
                                if ($currentStage !== '') echo '</optgroup>';
                                $currentStage = $gl['stage'];
                                $stageLabel = $currentStage === 'basic' ? 'المرحلة الأساسية' : 'المرحلة الثانوية';
                                echo "<optgroup label=\"{$stageLabel}\">";
                            endif;
                        ?>
                            <option value="<?= $gl['id'] ?>" <?= $student['grade_level_id'] == $gl['id'] ? 'selected' : '' ?>>
                                <?= e($gl['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentStage !== '') echo '</optgroup>'; ?>
                    </select>
                    <?php if (isset($errors['grade_level_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['grade_level_id']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">المدرسة <span class="required">*</span></label>
                    <?php if ($isGroupAdmin): ?>
                    <select name="school_id" class="form-control <?= isset($errors['school_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر المدرسة</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>" <?= $student['school_id'] == $school['id'] ? 'selected' : '' ?>>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <select name="school_id" class="form-control" required>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>" selected>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php if (isset($errors['school_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['school_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">تاريخ التسجيل</label>
                    <input type="date" name="enrollment_date" class="form-control <?= isset($errors['enrollment_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($student['enrollment_date'] ?? '') ?>">
                    <?php if (isset($errors['enrollment_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['enrollment_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <div class="form-check" style="margin-top:28px;">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= $student['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">طالب نشط</label>
                    </div>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="view.php?id=<?= $studentId ?>" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
