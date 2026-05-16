<?php
$pageTitle = 'تحليل المناخ المدرسي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'المناخ المدرسي' => 'index.php', 'تحليل المدرسة' => null];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$schoolId = (int)($_GET['school_id'] ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);
$ayId = (int)($_GET['academic_year_id'] ?? 0);

if (!$schoolId) {
    setFlash('معرف المدرسة غير صالح', 'danger');
    redirect('dashboard.php');
}

$userRole = $_SESSION['user']['role'];
$userSchool = $_SESSION['user']['school_id'] ?? null;
// SC-02: school_admin restricted to own school
if ($userRole !== 'group_admin' && $userSchool != $schoolId) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه المدرسة', 'danger');
    redirect('index.php');
}

$school = null;
try {
    $stmt = $db->prepare("SELECT id, name_ar, principal_name FROM schools WHERE id = ? AND is_active = 1");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
} catch (Exception $e) {}
if (!$school) { setFlash('المدرسة غير موجودة', 'danger'); redirect('dashboard.php'); }

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$validAyIds = array_column($academicYears, 'id');
if ($ayId && !in_array($ayId, $validAyIds)) {
    $ayId = (int)($academicYears[0]['id'] ?? 0);
}

$semesterId = (int)($_GET['semester_id'] ?? 0);
if (!in_array($semesterId, [0, 1, 2])) {
    $semesterId = 0;
}

$climateAvg = null;
$totalSurveys = 0;
$categoryScores = [];
$hasItems = false;
try {
    $yearFilter = buildYearFilter($ayId);
    $semFilter = buildSemesterSubquery($semesterId);
    if ($semesterId > 0) {
        $sql = "SELECT AVG(cs.overall_score) as avg_score, COUNT(cs.id) as total FROM climate_surveys cs WHERE cs.school_id = ? AND cs.is_active = 1{$yearFilter['sql']}{$semFilter['sql']}";
        $params = array_merge([$schoolId], $yearFilter['params'], $semFilter['params']);
    } else {
        $sql = "SELECT AVG(sem_avg) as avg_score, SUM(survey_count) as total FROM (
                    SELECT AVG(cs.overall_score) as sem_avg, COUNT(cs.id) as survey_count
                    FROM climate_surveys cs WHERE cs.school_id = ? AND cs.is_active = 1{$yearFilter['sql']}
                    GROUP BY cs.semester_id
                ) sub";
        $params = array_merge([$schoolId], $yearFilter['params']);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    $climateAvg = $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
    $totalSurveys = (int)$row['total'];

    // DS-02: check if items exist
    if ($totalSurveys > 0) {
        $iSql = "SELECT COUNT(*) FROM climate_items ci JOIN climate_surveys cs ON ci.survey_id = cs.id WHERE cs.school_id = ? AND cs.is_active = 1 AND ci.is_active = 1{$yearFilter['sql']}{$semFilter['sql']}";
        $iParams = array_merge([$schoolId], $yearFilter['params'], $semFilter['params']);
        $iStmt = $db->prepare($iSql);
        $iStmt->execute($iParams);
        $hasItems = (int)$iStmt->fetchColumn() > 0;
    }

    if ($hasItems) {
        $catSql = "SELECT ac.name_ar as category_name, AVG(ci.normalized_value) as cat_score, COUNT(DISTINCT ci.item_id) as item_count
                              FROM climate_surveys cs
                              JOIN climate_items ci ON ci.survey_id = cs.id
                              JOIN assessment_items ai ON ci.item_id = ai.id
                              JOIN assessment_criteria ac ON ai.criteria_id = ac.id
                              WHERE cs.school_id = ? AND cs.is_active = 1 AND ci.is_active = 1{$yearFilter['sql']}{$semFilter['sql']}
                              GROUP BY ac.id, ac.name_ar ORDER BY cat_score ASC";
        $catParams = array_merge([$schoolId], $yearFilter['params'], $semFilter['params']);
        $stmt2 = $db->prepare($catSql);
        $stmt2->execute($catParams);
        $categoryScores = $stmt2->fetchAll();
        foreach ($categoryScores as &$cat) {
            $cat['cat_score'] = $cat['cat_score'] !== null ? round((float)$cat['cat_score'], 1) : 0;
        }
        unset($cat);
    }
} catch (Exception $e) {}

// Participation rate
$expected = 0;
try {
    $tStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'teacher')");
    $tStmt->execute([$schoolId]);
    $expected += (int)$tStmt->fetchColumn();
    $sStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
    $sStmt->execute([$schoolId]);
    $expected += (int)$sStmt->fetchColumn();
} catch (Exception $e) {}
$participationRate = $expected > 0 ? round(($totalSurveys / $expected) * 100, 1) : 0;
if ($participationRate > 100) $participationRate = 100;

$prevScore = null;
try {
    if ($semesterId > 0 && $ayId > 0) {
        // Specific semester: compare same semester name across years
        $semName = semesterValueToNameAr($semesterId);
        // Find previous academic year
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$ayId]);
        $prevAyId = $stmt->fetchColumn();
        if ($prevAyId) {
            // Find same semester name in previous year
            $stmt = $db->prepare("SELECT id FROM semesters WHERE name_ar = ? AND academic_year_id = ? AND is_active = 1");
            $stmt->execute([$semName, $prevAyId]);
            $prevSemId = $stmt->fetchColumn();
            if ($prevSemId) {
                $stmt3 = $db->prepare("SELECT AVG(overall_score) FROM climate_surveys WHERE school_id = ? AND semester_id = ? AND is_active = 1");
                $stmt3->execute([$schoolId, $prevSemId]);
                $prevScore = $stmt3->fetchColumn();
                if ($prevScore !== null) $prevScore = round((float)$prevScore, 1);
            }
        }
    } elseif ($semesterId == 0 && $ayId > 0) {
        // S-04: All semesters — compare annual average with previous year
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$ayId]);
        $prevAyId = $stmt->fetchColumn();
        if ($prevAyId) {
            $stmt3 = $db->prepare("SELECT AVG(overall_score) FROM climate_surveys WHERE school_id = ? AND academic_year_id = ? AND is_active = 1");
            $stmt3->execute([$schoolId, $prevAyId]);
            $prevScore = $stmt3->fetchColumn();
            if ($prevScore !== null) $prevScore = round((float)$prevScore, 1);
        }
    }
} catch (Exception $e) {}

$trendLabels = []; $trendData = [];
try {
    $tYearFilter = buildYearFilter($ayId);
    $tSemFilter = buildSemesterSubquery($semesterId);
    $trendSql = "SELECT sem.name_ar, AVG(cs.overall_score) as avg_score
                 FROM climate_surveys cs
                 JOIN semesters sem ON cs.semester_id = sem.id
                 WHERE cs.school_id = ? AND cs.overall_score IS NOT NULL AND cs.is_active = 1{$tYearFilter['sql']}{$tSemFilter['sql']}
                 GROUP BY cs.semester_id, sem.name_ar, sem.start_date
                 ORDER BY sem.start_date DESC LIMIT 6";
    $trendParams = array_merge([$schoolId], $tYearFilter['params'], $tSemFilter['params']);
    $stmt = $db->prepare($trendSql);
    $stmt->execute($trendParams);
    $rows = $stmt->fetchAll();
    foreach (array_reverse($rows) as $row) {
        $trendLabels[] = $row['name_ar'];
        $trendData[] = round((float)$row['avg_score'], 1);
    }
} catch (Exception $e) {}

$recommendations = [];
$dimensionRecommendations = [
    'البيئة المدرسية' => 'تحسين البيئة المادية للفصول الدراسية من حيث النظافة والإضاءة والتهوية، وتوفير المساحات الخضراء والمرافق الترفيهية.',
    'العلاقات الاجتماعية' => 'تعزيز الأنشطة الجماعية وبرامج التواصل بين الطلاب والمعلمين، وتنظيم ورش عمل لتطوير مهارات التواصل الفعال.',
    'الأمن والسلامة' => 'تطوير إجراءات الأمن والسلامة في المدرسة، وتدريب الكادر على خطط الطوارئ والإخلاء، وتوفير معدات السلامة اللازمة.',
];
$lowestCats = array_slice($categoryScores, 0, 2);
foreach ($lowestCats as $lc) {
    if ($lc['cat_score'] < 70 && isset($dimensionRecommendations[$lc['category_name']])) {
        $recommendations[] = '🔸 <strong>' . e($lc['category_name']) . ':</strong> ' . $dimensionRecommendations[$lc['category_name']];
    }
}
if (empty($recommendations) && $climateAvg !== null && $climateAvg < 70) {
    $recommendations[] = '🔸 <strong>توصية عامة:</strong> العمل على تحسين جميع أبعاد المناخ المدرسي من خلال خطة شاملة تستهدف البيئة المدرسية والعلاقات الاجتماعية والأمن والسلامة.';
}
if ($climateAvg !== null && $climateAvg >= 80) {
    $recommendations[] = '🔸 <strong>أداء متميز:</strong> المناخ المدرسي في هذه المدرسة صحي وإيجابي. الحفاظ على الزخم وتقديم نموذج للمدارس الأخرى.';
}
if ($participationRate < 60 && $totalSurveys > 0) {
    $recommendations[] = '🔸 <strong>ضعف المشاركة:</strong> نسبة المشاركة في الاستبيان منخفضة (' . $participationRate . '%). يوصى بتشجيع المزيد من المعلمين والطلاب على المشاركة.';
}
?>
<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">🏫 تحليل المناخ المدرسي: <?= e($school['name_ar']) ?></h3>
            <div class="flex gap-2">
                <?php if ($userRole === 'group_admin'): ?>
                <a href="dashboard.php" class="btn btn-light btn-sm">↩ العودة</a>
                <?php else: ?>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline" style="margin-bottom:16px;">
                <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                <div class="form-group">
                    <label class="form-label">السنة الدراسية:</label>
                    <select name="academic_year_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="0" <?= $ayId === 0 ? 'selected' : '' ?>>جميع السنوات</option>
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $ayId == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي:</label>
                    <select name="semester_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="0" <?= $semesterId === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                        <option value="1" <?= $semesterId === 1 ? 'selected' : '' ?>>الفصل الدراسي الأول</option>
                        <option value="2" <?= $semesterId === 2 ? 'selected' : '' ?>>الفصل الدراسي الثاني</option>
                    </select>
                </div>
                <a href="school.php?school_id=<?= $schoolId ?>" class="btn btn-light">إعادة تعيين</a>
            </form>
            <div class="grid grid-4">
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">متوسط المناخ</span>
                    <?php $rating = $climateAvg !== null ? $engine->getScoreRating($climateAvg) : null; ?>
                    <div style="font-weight:700; font-size:28px; color:<?= $rating['color'] ?? '#6c757d' ?>;">
                        <?= $climateAvg !== null ? number_format($climateAvg, 1) . '%' : 'لا توجد بيانات' ?>
                    </div>
                    <div style="font-size:13px; color:<?= $rating['color'] ?? '#6c757d' ?>;"><?= $rating['label'] ?? '' ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">عدد الاستبيانات</span>
                    <div style="font-weight:700; font-size:28px; color:#17a2b8;"><?= $totalSurveys ?></div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">نسبة المشاركة</span>
                    <div style="font-weight:700; font-size:28px; color:<?= $participationRate >= 60 ? '#28a745' : '#dc3545' ?>;">
                        <?= $totalSurveys > 0 ? $participationRate . '%' : '—' ?>
                    </div>
                </div>
                <div>
                    <span style="font-size:12px; color:var(--text-muted);">التغير عن العام السابق</span>
                    <div style="font-weight:700; font-size:20px; color:<?= $prevScore !== null ? ($climateAvg >= $prevScore ? '#28a745' : '#dc3545') : '#6c757d' ?>;">
                        <?php if ($prevScore !== null && $climateAvg !== null): ?>
                            <?= $climateAvg >= $prevScore ? '▲+' : '▼' ?><?= number_format(abs($climateAvg - $prevScore), 1) ?>%
                            <span style="font-size:13px; font-weight:400;">(<?= number_format($prevScore, 1) ?>%)</span>
                        <?php else: ?>
                            <span style="font-size:16px; font-weight:400;">لا توجد بيانات سابقة</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($totalSurveys === 0): ?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:60px;">
            <div style="font-size:64px; margin-bottom:16px;">📋</div>
            <h4>لا توجد بيانات مناخ</h4>
            <p style="color:var(--text-muted);">لم يتم إجراء أي استبيان مناخ لهذه المدرسة في الفترة المحددة.</p>
            <a href="survey.php" class="btn btn-primary">🌡️ إنشاء استبيان جديد</a>
        </div>
    </div>
</div>
<?php else: ?>

<?php if ($totalSurveys > 0 && !$hasItems): ?>
<div class="section">
    <div class="alert alert-warning" style="padding:12px 16px; border-radius:var(--radius-md); background:#fff3cd; border:1px solid #ffc107; color:#856404;">
        <strong>⚠️ ملاحظة:</strong> تم إنشاء <?= $totalSurveys ?> استبيان ولكن لم تُستكمل الاستجابات بعد.
    </div>
</div>
<?php endif; ?>

<?php if ($hasItems): ?>
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">🎯 الأبعاد الفرعية</h3></div>
            <div class="card-body">
                <div style="height:300px;"><canvas id="dimensionsRadarChart"></canvas></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 اتجاه المناخ</h3></div>
            <div class="card-body">
                <?php if (count($trendData) <= 1): ?>
                <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px;">📉</div>
                    <p>بيانات غير كافية لعرض اتجاه. انتظر جمع بيانات الفصل القادم.</p>
                </div>
                <?php else: ?>
                <div style="height:300px;"><canvas id="schoolTrendChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 تفاصيل الأبعاد</h3></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($categoryScores)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">📊</div>
                <div class="table-empty-text">لا توجد بيانات أبعاد للفصل المحدد</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>البعد</th>
                            <th>الدرجة</th>
                            <th>التقييم</th>
                            <th>عدد العناصر</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryScores as $cat):
                            $catRating = $engine->getScoreRating($cat['cat_score']);
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($cat['category_name']) ?></td>
                            <td style="font-weight:700; color:<?= $catRating['color'] ?>;">
                                <?= number_format($cat['cat_score'], 1) ?>%
                                <?= $engine->renderScoreBar($cat['cat_score']) ?>
                            </td>
                            <td style="color:<?= $catRating['color'] ?>;"><?= $catRating['label'] ?></td>
                            <td><?= $cat['item_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($recommendations)): ?>
<div class="section">
    <div class="card" style="border:2px solid #ffc107;">
        <div class="card-header" style="background:#ffc107; color:#333;">
            <h3 class="card-title">💡 التوصيات</h3>
        </div>
        <div class="card-body">
            <ul style="margin:0; padding-right:20px; line-height:2;">
                <?php foreach ($recommendations as $rec): ?>
                <li><?= $rec ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
$radarLabels = []; $radarData = [];
foreach ($categoryScores as $cat) {
    $radarLabels[] = $cat['category_name'];
    $radarData[] = $cat['cat_score'];
}
$hasRadar = !empty($radarLabels);

$chartJs = '';

if ($hasRadar) {
    $chartJs .= "
    if (typeof Chart !== 'undefined' && document.getElementById('dimensionsRadarChart')) {
        new Chart(document.getElementById('dimensionsRadarChart'), {
            type: 'radar',
            data: {
                labels: " . json_encode($radarLabels, JSON_UNESCAPED_UNICODE) . ",
                datasets: [{
                    label: 'درجة البعد',
                    data: " . json_encode($radarData) . ",
                    backgroundColor: 'rgba(23,162,184,0.2)',
                    borderColor: '#17a2b8',
                    pointBackgroundColor: '#17a2b8',
                    pointBorderColor: '#fff',
                    pointHoverRadius: 5,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { stepSize: 20 }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }\n";
}

if (count($trendData) > 1) {
    $chartJs .= "
    eqasLineChart('schoolTrendChart', 'اتجاه المناخ', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [{
        label: 'متوسط المناخ',
        data: " . json_encode($trendData) . ",
        borderColor: '#1a5632',
        backgroundColor: 'rgba(26,86,50,0.1)',
        fill: true
    }], {scales: {y: {beginAtZero: true, max: 100}}});\n";
}

$pageJs = $chartJs;
?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
