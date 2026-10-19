<?php
/**
 * تعديل تقييم المهارات
 * ملاحظة: جدول skills_evaluations تم أرشفته في الترحيل v3.3.0
 */
$pageTitle = 'تعديل تقييم المهارات';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'قائمة المهارات' => 'index.php', 'تعديل التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    setFlash('معرف التقييم غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات التقييم
$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT e.*, s.full_name_ar as student_name, s.student_number,
               c.name_ar as class_name, gl.name_ar as grade_name,
               u.full_name_ar as evaluator_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        JOIN classes c ON e.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        JOIN users u ON e.evaluated_by = u.id
        JOIN semesters sem ON e.semester_id = sem.id
        JOIN academic_years ay ON e.academic_year_id = ay.id
        WHERE e.id = ? AND e.eval_type = 'skills_checklist' AND e.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {
    $evaluation = null;
}

if (!$evaluation) {
    setFlash('التقييم غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من الملكية: فقط المقيّم الأصلي أو المدير يمكنه التعديل
if ($evaluation['evaluated_by'] != $userId && !hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية لتعديل هذا التقييم', 'danger');
    redirect('view.php?id=' . $evalId);
}

// التحقق من ملكية المدرسة — لا يمكن تعديل تقييم من مدرسة أخرى
if ($schoolId && $userRole !== 'group_admin' && $evaluation['school_id'] != $schoolId) {
    setFlash('ليس لديك صلاحية لتعديل هذا التقييم', 'danger');
    redirect('view.php?id=' . $evalId);
}

// التحقق من وصول المعلم — الفصل ضمن فصوله المسموحة
if ($userRole === 'teacher') {
    $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!in_array((int)$evaluation['class_id'], $allowedIds)) {
        setFlash('ليس لديك صلاحية لتعديل هذا التقييم', 'danger');
        redirect('view.php?id=' . $evalId);
    }
}

// التحقق من قفل الفصل الدراسي
if (isSemesterLocked($db, $evaluation['semester_id'])) {
    if (!canEditSemester($db, $evaluation['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل التقييم', 'danger');
        redirect('view.php?id=' . $evalId);
    }
}

// تصنيفات المهارات
$skillCategories = [
    'أكاديمي' => ['icon' => '📖', 'color' => '#1a5632', 'label' => 'المهارات الأكاديمية'],
    'اجتماعي' => ['icon' => '🤝', 'color' => '#17a2b8', 'label' => 'المهارات الاجتماعية'],
    'بدني'    => ['icon' => '🏃', 'color' => '#fd7e14', 'label' => 'المهارات البدنية'],
    'إبداعي'  => ['icon' => '🎨', 'color' => '#6f42c1', 'label' => 'المهارات الإبداعية'],
];

// جلب عناصر التقييم الحالية
$existingItems = [];
try {
    $stmt = $db->prepare("
        SELECT ar.numeric_value as score, ar.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value,
               ac.name_ar as category_name
        FROM assessment_responses ar
        JOIN assessment_structure ai ON ar.item_id = ai.id
        JOIN assessment_structure ac ON ai.parent_id = ac.id
        WHERE ar.evaluation_id = ? AND ar.is_active = 1
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
    $currentValues[$ei['item_id']] = $ei['numeric_value'] ?? $ei['score'];
}

// جلب نموذج المهارات
$skillsForm = null;
$skillsItems = [];
$categoryGroups = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'skills_checklist' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $skillsForm = $engine->getFormWithItems($formRow['id']);
        if ($skillsForm) {
            foreach ($skillsForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $skillsItems[] = $item;
                }
                $catName = $cat['name_ar'];
                $matchedGroup = 'أكاديمي';
                foreach ($skillCategories as $key => $sc) {
                    if (mb_strpos($catName, $key) !== false || mb_strpos($key, $catName) !== false) {
                        $matchedGroup = $key;
                        break;
                    }
                }
                if (!isset($categoryGroups[$matchedGroup])) {
                    $categoryGroups[$matchedGroup] = [];
                }
                $categoryGroups[$matchedGroup][] = $cat;
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
            foreach ($skillsItems as $item) {
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
                SET combined_score = ?, current_percentage = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND eval_type = 'skills_checklist'
            ");
            $stmt->execute([$overallScore, $overallScore, $notes ?: null, $evalId]);

            // تحديث عناصر التقييم
            $stmtCheck = $db->prepare("SELECT id FROM assessment_responses WHERE evaluation_id = ? AND item_id = ? AND is_active = 1");
            $stmtUpdate = $db->prepare("UPDATE assessment_responses SET numeric_value = ?, responded_by = ?, response_date = NOW() WHERE evaluation_id = ? AND item_id = ? AND is_active = 1");
            $stmtInsert = $db->prepare("INSERT INTO assessment_responses (form_id, evaluation_id, item_id, student_id, school_id, class_id, academic_year_id, semester_id, numeric_value, responded_by, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

            foreach ($itemScores as $itemId => $scores) {
                $stmtCheck->execute([$evalId, $itemId]);
                $existingRow = $stmtCheck->fetch();

                if ($existingRow) {
                    $stmtUpdate->execute([$scores['normalized'], $userId, $evalId, $itemId]);
                } else {
                    $stmtInsert->execute([$formRow['id'], $evalId, $itemId, $evaluation['student_id'], $evaluation['school_id'], $evaluation['class_id'], $evaluation['academic_year_id'], $evaluation['semester_id'], $scores['normalized'], $userId]);
                }
            }

            $db->commit();
            logActivity($db, $userId, 'edit_skills_evaluation', 'تعديل تقييم مهارات - الدرجة: ' . number_format($overallScore, 1) . '% - الطالب: ' . $evaluation['student_name'], 'evaluation', $evalId);
            setFlash('تم تحديث تقييم المهارات بنجاح - الدرجة الكلية: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('view.php?id=' . $evalId);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Skills Edit Error: ' . $e->getMessage());
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل تقييم المهارات</h3>
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

        <?php if (!empty($skillsForm) && !empty($skillsItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر تقييم المهارات</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score"><?= number_format($evaluation['combined_score'] ?? 0, 1) ?></span>%
                </div>
            </div>
            <div class="card-body">
                <?php
                $grouped = !empty($categoryGroups) ? $categoryGroups : ['أكاديمي' => $skillsForm['categories']];
                foreach ($grouped as $groupName => $categories):
                    $groupInfo = $skillCategories[$groupName] ?? ['icon' => '📋', 'color' => 'var(--primary)', 'label' => $groupName];
                ?>
                <div style="margin-bottom:24px; border:1px solid var(--border-light); border-radius:var(--radius-md); overflow:hidden;">
                    <div style="background:<?= $groupInfo['color'] ?>; color:white; padding:10px 16px; display:flex; align-items:center; gap:8px;">
                        <span style="font-size:20px;"><?= $groupInfo['icon'] ?></span>
                        <span style="font-weight:700; font-size:15px;"><?= e($groupInfo['label']) ?></span>
                    </div>
                    <div style="padding:16px;">
                        <?php foreach ($categories as $cat): ?>
                        <div class="mb-4">
                            <h4 style="color:var(--primary); border-bottom:2px solid var(--success); padding-bottom:4px; margin-bottom:12px;">
                                ✅ <?= e($cat['name_ar']) ?>
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
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج تقييم مهارات</h4>
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
