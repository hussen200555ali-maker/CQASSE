<?php
/**
 * ============================================================
 * تقييم مهارات جديد - New Skills Evaluation
 * ملاحظة: جدول skills_evaluations تم أرشفته في الترحيل v3.3.0
 * يرجى استخدام نظام التقييم الموحد عبر assessments
 * ============================================================
 */

$pageTitle = 'تقييم مهارات جديد';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'قائمة المهارات' => 'index.php', 'تقييم جديد' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// تصنيفات المهارات مع الأيقونات والألوان
$skillCategories = [
    'أكاديمي' => ['icon' => '📖', 'color' => '#1a5632', 'label' => 'المهارات الأكاديمية'],
    'اجتماعي' => ['icon' => '🤝', 'color' => '#17a2b8', 'label' => 'المهارات الاجتماعية'],
    'بدني'    => ['icon' => '🏃', 'color' => '#fd7e14', 'label' => 'المهارات البدنية'],
    'إبداعي'  => ['icon' => '🎨', 'color' => '#6f42c1', 'label' => 'المهارات الإبداعية'],
];

// مستويات المهارات
$skillLevels = [
    ['key' => 'beginner',     'label' => 'مبتدئ',    'min' => 0,  'max' => 25,  'color' => '#dc3545', 'icon' => '🔴'],
    ['key' => 'intermediate', 'label' => 'متوسط',    'min' => 25, 'max' => 50,  'color' => '#ffc107', 'icon' => '🟡'],
    ['key' => 'advanced',     'label' => 'متقدم',    'min' => 50, 'max' => 75,  'color' => '#17a2b8', 'icon' => '🔵'],
    ['key' => 'expert',       'label' => 'خبير',     'min' => 75, 'max' => 100, 'color' => '#28a745', 'icon' => '🟢'],
];

// جلب البيانات الأساسية — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $sql = "SELECT DISTINCT c.id, c.name_ar, gl.name_ar as grade_name
                FROM classes c
                JOIN grade_levels gl ON c.grade_level_id = gl.id
                JOIN subject_teachers st ON st.classroom_id = c.id AND st.teacher_id = ? AND st.is_active = 1
                WHERE c.is_active = 1 AND c.school_id = ?
                UNION
                SELECT DISTINCT c.id, c.name_ar, gl.name_ar as grade_name
                FROM classes c
                JOIN grade_levels gl ON c.grade_level_id = gl.id
                WHERE c.teacher_id = ? AND c.is_active = 1 AND c.school_id = ? AND c.academic_year_id = ?
                ORDER BY grade_name, name_ar";
        $stmt = $db->prepare($sql); $stmt->execute([$userId, $schoolId, $userId, $schoolId, $academicYearId]);
    } else {
        $sql = "SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.is_active = 1";
        $p = [];
        if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND c.school_id = ?"; $p[] = $schoolId; }
        $sql .= " ORDER BY gl.level_number, c.name_ar";
        $stmt = $db->prepare($sql); $stmt->execute($p);
    }
    $classes = $stmt->fetchAll();
} catch (Exception $e) {}

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب نموذج المهارات
$skillsForm = null;
$skillsItems = [];
$categoryGroups = []; // تجميع العناصر حسب فئة المهارة
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'skills_checklist' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $skillsForm = $engine->getFormWithItems($formRow['id']);
        if ($skillsForm) {
            foreach ($skillsForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $skillsItems[] = $item;
                }
                // تصنيف الفئات ضمن مجموعات المهارات
                $catName = $cat['name_ar'];
                $matchedGroup = 'أكاديمي'; // افتراضي
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

// جلب تقييمات سابقة للطالب للمقارنة
$previousEvals = [];
$selectedStudentId = $_GET['student_id'] ?? 0;
if ($selectedStudentId) {
    try {
        $stmt = $db->prepare("
            SELECT e.id, e.combined_score as overall_score, DATE(e.created_at) as evaluation_date, sem.name_ar as semester_name
            FROM evaluations e
            JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.student_id = ? AND e.eval_type = 'skills_checklist' AND e.is_active = 1
            ORDER BY e.created_at DESC LIMIT 5
        ");
        $stmt->execute([$selectedStudentId]);
        $previousEvals = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $studentId = (int)($_POST['student_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterVal = (int)($_POST['semester_id'] ?? 0);
            $semesterId = 0;

            // التحقق الأمني: المعلم يملك صلاحية لهذا الفصل
            if ($userRole === 'teacher') {
                $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
                if (!in_array($classId, $allowedIds)) {
                    throw new Exception('ليس لديك صلاحية لتقييم مهارات في هذا الفصل');
                }
            }
            if ($semesterVal && $academicYearId) {
                $semName = semesterValueToNameAr($semesterVal);
                if ($semName) {
                    $sStmt = $db->prepare("SELECT id FROM semesters WHERE name_ar = ? AND academic_year_id = ? AND is_active = 1 LIMIT 1");
                    $sStmt->execute([$semName, $academicYearId]);
                    $semesterId = (int)$sStmt->fetchColumn();
                }
            }
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$studentId || !$classId || !$academicYearId || !$semesterId) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
            $responses = [];
            $itemScores = [];
            $categoryTempScores = []; // حساب درجات كل فئة
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
            
            $stmt = $db->prepare("
                INSERT INTO evaluations 
                (eval_type, student_id, class_id, school_id, academic_year_id, semester_id, 
                 evaluated_by, combined_score, current_percentage, notes, is_active, created_at, updated_at)
                VALUES ('skills_checklist', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$studentId, $classId, $schoolId, $academicYearId, $semesterId, $userId, $overallScore, $overallScore, $notes ?: null]);
            $evaluationId = $db->lastInsertId();
            
            $formId = $formRow['id'];
            $stmt = $db->prepare("
                INSERT INTO assessment_responses 
                (form_id, evaluation_id, item_id, student_id, school_id, class_id, academic_year_id, 
                 semester_id, numeric_value, responded_by, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            foreach ($itemScores as $itemId => $scores) {
                $stmt->execute([$formId, $evaluationId, $itemId, $studentId, $schoolId, $classId, $academicYearId, $semesterId, $scores['normalized'], $userId]);
            }
            
            $db->commit();
            logActivity($db, $userId, 'skills_evaluation_create', 'تقييم مهارات جديد - الدرجة: ' . number_format($overallScore, 1) . '%', 'evaluation', $evaluationId);
            setFlash('تم حفظ تقييم المهارات بنجاح - الدرجة الكلية: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('view.php?id=' . $evaluationId);
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
        
        <!-- معلومات التقييم الأساسية -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">✅ معلومات تقييم المهارات</h3>
                <div id="level-badge-container" style="display:none;">
                    <span id="level-badge" style="padding:4px 12px; border-radius:var(--radius-md); font-weight:700; font-size:13px;"></span>
                </div>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">الطالب <span class="required">*</span></label>
                        <select name="student_id" class="form-control" required id="student-select">
                            <option value="">-- اختر الطالب --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل <span class="required">*</span></label>
                        <select name="class_id" class="form-control" required id="class-select">
                            <option value="">-- اختر الفصل --</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>"><?php echo e($cls['grade_name'] . ' - ' . $cls['name_ar']); ?></option>
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
                            <option value="1">الفصل الدراسي الأول</option>
                            <option value="2">الفصل الدراسي الثاني</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- مؤشر مستوى المهارة المباشر -->
        <div class="card mb-4" id="skill-level-card" style="display:none;">
            <div class="card-header"><h3 class="card-title">🏅 مؤشر مستوى المهارة</h3></div>
            <div class="card-body">
                <div class="grid grid-4">
                    <?php foreach ($skillLevels as $level): ?>
                    <div id="level-indicator-<?php echo $level['key']; ?>" style="text-align:center; padding:12px; border-radius:var(--radius-md); border:2px solid var(--border-light); opacity:0.4; transition:all 0.3s;">
                        <div style="font-size:24px;"><?php echo $level['icon']; ?></div>
                        <div style="font-weight:700; font-size:14px; margin-top:4px;"><?php echo $level['label']; ?></div>
                        <div style="font-size:11px; color:var(--text-muted);"><?php echo $level['min']; ?>% - <?php echo $level['max']; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:12px; text-align:center;">
                    <span style="font-size:14px; color:var(--text-secondary);">المستوى الحالي: </span>
                    <span id="current-level-text" style="font-weight:700; font-size:16px;">—</span>
                </div>
            </div>
        </div>

        <!-- التقييمات السابقة للطالب -->
        <?php if (!empty($previousEvals)): ?>
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📈 التقييمات السابقة</h3></div>
            <div class="card-body">
                <div style="max-width:100%; height:200px;">
                    <canvas id="previousEvalsChart"></canvas>
                </div>
                <div style="margin-top:12px;">
                    <table style="width:100%; font-size:13px;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-light);">
                                <th style="padding:6px; text-align:right;">التاريخ</th>
                                <th style="padding:6px; text-align:right;">الفصل الدراسي</th>
                                <th style="padding:6px; text-align:right;">الدرجة</th>
                                <th style="padding:6px; text-align:right;">المستوى</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previousEvals as $pev):
                                $pevRating = $pev['overall_score'] !== null ? $engine->getScoreRating($pev['overall_score']) : null;
                                $pevLevel = '—';
                                if ($pev['overall_score'] !== null) {
                                    foreach ($skillLevels as $sl) {
                                        if ($pev['overall_score'] >= $sl['min'] && $pev['overall_score'] < $sl['max']) { $pevLevel = $sl['icon'] . ' ' . $sl['label']; break; }
                                    }
                                    if ($pev['overall_score'] >= 75) $pevLevel = '🟢 خبير';
                                }
                            ?>
                            <tr style="border-bottom:1px solid var(--border-light);">
                                <td style="padding:6px;"><?php echo formatDateAr($pev['evaluation_date'], 'short'); ?></td>
                                <td style="padding:6px;"><?php echo e($pev['semester_name']); ?></td>
                                <td style="padding:6px; font-weight:600; color:<?php echo $pevRating['color'] ?? 'inherit'; ?>;"><?php echo $pev['overall_score'] !== null ? number_format($pev['overall_score'], 1) . '%' : '—'; ?></td>
                                <td style="padding:6px;"><?php echo $pevLevel; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($skillsForm) && !empty($skillsItems)): ?>
        <!-- ملخص تقدم التقييم -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📊 تقدم التقييم</h3>
            </div>
            <div class="card-body">
                <div class="flex items-center gap-3 mb-2">
                    <div style="flex:1; background:var(--border-light); border-radius:var(--radius-md); height:12px; overflow:hidden;">
                        <div id="progress-bar" style="width:0%; height:100%; background:var(--primary); border-radius:var(--radius-md); transition:width 0.3s;"></div>
                    </div>
                    <span id="progress-text" style="font-size:14px; font-weight:600; min-width:60px; text-align:center;">0%</span>
                </div>
                <div style="font-size:12px; color:var(--text-muted);">
                    تم تقييم <span id="answered-count">0</span> من <span id="total-count"><?php echo count($skillsItems); ?></span> عنصر
                </div>
            </div>
        </div>

        <!-- عناصر تقييم المهارات مصنفة حسب المجموعات -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر تقييم المهارات</h3>
                <div style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <?php 
                // عرض الفئات مجمعة حسب نوع المهارة
                $grouped = !empty($categoryGroups) ? $categoryGroups : ['أكاديمي' => $skillsForm['categories']];
                foreach ($grouped as $groupName => $categories):
                    $groupInfo = $skillCategories[$groupName] ?? ['icon' => '📋', 'color' => 'var(--primary)', 'label' => $groupName];
                ?>
                <div style="margin-bottom:24px; border:1px solid var(--border-light); border-radius:var(--radius-md); overflow:hidden;">
                    <div style="background:<?php echo $groupInfo['color']; ?>; color:white; padding:10px 16px; display:flex; align-items:center; gap:8px;">
                        <span style="font-size:20px;"><?php echo $groupInfo['icon']; ?></span>
                        <span style="font-weight:700; font-size:15px;"><?php echo e($groupInfo['label']); ?></span>
                        <span style="margin-right:auto; font-size:13px; opacity:0.9;" id="group-score-<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $groupName); ?>">—</span>
                    </div>
                    <div style="padding:16px;">
                        <?php foreach ($categories as $cat): ?>
                        <div class="mb-4">
                            <h4 style="color:var(--primary); border-bottom:2px solid var(--success); padding-bottom:4px; margin-bottom:12px;">
                                ✅ <?php echo e($cat['name_ar']); ?>
                            </h4>
                            <?php foreach ($cat['items'] as $item): ?>
                                <?php echo $engine->renderTool($item, null, 'item_'); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- معاينة الرسم البياني للمهارات -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">📊 معاينة توزيع المهارات</h3></div>
            <div class="card-body">
                <div style="max-width:100%; height:300px;">
                    <canvas id="skillsPreviewChart"></canvas>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج تقييم مهارات</h4>
                <a href="../assessment/add_form.php?form_type=skills_checklist" class="btn btn-primary mt-3">إنشاء نموذج</a>
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
            <button type="submit" class="btn btn-primary">💾 حفظ التقييم</button>
        </div>
    </form>
</div>

<script>
document.getElementById('class-select').addEventListener('change', function() {
    const classId = this.value;
    const studentSelect = document.getElementById('student-select');
    studentSelect.innerHTML = '<option value="">-- جاري التحميل... --</option>';
    if (!classId) { studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>'; return; }
    fetch(`../../api/get_students.php?class_id=${classId}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json()).then(data => {
        studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
        if (data.students) data.students.forEach(s => { studentSelect.innerHTML += `<option value="${s.id}">${s.full_name_ar} (${s.student_number})</option>`; });
    }).catch(() => { studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>'; });
});

// مستويات المهارات للجافاسكربت
var skillLevels = <?php echo json_encode($skillLevels); ?>;

function getSkillLevel(score) {
    for (var i = skillLevels.length - 1; i >= 0; i--) {
        if (score >= skillLevels[i].min) return skillLevels[i];
    }
    return skillLevels[0];
}

function updateLevelIndicator(score) {
    var card = document.getElementById('skill-level-card');
    if (!card) return;
    card.style.display = 'block';
    
    var level = getSkillLevel(score);
    document.getElementById('current-level-text').textContent = level.icon + ' ' + level.label;
    document.getElementById('current-level-text').style.color = level.color;
    
    // تحديث شارة المستوى في الهيدر
    var badge = document.getElementById('level-badge');
    badge.textContent = level.icon + ' ' + level.label;
    badge.style.background = level.color + '20';
    badge.style.color = level.color;
    document.getElementById('level-badge-container').style.display = 'block';
    
    // إضاءة المؤشر المناسب
    skillLevels.forEach(function(sl) {
        var el = document.getElementById('level-indicator-' + sl.key);
        if (el) {
            if (sl.key === level.key) {
                el.style.opacity = '1';
                el.style.borderColor = sl.color;
                el.style.background = sl.color + '10';
            } else {
                el.style.opacity = '0.4';
                el.style.borderColor = 'var(--border-light)';
                el.style.background = 'transparent';
            }
        }
    });
}

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
        var categoryScores = {};
        var categoryWeights = {};
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
                var catName = item.closest('.mb-4').querySelector('h4')?.textContent?.replace('✅', '').trim() || 'عام';
                if (!categoryScores[catName]) { categoryScores[catName] = 0; categoryWeights[catName] = 0; }
                categoryScores[catName] += weight * n;
                categoryWeights[catName] += weight;
            }
        });
        var score = totalWeight > 0 ? Math.round((weightedSum/totalWeight)*10)/10 : 0;
        document.getElementById('live-score').textContent = score;
        updateLevelIndicator(score);
        updateProgress();
        if (typeof skillsPreviewChart !== 'undefined' && skillsPreviewChart) {
            var catData = [];
            var catColors = [];
            var groupColors = {
                'أكاديمي': 'rgba(26, 86, 50, 0.7)',
                'اجتماعي': 'rgba(23, 162, 184, 0.7)',
                'بدني': 'rgba(253, 126, 20, 0.7)',
                'إبداعي': 'rgba(111, 66, 193, 0.7)'
            };
            skillsPreviewChart.data.labels.forEach(function(label) {
                var matchedGroup = 'أكاديمي';
                for (var gk in groupColors) {
                    if (label.indexOf(gk) !== -1 || gk.indexOf(label) !== -1) { matchedGroup = gk; break; }
                }
                var catWeight = categoryWeights[label] || 0;
                var catScore = catWeight > 0 ? Math.round((categoryScores[label] / catWeight) * 10) / 10 : 0;
                catData.push(catScore);
                catColors.push(groupColors[matchedGroup]);
            });
            skillsPreviewChart.data.datasets[0].data = catData;
            skillsPreviewChart.data.datasets[0].backgroundColor = catColors;
            skillsPreviewChart.update();
        }
    }
});
</script>

<?php
// رسم بياني للتقييمات السابقة
if (!empty($previousEvals)):
    $prevLabels = [];
    $prevData = [];
    foreach (array_reverse($previousEvals) as $pev) {
        $prevLabels[] = formatDateAr($pev['evaluation_date'], 'short');
        $prevData[] = $pev['overall_score'] ?? 0;
    }
    $pageJs = "
        eqasLineChart('previousEvalsChart', 'تطور تقييمات المهارات', " . json_encode($prevLabels, JSON_UNESCAPED_UNICODE) . ", [{label: 'الدرجة الكلية', data: " . json_encode($prevData) . ", borderColor: '#1a5632', fill: true}], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
endif;

// رسم بياني للمعاينة
if (!empty($skillsForm)):
    $previewLabels = [];
    foreach ($skillsForm['categories'] as $cat) {
        $previewLabels[] = $cat['name_ar'];
    }
    if (!isset($pageJs)) $pageJs = '';
    $pageJs .= "
        var skillsPreviewChart = eqasBarChart('skillsPreviewChart', 'توزيع المهارات حسب الفئة', " . json_encode($previewLabels, JSON_UNESCAPED_UNICODE) . ", [{label: 'الدرجة المتوقعة', data: new Array(" . count($previewLabels) . ").fill(0), backgroundColor: 'rgba(26, 86, 50, 0.6)'}], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
endif;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
