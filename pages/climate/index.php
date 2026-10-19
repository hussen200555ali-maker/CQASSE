<?php
/**
 * ============================================================
 * استبيان المناخ المدرسي - فهرس
 * Climate Survey Index
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'استبيان المناخ المدرسي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'استبيان المناخ' => null];

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
$filterRespondent = $_GET['respondent_type'] ?? '';
$filterSemester = $_GET['semester_id'] ?? '';
$filterAy = (int)($_GET['academic_year_id'] ?? 0);

$sql = "SELECT cs.*, s.name_ar as school_name, sem.name_ar as semester_name, ay.name as year_name
        FROM surveys cs
        JOIN schools s ON cs.school_id = s.id
        JOIN semesters sem ON cs.semester_id = sem.id
        JOIN academic_years ay ON cs.academic_year_id = ay.id
        WHERE cs.survey_type = 'climate' AND cs.is_active = 1";
$params = [];

if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND cs.school_id = ?"; $params[] = $schoolId; }
if ($filterSchool) { $sql .= " AND cs.school_id = ?"; $params[] = $filterSchool; }
if ($filterRespondent) { $sql .= " AND cs.respondent_type = ?"; $params[] = $filterRespondent; }
if ($filterAy > 0) { $sql .= " AND cs.academic_year_id = ?"; $params[] = $filterAy; }
if ($filterSemester) {
    $semVal = (int)$filterSemester;
    $semFilter = buildSemesterSubquery($semVal);
    if ($semFilter['sql']) { $sql .= $semFilter['sql']; $params = array_merge($params, $semFilter['params']); }
}

$sql .= " ORDER BY cs.survey_date DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll();
} catch (Exception $e) { $surveys = []; }

$schools = [];
try {
    $sql = "SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar";
    $p = [];
    if ($schoolId && $userRole !== 'group_admin') { $sql .= " AND id = ?"; $p[] = $schoolId; }
    $stmt = $db->prepare($sql); $stmt->execute($p); $schools = $stmt->fetchAll();
} catch (Exception $e) {}

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$respondentTypes = ['teacher' => 'معلم', 'student' => 'طالب', 'parent' => 'ولي أمر', 'admin' => 'إداري'];

// إحصائيات المستجيبين
$respondentStats = ['teacher' => 0, 'student' => 0, 'parent' => 0, 'admin' => 0];
$respondentScores = ['teacher' => ['sum' => 0, 'count' => 0], 'student' => ['sum' => 0, 'count' => 0], 'parent' => ['sum' => 0, 'count' => 0], 'admin' => ['sum' => 0, 'count' => 0]];
$totalSurveys = count($surveys);
$avgScore = 0;
$scoreSum = 0;

foreach ($surveys as $survey) {
    if (isset($respondentStats[$survey['respondent_type']])) {
        $respondentStats[$survey['respondent_type']]++;
    }
    if ($survey['overall_score'] !== null) {
        $scoreSum += $survey['overall_score'];
        if (isset($respondentScores[$survey['respondent_type']])) {
            $respondentScores[$survey['respondent_type']]['sum'] += $survey['overall_score'];
            $respondentScores[$survey['respondent_type']]['count']++;
        }
    }
}
$avgScore = $totalSurveys > 0 ? round($scoreSum / $totalSurveys, 1) : 0;

// معدل الاستجابة (تقريبي - مقارنة بعدد المستجيبين المتوقع)
$expectedRespondents = 50; // قيمة افتراضية
$responseRate = $expectedRespondents > 0 ? round(($totalSurveys / $expectedRespondents) * 100, 1) : 0;
if ($responseRate > 100) $responseRate = 100;

// بيانات الاتجاه الزمني
$trendData = [];
$trendLabels = [];
if (!empty($surveys)) {
    $periodScores = [];
    foreach ($surveys as $s) {
        $period = $s['semester_name'];
        if (!isset($periodScores[$period])) $periodScores[$period] = ['sum' => 0, 'count' => 0];
        if ($s['overall_score'] !== null) {
            $periodScores[$period]['sum'] += $s['overall_score'];
            $periodScores[$period]['count']++;
        }
    }
    // عكس الترتيب ليكون من الأقدم للأحدث
    foreach (array_reverse($periodScores) as $period => $data) {
        $trendLabels[] = $period;
        $trendData[] = $data['count'] > 0 ? round($data['sum'] / $data['count'], 1) : 0;
    }
}
?>

<!-- بطاقات الإحصائيات -->
<div class="section">
    <div class="grid grid-4">
        <div class="card" style="border-top:4px solid #fd7e14;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">إجمالي الاستبيانات</div>
                <div style="font-size:32px; font-weight:700; color:#fd7e14;"><?php echo $totalSurveys; ?></div>
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
                <div style="font-size:13px; color:var(--text-secondary);">معدل الاستجابة</div>
                <div style="font-size:32px; font-weight:700; color:#28a745;"><?php echo $responseRate; ?>%</div>
                <div style="margin-top:8px;">
                    <div style="background:var(--border-light); border-radius:var(--radius-md); height:6px; overflow:hidden;">
                        <div style="width:<?php echo min($responseRate, 100); ?>%; height:100%; background:#28a745; border-radius:var(--radius-md);"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #6f42c1;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أنواع المستجيبين</div>
                <div style="font-size:32px; font-weight:700; color:#6f42c1;"><?php echo count(array_filter($respondentStats)); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- إحصائيات المستجيبين والاتجاه -->
<div class="section">
    <div class="grid grid-2">
        <!-- توزيع المستجيبين -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">👥 إحصائيات المستجيبين</h3></div>
            <div class="card-body">
                <div style="height:250px; margin-bottom:16px;">
                    <canvas id="respondentChart"></canvas>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:12px; justify-content:center;">
                    <?php foreach ($respondentTypes as $key => $label):
                        $count = $respondentStats[$key];
                        $avg = $respondentScores[$key]['count'] > 0 ? round($respondentScores[$key]['sum'] / $respondentScores[$key]['count'], 1) : 0;
                        $pct = $totalSurveys > 0 ? round(($count / $totalSurveys) * 100, 1) : 0;
                    ?>
                    <div style="text-align:center; min-width:80px; padding:8px; border-radius:var(--radius-md); background:var(--background);">
                        <div style="font-weight:700; font-size:20px; color:var(--primary);"><?php echo $count; ?></div>
                        <div style="font-size:13px; font-weight:600;"><?php echo e($label); ?></div>
                        <div style="font-size:11px; color:var(--text-muted);">متوسط: <?php echo $avg; ?>%</div>
                        <div style="font-size:11px; color:var(--text-muted);"><?php echo $pct; ?>% من الإجمالي</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- الاتجاه الزمني -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 الاتجاه الزمني</h3></div>
            <div class="card-body">
                <div style="height:300px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مقارنة بين الفترات -->
<?php if (count($trendLabels) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">🔄 مقارنة بين الفترات</h3></div>
        <div class="card-body">
            <div style="overflow-x:auto;">
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-light);">
                            <th style="padding:8px; text-align:right;">الفصل الدراسي</th>
                            <th style="padding:8px; text-align:center;">متوسط الدرجة</th>
                            <th style="padding:8px; text-align:center;">التقييم</th>
                            <th style="padding:8px; text-align:center;">التغير</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $prevScore = null;
                        for ($i = 0; $i < count($trendLabels); $i++):
                            $tRating = $engine->getScoreRating($trendData[$i]);
                            $diff = $prevScore !== null ? round($trendData[$i] - $prevScore, 1) : null;
                        ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:8px; font-weight:600;"><?php echo e($trendLabels[$i]); ?></td>
                            <td style="padding:8px; text-align:center; font-weight:700; color:<?php echo $tRating['color']; ?>;"><?php echo $trendData[$i]; ?>%</td>
                            <td style="padding:8px; text-align:center; color:<?php echo $tRating['color']; ?>;"><?php echo $tRating['label']; ?></td>
                            <td style="padding:8px; text-align:center; font-weight:600; color:<?php echo $diff === null ? 'var(--text-muted)' : ($diff >= 0 ? '#28a745' : '#dc3545'); ?>;">
                                <?php echo $diff === null ? '—' : (($diff >= 0 ? '↑+' : '↓') . $diff . '%'); ?>
                            </td>
                        </tr>
                        <?php 
                            $prevScore = $trendData[$i];
                        endfor; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
                    <label class="form-label">نوع المستجيب:</label>
                    <select name="respondent_type" class="form-control" style="width:auto;">
                        <option value="">الكل</option>
                        <?php foreach ($respondentTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filterRespondent === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">السنة الدراسية:</label>
                    <select name="academic_year_id" class="form-control" style="width:auto;">
                        <option value="0" <?php echo $filterAy === 0 ? 'selected' : ''; ?>>الكل</option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?php echo $ay['id']; ?>" <?php echo $filterAy == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي:</label>
                    <select name="semester_id" class="form-control" style="width:auto;">
                        <option value="0" <?php echo $filterSemester == 0 ? 'selected' : ''; ?>>الكل</option>
                        <option value="1" <?php echo $filterSemester == 1 ? 'selected' : ''; ?>>الفصل الدراسي الأول</option>
                        <option value="2" <?php echo $filterSemester == 2 ? 'selected' : ''; ?>>الفصل الدراسي الثاني</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">بحث</button>
                <a href="index.php" class="btn btn-light">إعادة تعيين</a>
            </form>
        </div>
    </div>
</div>

<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">استبيانات المناخ المدرسي</h3>
        <div class="flex gap-2">
            <button onclick="window.print()" class="btn btn-light" title="طباعة">🖨️</button>
            <a href="survey.php" class="btn btn-primary">🌡️ استبيان جديد</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($surveys)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">🌡️</div>
                    <div class="table-empty-text">لا توجد استبيانات مناخ مدرسي</div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المدرسة</th>
                                <th>نوع المستجيب</th>
                                <th>الفصل الدراسي</th>
                                <th>التاريخ</th>
                                <th>الدرجة الكلية</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($surveys as $i => $survey): 
                                $rating = $survey['overall_score'] !== null ? $engine->getScoreRating($survey['overall_score']) : null;
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td style="font-weight:600;"><?php echo e($survey['school_name']); ?></td>
                                <td>
                                    <?php echo e($respondentTypes[$survey['respondent_type']] ?? $survey['respondent_type']); ?>
                                </td>
                                <td><?php echo e($survey['semester_name']); ?></td>
                                <td><?php echo formatDateAr($survey['survey_date'], 'short'); ?></td>
                                <td style="font-weight:700; color:<?php echo $rating['color'] ?? 'inherit'; ?>;">
                                    <?php echo $survey['overall_score'] !== null ? number_format($survey['overall_score'], 1) . '%' : '—'; ?>
                                    <?php if ($rating): ?>
                                    <div style="font-size:11px; color:<?php echo $rating['color']; ?>;"><?php echo $rating['label']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?php echo $survey['id']; ?>" class="btn btn-sm btn-info">👁️</a>
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
$respLabels = [];
$respCounts = [];
$respColors = ['#1a5632', '#17a2b8', '#fd7e14', '#6f42c1'];
$colorIdx = 0;
foreach ($respondentTypes as $key => $label) {
    $respLabels[] = $label;
    $respCounts[] = $respondentStats[$key];
    $colorIdx++;
}

$pageJs = "
    eqasPieChart('respondentChart', 'توزيع المستجيبين', " . json_encode($respLabels, JSON_UNESCAPED_UNICODE) . ", " . json_encode($respCounts) . ", " . json_encode($respColors) . ", false);
    
    eqasLineChart('trendChart', 'اتجاه المناخ المدرسي', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [{
        label: 'متوسط الدرجة',
        data: " . json_encode($trendData) . ",
        borderColor: '#fd7e14',
        fill: true
    }], {scales: {y: {beginAtZero: true, max: 100}}});
";
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
