<?php
/**
 * ============================================================
 * EQAS - توزيع أوزان التقييمات
 * نظام التقييم الشامل - إعداد أوزان أنواع التقييم لكل مادة
 * ============================================================
 * يتضمن:
 * - اختيار المادة والمرحلة الدراسية
 * - تعيين وزن لكل نوع تقييم (شهري، واجب، نهائي...)
 * - التحقق من أن مجموع الأوزان = 100%
 * - حفظ لكل عام دراسي
 * - معاينة مثال حسابي
 * ============================================================
 */

$pageTitle = 'توزيع أوزان التقييمات';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'توزيع الأوزان' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$academicYearId = getCurrentAcademicYearId($db);

// ============================================================
// معالجة الحفظ
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_weights') {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح', 'danger');
        redirect('weight_setup.php');
    }

    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $gradeLevelId = (int)($_POST['grade_level_id'] ?? 0);
    $weights = $_POST['weights'] ?? []; // مصفوفة [assessment_type_id => weight]

    if ($subjectId <= 0 || $gradeLevelId <= 0) {
        setFlash('يرجى اختيار المادة والمرحلة الدراسية', 'danger');
        redirect('weight_setup.php');
    }

    // التحقق من مجموع الأوزان
    $totalWeight = array_sum(array_map('floatval', $weights));
    if (abs($totalWeight - 100) > 0.01) {
        setFlash("مجموع الأوزان يجب أن يساوي 100%. المجموع الحالي: {$totalWeight}%", 'danger');
        redirect("weight_setup.php?subject_id={$subjectId}&grade_level_id={$gradeLevelId}");
    }

    try {
        // جلب subject_grade_year_id
        $stmt = $db->prepare("
            SELECT id FROM subject_grade_years
            WHERE subject_id = ? AND grade_level_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$subjectId, $gradeLevelId]);
        $sgy = $stmt->fetch();

        if (!$sgy) {
            // إنشاء تعيين تلقائياً
            $stmt = $db->prepare("
                INSERT INTO subject_grade_years (subject_id, grade_level_id, passing_grade, is_active)
                VALUES (?, ?, 50.00, 1)
            ");
            $stmt->execute([$subjectId, $gradeLevelId]);
            $sgyId = $db->lastInsertId();
        } else {
            $sgyId = $sgy['id'];
        }

        // حذف الأوزان السابقة
        $stmt = $db->prepare("
            DELETE FROM subject_weight_distribution
            WHERE subject_grade_year_id = ? AND academic_year_id = ?
        ");
        $stmt->execute([$sgyId, $academicYearId]);

        // إدراج الأوزان الجديدة
        $stmt = $db->prepare("
            INSERT INTO subject_weight_distribution (subject_grade_year_id, assessment_type_id, weight, academic_year_id, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        foreach ($weights as $typeId => $weight) {
            $weight = (float)$weight;
            if ($weight > 0) {
                $stmt->execute([$sgyId, (int)$typeId, $weight, $academicYearId]);
            }
        }

        setFlash('تم حفظ توزيع الأوزان بنجاح', 'success');
        logActivity($db, $_SESSION['user']['id'] ?? 0, 'save_weights', "حفظ توزيع أوزان المادة", 'subject', $subjectId);
    } catch (Exception $e) {
        setFlash('حدث خطأ أثناء الحفظ: ' . $e->getMessage(), 'danger');
    }

    redirect("weight_setup.php?subject_id={$subjectId}&grade_level_id={$gradeLevelId}");
}

// ============================================================
// جلب البيانات
// ============================================================
$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
$gradeLevels = $db->query("SELECT id, name_ar, level_number, stage FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();
$assessmentTypes = $db->query("SELECT id, name_ar, code, weight_default, sort_order FROM assessment_types WHERE is_active = 1 ORDER BY sort_order")->fetchAll();

$selectedSubjectId = (int)($_GET['subject_id'] ?? 0);
$selectedGradeLevelId = (int)($_GET['grade_level_id'] ?? 0);
$currentWeights = [];
$subjectName = '';
$gradeName = '';

if ($selectedSubjectId > 0 && $selectedGradeLevelId > 0) {
    // جلب الأسماء
    $stmt = $db->prepare("SELECT name_ar FROM subjects WHERE id = ?");
    $stmt->execute([$selectedSubjectId]);
    $subjectName = $stmt->fetchColumn() ?: '';

    $stmt = $db->prepare("SELECT name_ar FROM grade_levels WHERE id = ?");
    $stmt->execute([$selectedGradeLevelId]);
    $gradeName = $stmt->fetchColumn() ?: '';

    // جلب الأوزان الحالية
    $stmt = $db->prepare("
        SELECT swd.assessment_type_id, swd.weight
        FROM subject_weight_distribution swd
        INNER JOIN subject_grade_years sgy ON sgy.id = swd.subject_grade_year_id
        WHERE sgy.subject_id = ? AND sgy.grade_level_id = ?
            AND swd.academic_year_id = ? AND swd.is_active = 1
    ");
    $stmt->execute([$selectedSubjectId, $selectedGradeLevelId, $academicYearId]);
    while ($row = $stmt->fetch()) {
        $currentWeights[$row['assessment_type_id']] = (float)$row['weight'];
    }
}
?>

<!-- ============================================================
     نموذج الاختيار
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">⚖️ توزيع أوزان التقييمات</h3>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-4">
            قم بتعيين وزن نسبة مئوية لكل نوع تقييم. يجب أن يكون المجموع 100%.
        </p>
        <form method="GET" action="weight_setup.php" class="form-inline">
            <div class="form-group">
                <label class="form-label">المادة</label>
                <select name="subject_id" class="form-control" required>
                    <option value="">-- اختر المادة --</option>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $selectedSubjectId == $sub['id'] ? 'selected' : '' ?>>
                            <?= e($sub['name_ar']) ?> (<?= e($sub['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">المرحلة الدراسية</label>
                <select name="grade_level_id" class="form-control" required>
                    <option value="">-- اختر المرحلة --</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= $gl['id'] ?>" <?= $selectedGradeLevelId == $gl['id'] ? 'selected' : '' ?>>
                            <?= e($gl['name_ar']) ?> (<?= $gl['stage'] === 'secondary' ? 'ثانوي' : 'أساس' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
        </form>
    </div>
</div>

<?php if ($selectedSubjectId > 0 && $selectedGradeLevelId > 0): ?>

<!-- ============================================================
     نموذج توزيع الأوزان
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">⚖️ أوزان التقييمات: <?= e($subjectName) ?> - <?= e($gradeName) ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" action="weight_setup.php" id="weightForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_weights">
            <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">
            <input type="hidden" name="grade_level_id" value="<?= $selectedGradeLevelId ?>">

            <div class="table-wrapper">
                <table class="data-table" id="weightTable">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>نوع التقييم</th>
                            <th>الرمز</th>
                            <th style="width:150px">الوزن (%)</th>
                            <th style="width:300px">معاينة بصرية</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($assessmentTypes as $at): ?>
                            <?php
                            $currentWeight = $currentWeights[$at['id']] ?? ($at['weight_default'] ?? 0);
                            $defaultWeight = $at['weight_default'] ?? 0;
                            ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><strong><?= e($at['name_ar']) ?></strong></td>
                                <td><code><?= e($at['code']) ?></code></td>
                                <td>
                                    <input type="number" name="weights[<?= $at['id'] ?>]"
                                           class="form-control weight-input"
                                           data-type-id="<?= $at['id'] ?>"
                                           value="<?= $currentWeight ?>"
                                           min="0" max="100" step="0.5"
                                           placeholder="<?= $defaultWeight ?>"
                                           oninput="updateTotal()">
                                </td>
                                <td>
                                    <div style="background:var(--background); border-radius:var(--radius-md); height:24px; overflow:hidden; position:relative;">
                                        <div class="weight-bar" style="height:100%; width:<?= $currentWeight ?>%; background:var(--primary); border-radius:var(--radius-md); transition:width 0.3s;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:left;"><strong>المجموع</strong></td>
                            <td>
                                <strong id="totalWeight" style="font-size:var(--font-size-lg); color:<?= array_sum($currentWeights) == 100 || empty($currentWeights) ? 'var(--success)' : 'var(--danger)' ?>">
                                    <?= empty($currentWeights) ? round(array_sum(array_map(function($at) { return $at['weight_default'] ?? 0; }, $assessmentTypes)), 1) : array_sum($currentWeights) ?>%
                                </strong>
                            </td>
                            <td>
                                <span id="weightStatus" class="badge badge-<?= empty($currentWeights) || array_sum($currentWeights) == 100 ? 'success' : 'danger' ?>">
                                    <?= empty($currentWeights) || array_sum($currentWeights) == 100 ? '✅ صحيح' : '❌ يجب أن يكون 100%' ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex items-center justify-between mt-6 flex-wrap gap-4">
                <div class="text-sm text-secondary">
                    ⚠️ يجب أن يكون مجموع الأوزان 100% بالضبط للحفظ
                </div>
                <div class="flex gap-3">
                    <button type="button" class="btn btn-light" onclick="resetDefaults()">🔄 إعادة تعيين الافتراضي</button>
                    <button type="submit" class="btn btn-success btn-lg" id="saveWeightBtn">
                        💾 حفظ الأوزان
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     معاينة حسابية
     ============================================================ -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">🔢 معاينة حسابية</h3>
    </div>
    <div class="card-body">
        <p class="text-sm text-secondary mb-4">مثال توضيحي لكيفية حساب الدرجة النهائية بناءً على الأوزان:</p>
        <div class="table-wrapper">
            <table class="data-table" id="previewTable">
                <thead>
                    <tr>
                        <th>نوع التقييم</th>
                        <th>الوزن</th>
                        <th>درجة الطالب (من 100)</th>
                        <th>الدرجة المرجحة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assessmentTypes as $at): ?>
                        <?php $w = $currentWeights[$at['id']] ?? ($at['weight_default'] ?? 0); ?>
                        <tr>
                            <td><?= e($at['name_ar']) ?></td>
                            <td class="text-center"><?= $w ?>%</td>
                            <td>
                                <input type="number" class="form-control preview-grade"
                                       data-weight="<?= $w ?>"
                                       value="<?= rand(50, 95) ?>"
                                       min="0" max="100"
                                       oninput="updatePreview()" style="width:100px;">
                            </td>
                            <td class="text-center preview-weighted">-</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:left;"><strong>المجموع النهائي</strong></td>
                        <td class="text-center"><strong id="previewTotal">-</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
/**
 * تحديث مجموع الأوزان
 */
function updateTotal() {
    const inputs = document.querySelectorAll('.weight-input');
    let total = 0;

    inputs.forEach(input => {
        total += parseFloat(input.value) || 0;
        // تحديث الشريط البصري
        const row = input.closest('tr');
        const bar = row.querySelector('.weight-bar');
        if (bar) bar.style.width = input.value + '%';
    });

    const totalEl = document.getElementById('totalWeight');
    const statusEl = document.getElementById('weightStatus');
    const saveBtn = document.getElementById('saveWeightBtn');

    totalEl.textContent = total.toFixed(1) + '%';

    if (Math.abs(total - 100) < 0.01) {
        totalEl.style.color = 'var(--success)';
        statusEl.className = 'badge badge-success';
        statusEl.textContent = '✅ صحيح';
        saveBtn.disabled = false;
    } else {
        totalEl.style.color = 'var(--danger)';
        statusEl.className = 'badge badge-danger';
        statusEl.textContent = '❌ يجب أن يكون 100% (الحالي: ' + total.toFixed(1) + '%)';
        saveBtn.disabled = true;
    }

    updatePreview();
}

/**
 * تحديث معاينة الحساب
 */
function updatePreview() {
    const previewInputs = document.querySelectorAll('.preview-grade');
    let totalWeighted = 0;
    let totalAppliedWeight = 0;

    previewInputs.forEach((input, idx) => {
        const weight = parseFloat(input.dataset.weight) || 0;
        const grade = parseFloat(input.value) || 0;
        const row = input.closest('tr');
        const weightedCell = row.querySelector('.preview-weighted');

        if (weight > 0 && grade > 0) {
            const weighted = (grade * weight / 100).toFixed(2);
            weightedCell.textContent = weighted;
            totalWeighted += parseFloat(weighted);
            totalAppliedWeight += weight;
        } else {
            weightedCell.textContent = '-';
        }
    });

    const previewTotal = document.getElementById('previewTotal');
    if (totalAppliedWeight > 0) {
        const finalPct = (totalWeighted * 100 / (totalAppliedWeight / 100)).toFixed(2);
        previewTotal.textContent = totalWeighted.toFixed(2) + ' / 100%';
        previewTotal.style.color = totalWeighted >= 50 ? 'var(--success)' : 'var(--danger)';
    }
}

/**
 * إعادة تعيين القيم الافتراضية
 */
function resetDefaults() {
    <?php foreach ($assessmentTypes as $at): ?>
    const input_<?= $at['id'] ?> = document.querySelector('input[name="weights[<?= $at['id'] ?>]"]');
    if (input_<?= $at['id'] ?>) input_<?= $at['id'] ?>.value = <?= $at['weight_default'] ?? 0 ?>;
    <?php endforeach; ?>
    updateTotal();
}

// تهيئة
document.addEventListener('DOMContentLoaded', function() {
    updateTotal();
    updatePreview();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
