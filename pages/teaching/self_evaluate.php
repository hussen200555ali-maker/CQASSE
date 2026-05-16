<?php
/**
 * ============================================================
 * تقييم ذاتي للتدريس - نموذج التقييم
 * Teaching Self-Evaluation Form (likert_5 and frequency_5 tools)
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'تقييم ذاتي للتدريس';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الذاتي' => 'self_index.php', 'تقييم جديد' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];

// جلب البيانات الأساسية
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب نموذج التقييم الذاتي
$selfForm = null;
$selfItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_self' AND is_active = 1 LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
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

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterVal = (int)($_POST['semester_id'] ?? 0);
            $semesterId = 0;
            if ($semesterVal && $academicYearId) {
                $semName = semesterValueToNameAr($semesterVal);
                if ($semName) {
                    $sStmt = $db->prepare("SELECT id FROM semesters WHERE name_ar = ? AND academic_year_id = ? AND is_active = 1 LIMIT 1");
                    $sStmt->execute([$semName, $academicYearId]);
                    $semesterId = (int)$sStmt->fetchColumn();
                }
            }
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$academicYearId || !$semesterId) {
                throw new Exception('يرجى اختيار السنة والفصل الدراسي');
            }
            
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
            
            foreach ($itemScores as $itemId => $scores) {
                $engine->saveResponse([
                    'form_id' => $formRow['id'],
                    'item_id' => $itemId,
                    'teacher_id' => $userId,
                    'school_id' => $_SESSION['user']['school_id'] ?? null,
                    'academic_year_id' => $academicYearId,
                    'semester_id' => $semesterId,
                    'numeric_value' => $scores['normalized'],
                    'responded_by' => $userId,
                ]);
            }
            
            $db->commit();
            setFlash('تم حفظ التقييم الذاتي بنجاح - الدرجة: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('self_index.php');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <form method="POST" data-validate>
        <?= csrfField() ?>
        
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">🪞 التقييم الذاتي للتدريس</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية <span class="required">*</span></label>
                        <select name="academic_year_id" class="form-control" required>
                            <option value="">-- اختر السنة --</option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>"><?php echo e($ay['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">-- اختر الفصل الدراسي --</option>
                            <option value="1">الفصل الدراسي الأول</option>
                            <option value="2">الفصل الدراسي الثاني</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($selfForm) && !empty($selfItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر التقييم الذاتي</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($selfForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:var(--info); border-bottom:2px solid var(--info); padding-bottom:4px; margin-bottom:12px;">
                        🪞 <?php echo e($cat['name_ar']); ?>
                    </h4>
                    <?php foreach ($cat['items'] as $item): ?>
                        <?php echo $engine->renderTool($item, null, 'item_'); ?>
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
                <a href="../assessment/add_form.php?form_type=teaching_self" class="btn btn-primary mt-3">إنشاء نموذج</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📝 ملاحظات</h3></div>
            <div class="card-body">
                <textarea name="notes" class="form-control" rows="3" placeholder="أضف ملاحظاتك هنا..."></textarea>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="self_index.php" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ التقييم</button>
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
