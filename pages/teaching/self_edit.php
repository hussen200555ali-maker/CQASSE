<?php
/**
 * ============================================================
 * تعديل التقييم الذاتي للتدريس - Edit Teaching Self-Evaluation
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل التقييم الذاتي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الذاتي' => 'self_index.php', 'تعديل التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('self_index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    setFlash('معرف التقييم غير صالح', 'danger');
    redirect('self_index.php');
}

// جلب بيانات التقييم الذاتي
$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT tse.*, u.full_name_ar as teacher_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_self_evaluations tse
        JOIN users u ON tse.teacher_id = u.id
        JOIN semesters sem ON tse.semester_id = sem.id
        JOIN academic_years ay ON tse.academic_year_id = ay.id
        WHERE tse.id = ? AND tse.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {
    $evaluation = null;
}

if (!$evaluation) {
    setFlash('التقييم غير موجود', 'danger');
    redirect('self_index.php');
}

// التحقق من الملكية: فقط المعلم نفسه أو المدير يمكنه التعديل
if ($evaluation['teacher_id'] != $userId && !hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية لتعديل هذا التقييم', 'danger');
    redirect('self_view.php?id=' . $evalId);
}

// التحقق من قفل الفصل الدراسي
if (isSemesterLocked($db, $evaluation['semester_id'])) {
    if (!canEditSemester($db, $evaluation['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل التقييم', 'danger');
        redirect('self_view.php?id=' . $evalId);
    }
}

// جلب عناصر التقييم الحالية
$existingItems = [];
try {
    $stmt = $db->prepare("
        SELECT tsi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value,
               ac.name_ar as category_name
        FROM teaching_self_items tsi
        JOIN assessment_items ai ON tsi.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        WHERE tsi.evaluation_id = ? AND tsi.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
    $existingItems = $stmt->fetchAll();
} catch (Exception $e) {
    $existingItems = [];
}

// بناء مصفوفة القيم الحالية
$currentValues = [];
foreach ($existingItems as $ei) {
    $currentValues[$ei['item_id']] = $ei['score'];
}

// جلب نموذج التقييم الذاتي
$selfForm = null;
$selfItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_self' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $selfForm = $engine->getFormWithItems($formRow['id']);
        if ($selfForm) {
            foreach ($selfForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $selfItems[] = $item;
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
            foreach ($selfItems as $item) {
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

            // تحديث التقييم الرئيسي
            $stmt = $db->prepare("
                UPDATE teaching_self_evaluations
                SET overall_score = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$overallScore, $notes ?: null, $evalId]);

            // تحديث عناصر التقييم
            $stmtCheck = $db->prepare("SELECT id FROM teaching_self_items WHERE evaluation_id = ? AND item_id = ? AND is_active = 1");
            $stmtUpdate = $db->prepare("UPDATE teaching_self_items SET score = ?, notes = ? WHERE evaluation_id = ? AND item_id = ?");
            $stmtInsert = $db->prepare("INSERT INTO teaching_self_items (evaluation_id, item_id, score, notes, is_active) VALUES (?, ?, ?, ?, 1)");

            foreach ($itemScores as $itemId => $scores) {
                $stmtCheck->execute([$evalId, $itemId]);
                $existingRow = $stmtCheck->fetch();

                if ($existingRow) {
                    $stmtUpdate->execute([$scores['normalized'], null, $evalId, $itemId]);
                } else {
                    $stmtInsert->execute([$evalId, $itemId, $scores['normalized'], null]);
                }
            }

            $db->commit();
            logActivity($db, $userId, 'edit_teaching_self', 'تعديل تقييم ذاتي - الدرجة: ' . number_format($overallScore, 1) . '%', 'teaching_self_evaluation', $evalId);
            setFlash('تم تحديث التقييم الذاتي بنجاح - الدرجة: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('self_view.php?id=' . $evalId);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Teaching Self Edit Error: ' . $e->getMessage());
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل التقييم الذاتي للتدريس</h3>
            <div class="flex gap-2">
                <a href="self_view.php?id=<?= $evalId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
                <a href="self_index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-3">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المعلم</span>
                    <div style="font-weight:600;"><?= e($evaluation['teacher_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span>
                    <div style="font-weight:600;"><?= e($evaluation['semester_name']) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?= e($evaluation['year_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الدرجة الحالية</span>
                    <div style="font-weight:700; font-size:20px; color:var(--primary);">
                        <?= number_format($evaluation['overall_score'] ?? 0, 1) ?>%
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" data-validate>
        <?= csrfField() ?>

        <?php if (!empty($selfForm) && !empty($selfItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر التقييم الذاتي</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score"><?= number_format($evaluation['overall_score'] ?? 0, 1) ?></span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($selfForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:var(--info); border-bottom:2px solid var(--info); padding-bottom:4px; margin-bottom:12px;">
                        🪞 <?= e($cat['name_ar']) ?>
                    </h4>
                    <?php foreach ($cat['items'] as $item):
                        $currentVal = $currentValues[$item['id']] ?? null;
                    ?>
                        <?= $engine->renderTool($item, $currentVal, 'item_') ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج تقييم ذاتي</h4>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📝 ملاحظات</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="notes" class="form-control" rows="3" placeholder="أضف ملاحظاتك هنا..."><?= e($evaluation['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="self_view.php?id=<?= $evalId ?>" class="btn btn-light">إلغاء</a>
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
