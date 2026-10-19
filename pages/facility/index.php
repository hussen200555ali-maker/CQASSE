<?php
/**
 * ============================================================
 * فحص المرافق المدرسية - فهرس
 * Facility Checklist Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'فحص المرافق المدرسية';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'فحص المرافق' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

$filterSchool = $_GET['school_id'] ?? '';
$filterSemester = $_GET['semester_id'] ?? '0';

$sql = "SELECT s.*, s.survey_date as evaluation_date, sch.name_ar as school_name, u.full_name_ar as evaluator_name,
        sem.name_ar as semester_name, ay.name as year_name
        FROM surveys s
        JOIN schools sch ON s.school_id = sch.id
        JOIN users u ON s.evaluator_id = u.id
        JOIN semesters sem ON s.semester_id = sem.id
        JOIN academic_years ay ON s.academic_year_id = ay.id
        WHERE s.survey_type = 'facility' AND s.is_active = 1";
$params = [];

if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND s.school_id = ?"; $params[] = $schoolId; }
if ($filterSchool) { $sql .= " AND s.school_id = ?"; $params[] = $filterSchool; }
if ($filterSemester && $filterSemester !== '0') {
    $sq = buildSemesterSubquery($filterSemester, 's');
    $sql .= $sq['sql'];
    $params = array_merge($params, $sq['params']);
}

$sql .= " ORDER BY s.survey_date DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $checklists = $stmt->fetchAll();
} catch (Exception $e) { $checklists = []; }

$schools = [];
try {
    $sql = "SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar";
    $p = [];
    if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND id = ?"; $p[] = $schoolId; }
    $stmt = $db->prepare($sql); $stmt->execute($p); $schools = $stmt->fetchAll();
} catch (Exception $e) {}



// إحصائيات عامة
$totalChecklists = count($checklists);
$avgScore = 0;
$highScore = 0;
$lowScore = 100;
$complianceCount = 0; // عدد الفحوصات ذات درجة >= 75

if ($totalChecklists > 0) {
    $sum = 0;
    foreach ($checklists as $cl) {
        if ($cl['overall_score'] !== null) {
            $s = $cl['overall_score'];
            $sum += $s;
            if ($s > $highScore) $highScore = $s;
            if ($s < $lowScore) $lowScore = $s;
            if ($s >= 75) $complianceCount++;
        }
    }
    $avgScore = round($sum / $totalChecklists, 1);
}

$complianceRate = $totalChecklists > 0 ? round(($complianceCount / $totalChecklists) * 100, 1) : 0;

// بيانات الرسم البياني - حالة المرافق
$conditionLabels = ['ممتاز (≥75%)', 'جيد (50-75%)', 'ضعيف (25-50%)', 'حرج (<25%)'];
$conditionData = [0, 0, 0, 0];
$conditionColors = ['#28a745', '#17a2b8', '#ffc107', '#dc3545'];

foreach ($checklists as $cl) {
    if ($cl['overall_score'] !== null) {
        if ($cl['overall_score'] >= 75) $conditionData[0]++;
        elseif ($cl['overall_score'] >= 50) $conditionData[1]++;
        elseif ($cl['overall_score'] >= 25) $conditionData[2]++;
        else $conditionData[3]++;
    }
}

// بيانات المقارنة التاريخية
$historicalLabels = [];
$historicalData = [];
if (!empty($checklists)) {
    $periodScores = [];
    foreach ($checklists as $cl) {
        $period = $cl['semester_name'];
        if (!isset($periodScores[$period])) $periodScores[$period] = ['sum' => 0, 'count' => 0];
        if ($cl['overall_score'] !== null) {
            $periodScores[$period]['sum'] += $cl['overall_score'];
            $periodScores[$period]['count']++;
        }
    }
    foreach (array_reverse($periodScores) as $period => $data) {
        $historicalLabels[] = $period;
        $historicalData[] = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;
    }
}
?>

<!-- بطاقات الإحصائيات -->
<div class="section">
    <div class="grid grid-4">
        <div class="card" style="border-top:4px solid #20c997;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">إجمالي الفحوصات</div>
                <div style="font-size:32px; font-weight:700; color:#20c997;"><?php echo $totalChecklists; ?></div>
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
                <div style="font-size:13px; color:var(--text-secondary);">نسبة الامتثال</div>
                <div style="font-size:32px; font-weight:700; color:#28a745;"><?php echo $complianceRate; ?>%</div>
                <div style="margin-top:8px;">
                    <div style="background:var(--border-light); border-radius:var(--radius-md); height:6px; overflow:hidden;">
                        <div style="width:<?php echo min($complianceRate, 100); ?>%; height:100%; background:#28a745; border-radius:var(--radius-md);"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #fd7e14;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">فحوصات ممتازة</div>
                <div style="font-size:32px; font-weight:700; color:#fd7e14;"><?php echo $complianceCount; ?></div>
                <div style="font-size:11px; color:var(--text-muted);">من أصل <?php echo $totalChecklists; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- رسوم بيانية -->
<div class="section">
    <div class="grid grid-2">
        <!-- مخطط دائري لحالة المرافق -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 حالة المرافق</h3></div>
            <div class="card-body">
                <div style="height:280px;">
                    <canvas id="conditionChart"></canvas>
                </div>
            </div>
        </div>
        <!-- المقارنة التاريخية -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 التطور التاريخي</h3></div>
            <div class="card-body">
                <div style="height:280px;">
                    <canvas id="historyChart"></canvas>
                </div>
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
                    <label class="form-label">المدرسة:</label>
                    <select name="school_id" class="form-control" style="width:auto;">
                        <option value="">الكل</option>
                        <?php foreach ($schools as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $filterSchool == $s['id'] ? 'selected' : ''; ?>><?php echo e($s['name_ar']); ?></option>
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

<!-- تفاصيل حالة المرافق -->
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📋 تفاصيل حالة المرافق</h3></div>
        <div class="card-body">
            <?php if (empty($checklists)): ?>
            <div style="text-align:center; color:var(--text-muted); padding:20px;">لا توجد بيانات</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-light);">
                            <th style="padding:8px; text-align:right;">الفئة</th>
                            <th style="padding:8px; text-align:center;">العدد</th>
                            <th style="padding:8px; text-align:center;">النسبة</th>
                            <th style="padding:8px; text-align:center;">التوزيع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $condCats = [
                            ['label' => 'ممتاز (≥75%)', 'count' => $conditionData[0], 'color' => '#28a745', 'icon' => '🟢'],
                            ['label' => 'جيد (50-75%)', 'count' => $conditionData[1], 'color' => '#17a2b8', 'icon' => '🔵'],
                            ['label' => 'ضعيف (25-50%)', 'count' => $conditionData[2], 'color' => '#ffc107', 'icon' => '🟡'],
                            ['label' => 'حرج (<25%)', 'count' => $conditionData[3], 'color' => '#dc3545', 'icon' => '🔴'],
                        ];
                        foreach ($condCats as $cc):
                            $ccPct = $totalChecklists > 0 ? round(($cc['count'] / $totalChecklists) * 100, 1) : 0;
                        ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:8px; font-weight:600;">
                                <span style="color:<?php echo $cc['color']; ?>;"><?php echo $cc['icon']; ?></span>
                                <?php echo e($cc['label']); ?>
                            </td>
                            <td style="padding:8px; text-align:center; font-weight:700; color:<?php echo $cc['color']; ?>;"><?php echo $cc['count']; ?></td>
                            <td style="padding:8px; text-align:center;"><?php echo $ccPct; ?>%</td>
                            <td style="padding:8px;">
                                <div style="background:var(--border-light); border-radius:var(--radius-md); height:10px; overflow:hidden; width:100%;">
                                    <div style="width:<?php echo $ccPct; ?>%; height:100%; background:<?php echo $cc['color']; ?>; border-radius:var(--radius-md);"></div>
                                </div>
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

<!-- القائمة -->
<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">قوائم فحص المرافق</h3>
        <div class="flex gap-2">
            <button onclick="window.print()" class="btn btn-light" title="طباعة">🖨️</button>
            <a href="checklist.php" class="btn btn-primary">🏫 فحص جديد</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($checklists)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">🏫</div>
                    <div class="table-empty-text">لا توجد قوائم فحص مرافق</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المدرسة</th>
                                <th>المقيّم</th>
                                <th>الفصل الدراسي</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checklists as $i => $cl): 
                                $rating = $cl['overall_score'] !== null ? $engine->getScoreRating($cl['overall_score']) : null;
                                // تحديد حالة المرافق
                                $condition = '—';
                                $conditionColor = 'var(--text-muted)';
                                $conditionIcon = '⚪';
                                if ($cl['overall_score'] !== null) {
                                    if ($cl['overall_score'] >= 75) { $condition = 'ممتاز'; $conditionColor = '#28a745'; $conditionIcon = '🟢'; }
                                    elseif ($cl['overall_score'] >= 50) { $condition = 'جيد'; $conditionColor = '#17a2b8'; $conditionIcon = '🔵'; }
                                    elseif ($cl['overall_score'] >= 25) { $condition = 'ضعيف'; $conditionColor = '#ffc107'; $conditionIcon = '🟡'; }
                                    else { $condition = 'حرج'; $conditionColor = '#dc3545'; $conditionIcon = '🔴'; }
                                }
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td style="font-weight:600;"><?php echo e($cl['school_name']); ?></td>
                                <td><?php echo e($cl['evaluator_name']); ?></td>
                                <td><?php echo e($cl['semester_name']); ?></td>
                                <td><?php echo formatDateAr($cl['evaluation_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $cl['overall_score'] !== null ? number_format($cl['overall_score'], 1) . '%' : '—'; ?>
                                </td>
                                <td>
                                    <span style="padding:2px 8px; border-radius:var(--radius-md); font-size:12px; font-weight:600; background:<?php echo $conditionColor; ?>20; color:<?php echo $conditionColor; ?>;">
                                        <?php echo $conditionIcon . ' ' . $condition; ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?php echo $cl['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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
    eqasPieChart('conditionChart', 'حالة المرافق', " . json_encode($conditionLabels, JSON_UNESCAPED_UNICODE) . ", " . json_encode($conditionData) . ", " . json_encode($conditionColors) . ", true);
    
    eqasLineChart('historyChart', 'التطور التاريخي لجودة المرافق', " . json_encode($historicalLabels, JSON_UNESCAPED_UNICODE) . ", [{
        label: 'متوسط الدرجة',
        data: " . json_encode($historicalData) . ",
        borderColor: '#20c997',
        fill: true
    }], {scales: {y: {beginAtZero: true, max: 100}}});
";
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
