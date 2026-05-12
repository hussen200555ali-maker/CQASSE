<?php
/**
 * ============================================================
 * قائمة فحص المرافق المدرسية - نموذج
 * Facility Checklist Form (yes_no, counter, severity_4 tools)
 * الفئات: الفصول، المعامل، المكتبة، الملعب، دورات المياه، السلامة
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'فحص المرافق المدرسية';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'فحص المرافق' => 'index.php', 'فحص جديد' => null];

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

$categoryIcons = [
    'الفصول' => '🏫', 'المعامل' => '🔬', 'المكتبة' => '📚',
    'الملعب' => '⚽', 'دورات المياه' => '🚻', 'السلامة' => '🛡️',
    'السور' => '🧱', 'البوابة' => '🚪', 'الممرات' => '🚶'
];

// أولويات الإصلاح
$repairPriorities = [
    'critical' => ['label' => 'عاجل', 'color' => '#dc3545', 'icon' => '🔴'],
    'high'     => ['label' => 'مهم',  'color' => '#fd7e14', 'icon' => '🟠'],
    'medium'   => ['label' => 'متوسط', 'color' => '#ffc107', 'icon' => '🟡'],
    'low'      => ['label' => 'منخفض', 'color' => '#28a745', 'icon' => '🟢'],
];

// جلب نموذج فحص المرافق
$facilityForm = null;
$facilityItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'facility_checklist' AND is_active = 1 LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $facilityForm = $engine->getFormWithItems($formRow['id']);
        if ($facilityForm) {
            foreach ($facilityForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) { $facilityItems[] = $item; }
            }
        }
    }
} catch (Exception $e) {}

// جلب فحوصات سابقة للمقارنة
$previousChecklists = [];
try {
    $stmt = $db->prepare("
        SELECT fc.id, fc.overall_score, fc.evaluation_date, sem.name_ar as semester_name
        FROM facility_checklists fc
        JOIN semesters sem ON fc.semester_id = sem.id
        WHERE fc.school_id = ? AND fc.is_active = 1
        ORDER BY fc.evaluation_date DESC LIMIT 5
    ");
    $stmt->execute([$schoolId ?: 0]);
    $previousChecklists = $stmt->fetchAll();
} catch (Exception $e) {}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $schoolIdPost = (int)($_POST['school_id'] ?? 0);
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            // بيانات الصور (عناصر نائبة)
            $photoData = $_POST['photo_placeholders'] ?? [];
            
            if (!$schoolIdPost || !$academicYearId || !$semesterId) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
            $responses = [];
            $itemScores = [];
            $repairItems = []; // عناصر تحتاج إصلاح
            foreach ($facilityItems as $item) {
                $fieldName = 'item_' . $item['id'];
                $rawValue = $_POST[$fieldName] ?? null;
                if ($rawValue !== null && $rawValue !== '') {
                    $validation = $engine->validateTool($item['tool_type'], $rawValue, $item['max_value']);
                    if ($validation['valid']) {
                        $normalized = $engine->normalizeTool($item['tool_type'], $validation['value'], $item['max_value'], $item['is_inverse']);
                        $responses[] = ['weight' => $item['weight'], 'normalized' => $normalized];
                        $itemScores[$item['id']] = ['raw' => $validation['value'], 'normalized' => $normalized];
                        
                        // تحديد أولوية الإصلاح
                        if ($item['tool_type'] === 'yes_no' && $validation['value'] == 0) {
                            $repairItems[] = ['text' => $item['text_ar'], 'priority' => 'critical'];
                        } elseif ($item['tool_type'] === 'severity_4' && $validation['value'] <= 2) {
                            $priority = $validation['value'] == 1 ? 'critical' : 'high';
                            $repairItems[] = ['text' => $item['text_ar'], 'priority' => $priority];
                        } elseif ($normalized < 50) {
                            $priority = $normalized < 25 ? 'high' : 'medium';
                            $repairItems[] = ['text' => $item['text_ar'], 'priority' => $priority];
                        }
                    }
                }
            }
            
            $overallScore = $engine->calculateScore($responses);
            
            $stmt = $db->prepare("
                INSERT INTO facility_checklists 
                (school_id, academic_year_id, semester_id, evaluator_id, evaluation_date, overall_score, notes, is_active)
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, 1)
            ");
            $stmt->execute([$schoolIdPost, $academicYearId, $semesterId, $userId, $overallScore, $notes ?: null]);
            $checklistId = $db->lastInsertId();
            
            $stmt = $db->prepare("INSERT INTO facility_items (checklist_id, item_id, score, notes, is_active) VALUES (?, ?, ?, ?, 1)");
            foreach ($itemScores as $itemId => $scores) {
                $stmt->execute([$checklistId, $itemId, $scores['normalized'], null]);
            }
            
            $db->commit();
            logActivity($db, $userId, 'facility_checklist_create', 'فحص مرافق جديد - الدرجة: ' . number_format($overallScore, 1) . '%', 'facility_checklist', $checklistId);
            setFlash('تم حفظ فحص المرافق بنجاح', 'success');
            redirect('view.php?id=' . $checklistId);
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
        
        <!-- معلومات الفحص الأساسية -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">🏫 فحص المرافق المدرسية</h3></div>
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

        <!-- تقدم الفحص ومؤشر الامتثال -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📊 تقدم الفحص</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <div class="flex items-center gap-3 mb-3">
                    <div style="flex:1; background:var(--border-light); border-radius:var(--radius-md); height:12px; overflow:hidden;">
                        <div id="progress-bar" style="width:0%; height:100%; background:#20c997; border-radius:var(--radius-md); transition:width 0.3s;"></div>
                    </div>
                    <span id="progress-text" style="font-size:14px; font-weight:600; min-width:60px; text-align:center;">0%</span>
                </div>
                <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">
                    تم فحص <span id="answered-count">0</span> من <span id="total-count"><?php echo count($facilityItems); ?></span> عنصر
                </div>
                <!-- مؤشر الامتثال -->
                <div style="background:var(--background); border-radius:var(--radius-md); padding:12px;">
                    <div style="font-size:13px; font-weight:600; margin-bottom:8px;">مؤشر الامتثال</div>
                    <div class="grid grid-4">
                        <div style="text-align:center;">
                            <div style="font-size:20px; font-weight:700; color:#28a745;" id="compliant-count">0</div>
                            <div style="font-size:11px; color:var(--text-muted);">متوافق ✅</div>
                        </div>
                        <div style="text-align:center;">
                            <div style="font-size:20px; font-weight:700; color:#dc3545;" id="non-compliant-count">0</div>
                            <div style="font-size:11px; color:var(--text-muted);">غير متوافق ❌</div>
                        </div>
                        <div style="text-align:center;">
                            <div style="font-size:20px; font-weight:700; color:#ffc107;" id="partial-count">0</div>
                            <div style="font-size:11px; color:var(--text-muted);">جزئي ⚠️</div>
                        </div>
                        <div style="text-align:center;">
                            <div style="font-size:20px; font-weight:700; color:#17a2b8;" id="compliance-pct">0%</div>
                            <div style="font-size:11px; color:var(--text-muted);">نسبة الامتثال</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($facilityForm) && !empty($facilityItems)): ?>
        <!-- عناصر فحص المرافق مع أماكن للصور -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📋 عناصر فحص المرافق</h3></div>
            <div class="card-body">
                <?php foreach ($facilityForm['categories'] as $cat): 
                    $catIcon = '🏫';
                    foreach ($categoryIcons as $key => $icon) {
                        if (mb_strpos($cat['name_ar'], $key) !== false) { $catIcon = $icon; break; }
                    }
                ?>
                <div class="mb-4" style="border:1px solid var(--border-light); border-radius:var(--radius-md); overflow:hidden;">
                    <div style="background:#20c997; color:white; padding:10px 16px; display:flex; justify-content:between; align-items:center;">
                        <span style="font-weight:700; font-size:15px;"><?php echo $catIcon . ' ' . e($cat['name_ar']); ?></span>
                        <span style="margin-right:auto; font-size:13px;" id="cat-score-<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $cat['name_ar']); ?>">—</span>
                    </div>
                    <div style="padding:16px;">
                        <?php foreach ($cat['items'] as $item): ?>
                            <?php echo $engine->renderTool($item, null, 'item_'); ?>
                        <?php endforeach; ?>
                        
                        <!-- مكان للصور -->
                        <div style="margin-top:12px; padding:12px; background:var(--background); border-radius:var(--radius-md); border:2px dashed var(--border-light);">
                            <div style="font-size:13px; font-weight:600; margin-bottom:8px; color:var(--text-secondary);">📷 صور <?php echo e($cat['name_ar']); ?></div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <div style="width:100px; height:80px; border:2px dashed var(--border-light); border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:24px; cursor:pointer;" onclick="this.innerHTML='📸<br><span style=font-size:10px>إضافة</span>'">
                                    ➕
                                </div>
                            </div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">انقر لإضافة صور (عنصر نائب)</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- توصيات الإصلاح المبدئية -->
        <div class="card mb-4">
            <div class="card-header" style="background:#dc3545; color:white;">
                <h3 class="card-title" style="color:white;">🔧 توصيات الإصلاح (تلقائية)</h3>
            </div>
            <div class="card-body">
                <div id="repair-recommendations" style="min-height:40px;">
                    <div style="text-align:center; color:var(--text-muted); padding:16px;">
                        سيتم عرض التوصيات تلقائياً بعد تعبئة الاستبيان
                    </div>
                </div>
                <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($repairPriorities as $key => $pri): ?>
                    <span style="padding:2px 8px; border-radius:var(--radius-md); font-size:12px; font-weight:600; background:<?php echo $pri['color']; ?>20; color:<?php echo $pri['color']; ?>;">
                        <?php echo $pri['icon'] . ' ' . $pri['label']; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- الفحوصات السابقة للمقارنة -->
        <?php if (!empty($previousChecklists)): ?>
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📊 الفحوصات السابقة</h3></div>
            <div class="card-body">
                <div style="max-width:100%; height:200px; margin-bottom:16px;">
                    <canvas id="previousChart"></canvas>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%; font-size:13px; border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-light);">
                                <th style="padding:6px; text-align:right;">التاريخ</th>
                                <th style="padding:6px; text-align:right;">الفصل الدراسي</th>
                                <th style="padding:6px; text-align:center;">الدرجة</th>
                                <th style="padding:6px; text-align:center;">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previousChecklists as $pc):
                                $pcRating = $pc['overall_score'] !== null ? $engine->getScoreRating($pc['overall_score']) : null;
                                $pcCondition = '—';
                                $pcConditionColor = 'var(--text-muted)';
                                if ($pc['overall_score'] !== null) {
                                    if ($pc['overall_score'] >= 75) { $pcCondition = 'ممتاز'; $pcConditionColor = '#28a745'; }
                                    elseif ($pc['overall_score'] >= 50) { $pcCondition = 'جيد'; $pcConditionColor = '#17a2b8'; }
                                    elseif ($pc['overall_score'] >= 25) { $pcCondition = 'ضعيف'; $pcConditionColor = '#ffc107'; }
                                    else { $pcCondition = 'حرج'; $pcConditionColor = '#dc3545'; }
                                }
                            ?>
                            <tr style="border-bottom:1px solid var(--border-light);">
                                <td style="padding:6px;"><?php echo formatDateAr($pc['evaluation_date'], 'short'); ?></td>
                                <td style="padding:6px;"><?php echo e($pc['semester_name']); ?></td>
                                <td style="padding:6px; text-align:center; font-weight:600; color:<?php echo $pcRating['color'] ?? 'inherit'; ?>;"><?php echo $pc['overall_score'] !== null ? number_format($pc['overall_score'], 1) . '%' : '—'; ?></td>
                                <td style="padding:6px; text-align:center; font-weight:600; color:<?php echo $pcConditionColor; ?>;"><?php echo $pcCondition; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج فحص مرافق</h4>
                <a href="../assessment/add_form.php?form_type=facility_checklist" class="btn btn-primary mt-3">إنشاء نموذج</a>
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
            <button type="submit" class="btn btn-primary">💾 حفظ الفحص</button>
        </div>
    </form>
</div>

<script>
var repairPriorities = <?php echo json_encode($repairPriorities); ?>;

document.addEventListener('change', function(e) {
    if (e.target.closest('.assessment-item')) {
        let totalWeight = 0, weightedSum = 0;
        let compliantCount = 0, nonCompliantCount = 0, partialCount = 0, totalYesNo = 0;
        let repairs = [];
        
        document.querySelectorAll('.assessment-item').forEach(item => {
            const input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
            if (input) {
                const val = parseFloat(input.value || 0);
                const weight = parseFloat(input.dataset.weight || 1);
                const tool = item.dataset.tool;
                let n = 0;
                if (['likert_5','frequency_5','satisfaction_5'].includes(tool)) n = (val/5)*100;
                else if (tool === 'yes_no') {
                    n = val*100;
                    totalYesNo++;
                    if (val == 1) compliantCount++;
                    else nonCompliantCount++;
                }
                else if (tool === 'severity_4') {
                    n = ((val-1)/3)*100;
                    totalYesNo++;
                    if (val >= 3) compliantCount++;
                    else if (val == 2) { partialCount++; nonCompliantCount++; }
                    else nonCompliantCount++;
                }
                else if (tool === 'rubric_4') n = (val/4)*100;
                else if (tool === 'numeric_100') n = val;
                else if (tool === 'counter') { const max = parseFloat(input.max || 100); n = max > 0 ? (Math.min(val,max)/max)*100 : val; }
                else n = (val/5)*100;
                weightedSum += weight * n; totalWeight += weight;
                
                // تحديد أولوية الإصلاح
                var labelText = item.querySelector('label') ? item.querySelector('label').textContent.trim() : '';
                if (tool === 'yes_no' && val == 0) {
                    repairs.push({text: labelText, priority: 'critical'});
                } else if (tool === 'severity_4' && val <= 2) {
                    repairs.push({text: labelText, priority: val == 1 ? 'critical' : 'high'});
                } else if (n < 50) {
                    repairs.push({text: labelText, priority: n < 25 ? 'high' : 'medium'});
                }
            }
        });
        
        var score = totalWeight > 0 ? Math.round((weightedSum/totalWeight)*10)/10 : 0;
        document.getElementById('live-score').textContent = score;
        
        // تحديث مؤشر الامتثال
        var compliancePct = totalYesNo > 0 ? Math.round((compliantCount / totalYesNo) * 100) : 0;
        document.getElementById('compliant-count').textContent = compliantCount;
        document.getElementById('non-compliant-count').textContent = nonCompliantCount;
        document.getElementById('partial-count').textContent = partialCount;
        document.getElementById('compliance-pct').textContent = compliancePct + '%';
        
        // تحديث تقدم الفحص
        var total = document.querySelectorAll('.assessment-item').length;
        var answered = 0;
        document.querySelectorAll('.assessment-item').forEach(function(item) {
            var inp = item.querySelector('input[type="radio"]:checked, input[type="number"]');
            if (inp && inp.value !== '') answered++;
        });
        var pct = total > 0 ? Math.round((answered / total) * 100) : 0;
        document.getElementById('progress-bar').style.width = pct + '%';
        document.getElementById('progress-text').textContent = pct + '%';
        document.getElementById('answered-count').textContent = answered;
        
        // تحديث توصيات الإصلاح
        var repairEl = document.getElementById('repair-recommendations');
        if (repairEl) {
            if (repairs.length === 0) {
                repairEl.innerHTML = '<div style="text-align:center; color:#28a745; padding:16px; font-weight:600;">✅ جميع المرافق في حالة جيدة</div>';
            } else {
                var html = '<div style="font-size:13px; margin-bottom:8px; font-weight:600;">عدد العناصر التي تحتاج إصلاح: ' + repairs.length + '</div>';
                repairs.sort(function(a, b) {
                    var order = {critical: 0, high: 1, medium: 2, low: 3};
                    return (order[a.priority] || 3) - (order[b.priority] || 3);
                });
                repairs.slice(0, 10).forEach(function(r) {
                    var pri = repairPriorities[r.priority] || repairPriorities['low'];
                    html += '<div style="padding:6px 10px; margin-bottom:4px; background:' + pri.color + '10; border-radius:var(--radius-md); display:flex; align-items:center; gap:8px;">';
                    html += '<span style="font-size:16px;">' + pri.icon + '</span>';
                    html += '<span style="font-size:13px; flex:1;">' + r.text + '</span>';
                    html += '<span style="font-size:11px; font-weight:600; color:' + pri.color + ';">' + pri.label + '</span>';
                    html += '</div>';
                });
                if (repairs.length > 10) {
                    html += '<div style="font-size:12px; color:var(--text-muted); text-align:center;">+ ' + (repairs.length - 10) + ' عناصر أخرى</div>';
                }
                repairEl.innerHTML = html;
            }
        }
    }
});
</script>

<?php
if (!empty($previousChecklists)):
    $prevLabels = [];
    $prevData = [];
    foreach (array_reverse($previousChecklists) as $pc) {
        $prevLabels[] = formatDateAr($pc['evaluation_date'], 'short');
        $prevData[] = $pc['overall_score'] ?? 0;
    }
    $pageJs = "
        eqasLineChart('previousChart', 'تطور فحوصات المرافق', " . json_encode($prevLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة الكلية',
            data: " . json_encode($prevData) . ",
            borderColor: '#20c997',
            fill: true
        }], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
endif;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
