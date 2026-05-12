<?php
/**
 * ============================================================
 * عرض التقييم الذاتي - مع رسم رادار
 * Teaching Self-Evaluation View with Radar Chart
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض التقييم الذاتي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الذاتي' => 'self_index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('self_index.php'); }

$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT tse.*, sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_self_evaluations tse
        JOIN semesters sem ON tse.semester_id = sem.id
        JOIN academic_years ay ON tse.academic_year_id = ay.id
        WHERE tse.id = ? AND tse.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('self_index.php'); }

// التأكد أن المعلم يرى تقييماته فقط
if ($_SESSION['user']['role'] === 'teacher' && $evaluation['teacher_id'] != $_SESSION['user']['id']) {
    setFlash('ليس لديك صلاحية لعرض هذا التقييم', 'danger');
    redirect('self_index.php');
}

$items = [];
$categoryScores = [];
try {
    $stmt = $db->prepare("
        SELECT tsi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value, ac.name_ar as category_name
        FROM teaching_self_items tsi JOIN assessment_items ai ON tsi.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
        WHERE tsi.evaluation_id = ? AND tsi.is_active = 1 ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
    $items = $stmt->fetchAll();
    
    // حساب متوسط كل فئة
    foreach ($items as $item) {
        $catName = $item['category_name'];
        if (!isset($categoryScores[$catName])) {
            $categoryScores[$catName] = ['total' => 0, 'count' => 0, 'weighted_sum' => 0, 'total_weight' => 0];
        }
        $categoryScores[$catName]['weighted_sum'] += $item['weight'] * ($item['score'] ?? 0);
        $categoryScores[$catName]['total_weight'] += $item['weight'];
        $categoryScores[$catName]['count']++;
    }
} catch (Exception $e) {}

$overallScore = $evaluation['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// تحضير بيانات رسم الرادار
$radarLabels = [];
$radarData = [];
foreach ($categoryScores as $catName => $catData) {
    $radarLabels[] = $catName;
    $radarData[] = $catData['total_weight'] > 0 ? round($catData['weighted_sum'] / $catData['total_weight'], 1) : 0;
}
?>

<!-- معلومات التقييم -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">🪞</span>
                <div>
                    <h3 class="card-title">التقييم الذاتي للتدريس</h3>
                    <div style="font-size:13px; color:var(--text-secondary);"><?php echo e($evaluation['semester_name'] . ' - ' . $evaluation['year_name']); ?></div>
                </div>
            </div>
            <a href="self_index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
        </div>
        <div class="card-body">
            <div class="grid grid-3">
                <div><span style="font-size:12px; color:var(--text-muted);">التاريخ</span><div style="font-weight:600;"><?php echo formatDateAr($evaluation['evaluation_date'], 'medium'); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?php echo e($evaluation['semester_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الدرجة الكلية</span>
                    <div style="font-weight:700; font-size:20px; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                        <?php echo $overallScore !== null ? number_format($overallScore, 1) . '%' : '—'; ?>
                    </div>
                    <?php if ($rating): ?>
                        <div style="color:<?php echo $rating['color']; ?>;"><?php echo $rating['label']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- رسم الرادار -->
<?php if (!empty($radarLabels)): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 رسم الرادار</h3></div>
        <div class="card-body" style="text-align:center;">
            <div style="max-width:500px; margin:0 auto; height:400px;">
                <canvas id="radarChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- عناصر التقييم -->
<div class="section">
    <h3 class="section-title">تفاصيل التقييم</h3>
    <?php
    $currentCategory = '';
    foreach ($items as $item):
        if ($item['category_name'] !== $currentCategory):
            if ($currentCategory !== '') echo '</div></div>';
            $currentCategory = $item['category_name'];
    ?>
    <div class="card mb-4">
        <div class="card-header" style="background:var(--info); color:white;">
            <h4 style="margin:0; color:white; font-size:15px;">🪞 <?php echo e($currentCategory); ?></h4>
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
// جافاسكربت لرسم الرادار
$pageJs = "
    initChart('radarChart', 'radar', {
        labels: " . json_encode($radarLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
            label: 'التقييم الذاتي',
            data: " . json_encode($radarData) . ",
            backgroundColor: 'rgba(23, 162, 184, 0.2)',
            borderColor: 'rgba(23, 162, 184, 1)',
            pointBackgroundColor: 'rgba(23, 162, 184, 1)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: 'rgba(23, 162, 184, 1)',
            borderWidth: 2
        }]
    }, {
        scales: {
            r: {
                beginAtZero: true,
                max: 100,
                ticks: { stepSize: 20 },
                pointLabels: { font: { size: 12 } }
            }
        }
    });
";
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
