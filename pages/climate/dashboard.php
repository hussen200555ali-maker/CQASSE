<?php
$pageTitle = 'لوحة معلومات المناخ المدرسي';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'المناخ المدرسي' => 'index.php', 'لوحة المعلومات' => null];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

// Y-02: No academic years
if (empty($academicYears)) {
    echo '<div class="section"><div class="card"><div class="card-body" style="text-align:center;padding:40px;">
        <div style="font-size:48px;margin-bottom:12px;">📅</div>
        <h4>لا توجد سنوات دراسية متاحة</h4>
        <p style="color:var(--text-muted);">يرجى إنشاء سنة دراسية جديدة من صفحة التقويم الأكاديمي.</p>
        <a href="../academic/index.php" class="btn btn-primary">الذهاب إلى التقويم الأكاديمي</a>
    </div></div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$ayId = (int)($_GET['academic_year_id'] ?? 0);
// ED-02: validate ayId — 0 means "جميع السنوات"
$validAyIds = array_column($academicYears, 'id');
if ($ayId && !in_array($ayId, $validAyIds)) {
    $ayId = (int)($academicYears[0]['id'] ?? 0);
}

$semesterId = (int)($_GET['semester_id'] ?? 0);
if (!in_array($semesterId, [0, 1, 2])) {
    $semesterId = 0;
}

// DS-01: Check if ANY surveys exist in the system
$totalSystemSurveys = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM climate_surveys WHERE is_active = 1");
    $totalSystemSurveys = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

if ($totalSystemSurveys === 0) {
    echo '<div class="section"><div class="card"><div class="card-body" style="text-align:center;padding:40px;">
        <div style="font-size:64px;margin-bottom:16px;">🌡️</div>
        <h3>مرحباً بك في وحدة المناخ المدرسي</h3>
        <p style="color:var(--text-muted);margin-bottom:20px;">لم يتم إجراء أي استبيان بعد. ابدأ بإنشاء استبيان من صفحة الإعدادات.</p>
        <a href="survey.php" class="btn btn-primary btn-lg">🌡️ إنشاء أول استبيان</a>
    </div></div></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Get all active schools
$allSchools = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    $allSchools = $stmt->fetchAll();
} catch (Exception $e) {}

// School filter — only for group_admin (already enforced at top)
$schoolId = (int)($_GET['school_id'] ?? 0);
$validSchoolIds = array_column($allSchools, 'id');
// SC-07: invalid school_id → fallback to all
if ($schoolId && !in_array($schoolId, $validSchoolIds)) {
    $schoolId = 0;
}
// SC-06: single school — force it, hide dropdown
$showSchoolFilter = count($allSchools) > 1;
if (!$showSchoolFilter && !empty($allSchools)) {
    $schoolId = (int)$allSchools[0]['id'];
}

// DS-05: Check if current filter period has data; if not, find latest period with data
$filterHasData = false;
try {
    $yearFilter = buildYearFilter($ayId);
    $semFilter = buildSemesterSubquery($semesterId);
    $schoolFilter = buildSchoolFilter($schoolId);
    $sql = "SELECT COUNT(*) FROM climate_surveys cs WHERE cs.is_active = 1";
    $params = [];
    if ($yearFilter['sql']) { $sql .= $yearFilter['sql']; $params = array_merge($params, $yearFilter['params']); }
    if ($semFilter['sql']) { $sql .= $semFilter['sql']; $params = array_merge($params, $semFilter['params']); }
    if ($schoolFilter['sql']) { $sql .= $schoolFilter['sql']; $params = array_merge($params, $schoolFilter['params']); }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $filterHasData = (int)$stmt->fetchColumn() > 0;
} catch (Exception $e) {}

$fallbackPeriod = null;
if (!$filterHasData) {
    try {
        $stmt = $db->query("SELECT cs.academic_year_id, cs.semester_id, ay.name as year_name, sem.name_ar as semester_name
                            FROM climate_surveys cs
                            JOIN academic_years ay ON cs.academic_year_id = ay.id
                            JOIN semesters sem ON cs.semester_id = sem.id
                            WHERE cs.is_active = 1
                            ORDER BY cs.survey_date DESC LIMIT 1");
        $fallbackPeriod = $stmt->fetch();
        if ($fallbackPeriod) {
            $ayId = (int)$fallbackPeriod['academic_year_id'];
            $semName = $fallbackPeriod['semester_name'] ?? '';
            if ($semName === 'الفصل الدراسي الأول') $semesterId = 1;
            elseif ($semName === 'الفصل الدراسي الثاني') $semesterId = 2;
            else $semesterId = 0;
        }
    } catch (Exception $e) {}
}

$schoolsClimate = [];
$avgTotal = 0; $highestScore = 0; $highestSchool = ''; $lowestScore = 100; $lowestSchool = '';
$totalSurveysAll = 0; $totalExpectedAll = 0; $participatingSchools = 0;

try {
    $yearFilter = buildYearFilter($ayId);
    $schoolFilter = buildSchoolFilter($schoolId);
    if ($semesterId > 0) {
        // Specific semester: straight average across all surveys
        $semFilter = buildSemesterSubquery($semesterId);
        $sql = "SELECT cs.school_id, s.name_ar as school_name,
                       AVG(cs.overall_score) as avg_score,
                       COUNT(cs.id) as survey_count
                FROM climate_surveys cs
                JOIN schools s ON cs.school_id = s.id
                WHERE cs.is_active = 1{$yearFilter['sql']}{$semFilter['sql']}{$schoolFilter['sql']}
                GROUP BY cs.school_id ORDER BY avg_score DESC";
        $params = array_merge($yearFilter['params'], $semFilter['params'], $schoolFilter['params']);
    } else {
        // S-04: All semesters — annual average = avg of semester averages
        $sql = "SELECT sub.school_id, MAX(sub.school_name) as school_name,
                       AVG(sub.sem_avg) as avg_score,
                       SUM(sub.survey_count) as survey_count
                FROM (
                    SELECT cs.school_id, s.name_ar as school_name,
                           AVG(cs.overall_score) as sem_avg,
                           COUNT(cs.id) as survey_count
                    FROM climate_surveys cs
                    JOIN schools s ON cs.school_id = s.id
                    WHERE cs.is_active = 1{$yearFilter['sql']}{$schoolFilter['sql']}
                    GROUP BY cs.school_id, cs.semester_id
                ) sub
                GROUP BY sub.school_id ORDER BY avg_score DESC";
        $params = array_merge($yearFilter['params'], $schoolFilter['params']);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $surveySchools = $stmt->fetchAll();

    $schoolMap = [];
    foreach ($surveySchools as $sc) {
        $sc['avg_score'] = $sc['avg_score'] !== null ? round((float)$sc['avg_score'], 1) : null;
        $sc['survey_count'] = (int)$sc['survey_count'];

        $tStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'teacher')");
        $tStmt->execute([$sc['school_id']]);
        $teacherTotal = (int)$tStmt->fetchColumn();
        $sStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
        $sStmt->execute([$sc['school_id']]);
        $studentTotal = (int)$sStmt->fetchColumn();

        $sc['expected'] = $teacherTotal + $studentTotal;
        $sc['participation_rate'] = $sc['expected'] > 0 ? round(($sc['survey_count'] / $sc['expected']) * 100, 1) : 0;
        if ($sc['participation_rate'] > 100) $sc['participation_rate'] = 100;
        $sc['has_data'] = $sc['avg_score'] !== null;
        $sc['is_absent'] = false;

        if ($sc['has_data']) {
            $avgTotal += $sc['avg_score'];
            $participatingSchools++;
            if ($sc['avg_score'] > $highestScore) { $highestScore = $sc['avg_score']; $highestSchool = $sc['school_name']; }
            if ($sc['avg_score'] < $lowestScore) { $lowestScore = $sc['avg_score']; $lowestSchool = $sc['school_name']; }
        }
        $totalSurveysAll += $sc['survey_count'];
        $totalExpectedAll += $sc['expected'];
        $schoolMap[$sc['school_id']] = $sc;
    }

    // DS-03: Add schools with no surveys
    if ($schoolId > 0 && !isset($schoolMap[$schoolId])) {
        // Selected specific school but it has no data — add as absent
        $tStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'teacher')");
        $tStmt->execute([$schoolId]);
        $teacherTotal = (int)$tStmt->fetchColumn();
        $sStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
        $sStmt->execute([$schoolId]);
        $studentTotal = (int)$sStmt->fetchColumn();
        $expected = $teacherTotal + $studentTotal;
        $schoolMap[$schoolId] = [
            'school_id' => $schoolId, 'school_name' => '',
            'avg_score' => null, 'survey_count' => 0,
            'expected' => $expected, 'participation_rate' => 0,
            'has_data' => false, 'is_absent' => true,
        ];
        // Find school name
        foreach ($allSchools as $as) {
            if ($as['id'] == $schoolId) { $schoolMap[$schoolId]['school_name'] = $as['name_ar']; break; }
        }
    }
    foreach ($allSchools as $as) {
        if (!isset($schoolMap[$as['id']])) {
            $tStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'teacher')");
            $tStmt->execute([$as['id']]);
            $teacherTotal = (int)$tStmt->fetchColumn();
            $sStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
            $sStmt->execute([$as['id']]);
            $studentTotal = (int)$sStmt->fetchColumn();
            $expected = $teacherTotal + $studentTotal;
            $schoolMap[$as['id']] = [
                'school_id' => $as['id'], 'school_name' => $as['name_ar'],
                'avg_score' => null, 'survey_count' => 0,
                'expected' => $expected, 'participation_rate' => 0,
                'has_data' => false, 'is_absent' => true,
            ];
        }
    }
    $schoolsClimate = array_values($schoolMap);
    usort($schoolsClimate, function($a, $b) {
        if ($a['has_data'] && !$b['has_data']) return -1;
        if (!$a['has_data'] && $b['has_data']) return 1;
        return ($b['avg_score'] ?? 0) <=> ($a['avg_score'] ?? 0);
    });
} catch (Exception $e) {}

$totalSchools = count($schoolsClimate);
$overallAvg = $participatingSchools > 0 ? round($avgTotal / $participatingSchools, 1) : 0;
$overallParticipation = $totalExpectedAll > 0 ? round(($totalSurveysAll / $totalExpectedAll) * 100, 1) : 0;
if ($overallParticipation > 100) $overallParticipation = 100;
$participatingRatio = $totalSchools > 0 ? round(($participatingSchools / $totalSchools) * 100) : 0;

// School name for single-school view
$selectedSchoolName = '';
$groupAvgForComparison = $overallAvg;
if ($schoolId > 0) {
    foreach ($allSchools as $as) {
        if ($as['id'] == $schoolId) { $selectedSchoolName = $as['name_ar']; break; }
    }
    // Compute group average (all schools) for comparison KPI
    try {
        $gYearFilter = buildYearFilter($ayId);
        $gSemFilter = buildSemesterSubquery($semesterId);
        $gSql = "SELECT AVG(cs.overall_score) FROM climate_surveys cs WHERE cs.is_active = 1";
        $gParams = [];
        if ($gYearFilter['sql']) { $gSql .= $gYearFilter['sql']; $gParams = array_merge($gParams, $gYearFilter['params']); }
        if ($gSemFilter['sql']) { $gSql .= $gSemFilter['sql']; $gParams = array_merge($gParams, $gSemFilter['params']); }
        $gStmt = $db->prepare($gSql);
        $gStmt->execute($gParams);
        $gAvg = $gStmt->fetchColumn();
        if ($gAvg !== null && $gAvg !== false) {
            $groupAvgForComparison = round((float)$gAvg, 1);
        }
    } catch (Exception $e) {}
}

$trendLabels = []; $trendData = [];
$groupTrendLabels = []; $groupTrendData = [];
$trendIsAnnual = ($ayId === 0);
try {
    if ($trendIsAnnual) {
        // Phase 3: Bar chart by year when "جميع السنوات"
        $trendSql = "SELECT ay.name as period_name, AVG(cs.overall_score) as avg_score
                     FROM climate_surveys cs
                     JOIN academic_years ay ON cs.academic_year_id = ay.id
                     WHERE cs.is_active = 1 AND cs.overall_score IS NOT NULL";
        $trendParams = [];
        if ($semesterId > 0) {
            $semName = semesterValueToNameAr($semesterId);
            $trendSql .= " AND cs.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
            $trendParams[] = $semName;
        }
        if ($schoolId > 0) {
            $trendSql .= " AND cs.school_id = ?";
            $trendParams[] = $schoolId;
        }
        $trendSql .= " GROUP BY cs.academic_year_id, ay.name, ay.start_date ORDER BY ay.start_date DESC LIMIT 6";
    } else {
        $trendSql = "SELECT sem.name_ar as period_name, AVG(cs.overall_score) as avg_score
                     FROM climate_surveys cs
                     JOIN semesters sem ON cs.semester_id = sem.id
                     WHERE cs.is_active = 1 AND cs.overall_score IS NOT NULL";
        $trendParams = [];
        if ($ayId > 0) {
            $trendSql .= " AND cs.academic_year_id = ?";
            $trendParams[] = $ayId;
        }
        if ($semesterId > 0) {
            $semName = semesterValueToNameAr($semesterId);
            $trendSql .= " AND cs.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
            $trendParams[] = $semName;
        }
        if ($schoolId > 0) {
            $trendSql .= " AND cs.school_id = ?";
            $trendParams[] = $schoolId;
        }
        $trendSql .= " GROUP BY cs.semester_id, sem.name_ar, sem.start_date ORDER BY sem.start_date DESC LIMIT 6";
    }
    $stmt = $db->prepare($trendSql);
    $stmt->execute($trendParams);
    $trendRows = $stmt->fetchAll();
    foreach (array_reverse($trendRows) as $row) {
        $trendLabels[] = $row['period_name'];
        $trendData[] = round((float)$row['avg_score'], 1);
    }

    // SC-03: When a specific school is selected, also get group average trend (for dual line/chart)
    if ($schoolId > 0) {
        if ($trendIsAnnual) {
            $gTrendSql = "SELECT ay.name as period_name, AVG(cs.overall_score) as avg_score
                          FROM climate_surveys cs
                          JOIN academic_years ay ON cs.academic_year_id = ay.id
                          WHERE cs.is_active = 1 AND cs.overall_score IS NOT NULL";
            $gTrendParams = [];
            if ($semesterId > 0) {
                $semName = semesterValueToNameAr($semesterId);
                $gTrendSql .= " AND cs.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
                $gTrendParams[] = $semName;
            }
            $gTrendSql .= " GROUP BY cs.academic_year_id, ay.name, ay.start_date ORDER BY ay.start_date DESC LIMIT 6";
        } else {
            $gTrendSql = "SELECT sem.name_ar as period_name, AVG(cs.overall_score) as avg_score
                          FROM climate_surveys cs
                          JOIN semesters sem ON cs.semester_id = sem.id
                          WHERE cs.is_active = 1 AND cs.overall_score IS NOT NULL";
            $gTrendParams = [];
            if ($ayId > 0) {
                $gTrendSql .= " AND cs.academic_year_id = ?";
                $gTrendParams[] = $ayId;
            }
            if ($semesterId > 0) {
                $semName = semesterValueToNameAr($semesterId);
                $gTrendSql .= " AND cs.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
                $gTrendParams[] = $semName;
            }
            $gTrendSql .= " GROUP BY cs.semester_id, sem.name_ar, sem.start_date ORDER BY sem.start_date DESC LIMIT 6";
        }
        $stmt2 = $db->prepare($gTrendSql);
        $stmt2->execute($gTrendParams);
        $groupTrendRows = $stmt2->fetchAll();
        foreach (array_reverse($groupTrendRows) as $row) {
            $groupTrendLabels[] = $row['period_name'];
            $groupTrendData[] = round((float)$row['avg_score'], 1);
        }
    }
} catch (Exception $e) {}

$yearlyTrends = [];
try {
    if ($semesterId > 0 && $ayId > 0) {
        // Specific semester: compare same semester name across years
        $currentSemesterName = semesterValueToNameAr($semesterId);
        // Find previous academic year
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$ayId]);
        $prevAyId = $stmt->fetchColumn();
        if ($prevAyId) {
            // Find same semester name in previous year
            $stmt = $db->prepare("SELECT id FROM semesters WHERE name_ar = ? AND academic_year_id = ? AND is_active = 1");
            $stmt->execute([$currentSemesterName, $prevAyId]);
            $prevSemId = $stmt->fetchColumn();
            if ($prevSemId) {
                foreach ($schoolsClimate as $sc) {
                    if ($sc['avg_score'] === null) continue;
                    $stmt2 = $db->prepare("SELECT AVG(overall_score) FROM climate_surveys WHERE school_id = ? AND semester_id = ? AND is_active = 1");
                    $stmt2->execute([$sc['school_id'], $prevSemId]);
                    $prevAvg = $stmt2->fetchColumn();
                    if ($prevAvg !== null && $prevAvg !== false) {
                        $prevAvg = round((float)$prevAvg, 1);
                        $diff = round($sc['avg_score'] - $prevAvg, 1);
                        $yearlyTrends[$sc['school_id']] = ['prev' => $prevAvg, 'diff' => $diff, 'direction' => $diff > 2 ? 'up' : ($diff < -2 ? 'down' : 'stable')];
                    }
                }
            }
        }
    } elseif ($semesterId == 0 && $ayId > 0 && $participatingSchools > 0) {
        // S-04: All semesters — compare annual average with previous year's annual average
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$ayId]);
        $prevAyId = $stmt->fetchColumn();
        if ($prevAyId) {
            foreach ($schoolsClimate as $sc) {
                if ($sc['avg_score'] === null) continue;
                $stmt2 = $db->prepare("SELECT AVG(overall_score) FROM climate_surveys WHERE school_id = ? AND academic_year_id = ? AND is_active = 1");
                $stmt2->execute([$sc['school_id'], $prevAyId]);
                $prevAvg = $stmt2->fetchColumn();
                if ($prevAvg !== null && $prevAvg !== false) {
                    $prevAvg = round((float)$prevAvg, 1);
                    $diff = round($sc['avg_score'] - $prevAvg, 1);
                    $yearlyTrends[$sc['school_id']] = ['prev' => $prevAvg, 'diff' => $diff, 'direction' => $diff > 2 ? 'up' : ($diff < -2 ? 'down' : 'stable')];
                }
            }
        }
    }
} catch (Exception $e) {}

$interventionSchools = array_filter($schoolsClimate, function($sc) {
    return ($sc['avg_score'] !== null && $sc['avg_score'] < 50) || ($sc['participation_rate'] < 60);
});

// VR-01 / VR-02: Variability check
$variability = null;
$scoresArr = array_filter(array_column($schoolsClimate, 'avg_score'), function($v) { return $v !== null; });
if (count($scoresArr) >= 2) {
    $minS = min($scoresArr);
    $maxS = max($scoresArr);
    $range = $maxS - $minS;
    if ($range <= 15) {
        $variability = 'منخفض';
    } elseif ($range >= 40) {
        $variability = 'مرتفع';
    } else {
        $variability = 'متوسط';
    }
}

// PL assessment
$overallRating = $overallAvg > 0 ? $engine->getScoreRating($overallAvg) : null;
$kpiColor = $overallRating ? $overallRating['color'] : '#17a2b8';

// Recommendations
$recommendations = [];
if ($overallAvg > 0) {
    if ($overallAvg >= 80) {
        $recommendations[] = 'المناخ المدرسي صحي وإيجابي. حافظوا على الزخم واستمروا في السياسات الحالية.';
    } elseif ($overallAvg >= 65) {
        $recommendations[] = 'المناخ المدرسي جيد بشكل عام. هناك مجال للتحسين في بعض الأبعاد.';
    } elseif ($overallAvg >= 50) {
        $recommendations[] = 'المناخ المدرسي بحاجة إلى متابعة. يوصى بتحليل الأبعاد الفرعية للمدارس المتأثرة.';
    } else {
        $recommendations[] = 'انخفاض حاد في المناخ المدرسي. مطلوب تدخل فوري وعاجل لتحسين البيئة المدرسية.';
    }
    if ($schoolId === 0 && $variability === 'مرتفع') {
        $recommendations[] = 'هناك فجوة كبيرة بين المدارس. يُنصح بتحليل ممارسات المدارس المتميزة وتطبيقها في المدارس المتعثرة.';
    } elseif ($schoolId === 0 && $variability === 'منخفض') {
        $recommendations[] = 'أداء المدارس متقارب. استمروا في السياسات الموحدة.';
    }
}
?>
<?php if ($fallbackPeriod): ?>
<div class="section">
    <div class="alert alert-warning" style="padding:12px 16px; border-radius:var(--radius-md); background:#fff3cd; border:1px solid #ffc107; color:#856404;">
        <strong>⚠️ ملاحظة:</strong> لا توجد بيانات للفترة المحددة. يتم عرض آخر فترة متاحة: <strong><?= e($fallbackPeriod['year_name'] . ' - ' . $fallbackPeriod['semester_name']) ?></strong>.
        <a href="dashboard.php" style="margin-right:12px; color:#856404; text-decoration:underline;">إعادة تعيين الفلاتر</a>
    </div>
</div>
<?php endif; ?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">🌡️ لوحة معلومات المناخ المدرسي<?= $schoolId > 0 && $selectedSchoolName ? ' — ' . e($selectedSchoolName) : '' ?></h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline">
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
                <?php if ($showSchoolFilter): ?>
                <div class="form-group">
                    <label class="form-label">المدرسة:</label>
                    <select name="school_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="0" <?= $schoolId === 0 ? 'selected' : '' ?>>جميع المدارس</option>
                        <?php foreach ($allSchools as $sc): ?>
                        <option value="<?= $sc['id'] ?>" <?= $schoolId == $sc['id'] ? 'selected' : '' ?>><?= e($sc['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-light">إعادة تعيين</a>
            </form>
        </div>
    </div>
</div>

<?php /* S-03 removed: semester filtering is now hardcoded */ ?>
<div class="section">
    <?php if ($schoolId > 0): ?>
    <!-- SC-03: Single-school KPI -- school score, group avg, difference, participation -->
    <?php
        $schoolScoreKpi = null;
        foreach ($schoolsClimate as $sc) {
            if ($sc['school_id'] == $schoolId) { $schoolScoreKpi = $sc; break; }
        }
        $schoolAvgVal = $schoolScoreKpi ? $schoolScoreKpi['avg_score'] : null;
        $schoolPartRate = $schoolScoreKpi ? $schoolScoreKpi['participation_rate'] : 0;
        $diffFromGroup = ($schoolAvgVal !== null && $groupAvgForComparison > 0) ? round($schoolAvgVal - $groupAvgForComparison, 1) : null;
        $schoolRating = $schoolAvgVal !== null ? $engine->getScoreRating($schoolAvgVal) : null;
    ?>
    <div class="grid grid-4">
        <div class="card" style="border-top:4px solid <?= $schoolRating['color'] ?? '#6c757d' ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">متوسط المناخ <?= $schoolRating ? '(' . $schoolRating['label'] . ')' : '' ?></div>
                <div style="font-size:32px; font-weight:700; color:<?= $schoolRating['color'] ?? '#6c757d' ?>;">
                    <?= $schoolAvgVal !== null ? number_format($schoolAvgVal, 1) . '%' : '—' ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #17a2b8;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">متوسط المجموعة</div>
                <div style="font-size:32px; font-weight:700; color:#17a2b8;">
                    <?= $groupAvgForComparison > 0 ? number_format($groupAvgForComparison, 1) . '%' : '—' ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid <?= $diffFromGroup !== null ? ($diffFromGroup >= 0 ? '#28a745' : '#dc3545') : '#6c757d' ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">الفرق عن المجموعة</div>
                <div style="font-size:32px; font-weight:700; color:<?= $diffFromGroup !== null ? ($diffFromGroup >= 0 ? '#28a745' : '#dc3545') : '#6c757d' ?>;">
                    <?php if ($diffFromGroup !== null): ?>
                        <?= $diffFromGroup >= 0 ? '+' : '' ?><?= number_format($diffFromGroup, 1) ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid <?= $schoolPartRate >= 80 ? '#28a745' : ($schoolPartRate >= 60 ? '#17a2b8' : ($schoolPartRate >= 40 ? '#fd7e14' : '#dc3545')) ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">
                    نسبة المشاركة
                    <?php if ($schoolPartRate < 60 && $schoolPartRate > 0): ?>
                    <span style="font-size:11px; color:#fd7e14;">⚠️ منخفضة</span>
                    <?php elseif ($schoolPartRate < 40 && $schoolPartRate > 0): ?>
                    <span style="font-size:11px; color:#dc3545;">🚨 حرجة</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:32px; font-weight:700; color:<?= $schoolPartRate >= 80 ? '#28a745' : ($schoolPartRate >= 60 ? '#17a2b8' : ($schoolPartRate >= 40 ? '#fd7e14' : '#dc3545')) ?>;">
                    <?= $schoolPartRate > 0 ? $schoolPartRate . '%' : '—' ?>
                </div>
                <div style="margin-top:8px;">
                    <div style="background:var(--border-light); border-radius:var(--radius-md); height:6px; overflow:hidden;">
                        <div style="width:<?= min($schoolPartRate, 100) ?>%; height:100%; background:<?= $schoolPartRate >= 80 ? '#28a745' : ($schoolPartRate >= 60 ? '#17a2b8' : ($schoolPartRate >= 40 ? '#fd7e14' : '#dc3545')) ?>; border-radius:var(--radius-md);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- All-schools KPI: overall avg, highest, lowest, participation -->
    <div class="grid grid-4">
        <div class="card" style="border-top:4px solid <?= $kpiColor ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">متوسط المناخ العام <?= $overallRating ? '(' . $overallRating['label'] . ')' : '' ?></div>
                <div style="font-size:32px; font-weight:700; color:<?= $kpiColor ?>;">
                    <?= $overallAvg > 0 ? number_format($overallAvg, 1) . '%' : '—' ?>
                </div>
                <div style="font-size:12px; color:var(--text-muted);">
                    <?php if ($participatingSchools < $totalSchools): ?>
                    بناءً على <?= $participatingSchools ?>/<?= $totalSchools ?> مدارس
                    <?php else: ?>
                    <?= $totalSchools ?> مدرسة
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #28a745;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أعلى مدرسة</div>
                <div style="font-size:20px; font-weight:700; color:#28a745; min-height:48px;"><?= $highestSchool ? e($highestSchool) : '—' ?></div>
                <div style="font-size:18px; font-weight:600;"><?= $highestScore > 0 ? number_format($highestScore, 1) . '%' : '—' ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #dc3545;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أقل مدرسة</div>
                <div style="font-size:20px; font-weight:700; color:#dc3545; min-height:48px;"><?= $lowestSchool ? e($lowestSchool) : '—' ?></div>
                <div style="font-size:18px; font-weight:600;"><?= $lowestScore < 100 ? number_format($lowestScore, 1) . '%' : '—' ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid <?= $overallParticipation >= 80 ? '#28a745' : ($overallParticipation >= 60 ? '#17a2b8' : ($overallParticipation >= 40 ? '#fd7e14' : '#dc3545')) ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">
                    نسبة المشاركة
                    <?php if ($overallParticipation < 60 && $overallParticipation > 0): ?>
                    <span style="font-size:11px; color:#fd7e14;">⚠️ منخفضة</span>
                    <?php elseif ($overallParticipation < 40 && $overallParticipation > 0): ?>
                    <span style="font-size:11px; color:#dc3545;">🚨 حرجة</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:32px; font-weight:700; color:<?= $overallParticipation >= 80 ? '#28a745' : ($overallParticipation >= 60 ? '#17a2b8' : ($overallParticipation >= 40 ? '#fd7e14' : '#dc3545')) ?>;">
                    <?= $overallParticipation > 0 ? $overallParticipation . '%' : '—' ?>
                </div>
                <div style="margin-top:8px;">
                    <div style="background:var(--border-light); border-radius:var(--radius-md); height:6px; overflow:hidden;">
                        <div style="width:<?= min($overallParticipation, 100) ?>%; height:100%; background:<?= $overallParticipation >= 80 ? '#28a745' : ($overallParticipation >= 60 ? '#17a2b8' : ($overallParticipation >= 40 ? '#fd7e14' : '#dc3545')) ?>; border-radius:var(--radius-md);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($recommendations)): ?>
<div class="section">
    <div class="card" style="border:1px solid #17a2b8;">
        <div class="card-header" style="background:#17a2b8; color:white;">
            <h3 class="card-title">💡 التوصيات</h3>
        </div>
        <div class="card-body">
            <ul style="margin:0; padding-right:20px; line-height:2;">
                <?php foreach ($recommendations as $rec): ?>
                <li><?= e($rec) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $noDataForFilter = empty($schoolsClimate) || $participatingSchools === 0; ?>
<?php if (!$noDataForFilter): ?>
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 <?= $schoolId > 0 ? 'مقارنة بمتوسط المجموعة' : 'مقارنة المدارس' ?></h3></div>
            <div class="card-body">
                <div style="height:300px;"><canvas id="schoolsBarChart"></canvas></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 اتجاه المناخ<?= $schoolId > 0 ? '' : ' (آخر 6 فصول)' ?></h3></div>
            <div class="card-body">
                <?php if (count($trendData) <= 1): ?>
                <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px;">📉</div>
                    <p>بيانات غير كافية لعرض اتجاه. انتظر جمع بيانات الفصل القادم.</p>
                </div>
                <?php else: ?>
                <div style="height:300px;"><canvas id="trendLineChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($schoolId === 0): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">🏫 أداء المدارس</h3></div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($schoolsClimate)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">🌡️</div>
                <div class="table-empty-text">لا توجد بيانات مناخ للفترة المحددة</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>متوسط المناخ</th>
                            <th>التقييم</th>
                            <th>المشاركة</th>
                            <?php if ($ayId !== 0): ?><th>الاتجاه السنوي</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schoolsClimate as $i => $sc):
                            $rating = $sc['avg_score'] !== null ? $engine->getScoreRating($sc['avg_score']) : null;
                            $trend = $yearlyTrends[$sc['school_id']] ?? null;
                            $rowStyle = '';
                            if ($sc['is_absent']) {
                                $rowStyle = 'style="background:var(--background); opacity:0.6;"';
                            } elseif ($sc['avg_score'] !== null && $sc['avg_score'] < 50) {
                                $rowStyle = 'style="background:#fff5f5;"';
                            } elseif ($sc['avg_score'] !== null && $sc['avg_score'] >= 80) {
                                $rowStyle = 'style="background:#f0fff4;"';
                            }
                        ?>
                        <tr <?= $rowStyle ?>>
                            <td><?= $i + 1 ?></td>
                            <td style="font-weight:600;">
                                <?php if (!$sc['is_absent']): ?>
                                <a href="school.php?school_id=<?= $sc['school_id'] ?>&semester_id=<?= $semesterId ?>&academic_year_id=<?= $ayId ?>"><?= e($sc['school_name']) ?></a>
                                <?php else: ?>
                                <span style="color:var(--text-muted);"><?= e($sc['school_name']) ?>
                                    <?php if ($filterHasData): ?>
                                    <span style="font-size:11px; color:#dc3545; display:block;">لم تشارك بعد</span>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700; color:<?= $rating['color'] ?? 'var(--text-muted)' ?>;">
                                <?= $rating ? number_format($sc['avg_score'], 1) . '%' : '—' ?>
                            </td>
                            <td style="color:<?= $rating['color'] ?? 'var(--text-muted)' ?>;"><?= $rating['label'] ?? ($sc['is_absent'] ? 'غائب' : '—') ?></td>
                            <td>
                                <?php if ($sc['is_absent']): ?>
                                <span style="color:var(--text-muted);">—</span>
                                <?php else: ?>
                                <?= $sc['participation_rate'] ?>%
                                <div style="background:var(--border-light); border-radius:4px; height:4px; width:80px; overflow:hidden; margin-top:4px;">
                                    <div style="width:<?= min($sc['participation_rate'], 100) ?>%; height:100%; background:<?= $sc['participation_rate'] >= 60 ? '#28a745' : '#dc3545' ?>; border-radius:4px;"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($ayId !== 0): ?>
                            <td style="font-weight:600; text-align:center;">
                                <?php if ($trend): ?>
                                    <span style="color:<?= $trend['direction'] === 'up' ? '#28a745' : ($trend['direction'] === 'down' ? '#dc3545' : '#6c757d') ?>;">
                                        <?= $trend['direction'] === 'up' ? '▲' : ($trend['direction'] === 'down' ? '▼' : '→') ?>
                                        <?= $trend['diff'] > 0 ? '+' : '' ?><?= $trend['diff'] ?>%
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; /* $schoolId === 0 */ ?>

<?php if (!empty($interventionSchools)): ?>
<div class="section">
    <div class="card" style="border:2px solid #dc3545;">
        <div class="card-header" style="background:#dc3545; color:white;">
            <h3 class="card-title">🚨 مدارس تستوجب التدخل</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>المدرسة</th>
                            <th>متوسط المناخ</th>
                            <th>نسبة المشاركة</th>
                            <th>سبب التنبيه</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interventionSchools as $sc):
                            $reasons = [];
                            if ($sc['avg_score'] !== null && $sc['avg_score'] < 50) $reasons[] = 'مناخ منخفض (< 50%)';
                            if ($sc['participation_rate'] < 60) $reasons[] = 'مشاركة ضعيفة (< 60%)';
                        ?>
                        <tr style="background:#fff5f5;">
                            <td style="font-weight:600;">
                                <a href="school.php?school_id=<?= $sc['school_id'] ?>&semester_id=<?= $semesterId ?>&academic_year_id=<?= $ayId ?>" style="color:#dc3545;"><?= e($sc['school_name']) ?></a>
                            </td>
                            <td style="font-weight:700; color:#dc3545;">
                                <?= $sc['avg_score'] !== null ? number_format($sc['avg_score'], 1) . '%' : '—' ?>
                            </td>
                            <td style="color:<?= $sc['participation_rate'] < 60 ? '#dc3545' : 'inherit' ?>;"><?= $sc['participation_rate'] ?>%</td>
                            <td style="font-size:13px;"><?= implode(' | ', $reasons) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($noDataForFilter && !$fallbackPeriod): ?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:60px;">
            <div style="font-size:64px; margin-bottom:16px;">📋</div>
            <h4>لا توجد استجابات مكتملة</h4>
            <p style="color:var(--text-muted);">تم إنشاء الاستبيان ولكن لم تُستكمل الاستجابات بعد. يرجى تشجيع المدارس على المشاركة.</p>
            <a href="survey.php" class="btn btn-primary">🌡️ إنشاء استبيان جديد</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$barLabels = []; $barData = []; $barColors = [];
$chartJs = '';

if ($schoolId > 0) {
    // SC-03: Single school view — bar chart shows school vs group average
    $schoolScore = null;
    foreach ($schoolsClimate as $sc) {
        if ($sc['school_id'] == $schoolId && $sc['avg_score'] !== null) {
            $schoolScore = $sc['avg_score'];
            break;
        }
    }
    $groupAvgScore = $groupAvgForComparison;
    if ($schoolScore !== null) {
        $barLabels = ['هذه المدرسة', 'متوسط المجموعة'];
        $barData = [$schoolScore, $groupAvgScore];
        $barColors = ['#1a5632', '#6c757d'];
        $chartJs .= "
        eqasBarChart('schoolsBarChart', 'مقارنة بمتوسط المجموعة', " . json_encode($barLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة',
            data: " . json_encode($barData) . ",
            backgroundColor: " . json_encode($barColors) . ",
            borderColor: " . json_encode($barColors) . ",
            borderWidth: 1
        }], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    }
} else {
    // All schools view
    $palette = ['#1a5632','#17a2b8','#fd7e14','#6f42c1','#28a745','#dc3545','#ffc107','#007bff','#e83e8c','#20c997','#6610f2','#d63384'];
    $schoolsWithData = array_filter($schoolsClimate, function($sc) { return $sc['avg_score'] !== null; });
    foreach ($schoolsWithData as $j => $sc) {
        $barLabels[] = $sc['school_name'];
        $barData[] = $sc['avg_score'];
        $ratingColor = $engine->getScoreRating($sc['avg_score'])['color'];
        $barColors[] = $ratingColor;
    }
    if (!empty($barData)) {
        $chartJs .= "
        eqasBarChart('schoolsBarChart', 'مقارنة متوسط المناخ', " . json_encode($barLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'متوسط المناخ',
            data: " . json_encode($barData) . ",
            backgroundColor: " . json_encode($barColors) . ",
            borderColor: " . json_encode($barColors) . ",
            borderWidth: 1
        }], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    }
}

// Trend chart (line by semester or bar by year)
$trendChartTitle = $trendIsAnnual ? 'اتجاه المناخ (سنوي)' : 'اتجاه المناخ عبر الفصول';
if ($schoolId > 0 && count($groupTrendData) > 1 && count($trendData) > 1) {
    // SC-03: Dual chart — school trend + group average trend
    if ($trendIsAnnual) {
        $chartJs .= "
        eqasBarChart('trendLineChart', '{$trendChartTitle}', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [
            {
                label: '" . e($selectedSchoolName) . "',
                data: " . json_encode($trendData) . ",
                backgroundColor: 'rgba(26,86,50,0.7)',
                borderColor: '#1a5632',
                borderWidth: 1
            },
            {
                label: 'متوسط المجموعة',
                data: " . json_encode($groupTrendData) . ",
                backgroundColor: 'rgba(108,117,125,0.5)',
                borderColor: '#6c757d',
                borderWidth: 1
            }
        ], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    } else {
        $chartJs .= "
        eqasLineChart('trendLineChart', '{$trendChartTitle}', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [
            {
                label: '" . e($selectedSchoolName) . "',
                data: " . json_encode($trendData) . ",
                borderColor: '#1a5632',
                backgroundColor: 'rgba(26,86,50,0.1)',
                borderWidth: 3,
                fill: true
            },
            {
                label: 'متوسط المجموعة',
                data: " . json_encode($groupTrendData) . ",
                borderColor: '#6c757d',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false
            }
        ], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    }
} elseif (count($trendData) > 1) {
    if ($trendIsAnnual) {
        $chartJs .= "
        eqasBarChart('trendLineChart', '{$trendChartTitle}', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'متوسط المناخ',
            data: " . json_encode($trendData) . ",
            backgroundColor: ['#1a5632','#17a2b8','#fd7e14','#6f42c1','#28a745','#dc3545'],
            borderColor: ['#1a5632','#17a2b8','#fd7e14','#6f42c1','#28a745','#dc3545'],
            borderWidth: 1
        }], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    } else {
        $trendColor = '#17a2b8';
        if (count($trendData) >= 3) {
            $recent = array_slice($trendData, -3);
            if ($recent[2] > $recent[1] && $recent[1] > $recent[0]) $trendColor = '#28a745';
            elseif ($recent[2] < $recent[1] && $recent[1] < $recent[0]) $trendColor = '#dc3545';
        }
        $chartJs .= "
        eqasLineChart('trendLineChart', '{$trendChartTitle}', " . json_encode($trendLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'متوسط المناخ',
            data: " . json_encode($trendData) . ",
            borderColor: '{$trendColor}',
            backgroundColor: 'rgba(" . ($trendColor === '#28a745' ? '40,167,69' : ($trendColor === '#dc3545' ? '220,53,69' : '23,162,184')) . ",0.1)',
            fill: true
        }], {scales: {y: {beginAtZero: true, max: 100}}});\n";
    }
}

$pageJs = $chartJs;
?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
