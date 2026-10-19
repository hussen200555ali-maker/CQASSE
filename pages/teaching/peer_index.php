<?php
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

$formId = 0;
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_peer' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $stmt->execute();
    $formId = $stmt->fetchColumn();
} catch (Exception $e) {}

$myEvaluations = [];
$receivedEvaluations = [];

if ($formId) {
    try {
        $stmt = $db->prepare("
            SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
                   ar.teacher_id, AVG(ar.numeric_value) AS overall_score,
                   u.full_name_ar AS teacher_name,
                   sem.name_ar AS semester_name, ay.name AS year_name
            FROM assessment_responses ar
            JOIN users u ON ar.teacher_id = u.id
            JOIN semesters sem ON ar.semester_id = sem.id
            JOIN academic_years ay ON ar.academic_year_id = ay.id
            WHERE ar.form_id = ? AND ar.responded_by = ? AND ar.is_active = 1
            GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
            ORDER BY ar.response_date DESC
        ");
        $stmt->execute([$formId, $userId]);
        $myEvaluations = $stmt->fetchAll();
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("
            SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
                   ar.responded_by AS evaluator_id, AVG(ar.numeric_value) AS overall_score,
                   u.full_name_ar AS evaluator_name,
                   sem.name_ar AS semester_name, ay.name AS year_name
            FROM assessment_responses ar
            JOIN users u ON ar.responded_by = u.id
            JOIN semesters sem ON ar.semester_id = sem.id
            JOIN academic_years ay ON ar.academic_year_id = ay.id
            WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.is_active = 1
            GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
            ORDER BY ar.response_date DESC
        ");
        $stmt->execute([$formId, $userId]);
        $receivedEvaluations = $stmt->fetchAll();
    } catch (Exception $e) {}
}
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
                                $dateParam = urlencode($eval['response_date']);
                            ?>
                            <tr>
                                <td><?= $i + 1; ?></td>
                                <td style="font-weight:600;"><?= e($eval['teacher_name']); ?></td>
                                <td><?= e($eval['semester_name']); ?></td>
                                <td><?= formatDateAr($eval['response_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?= $rating['color'] ?? 'inherit'; ?>;">
                                    <?= $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="peer_view.php?date=<?= $dateParam ?>&teacher_id=<?= $eval['teacher_id'] ?? '' ?>" class="btn btn-sm btn-info">👁️</a>
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
                                $dateParam = urlencode($eval['response_date']);
                            ?>
                            <tr>
                                <td><?= $i + 1; ?></td>
                                <td><?= e($eval['evaluator_name']); ?></td>
                                <td><?= e($eval['semester_name']); ?></td>
                                <td><?= formatDateAr($eval['response_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?= $rating['color'] ?? 'inherit'; ?>;">
                                    <?= $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="peer_view.php?date=<?= $dateParam ?>&evaluator_id=<?= $eval['evaluator_id'] ?? '' ?>" class="btn btn-sm btn-info">👁️</a>
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
