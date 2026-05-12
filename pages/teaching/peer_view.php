<?php
/**
 * ============================================================
 * عرض تقييم القرين - Peer Evaluation View
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض تقييم القرين';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'تقييم الأقران' => 'peer_index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) { setFlash('معرف التقييم غير صالح', 'danger'); redirect('peer_index.php'); }

$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT tpe.*, u.full_name_ar as teacher_name, ev.full_name_ar as evaluator_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_peer_evaluations tpe
        JOIN users u ON tpe.teacher_id = u.id
        JOIN users ev ON tpe.evaluator_id = ev.id
        JOIN semesters sem ON tpe.semester_id = sem.id
        JOIN academic_years ay ON tpe.academic_year_id = ay.id
        WHERE tpe.id = ? AND tpe.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) { setFlash('التقييم غير موجود', 'danger'); redirect('peer_index.php'); }

$items = [];
try {
    $stmt = $db->prepare("
        SELECT tpi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value, ac.name_ar as category_name
        FROM teaching_peer_items tpi JOIN assessment_items ai ON tpi.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
        WHERE tpi.evaluation_id = ? AND tpi.is_active = 1 ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
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
                    <h3 class="card-title">تقييم قرين - <?php echo e($evaluation['teacher_name']); ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);">المقيّم: <?php echo e($evaluation['evaluator_name']); ?></div>
                </div>
            </div>
            <a href="peer_index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
        </div>
        <div class="card-body">
            <div class="grid grid-3">
                <div><span style="font-size:12px; color:var(--text-muted);">المعلم</span><div style="font-weight:600;"><?php echo e($evaluation['teacher_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?php echo e($evaluation['semester_name']); ?></div></div>
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

<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:20px;">
            <?php echo $engine->renderScoreBar($overallScore ?? 0, 'الدرجة الكلية'); ?>
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
            <h4 style="margin:0; color:white; font-size:15px;">👥 <?php echo e($currentCategory); ?></h4>
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

<?php if ($evaluation['notes']): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📝 ملاحظات</h3></div>
        <div class="card-body"><p><?php echo e($evaluation['notes']); ?></p></div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
