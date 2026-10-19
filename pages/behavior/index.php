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
require_once __DIR__ . '/../../includes/filters.php';

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
$filterSemester = $_GET['semester_id'] ?? '0';

// بناء الاستعلام
$sql = "SELECT e.*, s.full_name_ar as student_name, s.student_number,
        c.name_ar as class_name, u.full_name_ar as evaluator_name,
        sem.name_ar as semester_name
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        JOIN classes c ON e.class_id = c.id
        JOIN users u ON e.evaluated_by = u.id
        JOIN semesters sem ON e.semester_id = sem.id
        WHERE e.eval_type = 'behavior_score' AND e.is_active = 1";
$params = [];

// فلتر حسب المدرسة أو فصول المعلم المسندة
if ($userRole === 'teacher') {
    $allowedClassIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!empty($allowedClassIds)) {
        $ph = implode(',', array_fill(0, count($allowedClassIds), '?'));
        $sql .= " AND (e.evaluated_by = ? OR e.class_id IN ($ph))";
        $params[] = $userId;
        $params = array_merge($params, $allowedClassIds);
    } else {
        $sql .= " AND e.evaluated_by = ?";
        $params[] = $userId;
    }
} elseif ($schoolId) {
    $sql .= " AND e.class_id IN (SELECT id FROM classes WHERE school_id = ?)";
    $params[] = $schoolId;
}

if ($filterClass) {
    $sql .= " AND e.class_id = ?";
    $params[] = $filterClass;
}
if ($filterStudent) {
    $sql .= " AND e.student_id = ?";
    $params[] = $filterStudent;
}
if ($filterSemester && $filterSemester !== '0') {
    $sq = buildSemesterSubquery($filterSemester, 'e');
    $sql .= $sq['sql'];
    $params = array_merge($params, $sq['params']);
}

$sql .= " ORDER BY e.created_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll();
} catch (Exception $e) {
    $evaluations = [];
}

// جلب الفصول للفلتر — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $classSql = "SELECT c.id, c.name_ar FROM classes c WHERE c.id IN ($ph) AND c.is_active = 1 ORDER BY c.name_ar";
            $stmt = $db->prepare($classSql);
            $stmt->execute($allowedIds);
            $classes = $stmt->fetchAll();
        }
    } else {
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
    }
} catch (Exception $e) {
    $classes = [];
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
                        <option value="0" <?php echo $filterSemester == '0' ? 'selected' : ''; ?>>جميع الفصول</option>
                        <option value="1" <?php echo $filterSemester == '1' ? 'selected' : ''; ?>>الفصل الدراسي الأول</option>
                        <option value="2" <?php echo $filterSemester == '2' ? 'selected' : ''; ?>>الفصل الدراسي الثاني</option>
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
                                $score = $eval['current_percentage'] ?? $eval['combined_score'] ?? null;
                                $rating = $score !== null ? $engine->getScoreRating($score) : null;
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
                                <td><?php echo formatDateAr($eval['created_at'] ?? $eval['evaluation_date'] ?? null, 'short'); ?></td>
                                <td>
                                    <?php if ($rating): ?>
                                        <span style="font-weight:700; color:<?php echo $rating['color']; ?>;">
                                            <?php echo number_format($score, 1); ?>%
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
