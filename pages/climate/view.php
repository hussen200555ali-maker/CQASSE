<?php
/**
 * ============================================================
 * عرض استبيان المناخ المدرسي - مع تحليل الأبعاد والاتجاهات
 * Climate Survey View with Dimension Breakdown & Trends
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض استبيان المناخ';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'استبيان المناخ' => 'index.php', 'عرض الاستبيان' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$surveyId = (int)($_GET['id'] ?? 0);
if (!$surveyId) { setFlash('معرف الاستبيان غير صالح', 'danger'); redirect('index.php'); }

$survey = null;
try {
    $stmt = $db->prepare("
        SELECT cs.*, s.name_ar as school_name, sem.name_ar as semester_name, ay.name as year_name
        FROM climate_surveys cs
        JOIN schools s ON cs.school_id = s.id
        JOIN semesters sem ON cs.semester_id = sem.id
        JOIN academic_years ay ON cs.academic_year_id = ay.id
        WHERE cs.id = ? AND cs.is_active = 1
    ");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch();
} catch (Exception $e) {}

if (!$survey) { setFlash('الاستبيان غير موجود', 'danger'); redirect('index.php'); }

$respondentTypes = ['teacher' => 'معلم', 'student' => 'طالب', 'parent' => 'ولي أمر', 'admin' => 'إداري'];
$respondentIcons = ['teacher' => '👨‍🏫', 'student' => '👨‍🎓', 'parent' => '👨‍👧', 'admin' => '👨‍💼'];

$items = [];
$categoryScores = [];
try {
    $stmt = $db->prepare("
        SELECT ci.id, ci.survey_id, ci.item_id, ci.numeric_value, ci.normalized_value AS score, ci.is_active,
               ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value, ac.name_ar as category_name
        FROM climate_items ci JOIN assessment_items ai ON ci.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        WHERE ci.survey_id = ? AND ci.is_active = 1 ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$surveyId]);
    $items = $stmt->fetchAll();
    
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

$overallScore = $survey['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// جلب الاستبيانات السابقة لنفس المدرسة للاتجاه الزمني
$trendSurveys = [];
try {
    $stmt = $db->prepare("
        SELECT cs.id, cs.overall_score, cs.survey_date, cs.respondent_type, sem.name_ar as semester_name
        FROM climate_surveys cs
        JOIN semesters sem ON cs.semester_id = sem.id
        WHERE cs.school_id = ? AND cs.is_active = 1
        ORDER BY cs.survey_date ASC
    ");
    $stmt->execute([$survey['school_id']]);
    $trendSurveys = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب استبيانات نفس الفترة لأنواع مستجيبين مختلفين للمقارنة
$periodComparisons = [];
try {
    $stmt = $db->prepare("
        SELECT cs.respondent_type, AVG(cs.overall_score) as avg_score, COUNT(*) as count
        FROM climate_surveys cs
        WHERE cs.school_id = ? AND cs.semester_id = ? AND cs.is_active = 1
        GROUP BY cs.respondent_type
    ");
    $stmt->execute([$survey['school_id'], $survey['semester_id']]);
    $periodComparisons = $stmt->fetchAll();
} catch (Exception $e) {}

// إحصائيات المستجيبين في هذه الفترة
$totalRespondents = 0;
$respondentBreakdown = [];
foreach ($periodComparisons as $pc) {
    $totalRespondents += $pc['count'];
    $respondentBreakdown[$pc['respondent_type']] = ['count' => $pc['count'], 'avg_score' => round($pc['avg_score'], 1)];
}
?>

<!-- معلومات الاستبيان -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">🌡️</span>
                <div>
                    <h3 class="card-title">استبيان المناخ المدرسي</h3>
                    <div style="font-size:13px; color:var(--text-secondary);"><?php echo e($survey['school_name']); ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="window.print()" class="btn btn-light btn-sm" title="طباعة التقرير">🖨️ طباعة</button>
                <a href="index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div><span style="font-size:12px; color:var(--text-muted);">المدرسة</span><div style="font-weight:600;"><?php echo e($survey['school_name']); ?></div></div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">نوع المستجيب</span>
                    <div style="font-weight:600;"><?php echo ($respondentIcons[$survey['respondent_type']] ?? '') . ' ' . e($respondentTypes[$survey['respondent_type']] ?? $survey['respondent_type']); ?></div>
                </div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?php echo e($survey['semester_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الدرجة الكلية</span>
                    <div style="font-weight:700; font-size:20px; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                        <?php echo $overallScore !== null ? number_format($overallScore, 1) . '%' : '—'; ?>
                    </div>
                    <?php if ($rating): ?><div style="color:<?php echo $rating['color']; ?>;"><?php echo $rating['label']; ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- الدرجة الكلية مع شريط التقدم -->
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:24px;">
            <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">الدرجة الكلية للمناخ المدرسي</div>
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
</div>

<!-- رسم بياني رادار للأبعاد -->
<?php if (!empty($categoryScores)): ?>
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">🕸️ مخطط رادار للأبعاد</h3></div>
            <div class="card-body">
                <div style="max-width:400px; height:350px; margin:0 auto;">
                    <canvas id="radarChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 درجات الأبعاد</h3></div>
            <div class="card-body">
                <div style="height:350px;">
                    <canvas id="dimensionBarChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- مقارنة المستجيبين في نفس الفترة -->
<?php if (count($respondentBreakdown) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">👥 مقارنة أنواع المستجيبين (نفس الفترة)</h3></div>
        <div class="card-body">
            <div class="grid grid-auto-lg">
                <?php foreach ($respondentTypes as $key => $label):
                    if (!isset($respondentBreakdown[$key])) continue;
                    $rb = $respondentBreakdown[$key];
                    $rbRating = $engine->getScoreRating($rb['avg_score']);
                ?>
                <div style="text-align:center; padding:16px; border-radius:var(--radius-md); border:2px solid <?php echo $rbRating['color']; ?>30;">
                    <div style="font-size:28px;"><?php echo $respondentIcons[$key]; ?></div>
                    <div style="font-weight:700; margin-top:4px;"><?php echo e($label); ?></div>
                    <div style="font-size:24px; font-weight:700; color:<?php echo $rbRating['color']; ?>;"><?php echo $rb['avg_score']; ?>%</div>
                    <div style="font-size:12px; color:<?php echo $rbRating['color']; ?>;"><?php echo $rbRating['label']; ?></div>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?php echo $rb['count']; ?> استبيان</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- الاتجاه الزمني -->
<?php if (count($trendSurveys) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📈 الاتجاه الزمني للمناخ المدرسي</h3></div>
        <div class="card-body">
            <div style="height:280px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- بطاقات الأبعاد -->
<?php if (!empty($categoryScores)): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 ملخص الأبعاد</h3></div>
        <div class="card-body">
            <div class="grid grid-auto-lg">
                <?php foreach ($categoryScores as $catName => $catData): 
                    $catScore = $catData['total_weight'] > 0 ? round($catData['weighted_sum'] / $catData['total_weight'], 1) : 0;
                    $catRating = $engine->getScoreRating($catScore);
                ?>
                <div class="card" style="border-top:4px solid <?php echo $catRating['color']; ?>;">
                    <div class="card-body" style="text-align:center;">
                        <div style="font-size:13px; color:var(--text-secondary); margin-bottom:4px;"><?php echo e($catName); ?></div>
                        <div style="font-size:28px; font-weight:700; color:<?php echo $catRating['color']; ?>;"><?php echo $catScore; ?>%</div>
                        <div style="font-size:13px; color:<?php echo $catRating['color']; ?>;"><?php echo $catRating['label']; ?></div>
                        <?php echo $engine->renderScoreBar($catScore); ?>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?php echo $catData['count']; ?> عنصر</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- تفاصيل العناصر -->
<div class="section">
    <h3 class="section-title">تفاصيل الاستبيان</h3>
    <?php
    $currentCategory = '';
    foreach ($items as $item):
        if ($item['category_name'] !== $currentCategory):
            if ($currentCategory !== '') echo '</div></div>';
            $currentCategory = $item['category_name'];
    ?>
    <div class="card mb-4">
        <div class="card-header" style="background:#fd7e14; color:white;">
            <h4 style="margin:0; color:white; font-size:15px;">🌡️ <?php echo e($currentCategory); ?></h4>
        </div>
        <div class="card-body">
    <?php endif; ?>
            <div style="margin-bottom:16px;">
                <div class="flex justify-between items-center mb-1">
                    <span style="font-size:14px; font-weight:500;"><?php echo e($item['text_ar']); ?></span>
                    <span style="font-size:14px; font-weight:600;"><?php echo number_format($item['score'] ?? 0, 1); ?>%</span>
                </div>
                <?php echo $engine->renderScoreBar($item['score'] ?? 0); ?>
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    الأداة: <?php echo e($engine->getToolLabel($item['tool_type'])); ?>
                </div>
            </div>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($survey['notes']): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📝 ملاحظات</h3></div>
        <div class="card-body"><p><?php echo e($survey['notes']); ?></p></div>
    </div>
</div>
<?php endif; ?>

<?php
// بيانات الرسوم البيانية
$dimLabels = [];
$dimData = [];
foreach ($categoryScores as $catName => $catData) {
    $dimLabels[] = $catName;
    $dimData[] = $catData['total_weight'] > 0 ? round($catData['weighted_sum'] / $catData['total_weight'], 1) : 0;
}

// بيانات الاتجاه الزمني
$trendLabels = [];
$trendData = [];
foreach ($trendSurveys as $ts) {
    $trendLabels[] = formatDateAr($ts['survey_date'], 'short');
    $trendData[] = $ts['overall_score'] ?? 0;
}

$pageJs = "";

// رسم رادار
if (!empty($dimLabels)) {
    $pageJs .= "
        initChart('radarChart', 'radar', {
            labels: " . json_encode($dimLabels, JSON_UNESCAPED_UNICODE) . ",
            datasets: [{
                label: 'درجة البعد',
                data: " . json_encode($dimData) . ",
                backgroundColor: 'rgba(253, 126, 20, 0.2)',
                borderColor: 'rgba(253, 126, 20, 1)',
                pointBackgroundColor: 'rgba(253, 126, 20, 1)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgba(253, 126, 20, 1)',
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
    
    // رسم أعمدة أفقي
    $pageJs .= "
        eqasBarChart('dimensionBarChart', 'درجات الأبعاد', " . json_encode($dimLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة',
            data: " . json_encode($dimData) . ",
            backgroundColor: 'rgba(253, 126, 20, 0.6)',
            borderColor: 'rgba(253, 126, 20, 1)',
            borderWidth: 1
        }], {indexAxis: 'y', scales: {x: {beginAtZero: true, max: 100}}});
    ";
}

// رسم الاتجاه الزمني
if (count($trendSurveys) > 1) {
    $pageJs .= "
        eqasLineChart('trendChart', 'اتجاه المناخ المدرسي', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة الكلية',
            data: " . json_encode($trendData) . ",
            borderColor: '#fd7e14',
            fill: true
        }], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
