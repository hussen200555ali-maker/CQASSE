<?php
/**
 * ============================================================
 * تقييم إشرافي جديد - Admin Evaluation Form
 * All tool types available, higher weight than self/peer
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'تقييم إشرافي جديد';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الإشرافي' => 'admin_index.php', 'تقييم جديد' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// جلب المعلمين
$teachers = [];
try {
    $sql = "SELECT u.id, u.full_name_ar FROM users u JOIN roles r ON u.role_id = r.id WHERE u.is_active = 1 AND r.name = 'teacher'";
    $params = [];
    if ($schoolId) { $sql .= " AND u.school_id = ?"; $params[] = $schoolId; }
    $sql .= " ORDER BY u.full_name_ar";
    $stmt = $db->prepare($sql); $stmt->execute($params); $teachers = $stmt->fetchAll();
} catch (Exception $e) {}

$academicYears = [];
$semesters = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
    $stmt = $db->query("SELECT id, name_ar, academic_year_id FROM semesters WHERE is_active = 1 ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب نموذج التقييم الإشرافي
$adminForm = null;
$adminItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_admin' AND is_active = 1 LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $adminForm = $engine->getFormWithItems($formRow['id']);
        if ($adminForm) {
            foreach ($adminForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) { $adminItems[] = $item; }
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
            
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$teacherId || !$academicYearId || !$semesterId) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
            $responses = [];
            $itemScores = [];
            foreach ($adminItems as $item) {
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
            
            $stmt = $db->prepare("
                INSERT INTO teaching_admin_evaluations 
                (teacher_id, evaluator_id, academic_year_id, semester_id, evaluation_date, overall_score, notes, is_active)
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, 1)
            ");
            $stmt->execute([$teacherId, $userId, $academicYearId, $semesterId, $overallScore, $notes ?: null]);
            $evaluationId = $db->lastInsertId();
            
            $stmt = $db->prepare("INSERT INTO teaching_admin_items (evaluation_id, item_id, score, notes, is_active) VALUES (?, ?, ?, ?, 1)");
            foreach ($itemScores as $itemId => $scores) {
                $stmt->execute([$evaluationId, $itemId, $scores['normalized'], null]);
            }
            
            $db->commit();
            setFlash('تم حفظ التقييم الإشرافي بنجاح', 'success');
            redirect('admin_view.php?id=' . $evaluationId);
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
            <div class="card-header"><h3 class="card-title">👔 التقييم الإشرافي للتدريس</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المعلم <span class="required">*</span></label>
                        <select name="teacher_id" class="form-control" required>
                            <option value="">-- اختر المعلم --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo e($t['full_name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?php echo $sem['id']; ?>"><?php echo e($sem['name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($adminForm) && !empty($adminItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر التقييم الإشرافي</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($adminForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:4px; margin-bottom:12px;">
                        👔 <?php echo e($cat['name_ar']); ?>
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
                <h4>لا يوجد نموذج تقييم إشرافي</h4>
                <a href="../assessment/add_form.php?form_type=teaching_admin" class="btn btn-primary mt-3">إنشاء نموذج</a>
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
            <a href="admin_index.php" class="btn btn-light">إلغاء</a>
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
