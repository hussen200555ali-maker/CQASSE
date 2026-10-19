<?php
/**
 * ============================================================
 * استبيان المناخ المدرسي - تعبئة المعلم
 * Teacher Climate Survey
 * نظام التقييم الشامل لجودة التعليم المدرسي
 * ============================================================
 */

$pageTitle = 'استبيان المناخ المدرسي';
$breadcrumb = ['استبيان المناخ' => 'index.php', 'تعبئة المعلم' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/assessment_engine.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../dashboard/teacher.php');
}

$engine = new AssessmentEngine($db);
$userId = $_SESSION['user']['id'];
$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();
$semesterId = currentSemesterId();

// التحقق من وجود مدرسة
if (!$schoolId) {
    setFlash('لا توجد مدرسة مرتبطة بحسابك', 'danger');
    redirect('../dashboard/teacher.php');
}

// التحقق من وجود عام وفصل دراسي
if (!$academicYearId || !$semesterId) {
    setFlash('لم يتم تحديد العام الدراسي أو الفصل الحالي', 'danger');
    redirect('../dashboard/teacher.php');
}

// جلب نموذج المناخ
$climateForm = null;
$climateItems = [];
$categoryGroups = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'climate_survey' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $climateForm = $engine->getFormWithItems($formRow['id']);
        if ($climateForm) {
            foreach ($climateForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) { $climateItems[] = $item; }
                $categoryGroups[] = $cat;
            }
        }
    }
} catch (Exception $e) {}

if (empty($climateItems)) {
    setFlash('لا يوجد نموذج استبيان مناخ جاهز حالياً', 'warning');
    redirect('index.php');
}

// التحقق من التعبئة السابقة
$existingSurvey = null;
$existingItems = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM surveys 
        WHERE survey_type = 'climate' AND school_id = ? AND semester_id = ? 
          AND respondent_type = 'teacher' AND respondent_id = ? AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$schoolId, $semesterId, $userId]);
    $existingSurvey = $stmt->fetch();

    if ($existingSurvey) {
        $stmt = $db->prepare("SELECT item_id, normalized_value FROM climate_items WHERE survey_id = ? AND is_active = 1");
        $stmt->execute([$existingSurvey['id']]);
        while ($row = $stmt->fetch()) { $existingItems[$row['item_id']] = $row['normalized_value']; }
    }
} catch (Exception $e) {}

// معالجة الحفظ
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingSurvey) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();

            // جمع الاستجابات
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

            if (empty($responses)) {
                throw new Exception('لم يتم الإجابة على أي عنصر');
            }

            $overallScore = $engine->calculateScore($responses);

            // حفظ الاستبيان
            $stmt = $db->prepare("
                INSERT INTO surveys 
                (survey_type, school_id, academic_year_id, semester_id, respondent_type, respondent_id, survey_date, overall_score, status, is_active, created_by)
                VALUES ('climate', ?, ?, ?, 'teacher', ?, CURDATE(), ?, 'closed', 1, ?)
            ");
            $stmt->execute([$schoolId, $academicYearId, $semesterId, $userId, $overallScore, $userId]);
            $surveyId = $db->lastInsertId();

            // حفظ العناصر
            foreach ($itemScores as $itemId => $scores) {
                $stmt = $db->prepare("INSERT INTO climate_items (survey_id, item_id, normalized_value, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$surveyId, $itemId, $scores['normalized']]);
            }

            $db->commit();
            logActivity($db, $userId, 'climate_survey_teacher', 'تعبئة استبيان مناخ (معلم) - الدرجة: ' . number_format($overallScore, 1) . '%', 'climate_survey', $surveyId);
            setFlash('تم حفظ استبيان المناخ بنجاح', 'success');
            $saved = true;

            // إعادة تحميل البيانات
            $stmt = $db->prepare("SELECT * FROM surveys WHERE id = ?");
            $stmt->execute([$surveyId]);
            $existingSurvey = $stmt->fetch();
            $stmt = $db->prepare("SELECT item_id, normalized_value FROM climate_items WHERE survey_id = ? AND is_active = 1");
            $stmt->execute([$surveyId]);
            while ($row = $stmt->fetch()) { $existingItems[$row['item_id']] = $row['normalized_value']; }

        } catch (Exception $e) {
            $db->rollBack();
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<div class="section">
    <?php if ($existingSurvey && !$saved): ?>
    <!-- تم التعبئة مسبقاً -->
    <div class="card mb-4" style="border-color:var(--success);">
        <div class="card-body text-center" style="padding:40px;">
            <div style="font-size:64px; margin-bottom:16px;">✅</div>
            <h3>لقد قمت بتعبئة هذا الاستبيان مسبقاً</h3>
            <p class="text-muted">تاريخ التعبئة: <?php echo e($existingSurvey['survey_date']); ?></p>
            <p class="text-muted">الدرجة الكلية: <strong><?php echo number_format($existingSurvey['overall_score'], 1); ?>%</strong></p>
            <a href="index.php" class="btn btn-primary mt-3">العودة لقائمة الاستبيانات</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$existingSurvey || $saved): ?>
    <form method="POST" data-validate>
        <?= csrfField() ?>

        <!-- معلومات الاستبيان -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">🌡️ استبيان المناخ المدرسي</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="form-label">المدرسة</label>
                        <div class="form-control-plaintext" style="padding:8px 0; font-weight:600;">
                            <?php
                            $stmt = $db->prepare("SELECT name_ar FROM schools WHERE id = ?");
                            $stmt->execute([$schoolId]);
                            echo e($stmt->fetchColumn() ?: '—');
                            ?>
                        </div>
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">نوع المستجيب</label>
                        <div class="form-control-plaintext" style="padding:8px 0;">👨‍🏫 معلم</div>
                    </div>
                    <div class="form-group col-md-3">
                        <label class="form-label">اسم المعلم</label>
                        <div class="form-control-plaintext" style="padding:8px 0;"><?php echo e($_SESSION['user']['full_name'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($climateForm) && !empty($climateItems)): ?>
        <!-- عناصر الاستبيان -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر الاستبيان</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($climateForm['categories'] as $cat): ?>
                <div class="mb-4" style="border:1px solid var(--border-light); border-radius:var(--radius-md); overflow:hidden;">
                    <div style="background:#fd7e14; color:white; padding:8px 16px; display:flex; align-items:center;">
                        <span style="font-weight:700;">🌡️ <?php echo e($cat['name_ar']); ?></span>
                        <span style="margin-right:auto; font-size:13px;" id="cat-score-<?php echo $cat['id']; ?>">—</span>
                    </div>
                    <div style="padding:16px;">
                        <?php foreach ($cat['items'] as $item): ?>
                            <?php echo $engine->renderTool($item, null, 'item_'); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- تقدم الاستبيان -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📊 تقدم الاستبيان</h3></div>
            <div class="card-body">
                <div class="flex items-center gap-3 mb-2">
                    <div style="flex:1; background:var(--border-light); border-radius:var(--radius-md); height:12px; overflow:hidden;">
                        <div id="progress-bar" style="width:0%; height:100%; background:#fd7e14; border-radius:var(--radius-md); transition:width 0.3s;"></div>
                    </div>
                    <span id="progress-text" style="font-size:14px; font-weight:600; min-width:60px; text-align:center;">0%</span>
                </div>
                <div style="font-size:12px; color:var(--text-muted);">
                    تم الإجابة على <span id="answered-count">0</span> من <span id="total-count"><?php echo count($climateItems); ?></span> عنصر
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex justify-end gap-3">
            <a href="index.php" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ الاستبيان</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function updateProgress() {
    var total = document.querySelectorAll('.assessment-item').length;
    var answered = 0;
    document.querySelectorAll('.assessment-item').forEach(function(item) {
        var input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
        if (input && input.value !== '') answered++;
    });
    var pct = total > 0 ? Math.round((answered / total) * 100) : 0;
    var bar = document.getElementById('progress-bar');
    var text = document.getElementById('progress-text');
    var countEl = document.getElementById('answered-count');
    if (bar) bar.style.width = pct + '%';
    if (text) text.textContent = pct + '%';
    if (countEl) countEl.textContent = answered;
}

document.addEventListener('change', function(e) {
    if (e.target.closest('.assessment-item')) {
        let totalWeight = 0, weightedSum = 0;
        document.querySelectorAll('.assessment-item').forEach(function(item) {
            var input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
            if (input) {
                var val = parseFloat(input.value || 0);
                var weight = parseFloat(input.dataset.weight || 1);
                var tool = item.dataset.tool;
                var n = 0;
                if (['likert_5','frequency_5','satisfaction_5'].includes(tool)) n = (val/5)*100;
                else if (tool === 'yes_no') n = val*100;
                else if (tool === 'severity_4') n = ((val-1)/3)*100;
                else if (tool === 'rubric_4') n = (val/4)*100;
                else if (tool === 'numeric_100') n = val;
                else n = (val/5)*100;
                weightedSum += weight * n; totalWeight += weight;
            }
        });
        var score = totalWeight > 0 ? Math.round((weightedSum/totalWeight)*10)/10 : 0;
        document.getElementById('live-score').textContent = score;
        updateProgress();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
