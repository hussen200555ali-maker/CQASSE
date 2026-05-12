<?php
/**
 * ============================================================
 * التقييم الإشرافي - فهرس
 * Administrative Evaluation Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'التقييم الإشرافي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم الإشرافي' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$evaluations = [];
try {
    $sql = "SELECT tae.*, u.full_name_ar as teacher_name, ev.full_name_ar as evaluator_name,
            sem.name_ar as semester_name, ay.name as year_name
            FROM teaching_admin_evaluations tae
            JOIN users u ON tae.teacher_id = u.id
            JOIN users ev ON tae.evaluator_id = ev.id
            JOIN semesters sem ON tae.semester_id = sem.id
            JOIN academic_years ay ON tae.academic_year_id = ay.id
            WHERE tae.is_active = 1";
    $params = [];
    if ($schoolId && $_SESSION['user']['role'] !== 'group_admin') {
        $sql .= " AND u.school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY tae.evaluation_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">التقييمات الإشرافية</h3>
        <a href="admin_evaluate.php" class="btn btn-primary">👔 تقييم إشرافي جديد</a>
    </div>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($evaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">👔</div>
                    <div class="table-empty-text">لا توجد تقييمات إشرافية</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المعلم</th>
                                <th>المقيّم الإداري</th>
                                <th>الفصل الدراسي</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $i => $eval): 
                                $rating = $eval['overall_score'] !== null ? $engine->getScoreRating($eval['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td style="font-weight:600;"><?php echo e($eval['teacher_name']); ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="admin_view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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
