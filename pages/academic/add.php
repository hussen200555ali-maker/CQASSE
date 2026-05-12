<?php
/**
 * ============================================================
 * إضافة سنة أكاديمية جديدة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إضافة سنة أكاديمية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'إضافة سنة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('add.php');
    }

    $old = [
        'name' => trim($_POST['name'] ?? ''),
        'start_date' => trim($_POST['start_date'] ?? ''),
        'end_date' => trim($_POST['end_date'] ?? ''),
        'status' => $_POST['status'] ?? 'planning'
    ];

    // التحقق من الحقول المطلوبة
    if (empty($old['name'])) $errors['name'] = 'اسم السنة الأكاديمية مطلوب';
    if (empty($old['start_date'])) $errors['start_date'] = 'تاريخ البداية مطلوب';
    if (empty($old['end_date'])) $errors['end_date'] = 'تاريخ النهاية مطلوب';

    // التحقق من أن تاريخ النهاية بعد تاريخ البداية
    if (!empty($old['start_date']) && !empty($old['end_date'])) {
        if ($old['end_date'] <= $old['start_date']) {
            $errors['end_date'] = 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية';
        }
    }

    // التحقق من عدم تداخل التواريخ مع سنوات موجودة
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id, name FROM academic_years WHERE is_active = 1 AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?)) AND id != 0");
        $stmt->execute([$old['start_date'], $old['start_date'], $old['end_date'], $old['end_date']]);
        $overlap = $stmt->fetch();
        if ($overlap) {
            $errors['start_date'] = 'تتداخل التواريخ مع السنة الأكاديمية: ' . $overlap['name'];
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // التحقق مما إذا كانت هذه أول سنة أكاديمية
            $stmt = $db->query("SELECT COUNT(*) FROM academic_years WHERE is_active = 1");
            $isFirst = $stmt->fetchColumn() == 0;
            $isCurrent = $isFirst ? 1 : 0;

            // إدراج السنة الأكاديمية
            // [إصلاح 2026-05-11] إزالة is_locked (العمود غير موجود في schema)
            $stmt = $db->prepare("INSERT INTO academic_years (name, start_date, end_date, is_current, status, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([
                $old['name'],
                $old['start_date'],
                $old['end_date'],
                $isCurrent,
                $old['status']
            ]);

            $yearId = $db->lastInsertId();

            // إنشاء فصلين دراسيين تلقائياً
            $startDate = new DateTime($old['start_date']);
            $endDate = new DateTime($old['end_date']);
            $interval = $startDate->diff($endDate);
            $totalMonths = ($interval->y * 12) + $interval->m;
            $midDate = clone $startDate;
            $midDate->modify('+' . intval($totalMonths / 2) . ' months');

            // الفصل الأول
            // [إصلاح 2026-05-11] إزالة is_locked (العمود غير موجود في schema)
            $stmt = $db->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'none', 1, NOW())");
            $stmt->execute([
                $yearId,
                'الفصل الدراسي الأول',
                $old['start_date'],
                $midDate->format('Y-m-d'),
                $isCurrent ? 1 : 0
            ]);

            // الفصل الثاني
            // [إصلاح 2026-05-11] إزالة is_locked (العمود غير موجود في schema)
            $midDatePlus = clone $midDate;
            $midDatePlus->modify('+1 day');
            $stmt = $db->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'none', 1, NOW())");
            $stmt->execute([
                $yearId,
                'الفصل الدراسي الثاني',
                $midDatePlus->format('Y-m-d'),
                $old['end_date'],
                0
            ]);

            $db->commit();

            logActivity($db, $_SESSION['user']['id'], 'create_academic_year', "إضافة سنة أكاديمية: {$old['name']}", 'academic_year', $yearId);

            setFlash('تم إضافة السنة الأكاديمية والفصلين الدراسيين بنجاح', 'success');
            redirect('index.php');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Academic Year Add Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء إضافة السنة الأكاديمية';
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">إضافة سنة أكاديمية جديدة</h3>
        <a href="index.php" class="btn btn-light">↩ العودة للقائمة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <div class="flash-message flash-info mb-4" style="background:rgba(23,162,184,0.1);border:1px solid rgba(23,162,184,0.3);padding:var(--space-4);border-radius:var(--radius-md);">
            <span>💡 سيتم إنشاء فصلين دراسيين تلقائياً عند إضافة السنة الأكاديمية.</span>
        </div>

        <form method="POST" action="" data-validate style="max-width:600px;">
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">اسم السنة الأكاديمية <span class="required">*</span></label>
                <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= e($old['name'] ?? '') ?>" required placeholder="مثال: 2025-2026">
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                <?php endif; ?>
                <div class="form-text">أدخل اسم السنة مثل: 2025-2026</div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">تاريخ البداية <span class="required">*</span></label>
                    <input type="date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['start_date'] ?? '') ?>" required>
                    <?php if (isset($errors['start_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['start_date']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">تاريخ النهاية <span class="required">*</span></label>
                    <input type="date" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['end_date'] ?? '') ?>" required>
                    <?php if (isset($errors['end_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['end_date']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">الحالة</label>
                <select name="status" class="form-control">
                    <option value="planning" <?= ($old['status'] ?? 'planning') === 'planning' ? 'selected' : '' ?>>تخطيط</option>
                    <option value="active" <?= ($old['status'] ?? '') === 'active' ? 'selected' : '' ?>>نشطة</option>
                </select>
                <div class="form-text">إذا كانت أول سنة أكاديمية، سيتم تعيينها كالسنة الحالية تلقائياً</div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ السنة الأكاديمية</button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
