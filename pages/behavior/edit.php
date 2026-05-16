<?php
/**
 * ============================================================
 * تعديل تقييم سلوكي - Edit Behavior Evaluation
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل التقييم السلوكي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم السلوكي' => 'index.php', 'تعديل التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    setFlash('معرف التقييم غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات التقييم
$stmt = $db->prepare("
    SELECT e.*, s.full_name_ar as student_name, s.student_number,
           c.name_ar as class_name, gl.name_ar as grade_name,
           u.full_name_ar as evaluator_name,
           sem.name_ar as semester_name, ay.name as year_name
    FROM evaluations e
    JOIN students s ON e.student_id = s.id
    JOIN classes c ON e.class_id = c.id
    JOIN grade_levels gl ON c.grade_level_id = gl.id
    JOIN users u ON e.student_id = u.id
    JOIN semesters sem ON e.semester_id = sem.id
    JOIN academic_years ay ON e.academic_year_id = ay.id
    WHERE e.eval_type = 'behavior_score' AND e.id = ? AND e.is_active = 1
");
$stmt->execute([$evalId]);
$evaluation = $stmt->fetch();

if (!$evaluation) {
    setFlash('التقييم غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من الملكية: فقط المقيّم الأصلي أو المدير يمكنه التعديل
if ($evaluation['evaluator_id'] != $userId && !hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية لتعديل هذا التقييم', 'danger');
    redirect('view.php?id=' . $evalId);
}

// التحقق من قفل الفصل الدراسي
if (isSemesterLocked($db, $evaluation['semester_id'])) {
    if (!canEditSemester($db, $evaluation['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل التقييم', 'danger');
        redirect('view.php?id=' . $evalId);
    }
}

// جلب عناصر التقييم الحالية
$existingItems = [];
try {
    $stmt = $db->prepare("
        SELECT bi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value,
               ac.name_ar as category_name
        FROM behavior_items bi
        JOIN assessment_structure ai ON bi.item_id = ai.id
        JOIN assessment_structure ac ON ai.parent_id = ac.id
        WHERE bi.evaluation_id = ? AND bi.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
    $existingItems = $stmt->fetchAll();
} catch (Exception $e) {
    $existingItems = [];
}

// بناء مصفوفة القيم الحالية حسب item_id
$currentValues = [];
foreach ($existingItems as $ei) {
    $currentValues[$ei['item_id']] = $ei['score'];
}

// جلب نموذج التقييم السلوكي
$behaviorForm = null;
$behaviorItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'behavior_eval' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $behaviorForm = $engine->getFormWithItems($formRow['id']);
        if ($behaviorForm) {
            foreach ($behaviorForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $behaviorItems[] = $item;
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
            foreach ($behaviorItems as $item) {
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
                UPDATE evaluations
                SET combined_score = ?, current_percentage = ?, updated_at = NOW()
                WHERE id = ? AND eval_type = 'behavior_score'
            ");
            $stmt->execute([$overallScore, $overallScore, $evalId]);

            // تحديث عناصر التقييم
            $stmtCheck = $db->prepare("SELECT id FROM behavior_items WHERE evaluation_id = ? AND item_id = ? AND is_active = 1");
            $stmtUpdate = $db->prepare("UPDATE behavior_items SET score = ?, notes = ? WHERE evaluation_id = ? AND item_id = ?");
            $stmtInsert = $db->prepare("INSERT INTO behavior_items (evaluation_id, item_id, score, notes, is_active) VALUES (?, ?, ?, ?, 1)");

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
            logActivity($db, $userId, 'edit_behavior_evaluation', 'تعديل تقييم سلوكي - الدرجة: ' . number_format($overallScore, 1) . '% - الطالب: ' . $evaluation['student_name'], 'behavior_evaluation', $evalId);
            setFlash('تم تحديث التقييم السلوكي بنجاح - الدرجة الكلية: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('view.php?id=' . $evalId);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Behavior Edit Error: ' . $e->getMessage());
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل التقييم السلوكي</h3>
            <div class="flex gap-2">
                <a href="view.php?id=<?= $evalId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الطالب</span>
                    <div style="font-weight:600;"><?= e($evaluation['student_name']) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?= e($evaluation['student_number']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الفصل</span>
                    <div style="font-weight:600;"><?= e($evaluation['grade_name'] . ' - ' . $evaluation['class_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span>
                    <div style="font-weight:600;"><?= e($evaluation['semester_name']) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?= e($evaluation['year_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المقيّم</span>
                    <div style="font-weight:600;"><?= e($evaluation['evaluator_name']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" data-validate>
        <?= csrfField() ?>

        <?php if (!empty($behaviorForm) && !empty($behaviorItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر التقييم السلوكي</h3>
                <div id="overall-score-display" style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score"><?= number_format($evaluation['overall_score'] ?? 0, 1) ?></span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($behaviorForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:4px; margin-bottom:12px;">
                        <?= e($cat['name_ar']) ?>
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
                <h4>لا يوجد نموذج تقييم سلوكي</h4>
                <p style="color:var(--text-secondary);">يرجى إنشاء نموذج تقييم سلوكي من صفحة إدارة النماذج أولاً</p>
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
            <a href="view.php?id=<?= $evalId ?>" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('change', function(e) {
    if (e.target.closest('.assessment-item')) {
        calculateLiveScore();
    }
});

function calculateLiveScore() {
    let totalWeight = 0, weightedSum = 0;
    document.querySelectorAll('.assessment-item').forEach(item => {
        const input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
        const toolType = item.dataset.tool;

        if (input) {
            const value = input.type === 'radio' ? parseFloat(input.value) : parseFloat(input.value || 0);
            const weight = parseFloat(input.dataset.weight || 1);
            let normalized = 0;

            switch (toolType) {
                case 'likert_5': case 'frequency_5': case 'satisfaction_5':
                    normalized = (value / 5) * 100; break;
                case 'yes_no':
                    normalized = value * 100; break;
                case 'severity_4':
                    normalized = ((value - 1) / 3) * 100; break;
                case 'rubric_4':
                    normalized = (value / 4) * 100; break;
                case 'numeric_100':
                    normalized = value; break;
                default:
                    normalized = (value / 5) * 100;
            }

            weightedSum += weight * normalized;
            totalWeight += weight;
        }
    });

    const score = totalWeight > 0 ? Math.round((weightedSum / totalWeight) * 10) / 10 : 0;
    document.getElementById('live-score').textContent = score;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
