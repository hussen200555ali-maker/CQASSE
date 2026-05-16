<?php
$pageTitle = 'عرض التقييم الإشرافي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الإشرافي' => 'admin_index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$dateStr = $_GET['date'] ?? null;
$teacherId = (int)($_GET['teacher_id'] ?? 0);
$userId = $_SESSION['user']['id'];

if (!$dateStr || !$teacherId) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('admin_index.php'); }

$formId = 0;
$stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_admin' AND is_active = 1 LIMIT 1");
$stmt->execute();
$formId = $stmt->fetchColumn();
if (!$formId) { setFlash('نموذج التقييم غير موجود', 'danger'); redirect('admin_index.php'); }

$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
               AVG(ar.numeric_value) AS overall_score,
               u.full_name_ar AS teacher_name,
               ev.full_name_ar AS evaluator_name,
               sem.name_ar AS semester_name, ay.name AS year_name
        FROM assessment_responses ar
        JOIN users u ON ar.teacher_id = u.id
        JOIN users ev ON ar.responded_by = ev.id
        JOIN semesters sem ON ar.semester_id = sem.id
        JOIN academic_years ay ON ar.academic_year_id = ay.id
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.response_date = ? AND ar.is_active = 1
        GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
    ");
    $stmt->execute([$formId, $teacherId, $dateStr]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('admin_index.php'); }

$items = [];
$categoryScores = [];
try {
    $stmt = $db->prepare("
        SELECT ar.numeric_value AS score, ai.text_ar, ai.tool_type, ai.weight,
               ai.is_inverse, ai.max_value, ac.name_ar AS category_name
        FROM assessment_responses ar
        JOIN assessment_items ai ON ar.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.response_date = ? AND ar.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$formId, $teacherId, $dateStr]);
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        $catName = $item['category_name'];
        if (!isset($categoryScores[$catName])) {
            $categoryScores[$catName] = ['weighted_sum' => 0, 'total_weight' => 0];
        }
        $categoryScores[$catName]['weighted_sum'] += $item['weight'] * ($item['score'] ?? 0);
        $categoryScores[$catName]['total_weight'] += $item['weight'];
    }
} catch (Exception $e) {}

$overallScore = $evaluation['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// جلب درجات التقييم الذاتي والأقران للمقارنة
$selfScore = null;
$peerScore = null;
try {
    $selfFormId = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_self' AND is_active = 1 LIMIT 1");
    $selfFormId->execute();
    $sfid = $selfFormId->fetchColumn();
    if ($sfid) {
        $stmt = $db->prepare("
            SELECT AVG(ar.numeric_value) FROM assessment_responses ar
            WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.semester_id = ? AND ar.is_active = 1
        ");
        $stmt->execute([$sfid, $teacherId, $evaluation['semester_id']]);
        $selfScore = $stmt->fetchColumn() ?: null;
    }

    $peerFormId = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_peer' AND is_active = 1 LIMIT 1");
    $peerFormId->execute();
    $pfid = $peerFormId->fetchColumn();
    if ($pfid) {
        $stmt = $db->prepare("
            SELECT AVG(ar.numeric_value) FROM assessment_responses ar
            WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.semester_id = ? AND ar.is_active = 1
        ");
        $stmt->execute([$pfid, $teacherId, $evaluation['semester_id']]);
        $peerScore = $stmt->fetchColumn() ?: null;
    }
} catch (Exception $e) {}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">👔</span>
                <div>
                    <h3 class="card-title">التقييم الإشرافي - <?= e($evaluation['teacher_name']) ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);">المقيّم: <?= e($evaluation['evaluator_name']) ?></div>
                </div>
            </div>
            <a href="admin_index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
        </div>
        <div class="card-body">
            <div class="grid grid-3">
                <div><span style="font-size:12px; color:var(--text-muted);">المعلم</span><div style="font-weight:600;"><?= e($evaluation['teacher_name']) ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?= e($evaluation['semester_name']) ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الدرجة الكلية</span>
                    <div style="font-weight:700; font-size:20px; color:<?= $rating['color'] ?? 'inherit' ?>;">
                        <?= $overallScore !== null ? number_format($overallScore, 1) . '%' : '—' ?>
                    </div>
                    <?php if ($rating): ?><div style="color:<?= $rating['color'] ?>;"><?= $rating['label'] ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 مقارنة التقييمات (ذاتي / أقران / إشرافي)</h3></div>
        <div class="card-body" style="text-align:center;">
            <div style="max-width:600px; margin:0 auto; height:350px;">
                <canvas id="comparisonChart"></canvas>
            </div>
        </div>
    </div>
</div>

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
        <div class="card-header" style="background:var(--primary); color:white;">
            <h4 style="margin:0; color:white; font-size:15px;">👔 <?= e($currentCategory) ?></h4>
        </div>
        <div class="card-body">
    <?php endif; ?>
            <div style="margin-bottom:16px;">
                <div class="flex justify-between items-center mb-1">
                    <span style="font-size:14px; font-weight:500;"><?= e($item['text_ar']) ?></span>
                    <span style="font-size:14px; font-weight:600;"><?= number_format($item['score'] ?? 0, 1) ?>%</span>
                </div>
                <?= $engine->renderScoreBar($item['score'] ?? 0) ?>
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    الأداة: <?= e($engine->getToolLabel($item['tool_type'])) ?>
                    <?php if ($item['is_inverse']): ?> | <span style="color:var(--warning);">عكسي</span><?php endif; ?>
                    | الوزن: <?= number_format($item['weight'], 1) ?>
                </div>
            </div>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$compLabels = [];
$compSelfData = [];
$compPeerData = [];
$compAdminData = [];

foreach ($categoryScores as $catName => $catData) {
    $compLabels[] = $catName;
    $compAdminData[] = $catData['total_weight'] > 0 ? round($catData['weighted_sum'] / $catData['total_weight'], 1) : 0;
    $compSelfData[] = $selfScore ?? 0;
    $compPeerData[] = $peerScore ?? 0;
}

$pageJs = "
    initChart('comparisonChart', 'bar', {
        labels: " . json_encode($compLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [
            {
                label: 'التقييم الذاتي',
                data: " . json_encode($compSelfData) . ",
                backgroundColor: 'rgba(23, 162, 184, 0.6)',
                borderColor: 'rgba(23, 162, 184, 1)',
                borderWidth: 1
            },
            {
                label: 'تقييم الأقران',
                data: " . json_encode($compPeerData) . ",
                backgroundColor: 'rgba(111, 66, 193, 0.6)',
                borderColor: 'rgba(111, 66, 193, 1)',
                borderWidth: 1
            },
            {
                label: 'التقييم الإشرافي',
                data: " . json_encode($compAdminData) . ",
                backgroundColor: 'rgba(26, 86, 50, 0.6)',
                borderColor: 'rgba(26, 86, 50, 1)',
                borderWidth: 1
            }
        ]
    }, {
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                ticks: { stepSize: 20 }
            }
        },
        plugins: {
            legend: { position: 'top' }
        }
    });
";
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
