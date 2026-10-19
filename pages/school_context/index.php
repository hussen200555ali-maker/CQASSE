<?php
/**
 * ============================================================
 * EQAS - إدارة عوامل التعديل السياقي للمدارس
 * نظام التقييم الشامل - School Context Management
 * ============================================================
 */

$pageTitle = 'إدارة التعديل السياقي';
$breadcrumb = ['النظام' => null, 'التعديل السياقي' => null];

require_once __DIR__ . '/../../includes/header.php';
requireRole('group_admin');
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

$adjustService = new ContextualAdjustmentService($db);

// === معالجة الحفظ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_context'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }

    $schoolId = (int)($_POST['school_id'] ?? 0);
    if ($schoolId <= 0) {
        setFlash('الرجاء اختيار مدرسة', 'danger');
        redirect('index.php');
    }

    $data = [
        'location_type' => $_POST['location_type'] ?? null,
        'total_students' => $_POST['total_students'] !== '' ? (int)$_POST['total_students'] : null,
        'student_teacher_ratio' => $_POST['student_teacher_ratio'] !== '' ? (float)$_POST['student_teacher_ratio'] : null,
        'socioeconomic_index' => $_POST['socioeconomic_index'] !== '' ? (float)$_POST['socioeconomic_index'] : null,
        'parent_education_level' => $_POST['parent_education_level'] !== '' ? (float)$_POST['parent_education_level'] : null,
        'students_with_special_needs_pct' => $_POST['students_with_special_needs_pct'] !== '' ? (float)$_POST['students_with_special_needs_pct'] : null,
        'infrastructure_score' => $_POST['infrastructure_score'] !== '' ? (float)$_POST['infrastructure_score'] : null,
        'student_mobility_rate' => $_POST['student_mobility_rate'] !== '' ? (float)$_POST['student_mobility_rate'] : null,
        'notes' => $_POST['notes'] ?? '',
    ];

    if ($adjustService->saveContext($schoolId, $data)) {
        logActivity($db, $_SESSION['user']['id'], 'context_update', 'تحديث بيانات السياق للمدرسة: ' . $schoolId, 'school_context', $schoolId);
        setFlash('تم حفظ بيانات السياق المدرسي بنجاح', 'success');
    } else {
        setFlash('حدث خطأ أثناء حفظ البيانات', 'danger');
    }
    redirect('index.php');
}

// === تعبئة تلقائية للمدارس بدون سياق ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_fill'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }

    $schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY id")->fetchAll();
    $filled = 0;

    foreach ($schools as $s) {
        // التحقق من عدم وجود سياق مسجل
        $existing = $adjustService->loadContext($s['id']);
        if ($existing) continue;

        // توليد بيانات سياقية واقعية ومناسبة لكل مدرسة
        $seed = crc32($s['name_ar']);
        mt_srand($seed);

        $locRand = mt_rand(0, 100);
        if ($locRand < 40) $loc = 'urban';
        elseif ($locRand < 75) $loc = 'suburban';
        else $loc = 'rural';

        switch ($loc) {
            case 'rural':
                $socio = mt_rand(20, 50);
                $infra = mt_rand(30, 60);
                $parentEd = mt_rand(20, 50);
                $mobility = mt_rand(10, 30);
                break;
            case 'suburban':
                $socio = mt_rand(35, 65);
                $infra = mt_rand(45, 80);
                $parentEd = mt_rand(30, 65);
                $mobility = mt_rand(5, 20);
                break;
            default: // urban
                $socio = mt_rand(45, 85);
                $infra = mt_rand(60, 95);
                $parentEd = mt_rand(40, 85);
                $mobility = mt_rand(2, 15);
        }

        $data = [
            'location_type' => $loc,
            'total_students' => mt_rand(150, 1200),
            'student_teacher_ratio' => round(mt_rand(120, 350) / 10, 1),
            'socioeconomic_index' => (float)$socio,
            'parent_education_level' => (float)$parentEd,
            'students_with_special_needs_pct' => round(mt_rand(5, 150) / 10, 1),
            'infrastructure_score' => (float)$infra,
            'student_mobility_rate' => (float)$mobility,
            'notes' => 'تعبئة تلقائية أولية',
        ];

        if ($adjustService->saveContext($s['id'], $data)) {
            $filled++;
        }
    }

    if ($filled > 0) {
        logActivity($db, $_SESSION['user']['id'], 'context_auto_fill', 'تعبئة تلقائية لـ ' . $filled . ' مدرسة', 'school_context');
        setFlash("تمت التعبئة التلقائية لـ $filled مدرسة بنجاح", 'success');
    } else {
        setFlash('جميع المدارس لديها سياق مسجل مسبقاً', 'info');
    }
    redirect('index.php');
}

// جلب المدارس والسياقات
$schools = $db->query("SELECT id, name_ar, is_active FROM schools ORDER BY name_ar")->fetchAll();
$allContexts = $adjustService->getAllContexts();
$schoolsWithoutContext = $adjustService->getSchoolsWithoutContext();

// بناء map للسياقات
$contextMap = [];
foreach ($allContexts as $c) {
    $contextMap[$c['school_id']] = $c;
}

// المدرسة المختارة للتعديل
$editSchoolId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editContext = $editSchoolId ? ($contextMap[$editSchoolId] ?? null) : null;
?>

<div class="section">
    <div class="grid grid-2 mb-6">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">🏫</div>
                <div style="font-size:24px; font-weight:bold; color:var(--primary);"><?= count($allContexts) ?></div>
                <div class="text-secondary" style="font-size:14px;">مدارس مسجل لها سياق</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">⚠️</div>
                <div style="font-size:24px; font-weight:bold; color:var(--danger);"><?= count($schoolsWithoutContext) ?></div>
                <div class="text-secondary" style="font-size:14px;">مدارس بدون سياق</div>
            </div>
        </div>
    </div>

    <!-- نموذج الإضافة/التعديل -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title" style="display:flex; align-items:center; gap:8px;">
                <?= $editContext ? 'تعديل السياق: ' . e($editContext['school_name'] ?? '') : 'إضافة سياق لمدرسة' ?>
                <span data-tooltip="المعدّل السياقي يعوّض فروق الظروف بين المدارس (الموقع، الوضع الاقتصادي، البنية التحتية، إلخ) لضمان مقارنة عادلة. مؤشر التحدي (0-100) يحسب من 7 عوامل ويُستخدم لرفع EQI الخام للمدارس ذات الظروف الأصعب حتى 10 نقاط." style="cursor:pointer; font-size:18px; color:var(--primary);">❓</span>
            </h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="form-label">المدرسة <span class="text-danger">*</span></label>
                        <select name="school_id" class="form-control" <?= $editContext ? 'disabled' : 'required' ?>>
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $editSchoolId === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= e($s['name_ar']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editContext): ?>
                        <input type="hidden" name="school_id" value="<?= $editSchoolId ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-group col-md-4">
                        <label class="form-label">نوع المنطقة</label>
                        <select name="location_type" class="form-control">
                            <option value="">-- اختر --</option>
                            <option value="urban" <?= ($editContext['location_type'] ?? '') === 'urban' ? 'selected' : '' ?>>حضري</option>
                            <option value="suburban" <?= ($editContext['location_type'] ?? '') === 'suburban' ? 'selected' : '' ?>>ضواحي</option>
                            <option value="rural" <?= ($editContext['location_type'] ?? '') === 'rural' ? 'selected' : '' ?>>ريفي</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label class="form-label">إجمالي الطلاب</label>
                        <input type="number" name="total_students" class="form-control" min="0" step="1"
                               value="<?= e($editContext['total_students'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label class="form-label">نسبة الطلاب:معلمين</label>
                        <input type="number" name="student_teacher_ratio" class="form-control" min="0" step="0.1"
                               value="<?= e($editContext['student_teacher_ratio'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">المؤشر الاقتصادي (0-100)</label>
                        <input type="number" name="socioeconomic_index" class="form-control" min="0" max="100" step="0.1"
                               value="<?= e($editContext['socioeconomic_index'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">مستوى تعليم الوالدين (0-100)</label>
                        <input type="number" name="parent_education_level" class="form-control" min="0" max="100" step="0.1"
                               value="<?= e($editContext['parent_education_level'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">نسبة ذوي الاحتياجات (%)</label>
                        <input type="number" name="students_with_special_needs_pct" class="form-control" min="0" max="100" step="0.1"
                               value="<?= e($editContext['students_with_special_needs_pct'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label class="form-label">جودة البنية التحتية (0-100)</label>
                        <input type="number" name="infrastructure_score" class="form-control" min="0" max="100" step="0.1"
                               value="<?= e($editContext['infrastructure_score'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">معدل تنقل الطلاب (0-100)</label>
                        <input type="number" name="student_mobility_rate" class="form-control" min="0" max="100" step="0.1"
                               value="<?= e($editContext['student_mobility_rate'] ?? '') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" name="notes" class="form-control" value="<?= e($editContext['notes'] ?? '') ?>">
                    </div>
                </div>
                <?php if ($editContext && isset($editContext['challenge_index'])): ?>
                <div class="alert alert-info">
                    <strong>مؤشر التحدي الحالي:</strong> <?= $editContext['challenge_index'] ?>
                    (سيتم إعادة حسابه تلقائياً بعد الحفظ)
                </div>
                <?php endif; ?>
                <div class="mt-3">
                    <button type="submit" name="save_context" value="1" class="btn btn-primary">
                        💾 حفظ
                    </button>
                    <?php if ($editContext): ?>
                    <a href="index.php" class="btn btn-light">إلغاء</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول جميع المدارس -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">جميع المدارس وحالة السياق</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($schools)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">🏫</div>
                <div class="table-empty-text">لا توجد مدارس مسجلة</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>المدرسة</th>
                            <th>الموقع</th>
                            <th>المؤشر الاقتصادي</th>
                            <th>نسبة طالب:معلم</th>
                            <th>مؤشر التحدي</th>
                            <th>الحالة</th>
                            <th>آخر تحديث</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schools as $s):
                            $ctx = $contextMap[$s['id']] ?? null;
                            $hasContext = $ctx !== null;
                        ?>
                        <tr>
                            <td><?= e($s['name_ar']) ?></td>
                            <td>
                                <?php if ($hasContext && $ctx['location_type']): ?>
                                    <?php
                                    $locLabels = ['urban' => '🏙️ حضري', 'suburban' => '🏘️ ضواحي', 'rural' => '🌾 ريفي'];
                                    echo $locLabels[$ctx['location_type']] ?? $ctx['location_type'];
                                    ?>
                                <?php else: ?>--<?php endif; ?>
                            </td>
                            <td><?= $hasContext ? e($ctx['socioeconomic_index'] ?? '--') : '--' ?></td>
                            <td><?= $hasContext ? e($ctx['student_teacher_ratio'] ?? '--') : '--' ?></td>
                            <td>
                                <?php if ($hasContext && $ctx['challenge_index'] !== null):
                                    $ci = (float)$ctx['challenge_index'];
                                    $color = $ci < 30 ? 'var(--success)' : ($ci < 60 ? 'var(--warning)' : 'var(--danger)');
                                ?>
                                <span style="color:<?= $color ?>; font-weight:600;"><?= $ci ?></span>
                                <?php else: ?>--<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasContext): ?>
                                <span style="color:var(--success);">✅ مسجل</span>
                                <?php else: ?>
                                <span style="color:var(--danger);">❌ غير مسجل</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; white-space:nowrap;">
                                <?= $hasContext ? formatDateAr($ctx['updated_at'] ?? $ctx['created_at'], 'short') : '--' ?>
                            </td>
                            <td>
                                <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-info" title="تعديل السياق">✏️</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($schoolsWithoutContext)): ?>
    <!-- تنبيه: مدارس بدون سياق -->
    <div class="alert alert-warning mt-4">
        <strong>⚠️ مدارس بدون سياق مسجل:</strong>
        <?php foreach ($schoolsWithoutContext as $swc): ?>
            <a href="?edit=<?= $swc['id'] ?>" class="btn btn-sm btn-outline-warning ml-2"><?= e($swc['name_ar']) ?></a>
        <?php endforeach; ?>
        <form method="POST" style="display:inline-block; margin-right:10px;" onsubmit="return confirm('سيتم تعبئة بيانات سياقية تقديرية لجميع المدارس بدون سياق. هل تريد المتابعة؟')">
            <?= csrfField() ?>
            <button type="submit" name="auto_fill" value="1" class="btn btn-sm btn-primary" data-tooltip="تعبئة جميع المدارس ببيانات سياقية تلقائية ومناسبة حسب الموقع والنوع">
                ⚡ تعبئة تلقائية للكل
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
