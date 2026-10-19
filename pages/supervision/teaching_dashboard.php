<?php
// تصدير CSV - قبل أي إخراج
if (isset($_GET['export']) && $_GET['export'] === 'teachers_csv') {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/database.php';
    require_once __DIR__ . '/../../includes/helpers.php';
    require_once __DIR__ . '/../../includes/session.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/contextual_adjustment.php';

    requireRole('group_admin');
    if (!$db) { header('HTTP/1.1 500 Internal Server Error'); exit; }

    $selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
    $selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
    $selectedSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
    $allYearsSelected = ($selectedAcademicYearId === 0);
    $yearFilter = $allYearsSelected ? '' : "AND e.academic_year_id = " . (int)$selectedAcademicYearId;
    $semFilter = $yearFilter;
    if ($selectedSemesterId > 0) { $semFilter .= " AND e.semester_id = " . (int)$selectedSemesterId; }
    $schoolFilter = $selectedSchoolId > 0 ? "AND u.school_id = " . (int)$selectedSchoolId : '';

    $rows = $db->query("SELECT u.full_name_ar, s.name_ar AS school_name,
        e.self_score, e.peer_score, e.admin_score, e.combined_score, e.adjusted_score
        FROM evaluations e
        JOIN users u ON e.teacher_id = u.id
        JOIN schools s ON u.school_id = s.id
        WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter $semFilter $schoolFilter
        ORDER BY s.name_ar, u.full_name_ar")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teachers_evaluations_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['المعلم', 'المدرسة', 'التقييم الذاتي', 'تقييم الأقران', 'التقييم الإشرافي', 'المجموع', 'المعدّل سياقياً']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name_ar'],
            $r['school_name'],
            $r['self_score'] !== null ? round($r['self_score'], 1) . '%' : '-',
            $r['peer_score'] !== null ? round($r['peer_score'], 1) . '%' : '-',
            $r['admin_score'] !== null ? round($r['admin_score'], 1) . '%' : '-',
            $r['combined_score'] !== null ? round($r['combined_score'], 1) . '%' : '-',
            $r['adjusted_score'] !== null ? round($r['adjusted_score'], 1) . '%' : '-',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'مركز قيادة تقييم المعلمين';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'مركز قيادة تقييم المعلمين' => null];

require_once __DIR__ . '/../../includes/header.php';

requireRole('group_admin');
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$selectedSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$allYearsSelected = ($selectedAcademicYearId === 0);

// عند تحميل الصفحة أول مرة بدون تحديد سنة: اختار السنة الحالية
$isInitialLoad = !isset($_GET['academic_year_id']);
if ($isInitialLoad) {
    $selectedAcademicYearId = (int)currentAcademicYearId();
    $allYearsSelected = false;
    if (!isset($_GET['semester_id'])) {
        $selectedSemesterId = (int)currentSemesterId($selectedAcademicYearId);
    }
}

$academicYears = [];
try {
    $academicYears = $db->query("SELECT id, name AS name_ar FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {}

$yearFilter = $allYearsSelected ? '' : "AND e.academic_year_id = " . (int)$selectedAcademicYearId;

$allSemesters = [];
if (!$allYearsSelected && $selectedAcademicYearId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedAcademicYearId]);
        $allSemesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$schools = getAvailableSchools($db, 'group_admin', null);

$validSemester = ($selectedSemesterId === 0 || $allYearsSelected || empty($allSemesters));
if (!$validSemester) {
    foreach ($allSemesters as $s) {
        if ((int)$s['id'] === $selectedSemesterId) { $validSemester = true; break; }
    }
}
if (!$validSemester && !empty($allSemesters)) {
    $selectedSemesterId = (int)$allSemesters[0]['id'];
}

if ($selectedSchoolId > 0) {
    $validSchool = false;
    foreach ($schools as $s) {
        if ((int)$s['id'] === $selectedSchoolId) { $validSchool = true; break; }
    }
    if (!$validSchool) $selectedSchoolId = 0;
}

$selectedSchoolName = 'الكل';
if ($selectedSchoolId > 0) {
    foreach ($schools as $s) {
        if ((int)$s['id'] === $selectedSchoolId) {
            $selectedSchoolName = $s['name_ar']; break;
        }
    }
}

$selectedSemesterName = 'جميع الفصول';
if ($selectedSemesterId > 0) {
    foreach ($allSemesters as $s) {
        if ((int)$s['id'] === $selectedSemesterId) {
            $selectedSemesterName = $s['name_ar']; break;
        }
    }
}

$schoolFilter = $selectedSchoolId > 0 ? "AND u.school_id = $selectedSchoolId" : '';
$semFilter = $yearFilter;
if ($selectedSemesterId > 0) {
    $semFilter .= " AND e.semester_id = $selectedSemesterId";
}

// KPI 1: متوسط التقييم العام
$avgScore = 0;
try {
    $sql = "SELECT AVG(per_teacher.avg_score) FROM (
                SELECT e.teacher_id, AVG(e.combined_score) AS avg_score
                FROM evaluations e
                WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter $schoolFilter
                GROUP BY e.teacher_id
            ) per_teacher";
    if ($selectedSemesterId > 0) {
        $sql = "SELECT AVG(e.combined_score) FROM evaluations e
                WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter AND e.semester_id = $selectedSemesterId $schoolFilter";
    }
    $avgScore = (float)($db->query($sql)->fetchColumn() ?: 0);
} catch (Exception $e) {}

// KPI 2+3: أعلى/أقل مدرسة
$topSchool = '';
$topScore = 0;
$bottomSchool = '';
$bottomScore = 100;
$totalTeachers = 0;
try {
    $schoolClause = $selectedSchoolId > 0 ? "AND u.school_id = $selectedSchoolId" : '';

    // عدد المعلمين المُقيّمين
    $countSql = "SELECT COUNT(DISTINCT e.teacher_id) FROM evaluations e
                 WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter $schoolClause";
    if ($selectedSemesterId > 0) {
        $countSql .= " AND e.semester_id = $selectedSemesterId";
    }
    $totalTeachers = (int)($db->query($countSql)->fetchColumn() ?: 0);

    if ($selectedSchoolId === 0) {
        $sql = "SELECT s.name_ar, AVG(e.combined_score) AS s_avg
                FROM evaluations e
                JOIN users u ON e.teacher_id = u.id
                JOIN schools s ON u.school_id = s.id
                WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter
                GROUP BY s.id, s.name_ar
                ORDER BY s_avg DESC";
        if ($selectedSemesterId > 0) {
            $sql = "SELECT s.name_ar, AVG(e.combined_score) AS s_avg
                    FROM evaluations e
                    JOIN users u ON e.teacher_id = u.id
                    JOIN schools s ON u.school_id = s.id
                    WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $yearFilter AND e.semester_id = $selectedSemesterId
                    GROUP BY s.id, s.name_ar
                    ORDER BY s_avg DESC";
        }
        $rows = $db->query($sql)->fetchAll();
        if (!empty($rows)) {
            $topSchool = $rows[0]['name_ar'];
            $topScore = round((float)$rows[0]['s_avg'], 1);
            $bottomSchool = $rows[count($rows) - 1]['name_ar'];
            $bottomScore = round((float)$rows[count($rows) - 1]['s_avg'], 1);
        }
    }
} catch (Exception $e) {}

// KPI مع التعديل السياقي (لـ adjusted_score)
$adjustedAvg = $avgScore;
if (!$allYearsSelected && $selectedSchoolId > 0 && $avgScore > 0) {
    try {
        $adj = new ContextualAdjustmentService($db);
        $adjustedAvg = $adj->calculateAdjustedSQI($selectedSchoolId, $avgScore);
    } catch (Exception $e) {}
}

// رسم بياني: مقارنة المدارس (Bar Chart)
$barLabels = [];
$barSelf = [];
$barPeer = [];
$barAdmin = [];
$barCombined = [];
try {
    $barSemFilter = $selectedSemesterId > 0 ? "AND e.semester_id = $selectedSemesterId" : $yearFilter;
    $schoolClause = $selectedSchoolId > 0 ? "AND u.school_id = $selectedSchoolId" : '';

    $sql = "SELECT s.name_ar,
                   AVG(e.self_score) AS s_self,
                   AVG(e.peer_score) AS s_peer,
                   AVG(e.admin_score) AS s_admin,
                   AVG(e.combined_score) AS s_combined
            FROM evaluations e
            JOIN users u ON e.teacher_id = u.id
            JOIN schools s ON u.school_id = s.id
            WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $barSemFilter $schoolClause
            GROUP BY s.id, s.name_ar
            ORDER BY s_combined DESC";

    if ($selectedSchoolId === 0) {
        $barRows = $db->query($sql)->fetchAll();
    } else {
        // مدرسة واحدة: قارنها بمتوسط المجموعة
        $barRows = $db->query($sql)->fetchAll();
        $groupAvg = $db->query("
            SELECT AVG(e.self_score) AS s_self, AVG(e.peer_score) AS s_peer,
                   AVG(e.admin_score) AS s_admin, AVG(e.combined_score) AS s_combined
            FROM evaluations e
            JOIN users u ON e.teacher_id = u.id
            WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $semFilter
        ")->fetch();

        if (!empty($barRows)) {
            $barLabels[] = $barRows[0]['name_ar'];
            $barSelf[] = round((float)$barRows[0]['s_self'], 1);
            $barPeer[] = round((float)$barRows[0]['s_peer'], 1);
            $barAdmin[] = round((float)$barRows[0]['s_admin'], 1);
            $barCombined[] = round((float)$barRows[0]['s_combined'], 1);

            // متوسط المجموعة كشريحة إضافية
            $barLabels[] = 'متوسط المجموعة';
            $barSelf[] = round((float)$groupAvg['s_self'], 1);
            $barPeer[] = round((float)$groupAvg['s_peer'], 1);
            $barAdmin[] = round((float)$groupAvg['s_admin'], 1);
            $barCombined[] = round((float)$groupAvg['s_combined'], 1);
        }
    }

    if ($selectedSchoolId === 0) {
        foreach ($barRows as $r) {
            $barLabels[] = $r['name_ar'];
            $barSelf[] = round((float)$r['s_self'], 1);
            $barPeer[] = round((float)$r['s_peer'], 1);
            $barAdmin[] = round((float)$r['s_admin'], 1);
            $barCombined[] = round((float)$r['s_combined'], 1);
        }
    }
} catch (Exception $e) {}

// رسم خطي: اتجاه آخر 6 فصول
$trendLabels = [];
$trendSchoolData = [];
$trendOverallData = [];
if (!$allYearsSelected) {
    try {
        $histStmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY start_date DESC LIMIT 6");
        $histSemesters = $histStmt->fetchAll();
        $histSemesters = array_reverse($histSemesters);

        foreach ($histSemesters as $hs) {
            $hsId = (int)$hs['id'];
            $trendLabels[] = $hs['name_ar'];

            $schoolClause = '';
            if ($selectedSchoolId > 0) {
                $schoolClause = "AND u.school_id = $selectedSchoolId";
            }
            $sStmt = $db->prepare("
                SELECT AVG(e.combined_score)
                FROM evaluations e
                JOIN users u ON e.teacher_id = u.id
                WHERE e.eval_type = 'teaching_summary' AND e.semester_id = ? AND e.academic_year_id = ? AND e.is_active = 1 $schoolClause
            ");
            $sStmt->execute([$hsId, $selectedAcademicYearId]);
            $trendSchoolData[] = round((float)($sStmt->fetchColumn() ?: 0), 1);

            $oStmt = $db->prepare("
                SELECT AVG(e.combined_score)
                FROM evaluations e
                JOIN users u ON e.teacher_id = u.id
                WHERE e.eval_type = 'teaching_summary' AND e.semester_id = ? AND e.academic_year_id = ? AND e.is_active = 1
            ");
            $oStmt->execute([$hsId, $selectedAcademicYearId]);
            $trendOverallData[] = round((float)($oStmt->fetchColumn() ?: 0), 1);
        }
    } catch (Exception $e) {}
}

// جدول أداء المدارس
$schoolsData = [];
$yearlyTrends = [];
$schoolsAtRisk = [];
try {
    $groupBySem = $selectedSemesterId > 0
        ? "AND e.semester_id = $selectedSemesterId"
        : "";

    $sql = "SELECT s.id, s.name_ar, s.reference_code,
                   AVG(e.combined_score) AS avg_score,
                   AVG(e.adjusted_score) AS avg_adjusted,
                   COUNT(DISTINCT e.teacher_id) AS teacher_count
            FROM evaluations e
            JOIN users u ON e.teacher_id = u.id
            JOIN schools s ON u.school_id = s.id
            WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $semFilter
            GROUP BY s.id, s.name_ar, s.reference_code
            ORDER BY avg_score DESC";
    $schoolsData = $db->query($sql)->fetchAll();

    // الاتجاه السنوي
    if (!empty($schoolsData) && $selectedAcademicYearId > 0 && $selectedSemesterId > 0) {
        $prevStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $prevStmt->execute([$selectedAcademicYearId]);
        $prevYearId = $prevStmt->fetchColumn();

        if ($prevYearId) {
            $pStmt = $db->prepare("
                SELECT s2.id FROM semesters s2
                JOIN semesters s1 ON s1.id = ?
                WHERE s2.academic_year_id = ? AND s2.name_ar = s1.name_ar AND s2.is_active = 1
                LIMIT 1
            ");
            $pStmt->execute([$selectedSemesterId, $prevYearId]);
            $prevSemId = $pStmt->fetchColumn();

            if ($prevSemId) {
                $pStmt = $db->prepare("
                    SELECT u.school_id, AVG(e.combined_score) AS avg_score
                    FROM evaluations e
                    JOIN users u ON e.teacher_id = u.id
                    WHERE e.eval_type = 'teaching_summary' AND e.semester_id = ? AND e.academic_year_id = ? AND e.is_active = 1
                    GROUP BY u.school_id
                ");
                $pStmt->execute([$prevSemId, $prevYearId]);
                while ($row = $pStmt->fetch()) {
                    $yearlyTrends[(int)$row['school_id']] = (float)$row['avg_score'];
                }
            }
        }
    }

    // المدارس في الخطر
    $adjService = new ContextualAdjustmentService($db);
    foreach ($schoolsData as &$school) {
        $score = (float)$school['avg_score'];
        $prev = $yearlyTrends[(int)$school['id']] ?? null;

        if ($score > 0 && $score < 50) {
            $school['risk_reason'] = 'متوسط التقييم أقل من 50%';
            $schoolsAtRisk[] = $school;
        } elseif ($prev !== null && $prev > 0 && $score > 0 && $score < $prev) {
            $school['risk_reason'] = 'انخفاض عن الفصل السابق';
            $schoolsAtRisk[] = $school;
        }

        // 3+ teachers < 60% without active plan
        $riskStmt = $db->prepare("
            SELECT COUNT(DISTINCT e.teacher_id)
            FROM evaluations e
            JOIN users u ON e.teacher_id = u.id AND u.school_id = ?
            LEFT JOIN improvement_plans ip
                ON ip.teacher_id = e.teacher_id AND ip.plan_type = 'teacher' AND ip.status = 'active' AND ip.is_active = 1
            WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1 $semFilter
              AND e.combined_score < 60 AND ip.id IS NULL
        ");
        $riskStmt->execute([$school['id']]);
        $riskCount = (int)$riskStmt->fetchColumn();
        if ($riskCount >= 3) {
            $school['risk_reason2'] = "$riskCount معلمين تقييمهم < 60% بدون خطة تطوير";
            $schoolsAtRisk[] = $school;
        }
    }
    unset($school);
} catch (Exception $e) {}

// بيانات المعلمين المقيمين (لجدول المعلمين)
$teacherEvalData = [];
$teachersWithIssues = [];
$totalTeachersWithEval = 0;
$teacherCompletionStats = ['all' => 0, 'partial' => 0, 'none' => 0, 'total_teachers' => 0];
$teachersWithoutEval = [];
try {
    $tYearClause = $allYearsSelected ? '' : "AND e.academic_year_id = " . (int)$selectedAcademicYearId;
    $tSemClause = $selectedSemesterId > 0 ? "AND e.semester_id = " . (int)$selectedSemesterId : '';
    $tSchoolClause = $selectedSchoolId > 0 ? "AND u.school_id = " . (int)$selectedSchoolId : '';

    // المعلمين مع تقييماتهم
    $sql = "SELECT u.id AS teacher_id, u.full_name_ar AS teacher_name,
                   s.id AS school_id, s.name_ar AS school_name,
                   e.self_score, e.peer_score, e.admin_score, e.combined_score, e.adjusted_score,
                   e.academic_year_id, e.semester_id, e.id AS eval_id
            FROM evaluations e
            JOIN users u ON e.teacher_id = u.id
            JOIN schools s ON u.school_id = s.id
            WHERE e.eval_type = 'teaching_summary' AND e.is_active = 1
              $tYearClause $tSemClause $tSchoolClause
            ORDER BY e.combined_score ASC";
    $teacherEvalRaw = $db->query($sql)->fetchAll();

    // تجميع التقييمات لكل معلم (قد يكون له أكثر من تقييم)
    $grouped = [];
    foreach ($teacherEvalRaw as $t) {
        $tid = (int)$t['teacher_id'];
        if (!isset($grouped[$tid])) {
            $grouped[$tid] = [
                'teacher_id' => $tid,
                'teacher_name' => $t['teacher_name'],
                'school_id' => (int)$t['school_id'],
                'school_name' => $t['school_name'],
                'self_score' => null,
                'peer_score' => null,
                'admin_score' => null,
                'combined_score' => null,
                'eval_count' => 0,
            ];
        }
        // استخدام أحدث تقييم
        if ($t['combined_score'] !== null) {
            $g = &$grouped[$tid];
            if ($g['combined_score'] === null || (float)$t['combined_score'] > (float)$g['combined_score']) {
                $g['self_score'] = $t['self_score'] !== null ? (float)$t['self_score'] : null;
                $g['peer_score'] = $t['peer_score'] !== null ? (float)$t['peer_score'] : null;
                $g['admin_score'] = $t['admin_score'] !== null ? (float)$t['admin_score'] : null;
                $g['combined_score'] = (float)$t['combined_score'];
            }
            $g['eval_count']++;
        }
    }
    $teacherEvalData = array_values($grouped);
    $totalTeachersWithEval = count($teacherEvalData);

    // إحصائيات الاكتمال وتحديد المعلمين بحاجة تدخل
    foreach ($teacherEvalData as &$t) {
        $completed = 0;
        if ($t['self_score'] !== null) $completed++;
        if ($t['peer_score'] !== null) $completed++;
        if ($t['admin_score'] !== null) $completed++;
        $t['completion'] = $completed;

        if ($completed === 3) $teacherCompletionStats['all']++;
        elseif ($completed > 0) $teacherCompletionStats['partial']++;
        else $teacherCompletionStats['none']++;

        if ($t['combined_score'] !== null && $t['combined_score'] < 60) {
            $teachersWithIssues[] = $t;
        }
    }
    unset($t);

    // معلمون بدون أي تقييم
    $excludeIds = empty($teacherEvalRaw) ? [0] : array_unique(array_column($teacherEvalRaw, 'teacher_id'));
    $excludeList = implode(',', $excludeIds);
    $tSchoolClause2 = $selectedSchoolId > 0 ? "AND u.school_id = " . (int)$selectedSchoolId : '';
    $sql2 = "SELECT u.id, u.full_name_ar, s.name_ar AS school_name, s.id AS school_id
             FROM users u
             JOIN roles r ON u.role_id = r.id
             JOIN schools s ON u.school_id = s.id
             WHERE r.name = 'teacher' AND u.is_active = 1
               AND u.id NOT IN ($excludeList)
               $tSchoolClause2
             ORDER BY u.full_name_ar";
    $teachersWithoutEval = $db->query($sql2)->fetchAll();
    $teacherCompletionStats['total_teachers'] = $totalTeachersWithEval + count($teachersWithoutEval);

    // الاتجاه السنوي للمعلمين
    $teacherTrends = [];
    if ($selectedSemesterId > 0 && $selectedAcademicYearId > 0) {
        $prevStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $prevStmt->execute([$selectedAcademicYearId]);
        $prevYearId = $prevStmt->fetchColumn();
        if ($prevYearId) {
            $pStmt = $db->prepare("SELECT id FROM semesters WHERE academic_year_id = ? AND name_ar = (SELECT name_ar FROM semesters WHERE id = ?) AND is_active = 1 LIMIT 1");
            $pStmt->execute([$prevYearId, $selectedSemesterId]);
            $prevSemId = $pStmt->fetchColumn();
            if ($prevSemId) {
                $pStmt = $db->prepare("SELECT teacher_id, AVG(combined_score) AS avg_score
                    FROM evaluations WHERE eval_type = 'teaching_summary' AND semester_id = ? AND academic_year_id = ? AND is_active = 1
                    GROUP BY teacher_id");
                $pStmt->execute([$prevSemId, $prevYearId]);
                while ($row = $pStmt->fetch()) {
                    $teacherTrends[(int)$row['teacher_id']] = (float)$row['avg_score'];
                }
            }
        }
    }

    // خطط التطوير النشطة للمعلمين
    $teachersWithPlans = [];
    try {
        $planStmt = $db->query("SELECT teacher_id FROM improvement_plans WHERE plan_type = 'teacher' AND status = 'active' AND is_active = 1");
        while ($row = $planStmt->fetch()) {
            $teachersWithPlans[] = (int)$row['teacher_id'];
        }
    } catch (Exception $e) {}
} catch (Exception $e) {}

// آخر التنبيهات غير المقروءة (خاصة بالتدريس)
$latestAlerts = [];
try {
    $sql = "
        SELECT a.*, s.name_ar AS school_name
        FROM alerts a
        JOIN schools s ON a.school_id = s.id
        WHERE a.is_read = 0 AND a.is_resolved = 0 AND a.alert_type = 'teaching'
        ORDER BY a.created_at DESC LIMIT 5
    ";
    $latestAlerts = $db->query($sql)->fetchAll();
} catch (Exception $e) {}
?>

<div class="section">
    <!-- شريط الفلاتر -->
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="min-width:180px;">
                    <label class="form-label" style="font-size:12px;">السنة الدراسية</label>
                    <select name="academic_year_id" class="form-control" onchange="this.form.submit()">
                        <option value="0" <?= $allYearsSelected ? 'selected' : '' ?>>جميع السنوات</option>
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= !$allYearsSelected && $ay['id'] == $selectedAcademicYearId ? 'selected' : '' ?>><?= e($ay['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:160px;">
                    <label class="form-label" style="font-size:12px;">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control" onchange="this.form.submit()" <?= $allYearsSelected ? 'disabled' : '' ?>>
                        <option value="0" <?= $selectedSemesterId === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                        <?php foreach ($allSemesters as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSemesterId ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:200px;">
                    <label class="form-label" style="font-size:12px;">المدرسة</label>
                    <select name="school_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">جميع المدارس</option>
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSchoolId ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;">تطبيق</button>
            </form>
        </div>
    </div>

    <!-- الخلاصة التنفيذية -->
    <div class="card mb-6" style="background:var(--background);border:1px solid var(--border-light);">
        <div class="card-body" style="padding:12px 20px;font-size:14px;color:var(--text-secondary);">
            متوسط تقييم أداء المعلمين <strong><?= number_format($avgScore, 1) ?></strong> نقطة —
            <strong><?= number_format(count($schoolsData)) ?></strong> مدرسة —
            بإجمالي <strong><?= number_format($totalTeachers) ?></strong> معلماً مُقيّماً —
            <?php if (!empty($schoolsAtRisk)): ?>
            <strong class="text-danger"><?= count($schoolsAtRisk) ?></strong> مدرسة تستوجب التدخل
            <?php else: ?>
            لا توجد مدارس في خطر
            <?php endif; ?>
            — الفصل: <?= e($selectedSemesterName) ?>
            <?php if ($selectedSchoolId > 0): ?>— (<?= e($selectedSchoolName) ?>)<?php endif; ?>
        </div>
    </div>

    <!-- بطاقات KPI -->
    <div class="grid grid-4 mb-6" style="gap:16px;">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(26,86,50,0.1);color:var(--success);">📊</div>
            <div class="stat-value" style="color:<?= $avgScore < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                <?= number_format($avgScore, 1) ?>
            </div>
            <div class="stat-label">متوسط التقييم العام</div>
            <?php if ($selectedSchoolId > 0 && $adjustedAvg != $avgScore): ?>
            <div style="font-size:11px;color:var(--text-muted);">المعدّل: <?= number_format($adjustedAvg, 1) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($selectedSchoolId === 0): ?>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(40,167,69,0.1);color:var(--success);">🥇</div>
            <div class="stat-value" style="font-size:22px;color:var(--success);"><?= e($topSchool) ?></div>
            <div class="stat-label">أعلى مدرسة (<?= number_format($topScore, 1) ?>)</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(220,53,69,0.1);color:var(--danger);">⚠️</div>
            <div class="stat-value" style="font-size:22px;color:var(--danger);"><?= e($bottomSchool) ?></div>
            <div class="stat-label">أقل مدرسة (<?= number_format($bottomScore, 1) ?>)</div>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(23,162,184,0.1);color:var(--info);">📈</div>
            <div class="stat-value" style="color:var(--info);"><?= number_format($adjustedAvg, 1) ?></div>
            <div class="stat-label">المتوسط بعد التعديل السياقي</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(255,193,7,0.1);color:var(--warning);">👨‍🏫</div>
            <div class="stat-value" style="color:var(--warning);"><?= number_format($totalTeachers) ?></div>
            <div class="stat-label">عدد المعلمين المُقيّمين</div>
        </div>
        <?php endif; ?>

        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(23,162,184,0.1);color:var(--info);">👨‍🏫</div>
            <div class="stat-value" style="color:var(--info);"><?= number_format($totalTeachers) ?></div>
            <div class="stat-label">إجمالي المعلمين المُقيّمين</div>
        </div>
    </div>

    <!-- بطاقات KPI - مقاييس التقييمات -->
    <div class="card mb-6" style="background:var(--background);border:1px solid var(--border-light);padding:12px 20px;">
        <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">📋 مقاييس التقييمات</div>
        <div class="grid grid-4" style="gap:12px;">
            <div class="stat-card" style="margin:0;padding:12px;">
                <div class="stat-icon" style="background:rgba(40,167,69,0.1);color:var(--success);">✅</div>
                <div class="stat-value" style="font-size:22px;color:var(--success);"><?= $teacherCompletionStats['total_teachers'] > 0 ? number_format(($teacherCompletionStats['all'] / max($teacherCompletionStats['total_teachers'], 1)) * 100, 0) : 0 ?>%</div>
                <div class="stat-label">معدل إكمال التقييمات (3/3)</div>
                <div style="font-size:11px;color:var(--text-muted);"><?= $teacherCompletionStats['all'] ?> كامل / <?= $teacherCompletionStats['partial'] ?> جزئي</div>
            </div>
            <div class="stat-card" style="margin:0;padding:12px;">
                <div class="stat-icon" style="background:rgba(220,53,69,0.1);color:var(--danger);">🚨</div>
                <div class="stat-value" style="font-size:22px;color:var(--danger);"><?= number_format(count($teachersWithIssues)) ?></div>
                <div class="stat-label">معلمون بحاجة تدخل (&lt; 60%)</div>
            </div>
            <div class="stat-card" style="margin:0;padding:12px;">
                <div class="stat-icon" style="background:rgba(255,193,7,0.1);color:var(--warning);">⚠️</div>
                <div class="stat-value" style="font-size:22px;color:var(--warning);"><?= number_format(count($teachersWithoutEval)) ?></div>
                <div class="stat-label">معلمون بدون تقييم</div>
                <div style="font-size:11px;color:var(--text-muted);">من أصل <?= $teacherCompletionStats['total_teachers'] ?> معلم</div>
            </div>
            <div class="stat-card" style="margin:0;padding:12px;">
                <div class="stat-icon" style="background:rgba(23,162,184,0.1);color:var(--info);">🏆</div>
                <div class="stat-value" style="font-size:20px;color:var(--info);">
                    <?php
                    $bestTeacher = '';
                    $bestScore = 0;
                    if (!empty($teacherEvalData)) {
                        usort($teacherEvalData, function($a, $b) {
                            return ($b['combined_score'] ?? 0) <=> ($a['combined_score'] ?? 0);
                        });
                        if ($teacherEvalData[0]['combined_score'] !== null) {
                            $bestTeacher = mb_substr($teacherEvalData[0]['teacher_name'], 0, 20);
                            $bestScore = $teacherEvalData[0]['combined_score'];
                            // إعادة الترتيب الأصلي (تصاعدي)
                            usort($teacherEvalData, function($a, $b) {
                                return ($a['combined_score'] ?? 0) <=> ($b['combined_score'] ?? 0);
                            });
                        }
                    }
                    ?>
                    <?= e($bestTeacher ?: '—') ?>
                </div>
                <div class="stat-label">أفضل معلم (<?= number_format($bestScore, 1) ?>)</div>
            </div>
        </div>
    </div>

    <!-- المخططات البيانية -->
    <div class="grid grid-2 mb-6" style="gap:16px;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 مقارنة المدارس</h3></div>
            <div class="card-body"><canvas id="teachingBarChart" height="250"></canvas></div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 اتجاه التقييم (آخر 6 فصول)</h3></div>
            <div class="card-body">
                <?php if ($allYearsSelected): ?>
                <div style="text-align:center;padding:30px;color:var(--text-muted);">يرجى اختيار سنة دراسية محددة لعرض الاتجاه الزمني</div>
                <?php else: ?>
                <canvas id="teachingTrendChart" height="250"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- المدارس في خطر -->
    <?php if (!empty($schoolsAtRisk)): ?>
    <div class="card mb-6" style="border:2px solid var(--danger);">
        <div class="card-header" style="background:rgba(220,53,69,0.05);">
            <h3 class="card-title" style="color:var(--danger);">🚨 مدارس تستوجب التدخل الفوري</h3>
        </div>
        <div class="card-body">
            <?php $seen = []; foreach ($schoolsAtRisk as $risk): $sid = (int)$risk['id']; if (isset($seen[$sid])) continue; $seen[$sid] = true; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light);">
                <div>
                    <strong><?= e($risk['name_ar'] ?? ($risk['school'] ?? '')) ?></strong>
                    <span style="font-size:13px;color:var(--text-muted);margin-right:12px;">
                        <?= e($risk['risk_reason'] ?? '') ?>
                        <?php if (!empty($risk['risk_reason2'])): ?> | <?= e($risk['risk_reason2']) ?><?php endif; ?>
                    </span>
                </div>
                <a href="teaching_school.php?id=<?= $risk['id'] ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="btn btn-danger btn-sm">تحليل المدرسة</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- جدول أداء المدارس -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🏆 أداء المدارس</h3>
        </div>
        <div class="card-body">
            <?php if (empty($schoolsData)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📊</div>
                <p>لا توجد بيانات تقييم للمعلمين متاحة.</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>متوسط التقييم</th>
                            <th>المعدّل سياقياً</th>
                            <th>الاتجاه</th>
                            <th>عدد المعلمين</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        foreach ($schoolsData as $school):
                            $score = (float)$school['avg_score'];
                            $adjScore = $school['avg_adjusted'] !== null ? (float)$school['avg_adjusted'] : $score;

                            $prev = $yearlyTrends[(int)$school['id']] ?? null;
                            $trendText = '—';
                            $trendClass = '';
                            if ($prev !== null && $prev > 0 && $score > 0) {
                                $diff = $score - $prev;
                                $pct = ($diff / $prev) * 100;
                                if ($pct > 1) {
                                    $trendText = '▲ +' . number_format($pct, 1) . '%';
                                    $trendClass = 'trend-up';
                                } elseif ($pct < -1) {
                                    $trendText = '▼ ' . number_format(abs($pct), 1) . '%';
                                    $trendClass = 'trend-down';
                                } else {
                                    $trendText = '→ مستقر';
                                    $trendClass = 'trend-neutral';
                                }
                            }

                            if ($score >= 80) $rowBg = 'background:rgba(40,167,69,0.03);';
                            elseif ($score >= 70) $rowBg = 'background:rgba(23,162,184,0.03);';
                            elseif ($score >= 60) $rowBg = 'background:rgba(255,193,7,0.03);';
                            else $rowBg = 'background:rgba(220,53,69,0.03);';
                        ?>
                        <tr style="<?= $rowBg ?>">
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><a href="teaching_school.php?id=<?= $school['id'] ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>"><strong><?= e($school['name_ar']) ?></strong></a></td>
                            <td><strong style="color:<?= $score < 60 ? 'var(--danger)' : 'var(--success)' ?>;"><?= number_format($score, 1) ?></strong></td>
                            <td style="font-size:13px;color:var(--text-muted);"><?= number_format($adjScore, 1) ?></td>
                            <td><span class="<?= $trendClass ?>"><?= $trendText ?></span></td>
                            <td><?= (int)$school['teacher_count'] ?></td>
                            <td>
                                <?php
                                $rating = getEqiRating($score);
                                ?>
                                <span style="background:var(--<?= $rating[2] ?>);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;"><?= $rating[0] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- جدول جميع تقييمات المعلمين -->
    <div class="card mb-6" id="teachers-section">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h3 class="card-title">👨‍🏫 جميع تقييمات المعلمين</h3>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="teacherSearchInput" class="form-control" placeholder="🔍 بحث باسم المعلم..." style="width:200px;font-size:13px;padding:4px 8px;" oninput="filterTeacherTable()">
                <select id="completionFilter" class="form-control" style="width:140px;font-size:13px;padding:4px 8px;" onchange="filterTeacherTable()">
                    <option value="all">جميع التقييمات</option>
                    <option value="complete">مكتمل (3/3)</option>
                    <option value="partial">جزئي</option>
                    <option value="none">بدون تقييم</option>
                    <option value="low">أقل من 60%</option>
                </select>
                <a href="?<?= $_SERVER['QUERY_STRING'] ?>&export=teachers_csv" class="btn btn-light btn-sm" style="font-size:12px;">📥 CSV</a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($teacherEvalData) && empty($teachersWithoutEval)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <div style="font-size:40px;margin-bottom:8px;">📋</div>
                <p>لا توجد بيانات تقييم للمعلمين متاحة.</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table" id="teacherEvalTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المعلم</th>
                            <th>المدرسة</th>
                            <th>ذاتي (20%)</th>
                            <th>أقران (30%)</th>
                            <th>إشرافي (50%)</th>
                            <th>المُجمّع</th>
                            <th>الاكتمال</th>
                            <th>الاتجاه</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tRank = 1;
                        // دمج المعلمين بدون تقييم مع المعلمين المقيمين
                        $allTeachers = [];
                        foreach ($teacherEvalData as $t) {
                            $tid = (int)$t['teacher_id'];
                            $prevScore = $teacherTrends[$tid] ?? null;
                            $cs = $t['combined_score'] ?? null;
                            $trendText = '—';
                            $trendClass = '';
                            if ($prevScore !== null && $prevScore > 0 && $cs !== null && $cs > 0) {
                                $diff = $cs - $prevScore;
                                $pct = ($diff / $prevScore) * 100;
                                if ($pct > 1) { $trendText = '▲'; $trendClass = 'trend-up'; }
                                elseif ($pct < -1) { $trendText = '▼'; $trendClass = 'trend-down'; }
                                else { $trendText = '→'; $trendClass = 'trend-neutral'; }
                            }
                            $t['trend_text'] = $trendText;
                            $t['trend_class'] = $trendClass;
                            $t['has_plan'] = in_array($tid, $teachersWithPlans);
                            $allTeachers[] = $t;
                        }
                        foreach ($teachersWithoutEval as $wo) {
                            $allTeachers[] = [
                                'teacher_id' => (int)$wo['id'],
                                'teacher_name' => $wo['full_name_ar'],
                                'school_id' => (int)$wo['school_id'],
                                'school_name' => $wo['school_name'],
                                'self_score' => null,
                                'peer_score' => null,
                                'admin_score' => null,
                                'combined_score' => null,
                                'completion' => 0,
                                'trend_text' => '—',
                                'trend_class' => '',
                                'has_plan' => false,
                            ];
                        }
                        foreach ($allTeachers as $t):
                            $tid = (int)$t['teacher_id'];
                            $cs = $t['combined_score'] ?? null;
                            $completion = $t['completion'] ?? 0;
                            $hasPlan = $t['has_plan'] ?? false;

                            if ($cs !== null && $cs < 60) $rowBg = 'background:rgba(220,53,69,0.03);';
                            elseif ($cs !== null && $cs < 75) $rowBg = 'background:rgba(255,193,7,0.03);';
                            elseif ($cs !== null) $rowBg = 'background:rgba(40,167,69,0.03);';
                            else $rowBg = 'background:rgba(108,117,125,0.03);';

                            $rating = $cs !== null ? getEqiRating($cs) : ['غير مقيم', '—', 'secondary'];
                        ?>
                        <tr style="<?= $rowBg ?>" data-teacher-name="<?= e($t['teacher_name']) ?>" data-completion="<?= $completion === 3 ? 'complete' : ($completion > 0 ? 'partial' : 'none') ?>" data-combined="<?= $cs !== null ? $cs : 0 ?>">
                            <td><strong><?= $tRank++ ?></strong></td>
                            <td>
                                <a href="teaching_teacher.php?id=<?= $tid ?>&school_id=<?= $t['school_id'] ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="teacher-name-link" style="font-weight:600;text-decoration:none;color:inherit;"><?= e($t['teacher_name']) ?></a>
                            </td>
                            <td><a href="teaching_school.php?id=<?= $t['school_id'] ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" style="font-size:13px;color:var(--text-secondary);text-decoration:none;"><?= e($t['school_name']) ?></a></td>
                            <td style="font-size:13px;">
                                <?php if ($t['self_score'] !== null): ?>
                                <span style="color:var(--info);font-weight:500;"><?= number_format($t['self_score'], 1) ?>%</span>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($t['peer_score'] !== null): ?>
                                <span style="color:var(--warning);font-weight:500;"><?= number_format($t['peer_score'], 1) ?>%</span>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($t['admin_score'] !== null): ?>
                                <span style="color:var(--primary);font-weight:500;"><?= number_format($t['admin_score'], 1) ?>%</span>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($cs !== null): ?>
                                <strong style="color:<?= $cs < 60 ? 'var(--danger)' : 'var(--success)' ?>;"><?= number_format($cs, 1) ?>%</strong>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:11px;">بدون تقييم</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $compColor = $completion === 3 ? 'var(--success)' : ($completion > 0 ? 'var(--warning)' : 'var(--danger)');
                                $compLabel = $completion === 3 ? '✅' : ($completion > 0 ? '⚠️ ' . $completion . '/3' : '❌');
                                ?>
                                <span style="color:<?= $compColor ?>;font-size:12px;font-weight:500;"><?= $compLabel ?></span>
                            </td>
                            <td><span class="<?= $t['trend_class'] ?>"><?= $t['trend_text'] ?></span></td>
                            <td>
                                <span style="background:var(--<?= $rating[2] ?>);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:10px;white-space:nowrap;"><?= $rating[0] ?></span>
                            </td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:nowrap;">
                                    <button type="button" class="btn btn-sm btn-light teacher-quick-view" style="font-size:10px;padding:2px 6px;cursor:pointer;"
                                        data-teacher-id="<?= $tid ?>"
                                        data-teacher-name="<?= e($t['teacher_name']) ?>"
                                        data-school-name="<?= e($t['school_name']) ?>"
                                        data-self="<?= $t['self_score'] !== null ? number_format($t['self_score'], 1) : '' ?>"
                                        data-peer="<?= $t['peer_score'] !== null ? number_format($t['peer_score'], 1) : '' ?>"
                                        data-admin="<?= $t['admin_score'] !== null ? number_format($t['admin_score'], 1) : '' ?>"
                                        data-combined="<?= $cs !== null ? number_format($cs, 1) : '' ?>"
                                        data-school-id="<?= $t['school_id'] ?>"
                                        onclick="openTeacherModal(this)">👁️</button>
                                    <?php if ($cs !== null && $cs < 60 && !$hasPlan): ?>
                                    <a href="development_plan.php?school_id=<?= $t['school_id'] ?>&teacher_id=<?= $tid ?>" class="btn btn-sm" style="background:var(--danger);color:white;font-size:10px;padding:2px 6px;text-decoration:none;border-radius:var(--radius-full);">🎯</a>
                                    <?php endif; ?>
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

    <!-- معلمون بحاجة تدخل فوري -->
    <?php
    $interventionTeachers = [];
    foreach ($teacherEvalData as $t) {
        $tid = (int)$t['teacher_id'];
        $cs = $t['combined_score'] ?? null;
        if ($cs === null) continue;
        $hasPlan = in_array($tid, $teachersWithPlans);
        $prevScore = $teacherTrends[$tid] ?? null;
        $sharpDrop = ($prevScore !== null && $prevScore > 0 && (($cs - $prevScore) / $prevScore) < -0.15);

        if ($cs < 50 || ($cs < 60 && !$hasPlan) || $sharpDrop) {
            $reasons = [];
            if ($cs < 50) $reasons[] = 'تقييم أقل من 50%';
            if ($cs < 60 && !$hasPlan) $reasons[] = 'أقل من 60% بدون خطة تطوير';
            if ($sharpDrop) $reasons[] = 'انخفاض حاد > 15% عن الفصل السابق';
            $t['reasons'] = implode(' | ', $reasons);
            $interventionTeachers[] = $t;
        }
    }
    ?>
    <?php if (!empty($interventionTeachers)): ?>
    <div class="card mb-6" style="border:2px solid var(--danger);">
        <div class="card-header" style="background:rgba(220,53,69,0.05);">
            <h3 class="card-title" style="color:var(--danger);">🚨 معلمون بحاجة تدخل فوري</h3>
        </div>
        <div class="card-body">
            <?php foreach ($interventionTeachers as $t): $tid = (int)$t['teacher_id']; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light);">
                <div>
                    <strong><?= e($t['teacher_name']) ?></strong>
                    <span style="font-size:12px;color:var(--text-muted);margin-right:8px;">— <?= e($t['school_name'] ?? '') ?></span>
                    <span style="font-size:12px;color:var(--danger);margin-right:8px;font-weight:500;">(<?= number_format($cs = $t['combined_score'] ?? 0, 1) ?>%)</span>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?= e($t['reasons'] ?? '') ?></div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="teaching_teacher.php?id=<?= $tid ?>&school_id=<?= $t['school_id'] ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="btn btn-danger btn-sm" style="font-size:11px;">تحليل</a>
                    <?php if (!in_array($tid, $teachersWithPlans)): ?>
                    <a href="development_plan.php?school_id=<?= $t['school_id'] ?>&teacher_id=<?= $tid ?>" class="btn btn-sm" style="background:var(--warning);color:white;font-size:11px;text-decoration:none;border-radius:4px;padding:4px 8px;">🎯 خطة</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick View Modal -->
    <div id="teacherQuickModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)closeTeacherModal()">
        <div style="background:white;border-radius:12px;width:500px;max-width:90vw;max-height:80vh;overflow-y:auto;padding:0;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border-light);">
                <h3 id="modalTeacherName" style="margin:0;font-size:16px;">👨‍🏫 <span id="modalTeacherNameSpan"></span></h3>
                <button onclick="closeTeacherModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <div style="padding:20px;">
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
                    المدرسة: <strong id="modalSchoolName"></strong>
                </div>
                <div class="grid grid-2" style="gap:12px;margin-bottom:20px;">
                    <div class="stat-card" style="margin:0;padding:12px;text-align:center;border-top:3px solid var(--info);">
                        <div style="font-size:11px;color:var(--text-muted);">ذاتي (20%)</div>
                        <div id="modalSelfScore" style="font-size:28px;font-weight:bold;color:var(--info);">—</div>
                    </div>
                    <div class="stat-card" style="margin:0;padding:12px;text-align:center;border-top:3px solid var(--warning);">
                        <div style="font-size:11px;color:var(--text-muted);">أقران (30%)</div>
                        <div id="modalPeerScore" style="font-size:28px;font-weight:bold;color:var(--warning);">—</div>
                    </div>
                    <div class="stat-card" style="margin:0;padding:12px;text-align:center;border-top:3px solid var(--primary);">
                        <div style="font-size:11px;color:var(--text-muted);">إشرافي (50%)</div>
                        <div id="modalAdminScore" style="font-size:28px;font-weight:bold;color:var(--primary);">—</div>
                    </div>
                    <div class="stat-card" style="margin:0;padding:12px;text-align:center;border-top:3px solid var(--success);">
                        <div style="font-size:11px;color:var(--text-muted);">المُجمّع</div>
                        <div id="modalCombinedScore" style="font-size:28px;font-weight:bold;">—</div>
                    </div>
                </div>
                <div style="text-align:center;">
                    <a id="modalTeacherLink" href="#" class="btn btn-primary btn-sm">عرض التفاصيل الكاملة ←</a>
                </div>
            </div>
        </div>
    </div>

    <!-- آخر التنبيهات -->
    <?php if (!empty($latestAlerts)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔔 آخر التنبيهات (تقييم المعلمين)</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php foreach ($latestAlerts as $alert): ?>
            <div style="padding:12px 20px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong style="color:<?= $alert['severity'] === 'danger' ? 'var(--danger)' : 'var(--warning)' ?>;">
                        <?= e($alert['title_ar']) ?>
                    </strong>
                    <span style="color:var(--text-muted);font-size:13px;margin-right:8px;">
                        — <?= e($alert['school_name']) ?>
                    </span>
                    <div style="color:var(--text-secondary);font-size:12px;margin-top:4px;">
                        <?= e(mb_substr($alert['message_ar'], 0, 100)) ?><?= mb_strlen($alert['message_ar']) > 100 ? '...' : '' ?>
                    </div>
                </div>
                <span style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?= formatDateAr($alert['created_at'], 'short') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Bar Chart
    const barCtx = document.getElementById('teachingBarChart');
    if (barCtx) {
        const labels = <?= json_encode($barLabels, JSON_UNESCAPED_UNICODE) ?>;
        const datasets = [];
        if (labels.length > 0) {
            datasets.push({ label: 'ذاتي', data: <?= json_encode($barSelf) ?>, backgroundColor: 'rgba(23,162,184,0.7)' });
            datasets.push({ label: 'أقران', data: <?= json_encode($barPeer) ?>, backgroundColor: 'rgba(255,193,7,0.7)' });
            datasets.push({ label: 'إشرافي', data: <?= json_encode($barAdmin) ?>, backgroundColor: 'rgba(26,86,50,0.7)' });
            datasets.push({ label: 'المجموع', data: <?= json_encode($barCombined) ?>, backgroundColor: 'rgba(220,53,69,0.7)' });
        }
        new Chart(barCtx, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { font: { family: 'Tajawal' }, usePointStyle: true } } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { font: { family: 'Tajawal' } } },
                    x: { ticks: { font: { family: 'Tajawal' } } }
                }
            }
        });
    }

    // Line Chart
    const lineCtx = document.getElementById('teachingTrendChart');
    if (lineCtx) {
        const labels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
        const schoolData = <?= json_encode($trendSchoolData) ?>;
        const overallData = <?= json_encode($trendOverallData) ?>;
        const datasets = [];

        <?php if ($selectedSchoolId > 0): ?>
        datasets.push({
            label: '<?= e($selectedSchoolName) ?>',
            data: schoolData,
            borderColor: '#1a5632',
            backgroundColor: 'rgba(26,86,50,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 4,
            pointBackgroundColor: '#1a5632',
        });
        datasets.push({
            label: 'متوسط المجموعة',
            data: overallData,
            borderColor: '#c8a951',
            backgroundColor: 'transparent',
            borderDash: [6, 3],
            tension: 0.3,
            pointRadius: 3,
            pointBackgroundColor: '#c8a951',
        });
        <?php else: ?>
        datasets.push({
            label: 'متوسط التقييم',
            data: schoolData,
            borderColor: '#1a5632',
            backgroundColor: 'rgba(26,86,50,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 4,
            pointBackgroundColor: '#1a5632',
        });
        <?php endif; ?>

        new Chart(lineCtx, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Tajawal' }, usePointStyle: true } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { font: { family: 'Tajawal' } } },
                    x: { ticks: { font: { family: 'Tajawal' } } }
                }
            }
        });
    }
});

// دوال البحث والتصفية لجدول المعلمين
function filterTeacherTable() {
    const input = document.getElementById('teacherSearchInput');
    const filter = document.getElementById('completionFilter');
    const table = document.getElementById('teacherEvalTable');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const searchTerm = input ? input.value.trim().toLowerCase() : '';
    const completionVal = filter ? filter.value : 'all';

    rows.forEach(function(row) {
        const name = row.getAttribute('data-teacher-name') || '';
        const completion = row.getAttribute('data-completion') || 'none';
        const combined = parseFloat(row.getAttribute('data-combined')) || 0;

        const matchesSearch = name.includes(searchTerm);
        let matchesFilter = true;
        if (completionVal === 'complete') matchesFilter = (completion === 'complete');
        else if (completionVal === 'partial') matchesFilter = (completion === 'partial');
        else if (completionVal === 'none') matchesFilter = (completion === 'none');
        else if (completionVal === 'low') matchesFilter = (combined > 0 && combined < 60);

        row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
    });
}

// دوال الـ Quick View Modal
function openTeacherModal(btn) {
    const modal = document.getElementById('teacherQuickModal');
    if (!modal) return;

    document.getElementById('modalTeacherNameSpan').textContent = btn.getAttribute('data-teacher-name') || '';
    document.getElementById('modalSchoolName').textContent = btn.getAttribute('data-school-name') || '';

    const selfVal = btn.getAttribute('data-self');
    const peerVal = btn.getAttribute('data-peer');
    const adminVal = btn.getAttribute('data-admin');
    const combinedVal = btn.getAttribute('data-combined');

    document.getElementById('modalSelfScore').textContent = selfVal ? selfVal + '%' : '—';
    document.getElementById('modalSelfScore').style.color = selfVal ? 'var(--info)' : 'var(--text-muted)';
    document.getElementById('modalPeerScore').textContent = peerVal ? peerVal + '%' : '—';
    document.getElementById('modalPeerScore').style.color = peerVal ? 'var(--warning)' : 'var(--text-muted)';
    document.getElementById('modalAdminScore').textContent = adminVal ? adminVal + '%' : '—';
    document.getElementById('modalAdminScore').style.color = adminVal ? 'var(--primary)' : 'var(--text-muted)';

    const combinedEl = document.getElementById('modalCombinedScore');
    if (combinedVal) {
        combinedEl.textContent = combinedVal + '%';
        combinedEl.style.color = parseFloat(combinedVal) < 60 ? 'var(--danger)' : 'var(--success)';
    } else {
        combinedEl.textContent = '—';
        combinedEl.style.color = 'var(--text-muted)';
    }

    const teacherId = btn.getAttribute('data-teacher-id');
    const schoolId = btn.getAttribute('data-school-id');
    const yearId = <?= json_encode($selectedAcademicYearId) ?>;
    const semId = <?= json_encode($selectedSemesterId) ?>;
    const link = document.getElementById('modalTeacherLink');
    link.href = 'teaching_teacher.php?id=' + teacherId + '&school_id=' + schoolId + '&academic_year_id=' + yearId + '&semester_id=' + semId;

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeTeacherModal() {
    const modal = document.getElementById('teacherQuickModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// إغلاق الـ modal بالضغط على Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTeacherModal();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>