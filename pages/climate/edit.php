<?php
/**
 * ============================================================
 * تعديل استبيان المناخ المدرسي - Edit Climate Survey
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل استبيان المناخ';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'استبيان المناخ' => 'index.php', 'تعديل الاستبيان' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

// فقط المدير يمكنه تعديل استبيانات المناخ
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية لتعديل استبيانات المناخ المدرسي', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];

$surveyId = (int)($_GET['id'] ?? 0);
if (!$surveyId) {
    setFlash('معرف الاستبيان غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات الاستبيان
$survey = null;
try {
    $stmt = $db->prepare("
        SELECT cs.*, s.name_ar as school_name,
               sem.name_ar as semester_name, ay.name as year_name,
               u.full_name_ar as respondent_name
        FROM surveys cs
        JOIN schools s ON cs.school_id = s.id
        JOIN semesters sem ON cs.semester_id = sem.id
        JOIN academic_years ay ON cs.academic_year_id = ay.id
        LEFT JOIN users u ON cs.respondent_id = u.id
        WHERE cs.survey_type = 'climate' AND cs.id = ? AND cs.is_active = 1
    ");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch();
} catch (Exception $e) {
    $survey = null;
}

if (!$survey) {
    setFlash('الاستبيان غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من قفل الفصل الدراسي
if (isSemesterLocked($db, $survey['semester_id'])) {
    if (!canEditSemester($db, $survey['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل الاستبيان', 'danger');
        redirect('view.php?id=' . $surveyId);
    }
}

$respondentTypes = ['teacher' => 'معلم', 'student' => 'طالب', 'parent' => 'ولي أمر', 'admin' => 'إداري'];
$respondentIcons = ['teacher' => '👨‍🏫', 'student' => '👨‍🎓', 'parent' => '👨‍👧', 'admin' => '👨‍💼'];

// جلب عناصر الاستبيان الحالية
$existingItems = [];
try {
    $stmt = $db->prepare("
        SELECT ci.id, ci.survey_id, ci.item_id, ci.normalized_value, ci.numeric_value, ci.is_active,
               ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value,
               ac.name_ar as category_name
        FROM climate_items ci
        JOIN assessment_structure ai ON ci.item_id = ai.id
        JOIN assessment_structure ac ON ai.parent_id = ac.id
        WHERE ci.survey_id = ? AND ci.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$surveyId]);
    $existingItems = $stmt->fetchAll();
} catch (Exception $e) {
    $existingItems = [];
}

// بناء مصفوفة القيم الحالية
$currentValues = [];
foreach ($existingItems as $ei) {
    $currentValues[$ei['item_id']] = $ei['normalized_value'];
}

// جلب نموذج المناخ
$climateForm = null;
$climateItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'climate_survey' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $climateForm = $engine->getFormWithItems($formRow['id']);
        if ($climateForm) {
            foreach ($climateForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $climateItems[] = $item;
                }
            }
        }
    }
} catch (Exception $e) {}

// معالجة التعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();

            $notes = trim($_POST['notes'] ?? '');

            $responses = [];
            $itemScores = [];
            foreach ($climateItems as $item) {
                $fieldName = 'item_' . $item['id'];
                $rawValue = $_POST[$fieldName] ?? null;
                if ($rawValue !== null && $rawValue !== '') {
                    $validation = $engine->validateTool($item['tool_type'], $rawValue, $item['max_value']);
                    if ($validation['valid']) {
                        $normalized = $engine->normalizeTool($item['tool_type'], $validation['value'], $item['max_value'], $item['is_inverse']);
                        $responses[] = ['weight' => $item['weight'], 'normalized' => $normalized];
                        $itemScores[$item['id']] = ['raw' => $validation['value'], 'normalized' => $normalized];
                    }
                }
            }

            $overallScore = $engine->calculateScore($responses);

            // تحديث الاستبيان الرئيسي
            $stmt = $db->prepare("
                UPDATE surveys
                SET overall_score = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$overallScore, $notes ?: null, $surveyId]);

            // تحديث عناصر الاستبيان
            $stmtCheck = $db->prepare("SELECT id FROM climate_items WHERE survey_id = ? AND item_id = ? AND is_active = 1");
            $stmtUpdate = $db->prepare("UPDATE climate_items SET normalized_value = ? WHERE survey_id = ? AND item_id = ?");
            $stmtInsert = $db->prepare("INSERT INTO climate_items (survey_id, item_id, normalized_value, is_active) VALUES (?, ?, ?, 1)");

            foreach ($itemScores as $itemId => $scores) {
                $stmtCheck->execute([$surveyId, $itemId]);
                $existingRow = $stmtCheck->fetch();

                if ($existingRow) {
                    $stmtUpdate->execute([$scores['normalized'], $surveyId, $itemId]);
                } else {
                    $stmtInsert->execute([$surveyId, $itemId, $scores['normalized']]);
                }
            }

            $db->commit();
            logActivity($db, $userId, 'edit_climate_survey', 'تعديل استبيان مناخ - الدرجة: ' . number_format($overallScore, 1) . '% - المدرسة: ' . $survey['school_name'], 'climate_survey', $surveyId);
            setFlash('تم تحديث الاستبيان بنجاح - الدرجة الكلية: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('view.php?id=' . $surveyId);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Climate Edit Error: ' . $e->getMessage());
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل استبيان المناخ المدرسي</h3>
            <div class="flex gap-2">
                <a href="view.php?id=<?= $surveyId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المدرسة</span>
                    <div style="font-weight:600;"><?= e($survey['school_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">نوع المستجيب</span>
                    <div style="font-weight:600;">
                        <?= ($respondentIcons[$survey['respondent_type']] ?? '') . ' ' . e($respondentTypes[$survey['respondent_type']] ?? $survey['respondent_type']) ?>
                    </div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span>
                    <div style="font-weight:600;"><?= e($survey['semester_name']) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?= e($survey['year_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الدرجة الحالية</span>
                    <?php
                        $currentScore = $survey['overall_score'] ?? 0;
                        $rating = $engine->getScoreRating($currentScore);
                    ?>
                    <div style="font-weight:700; font-size:20px; color:<?= $rating['color'] ?>;">
                        <?= number_format($currentScore, 1) ?>%
                    </div>
                    <div style="font-size:12px; color:<?= $rating['color'] ?>;"><?= $rating['label'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" data-validate>
        <?= csrfField() ?>

        <?php if (!empty($climateForm) && !empty($climateItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر الاستبيان</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score"><?= number_format($currentScore, 1) ?></span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($climateForm['categories'] as $cat): ?>
                <div class="mb-4" style="border:1px solid var(--border-light); border-radius:var(--radius-md); overflow:hidden;">
                    <div style="background:#fd7e14; color:white; padding:8px 16px; display:flex; align-items:center;">
                        <span style="font-weight:700;">🌡️ <?= e($cat['name_ar']) ?></span>
                    </div>
                    <div style="padding:16px;">
                        <?php foreach ($cat['items'] as $item):
                            $currentVal = $currentValues[$item['id']] ?? null;
                        ?>
                            <?= $engine->renderTool($item, $currentVal, 'item_') ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج استبيان مناخ</h4>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📝 ملاحظات</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="notes" class="form-control" rows="3" placeholder="أضف ملاحظاتك هنا..."><?= e($survey['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="view.php?id=<?= $surveyId ?>" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('change', function(e) {
    if (e.target.closest('.assessment-item')) {
        let totalWeight = 0, weightedSum = 0;
        document.querySelectorAll('.assessment-item').forEach(item => {
            const input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
            if (input) {
                const val = parseFloat(input.value || 0);
                const weight = parseFloat(input.dataset.weight || 1);
                const tool = item.dataset.tool;
                let n = 0;
                if (['likert_5','frequency_5','satisfaction_5'].includes(tool)) n = (val/5)*100;
                else if (tool === 'yes_no') n = val*100;
                else if (tool === 'severity_4') n = ((val-1)/3)*100;
                else if (tool === 'rubric_4') n = (val/4)*100;
                else if (tool === 'numeric_100') n = val;
                else n = (val/5)*100;
                weightedSum += weight * n; totalWeight += weight;
            }
        });
        document.getElementById('live-score').textContent = totalWeight > 0 ? Math.round((weightedSum/totalWeight)*10)/10 : 0;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
