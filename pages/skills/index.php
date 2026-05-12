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
$filterSemester = $_GET['semester_id'] ?? '';

// مستويات المهارات
$skillLevels = [
    ['key' => 'beginner',     'label' => 'مبتدئ',    'min' => 0,  'max' => 25,  'color' => '#dc3545', 'icon' => '🔴'],
    ['key' => 'intermediate', 'label' => 'متوسط',    'min' => 25, 'max' => 50,  'color' => '#ffc107', 'icon' => '🟡'],
    ['key' => 'advanced',     'label' => 'متقدم',    'min' => 50, 'max' => 75,  'color' => '#17a2b8', 'icon' => '🔵'],
    ['key' => 'expert',       'label' => 'خبير',     'min' => 75, 'max' => 100, 'color' => '#28a745', 'icon' => '🟢'],
];

function getSkillLevelInfo($score) {
    global $skillLevels;
    foreach ($skillLevels as $level) {
        if ($score >= $level['min'] && $score < $level['max']) return $level;
    }
    return $score >= 100 ? $skillLevels[3] : $skillLevels[0];
}

// جلب التقييمات
$sql = "SELECT se.*, s.full_name_ar as student_name, s.student_number,
        c.name_ar as class_name, u.full_name_ar as evaluator_name,
        sem.name_ar as semester_name
        FROM skills_evaluations se
        JOIN students s ON se.student_id = s.id
        JOIN classes c ON se.class_id = c.id
        JOIN users u ON se.evaluator_id = u.id
        JOIN semesters sem ON se.semester_id = sem.id
        WHERE se.is_active = 1";
$params = [];

if ($schoolId && $userRole !== 'group_admin') {
    $sql .= " AND se.class_id IN (SELECT id FROM classes WHERE school_id = ?)";
    $params[] = $schoolId;
}
if ($userRole === 'teacher') {
    $sql .= " AND se.evaluator_id = ?";
    $params[] = $userId;
}
if ($filterClass) { $sql .= " AND se.class_id = ?"; $params[] = $filterClass; }
if ($filterStudent) { $sql .= " AND se.student_id = ?"; $params[] = $filterStudent; }
if ($filterSemester) { $sql .= " AND se.semester_id = ?"; $params[] = $filterSemester; }

$sql .= " ORDER BY se.evaluation_date DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll();
} catch (Exception $e) { $evaluations = []; }

// إحصائيات عامة
$totalEvaluations = count($evaluations);
$avgScore = 0;
$highScore = 0;
$lowScore = 100;
$levelCounts = ['beginner' => 0, 'intermediate' => 0, 'advanced' => 0, 'expert' => 0];

if ($totalEvaluations > 0) {
    $sum = 0;
    foreach ($evaluations as $eval) {
        if ($eval['overall_score'] !== null) {
            $s = $eval['overall_score'];
            $sum += $s;
            if ($s > $highScore) $highScore = $s;
            if ($s < $lowScore) $lowScore = $s;
            $lv = getSkillLevelInfo($s);
            $levelCounts[$lv['key']]++;
        }
    }
    $avgScore = round($sum / $totalEvaluations, 1);
}

// بيانات الرسم البياني - توزيع المستويات
$chartLabels = ['مبتدئ', 'متوسط', 'متقدم', 'خبير'];
$chartData = [$levelCounts['beginner'], $levelCounts['intermediate'], $levelCounts['advanced'], $levelCounts['expert']];
$chartColors = ['#dc3545', '#ffc107', '#17a2b8', '#28a745'];

// بيانات الرسم البياني - متوسط الدرجات حسب الفصل
$classAvgData = [];
$classAvgLabels = [];
if (!empty($evaluations)) {
    $classScores = [];
    foreach ($evaluations as $eval) {
        $cn = $eval['class_name'];
        if (!isset($classScores[$cn])) $classScores[$cn] = ['sum' => 0, 'count' => 0];
        if ($eval['overall_score'] !== null) {
            $classScores[$cn]['sum'] += $eval['overall_score'];
            $classScores[$cn]['count']++;
        }
    }
    foreach ($classScores as $cn => $data) {
        $classAvgLabels[] = $cn;
        $classAvgData[] = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;
    }
}

// الفصول للفلتر
$classes = [];
try {
    $sql = "SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.is_active = 1";
    $p = [];
    if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND c.school_id = ?"; $p[] = $schoolId; }
    $sql .= " ORDER BY gl.level_number, c.name_ar";
    $stmt = $db->prepare($sql); $stmt->execute($p); $classes = $stmt->fetchAll();
} catch (Exception $e) {}

$semesters = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {}

// الطلاب المميزون (خبير)
$topStudents = array_filter($evaluations, function($e) { return ($e['overall_score'] ?? 0) >= 75; });
usort($topStudents, function($a, $b) { return ($b['overall_score'] ?? 0) - ($a['overall_score'] ?? 0); });
$topStudents = array_slice($topStudents, 0, 5);
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
                    $topLevel = getSkillLevelInfo($top['overall_score']);
                ?>
                <div style="text-align:center; padding:12px; border-radius:var(--radius-md); background:rgba(40,167,69,0.05); border:1px solid rgba(40,167,69,0.2);">
                    <div style="font-size:28px;">🟢</div>
                    <div style="font-weight:700; font-size:14px; margin-top:4px;"><?php echo e($top['student_name']); ?></div>
                    <div style="font-size:13px; color:var(--text-muted);"><?php echo e($top['class_name']); ?></div>
                    <div style="font-size:22px; font-weight:700; color:#28a745; margin-top:4px;"><?php echo number_format($top['overall_score'], 1); ?>%</div>
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
                                $rating = $eval['overall_score'] !== null ? $engine->getScoreRating($eval['overall_score']) : null;
                                $level = $eval['overall_score'] !== null ? getSkillLevelInfo($eval['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><div style="font-weight:600;"><?php echo e($eval['student_name']); ?></div></td>
                                <td><?php echo e($eval['class_name']); ?></td>
                                <td><?php echo e($eval['semester_name']); ?></td>
                                <td><?php echo e($eval['evaluator_name']); ?></td>
                                <td><?php echo formatDateAr($eval['evaluation_date'], 'short'); ?></td>
                                <td>
                                    <?php if ($rating): ?>
                                        <span style="font-weight:700; color:<?php echo $rating['color']; ?>;"><?php echo number_format($eval['overall_score'], 1); ?>%</span>
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
