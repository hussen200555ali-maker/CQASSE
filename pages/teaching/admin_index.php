<?php
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

$formId = 0;
$stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'teaching_admin' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
$stmt->execute();
$formId = $stmt->fetchColumn();

$evaluations = [];
if ($formId) {
    try {
        $sql = "
            SELECT ar.response_date, ar.academic_year_id, ar.semester_id,
                   ar.teacher_id, AVG(ar.numeric_value) AS overall_score,
                   u.full_name_ar AS teacher_name,
                   ev.full_name_ar AS evaluator_name,
                   sem.name_ar AS semester_name, ay.name AS year_name
            FROM assessment_responses ar
            JOIN users u ON ar.teacher_id = u.id
            JOIN users ev ON ar.responded_by = ev.id
            JOIN semesters sem ON ar.semester_id = sem.id
            JOIN academic_years ay ON ar.academic_year_id = ay.id
            WHERE ar.form_id = ? AND ar.is_active = 1";
        $params = [$formId];
        if ($schoolId && $_SESSION['user']['role'] !== 'group_admin') {
            $sql .= " AND u.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " GROUP BY ar.teacher_id, ar.responded_by, ar.response_date, ar.academic_year_id, ar.semester_id
                  ORDER BY ar.response_date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $evaluations = $stmt->fetchAll();
    } catch (Exception $e) {}
}
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
                                $dateParam = urlencode($eval['response_date']);
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td style="font-weight:600;"><?php echo e($eval['teacher_name']); ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo formatDateAr($eval['response_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $eval['overall_score'] !== null ? number_format($eval['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td class="actions">
                                    <a href="admin_view.php?date=<?php echo $dateParam ?>&teacher_id=<?php echo $eval['teacher_id']; ?>" class="btn btn-sm btn-info">👁️</a>
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
