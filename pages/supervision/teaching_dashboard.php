<?php
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

$yearFilter = $allYearsSelected ? '' : "AND tes.academic_year_id = " . (int)$selectedAcademicYearId;

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
    $semFilter .= " AND tes.semester_id = $selectedSemesterId";
}

// KPI 1: متوسط التقييم العام
$avgScore = 0;
try {
    $sql = "SELECT AVG(per_teacher.avg_score) FROM (
                SELECT tes.teacher_id, AVG(tes.combined_score) AS avg_score
                FROM teaching_evaluation_summary tes
                JOIN users u ON tes.teacher_id = u.id
                WHERE tes.is_active = 1 $yearFilter $schoolFilter
                GROUP BY tes.teacher_id
            ) per_teacher";
    if ($selectedSemesterId > 0) {
        $sql = "SELECT AVG(tes.combined_score) FROM teaching_evaluation_summary tes
                JOIN users u ON tes.teacher_id = u.id
                WHERE tes.is_active = 1 $yearFilter AND tes.semester_id = $selectedSemesterId $schoolFilter";
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
    $countSql = "SELECT COUNT(DISTINCT tes.teacher_id) FROM teaching_evaluation_summary tes
                 JOIN users u ON tes.teacher_id = u.id
                 WHERE tes.is_active = 1 $yearFilter $schoolClause";
    if ($selectedSemesterId > 0) {
        $countSql .= " AND tes.semester_id = $selectedSemesterId";
    }
    $totalTeachers = (int)($db->query($countSql)->fetchColumn() ?: 0);

    if ($selectedSchoolId === 0) {
        $sql = "SELECT s.name_ar, AVG(tes.combined_score) AS s_avg
                FROM teaching_evaluation_summary tes
                JOIN users u ON tes.teacher_id = u.id
                JOIN schools s ON u.school_id = s.id
                WHERE tes.is_active = 1 $yearFilter
                GROUP BY s.id, s.name_ar
                ORDER BY s_avg DESC";
        if ($selectedSemesterId > 0) {
            $sql = "SELECT s.name_ar, AVG(tes.combined_score) AS s_avg
                    FROM teaching_evaluation_summary tes
                    JOIN users u ON tes.teacher_id = u.id
                    JOIN schools s ON u.school_id = s.id
                    WHERE tes.is_active = 1 $yearFilter AND tes.semester_id = $selectedSemesterId
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
        $adjustedAvg = $adj->calculateAdjustedSQI($selectedSchoolId, $selectedAcademicYearId, $avgScore);
    } catch (Exception $e) {}
}

// رسم بياني: مقارنة المدارس (Bar Chart)
$barLabels = [];
$barSelf = [];
$barPeer = [];
$barAdmin = [];
$barCombined = [];
try {
    $barSemFilter = $selectedSemesterId > 0 ? "AND tes.semester_id = $selectedSemesterId" : $yearFilter;
    $schoolClause = $selectedSchoolId > 0 ? "AND u.school_id = $selectedSchoolId" : '';

    $sql = "SELECT s.name_ar,
                   AVG(tes.self_score) AS s_self,
                   AVG(tes.peer_score) AS s_peer,
                   AVG(tes.admin_score) AS s_admin,
                   AVG(tes.combined_score) AS s_combined
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id
            JOIN schools s ON u.school_id = s.id
            WHERE tes.is_active = 1 $barSemFilter $schoolClause
            GROUP BY s.id, s.name_ar
            ORDER BY s_combined DESC";

    if ($selectedSchoolId === 0) {
        $barRows = $db->query($sql)->fetchAll();
    } else {
        // مدرسة واحدة: قارنها بمتوسط المجموعة
        $barRows = $db->query($sql)->fetchAll();
        $groupAvg = $db->query("
            SELECT AVG(tes.self_score) AS s_self, AVG(tes.peer_score) AS s_peer,
                   AVG(tes.admin_score) AS s_admin, AVG(tes.combined_score) AS s_combined
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id
            WHERE tes.is_active = 1 $semFilter
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
                SELECT AVG(tes.combined_score)
                FROM teaching_evaluation_summary tes
                JOIN users u ON tes.teacher_id = u.id
                WHERE tes.semester_id = ? AND tes.academic_year_id = ? AND tes.is_active = 1 $schoolClause
            ");
            $sStmt->execute([$hsId, $selectedAcademicYearId]);
            $trendSchoolData[] = round((float)($sStmt->fetchColumn() ?: 0), 1);

            $oStmt = $db->prepare("
                SELECT AVG(tes.combined_score)
                FROM teaching_evaluation_summary tes
                JOIN users u ON tes.teacher_id = u.id
                WHERE tes.semester_id = ? AND tes.academic_year_id = ? AND tes.is_active = 1
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
        ? "AND tes.semester_id = $selectedSemesterId"
        : "";

    $sql = "SELECT s.id, s.name_ar, s.reference_code,
                   AVG(tes.combined_score) AS avg_score,
                   AVG(tes.adjusted_score) AS avg_adjusted,
                   COUNT(DISTINCT tes.teacher_id) AS teacher_count
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id
            JOIN schools s ON u.school_id = s.id
            WHERE tes.is_active = 1 $semFilter
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
                    SELECT u.school_id, AVG(tes.combined_score) AS avg_score
                    FROM teaching_evaluation_summary tes
                    JOIN users u ON tes.teacher_id = u.id
                    WHERE tes.semester_id = ? AND tes.academic_year_id = ? AND tes.is_active = 1
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
            SELECT COUNT(DISTINCT tes.teacher_id)
            FROM teaching_evaluation_summary tes
            JOIN users u ON tes.teacher_id = u.id AND u.school_id = ?
            LEFT JOIN teacher_development_plans tdp
                ON tdp.teacher_id = tes.teacher_id AND tdp.status = 'active' AND tdp.is_active = 1
            WHERE tes.is_active = 1 $semFilter
              AND tes.combined_score < 60 AND tdp.id IS NULL
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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>