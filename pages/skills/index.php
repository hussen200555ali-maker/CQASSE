<?php
/**
 * ============================================================
 * قائمة المهارات - فهرس تقييمات المهارات
 * Skills Checklist Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'قائمة المهارات';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'قائمة المهارات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$filterClass = $_GET['class_id'] ?? '';
$filterStudent = $_GET['student_id'] ?? '';
$filterSemester = $_GET['semester_id'] ?? '0';

// الفصول للفلتر — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql = "SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.id IN ($ph) AND c.is_active = 1 ORDER BY gl.level_number, c.name_ar";
            $stmt = $db->prepare($sql); $stmt->execute($allowedIds);
            $classes = $stmt->fetchAll();
        }
    } else {
        $sql = "SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.is_active = 1";
        $p = [];
        if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND c.school_id = ?"; $p[] = $schoolId; }
        $sql .= " ORDER BY gl.level_number, c.name_ar";
        $stmt = $db->prepare($sql); $stmt->execute($p);
        $classes = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// جلب التقييمات — للمعلم: فقط تقييماته أو تقييمات طلاب فصوله
$sql = "SELECT e.*, s.full_name_ar as student_name, s.student_number,
        c.name_ar as class_name, u.full_name_ar as evaluator_name,
        sem.name_ar as semester_name
        FROM evaluations e
        JOIN students s ON e.student_id = s.id
        JOIN classes c ON e.class_id = c.id
        JOIN users u ON e.evaluated_by = u.id
        JOIN semesters sem ON e.semester_id = sem.id
        WHERE e.eval_type = 'skills_checklist' AND e.is_active = 1";
$params = [];

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
} elseif ($schoolId && $userRole !== 'group_admin') {
    $sql .= " AND c.school_id = ?";
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

// الإحصائيات
$totalEvaluations = count($evaluations);
$scores = array_filter(array_column($evaluations, 'combined_score'), function($v) { return $v !== null; });
$avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;
$highScore = count($scores) > 0 ? max($scores) : 0;
$lowScore = count($scores) > 0 ? min($scores) : 0;

// الطلاب المتميزون (خبير)
function getSkillLevelInfo($score) {
    if ($score >= 80) return ['label' => 'متقن', 'color' => '#28a745', 'icon' => '🌟', 'level' => 4];
    if ($score >= 60) return ['label' => 'جيد', 'color' => '#17a2b8', 'icon' => '✅', 'level' => 3];
    if ($score >= 40) return ['label' => 'مبتدئ', 'color' => '#ffc107', 'icon' => '🔸', 'level' => 2];
    return ['label' => 'يحتاج تطوير', 'color' => '#dc3545', 'icon' => '⚠️', 'level' => 1];
}
$topStudents = array_filter($evaluations, function($e) { return ($e['combined_score'] ?? 0) >= 75; });
usort($topStudents, function($a, $b) { return ($b['combined_score'] ?? 0) - ($a['combined_score'] ?? 0); });
$topStudents = array_slice($topStudents, 0, 5);

// بيانات الرسوم البيانية
$chartLabels = []; $chartData = []; $chartColors = [];
$levels = ['يحتاج تطوير', 'مبتدئ', 'جيد', 'متقن'];
$levelColors = ['#dc3545', '#ffc107', '#17a2b8', '#28a745'];
$levelCounts = array_fill(0, 4, 0);
foreach ($evaluations as $e) {
    $info = getSkillLevelInfo($e['combined_score'] ?? 0);
    $levelCounts[$info['level'] - 1]++;
}
foreach ($levels as $i => $lbl) {
    if ($levelCounts[$i] > 0) {
        $chartLabels[] = $lbl;
        $chartData[] = $levelCounts[$i];
        $chartColors[] = $levelColors[$i];
    }
}

$classAvgLabels = []; $classAvgData = [];
$classSums = []; $classCounts = [];
foreach ($evaluations as $e) {
    $cn = $e['class_name'] ?? '—';
    if (!isset($classSums[$cn])) { $classSums[$cn] = 0; $classCounts[$cn] = 0; }
    $classSums[$cn] += $e['combined_score'] ?? 0;
    $classCounts[$cn]++;
}
foreach ($classSums as $cn => $sum) {
    $classAvgLabels[] = $cn;
    $classAvgData[] = round($sum / $classCounts[$cn], 1);
}
?>

<!-- بطاقات الإحصائيات -->
<div class="section">
    <div class="grid grid-4">
        <div class="card" style="border-top:4px solid var(--primary);">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">إجمالي التقييمات</div>
                <div style="font-size:32px; font-weight:700; color:var(--primary);"><?php echo $totalEvaluations; ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #17a2b8;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">متوسط الدرجات</div>
                <div style="font-size:32px; font-weight:700; color:#17a2b8;"><?php echo number_format($avgScore, 1); ?>%</div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #28a745;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أعلى درجة</div>
                <div style="font-size:32px; font-weight:700; color:#28a745;"><?php echo number_format($highScore, 1); ?>%</div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #dc3545;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أدنى درجة</div>
                <div style="font-size:32px; font-weight:700; color:#dc3545;"><?php echo $totalEvaluations > 0 ? number_format($lowScore, 1) : '—'; ?>%</div>
            </div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">🔍 فلتر البحث</h3></div>
        <div class="card-body">
            <form method="GET" class="form-inline">
                <div class="form-group">
                    <label class="form-label">الفصل:</label>
                    <select name="class_id" class="form-control" style="width:auto;">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo $cls['id']; ?>" <?php echo $filterClass == $cls['id'] ? 'selected' : ''; ?>><?php echo e($cls['grade_name'] . ' - ' . $cls['name_ar']); ?></option>
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

<!-- رسوم بيانية -->
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 توزيع مستويات المهارات</h3></div>
            <div class="card-body">
                <div style="height:280px;">
                    <canvas id="levelsChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 متوسط الدرجات حسب الفصل</h3></div>
            <div class="card-body">
                <div style="height:280px;">
                    <canvas id="classAvgChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- الطلاب المتميزون -->
<?php if (!empty($topStudents)): ?>
<div class="section">
    <div class="card">
        <div class="card-header" style="background:var(--primary); color:white;">
            <h3 class="card-title" style="color:white;">🏆 الطلاب المتميزون (مستوى خبير)</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto-lg">
                <?php foreach ($topStudents as $top): 
                    $topLevel = getSkillLevelInfo($top['combined_score']);
                ?>
                <div style="text-align:center; padding:12px; border-radius:var(--radius-md); background:rgba(40,167,69,0.05); border:1px solid rgba(40,167,69,0.2);">
                    <div style="font-size:28px;">🟢</div>
                    <div style="font-weight:700; font-size:14px; margin-top:4px;"><?php echo e($top['student_name']); ?></div>
                    <div style="font-size:13px; color:var(--text-muted);"><?php echo e($top['class_name']); ?></div>
                    <div style="font-size:22px; font-weight:700; color:#28a745; margin-top:4px;"><?php echo number_format($top['combined_score'], 1); ?>%</div>
                    <div style="font-size:12px; color:#28a745;"><?php echo $topLevel['label']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- القائمة -->
<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">تقييمات المهارات</h3>
        <div class="flex gap-2">
            <button onclick="window.print()" class="btn btn-light" title="طباعة">🖨️</button>
            <a href="evaluate.php" class="btn btn-primary">➕ تقييم جديد</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($evaluations)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">✅</div>
                    <div class="table-empty-text">لا توجد تقييمات مهارات</div>
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
                                <th>المستوى</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $i => $eval): 
                                $rating = $eval['combined_score'] !== null ? $engine->getScoreRating($eval['combined_score']) : null;
                                $level = $eval['combined_score'] !== null ? getSkillLevelInfo($eval['combined_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><div style="font-weight:600;"><?php echo e($eval['student_name']); ?></div></td>
                                <td><?php echo e($eval['class_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo formatDateAr($eval['created_at'], 'short'); ?></td>
                                <td>
                                    <?php if ($rating): ?>
                                        <span style="font-weight:700; color:<?php echo $rating['color']; ?>;"><?php echo number_format($eval['combined_score'], 1); ?>%</span>
                                        <div style="font-size:11px; color:<?php echo $rating['color']; ?>;"><?php echo $rating['label']; ?></div>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($level): ?>
                                    <span style="display:inline-block; padding:2px 8px; border-radius:var(--radius-md); font-size:12px; font-weight:600; background:<?php echo $level['color']; ?>20; color:<?php echo $level['color']; ?>;">
                                        <?php echo $level['icon'] . ' ' . $level['label']; ?>
                                    </span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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

<?php
$pageJs = "
    eqasBarChart('levelsChart', 'توزيع المستويات', " . json_encode($chartLabels, JSON_UNESCAPED_UNICODE) . ", [{
        label: 'عدد الطلاب',
        data: " . json_encode($chartData) . ",
        backgroundColor: " . json_encode($chartColors) . "
    }], {indexAxis: 'y', plugins: {legend: {display: false}}});

    eqasBarChart('classAvgChart', 'متوسط الدرجات', " . json_encode($classAvgLabels, JSON_UNESCAPED_UNICODE) . ", [{
        label: 'متوسط الدرجة',
        data: " . json_encode($classAvgData) . ",
        backgroundColor: 'rgba(26, 86, 50, 0.6)',
        borderColor: 'rgba(26, 86, 50, 1)',
        borderWidth: 1
    }], {scales: {y: {beginAtZero: true, max: 100}}});
";
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
