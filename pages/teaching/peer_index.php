<?php
/**
 * ============================================================
 * تقييم الأقران - فهرس
 * Peer Evaluation Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'تقييم الأقران';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'تقييم الأقران' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// التقييمات التي قمت بها
$myEvaluations = [];
try {
    $stmt = $db->prepare("
        SELECT tpe.*, u.full_name_ar as teacher_name, sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_peer_evaluations tpe
        JOIN users u ON tpe.teacher_id = u.id
        JOIN semesters sem ON tpe.semester_id = sem.id
        JOIN academic_years ay ON tpe.academic_year_id = ay.id
        WHERE tpe.evaluator_id = ? AND tpe.is_active = 1
        ORDER BY tpe.evaluation_date DESC
    ");
    $stmt->execute([$userId]);
    $myEvaluations = $stmt->fetchAll();
} catch (Exception $e) {}

// التقييمات التي تمت عليّ
$receivedEvaluations = [];
try {
    $stmt = $db->prepare("
        SELECT tpe.*, u.full_name_ar as evaluator_name, sem.name_ar as semester_name, ay.name as year_name
        FROM teaching_peer_evaluations tpe
        JOIN users u ON tpe.evaluator_id = u.id
        JOIN semesters sem ON tpe.semester_id = sem.id
        JOIN academic_years ay ON tpe.academic_year_id = ay.id
        WHERE tpe.teacher_id = ? AND tpe.is_active = 1
        ORDER BY tpe.evaluation_date DESC
    ");
    $stmt->execute([$userId]);
    $receivedEvaluations = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<!-- التقييمات التي قمت بها -->
<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">التقييمات التي قمت بها</h3>
        <a href="peer_evaluate.php" class="btn btn-primary">👥 تقييم قرين جديد</a>
    </div>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($myEvaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">👥</div>
                    <div class="table-empty-text">لم تقم بأي تقييمات أقران بعد</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المعلم المقيّم</th>
                                <th>الفصل الدراسي</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myEvaluations as $i => $eval): 
                                $rating = $eval['overall_score'] !== null ? $engine->getScoreRating($eval['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td style="font-weight:600;"><?php echo e($eval['teacher_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="peer_view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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

<!-- التقييمات التي تلقيتها -->
<div class="section">
    <h3 class="section-title">التقييمات التي تلقيتها</h3>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($receivedEvaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">📨</div>
                    <div class="table-empty-text">لم تتلق أي تقييمات أقران بعد</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المقيّم</th>
                                <th>الفصل الدراسي</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivedEvaluations as $i => $eval): 
                                $rating = $eval['overall_score'] !== null ? $engine->getScoreRating($eval['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="peer_view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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
