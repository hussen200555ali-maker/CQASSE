<?php
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
$dateStr = $_GET['date'] ?? null;

if (!$dateStr) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('self_index.php'); }

$formId = 0;
$stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_self' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
$stmt->execute();
$formId = (int)$stmt->fetchColumn();

$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
               AVG(ar.numeric_value) AS overall_score,
               u.full_name_ar AS teacher_name,
               sem.name_ar AS semester_name, ay.name AS year_name
        FROM assessment_responses ar
        JOIN users u ON ar.teacher_id = u.id
        JOIN semesters sem ON ar.semester_id = sem.id
        JOIN academic_years ay ON ar.academic_year_id = ay.id
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
        GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
    ");
    $stmt->execute([$formId, $userId, $userId, $dateStr]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('self_index.php'); }

if (isSemesterLocked($db, $evaluation['semester_id'])) {
    if (!canEditSemester($db, $evaluation['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل التقييم', 'danger');
        redirect('self_index.php');
    }
}

// جلب القيم الحالية
$currentValues = [];
try {
    $stmt = $db->prepare("
        SELECT ar.item_id, ar.numeric_value AS score
        FROM assessment_responses ar
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
    ");
    $stmt->execute([$formId, $userId, $userId, $dateStr]);
    while ($row = $stmt->fetch()) {
        $currentValues[(int)$row['item_id']] = $row['score'];
    }
} catch (Exception $e) {}

$selfForm = null;
$selfItems = [];
try {
    if ($formId) {
        $selfForm = $engine->getFormWithItems($formId);
        if ($selfForm) {
            foreach ($selfForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $selfItems[] = $item;
                }
            }
        }
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();

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
                        $itemScores[$item['id']] = $normalized;
                    }
                }
            }

            $overallScore = $engine->calculateScore($responses);

            // Update: delete old responses for this group, insert new ones
            $stmt = $db->prepare("
                UPDATE assessment_responses SET is_active = 0
                WHERE form_id = ? AND teacher_id = ? AND responded_by = ? AND response_date = ?
            ");
            $stmt->execute([$formId, $userId, $userId, $dateStr]);

            foreach ($itemScores as $itemId => $normalized) {
                $engine->saveResponse([
                    'form_id' => $formId,
                    'item_id' => $itemId,
                    'teacher_id' => $userId,
                    'school_id' => $_SESSION['user']['school_id'] ?? null,
                    'academic_year_id' => $evaluation['academic_year_id'],
                    'semester_id' => $evaluation['semester_id'],
                    'numeric_value' => $normalized,
                    'responded_by' => $userId,
                ]);
            }

            $db->commit();
            logActivity($db, $userId, 'edit_teaching_self', 'تعديل تقييم ذاتي - الدرجة: ' . number_format($overallScore, 1) . '%', 'assessment_responses', 0);
            setFlash('تم تحديث التقييم الذاتي بنجاح - الدرجة: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('self_index.php');
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
                <a href="self_view.php?date=<?= urlencode($dateStr) ?>" class="btn btn-info btn-sm">👁️ عرض</a>
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

        <div class="flex justify-end gap-3">
            <a href="self_view.php?date=<?= urlencode($dateStr) ?>" class="btn btn-light">إلغاء</a>
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
