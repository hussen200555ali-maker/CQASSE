<?php
/**
 * ============================================================
 * EQAS - توزيع أوزان التقييمات
 * نظام التقييم الشامل - Weight Distribution Setup
 * ============================================================
 * ضبط أوزان أنواع التقييم لكل مادة:
 * - جميع الأوزان يجب أن يكون مجموعها 100%
 * - حفظ كقالب لإعادة الاستخدام
 * ============================================================
 */

$pageTitle = 'توزيع أوزان التقييمات';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'أوزان التقييمات' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// معالجة الحفظ
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_weights') {
            // حفظ أوزان مادة معينة
            $sgyId = (int)$_POST['subject_grade_year_id'];
            $weights = $_POST['weights'] ?? [];
            $totalWeight = array_sum($weights);

            if (abs($totalWeight - 100) > 0.01) {
                setFlash("مجموع الأوزان يجب أن يكون 100%. المجموع الحالي: {$totalWeight}%", 'danger');
            } else {
                try {
                    $db->beginTransaction();
                    foreach ($weights as $typeId => $weight) {
                        $stmt = $db->prepare("
                            INSERT INTO subject_weight_distribution (subject_grade_year_id, assessment_type_id, weight, academic_year_id, is_active)
                            VALUES (?, ?, ?, ?, 1)
                            ON DUPLICATE KEY UPDATE weight = VALUES(weight)
                        ");
                        $stmt->execute([$sgyId, (int)$typeId, (float)$weight, $academicYearId]);
                    }
                    $db->commit();
                    logActivity($db, $_SESSION['user']['id'], 'setup_weights', "حفظ أوزان المادة-الصف رقم {$sgyId}", 'subject_weight_distribution', null);
                    setFlash('تم حفظ الأوزان بنجاح.', 'success');
                } catch (Exception $e) {
                    $db->rollBack();
                    setFlash('خطأ في الحفظ: ' . $e->getMessage(), 'danger');
                }
            }

        } elseif ($action === 'save_template') {
            // حفظ كقالب
            $templateName = trim($_POST['template_name'] ?? '');
            $weights = $_POST['weights'] ?? [];

            if (empty($templateName)) {
                setFlash('يرجى إدخال اسم القالب.', 'warning');
            } else {
                try {
                    $templateData = json_encode($weights, JSON_UNESCAPED_UNICODE);
                    $stmt = $db->prepare("
                        INSERT INTO system_settings (setting_key, setting_value, value_type, is_active)
                        VALUES (?, ?, 'json', 1)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    $stmt->execute(['weight_template_' . $templateName, $templateData]);
                    setFlash('تم حفظ القالب بنجاح.', 'success');
                } catch (Exception $e) {
                    setFlash('خطأ في حفظ القالب.', 'danger');
                }
            }

        } elseif ($action === 'apply_template') {
            // تطبيق قالب على مادة
            $sgyId = (int)$_POST['subject_grade_year_id'];
            $templateData = $_POST['template_data'] ?? '';

            if ($templateData) {
                $weights = json_decode($templateData, true);
                if ($weights && is_array($weights)) {
                    try {
                        $db->beginTransaction();
                        foreach ($weights as $typeId => $weight) {
                            $stmt = $db->prepare("
                                INSERT INTO subject_weight_distribution (subject_grade_year_id, assessment_type_id, weight, academic_year_id, is_active)
                                VALUES (?, ?, ?, ?, 1)
                                ON DUPLICATE KEY UPDATE weight = VALUES(weight)
                            ");
                            $stmt->execute([$sgyId, (int)$typeId, (float)$weight, $academicYearId]);
                        }
                        $db->commit();
                        setFlash('تم تطبيق القالب بنجاح.', 'success');
                    } catch (Exception $e) {
                        $db->rollBack();
                        setFlash('خطأ في تطبيق القالب.', 'danger');
                    }
                }
            }
        }

        redirect('weight_setup.php');
    }
}

// ═══════════════════════════════════════════
// جلب البيانات
// ═══════════════════════════════════════════
// جلب أنواع التقييمات (أولوية: system_settings JSON → احتياطي: جدول assessment_types)
$settingsValue = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'assessment_types'")->fetchColumn();
$assessmentTypes = json_decode($settingsValue ?: '[]', true);
if (empty($assessmentTypes) || !is_array($assessmentTypes)) {
    $stmt = $db->query("SELECT id, name_ar, name_en, code, weight_default, sort_order, is_active FROM assessment_types WHERE is_active = 1 ORDER BY sort_order");
    $assessmentTypes = $stmt ? $stmt->fetchAll() : [];
}

$subjectGrades = $db->query("
    SELECT sgy.id, sgy.subject_id, sgy.grade_level_id, sgy.hours_per_week, sgy.passing_grade,
           s.name_ar as subject_name, s.code as subject_code,
           gl.name_ar as grade_name, gl.level_number
    FROM subject_grade_years sgy
    JOIN subjects s ON sgy.subject_id = s.id
    JOIN grade_levels gl ON sgy.grade_level_id = gl.id
    WHERE sgy.is_active = 1
    ORDER BY gl.level_number, s.name_ar
")->fetchAll();

// الأوزان الحالية
$currentWeights = [];
$stmt = $db->prepare("SELECT subject_grade_year_id, assessment_type_id, weight FROM subject_weight_distribution WHERE is_active = 1 AND academic_year_id = ?");
$stmt->execute([$academicYearId]);
while ($row = $stmt->fetch()) {
    $currentWeights[$row['subject_grade_year_id']][$row['assessment_type_id']] = (float)$row['weight'];
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h2 class="card-title">⚖️ توزيع أوزان التقييمات</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>⚠️ هام:</strong> مجموع أوزان التقييمات لكل مادة يجب أن يساوي <strong>100%</strong> بالضبط.
            </div>

            <?php if (empty($subjectGrades)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">⚖️</div>
                <p>لم يتم تعيين مواد للصفوف بعد. يرجى الذهاب لصفحة <a href="subjects.php">إعداد المواد</a> أولاً.</p>
            </div>
            <?php elseif (empty($assessmentTypes)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <p>لم يتم تعريف أنواع التقييمات بعد. يرجى التواصل مع مدير النظام.</p>
            </div>
            <?php else: ?>

            <?php foreach ($subjectGrades as $sg): ?>
            <?php
                $existingWeights = $currentWeights[$sg['id']] ?? [];
                $totalWeight = 0;
                foreach ($assessmentTypes as $at) {
                    $totalWeight += $existingWeights[$at['id']] ?? $at['weight_default'] ?? 0;
                }
                $isComplete = abs($totalWeight - 100) < 0.01;
            ?>
            <div class="card mb-4" style="border-right:4px solid <?= $isComplete ? 'var(--success)' : 'var(--warning)' ?>;">
                <div class="card-header" style="background:var(--background);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h4 style="margin:0;">
                            <?= e($sg['grade_name']) ?> - <?= e($sg['subject_name']) ?>
                            <span style="font-size:12px;color:var(--text-muted);">(<?= e($sg['subject_code']) ?>)</span>
                        </h4>
                        <span style="padding:2px 10px;border-radius:var(--radius-full);font-size:11px;
                            background:<?= $isComplete ? 'var(--success)' : 'var(--warning)' ?>;
                            color:<?= $isComplete ? 'white' : 'var(--text-primary)' ?>;">
                            <?= $isComplete ? '✅ مكتمل' : '⏳ المجموع: ' . $totalWeight . '%' ?>
                        </span>
                    </div>
                </div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_weights">
                    <input type="hidden" name="subject_grade_year_id" value="<?= $sg['id'] ?>">
                    <div class="card-body">
                        <div class="grid grid-auto" style="gap:12px;">
                            <?php foreach ($assessmentTypes as $at):
                                $currentVal = $existingWeights[$at['id']] ?? $at['weight_default'] ?? 0;
                            ?>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <label style="min-width:120px;font-size:13px;font-weight:500;"><?= e($at['name_ar']) ?></label>
                                <input type="number" name="weights[<?= $at['id'] ?>]" class="form-control"
                                       value="<?= $currentVal ?>" min="0" max="100" step="0.5"
                                       style="width:80px;text-align:center;"
                                       oninput="updateTotal(this)">
                                <span style="font-size:12px;color:var(--text-muted);">%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:12px;display:flex;align-items:center;gap:12px;">
                            <strong>المجموع:</strong>
                            <span class="weight-total" data-form="<?= $sg['id'] ?>"
                                  style="font-weight:bold;color:<?= $isComplete ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $totalWeight ?>%
                            </span>
                            <button type="submit" class="btn btn-primary btn-sm">💾 حفظ</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>

            <!-- حفظ كقالب -->
            <div class="card mt-4">
                <div class="card-header">
                    <h4 style="margin:0;">💾 حفظ كقالب</h4>
                </div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_template">
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">اسم القالب</label>
                                <input type="text" name="template_name" class="form-control" placeholder="مثال: أوزان المواد العلمية" required>
                            </div>
                            <?php foreach ($assessmentTypes as $at): ?>
                            <div class="form-group" style="max-width:120px;">
                                <label class="form-label"><?= e($at['name_ar']) ?></label>
                                <input type="number" name="weights[<?= $at['id'] ?>]" class="form-control"
                                       value="<?= $at['weight_default'] ?? 0 ?>" min="0" max="100" step="0.5">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-secondary">💾 حفظ القالب</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateTotal(input) {
    var form = input.closest('form');
    var inputs = form.querySelectorAll('input[type="number"][name^="weights["]');
    var total = 0;
    inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
    var totalEl = form.querySelector('.weight-total');
    if (totalEl) {
        totalEl.textContent = total.toFixed(1) + '%';
        totalEl.style.color = Math.abs(total - 100) < 0.1 ? 'var(--success)' : 'var(--danger)';
    }
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
