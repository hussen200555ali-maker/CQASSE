<?php
/**
 * ============================================================
 * عرض تقييم سلوكي - View Behavior Evaluation
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض التقييم السلوكي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم السلوكي' => 'index.php', 'عرض التقييم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    setFlash('معرف التقييم غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات التقييم
$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT be.*, s.full_name_ar as student_name, s.student_number,
               c.name_ar as class_name, gl.name_ar as grade_name,
               u.full_name_ar as evaluator_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM behavior_scores be
        JOIN students s ON be.student_id = s.id
        JOIN classes c ON be.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        JOIN users u ON be.evaluator_id = u.id
        JOIN semesters sem ON be.semester_id = sem.id
        JOIN academic_years ay ON be.academic_year_id = ay.id
        WHERE be.id = ? AND be.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) {
    setFlash('التقييم غير موجود', 'danger');
    redirect('index.php');
}

// جلب عناصر التقييم
$items = [];
try {
    $stmt = $db->prepare("
        SELECT bi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value,
               ac.name_ar as category_name
        FROM behavior_items bi
        JOIN assessment_items ai ON bi.item_id = ai.id
        JOIN assessment_criteria ac ON ai.criteria_id = ac.id  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
        WHERE bi.evaluation_id = ? AND bi.is_active = 1
        ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$evalId]);
    $items = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب التقييمات السابقة للمقارنة
$previousEvals = [];
try {
    $stmt = $db->prepare("
        SELECT id, evaluation_date, overall_score 
        FROM behavior_scores 
        WHERE student_id = ? AND id != ? AND is_active = 1
        ORDER BY evaluation_date DESC LIMIT 5
    ");
    $stmt->execute([$evaluation['student_id'], $evalId]);
    $previousEvals = $stmt->fetchAll();
} catch (Exception $e) {}

// حساب الدرجة الكلية
$overallScore = $evaluation['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;
?>

<!-- معلومات الطالب -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">🎯</span>
                <div>
                    <h3 class="card-title">تقييم سلوكي - <?php echo e($evaluation['student_name']); ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);">
                        <?php echo e($evaluation['grade_name'] . ' - ' . $evaluation['class_name']); ?>
                    </div>
                </div>
            </div>
            <a href="index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الطالب</span>
                    <div style="font-weight:600;"><?php echo e($evaluation['student_name']); ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?php echo e($evaluation['student_number']); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">المقيّم</span>
                    <div style="font-weight:600;"><?php echo e($evaluation['evaluator_name']); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span>
                    <div style="font-weight:600;"><?php echo e($evaluation['semester_name']); ?></div>
                    <div style="font-size:12px; color:var(--text-muted);"><?php echo e($evaluation['year_name']); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">التاريخ</span>
                    <div style="font-weight:600;"><?php echo formatDateAr($evaluation['evaluation_date'], 'medium'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- الدرجة الكلية -->
<div class="section">
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
</div>

<!-- عناصر التقييم بالأشرطة المرئية -->
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
            <h4 style="margin:0; color:white; font-size:15px;"><?php echo e($currentCategory); ?></h4>
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
                    | الوزن: <?php echo number_format($item['weight'], 1); ?>
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
        <div class="card-body">
            <p><?php echo e($evaluation['notes']); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- مقارنة مع التقييمات السابقة -->
<?php if (!empty($previousEvals)): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 مقارنة مع التقييمات السابقة</h3></div>
        <div class="card-body" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>الدرجة الكلية</th>
                        <th>التقدير</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previousEvals as $prev): 
                        $prevRating = $prev['overall_score'] !== null ? $engine->getScoreRating($prev['overall_score']) : null;
                    ?>
                    <tr>
                        <td><?php echo formatDateAr($prev['evaluation_date'], 'medium'); ?></td>
                        <td style="font-weight:700; color:<?php echo $prevRating['color'] ?? 'inherit'; ?>;">
                            <?php echo $prev['overall_score'] !== null ? number_format($prev['overall_score'], 1) . '%' : '—'; ?>
                        </td>
                        <td style="color:<?php echo $prevRating['color'] ?? 'inherit'; ?>;"><?php echo $prevRating['label'] ?? '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
