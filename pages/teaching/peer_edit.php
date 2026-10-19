<?php
$pageTitle = 'تعديل تقييم القرين';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'تقييم الأقران' => 'peer_index.php', 'تعديل التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('peer_index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$dateStr = $_GET['date'] ?? null;
$teacherId = (int)($_GET['teacher_id'] ?? 0);

if (!$dateStr || !$teacherId) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('peer_index.php'); }

$formId = 0;
$stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_peer' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
$stmt->execute();
$formId = (int)$stmt->fetchColumn();

$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
               AVG(ar.numeric_value) AS overall_score,
               u.full_name_ar AS teacher_name,
               ev.full_name_ar AS evaluator_name,
               sem.name_ar AS semester_name, ay.name AS year_name
        FROM assessment_responses ar
        JOIN users u ON ar.teacher_id = u.id
        JOIN users ev ON ar.responded_by = ev.id
        JOIN semesters sem ON ar.semester_id = sem.id
        JOIN academic_years ay ON ar.academic_year_id = ay.id
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
        GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
    ");
    $stmt->execute([$formId, $teacherId, $userId, $dateStr]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('peer_index.php'); }

// التحقق من أن المعلم المقيّم ينتمي لنفس المدرسة
$schoolId = $_SESSION['user']['school_id'] ?? null;
if ($schoolId) {
    $checkTeacher = $db->prepare("SELECT 1 FROM users WHERE id = ? AND school_id = ? AND is_active = 1");
    $checkTeacher->execute([$teacherId, $schoolId]);
    if (!$checkTeacher->fetch()) {
        setFlash('المعلم المحدد غير موجود في مدرستك', 'danger');
        redirect('peer_index.php');
    }
}

if (isSemesterLocked($db, $evaluation['semester_id'])) {
    if (!canEditSemester($db, $evaluation['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل التقييم', 'danger');
        redirect('peer_index.php');
    }
}

$currentValues = [];
try {
    $stmt = $db->prepare("
        SELECT ar.item_id, ar.numeric_value AS score
        FROM assessment_responses ar
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
    ");
    $stmt->execute([$formId, $teacherId, $userId, $dateStr]);
    while ($row = $stmt->fetch()) {
        $currentValues[(int)$row['item_id']] = $row['score'];
    }
} catch (Exception $e) {}

$peerForm = null;
$peerItems = [];
try {
    if ($formId) {
        $peerForm = $engine->getFormWithItems($formId);
        if ($peerForm) {
            foreach ($peerForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $peerItems[] = $item;
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
            foreach ($peerItems as $item) {
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

            $stmt = $db->prepare("
                UPDATE assessment_responses SET is_active = 0
                WHERE form_id = ? AND teacher_id = ? AND responded_by = ? AND response_date = ?
            ");
            $stmt->execute([$formId, $teacherId, $userId, $dateStr]);

            foreach ($itemScores as $itemId => $normalized) {
                $engine->saveResponse([
                    'form_id' => $formId,
                    'item_id' => $itemId,
                    'teacher_id' => $teacherId,
                    'school_id' => $_SESSION['user']['school_id'] ?? null,
                    'academic_year_id' => $evaluation['academic_year_id'],
                    'semester_id' => $evaluation['semester_id'],
                    'numeric_value' => $normalized,
                    'responded_by' => $userId,
                ]);
            }

            $db->commit();
            logActivity($db, $userId, 'edit_teaching_peer', 'تعديل تقييم قرين - الدرجة: ' . number_format($overallScore, 1) . '% - المعلم: ' . $evaluation['teacher_name'], 'assessment_responses', 0);
            setFlash('تم تحديث تقييم القرين بنجاح - الدرجة: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('peer_index.php');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Teaching Peer Edit Error: ' . $e->getMessage());
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل تقييم القرين</h3>
            <div class="flex gap-2">
                <a href="peer_view.php?date=<?= urlencode($dateStr) ?>&teacher_id=<?= $teacherId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
                <a href="peer_index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المعلم المقيّم</span>
                    <div style="font-weight:600;"><?= e($evaluation['teacher_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المقيّم</span>
                    <div style="font-weight:600;"><?= e($evaluation['evaluator_name']) ?></div>
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

        <?php if (!empty($peerForm) && !empty($peerItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر تقييم القرين</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score"><?= number_format($evaluation['overall_score'] ?? 0, 1) ?></span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($peerForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:#6f42c1; border-bottom:2px solid #6f42c1; padding-bottom:4px; margin-bottom:12px;">
                        👥 <?= e($cat['name_ar']) ?>
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
                <h4>لا يوجد نموذج تقييم أقران</h4>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex justify-end gap-3">
            <a href="peer_view.php?date=<?= urlencode($dateStr) ?>&teacher_id=<?= $teacherId ?>" class="btn btn-light">إلغاء</a>
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
