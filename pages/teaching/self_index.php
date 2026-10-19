<?php
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

$evaluations = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_self' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $stmt->execute();
    $formId = $stmt->fetchColumn();

    if ($formId) {
        $stmt = $db->prepare("
            SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
                   AVG(ar.numeric_value) AS overall_score,
                   sem.name_ar AS semester_name, ay.name AS year_name
            FROM assessment_responses ar
            JOIN semesters sem ON ar.semester_id = sem.id
            JOIN academic_years ay ON ar.academic_year_id = ay.id
            WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.responded_by = ? AND ar.is_active = 1
            GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
            ORDER BY ar.response_date DESC
        ");
        $stmt->execute([$formId, $userId, $userId]);
        $evaluations = $stmt->fetchAll();
    }
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
                                $dateParam = urlencode($eval['response_date']);
                            ?>
                            <tr>
                                <td><?= $i + 1; ?></td>
                                <td><?= e($eval['semester_name']); ?></td>
                                <td><?= e($eval['year_name']); ?></td>
                                <td><?= formatDateAr($eval['response_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?= $rating['color'] ?? 'inherit'; ?>;">
                                    <?= $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td style="color:<?= $rating['color'] ?? 'inherit'; ?>;">
                                    <?= $rating['label'] ?? '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="self_view.php?date=<?= $dateParam ?>" class="btn btn-sm btn-info">👁️</a>
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
