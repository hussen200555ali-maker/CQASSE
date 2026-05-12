<?php
/**
 * ============================================================
 * التقييم السلوكي - قائمة التقييمات
 * Behavior Evaluation List
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'التقييم السلوكي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم السلوكي' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userRole = $_SESSION['user']['role'];
$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3
$schoolId = $_SESSION['user']['school_id'] ?? null;

// جلب الفلاتر
$filterClass = $_GET['class_id'] ?? '';
$filterStudent = $_GET['student_id'] ?? '';
$filterSemester = $_GET['semester_id'] ?? '';

// بناء الاستعلام
$sql = "SELECT be.*, s.full_name_ar as student_name, s.student_number,
        c.name_ar as class_name, u.full_name_ar as evaluator_name,
        sem.name_ar as semester_name
        FROM behavior_scores be
        JOIN students s ON be.student_id = s.id
        JOIN classes c ON be.class_id = c.id
        JOIN users u ON be.evaluator_id = u.id
        JOIN semesters sem ON be.semester_id = sem.id
        WHERE be.is_active = 1";
$params = [];

// فلتر حسب المدرسة
if ($schoolId && $userRole !== 'group_admin') {
    $sql .= " AND be.class_id IN (SELECT id FROM classes WHERE school_id = ?)";
    $params[] = $schoolId;
}

// فلتر حسب المعلم (يرى فقط تقييماته)
if ($userRole === 'teacher') {
    $sql .= " AND be.evaluator_id = ?";
    $params[] = $userId;
}

if ($filterClass) {
    $sql .= " AND be.class_id = ?";
    $params[] = $filterClass;
}
if ($filterStudent) {
    $sql .= " AND be.student_id = ?";
    $params[] = $filterStudent;
}
if ($filterSemester) {
    $sql .= " AND be.semester_id = ?";
    $params[] = $filterSemester;
}

$sql .= " ORDER BY be.evaluation_date DESC, be.created_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll();
} catch (Exception $e) {
    $evaluations = [];
}

// جلب الفصول للفلتر
$classes = [];
try {
    $classSql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
    $classParams = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $classSql .= " AND school_id = ?";
        $classParams[] = $schoolId;
    }
    $classSql .= " ORDER BY name_ar";
    $stmt = $db->prepare($classSql);
    $stmt->execute($classParams);
    $classes = $stmt->fetchAll();
} catch (Exception $e) {
    $classes = [];
}

// جلب الفصول الدراسية
$semesters = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {
    $semesters = [];
}
?>

<!-- فلاتر البحث -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔍 فلتر البحث</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline">
                <div class="form-group">
                    <label class="form-label">الفصل:</label>
                    <select name="class_id" class="form-control" style="width:auto;">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>" <?php echo $filterClass == $cls['id'] ? 'selected' : ''; ?>><?php echo e($cls['name_ar']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي:</label>
                    <select name="semester_id" class="form-control" style="width:auto;">
                        <option value="">الكل</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?php echo $sem['id']; ?>" <?php echo $filterSemester == $sem['id'] ? 'selected' : ''; ?>><?php echo e($sem['name_ar']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">بحث</button>
                <a href="index.php" class="btn btn-light">إعادة تعيين</a>
            </form>
        </div>
    </div>
</div>

<!-- قائمة التقييمات -->
<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">تقييمات السلوك</h3>
        <a href="evaluate.php" class="btn btn-primary">➕ تقييم جديد</a>
    </div>

    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($evaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">🎯</div>
                    <div class="table-empty-text">لا توجد تقييمات سلوكية</div>
                    <p style="color:var(--text-muted); font-size:14px;">ابدأ بإضافة تقييم سلوكي جديد</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>الفصل الدراسي</th>
                                <th>المقيّم</th>
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
                                <td>
                                    <div style="font-weight:600;"><?php echo e($eval['student_name']); ?></div>
                                    <div style="font-size:11px; color:var(--text-muted);"><?php echo e($eval['student_number']); ?></div>
                                </td>
                                <td><?php echo e($eval['class_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td>
                                    <?php if ($rating): ?>
                                        <span style="font-weight:700; color:<?php echo $rating['color']; ?>;">
                                            <?php echo number_format($eval['overall_score'], 1); ?>%
                                        </span>
                                        <div style="font-size:11px; color:<?php echo $rating['color']; ?>;"><?php echo $rating['label']; ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
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
