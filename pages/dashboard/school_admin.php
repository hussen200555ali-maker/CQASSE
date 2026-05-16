<?php
/**
 * ============================================================
 * EQAS - ظ„ظˆط­ط© طھط­ظƒظ… ظ…ط¯ظٹط± ط§ظ„ظ…ط¯ط±ط³ط©
 * ظ†ط¸ط§ظ… ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط´ط§ظ…ظ„ - School Admin Dashboard
 * ============================================================
 * ظ„ظˆط­ط© طھط­ظƒظ… ظ…ط¯ظٹط± ط§ظ„ظ…ط¯ط±ط³ط© ظ…ط¹:
 * - ظ…ظ‚ظٹط§ط³ ظ…ط¤ط´ط± ط¬ظˆط¯ط© ط§ظ„طھط¹ظ„ظٹظ… + ط±ط§ط¯ط§ط±
 * - ط¥ط­طµط§ط¦ظٹط§طھ ط³ط±ظٹط¹ط© (ط·ظ„ط§ط¨طŒ ط­ط¶ظˆط±طŒ ظ…ط¹ط¯ظ„طŒ ط³ظ„ظˆظƒ)
 * - طھظ‚ط¯ظ… ط¥ظƒظ…ط§ظ„ ط§ظ„طھظ‚ظٹظٹظ…ط§طھ
 * - ظ…ظ„ط®طµ ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ
 * - ظ†ط¸ط±ط© ط¹ط§ظ…ط© ط¹ظ„ظ‰ ط§ظ„طھط±ظپظٹط¹
 * - ط±ط³ظ… ط¨ظٹط§ظ†ظٹ ظ„ظ„ط­ط¶ظˆط± ط§ظ„ط£ط³ط¨ظˆط¹ظٹ
 * - طھظˆط²ظٹط¹ ط§ظ„ط­ظˆط§ط¯ط« ط§ظ„ط³ظ„ظˆظƒظٹط©
 * - طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ
 * - ط§ظ„ظ…ظ‡ط§ظ… ط§ظ„ظ…ط¹ظ„ظ‚ط©
 * - ظ…ظ‚ط§ط±ظ†ط© ط£ط¯ط§ط، ط§ظ„ظپطµظˆظ„
 * - ط®ط· ط²ظ…ظ†ظٹ ظ„ظ„ظ†ط´ط§ط·ط§طھ ط§ظ„ط£ط®ظٹط±ط©
 * - ط¥ط¬ط±ط§ط،ط§طھ ط³ط±ظٹط¹ط©
 * ============================================================
 */

$pageTitle = 'ظ„ظˆط­ط© طھط­ظƒظ… ظ…ط¯ظٹط± ط§ظ„ظ…ط¯ط±ط³ط©';
$breadcrumb = ['ط§ظ„ط±ط¦ظٹط³ظٹط©' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ظ‡ط°ظ‡ ط§ظ„طµظپط­ط© ظ„ظ…ط¯ظٹط±ظٹ ط§ظ„ظ…ط¯ط§ط±ط³ ظپظ‚ط·.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط¥ط­طµط§ط¦ظٹط§طھ ط£ط³ط§ط³ظٹط©
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$counts = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => 0];
try {
    $counts['students'] = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
    $counts['students']->execute([$schoolId]); $counts['students'] = $counts['students']->fetchColumn();

    $counts['teachers'] = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1");
    $counts['teachers']->execute([$schoolId]); $counts['teachers'] = $counts['teachers']->fetchColumn();

    $counts['classes'] = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND academic_year_id = ? AND is_active = 1");
    $counts['classes']->execute([$schoolId, $academicYearId]); $counts['classes'] = $counts['classes']->fetchColumn();

    $counts['subjects'] = $db->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط¥ط­طµط§ط¦ظٹط§طھ ط³ط±ظٹط¹ط© ط¥ط¶ط§ظپظٹط© (ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±طŒ ط§ظ„ظ…ط¹ط¯ظ„طŒ ط§ظ„ط³ظ„ظˆظƒ)
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$quickStats = ['attendanceRate' => null, 'avgGpa' => null, 'behaviorScore' => null];
try {
    $stmt = $db->prepare("SELECT AVG(attendance_rate) as avg_rate FROM v_attendance_summary WHERE academic_year_id = ? AND class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1)");
    $stmt->execute([$academicYearId, $schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_rate'] !== null) $quickStats['attendanceRate'] = round($r['avg_rate'], 1);
} catch (Exception $e) { }

try {
    $stmt = $db->prepare("SELECT AVG(g.percentage) as avg_pct FROM grades g JOIN tests t ON g.test_id = t.id JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
    $stmt->execute([$schoolId, $academicYearId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_pct'] !== null) $quickStats['avgGpa'] = round($r['avg_pct'], 1);
} catch (Exception $e) { }

try {
    $stmt = $db->prepare("SELECT AVG(e.current_percentage) as avg_pct FROM evaluations e WHERE e.eval_type = 'behavior_score' AND e.academic_year_id = ? AND e.class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1)");
    $stmt->execute([$academicYearId, $schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_pct'] !== null) $quickStats['behaviorScore'] = round($r['avg_pct'], 1);
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ظ…ط¤ط´ط± ط¬ظˆط¯ط© ط§ظ„طھط¹ظ„ظٹظ…
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$eqiScore = null;
$eqiDimensions = [];
try {
    $stmt = $db->prepare("SELECT * FROM eqi_results WHERE school_id = ? AND is_active = 1 ORDER BY calculated_at DESC LIMIT 1");
    $stmt->execute([$schoolId]);
    $eqiRow = $stmt->fetch();
    if ($eqiRow && isset($eqiRow['overall_eqi']) && $eqiRow['overall_eqi'] !== null) {
        $eqiScore = round($eqiRow['overall_eqi'], 1);
    }
} catch (Exception $e) { }

// ط£ط¨ط¹ط§ط¯ EQI (ظ…ط­ط§ظƒط§ط© ظ…ظ† ط¨ظٹط§ظ†ط§طھ ط§ظ„طھظ‚ظٹظٹظ…ط§طھ)
$dimensionScores = [];
$dimensionNames = ['ط§ظ„ظ…ظ†ط§ط® ط§ظ„ظ…ط¯ط±ط³ظٹ' => 'climate', 'ط§ظ„ظ…ط±ط§ظپظ‚' => 'facility', 'ط£ط¯ط§ط، ط§ظ„ظ…ط¹ظ„ظ…ظٹظ†' => 'teaching', 'ط§ظ„طھط­طµظٹظ„ ط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹ' => 'academic', 'ط§ظ„ط³ظ„ظˆظƒ' => 'behavior', 'ط§ظ„ط­ط¶ظˆط±' => 'attendance'];
foreach ($dimensionNames as $label => $key) {
    $dimensionScores[$key] = null;
}

try {
    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['climate'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['facility'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key IN ('teaching_self','teaching_peer','teaching_admin') AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['teaching'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(g.percentage) as avg FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.school_id = ? AND g.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['academic'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// طھظ‚ط¯ظ… ط§ظ„طھظ‚ظٹظٹظ…ط§طھ
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$assessmentProgress = [
    'climate' => ['label' => 'ط§ظ„ظ…ظ†ط§ط® ط§ظ„ظ…ط¯ط±ط³ظٹ', 'done' => false],
    'facility' => ['label' => 'ط§ظ„ظ…ط±ط§ظپظ‚', 'done' => false],
    'teaching' => ['label' => 'طھظ‚ظٹظٹظ… ط§ظ„ظ…ط¹ظ„ظ…ظٹظ†', 'done' => false],
    'behavior' => ['label' => 'ط§ظ„ط³ظ„ظˆظƒ', 'done' => false],
    'skills' => ['label' => 'ط§ظ„ظ…ظ‡ط§ط±ط§طھ', 'done' => false],
];
foreach ($dimensionScores as $key => $val) {
    if ($val !== null && isset($assessmentProgress[$key])) {
        $assessmentProgress[$key]['done'] = true;
    }
}
$completedAssessments = count(array_filter($assessmentProgress, function($a) { return $a['done']; }));
$totalAssessments = count($assessmentProgress);
$assessmentPercent = $totalAssessments > 0 ? round(($completedAssessments / $totalAssessments) * 100) : 0;

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$alertSummary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
try {
    $stmt = $db->prepare("SELECT severity, COUNT(*) as cnt FROM alerts WHERE school_id = ? AND is_active = 1 AND status = 'open' GROUP BY severity");
    $stmt->execute([$schoolId ?: 0]);
    while ($row = $stmt->fetch()) {
        $sev = $row['severity'] ?? 'info';
        if (isset($alertSummary[$sev])) $alertSummary[$sev] = $row['cnt'];
        $alertSummary['total'] += $row['cnt'];
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط§ظ„طھط±ظپظٹط¹
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$promoStats = ['proposed' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
try {
    $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM promotions WHERE school_id = ? AND is_active = 1 GROUP BY status");
    $stmt->execute([$schoolId]);
    while ($row = $stmt->fetch()) {
        if (isset($promoStats[$row['status']])) $promoStats[$row['status']] = $row['cnt'];
        $promoStats['total'] += $row['cnt'];
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط¨ظٹط§ظ†ط§طھ ط§ظ„ط­ط¶ظˆط± ط§ظ„ط£ط³ط¨ظˆط¹ظٹ (ط¢ط®ط± 7 ط£ظٹط§ظ…)
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$weeklyAttendance = ['labels' => [], 'present' => [], 'absent' => [], 'late' => []];
try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dayName = formatDateAr($date, 'short');
        $weeklyAttendance['labels'][] = $dayName;

        $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance_daily WHERE date = ? AND class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1) AND is_active = 1 GROUP BY status");
        $stmt->execute([$date, $schoolId]);
        $dayData = ['present' => 0, 'absent' => 0, 'late' => 0];
        while ($row = $stmt->fetch()) {
            if (isset($dayData[$row['status']])) $dayData[$row['status']] = (int)$row['cnt'];
        }
        $weeklyAttendance['present'][] = $dayData['present'];
        $weeklyAttendance['absent'][] = $dayData['absent'];
        $weeklyAttendance['late'][] = $dayData['late'];
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// طھظˆط²ظٹط¹ ط§ظ„ط­ظˆط§ط¯ط« ط§ظ„ط³ظ„ظˆظƒظٹط© ط­ط³ط¨ ط§ظ„ظپط¦ط©
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$behaviorByCategory = ['labels' => [], 'counts' => [], 'colors' => []];
try {
    $stmt = $db->prepare("SELECT bc.name_ar, bc.type, COUNT(bi.id) as cnt FROM behavior_incidents bi JOIN behavior_categories bc ON bi.category_id = bc.id JOIN classes c ON bi.class_id = c.id WHERE c.school_id = ? AND bi.is_active = 1 AND bi.academic_year_id = ? GROUP BY bi.category_id ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$schoolId, $academicYearId]);
    $catColors = ['positive' => '#28a745', 'negative' => '#dc3545'];
    while ($row = $stmt->fetch()) {
        $behaviorByCategory['labels'][] = $row['name_ar'];
        $behaviorByCategory['counts'][] = (int)$row['cnt'];
        $behaviorByCategory['colors'][] = $catColors[$row['type']] ?? '#17a2b8';
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ (ظ…ط¬ظ…ظˆط¹ط§طھ ط§ظ„ظ†ط³ط¨ ط§ظ„ظ…ط¦ظˆظٹط©)
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$gradeDistribution = ['labels' => ['0-19%', '20-39%', '40-49%', '50-59%', '60-69%', '70-79%', '80-89%', '90-100%'], 'counts' => [0,0,0,0,0,0,0,0]];
try {
    $stmt = $db->prepare("SELECT g.percentage FROM grades g JOIN tests t ON g.test_id = t.id JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
    $stmt->execute([$schoolId, $academicYearId]);
    while ($row = $stmt->fetch()) {
        $p = $row['percentage'];
        if ($p < 20) $gradeDistribution['counts'][0]++;
        elseif ($p < 40) $gradeDistribution['counts'][1]++;
        elseif ($p < 50) $gradeDistribution['counts'][2]++;
        elseif ($p < 60) $gradeDistribution['counts'][3]++;
        elseif ($p < 70) $gradeDistribution['counts'][4]++;
        elseif ($p < 80) $gradeDistribution['counts'][5]++;
        elseif ($p < 90) $gradeDistribution['counts'][6]++;
        else $gradeDistribution['counts'][7]++;
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط§ظ„ظ…ظ‡ط§ظ… ط§ظ„ظ…ط¹ظ„ظ‚ط©
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$pendingTasks = [];
// ظ‚ط±ط§ط±ط§طھ ط§ظ„طھط±ظپظٹط¹ ط§ظ„ظ…ط¹ظ„ظ‚ط©
if ($promoStats['proposed'] > 0) {
    $pendingTasks[] = ['icon' => 'ًںژ“', 'label' => 'ظ‚ط±ط§ط±ط§طھ طھط±ظپظٹط¹ ظ…ط¹ظ„ظ‚ط©', 'count' => $promoStats['proposed'], 'link' => '../promotion/index.php', 'color' => 'var(--warning)'];
}
// طھظ†ط¨ظٹظ‡ط§طھ ط؛ظٹط± ظ…ط¹طھط±ظپ ط¨ظ‡ط§
if ($alertSummary['critical'] > 0) {
    $pendingTasks[] = ['icon' => 'ًںڑ¨', 'label' => 'طھظ†ط¨ظٹظ‡ط§طھ ط­ط±ط¬ط©', 'count' => $alertSummary['critical'], 'link' => '../alerts/index.php', 'color' => 'var(--danger)'];
}
// ط¥ظ‚ط±ط§ط±ط§طھ ط£ظˆظ„ظٹط§ط، ط§ظ„ط£ظ…ظˆط± ط§ظ„ظ…ط¹ظ„ظ‚ط©
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM parent_acknowledgments pa JOIN students s ON pa.student_id = s.id WHERE s.school_id = ? AND pa.is_acknowledged = 0 AND pa.is_active = 1");
    $stmt->execute([$schoolId]);
    $unacked = (int)$stmt->fetchColumn();
    if ($unacked > 0) {
        $pendingTasks[] = ['icon' => 'ًں“‌', 'label' => 'ط¥ظ‚ط±ط§ط±ط§طھ ظ…ط¹ظ„ظ‚ط©', 'count' => $unacked, 'link' => '../parent_ack/index.php', 'color' => 'var(--info)'];
    }
} catch (Exception $e) { }
// ط§ط®طھط¨ط§ط±ط§طھ ط¨ط¯ظˆظ† ط¯ط±ط¬ط§طھ
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tests t JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND t.is_active = 1 AND t.academic_year_id = ? AND t.status = 'draft'");
    $stmt->execute([$schoolId, $academicYearId]);
    $draftTests = (int)$stmt->fetchColumn();
    if ($draftTests > 0) {
        $pendingTasks[] = ['icon' => 'ًں“‌', 'label' => 'ط§ط®طھط¨ط§ط±ط§طھ ظ…ط³ظˆط¯ط©', 'count' => $draftTests, 'link' => '../academic_assessment/index.php', 'color' => 'var(--secondary)'];
    }
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ظ…ظ‚ط§ط±ظ†ط© ط£ط¯ط§ط، ط§ظ„ظپطµظˆظ„
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$classComparison = [];
try {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar as grade_name,
               (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1 AND se.status = 'enrolled') as student_count,
               (SELECT AVG(g.percentage) FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.class_id = c.id AND g.is_active = 1) as avg_grade,
               (SELECT AVG(v.attendance_rate) FROM v_attendance_summary v WHERE v.class_id = c.id) as avg_attendance,
               (SELECT AVG(e.current_percentage) FROM evaluations e WHERE e.eval_type = 'behavior_score' AND e.class_id = c.id) as avg_behavior
        FROM classes c
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
        ORDER BY gl.level_number, c.name_ar
        LIMIT 15
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classComparison = $stmt->fetchAll();
} catch (Exception $e) { }

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// ط§ظ„ظ†ط´ط§ط·ط§طھ ط§ظ„ط£ط®ظٹط±ط©
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$recentActivities = [];
try {
    $stmt = $db->prepare("
        SELECT al.*, u.full_name_ar as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.school_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$schoolId]);
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) { }

// ط§ط³ظ… ط§ظ„ظ…ط¯ط±ط³ط©
$schoolName = '';
try {
    $stmt = $db->prepare("SELECT name_ar FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $schoolName = $stmt->fetchColumn();
} catch (Exception $e) { }
?>

<style>
.dashboard-stat {
    text-align: center; padding: 20px 16px; border-radius: var(--radius-lg);
    background: var(--surface); border: 1px solid var(--border-light);
    transition: all var(--transition-base);
}
.dashboard-stat:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.dashboard-stat .stat-icon { font-size: 28px; margin-bottom: 8px; }
.dashboard-stat .stat-value { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
.dashboard-stat .stat-label { font-size: 13px; color: var(--text-secondary); }
.eqi-gauge { position: relative; width: 180px; height: 180px; margin: 0 auto; }
.eqi-gauge-value { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
.progress-item { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
.progress-item:last-child { border-bottom: none; }
.task-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border-light); }
.task-item:last-child { border-bottom: none; }
.task-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: var(--radius-full); font-size: 12px; font-weight: 700; color: white; padding: 0 6px; }
.timeline-item { display: flex; gap: 12px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid var(--border-light); }
.timeline-item:last-child { border-bottom: none; }
.timeline-dot { width: 10px; height: 10px; border-radius: var(--radius-full); margin-top: 5px; flex-shrink: 0; }
.class-perf-cell { padding: 6px 8px; text-align: center; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; }
</style>

<div class="section">
    <!-- ط¹ظ†ظˆط§ظ† ط§ظ„ظ…ط¯ط±ط³ط© -->
    <div class="card mb-6">
        <div class="card-body" style="padding:12px 24px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h2 style="margin:0;font-size:20px;">ًںڈ« <?= e($schoolName) ?></h2>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
                <?= formatDateAr(date('Y-m-d'), 'full') ?>
            </div>
        </div>
    </div>

    <!-- ط¨ط·ط§ظ‚ط§طھ ط§ظ„ط¥ط­طµط§ط¦ظٹط§طھ ط§ظ„ط³ط±ظٹط¹ط© -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--primary);">
            <div class="stat-icon">ًں‘¥</div>
            <div class="stat-value" style="color:var(--primary);"><?= number_format($counts['students']) ?></div>
            <div class="stat-label">ط§ظ„ط·ظ„ط§ط¨</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">ًں“…</div>
            <div class="stat-value" style="color:var(--success);"><?= $quickStats['attendanceRate'] !== null ? $quickStats['attendanceRate'] . '%' : '-' ?></div>
            <div class="stat-label">ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">ًں“ٹ</div>
            <div class="stat-value" style="color:var(--info);"><?= $quickStats['avgGpa'] !== null ? $quickStats['avgGpa'] . '%' : '-' ?></div>
            <div class="stat-label">ظ…طھظˆط³ط· ط§ظ„ط¯ط±ط¬ط§طھ</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">ًں“‹</div>
            <div class="stat-value" style="color:var(--secondary);"><?= $quickStats['behaviorScore'] !== null ? $quickStats['behaviorScore'] . '%' : '-' ?></div>
            <div class="stat-label">ظ…ط¹ط¯ظ„ ط§ظ„ط³ظ„ظˆظƒ</div>
        </div>
    </div>

    <!-- ط¥ط­طµط§ط¦ظٹط§طھ ط«ط§ظ†ظˆظٹط© -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">ًں‘¨â€چًںڈ«</div>
            <div class="stat-value" style="color:var(--success);"><?= number_format($counts['teachers']) ?></div>
            <div class="stat-label">ط§ظ„ظ…ط¹ظ„ظ…ظˆظ†</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">ًں“ڑ</div>
            <div class="stat-value" style="color:var(--info);"><?= number_format($counts['classes']) ?></div>
            <div class="stat-label">ط§ظ„ظپطµظˆظ„</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">ًں“–</div>
            <div class="stat-value" style="color:var(--secondary);"><?= number_format($counts['subjects']) ?></div>
            <div class="stat-label">ط§ظ„ظ…ظˆط§ط¯</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--danger);">
            <div class="stat-icon">ًںڑ¨</div>
            <div class="stat-value" style="color:var(--danger);"><?= $alertSummary['total'] ?></div>
            <div class="stat-label">طھظ†ط¨ظٹظ‡ط§طھ ظ†ط´ط·ط©</div>
        </div>
    </div>

    <div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- ظ…ظ‚ظٹط§ط³ ظ…ط¤ط´ط± ط¬ظˆط¯ط© ط§ظ„طھط¹ظ„ظٹظ… -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">ًں“ٹ ظ…ط¤ط´ط± ط¬ظˆط¯ط© ط§ظ„طھط¹ظ„ظٹظ…</h3>
                <a href="../eqi/report.php" class="btn btn-sm btn-light">ط§ظ„طھظ‚ط±ظٹط±</a>
            </div>
            <div class="card-body" style="text-align:center;">
                <?php if ($eqiScore !== null):
                    $rating = getEqiRating($eqiScore);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="font-size:64px;font-weight:bold;color:var(--<?= $rating[2] ?>);"><?= $eqiScore ?>%</div>
                    <span class="badge badge-<?= $rating[1] ?>" style="font-size:14px;"><?= $rating[0] ?></span>
                </div>
                <div style="width:100%;height:12px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= $eqiScore ?>%;height:100%;background:var(--<?= $rating[2] ?>);border-radius:var(--radius-full);transition:width 1s;"></div>
                </div>
                <?php else: ?>
                <div style="padding:30px;color:var(--text-muted);">
                    <div style="font-size:48px;margin-bottom:12px;">ًں“ٹ</div>
                    <p>ظ„ظ… ظٹطھظ… ط­ط³ط§ط¨ ط§ظ„ظ…ط¤ط´ط± ط¨ط¹ط¯</p>
                    <a href="../eqi/calculate.php" class="btn btn-primary btn-sm">ط¨ط¯ط، ط§ظ„ط­ط³ط§ط¨</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- طھظ‚ط¯ظ… ط§ظ„طھظ‚ظٹظٹظ…ط§طھ -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“‹ طھظ‚ط¯ظ… ط§ظ„طھظ‚ظٹظٹظ…ط§طھ</h3>
            </div>
            <div class="card-body">
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="font-size:36px;font-weight:bold;color:var(--primary);"><?= $assessmentPercent ?>%</div>
                    <div style="color:var(--text-secondary);font-size:13px;"><?= $completedAssessments ?> ظ…ظ† <?= $totalAssessments ?> ظ…ظƒطھظ…ظ„</div>
                </div>
                <div style="width:100%;height:8px;background:var(--border-light);border-radius:var(--radius-full);margin-bottom:16px;">
                    <div style="width:<?= $assessmentPercent ?>%;height:100%;background:var(--primary);border-radius:var(--radius-full);"></div>
                </div>
                <?php foreach ($assessmentProgress as $key => $ap): ?>
                <div class="progress-item">
                    <span style="width:20px;"><?= $ap['done'] ? 'âœ…' : 'âڈ³' ?></span>
                    <span style="flex:1;"><?= $ap['label'] ?></span>
                    <span style="font-size:12px;color:<?= $ap['done'] ? 'var(--success)' : 'var(--text-muted)' ?>;">
                        <?= $ap['done'] ? 'ظ…ظƒطھظ…ظ„' : 'ط¨ط§ظ†طھط¸ط§ط±' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">ًںڑ¨ ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ</h3>
                <a href="../alerts/index.php" class="btn btn-sm btn-light">ط§ظ„ظƒظ„</a>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(220,53,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--danger);"><?= $alertSummary['critical'] ?></div>
                        <div style="font-size:11px;color:var(--danger);">ط­ط±ط¬</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(255,193,7,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--warning);"><?= $alertSummary['warning'] ?></div>
                        <div style="font-size:11px;color:var(--warning);">طھط­ط°ظٹط±</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(23,162,184,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--info);"><?= $alertSummary['info'] ?></div>
                        <div style="font-size:11px;color:var(--info);">ظ…ط¹ظ„ظˆظ…ط§طھ</div>
                    </div>
                </div>
                <?php if ($alertSummary['total'] === 0): ?>
                <div style="text-align:center;color:var(--success);padding:12px;">âœ… ظ„ط§ طھظˆط¬ط¯ طھظ†ط¨ظٹظ‡ط§طھ ظ†ط´ط·ط©</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ط§ظ„طھط±ظپظٹط¹ -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">ًںژ“ ط§ظ„طھط±ظپظٹط¹</h3>
                <a href="../promotion/index.php" class="btn btn-sm btn-light">ط§ظ„طھظپط§طµظٹظ„</a>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(23,162,184,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--info);"><?= $promoStats['proposed'] ?></div>
                        <div style="font-size:11px;">ظ…ظ‚طھط±ط­</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(40,167,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--success);"><?= $promoStats['approved'] ?></div>
                        <div style="font-size:11px;">ظ…ط¹طھظ…ط¯</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(220,53,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--danger);"><?= $promoStats['rejected'] ?></div>
                        <div style="font-size:11px;">ظ…ط±ظپظˆط¶</div>
                    </div>
                </div>
                <?php if ($promoStats['proposed'] > 0): ?>
                <a href="../promotion/batch_approve.php" class="btn btn-warning btn-sm" style="width:100%;">âڑ، ط§ط¹طھظ…ط§ط¯ ط§ظ„ظ‚ط±ط§ط±ط§طھ ط§ظ„ظ…ط¹ظ„ظ‚ط© (<?= $promoStats['proposed'] ?>)</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ط§ظ„ظ…ظ‡ط§ظ… ط§ظ„ظ…ط¹ظ„ظ‚ط© -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“Œ ط§ظ„ظ…ظ‡ط§ظ… ط§ظ„ظ…ط¹ظ„ظ‚ط© <span style="background:var(--danger);color:white;padding:1px 8px;border-radius:var(--radius-full);font-size:11px;"><?= count($pendingTasks) ?></span></h3>
            </div>
            <div class="card-body">
                <?php if (empty($pendingTasks)): ?>
                <div style="text-align:center;padding:20px;color:var(--success);">
                    âœ… ظ„ط§ طھظˆط¬ط¯ ظ…ظ‡ط§ظ… ظ…ط¹ظ„ظ‚ط©
                </div>
                <?php else: ?>
                <?php foreach ($pendingTasks as $task): ?>
                <a href="<?= $task['link'] ?>" style="text-decoration:none;color:inherit;">
                    <div class="task-item">
                        <span style="font-size:20px;"><?= $task['icon'] ?></span>
                        <span style="flex:1;font-size:14px;"><?= e($task['label']) ?></span>
                        <span class="task-badge" style="background:<?= $task['color'] ?>;"><?= $task['count'] ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ط£ط¨ط¹ط§ط¯ ظ…ط¤ط´ط± ط§ظ„ط¬ظˆط¯ط© (ط£ظپظ‚ظٹ) -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“گ طھظپطµظٹظ„ ط£ط¨ط¹ط§ط¯ ط§ظ„ظ…ط¤ط´ط±</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="eqiDimBarChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ط±ط³ظˆظ… ط¨ظٹط§ظ†ظٹط© - ط§ظ„طµظپ ط§ظ„ط£ظˆظ„ -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“ٹ ط£ط¨ط¹ط§ط¯ ظ…ط¤ط´ط± ط§ظ„ط¬ظˆط¯ط©</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="eqiRadarChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًںڑ¨ طھظˆط²ظٹط¹ ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="alertsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ط±ط³ظˆظ… ط¨ظٹط§ظ†ظٹط© - ط§ظ„طµظپ ط§ظ„ط«ط§ظ†ظٹ -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- ط§ظ„ط­ط¶ظˆط± ط§ظ„ط£ط³ط¨ظˆط¹ظٹ -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“… ظ†ط¸ط±ط© ط¹ط§ظ…ط© ط¹ظ„ظ‰ ط§ظ„ط­ط¶ظˆط± (ط¢ط®ط± 7 ط£ظٹط§ظ…)</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="weeklyAttendanceChart"></canvas>
            </div>
        </div>
        <!-- طھظˆط²ظٹط¹ ط§ظ„ط­ظˆط§ط¯ط« ط§ظ„ط³ظ„ظˆظƒظٹط© -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“‹ ظ…ظ„ط®طµ ط§ظ„ط­ظˆط§ط¯ط« ط§ظ„ط³ظ„ظˆظƒظٹط©</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="behaviorCategoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ط±ط³ظˆظ… ط¨ظٹط§ظ†ظٹط© - ط§ظ„طµظپ ط§ظ„ط«ط§ظ„ط« -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“ٹ طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="gradeDistChart"></canvas>
            </div>
        </div>
        <!-- ط§ظ„ظ†ط´ط§ط·ط§طھ ط§ظ„ط£ط®ظٹط±ط© -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں•گ ط¢ط®ط± ط§ظ„ظ†ط´ط§ط·ط§طھ</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div style="max-height:300px;overflow-y:auto;">
                    <?php if (empty($recentActivities)): ?>
                    <div style="text-align:center;padding:30px;color:var(--text-muted);">ظ„ط§ طھظˆط¬ط¯ ظ†ط´ط§ط·ط§طھ ظ…ط³ط¬ظ„ط©</div>
                    <?php else: ?>
                    <?php foreach ($recentActivities as $act):
                        $actColors = ['create' => 'var(--success)', 'update' => 'var(--info)', 'delete' => 'var(--danger)', 'login' => 'var(--primary)', 'default' => 'var(--secondary)'];
                        $dotColor = $actColors[$act['action'] ?? 'default'] ?? $actColors['default'];
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot" style="background:<?= $dotColor ?>;"></div>
                        <div style="flex:1;">
                            <div style="display:flex;justify-content:space-between;">
                                <strong style="font-size:12px;"><?= e($act['user_name'] ?? 'ظ†ط¸ط§ظ…') ?></strong>
                                <span style="font-size:10px;color:var(--text-muted);"><?= formatDateAr($act['created_at'], 'short') ?></span>
                            </div>
                            <div style="color:var(--text-secondary);font-size:12px;margin-top:2px;"><?= e($act['description'] ?? $act['action']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ظ…ظ‚ط§ط±ظ†ط© ط£ط¯ط§ط، ط§ظ„ظپطµظˆظ„ -->
    <?php if (!empty($classComparison)): ?>
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">ًں“ڑ ظ…ظ‚ط§ط±ظ†ط© ط£ط¯ط§ط، ط§ظ„ظپطµظˆظ„</h3>
        </div>
        <div class="card-body" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;min-width:600px;">
                <thead>
                    <tr>
                        <th>ط§ظ„طµظپ</th>
                        <th>ط§ظ„ظپطµظ„</th>
                        <th>ط§ظ„ط·ظ„ط§ط¨</th>
                        <th>ظ…طھظˆط³ط· ط§ظ„ط¯ط±ط¬ط§طھ</th>
                        <th>ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±</th>
                        <th>ظ…ط¹ط¯ظ„ ط§ظ„ط³ظ„ظˆظƒ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classComparison as $cl):
                        $gradeVal = $cl['avg_grade'] !== null ? round($cl['avg_grade'], 1) : null;
                        $attVal = $cl['avg_attendance'] !== null ? round($cl['avg_attendance'], 1) : null;
                        $behVal = $cl['avg_behavior'] !== null ? round($cl['avg_behavior'], 1) : null;
                        $gradeColor = $gradeVal !== null ? ($gradeVal >= 70 ? 'var(--success)' : ($gradeVal >= 50 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                        $attColor = $attVal !== null ? ($attVal >= 85 ? 'var(--success)' : ($attVal >= 70 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                        $behColor = $behVal !== null ? ($behVal >= 70 ? 'var(--success)' : ($behVal >= 50 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                    ?>
                    <tr>
                        <td><?= e($cl['grade_name']) ?></td>
                        <td><?= e($cl['name_ar']) ?></td>
                        <td style="text-align:center;"><?= $cl['student_count'] ?></td>
                        <td><span class="class-perf-cell" style="color:<?= $gradeColor ?>;background:<?= $gradeVal !== null ? ($gradeVal >= 70 ? 'rgba(40,167,69,0.1)' : ($gradeVal >= 50 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $gradeVal !== null ? $gradeVal . '%' : '-' ?></span></td>
                        <td><span class="class-perf-cell" style="color:<?= $attColor ?>;background:<?= $attVal !== null ? ($attVal >= 85 ? 'rgba(40,167,69,0.1)' : ($attVal >= 70 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $attVal !== null ? $attVal . '%' : '-' ?></span></td>
                        <td><span class="class-perf-cell" style="color:<?= $behColor ?>;background:<?= $behVal !== null ? ($behVal >= 70 ? 'rgba(40,167,69,0.1)' : ($behVal >= 50 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $behVal !== null ? $behVal . '%' : '-' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // ط±ط§ط¯ط§ط± ط£ط¨ط¹ط§ط¯ ط§ظ„ظ…ط¤ط´ط±
    var dimData = {
        dimensions: <?= json_encode($dimensionScores, JSON_UNESCAPED_UNICODE) ?>,
        overall: <?= $eqiScore ?: 0 ?>
    };
    eqasEqiRadar('eqiRadarChart', dimData);

    // طھظˆط²ظٹط¹ ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ
    eqasPieChart('alertsChart', '',
        ['ط­ط±ط¬', 'طھط­ط°ظٹط±', 'ظ…ط¹ظ„ظˆظ…ط§طھ'],
        [<?= $alertSummary['critical'] ?>, <?= $alertSummary['warning'] ?>, <?= $alertSummary['info'] ?>],
        ['#dc3545', '#ffc107', '#17a2b8']
    );

    // ط£ط¨ط¹ط§ط¯ ط§ظ„ظ…ط¤ط´ط± - ط´ط±ظٹط· ط£ظپظ‚ظٹ
    var dimLabels = <?= json_encode(array_keys($dimensionNames), JSON_UNESCAPED_UNICODE) ?>;
    var dimValues = [];
    <?php foreach ($dimensionNames as $label => $key): ?>
    dimValues.push(<?= $dimensionScores[$key] ?? 0 ?>);
    <?php endforeach; ?>
    eqasBarChart('eqiDimBarChart', '',
        dimLabels,
        [{label: 'ط§ظ„ط¯ط±ط¬ط©', data: dimValues, backgroundColor: ['#1a5632','#c8a951','#17a2b8','#28a745','#fd7e14','#6f42c1']}],
        {indexAxis: 'y'}
    );

    // ط§ظ„ط­ط¶ظˆط± ط§ظ„ط£ط³ط¨ظˆط¹ظٹ
    eqasBarChart('weeklyAttendanceChart', '',
        <?= json_encode($weeklyAttendance['labels'], JSON_UNESCAPED_UNICODE) ?>,
        [
            {label: 'ط­ط§ط¶ط±', data: <?= json_encode($weeklyAttendance['present']) ?>, backgroundColor: '#28a745'},
            {label: 'ط؛ط§ط¦ط¨', data: <?= json_encode($weeklyAttendance['absent']) ?>, backgroundColor: '#dc3545'},
            {label: 'ظ…طھط£ط®ط±', data: <?= json_encode($weeklyAttendance['late']) ?>, backgroundColor: '#ffc107'}
        ]
    );

    // ط§ظ„ط­ظˆط§ط¯ط« ط§ظ„ط³ظ„ظˆظƒظٹط© ط­ط³ط¨ ط§ظ„ظپط¦ط©
    eqasPieChart('behaviorCategoryChart', '',
        <?= json_encode($behaviorByCategory['labels'], JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($behaviorByCategory['counts']) ?>,
        <?= json_encode($behaviorByCategory['colors']) ?>,
        false
    );

    // طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ
    eqasBarChart('gradeDistChart', '',
        <?= json_encode($gradeDistribution['labels']) ?>,
        [{
            label: 'ط¹ط¯ط¯ ط§ظ„ط·ظ„ط§ط¨',
            data: <?= json_encode($gradeDistribution['counts']) ?>,
            backgroundColor: ['#dc3545','#dc3545','#fd7e14','#ffc107','#ffc107','#17a2b8','#28a745','#1a5632']
        }]
    );
    });
    </script>

    <!-- ط¥ط¬ط±ط§ط،ط§طھ ط³ط±ظٹط¹ط© -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">âڑ، ط¥ط¬ط±ط§ط،ط§طھ ط³ط±ظٹط¹ط©</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto" style="gap:12px;">
                <a href="../setup/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">âڑ™ï¸ڈ</div><div style="font-size:13px;">ط§ظ„ط¥ط¹ط¯ط§ط¯ط§طھ ط§ظ„ظ‡ظٹظƒظ„ظٹط©</div>
                </a>
                <a href="../supervision/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًں“‹</div><div style="font-size:13px;">ط§ظ„طھظ‚ظٹظٹظ…ط§طھ ط§ظ„ط¥ط´ط±ط§ظپظٹط©</div>
                </a>
                <a href="../academic_assessment/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًں“ٹ</div><div style="font-size:13px;">ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹ</div>
                </a>
                <a href="../attendance/daily.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًں“…</div><div style="font-size:13px;">ط§ظ„ط­ط¶ظˆط± ط§ظ„ظٹظˆظ…ظٹ</div>
                </a>
                <a href="../behavior/evaluate.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًں“‹</div><div style="font-size:13px;">طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒ</div>
                </a>
                <a href="../alerts/run_checks.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًںڑ¨</div><div style="font-size:13px;">ظپط­طµ ط§ظ„طھظ†ط¨ظٹظ‡ط§طھ</div>
                </a>
                <a href="../reports/school_report.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًں“„</div><div style="font-size:13px;">طھظ‚ط±ظٹط± ط§ظ„ظ…ط¯ط±ط³ط©</div>
                </a>
                <a href="../promotion/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">ًںژ“</div><div style="font-size:13px;">ط¥ط¯ط§ط±ط© ط§ظ„طھط±ظپظٹط¹</div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
