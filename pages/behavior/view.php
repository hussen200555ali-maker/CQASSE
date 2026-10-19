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

$userRole = $_SESSION['user']['role'] ?? '';
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$evalId = (int)($_GET['id'] ?? 0);
if (!$evalId) {
    setFlash('معرف التقييم غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات التقييم مع التحقق من ملكية المدرسة
$evaluation = null;
try {
    $stmt = $db->prepare("
        SELECT e.*, s.full_name_ar as student_name, s.student_number,
               c.name_ar as class_name, c.school_id as school_id,
               gl.name_ar as grade_name,
               u.full_name_ar as evaluator_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        JOIN classes c ON e.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        JOIN users u ON e.evaluated_by = u.id
        JOIN semesters sem ON e.semester_id = sem.id
        JOIN academic_years ay ON e.academic_year_id = ay.id
        WHERE e.eval_type = 'behavior_score' AND e.id = ? AND e.is_active = 1
    ");
    $stmt->execute([$evalId]);
    $evaluation = $stmt->fetch();
} catch (Exception $e) {}

if (!$evaluation) {
    setFlash('التقييم غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من ملكية المدرسة
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

// جلب التقييمات السابقة للمقارنة
$previousEvals = [];
try {
    $stmt = $db->prepare("
        SELECT id, e.created_at as evaluation_date, 
               COALESCE(e.current_percentage, e.combined_score) as overall_score 
        FROM evaluations e
        WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.id != ? AND e.is_active = 1
        ORDER BY e.created_at DESC LIMIT 5
    ");
    $stmt->execute([$evaluation['student_id'], $evalId]);
    $previousEvals = $stmt->fetchAll();
} catch (Exception $e) {}

// حساب الدرجة الكلية
$overallScore = $evaluation['current_percentage'] ?? $evaluation['combined_score'] ?? null;
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// جلب الحوادث السلوكية (العناصر التفصيلية)
$items = [];
try {
    $stmt = $db->prepare("
        SELECT bi.*, bc.name_ar as category_name, bc.points as category_points,
               u.full_name_ar as reported_by_name
        FROM behavior_incidents bi
        JOIN behavior_categories bc ON bi.category_id = bc.id
        LEFT JOIN users u ON bi.reported_by = u.id
        WHERE bi.student_id = ? AND bi.academic_year_id = ? AND bi.semester_id = ? AND bi.is_active = 1
        ORDER BY bi.incident_date DESC
    ");
    $stmt->execute([$evaluation['student_id'], $evaluation['academic_year_id'], $evaluation['semester_id']]);
    $items = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب درجات السلوك
$behaviorScore = null;
try {
    $stmt = $db->prepare("
        SELECT * FROM behavior_scores
        WHERE student_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$evaluation['student_id'], $evaluation['academic_year_id'], $evaluation['semester_id']]);
    $behaviorScore = $stmt->fetch();
} catch (Exception $e) {}
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
                    <div style="font-weight:600;"><?php echo formatDateAr($evaluation['created_at'] ?? null, 'medium'); ?></div>
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

<!-- الحوادث السلوكية -->
<div class="section">
    <h3 class="section-title">الحوادث السلوكية</h3>
    <?php if (empty($items)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:30px; color:var(--text-muted);">
            <div style="font-size:48px; margin-bottom:12px;">📋</div>
            <p>لا توجد حوادث سلوكية مسجلة لهذا الطالب في هذا الفصل.</p>
        </div>
    </div>
    <?php else: ?>
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
            <div style="margin-bottom:16px; padding:12px; background:var(--background); border-radius:var(--radius);">
                <div class="flex justify-between items-center mb-1">
                    <span style="font-size:14px; font-weight:500;"><?php echo e($item['description'] ?? 'بدون وصف'); ?></span>
                    <span style="font-size:14px; font-weight:600; color:<?php echo $item['points'] < 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                        <?php echo $item['points'] > 0 ? '+' : ''; ?><?php echo number_format($item['points'], 1); ?> نقطة
                    </span>
                </div>
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    التاريخ: <?php echo formatDateAr($item['incident_date'], 'short'); ?>
                    <?php if ($item['reported_by_name']): ?> | المبلّغ: <?php echo e($item['reported_by_name']); ?><?php endif; ?>
                </div>
            </div>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($behaviorScore): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 ملخص درجات السلوك</h3></div>
        <div class="card-body">
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">النقاط الابتدائية</span>
                    <div style="font-weight:600;"><?php echo number_format($behaviorScore['starting_points'], 1); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">النقاط المكتسبة</span>
                    <div style="font-weight:600; color:var(--success);">+<?php echo number_format($behaviorScore['earned_points'], 1); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">النقاط المخصومة</span>
                    <div style="font-weight:600; color:var(--danger);">-<?php echo number_format($behaviorScore['deducted_points'], 1); ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">النسبة الحالية</span>
                    <div style="font-weight:600;"><?php echo number_format($behaviorScore['current_percentage'], 1); ?>%</div>
                </div>
            </div>
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
