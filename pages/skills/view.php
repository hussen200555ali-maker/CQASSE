<?php
/**
 * عرض تقييم المهارات
 * ملاحظة: جدول skills_evaluations تم أرشفته في الترحيل v3.3.0
 */
$pageTitle = 'عرض تقييم المهارات';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'قائمة المهارات' => 'index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userRole = $_SESSION['user']['role'] ?? '';
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('index.php'); }

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
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('index.php'); }

// التحقق من ملكية المدرسة — لا يمكن عرض تقييم من مدرسة أخرى
if ($schoolId && $userRole !== 'group_admin' && $evaluation['school_id'] != $schoolId) {
    setFlash('ليس لديك صلاحية لعرض هذا التقييم', 'danger');
    redirect('index.php');
}

// التحقق من وصول المعلم — الفصل ضمن فصوله المسموحة
if ($userRole === 'teacher') {
    $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!in_array((int)$evaluation['class_id'], $allowedIds)) {
        setFlash('ليس لديك صلاحية لعرض هذا التقييم', 'danger');
        redirect('index.php');
    }
}

$items = [];
$categoryScores = [];
try {
    $stmt = $db->prepare("
        SELECT ar.numeric_value as score, ar.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value, ac.name_ar as category_name
        FROM assessment_responses ar JOIN assessment_structure ai ON ar.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        WHERE ar.evaluation_id = ? AND ar.is_active = 1 ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
    $items = $stmt->fetchAll();
    
    // حساب درجات الفئات
    foreach ($items as $item) {
        $catName = $item['category_name'];
        if (!isset($categoryScores[$catName])) {
            $categoryScores[$catName] = ['weighted_sum' => 0, 'total_weight' => 0, 'count' => 0];
        }
        $categoryScores[$catName]['weighted_sum'] += $item['weight'] * ($item['score'] ?? 0);
        $categoryScores[$catName]['total_weight'] += $item['weight'];
        $categoryScores[$catName]['count']++;
    }
} catch (Exception $e) {}

$overallScore = $evaluation['combined_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// مستوى المهارة
$skillLevels = [
    ['key' => 'beginner',     'label' => 'مبتدئ',    'min' => 0,  'max' => 25,  'color' => '#dc3545', 'icon' => '🔴'],
    ['key' => 'intermediate', 'label' => 'متوسط',    'min' => 25, 'max' => 50,  'color' => '#ffc107', 'icon' => '🟡'],
    ['key' => 'advanced',     'label' => 'متقدم',    'min' => 50, 'max' => 75,  'color' => '#17a2b8', 'icon' => '🔵'],
    ['key' => 'expert',       'label' => 'خبير',     'min' => 75, 'max' => 100, 'color' => '#28a745', 'icon' => '🟢'],
];

$currentLevel = null;
if ($overallScore !== null) {
    foreach ($skillLevels as $sl) {
        if ($overallScore >= $sl['min'] && $overallScore < $sl['max']) { $currentLevel = $sl; break; }
    }
    if ($overallScore >= 100) $currentLevel = $skillLevels[3];
    if (!$currentLevel) $currentLevel = $skillLevels[0];
}

// جلب التقييمات السابقة للطالب (للخط الزمني)
$timeline = [];
try {
    $stmt = $db->prepare("
        SELECT e.id, e.combined_score as overall_score, DATE(e.created_at) as evaluation_date, sem.name_ar as semester_name
        FROM evaluations e
        JOIN semesters sem ON e.semester_id = sem.id
        WHERE e.student_id = ? AND e.eval_type = 'skills_checklist' AND e.is_active = 1
        ORDER BY e.created_at ASC
    ");
    $stmt->execute([$evaluation['student_id']]);
    $timeline = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب تقييمات الطلاب الآخرين في نفس الفصل للمقارنة
$comparisonData = [];
try {
    $stmt = $db->prepare("
        SELECT e.id, e.combined_score as overall_score, s.full_name_ar as student_name
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        WHERE e.class_id = ? AND e.semester_id = ? AND e.eval_type = 'skills_checklist' AND e.is_active = 1
        ORDER BY e.combined_score DESC
        LIMIT 10
    ");
    $stmt->execute([$evaluation['class_id'], $evaluation['semester_id']]);
    $comparisonData = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<!-- معلومات الطالب -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">✅</span>
                <div>
                    <h3 class="card-title">تقييم مهارات - <?php echo e($evaluation['student_name']); ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);"><?php echo e($evaluation['grade_name'] . ' - ' . $evaluation['class_name']); ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="window.print()" class="btn btn-light btn-sm" title="طباعة">🖨️</button>
                <a href="index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div><span style="font-size:12px; color:var(--text-muted);">الطالب</span><div style="font-weight:600;"><?php echo e($evaluation['student_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">المقيّم</span><div style="font-weight:600;"><?php echo e($evaluation['evaluator_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?php echo e($evaluation['semester_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">التاريخ</span><div style="font-weight:600;"><?php echo formatDateAr($evaluation['created_at'] ?? $evaluation['evaluation_date'], 'medium'); ?></div></div>
            </div>
        </div>
    </div>
</div>

<!-- الدرجة الكلية ومستوى المهارة -->
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-body" style="text-align:center; padding:30px;">
                <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">الدرجة الكلية</div>
                <div style="font-size:48px; font-weight:700; color:<?php echo $rating['color'] ?? 'var(--text-primary)'; ?>;">
                    <?php echo $overallScore !== null ? number_format($overallScore, 1) : '—'; ?>%
                </div>
                <?php if ($rating): ?>
                    <div style="font-size:20px; color:<?php echo $rating['color']; ?>; font-weight:600;"><?php echo $rating['label']; ?></div>
                <?php endif; ?>
                <div style="margin-top:16px; max-width:400px; margin-left:auto; margin-right:auto;">
                    <?php echo $engine->renderScoreBar($overallScore ?? 0); ?>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:30px;">
                <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">مستوى المهارة</div>
                <?php if ($currentLevel): ?>
                <div style="font-size:48px; margin-bottom:8px;"><?php echo $currentLevel['icon']; ?></div>
                <div style="font-size:28px; font-weight:700; color:<?php echo $currentLevel['color']; ?>;"><?php echo $currentLevel['label']; ?></div>
                <div style="font-size:13px; color:var(--text-muted); margin-top:4px;"><?php echo $currentLevel['min']; ?>% - <?php echo $currentLevel['max']; ?>%</div>
                <?php else: ?>
                <div style="font-size:48px;">—</div>
                <?php endif; ?>
                <!-- مؤشرات المستويات -->
                <div style="margin-top:16px; display:flex; justify-content:center; gap:8px;">
                    <?php foreach ($skillLevels as $sl): ?>
                    <div style="padding:4px 12px; border-radius:var(--radius-md); font-size:12px; font-weight:600;
                        <?php if ($currentLevel && $sl['key'] === $currentLevel['key']): ?>
                            background:<?php echo $sl['color']; ?>; color:white;
                        <?php else: ?>
                            background:<?php echo $sl['color']; ?>20; color:<?php echo $sl['color']; ?>;
                        <?php endif; ?>
                    ">
                        <?php echo $sl['icon'] . ' ' . $sl['label']; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- رسم بياني لدرجات الفئات -->
<?php if (!empty($categoryScores)): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 درجات المهارات حسب الفئة</h3></div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- الخط الزمني لتطور المهارات -->
<?php if (count($timeline) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📈 تطور المهارات عبر الزمن</h3></div>
        <div class="card-body">
            <div style="height:250px; margin-bottom:16px;">
                <canvas id="timelineChart"></canvas>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center;">
                <?php foreach ($timeline as $tl):
                    $tlLevel = null;
                    if ($tl['overall_score'] !== null) {
                        foreach ($skillLevels as $sl) {
                            if ($tl['overall_score'] >= $sl['min'] && $tl['overall_score'] < $sl['max']) { $tlLevel = $sl; break; }
                        }
                        if ($tl['overall_score'] >= 100) $tlLevel = $skillLevels[3];
                    }
                    $isCurrent = $tl['id'] == $evalId;
                ?>
                <div style="padding:8px 12px; border-radius:var(--radius-md); font-size:12px;
                    <?php echo $isCurrent ? 'background:var(--primary); color:white; font-weight:700;' : 'background:var(--background); border:1px solid var(--border-light);'; ?>">
                    <?php echo formatDateAr($tl['evaluation_date'], 'short'); ?> — 
                    <?php echo $tl['overall_score'] !== null ? number_format($tl['overall_score'], 1) . '%' : '—'; ?>
                    <?php if ($tlLevel): ?> (<?php echo $tlLevel['label']; ?>)<?php endif; ?>
                    <?php if ($isCurrent): ?> ◀<?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- مقارنة مع زملاء الفصل -->
<?php if (count($comparisonData) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">👥 مقارنة مع زملاء الفصل</h3></div>
        <div class="card-body">
            <div style="height:250px;">
                <canvas id="comparisonChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- عناصر التقييم مع أشرطة التقدم -->
<div class="section">
    <h3 class="section-title">تفاصيل المهارات</h3>
    <?php
    $currentCategory = '';
    foreach ($items as $item):
        if ($item['category_name'] !== $currentCategory):
            if ($currentCategory !== '') echo '</div></div>';
            $currentCategory = $item['category_name'];
            $catScore = isset($categoryScores[$currentCategory]) && $categoryScores[$currentCategory]['total_weight'] > 0 
                ? round($categoryScores[$currentCategory]['weighted_sum'] / $categoryScores[$currentCategory]['total_weight'], 1) : 0;
            $catRating = $engine->getScoreRating($catScore);
    ?>
    <div class="card mb-4">
        <div class="card-header" style="background:var(--success); color:white;">
            <div class="flex items-center justify-between" style="width:100%;">
                <h4 style="margin:0; color:white; font-size:15px;">✅ <?php echo e($currentCategory); ?></h4>
                <span style="font-size:14px; font-weight:700; color:white;"><?php echo $catScore; ?>% (<?php echo $catRating['label']; ?>)</span>
            </div>
        </div>
        <div class="card-body">
    <?php endif; ?>
            <div style="margin-bottom:16px;">
                <div class="flex justify-between items-center mb-1">
                    <span style="font-size:14px; font-weight:500;"><?php echo e($item['text_ar']); ?></span>
                    <span style="font-size:14px; font-weight:600; color:<?php echo $engine->getScoreRating($item['score'] ?? 0)['color']; ?>;">
                        <?php echo number_format($item['score'] ?? 0, 1); ?>%
                    </span>
                </div>
                <?php echo $engine->renderScoreBar($item['score'] ?? 0); ?>
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    الأداة: <?php echo e($engine->getToolLabel($item['tool_type'])); ?>
                    <?php if ($item['is_inverse']): ?> | <span style="color:var(--warning);">عكسي</span><?php endif; ?>
                </div>
            </div>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($evaluation['notes']): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📝 ملاحظات</h3></div>
        <div class="card-body"><p><?php echo e($evaluation['notes']); ?></p></div>
    </div>
</div>
<?php endif; ?>

<?php
// بيانات الرسوم البيانية
$catLabels = [];
$catData = [];
foreach ($categoryScores as $catName => $catDataItem) {
    $catLabels[] = $catName;
    $catData[] = $catDataItem['total_weight'] > 0 ? round($catDataItem['weighted_sum'] / $catDataItem['total_weight'], 1) : 0;
}

$tlLabels = [];
$tlData = [];
foreach ($timeline as $tl) {
    $tlLabels[] = formatDateAr($tl['evaluation_date'], 'short');
    $tlData[] = $tl['overall_score'] ?? 0;
}

$compLabels = [];
$compData = [];
$compColors = [];
foreach ($comparisonData as $cd) {
    $compLabels[] = $cd['student_name'];
    $compData[] = $cd['overall_score'] ?? 0;
    $compColors[] = ($cd['id'] == $evalId) ? '#1a5632' : 'rgba(26, 86, 50, 0.3)';
}

$pageJs = "";
if (!empty($catLabels)) {
    $pageJs .= "
        eqasBarChart('categoryChart', 'درجات الفئات', " . json_encode($catLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة',
            data: " . json_encode($catData) . ",
            backgroundColor: 'rgba(26, 86, 50, 0.6)',
            borderColor: 'rgba(26, 86, 50, 1)',
            borderWidth: 1
        }], {indexAxis: 'y', scales: {x: {beginAtZero: true, max: 100}}});
    ";
}
if (count($timeline) > 1) {
    $pageJs .= "
        eqasLineChart('timelineChart', 'تطور المهارات', " . json_encode($tlLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة الكلية',
            data: " . json_encode($tlData) . ",
            borderColor: '#1a5632',
            fill: true
        }], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
}
if (count($comparisonData) > 1) {
    $pageJs .= "
        eqasBarChart('comparisonChart', 'مقارنة الزملاء', " . json_encode($compLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة الكلية',
            data: " . json_encode($compData) . ",
            backgroundColor: " . json_encode($compColors) . "
        }], {scales: {y: {beginAtZero: true, max: 100}}, plugins: {legend: {display: false}}});
    ";
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
