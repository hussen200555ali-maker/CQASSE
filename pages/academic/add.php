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
        'semester1_end' => trim($_POST['semester1_end'] ?? ''),
        'semester2_start' => trim($_POST['semester2_start'] ?? ''),
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

    // التحقق من تواريخ الفصول الاختيارية
    if (empty($errors)) {
        if (!empty($old['semester1_end'])) {
            if ($old['semester1_end'] <= $old['start_date']) {
                $errors['semester1_end'] = 'نهاية الفصل الأول يجب أن تكون بعد تاريخ بداية السنة';
            } elseif ($old['semester1_end'] >= $old['end_date']) {
                $errors['semester1_end'] = 'نهاية الفصل الأول يجب أن تكون قبل تاريخ نهاية السنة';
            }
        }
        if (!empty($old['semester2_start'])) {
            if ($old['semester2_start'] <= $old['start_date']) {
                $errors['semester2_start'] = 'بداية الفصل الثاني يجب أن تكون بعد تاريخ بداية السنة';
            } elseif ($old['semester2_start'] >= $old['end_date']) {
                $errors['semester2_start'] = 'بداية الفصل الثاني يجب أن تكون قبل تاريخ نهاية السنة';
            }
        }
        if (!empty($old['semester1_end']) && !empty($old['semester2_start'])) {
            if ($old['semester2_start'] <= $old['semester1_end']) {
                $errors['semester2_start'] = 'بداية الفصل الثاني يجب أن تكون بعد نهاية الفصل الأول';
            }
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
            $stmt = $db->prepare("INSERT INTO academic_years (name, start_date, end_date, is_current, status, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([
                $old['name'],
                $old['start_date'],
                $old['end_date'],
                $isCurrent,
                $old['status']
            ]);

            $yearId = $db->lastInsertId();

            // تحديد تواريخ الفصول (يدوي أو تلقائي)
            if (!empty($old['semester1_end']) && !empty($old['semester2_start'])) {
                $sem1Start = $old['start_date'];
                $sem1End = $old['semester1_end'];
                $sem2Start = $old['semester2_start'];
                $sem2End = $old['end_date'];
            } else {
                // الحساب التلقائي المحسّن 45/10/45
                $startDate = new DateTime($old['start_date']);
                $endDate = new DateTime($old['end_date']);
                $totalDays = $startDate->diff($endDate)->days + 1;

                $sem1Days = intval($totalDays * 0.45);
                $breakDays = intval($totalDays * 0.10);
                $sem2Days = $totalDays - $sem1Days - $breakDays;

                $sem1EndDt = clone $startDate;
                $sem1EndDt->modify('+' . ($sem1Days - 1) . ' days');

                $sem2StartDt = clone $sem1EndDt;
                $sem2StartDt->modify('+' . ($breakDays + 1) . ' days');

                $sem1Start = $old['start_date'];
                $sem1End = $sem1EndDt->format('Y-m-d');
                $sem2Start = $sem2StartDt->format('Y-m-d');
                $sem2End = $old['end_date'];
            }

            // الفصل الأول
            $stmt = $db->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'none', 1, NOW())");
            $stmt->execute([
                $yearId,
                'الفصل الدراسي الأول',
                $sem1Start,
                $sem1End,
                $isCurrent ? 1 : 0
            ]);

            // الفصل الثاني
            $stmt = $db->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'none', 1, NOW())");
            $stmt->execute([
                $yearId,
                'الفصل الدراسي الثاني',
                $sem2Start,
                $sem2End,
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

<div class="card mb-6">
    <div class="card-header" style="background:var(--gradient-primary);">
        <h3 class="card-title" style="color:var(--text-white);">📋 إضافة سنة أكاديمية جديدة</h3>
        <a href="index.php" class="btn btn-light btn-sm">↩ العودة للقائمة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <div class="alert alert-info mb-5">
            <span class="alert-icon">💡</span>
            <div class="flex-1">
                <strong>إضافة سنة أكاديمية جديدة</strong><br>
                <span class="text-sm">املأ بيانات السنة، ثم حدد تواريخ الفصول يدوياً أو اتركها للحساب التلقائي (توزيع 45% فصل أول - 10% إجازة - 45% فصل ثان).</span>
            </div>
        </div>

        <form method="POST" action="" data-validate>
            <?= csrfField() ?>

            <!-- القسم 1: معلومات السنة -->
            <div class="card mb-6">
                <div class="card-header">
                    <h4 class="card-title" style="font-size:var(--font-size-base);">📅 معلومات السنة الأكاديمية</h4>
                </div>
                <div class="card-body">
                    <div class="form-group mb-4">
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
                            <label class="form-label">تاريخ بداية السنة <span class="required">*</span></label>
                            <input type="date" name="start_date" id="year_start" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($old['start_date'] ?? '') ?>" required onchange="previewDates()">
                            <?php if (isset($errors['start_date'])): ?>
                                <div class="invalid-feedback"><?= e($errors['start_date']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">تاريخ نهاية السنة <span class="required">*</span></label>
                            <input type="date" name="end_date" id="year_end" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($old['end_date'] ?? '') ?>" required onchange="previewDates()">
                            <?php if (isset($errors['end_date'])): ?>
                                <div class="invalid-feedback"><?= e($errors['end_date']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label">الحالة</label>
                        <select name="status" class="form-control">
                            <option value="planning" <?= ($old['status'] ?? 'planning') === 'planning' ? 'selected' : '' ?>>تخطيط</option>
                            <option value="active" <?= ($old['status'] ?? '') === 'active' ? 'selected' : '' ?>>نشطة</option>
                        </select>
                        <div class="form-text">إذا كانت أول سنة أكاديمية، سيتم تعيينها كالسنة الحالية تلقائياً</div>
                    </div>
                </div>
            </div>

            <!-- القسم 2: تواريخ الفصول -->
            <div class="card mb-6">
                <div class="card-header">
                    <h4 class="card-title" style="font-size:var(--font-size-base);">🗓️ تواريخ الفصول الدراسية</h4>
                    <span class="badge badge-info">اختياري</span>
                </div>
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-sm text-secondary">اترك الحقلين فارغين للحساب التلقائي (45% / 10% / 45%)</span>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">نهاية الفصل الدراسي الأول</label>
                            <input type="date" name="semester1_end" id="sem1_end" class="form-control <?= isset($errors['semester1_end']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($old['semester1_end'] ?? '') ?>" onchange="previewDates()">
                            <?php if (isset($errors['semester1_end'])): ?>
                                <div class="invalid-feedback"><?= e($errors['semester1_end']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">إذا تُرك فارغاً، يُحسب تلقائياً</div>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">بداية الفصل الدراسي الثاني</label>
                            <input type="date" name="semester2_start" id="sem2_start" class="form-control <?= isset($errors['semester2_start']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($old['semester2_start'] ?? '') ?>" onchange="previewDates()">
                            <?php if (isset($errors['semester2_start'])): ?>
                                <div class="invalid-feedback"><?= e($errors['semester2_start']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">إذا تُرك فارغاً، يُحسب تلقائياً</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- القسم 3: المعاينة -->
            <div id="datePreview" class="card mb-6" style="display:none;">
                <div class="card-header">
                    <h4 class="card-title" style="font-size:var(--font-size-base);">📋 معاينة الفصول الدراسية</h4>
                </div>
                <div class="card-body">
                    <div class="grid grid-3" style="gap:var(--space-4);">
                        <div class="stat-card primary" style="margin:0;">
                            <div class="stat-card-label" style="font-size:var(--font-size-base);font-weight:600;margin-bottom:var(--space-2);">📖 الفصل الأول</div>
                            <div id="preview_sem1" class="stat-card-value" style="font-size:var(--font-size-sm);font-weight:normal;"></div>
                        </div>
                        <div class="stat-card warning" style="margin:0;">
                            <div class="stat-card-label" style="font-size:var(--font-size-base);font-weight:600;margin-bottom:var(--space-2);">🌴 إجازة نصف العام</div>
                            <div id="preview_break" class="stat-card-value" style="font-size:var(--font-size-sm);font-weight:normal;"></div>
                        </div>
                        <div class="stat-card success" style="margin:0;">
                            <div class="stat-card-label" style="font-size:var(--font-size-base);font-weight:600;margin-bottom:var(--space-2);">📚 الفصل الثاني</div>
                            <div id="preview_sem2" class="stat-card-value" style="font-size:var(--font-size-sm);font-weight:normal;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- أزرار التحكم -->
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary btn-lg">💾 حفظ السنة الأكاديمية</button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<script>
function previewDates() {
    var start = document.getElementById('year_start').value;
    var end = document.getElementById('year_end').value;
    var manualSem1End = document.getElementById('sem1_end').value;
    var manualSem2Start = document.getElementById('sem2_start').value;

    var preview = document.getElementById('datePreview');
    var previewSem1 = document.getElementById('preview_sem1');
    var previewBreak = document.getElementById('preview_break');
    var previewSem2 = document.getElementById('preview_sem2');

    if (!start || !end) {
        preview.style.display = 'none';
        return;
    }

    preview.style.display = 'block';
    preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    if (manualSem1End && manualSem2Start) {
        previewSem1.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + start + '</strong><strong style="display:block;">إلى: ' + manualSem1End + '</strong>';
        var breakStart = new Date(manualSem1End);
        breakStart.setDate(breakStart.getDate() + 1);
        var breakEnd = new Date(manualSem2Start);
        breakEnd.setDate(breakEnd.getDate() - 1);
        if (breakEnd >= breakStart) {
            previewBreak.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + breakStart.toISOString().slice(0,10) + '</strong><strong style="display:block;">إلى: ' + breakEnd.toISOString().slice(0,10) + '</strong>';
        } else {
            previewBreak.innerHTML = '<span style="opacity:0.6;">(بدون إجازة)</span>';
        }
        previewSem2.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + manualSem2Start + '</strong><strong style="display:block;">إلى: ' + end + '</strong>';
        return;
    }

    var d1 = new Date(start);
    var d2 = new Date(end);
    var diffDays = Math.round((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;

    var sem1Days = Math.floor(diffDays * 0.45);
    var breakDays = Math.floor(diffDays * 0.10);
    var sem2Days = diffDays - sem1Days - breakDays;

    var sem1EndDt = new Date(d1);
    sem1EndDt.setDate(sem1EndDt.getDate() + sem1Days - 1);

    var sem2StartDt = new Date(sem1EndDt);
    sem2StartDt.setDate(sem2StartDt.getDate() + breakDays + 1);

    var breakEndDt = new Date(sem2StartDt);
    breakEndDt.setDate(breakEndDt.getDate() - 1);

    function fmt(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    previewSem1.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + start + '</strong><strong style="display:block;">إلى: ' + fmt(sem1EndDt) + '</strong><span class="badge badge-primary" style="display:inline-block;margin-top:var(--space-2);">' + sem1Days + ' يوم</span>';
    previewBreak.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + fmt(new Date(sem1EndDt.getTime() + 86400000)) + '</strong><strong style="display:block;">إلى: ' + fmt(breakEndDt) + '</strong><span class="badge badge-warning" style="display:inline-block;margin-top:var(--space-2);">' + breakDays + ' يوم</span>';
    previewSem2.innerHTML = '<strong style="display:block;margin-bottom:var(--space-1);">من: ' + fmt(sem2StartDt) + '</strong><strong style="display:block;">إلى: ' + end + '</strong><span class="badge badge-success" style="display:inline-block;margin-top:var(--space-2);">' + sem2Days + ' يوم</span>';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
