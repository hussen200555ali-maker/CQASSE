<?php
/**
 * ============================================================
 * التقييم الذاتي للتدريس - فهرس
 * Teaching Self-Evaluation Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'التقييم الذاتي للتدريس';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الذاتي' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];

// جلب تقييمات المعلم الذاتية
$evaluations = [];
try {
    $stmt = $db->prepare("
        SELECT tse.*, sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_self_evaluations tse
        JOIN semesters sem ON tse.semester_id = sem.id
        JOIN academic_years ay ON tse.academic_year_id = ay.id
        WHERE tse.teacher_id = ? AND tse.is_active = 1
        ORDER BY tse.evaluation_date DESC
    ");
    $stmt->execute([$userId]);
    $evaluations = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">التقييمات الذاتية</h3>
        <a href="self_evaluate.php" class="btn btn-primary">🪞 تقييم ذاتي جديد</a>
    </div>

    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($evaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">🪞</div>
                    <div class="table-empty-text">لا توجد تقييمات ذاتية</div>
                    <p style="color:var(--text-muted); font-size:14px;">ابدأ بإجراء تقييم ذاتي لأدائك التدريسي</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الفصل الدراسي</th>
                                <th>السنة الدراسية</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>التقدير</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $i => $eval): 
                                $rating = $eval['overall_score'] !== null ? $engine->getScoreRating($eval['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo e($eval['year_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td style="color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $rating['label'] ?? '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="self_view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info">👁️</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
