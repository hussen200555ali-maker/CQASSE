<?php
$pageTitle = 'تعديل تواريخ الفصل الدراسي';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => '../academic/index.php',
    'إدارة الفصول الدراسية' => null,
    'تعديل التواريخ' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$semesterId = intval($_GET['id'] ?? 0);
if (!$semesterId) {
    setFlash('معرف الفصل غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات الفصل
$stmt = $db->prepare("SELECT s.*, ay.name AS year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = ?");
$stmt->execute([$semesterId]);
$semester = $stmt->fetch();

if (!$semester) {
    setFlash('الفصل الدراسي غير موجود', 'danger');
    redirect('index.php');
}

$yearId = $semester['academic_year_id'];

// التحقق من عدم القفل الإداري
if ($semester['lock_level'] === 'admin_handled') {
    setFlash('لا يمكن تعديل تواريخ فصل مقفل إدارياً', 'danger');
    redirect("semesters.php?year_id={$yearId}");
}

// التحقق من وجود حسابات EQI لهذا الفصل
$eqiCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM equi_results WHERE semester_id = ?");
    $stmt->execute([$semesterId]);
    $eqiCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("edit_semester.php?id={$semesterId}");
    }

    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');

    $errors = [];

    if (empty($startDate)) $errors[] = 'تاريخ البداية مطلوب';
    if (empty($endDate)) $errors[] = 'تاريخ النهاية مطلوب';

    if ($startDate && $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        if ($start >= $end) {
            $errors[] = 'يجب أن يكون تاريخ البداية قبل تاريخ النهاية';
        }

        // التحقق من عدم تداخل الفصول في نفس السنة
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND id != ? AND is_active = 1 AND start_date <= ? AND end_date >= ?");
        $stmt->execute([$yearId, $semesterId, $endDate, $startDate]);
        $overlapping = $stmt->fetchAll();

        if (!empty($overlapping)) {
            $names = array_map(function($s) { return $s['name_ar']; }, $overlapping);
            $errors[] = 'تتعارض هذه التواريخ مع الفصل/الفصول التالية: ' . implode(', ', $names);
        }
    }

    if (!empty($errors)) {
        setFlash(implode('<br>', $errors), 'danger');
        redirect("edit_semester.php?id={$semesterId}");
    }

    // حفظ القيم القديمة للتسجيل
    $oldStartDate = $semester['start_date'];
    $oldEndDate = $semester['end_date'];

    // تنفيذ التحديث
    try {
        $stmt = $db->prepare("UPDATE semesters SET start_date = ?, end_date = ? WHERE id = ?");
        $stmt->execute([$startDate, $endDate, $semesterId]);

        $description = "تعديل تواريخ الفصل {$semester['name_ar']} (السنة: {$semester['year_name']}): {$oldStartDate} → {$startDate}, {$oldEndDate} → {$endDate}";
        logActivity($db, $_SESSION['user']['id'], 'update_semester_dates', $description, 'semester', $semesterId);

        setFlash("تم تعديل تواريخ الفصل {$semester['name_ar']} بنجاح", 'success');
        redirect("semesters.php?year_id={$yearId}");
    } catch (Exception $e) {
        error_log('Edit Semester Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء تعديل تواريخ الفصل', 'danger');
        redirect("edit_semester.php?id={$semesterId}");
    }
}
?>
<div class="card mb-6">
    <div class="card-header" style="background:var(--gradient-primary);">
        <h3 class="card-title" style="color:var(--text-white);">🗓️ تعديل تواريخ الفصل: <?= e($semester['name_ar']) ?></h3>
        <a href="semesters.php?year_id=<?= $yearId ?>" class="btn btn-light btn-sm">↩ العودة إلى الفصول</a>
    </div>
    <div class="card-body">
        <?php if ($eqiCount > 0): ?>
        <div class="alert alert-warning mb-5">
            <span class="alert-icon">⚠️</span>
            <div class="flex-1">
                <strong>تنبيه: حسابات EQI موجودة</strong><br>
                <span class="text-sm">هذا الفصل يحتوي على <strong><?= $eqiCount ?></strong> حساب جودة (EQI). قد تؤثر التواريخ الجديدة على دقة المؤشر. يمكنك إعادة الحساب يدوياً من صفحة تقارير EQI بعد الحفظ.</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="alert alert-info mb-5">
            <span class="alert-icon">💡</span>
            <div class="flex-1">
                <strong>تعديل تواريخ الفصل الدراسي</strong><br>
                <span class="text-sm">يمكنك تعديل تاريخي البداية والنهاية للفصل. تأكد من عدم تداخل التواريخ مع الفصول الأخرى في نفس السنة الأكاديمية.</span>
            </div>
        </div>

        <!-- القسم 1: معلومات الفصل -->
        <div class="card mb-6">
            <div class="card-header">
                <h4 class="card-title" style="font-size:var(--font-size-base);">📋 معلومات الفصل الحالية</h4>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:var(--space-4);">
                    <div class="form-group">
                        <label class="form-label">السنة الأكاديمية</label>
                        <input type="text" class="form-control" value="<?= e($semester['year_name']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">اسم الفصل</label>
                        <input type="text" class="form-control" value="<?= e($semester['name_ar']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حالة القفل</label>
                        <div style="padding-top:var(--space-2);"><?php
                            $lockBadges = [
                                'none' => '<span class="badge badge-success">مفتوح</span>',
                                'soft' => '<span class="badge badge-info">قفل ناعم</span>',
                                'hard' => '<span class="badge badge-warning">قفل صلب</span>',
                                'admin_handled' => '<span class="badge badge-danger">قفل إداري</span>'
                            ];
                            echo $lockBadges[$semester['lock_level']] ?? e($semester['lock_level']);
                        ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- القسم 2: تعديل التواريخ -->
        <div class="card mb-6">
            <div class="card-header">
                <h4 class="card-title" style="font-size:var(--font-size-base);">✏️ تعديل التواريخ</h4>
            </div>
            <div class="card-body">
                <form method="POST" action="" data-validate>
                    <?= csrfField() ?>
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">تاريخ البداية <span class="required">*</span></label>
                            <input type="date" name="start_date" class="form-control" value="<?= e($semester['start_date']) ?>" required>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">تاريخ النهاية <span class="required">*</span></label>
                            <input type="date" name="end_date" class="form-control" value="<?= e($semester['end_date']) ?>" required>
                        </div>
                    </div>
                    <div class="flex gap-3" style="margin-top:var(--space-5);">
                        <button type="submit" name="save" class="btn btn-primary btn-lg">💾 حفظ التغييرات</button>
                        <a href="semesters.php?year_id=<?= $yearId ?>" class="btn btn-light btn-lg">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
