<?php
/**
 * ============================================================
 * إضافة طالب جديد
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إضافة طالب جديد';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => 'index.php',
    'إضافة طالب' => null
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
$old = [];

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect('add.php');
    }

    $old = [
        'student_number' => trim($_POST['student_number'] ?? ''),
        'full_name_ar' => trim($_POST['full_name_ar'] ?? ''),
        'full_name_en' => trim($_POST['full_name_en'] ?? ''),
        'guardian_name' => trim($_POST['guardian_name'] ?? ''),
        'guardian_phone' => trim($_POST['guardian_phone'] ?? ''),
        'guardian_email' => trim($_POST['guardian_email'] ?? ''),
        'grade_level_id' => $_POST['grade_level_id'] ?? '',
        'school_id' => $_POST['school_id'] ?? '',
        'enrollment_date' => trim($_POST['enrollment_date'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // التحقق من الحقول المطلوبة
    if (empty($old['full_name_ar'])) {
        $errors['full_name_ar'] = 'الاسم الكامل بالعربية مطلوب';
    }

    if (empty($old['grade_level_id'])) {
        $errors['grade_level_id'] = 'المرحلة الدراسية مطلوبة';
    }

    if (empty($old['school_id'])) {
        $errors['school_id'] = 'المدرسة مطلوبة';
    }

    // التحقق من رقم الطالب (توليد تلقائي إذا كان فارغاً)
    if (empty($old['student_number'])) {
        // توليد رقم طالب تلقائي
        $stmt = $db->prepare("SELECT MAX(CAST(student_number AS UNSIGNED)) AS max_num FROM students WHERE school_id = ?");
        $stmt->execute([$old['school_id']]);
        $maxNum = $stmt->fetchColumn();
        $old['student_number'] = 'STD' . str_pad(($maxNum ? $maxNum + 1 : 1), 5, '0', STR_PAD_LEFT);
    } else {
        // التحقق من تفرد رقم الطالب
        $stmt = $db->prepare("SELECT id FROM students WHERE student_number = ?");
        $stmt->execute([$old['student_number']]);
        if ($stmt->fetch()) {
            $errors['student_number'] = 'رقم الطالب مستخدم بالفعل';
        }
    }

    // التحقق من بريد ولي الأمر
    if (!empty($old['guardian_email']) && !filter_var($old['guardian_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['guardian_email'] = 'البريد الإلكتروني لولي الأمر غير صحيح';
    }

    // التحقق من تاريخ التسجيل
    if (!empty($old['enrollment_date']) && !isValidDate($old['enrollment_date'])) {
        $errors['enrollment_date'] = 'تاريخ التسجيل غير صحيح';
    }

    // التحقق من أن مدير المدرسة يضيف طلاب لمدرسته فقط
    if (!$isGroupAdmin && $old['school_id'] != $userSchoolId) {
        $errors['school_id'] = 'يمكنك إضافة طلاب لمدرستك فقط';
    }

    // التحقق من صحة المرحلة الدراسية
    if (!empty($old['grade_level_id'])) {
        $validGrade = false;
        foreach ($gradeLevels as $gl) {
            if ($gl['id'] == $old['grade_level_id']) {
                $validGrade = true;
                break;
            }
        }
        if (!$validGrade) {
            $errors['grade_level_id'] = 'المرحلة الدراسية غير صحيحة';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO students (student_number, full_name_ar, full_name_en, guardian_name, guardian_phone, guardian_email, grade_level_id, school_id, enrollment_date, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $old['student_number'],
                $old['full_name_ar'],
                $old['full_name_en'] ?: null,
                $old['guardian_name'] ?: null,
                $old['guardian_phone'] ?: null,
                $old['guardian_email'] ?: null,
                $old['grade_level_id'],
                $old['school_id'],
                $old['enrollment_date'] ?: null,
                $old['is_active']
            ]);

            $studentId = $db->lastInsertId();
            logActivity($db, $_SESSION['user']['id'], 'create_student', "إضافة طالب جديد: {$old['full_name_ar']} ({$old['student_number']})", 'student', $studentId);

            setFlash('تم إضافة الطالب بنجاح', 'success');
            redirect('index.php');
        } catch (Exception $e) {
            error_log('Student Add Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء إضافة الطالب. يرجى المحاولة مرة أخرى.';
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">إضافة طالب جديد</h3>
        <a href="index.php" class="btn btn-light">↩ العودة للقائمة</a>
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
                    <input type="text" name="student_number" class="form-control <?= isset($errors['student_number']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['student_number'] ?? '') ?>"
                           placeholder="يتم توليده تلقائياً إذا ترك فارغاً">
                    <?php if (isset($errors['student_number'])): ?>
                        <div class="invalid-feedback"><?= e($errors['student_number']) ?></div>
                    <?php endif; ?>
                    <div class="form-text">اتركه فارغاً للتوليد التلقائي</div>
                </div>

                <div class="form-group">
                    <label class="form-label">الاسم الكامل بالعربية <span class="required">*</span></label>
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
                    <label class="form-label">الاسم الكامل بالإنجليزية</label>
                    <input type="text" name="full_name_en" class="form-control"
                           value="<?= e($old['full_name_en'] ?? '') ?>" dir="ltr"
                           placeholder="Full name in English">
                </div>

                <div class="form-group">
                    <label class="form-label">اسم ولي الأمر</label>
                    <input type="text" name="guardian_name" class="form-control"
                           value="<?= e($old['guardian_name'] ?? '') ?>"
                           placeholder="أدخل اسم ولي الأمر">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">هاتف ولي الأمر</label>
                    <input type="text" name="guardian_phone" class="form-control"
                           value="<?= e($old['guardian_phone'] ?? '') ?>" dir="ltr"
                           placeholder="777123456">
                </div>

                <div class="form-group">
                    <label class="form-label">بريد ولي الأمر</label>
                    <input type="email" name="guardian_email" class="form-control <?= isset($errors['guardian_email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['guardian_email'] ?? '') ?>" dir="ltr"
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
                            <option value="<?= $gl['id'] ?>" <?= ($old['grade_level_id'] ?? '') == $gl['id'] ? 'selected' : '' ?>>
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
                    <select name="school_id" id="school-select" class="form-control <?= isset($errors['school_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر المدرسة</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>" <?= ($old['school_id'] ?? '') == $school['id'] ? 'selected' : '' ?>>
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
                           value="<?= e($old['enrollment_date'] ?? date('Y-m-d')) ?>">
                    <?php if (isset($errors['enrollment_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['enrollment_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <div class="form-check" style="margin-top:28px;">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= !isset($old['is_active']) || $old['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">طالب نشط</label>
                    </div>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ الطالب</button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
