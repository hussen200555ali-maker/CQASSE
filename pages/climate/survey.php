<?php
/**
 * ============================================================
 * استبيان المناخ المدرسي - نموذج
 * Climate Survey Form (satisfaction_5 and frequency_5 tools)
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'استبيان المناخ المدرسي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'استبيان المناخ' => 'index.php', 'استبيان جديد' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$schools = [];
try {
    $sql = "SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar";
    $p = [];
    if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND id = ?"; $p[] = $schoolId; }
    $stmt = $db->prepare($sql); $stmt->execute($p); $schools = $stmt->fetchAll();
} catch (Exception $e) {}

$academicYears = [];
$semesters = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
    $stmt = $db->query("SELECT id, name_ar, academic_year_id FROM semesters WHERE is_active = 1 ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {}

$respondentTypes = ['teacher' => 'معلم', 'student' => 'طالب', 'parent' => 'ولي أمر', 'admin' => 'إداري'];
$respondentIcons = ['teacher' => '👨‍🏫', 'student' => '👨‍🎓', 'parent' => '👨‍👧', 'admin' => '👨‍💼'];

// معلومات إضافية عن المستجيب
$respondentInfo = [
    'age_group' => '',
    'years_experience' => '',
    'grade_level' => '',
];

// جلب نموذج المناخ
$climateForm = null;
$climateItems = [];
$categoryGroups = []; // تجميع الأبعاد
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'climate_survey' AND is_active = 1 LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
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

// جلب استبيانات سابقة للمقارنة
$previousSurveys = [];
try {
    $stmt = $db->prepare("
        SELECT cs.id, cs.overall_score, cs.survey_date, cs.respondent_type, sem.name_ar as semester_name
        FROM climate_surveys cs
        JOIN semesters sem ON cs.semester_id = sem.id
        WHERE cs.school_id = ? AND cs.is_active = 1
        ORDER BY cs.survey_date DESC LIMIT 10
    ");
    $stmt->execute([$schoolId ?: 0]);
    $previousSurveys = $stmt->fetchAll();
} catch (Exception $e) {}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $schoolIdPost = (int)($_POST['school_id'] ?? 0);
            $respondentType = $_POST['respondent_type'] ?? '';
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            // معلومات إضافية
            $ageGroup = trim($_POST['age_group'] ?? '');
            $yearsExperience = trim($_POST['years_experience'] ?? '');
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            
            if (!$schoolIdPost || !$respondentType || !$academicYearId || !$semesterId) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
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
            
            $stmt = $db->prepare("
                INSERT INTO climate_surveys 
                (school_id, academic_year_id, semester_id, respondent_type, respondent_id, survey_date, overall_score, notes, is_active)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 1)
            ");
            $stmt->execute([$schoolIdPost, $academicYearId, $semesterId, $respondentType, $userId, $overallScore, $notes ?: null]);
            $surveyId = $db->lastInsertId();
            
            $stmt = $db->prepare("INSERT INTO climate_items (survey_id, item_id, score, notes, is_active) VALUES (?, ?, ?, ?, 1)");
            foreach ($itemScores as $itemId => $scores) {
                $stmt->execute([$surveyId, $itemId, $scores['normalized'], null]);
            }
            
            $db->commit();
            logActivity($db, $userId, 'climate_survey_create', 'استبيان مناخ جديد - الدرجة: ' . number_format($overallScore, 1) . '% - النوع: ' . $respondentTypes[$respondentType], 'climate_survey', $surveyId);
            setFlash('تم حفظ الاستبيان بنجاح', 'success');
            redirect('view.php?id=' . $surveyId);
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
        
        <!-- معلومات الاستبيان الأساسية -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">🌡️ استبيان المناخ المدرسي</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المدرسة <span class="required">*</span></label>
                        <select name="school_id" class="form-control" required>
                            <option value="">-- اختر المدرسة --</option>
                            <?php foreach ($schools as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo e($s['name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نوع المستجيب <span class="required">*</span></label>
                        <select name="respondent_type" class="form-control" required id="respondent-type-select">
                            <option value="">-- اختر النوع --</option>
                            <?php foreach ($respondentTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $respondentIcons[$key] . ' ' . e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
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
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?php echo $sem['id']; ?>"><?php echo e($sem['name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- معلومات إضافية عن المستجيب -->
        <div class="card mb-4" id="respondent-info-card">
            <div class="card-header"><h3 class="card-title">👤 معلومات المستجيب</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">الفئة العمرية</label>
                        <select name="age_group" class="form-control">
                            <option value="">-- اختر --</option>
                            <option value="under_25">أقل من 25 سنة</option>
                            <option value="25_35">25-35 سنة</option>
                            <option value="36_45">36-45 سنة</option>
                            <option value="over_45">أكثر من 45 سنة</option>
                        </select>
                    </div>
                    <div class="form-group" id="experience-group">
                        <label class="form-label">سنوات الخبرة</label>
                        <select name="years_experience" class="form-control">
                            <option value="">-- اختر --</option>
                            <option value="less_1">أقل من سنة</option>
                            <option value="1_3">1-3 سنوات</option>
                            <option value="4_10">4-10 سنوات</option>
                            <option value="over_10">أكثر من 10 سنوات</option>
                        </select>
                    </div>
                    <div class="form-group" id="grade-group" style="display:none;">
                        <label class="form-label">المرحلة الدراسية</label>
                        <select name="grade_level" class="form-control">
                            <option value="">-- اختر --</option>
                            <option value="primary">المرحلة الابتدائية</option>
                            <option value="preparatory">المرحلة الإعدادية</option>
                            <option value="secondary">المرحلة الثانوية</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- تقدم الاستبيان -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📊 تقدم الاستبيان</h3>
            </div>
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

        <?php if (!empty($climateForm) && !empty($climateItems)): ?>
        <!-- عناصر الاستبيان مع مؤشر البعد -->
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
                    <div style="background:#fd7e14; color:white; padding:8px 16px; display:flex; justify-content:between; align-items:center;">
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

        <!-- معاينة الرسم البياني للأبعاد -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📊 معاينة توزيع الأبعاد</h3></div>
            <div class="card-body">
                <div style="max-width:500px; height:350px; margin:0 auto;">
                    <canvas id="dimensionPreviewChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ملخص التحقق من الاستجابة -->
        <div class="card mb-4" id="validation-summary-card" style="display:none;">
            <div class="card-header"><h3 class="card-title">✅ ملخص الاستجابة</h3></div>
            <div class="card-body">
                <div class="grid grid-4">
                    <div style="text-align:center; padding:8px;">
                        <div style="font-size:24px; font-weight:700; color:#28a745;" id="val-answered">0</div>
                        <div style="font-size:12px; color:var(--text-secondary);">تم الإجابة</div>
                    </div>
                    <div style="text-align:center; padding:8px;">
                        <div style="font-size:24px; font-weight:700; color:#dc3545;" id="val-unanswered">0</div>
                        <div style="font-size:12px; color:var(--text-secondary);">لم يتم الإجابة</div>
                    </div>
                    <div style="text-align:center; padding:8px;">
                        <div style="font-size:24px; font-weight:700; color:#17a2b8;" id="val-completion">0%</div>
                        <div style="font-size:12px; color:var(--text-secondary);">نسبة الاكتمال</div>
                    </div>
                    <div style="text-align:center; padding:8px;">
                        <div style="font-size:24px; font-weight:700; color:#fd7e14;" id="val-score">0%</div>
                        <div style="font-size:12px; color:var(--text-secondary);">الدرجة الحالية</div>
                    </div>
                </div>
                <div id="validation-warnings" style="margin-top:12px;"></div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج استبيان مناخ</h4>
                <a href="../assessment/add_form.php?form_type=climate_survey" class="btn btn-primary mt-3">إنشاء نموذج</a>
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
            <a href="index.php" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ الاستبيان</button>
        </div>
    </form>
</div>

<script>
// إظهار/إخفاء حقول حسب نوع المستجيب
document.getElementById('respondent-type-select').addEventListener('change', function() {
    var type = this.value;
    var expGroup = document.getElementById('experience-group');
    var gradeGroup = document.getElementById('grade-group');
    
    if (type === 'teacher' || type === 'admin') {
        if (expGroup) expGroup.style.display = '';
        if (gradeGroup) gradeGroup.style.display = 'none';
    } else if (type === 'student') {
        if (expGroup) expGroup.style.display = 'none';
        if (gradeGroup) gradeGroup.style.display = '';
    } else if (type === 'parent') {
        if (expGroup) expGroup.style.display = 'none';
        if (gradeGroup) gradeGroup.style.display = '';
    } else {
        if (expGroup) expGroup.style.display = '';
        if (gradeGroup) gradeGroup.style.display = 'none';
    }
});

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
        var score = totalWeight > 0 ? Math.round((weightedSum/totalWeight)*10)/10 : 0;
        document.getElementById('live-score').textContent = score;
        updateProgress();
        
        // تحديث ملخص التحقق
        var valCard = document.getElementById('validation-summary-card');
        if (valCard) {
            valCard.style.display = 'block';
            var total = document.querySelectorAll('.assessment-item').length;
            var answered = 0;
            document.querySelectorAll('.assessment-item').forEach(function(item) {
                var inp = item.querySelector('input[type="radio"]:checked, input[type="number"]');
                if (inp && inp.value !== '') answered++;
            });
            var unanswered = total - answered;
            var completion = total > 0 ? Math.round((answered / total) * 100) : 0;
            document.getElementById('val-answered').textContent = answered;
            document.getElementById('val-unanswered').textContent = unanswered;
            document.getElementById('val-completion').textContent = completion + '%';
            document.getElementById('val-score').textContent = score + '%';
            
            // تحذيرات
            var warnings = [];
            if (unanswered > 0) warnings.push('⚠️ لا يزال ' + unanswered + ' عنصر بحاجة للإجابة');
            if (completion < 50) warnings.push('⚠️ نسبة الاكتمال أقل من 50%');
            if (score > 0 && score < 30) warnings.push('⚠️ الدرجة منخفضة جداً - يرجى مراجعة الإجابات');
            
            var warnEl = document.getElementById('validation-warnings');
            if (warnEl) {
                if (warnings.length > 0) {
                    warnEl.innerHTML = warnings.map(function(w) {
                        return '<div style="padding:4px 8px; margin-bottom:4px; background:#ffc10720; border-radius:var(--radius-md); font-size:12px; color:#856404;">' + w + '</div>';
                    }).join('');
                } else if (completion === 100) {
                    warnEl.innerHTML = '<div style="padding:8px; background:#28a74520; border-radius:var(--radius-md); font-size:13px; color:#155724; font-weight:600; text-align:center;">✅ اكتملت جميع الإجابات</div>';
                } else {
                    warnEl.innerHTML = '';
                }
            }
        }
    }
});
</script>

<?php
if (!empty($climateForm)):
    $previewLabels = [];
    foreach ($climateForm['categories'] as $cat) {
        $previewLabels[] = $cat['name_ar'];
    }
    $pageJs = "
        initChart('dimensionPreviewChart', 'radar', {
            labels: " . json_encode($previewLabels, JSON_UNESCAPED_UNICODE) . ",
            datasets: [{
                label: 'درجة البعد',
                data: new Array(" . count($previewLabels) . ").fill(0),
                backgroundColor: 'rgba(253, 126, 20, 0.2)',
                borderColor: 'rgba(253, 126, 20, 1)',
                pointBackgroundColor: 'rgba(253, 126, 20, 1)',
                borderWidth: 2
            }]
        }, {
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { stepSize: 20 }
                }
            }
        });
    ";
endif;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
