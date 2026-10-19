<?php
$pageTitle = 'عرض تقييم القرين';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'تقييم الأقران' => 'peer_index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$dateStr = $_GET['date'] ?? null;
$teacherId = (int)($_GET['teacher_id'] ?? 0);
$evaluatorId = (int)($_GET['evaluator_id'] ?? 0);
$userId = $_SESSION['user']['id'];

if (!$dateStr) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('peer_index.php'); }

// للمشاهدة: إما أن المستخدم هو المقيّم (my evaluation) أو المقيَّم (received)
$viewTeacherId = $teacherId ?: $userId;
$viewRespondedBy = $evaluatorId ?: $userId;

$formId = 0;
$stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_peer' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
$stmt->execute();
$formId = $stmt->fetchColumn();
if (!$formId) { setFlash('نموذج التقييم غير موجود', 'danger'); redirect('peer_index.php'); }

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
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
        GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
    ");
    $stmt->execute([$formId, $viewTeacherId, $viewRespondedBy, $dateStr]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('peer_index.php'); }

// التحقق من أن المعلم والمقيّم ينتميان لنفس المدرسة
$schoolId = $_SESSION['user']['school_id'] ?? null;
if ($schoolId && $viewTeacherId) {
    $checkTeacher = $db->prepare("SELECT 1 FROM users WHERE id = ? AND school_id = ? AND is_active = 1");
    $checkTeacher->execute([$viewTeacherId, $schoolId]);
    if (!$checkTeacher->fetch()) {
        setFlash('المعلم المحدد غير موجود في مدرستك', 'danger');
        redirect('peer_index.php');
    }
}

$items = [];
try {
    $stmt = $db->prepare("
        SELECT ar.numeric_value AS score, ai.text_ar, ai.tool_type, ai.weight,
               ai.is_inverse, ai.max_value, ac.name_ar AS category_name
        FROM assessment_responses ar
        JOIN assessment_structure ai ON ar.item_id = ai.id
        JOIN assessment_structure ac ON ai.parent_id = ac.id
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ?
          AND ar.response_date = ? AND ar.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$formId, $viewTeacherId, $viewRespondedBy, $dateStr]);
    $items = $stmt->fetchAll();
} catch (Exception $e) {}

$overallScore = $evaluation['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">👥</span>
                <div>
                    <h3 class="card-title">تقييم قرين - <?= e($evaluation['teacher_name']) ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);">المقيّم: <?= e($evaluation['evaluator_name']) ?></div>
                </div>
            </div>
            <a href="peer_index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
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
        <div class="card-body" style="text-align:center; padding:20px;">
            <?= $engine->renderScoreBar($overallScore ?? 0, 'الدرجة الكلية') ?>
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
        <div class="card-header" style="background:#6f42c1; color:white;">
            <h4 style="margin:0; color:white; font-size:15px;">👥 <?= e($currentCategory) ?></h4>
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
                </div>
            </div>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
