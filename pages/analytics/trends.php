<?php
$pageTitle = 'تقارير الاتجاهات الزمنية';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => 'index.php',
    'تقارير الاتجاهات الزمنية' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../../index.php');
}

// ============================================================================
// نوع المؤشر
// ============================================================================
$type = $_GET['type'] ?? 'eqi';
$validTypes = ['eqi', 'attendance', 'success', 'cohort'];
if (!in_array($type, $validTypes)) $type = 'eqi';

$typeConfig = [
    'eqi'        => ['label' => 'مؤشر جودة التعليم (EQI)', 'icon' => '📊', 'unit' => '', 'decimals' => 1],
    'attendance' => ['label' => 'معدل الحضور',           'icon' => '📋', 'unit' => '%', 'decimals' => 1],
    'success'    => ['label' => 'نسبة النجاح',            'icon' => '✅', 'unit' => '%', 'decimals' => 1],
    'cohort'     => ['label' => 'مقارنة الأفواج',         'icon' => '👥', 'unit' => '%', 'decimals' => 1],
];
$cfg = $typeConfig[$type];

// ============================================================================
// الفلاتر العامة
// ============================================================================
$selectedYearId   = $_GET['year_id'] ?? null;
$selectedSchoolId = $_GET['school_id'] ?? null;
$selectedSchoolName = null;

$selectedSemesterId   = null;
$selectedSemesterName = null;
$selectedGradeIds     = [];

if ($type !== 'cohort') {
    $selectedSemesterId = $_GET['semester_id'] ?? null;
} else {
    if (isset($_GET['grade_ids']) && is_array($_GET['grade_ids'])) {
        $selectedGradeIds = array_map('intval', $_GET['grade_ids']);
    } elseif (!empty($_GET['grade_ids'])) {
        $selectedGradeIds = [intval($_GET['grade_ids'])];
    }
}

// ============================================================================
// قوائم الفلاتر
// ============================================================================
$academicYears = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC")->fetchAll();

$yearsForTrend = $db->query("SELECT id, name, start_date FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date ASC")->fetchAll();

$selectedYearName = null;
if ($selectedYearId) {
    $stmt = $db->prepare("SELECT name FROM academic_years WHERE id = ?");
    $stmt->execute([$selectedYearId]);
    $selectedYearName = $stmt->fetchColumn();
}

if ($type !== 'cohort') {
    if ($selectedYearId) {
        $stmt = $db->prepare("SELECT id, name_ar AS name FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedYearId]);
        $semesters = $stmt->fetchAll();
    } else {
        $semesters = $db->query("SELECT id, name_ar AS name FROM semesters WHERE is_active = 1 ORDER BY start_date DESC")->fetchAll();
    }

    if ($selectedSemesterId) {
        $stmt = $db->prepare("SELECT name_ar FROM semesters WHERE id = ?");
        $stmt->execute([$selectedSemesterId]);
        $selectedSemesterName = $stmt->fetchColumn();
    }
}

$schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

if ($selectedSchoolId) {
    $stmt = $db->prepare("SELECT name_ar FROM schools WHERE id = ?");
    $stmt->execute([$selectedSchoolId]);
    $selectedSchoolName = $stmt->fetchColumn();
}

$gradeLevels = [];
if ($type === 'cohort') {
    $gradeLevels = $db->query("SELECT id, name_ar, level_number FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();
}

// ============================================================================
// دوال التقييم
// ============================================================================
function metricRating($score, $type) {
    if ($score <= 0) return ['label' => '—', 'class' => 'badge-no-data'];
    if ($type === 'attendance') {
        if ($score >= 95) return ['label' => 'ممتاز', 'class' => 'badge-success'];
        if ($score >= 90) return ['label' => 'جيد',   'class' => 'badge-info'];
        if ($score >= 80) return ['label' => 'مقبول',  'class' => 'badge-warning'];
        return ['label' => 'ضعيف', 'class' => 'badge-danger'];
    }
    if ($score >= 80) return ['label' => 'ممتاز', 'class' => 'badge-success'];
    if ($score >= 65) return ['label' => 'جيد',   'class' => 'badge-info'];
    if ($score >= 50) return ['label' => 'مقبول',  'class' => 'badge-warning'];
    return ['label' => 'ضعيف', 'class' => 'badge-danger'];
}

function buildTabUrl($tabType, $currentParams) {
    $parts = [];
    if (!empty($currentParams['year_id']))   $parts[] = 'year_id=' . urlencode($currentParams['year_id']);
    if (!empty($currentParams['school_id'])) $parts[] = 'school_id=' . urlencode($currentParams['school_id']);
    if ($tabType !== 'cohort' && !empty($currentParams['semester_id'])) {
        $parts[] = 'semester_id=' . urlencode($currentParams['semester_id']);
    }
    if ($tabType === 'cohort' && !empty($currentParams['grade_ids']) && is_array($currentParams['grade_ids'])) {
        foreach ($currentParams['grade_ids'] as $gid) {
            $parts[] = 'grade_ids[]=' . urlencode($gid);
        }
    }
    $parts[] = 'type=' . urlencode($tabType);
    return 'trends.php?' . implode('&', $parts);
}

// ============================================================================
// استعلام البيانات حسب النوع
// ============================================================================
$trendData      = [];
$comparisonData = [];
$prevValue      = null;
$overallSum     = 0;
$overallCount   = 0;
$cohortMatrix   = [];
$cohortGradeIds = [];

$semesterNameForMatch = $selectedSemesterName;

switch ($type) {

    // ────────────────────────────────────────────────────────────────────────
    // EQI
    // ────────────────────────────────────────────────────────────────────────
    case 'eqi':
        foreach ($yearsForTrend as $year) {
            $yearId = $year['id'];

            if ($selectedSchoolId) {
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT er.overall_eqi
                        FROM equi_results er
                        JOIN semesters s ON er.semester_id = s.id
                        WHERE er.academic_year_id = ?
                          AND er.school_id = ?
                          AND s.name_ar = ?
                          AND er.is_active = 1
                          AND er.overall_eqi IS NOT NULL
                        LIMIT 1
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT AVG(overall_eqi)
                        FROM equi_results
                        WHERE academic_year_id = ?
                          AND school_id = ?
                          AND is_active = 1
                          AND overall_eqi IS NOT NULL
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId]);
                }
                $avgValue = (float) $stmt->fetchColumn();

                if ($semesterNameForMatch) {
                    $stmtComp = $db->prepare("
                        SELECT AVG(er.overall_eqi)
                        FROM equi_results er
                        JOIN semesters s ON er.semester_id = s.id
                        WHERE er.academic_year_id = ?
                          AND s.name_ar = ?
                          AND er.is_active = 1
                          AND er.overall_eqi IS NOT NULL
                    ");
                    $stmtComp->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmtComp = $db->prepare("
                        SELECT AVG(overall_eqi)
                        FROM equi_results
                        WHERE academic_year_id = ?
                          AND is_active = 1
                          AND overall_eqi IS NOT NULL
                    ");
                    $stmtComp->execute([$yearId]);
                }
                $compValue = (float) $stmtComp->fetchColumn();
            } else {
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT AVG(er.overall_eqi)
                        FROM equi_results er
                        JOIN semesters s ON er.semester_id = s.id
                        WHERE er.academic_year_id = ?
                          AND s.name_ar = ?
                          AND er.is_active = 1
                          AND er.overall_eqi IS NOT NULL
                    ");
                    $stmt->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT AVG(overall_eqi)
                        FROM equi_results
                        WHERE academic_year_id = ?
                          AND is_active = 1
                          AND overall_eqi IS NOT NULL
                    ");
                    $stmt->execute([$yearId]);
                }
                $avgValue = (float) $stmt->fetchColumn();
                $compValue = null;
            }

            $change = $changePct = null;
            if ($prevValue !== null && $avgValue > 0 && $prevValue > 0) {
                $change = $avgValue - $prevValue;
                $changePct = round(($change / $prevValue) * 100, 1);
            }

            $trendData[] = [
                'year'       => $year['name'],
                'year_id'    => $yearId,
                'value'      => $avgValue,
                'change'     => $change,
                'change_pct' => $changePct,
                'prev_value' => $prevValue,
            ];
            $comparisonData[] = $compValue;

            if ($avgValue > 0) { $overallSum += $avgValue; $overallCount++; }
            $prevValue = $avgValue;
        }
        break;

    // ────────────────────────────────────────────────────────────────────────
    // ATTENDANCE
    // ────────────────────────────────────────────────────────────────────────
    case 'attendance':
        foreach ($yearsForTrend as $year) {
            $yearId = $year['id'];

            if ($selectedSchoolId) {
                // School-specific query — attendances have no school_id, JOIN via students
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        JOIN semesters sem ON ats.semester_id = sem.id
                        WHERE ats.academic_year_id = ?
                          AND s.school_id = ?
                          AND sem.name_ar = ?
                          AND ats.is_active = 1
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        WHERE ats.academic_year_id = ?
                          AND s.school_id = ?
                          AND ats.is_active = 1
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId]);
                }

                // Group comparison
                if ($semesterNameForMatch) {
                    $stmtComp = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        JOIN semesters sem ON ats.semester_id = sem.id
                        WHERE ats.academic_year_id = ?
                          AND sem.name_ar = ?
                          AND ats.is_active = 1
                    ");
                    $stmtComp->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmtComp = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        WHERE ats.academic_year_id = ?
                          AND ats.is_active = 1
                    ");
                    $stmtComp->execute([$yearId]);
                }
                $compValue = (float) $stmtComp->fetchColumn();
            } else {
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        JOIN semesters sem ON ats.semester_id = sem.id
                        WHERE ats.academic_year_id = ?
                          AND sem.name_ar = ?
                          AND ats.is_active = 1
                    ");
                    $stmt->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT AVG(ats.attendance_rate)
                        FROM attendance_summaries ats
                        JOIN students s ON s.id = ats.student_id AND s.is_active = 1
                        WHERE ats.academic_year_id = ?
                          AND ats.is_active = 1
                    ");
                    $stmt->execute([$yearId]);
                }
                $compValue = null;
            }

            $avgValue = (float) $stmt->fetchColumn();

            $change = $changePct = null;
            if ($prevValue !== null && $avgValue > 0 && $prevValue > 0) {
                $change = $avgValue - $prevValue;
                $changePct = round(($change / $prevValue) * 100, 1);
            }

            $trendData[] = [
                'year'       => $year['name'],
                'year_id'    => $yearId,
                'value'      => $avgValue,
                'change'     => $change,
                'change_pct' => $changePct,
                'prev_value' => $prevValue,
            ];
            $comparisonData[] = $compValue;

            if ($avgValue > 0) { $overallSum += $avgValue; $overallCount++; }
            $prevValue = $avgValue;
        }
        break;

    // ────────────────────────────────────────────────────────────────────────
    // SUCCESS (pass rate)
    // ────────────────────────────────────────────────────────────────────────
    case 'success':
        foreach ($yearsForTrend as $year) {
            $yearId = $year['id'];

            if ($selectedSchoolId) {
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        JOIN semesters sem ON sss.semester_id = sem.id
                        WHERE sss.academic_year_id = ?
                          AND c.school_id = ?
                          AND sem.name_ar = ?
                          AND sss.is_active = 1
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        WHERE sss.academic_year_id = ?
                          AND c.school_id = ?
                          AND sss.is_active = 1
                    ");
                    $stmt->execute([$yearId, $selectedSchoolId]);
                }

                if ($semesterNameForMatch) {
                    $stmtComp = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        JOIN semesters sem ON sss.semester_id = sem.id
                        WHERE sss.academic_year_id = ?
                          AND sem.name_ar = ?
                          AND sss.is_active = 1
                    ");
                    $stmtComp->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmtComp = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        WHERE sss.academic_year_id = ?
                          AND sss.is_active = 1
                    ");
                    $stmtComp->execute([$yearId]);
                }
                $compValue = (float) $stmtComp->fetchColumn();
            } else {
                if ($semesterNameForMatch) {
                    $stmt = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        JOIN semesters sem ON sss.semester_id = sem.id
                        WHERE sss.academic_year_id = ?
                          AND sem.name_ar = ?
                          AND sss.is_active = 1
                    ");
                    $stmt->execute([$yearId, $semesterNameForMatch]);
                } else {
                    $stmt = $db->prepare("
                        SELECT
                          COUNT(DISTINCT CASE WHEN sss.percentage >= 50 THEN sss.student_id END) * 100.0
                          / NULLIF(COUNT(DISTINCT sss.student_id), 0) AS pass_rate
                        FROM student_subject_summary sss
                        JOIN classes c ON sss.class_id = c.id
                        WHERE sss.academic_year_id = ?
                          AND sss.is_active = 1
                    ");
                    $stmt->execute([$yearId]);
                }
                $compValue = null;
            }

            $avgValue = (float) $stmt->fetchColumn();

            $change = $changePct = null;
            if ($prevValue !== null && $avgValue > 0 && $prevValue > 0) {
                $change = $avgValue - $prevValue;
                $changePct = round(($change / $prevValue) * 100, 1);
            }

            $trendData[] = [
                'year'       => $year['name'],
                'year_id'    => $yearId,
                'value'      => $avgValue,
                'change'     => $change,
                'change_pct' => $changePct,
                'prev_value' => $prevValue,
            ];
            $comparisonData[] = $compValue;

            if ($avgValue > 0) { $overallSum += $avgValue; $overallCount++; }
            $prevValue = $avgValue;
        }
        break;

    // ────────────────────────────────────────────────────────────────────────
    // COHORT — grade level performance across years
    // ────────────────────────────────────────────────────────────────────────
    case 'cohort':
        $gradeFilter = '';
        $gradeParams = [];
        if (!empty($selectedGradeIds)) {
            $placeholders = implode(',', array_fill(0, count($selectedGradeIds), '?'));
            $gradeFilter = "AND c.grade_level_id IN ($placeholders)";
            $gradeParams = $selectedGradeIds;
        }

        $schoolFilter = '';
        $schoolParams = [];
        if ($selectedSchoolId) {
            $schoolFilter = 'AND c.school_id = ?';
            $schoolParams = [$selectedSchoolId];
        }

        $yearFilter = '';
        $yearParams = [];
        if ($selectedYearId) {
            $yearFilter = 'AND sss.academic_year_id = ?';
            $yearParams = [$selectedYearId];
        }

        $stmt = $db->prepare("
            SELECT
              ay.id   AS year_id,
              ay.name AS year_name,
              gl.id   AS grade_id,
              gl.name_ar AS grade_name,
              AVG(sss.percentage) AS avg_pct,
              COUNT(DISTINCT sss.student_id) AS student_count
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            JOIN academic_years ay ON sss.academic_year_id = ay.id
            WHERE sss.is_active = 1
              AND ay.is_active = 1
              AND ay.status != 'planning'
              $gradeFilter
              $schoolFilter
              $yearFilter
            GROUP BY ay.id, ay.name, gl.id, gl.name_ar, gl.level_number, ay.start_date
            ORDER BY ay.start_date, gl.level_number
        ");
        $params = array_merge($gradeParams, $schoolParams, $yearParams);
        $stmt->execute($params);
        $cohortRows = $stmt->fetchAll();

        // Build matrix: [year_label][grade_label] = value
        $yearLabels = [];
        $gradeLabels = [];
        $cohortGradeIds = [];
        foreach ($cohortRows as $row) {
            $yearKey  = $row['year_name'];
            $gradeKey = $row['grade_name'];
            if (!isset($yearLabels[$yearKey]))  $yearLabels[$yearKey] = $row['year_id'];
            if (!isset($gradeLabels[$gradeKey])) {
                $gradeLabels[$gradeKey] = $row['grade_id'];
                $cohortGradeIds[] = $row['grade_id'];
            }
            $cohortMatrix[$yearKey][$gradeKey] = [
                'avg'    => round((float)$row['avg_pct'], 1),
                'count'   => (int)$row['student_count'],
                'grade_id' => $row['grade_id'],
            ];
        }
        // If no grades selected but data exists, auto-select first one
        if (empty($selectedGradeIds) && !empty($cohortGradeIds)) {
            $selectedGradeIds = array_unique($cohortGradeIds);
        }

        // Build chart-ready arrays
        $chartYearLabels = array_keys($yearLabels);
        // For each grade, collect values across years
        $cohortChartData = [];
        foreach ($gradeLabels as $gName => $gId) {
            if (!empty($selectedGradeIds) && !in_array($gId, $selectedGradeIds)) continue;
            $values = [];
            foreach ($chartYearLabels as $yl) {
                $values[] = isset($cohortMatrix[$yl][$gName])
                    ? $cohortMatrix[$yl][$gName]['avg']
                    : null;
            }
            $cohortChartData[] = [
                'grade_name' => $gName,
                'grade_id'   => $gId,
                'values'     => $values,
            ];
            // For overall average in recommendations
            $nonNull = array_filter($values, function($v) { return $v !== null; });
            if (!empty($nonNull)) {
                $overallSum += array_sum($nonNull);
                $overallCount += count($nonNull);
            }
        }
        break;
}

// المتوسط العام
$overallAvg = $overallCount > 0 ? $overallSum / $overallCount : 0;

// ============================================================================
// التوصية التلقائية
// ============================================================================
$recommendation = '';
$trendDirection = 'insufficient';

if ($type !== 'cohort') {
    // EQI / Attendance / Success
    if (count($trendData) >= 2) {
        $first = $trendData[0]['value'];
        $last  = $trendData[count($trendData) - 1]['value'];
        $latestVal = $last;

        if ($last > 0 && $first > 0) {
            $totalChange = $last - $first;
            $totalChangePct = round(($totalChange / $first) * 100, 1);

            // Thresholds differ per type
            if ($type === 'attendance') {
                if ($totalChange > 1) {
                    $trendDirection = 'up';
                    if ($latestVal >= 95)      $recommendation = "معدل حضور متميز وفي تحسن (+{$totalChangePct}%). استمر في تعزيز ثقافة الحضور.";
                    elseif ($latestVal >= 90)  $recommendation = "معدل حضور جيد في تحسن (+{$totalChangePct}%). ركز على الوصول لمستوى 95% فما فوق.";
                    elseif ($latestVal >= 80)  $recommendation = "معدل حضور في تحسن (+{$totalChangePct}%). عزز إجراءات متابعة الغياب لتحسين النسبة.";
                    else                       $recommendation = "تحسن طفيف (+{$totalChangePct}%) لكن معدل الحضور لا يزال منخفضاً. يحتاج خطة تدخل لمكافحة الغياب.";
                } elseif ($totalChange < -1) {
                    $trendDirection = 'down';
                    if ($latestVal >= 95)      $recommendation = "انخفاض طفيف ({$totalChangePct}%) رغم بقاء الحضور متميزاً. راقب الوضع.";
                    elseif ($latestVal >= 90)  $recommendation = "تراجع في الحضور ({$totalChangePct}%). فعّل إنذارات الغياب المبكرة.";
                    elseif ($latestVal >= 80)  $recommendation = "تراجع ملحوظ في الحضور ({$totalChangePct}%). تدخل عاجل مطلوب.";
                    else                       $recommendation = "تراجع خطير في الحضور ({$totalChangePct}%) مع معدل منخفض. اجتماع طارئ لمكافحة الغياب.";
                } else {
                    $trendDirection = 'stable';
                    if ($latestVal >= 95)      $recommendation = "معدل حضور مستقر ومتميز. شارك تجاربك الناجحة مع بقية المدارس.";
                    elseif ($latestVal >= 90)  $recommendation = "معدل حضور مستقر عند مستوى جيد. حافظ على السياسات الحالية.";
                    elseif ($latestVal >= 80)  $recommendation = "معدل حضور مستقر عند مستوى مقبول. قد تحتاج إجراءات إضافية للتحسين.";
                    else                       $recommendation = "معدل حضور مستقر عند مستوى ضعيف — المشكلة مزمنة وتحتاج مراجعة جذرية.";
                }
            } else {
                // EQI or Success
                if ($totalChange > 2) {
                    $trendDirection = 'up';
                    if ($latestVal >= 80)      $recommendation = "أداء متميز في تحسن مستمر (+{$totalChangePct}%). حافظ على السياسات الحالية.";
                    elseif ($latestVal >= 65)  $recommendation = "جودة التعليم في تحسن مستمر (+{$totalChangePct}%). استمر وارفع السقف.";
                    elseif ($latestVal >= 50)  $recommendation = "تحسن ملحوظ (+{$totalChangePct}%). كثّف برامج التطوير المهني.";
                    else                       $recommendation = "تحسن طفيف (+{$totalChangePct}%) لكن الأداء لا يزال ضعيفاً. تدخل عاجل مطلوب.";
                } elseif ($totalChange < -2) {
                    $trendDirection = 'down';
                    if ($latestVal >= 80)      $recommendation = "تراجع طفيف ({$totalChangePct}%) رغم بقاء الأداء متميزاً. راقب الوضع.";
                    elseif ($latestVal >= 65)  $recommendation = "تراجع ملحوظ ({$totalChangePct}%). ورش عمل مكثفة ومراجعة استراتيجيات.";
                    elseif ($latestVal >= 50)  $recommendation = "تراجع حاد ({$totalChangePct}%). تدخل عاجل: مراجعة المناهج وخطط علاجية.";
                    else                       $recommendation = "تراجع خطير ({$totalChangePct}%) مع أداء ضعيف. استدعاء فريق دعم فني.";
                } else {
                    $trendDirection = 'stable';
                    if ($latestVal >= 80)      $recommendation = "أداء مستقر متميز. شارك تجاربك مع المدارس الأخرى.";
                    elseif ($latestVal >= 65)  $recommendation = "أداء مستقر جيد. قد تحتاج مبادرات جديدة للوصول لمستوى ممتاز.";
                    elseif ($latestVal >= 50)  $recommendation = "أداء مستقر مقبول. يُنصح بتبني مبادرات تطويرية.";
                    else                       $recommendation = "أداء مستقر ضعيف — المشكلة مزمنة وتحتاج مراجعة هيكلية.";
                }
            }
        } else {
            $recommendation = 'البيانات غير كافية لاستخراج اتجاه واضح. أضف بيانات لفصول إضافية.';
        }
    } else {
        $recommendation = 'لا توجد بيانات كافية لتوليد توصيات. يلزم فصلين دراسيين على الأقل.';
    }
} else {
    // Cohort
    if (!empty($cohortChartData)) {
        $trendDirection = 'stable';
        $gradeCount = count($cohortChartData);
        $yearCount = count($chartYearLabels);
        $recommendation = "مقارنة {$gradeCount} صف(وف) دراسي(ة) عبر {$yearCount} سنة دراسية.";
        $strongest = null; $strongestVal = 0;
        $weakest = null; $weakestVal = 100;
        foreach ($cohortChartData as $g) {
            $vals = array_filter($g['values']);
            if (!empty($vals)) {
                $avg = array_sum($vals) / count($vals);
                if ($avg > $strongestVal) { $strongestVal = $avg; $strongest = $g['grade_name']; }
                if ($avg < $weakestVal)   { $weakestVal = $avg; $weakest = $g['grade_name']; }
            }
        }
        if ($strongest && $weakest && $strongest !== $weakest) {
            $recommendation .= " الأقوى أداءً: «{$strongest}» بمتوسط {$strongestVal}%، والأقل: «{$weakest}» بمتوسط {$weakestVal}%. يُنصح بدراسة ممارسات الصفوف القوية وتعميمها.";
        } elseif ($strongest) {
            $recommendation .= " متوسط أداء الصفوف: {$strongestVal}%.";
        }
    } else {
        $recommendation = 'لا توجد بيانات كافية لمقارنة الأفواج. يلزم تسجيل درجات لعدة سنوات وص فوف.';
    }
}

// ============================================================================
// إعداد بيانات الرسم البياني
// ============================================================================
$chartLabels = [];
$chartValues = [];
$chartComparison = [];
$chartColors = [];

if ($type !== 'cohort') {
    $chartLabels     = array_map(function($d) { return $d['year']; }, $trendData);
    $chartValues     = array_map(function($d) use ($cfg) { return round($d['value'], $cfg['decimals']); }, $trendData);
    $chartComparison = array_map(function($v) { return $v !== null ? round($v, 1) : null; }, $comparisonData);
} else {
    $chartLabels = $chartYearLabels ?? [];
}

$chartTitle = $cfg['icon'] . ' ' . $cfg['label'];
if ($type !== 'cohort') {
    if ($selectedSchoolName) {
        $chartTitle .= " — {$selectedSchoolName}";
        if ($selectedSemesterName) $chartTitle .= " ({$selectedSemesterName})";
    } elseif ($selectedYearName) {
        $chartTitle .= " — {$selectedYearName}";
        if ($selectedSemesterName) $chartTitle .= " ({$selectedSemesterName})";
    }
} else {
    if ($selectedSchoolName) $chartTitle .= " — {$selectedSchoolName}";
    elseif ($selectedYearName) $chartTitle .= " — {$selectedYearName}";
}

// Color palettes per type
$colorSchemes = [
    'eqi'        => ['main' => '#1a5632', 'fill' => '#1a563220', 'comp' => '#94a3b8'],
    'attendance' => ['main' => '#2563eb', 'fill' => '#2563eb20', 'comp' => '#94a3b8'],
    'success'    => ['main' => '#d97706', 'fill' => '#d9770620', 'comp' => '#94a3b8'],
    'cohort'     => [
        '#1a5632', '#dc3545', '#28a745', '#c8a951', '#6f42c1', '#e83e8c',
        '#17a2b8', '#65a30d', '#fd7e14', '#4f46e5',
    ],
];
$cs = $colorSchemes[$type];

// ============================================================================
// CSS
// ============================================================================
$pageCSS = 'analytics.css';
?>
<style>
    .chart-container { height: 420px; }
    .recommendation-box {
        background: linear-gradient(135deg, rgba(40,167,69,0.06) 0%, var(--background) 100%);
        border: 1px solid rgba(40,167,69,0.2);
        border-radius: var(--radius-xl);
        padding: 1.5rem;
    }
    .recommendation-box.trend-down {
        background: linear-gradient(135deg, rgba(220,53,69,0.06) 0%, var(--background) 100%);
        border-color: rgba(220,53,69,0.2);
    }
    .recommendation-box.trend-stable {
        background: linear-gradient(135deg, rgba(23,162,184,0.06) 0%, var(--background) 100%);
        border-color: rgba(23,162,184,0.2);
    }
    .recommendation-box.trend-insufficient {
        background: linear-gradient(135deg, var(--background) 0%, var(--card-hover) 100%);
        border-color: var(--border);
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
    }
    .stat-mini {
        background: var(--surface);
        border-radius: var(--radius-md);
        padding: 1rem;
        box-shadow: var(--shadow-xs);
        text-align: center;
        border: 1px solid var(--border-light);
    }
    .stat-mini-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
    }
    .stat-mini-label {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }
    .current-year-row {
        background-color: rgba(26,86,50,0.06) !important;
    }
    .badge-no-data {
        background-color: var(--border);
        color: var(--text-secondary);
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.875rem;
        font-weight: 600;
    }
    /* Trend Tabs */
    .trend-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    .trend-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.95rem;
        text-decoration: none;
        transition: all 0.2s;
        background: var(--surface);
        color: var(--text-secondary);
        border: 2px solid transparent;
        box-shadow: var(--shadow-xs);
    }
    .trend-tab:hover {
        border-color: var(--border);
        color: var(--text-primary);
        transform: translateY(-1px);
    }
    .trend-tab.active {
        border-color: var(--primary);
        background: var(--primary);
        color: white;
    }
    .trend-tab .tab-icon { font-size: 1.2rem; }
    /* Cohort table */
    .cohort-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: var(--surface);
        border-radius: var(--radius-xl);
        overflow: hidden;
        box-shadow: var(--shadow-xs);
    }
    .cohort-table th {
        background: var(--background);
        font-weight: 600;
        padding: 0.75rem 1rem;
        border-bottom: 2px solid var(--border);
        text-align: center;
    }
    .cohort-table td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border-light);
        text-align: center;
    }
    .cohort-table tbody tr:hover { background: var(--card-hover); }
    .cohort-cell-best { background: rgba(40,167,69,0.1) !important; font-weight: 700; }
    .cohort-cell-worst { background: rgba(220,53,69,0.1) !important; }
    .cohort-grade-selector {
        background: var(--surface);
        border-radius: var(--radius-xl);
        padding: 1rem;
        box-shadow: var(--shadow-xs);
        margin-bottom: 1.5rem;
    }
</style>

<!-- =========================================================================
     شريط التبويبات
     ========================================================================= -->
<div class="trend-tabs">
    <?php foreach ($validTypes as $t): ?>
        <?php $tc = $typeConfig[$t]; ?>
        <a href="<?= e(buildTabUrl($t, $_GET)) ?>"
           class="trend-tab <?= $t === $type ? 'active' : '' ?>">
            <span class="tab-icon"><?= $tc['icon'] ?></span>
            <?= $tc['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- =========================================================================
     بطاقة العنوان
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header" style="background:var(--gradient-primary);">
        <h3 class="card-title" style="color:var(--text-white);"><?= $cfg['icon'] ?> <?= e($pageTitle) ?></h3>
    </div>
    <div class="card-body">
        <p class="text-secondary"><?= e($cfg['label']) ?> — تحليل الاتجاهات الزمنية عبر السنوات الدراسية</p>
    </div>
</div>

<!-- =========================================================================
     فلاتر التقرير
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header">
        <h4 class="card-title" style="font-size:var(--font-size-base);">⚙️ تحكم في التقرير</h4>
    </div>
    <div class="card-body">
        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--space-4);align-items:end;">
                <!-- Year -->
                <div class="form-group">
                    <label class="form-label">السنة الدراسية:</label>
                    <select name="year_id" class="form-control" onchange="this.form.submit()">
                        <option value="">جميع السنوات</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?= $year['id'] ?>" <?= $selectedYearId == $year['id'] ? 'selected' : '' ?>>
                                <?= e($year['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($type !== 'cohort'): ?>
                <!-- Semester (non-cohort) -->
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي:</label>
                    <select name="semester_id" class="form-control" onchange="this.form.submit()">
                        <option value="">جميع الفصول</option>
                        <?php foreach ($semesters as $semester): ?>
                            <option value="<?= $semester['id'] ?>" <?= $selectedSemesterId == $semester['id'] ? 'selected' : '' ?>>
                                <?= e($semester['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <!-- Grade level selector (cohort) -->
                <div class="form-group">
                    <label class="form-label">الصف الدراسي (اختر حتى 6):</label>
                    <select name="grade_ids[]" class="form-control" multiple size="4" onchange="this.form.submit()">
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= $gl['id'] ?>" <?= in_array($gl['id'], $selectedGradeIds) ? 'selected' : '' ?>>
                                <?= e($gl['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-secondary mt-1">اضغط Ctrl لتحديد متعدد</p>
                </div>
                <?php endif; ?>

                <!-- School (all types) -->
                <div class="form-group">
                    <label class="form-label">المدرسة:</label>
                    <select name="school_id" class="form-control" onchange="this.form.submit()">
                        <option value="">جميع المدارس</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>" <?= $selectedSchoolId == $school['id'] ? 'selected' : '' ?>>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Apply button (for cohort multi-select) -->
                <?php if ($type === 'cohort'): ?>
                <div class="form-group mb-0" style="align-self:end;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">🔍 تطبيق الفلاتر</button>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <div class="alert alert-info mt-4 mb-0">
            <span class="alert-icon">💡</span>
            <div class="flex-1">
                <span class="text-sm">
                    تم استبعاد سنوات التخطيط من الحسابات.
                    <?php if ($type === 'cohort'): ?>
                        يتم مقارنة أداء نفس الصف الدراسي عبر السنوات. اختر صفاً واحداً أو أكثر للمقارنة.
                    <?php elseif ($selectedSemesterName): ?>
                        عند اختيار فصل، تتم مطابقته عبر السنوات بالاسم لعرض اتّجاه موحّد.
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     الرسم البياني
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header">
        <h4 class="card-title" style="font-size:var(--font-size-base);">📈 <?= e($chartTitle) ?></h4>
    </div>
    <div class="card-body">
        <div class="chart-container">
            <canvas id="metricTrendChart"></canvas>
        </div>
    </div>
</div>

<?php if ($type !== 'cohort'): ?>
<!-- =========================================================================
     جدول البيانات التفصيلية (EQI / Attendance / Success)
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header">
        <h4 class="card-title" style="font-size:var(--font-size-base);">📋 بيانات الاتجاه التفصيلية</h4>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>السنة الدراسية</th>
                        <th><?= e($cfg['label']) ?></th>
                        <th>التغيير</th>
                        <th>نسبة التغيير</th>
                        <th>التقييم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trendData)): ?>
                        <tr>
                            <td colspan="6" class="table-empty">
                                <div class="table-empty-icon">📊</div>
                                <div class="table-empty-text">لا توجد بيانات متاحة للفلاتر المحددة</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $counter = 1; ?>
                        <?php foreach ($trendData as $data): ?>
                            <?php
                            $isLatest = ($counter === count($trendData));
                            $rating = metricRating($data['value'], $type);

                            $trendStr = '—';
                            $trendClass = 'trend-neutral';
                            if ($data['change'] !== null) {
                                if ($data['change'] > 0) {
                                    $trendStr = '▲ ' . number_format(abs($data['change']), 1);
                                    $trendClass = 'trend-up';
                                } elseif ($data['change'] < 0) {
                                    $trendStr = '▼ ' . number_format(abs($data['change']), 1);
                                    $trendClass = 'trend-down';
                                } else {
                                    $trendStr = '→ مستقر';
                                    $trendClass = 'trend-neutral';
                                }
                            }
                            ?>
                            <tr class="<?= $isLatest ? 'current-year-row' : '' ?>">
                                <td><?= $counter++ ?></td>
                                <td style="font-weight:600;"><?= e($data['year']) ?></td>
                                <td><?= $data['value'] > 0 ? number_format($data['value'], $cfg['decimals']) . $cfg['unit'] : '—' ?></td>
                                <td><span class="<?= $trendClass ?>"><?= e($trendStr) ?></span></td>
                                <td><?= $data['change_pct'] !== null ? ($data['change'] > 0 ? '+' : '') . $data['change_pct'] . '%' : '—' ?></td>
                                <td><span class="badge <?= $rating['class'] ?>"><?= $rating['label'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- =========================================================================
     جدول الأفواج (Cohort matrix)
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header">
        <h4 class="card-title" style="font-size:var(--font-size-base);">📋 مصفوفة الأداء: الصف × السنة</h4>
    </div>
    <div class="card-body">
        <?php if (empty($cohortChartData)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">👥</div>
                <div class="table-empty-text">لا توجد بيانات كافية. اختر صفاً دراسياً وتأكد من وجود درجات لعدة سنوات.</div>
            </div>
        <?php else: ?>
            <?php
            // Find best/worst per row for highlighting
            $bestPerYear = [];
            $worstPerYear = [];
            foreach ($chartYearLabels as $yl) {
                $rowVals = [];
                foreach ($cohortChartData as $g) {
                    $idx = array_search($yl, $chartYearLabels);
                    if ($idx !== false && $g['values'][$idx] !== null) {
                        $rowVals[$g['grade_name']] = $g['values'][$idx];
                    }
                }
                if (!empty($rowVals)) {
                    $bestPerYear[$yl] = array_keys($rowVals, max($rowVals))[0];
                    $worstPerYear[$yl] = array_keys($rowVals, min($rowVals))[0];
                }
            }
            ?>
            <div class="table-responsive">
                <table class="cohort-table">
                    <thead>
                        <tr>
                            <th>السنة ➞ الصف</th>
                            <?php foreach ($cohortChartData as $g): ?>
                                <th><?= e($g['grade_name']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chartYearLabels as $yl): ?>
                            <tr>
                                <td style="font-weight:600;text-align:right;"><?= e($yl) ?></td>
                                <?php foreach ($cohortChartData as $g): ?>
                                    <?php
                                    $idx = array_search($yl, $chartYearLabels);
                                    $val = ($idx !== false) ? $g['values'][$idx] : null;
                                    $cellClass = '';
                                    if ($val !== null) {
                                        if (isset($bestPerYear[$yl]) && $g['grade_name'] === $bestPerYear[$yl]) $cellClass = 'cohort-cell-best';
                                        if (isset($worstPerYear[$yl]) && $g['grade_name'] === $worstPerYear[$yl]) $cellClass = 'cohort-cell-worst';
                                    }
                                    ?>
                                    <td class="<?= $cellClass ?>">
                                        <?= $val !== null ? number_format($val, 1) . '%' : '—' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            // Summary counts per grade
            ?>
            <div class="mt-4 stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
                <?php foreach ($cohortChartData as $g): ?>
                    <?php
                    $vals = array_filter($g['values']);
                    $avg = !empty($vals) ? array_sum($vals) / count($vals) : 0;
                    $rating = metricRating($avg, 'success');
                    ?>
                    <div class="stat-mini">
                        <div class="stat-mini-value" style="font-size:1.25rem;"><?= e($g['grade_name']) ?></div>
                        <div class="stat-mini-label">متوسط <?= number_format($avg, 1) ?>%</div>
                        <div><span class="badge <?= $rating['class'] ?>"><?= $rating['label'] ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- =========================================================================
     الرؤى والتوصيات
     ========================================================================= -->
<div class="card mb-6">
    <div class="card-header">
        <h4 class="card-title" style="font-size:var(--font-size-base);">💡 الرؤى والتوصيات</h4>
    </div>
    <div class="card-body">
        <?php if ($type !== 'cohort' && empty($trendData)): ?>
            <p class="text-secondary text-center">لا توجد بيانات كافية لاستخراج الرؤى</p>
        <?php elseif ($type === 'cohort' && empty($cohortChartData)): ?>
            <p class="text-secondary text-center">لا توجد بيانات كافية لاستخراج الرؤى</p>
        <?php else: ?>
            <!-- بطاقات إحصائية سريعة -->
            <div class="stats-grid mb-6">
                <?php if ($type !== 'cohort'): ?>
                <div class="stat-mini">
                    <div class="stat-mini-value"><?= number_format($overallAvg, $cfg['decimals']) ?><?= $cfg['unit'] ?></div>
                    <div class="stat-mini-label">متوسط <?= $cfg['label'] ?> العام</div>
                </div>
                <?php endif; ?>
                <div class="stat-mini">
                    <div class="stat-mini-value">
                        <?php if ($trendDirection === 'up'): ?>
                            <span class="trend-up">▲ متصاعد</span>
                        <?php elseif ($trendDirection === 'down'): ?>
                            <span class="trend-down">▼ هابط</span>
                        <?php elseif ($trendDirection === 'stable'): ?>
                            <span class="trend-neutral">→ مستقر</span>
                        <?php else: ?>
                            <span class="trend-neutral">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-mini-label">الاتجاه العام</div>
                </div>
                <?php if ($type !== 'cohort'): ?>
                <div class="stat-mini">
                    <?php
                    $maxVal = null; $maxYear = '';
                    $minVal = null; $minYear = '';
                    foreach ($trendData as $d) {
                        if ($d['value'] > 0) {
                            if ($maxVal === null || $d['value'] > $maxVal) { $maxVal = $d['value']; $maxYear = $d['year']; }
                            if ($minVal === null || $d['value'] < $minVal) { $minVal = $d['value']; $minYear = $d['year']; }
                        }
                    }
                    ?>
                    <div class="stat-mini-value" style="color:var(--success);"><?= $maxVal ? number_format($maxVal, $cfg['decimals']) : '—' ?></div>
                    <div class="stat-mini-label">أعلى قيمة (<?= e($maxYear) ?>)</div>
                </div>
                <div class="stat-mini">
                    <div class="stat-mini-value" style="color:var(--danger);"><?= $minVal ? number_format($minVal, $cfg['decimals']) : '—' ?></div>
                    <div class="stat-mini-label">أدنى قيمة (<?= e($minYear) ?>)</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- صندوق التوصية -->
            <div class="recommendation-box trend-<?= $trendDirection ?>">
                <div style="display:flex;align-items:flex-start;gap:1rem;">
                    <span style="font-size:2rem;">
                        <?php if ($trendDirection === 'up'): ?>📈
                        <?php elseif ($trendDirection === 'down'): ?>📉
                        <?php elseif ($trendDirection === 'stable'): ?>➡️
                        <?php else: ?>ℹ️
                        <?php endif; ?>
                    </span>
                    <div>
                        <h5 style="margin:0 0 0.5rem 0;font-size:1rem;font-weight:700;color:#1e293b;">
                            <?php if ($trendDirection === 'up'): ?>
                                اتجاه إيجابي — <?= e($cfg['label']) ?> في تحسن
                            <?php elseif ($trendDirection === 'down'): ?>
                                تنبيه — <?= e($cfg['label']) ?> في تراجع
                            <?php elseif ($trendDirection === 'stable'): ?>
                                أداء مستقر
                            <?php else: ?>
                                بيانات غير كافية
                            <?php endif; ?>
                        </h5>
                        <p style="margin:0;color:#475569;line-height:1.7;">
                            <?= e($recommendation) ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var type = <?= json_encode($type) ?>;
    var labels = <?= json_encode($chartLabels) ?>;
    var values = <?= json_encode($chartValues) ?>;
    var comparison = <?= json_encode($chartComparison) ?>;
    var title = <?= json_encode($chartTitle) ?>;
    var hasSchool = <?= $selectedSchoolId ? 'true' : 'false' ?>;
    var colorMain = <?= json_encode($cs['main'] ?? '#1a5632') ?>;
    var colorFill = <?= json_encode($cs['fill'] ?? '#1a563220') ?>;
    var colorComp = <?= json_encode($cs['comp'] ?? '#94a3b8') ?>;

    <?php if ($type !== 'cohort'): ?>
    // ─── EQI / Attendance / Success — single line chart ───
    if (labels.length > 0 && values.length > 0) {
        var datasets = [{
            label: hasSchool ? <?= json_encode($selectedSchoolName ?? '') ?> : <?= json_encode($cfg['label']) ?>,
            data: values,
            borderColor: colorMain,
            backgroundColor: colorFill,
            fill: true,
            tension: 0.3,
            pointRadius: 5,
            pointHoverRadius: 7,
            borderWidth: 3
        }];

        if (hasSchool && comparison.length > 0) {
            datasets.push({
                label: 'متوسط المجموعة',
                data: comparison,
                borderColor: colorComp,
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5,
                borderWidth: 2,
                borderDash: [6, 3]
            });
        }

        eqasLineChart('metricTrendChart', title, labels, datasets, {
            beginAtZero: false
        });
    }
    <?php else: ?>
    // ─── Cohort — multiple line chart ───
    var cohortData = <?= json_encode($cohortChartData) ?>;
    var cohortColors = <?= json_encode($cs) ?>;
    var chartLabels = <?= json_encode($chartLabels) ?>;

    if (chartLabels.length > 0 && cohortData.length > 0) {
        var datasets = [];
        cohortData.forEach(function(g, i) {
            datasets.push({
                label: g.grade_name,
                data: g.values,
                borderColor: cohortColors[i % cohortColors.length],
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.3,
                pointRadius: 4,
                pointHoverRadius: 6,
                borderWidth: 2.5
            });
        });

        eqasLineChart('metricTrendChart', title, chartLabels, datasets, {
            beginAtZero: true
        });
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
