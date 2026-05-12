<?php
/**
 * ============================================================
 * تعديل سنة أكاديمية
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل سنة أكاديمية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'تعديل سنة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$yearId = intval($_GET['id'] ?? 0);
if (!$yearId) {
    setFlash('معرف السنة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
$stmt->execute([$yearId]);
$year = $stmt->fetch();

if (!$year) {
    setFlash('السنة الأكاديمية غير موجودة', 'danger');
    redirect('index.php');
}

// التحقق من حالة القفل
// [إصلاح 2026-05-11] فحص lock_level من الفصول بدلاً من is_locked (العمود غير موجود في academic_years)
$stmt = $db->prepare("SELECT lock_level FROM semesters WHERE academic_year_id = ? AND lock_level IN ('hard', 'admin_handled') LIMIT 1");
$stmt->execute([$yearId]);
$lockInfo = $stmt->fetch();

if ($lockInfo && $lockInfo['lock_level'] === 'admin_handled') {
    setFlash('لا يمكن تعديل هذه السنة لأنها مقفلة بقفل إداري. فقط مدير المجموعة يمكنه فتحها.', 'danger');
    redirect('view.php?id=' . $yearId);
}

if ($lockInfo && $lockInfo['lock_level'] === 'hard' && !hasPermission('group_admin')) {
    setFlash('لا يمكن تعديل هذه السنة لأنها مقفلة بقفل صلب', 'danger');
    redirect('view.php?id=' . $yearId);
}

if ($year['status'] === 'closed') {
    setFlash('لا يمكن تعديل سنة أكاديمية مغلقة', 'danger');
    redirect('view.php?id=' . $yearId);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("edit.php?id={$yearId}");
    }

    $name = trim($_POST['name'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $status = $_POST['status'] ?? $year['status'];

    if (empty($name)) $errors['name'] = 'اسم السنة الأكاديمية مطلوب';
    if (empty($start_date)) $errors['start_date'] = 'تاريخ البداية مطلوب';
    if (empty($end_date)) $errors['end_date'] = 'تاريخ النهاية مطلوب';

    if (!empty($start_date) && !empty($end_date) && $end_date <= $start_date) {
        $errors['end_date'] = 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية';
    }

    // التحقق من التداخل
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id, name FROM academic_years WHERE is_active = 1 AND id != ? AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))");
        $stmt->execute([$yearId, $start_date, $start_date, $end_date, $end_date]);
        $overlap = $stmt->fetch();
        if ($overlap) {
            $errors['start_date'] = 'تتداخل التواريخ مع السنة: ' . $overlap['name'];
        }
    }

    // التحقق من إمكانية تغيير التواريخ إذا كان هناك بيانات
    if (empty($errors) && ($start_date !== $year['start_date'] || $end_date !== $year['end_date'])) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM assessment_responses WHERE academic_year_id = ?");
        $stmt->execute([$yearId]);
        if ($stmt->fetchColumn() > 0) {
            $errors['start_date'] = 'لا يمكن تغيير التواريخ لوجود بيانات تقييم مرتبطة';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE academic_years SET name = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $start_date, $end_date, $status, $yearId]);

            logActivity($db, $_SESSION['user']['id'], 'edit_academic_year', "تعديل السنة الأكاديمية: {$name}", 'academic_year', $yearId);
            setFlash('تم تحديث السنة الأكاديمية بنجاح', 'success');
            redirect("view.php?id={$yearId}");
        } catch (Exception $e) {
            error_log('Academic Year Edit Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء التحديث';
        }
    }

    $year['name'] = $name;
    $year['start_date'] = $start_date;
    $year['end_date'] = $end_date;
    $year['status'] = $status;
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تعديل السنة الأكاديمية: <?= e($year['name']) ?></h3>
        <a href="view.php?id=<?= $yearId ?>" class="btn btn-light btn-sm">↩ العودة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <form method="POST" action="" data-validate style="max-width:600px;">
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">اسم السنة الأكاديمية <span class="required">*</span></label>
                <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= e($year['name']) ?>" required>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">تاريخ البداية <span class="required">*</span></label>
                    <input type="date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($year['start_date']) ?>" required>
                    <?php if (isset($errors['start_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['start_date']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">تاريخ النهاية <span class="required">*</span></label>
                    <input type="date" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($year['end_date']) ?>" required>
                    <?php if (isset($errors['end_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['end_date']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">الحالة</label>
                <select name="status" class="form-control">
                    <option value="planning" <?= $year['status'] === 'planning' ? 'selected' : '' ?>>تخطيط</option>
                    <option value="active" <?= $year['status'] === 'active' ? 'selected' : '' ?>>نشطة</option>
                    <?php if ($year['status'] === 'closed'): ?>
                        <option value="closed" selected>مغلقة</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="view.php?id=<?= $yearId ?>" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
