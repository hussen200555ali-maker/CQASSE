<?php
/**
 * ============================================================
 * لوحة القيادة الاستراتيجية لمدير المجموعة
 * نظام التقييم الشامل لجودة التعليم المدرسي - EQAS
 * ============================================================
 */

$pageTitle = 'لوحة القيادة الاستراتيجية';
$breadcrumb = [
    'الإدارة' => null,
    'لوحة القيادة الاستراتيجية' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
requireRole('group_admin');

// تضمين خدمة التعديل السياقي
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

// ═══════════════════════════════════════════════════════════
// معالجة الفلاتر
// ═══════════════════════════════════════════════════════════

$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$selectedSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

// تعيين القيم الافتراضية
if ($selectedAcademicYearId === 0) {
    $selectedAcademicYearId = (int)currentAcademicYearId();
}
// فقط إذا لم يحدد المستخدم صراحةً semester_id=0 (جميع الفصول)، نضع القيمة الافتراضية
if (!isset($_GET['semester_id']) && $selectedAcademicYearId > 0) {
    $selectedSemesterId = (int)currentSemesterId($selectedAcademicYearId);
}

// ─── قوائم القوائم المنسدلة ──────────────────────────────
$academicYears = [];
try {
    $academicYears = $db->query("SELECT id, name AS name_ar FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {}

$allSemesters = [];
if ($selectedAcademicYearId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedAcademicYearId]);
        $allSemesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$schools = getAvailableSchools($db, 'group_admin', null);

// التحقق من صحة semester_id المختار (0 = جميع الفصول)
$validSemester = ($selectedSemesterId === 0);
if (!$validSemester) {
    foreach ($allSemesters as $s) {
        if ((int)$s['id'] === $selectedSemesterId) { $validSemester = true; break; }
    }
}
if (!$validSemester && !empty($allSemesters)) {
    $selectedSemesterId = (int)$allSemesters[0]['id'];
}

// التحقق من صحة school_id المختار
if ($selectedSchoolId > 0) {
    $validSchool = false;
    foreach ($schools as $s) {
        if ((int)$s['id'] === $selectedSchoolId) { $validSchool = true; break; }
    }
    if (!$validSchool) $selectedSchoolId = 0;
}

// اسم المدرسة المختارة
$selectedSchoolName = 'الكل';
if ($selectedSchoolId > 0) {
    foreach ($schools as $s) {
        if ((int)$s['id'] === $selectedSchoolId) {
            $selectedSchoolName = $s['name_ar']; break;
        }
    }
}

// اسم الفصل المختار
$selectedSemesterName = 'جميع الفصول';
if ($selectedSemesterId > 0) {
    foreach ($allSemesters as $s) {
        if ((int)$s['id'] === $selectedSemesterId) {
            $selectedSemesterName = $s['name_ar']; break;
        }
    }
}

// ═══════════════════════════════════════════════════════════
// بناء شروط WHERE للاستعلامات
// ═══════════════════════════════════════════════════════════

$schoolFilter = $selectedSchoolId > 0 ? "AND s.id = $selectedSchoolId" : '';

// ═══════════════════════════════════════════════════════════
// KPI - إحصائيات سريعة
// ═══════════════════════════════════════════════════════════

// 1. عدد المدارس النشطة
$totalSchools = 0;
try { $totalSchools = (int)$db->query("SELECT COUNT(*) FROM schools WHERE is_active = 1")->fetchColumn(); } catch (Exception $e) {}

// 2. عدد الطلاب النشطين
$totalStudents = 0;
try {
    $sql = "SELECT COUNT(*) FROM students WHERE is_active = 1";
    if ($selectedSchoolId > 0) $sql .= " AND school_id = $selectedSchoolId";
    $totalStudents = (int)$db->query($sql)->fetchColumn();
} catch (Exception $e) {}

// 3. متوسط الحضور
$avgAttendance = 0;
try {
    $sql = "SELECT AVG(a.attendance_rate) FROM attendance_summaries a WHERE 1=1";
    if ($selectedSemesterId > 0) $sql .= " AND a.semester_id = $selectedSemesterId";
    if ($selectedSchoolId > 0) $sql .= " AND a.student_id IN (SELECT id FROM students WHERE school_id = $selectedSchoolId)";
    $avgAttendance = (float)($db->query($sql)->fetchColumn() ?: 0);
} catch (Exception $e) {}

// 4. متوسط EQI الحالي
$avgEQI = 0;
try {
    if ($selectedSemesterId > 0) {
        $sql = "SELECT AVG(eq.overall_eqi) FROM equi_results eq WHERE eq.semester_id = $selectedSemesterId";
    } elseif ($selectedAcademicYearId > 0) {
        $sql = "SELECT AVG(eq.overall_eqi) FROM equi_results eq WHERE eq.academic_year_id = $selectedAcademicYearId";
    } else {
        $sql = "SELECT AVG(eq.overall_eqi) FROM equi_results eq";
    }
    if ($selectedSchoolId > 0) $sql .= " AND eq.school_id = $selectedSchoolId";
    $avgEQI = (float)($db->query($sql)->fetchColumn() ?: 0);
} catch (Exception $e) {}

// 5. اتجاه EQI السنوي (لاتجاه KPI)
$prevAvgEQI = null;
$eqiTrendText = '';
$eqiTrendClass = '';
try {
    if ($selectedSemesterId > 0) {
        // مقارنة فصليّة: نفس الفصل من العام السابق
        $stmt = $db->prepare("SELECT name_ar, start_date FROM semesters WHERE id = ?");
        $stmt->execute([$selectedSemesterId]);
        $semInfo = $stmt->fetch();

        if ($semInfo) {
            $prevStmt = $db->prepare("
                SELECT AVG(eq.overall_eqi)
                FROM equi_results eq
                JOIN semesters s ON eq.semester_id = s.id
                WHERE s.name_ar = ? AND s.start_date < ? AND s.is_active = 1
            ");
            $prevStmt->execute([$semInfo['name_ar'], $semInfo['start_date']]);
            $prevAvgEQI = (float)($prevStmt->fetchColumn() ?: 0);
        }
    } elseif ($selectedAcademicYearId > 0) {
        // مقارنة سنويّة: العام الحالي vs العام السابق
        $prevYearStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $prevYearStmt->execute([$selectedAcademicYearId]);
        $prevYearId = $prevYearStmt->fetchColumn();

        if ($prevYearId) {
            $prevStmt = $db->prepare("SELECT AVG(overall_eqi) FROM equi_results WHERE academic_year_id = ?");
            $prevStmt->execute([$prevYearId]);
            $prevAvgEQI = (float)($prevStmt->fetchColumn() ?: 0);
        }
    }

    if ($prevAvgEQI > 0 && $avgEQI > 0) {
        $diff = $avgEQI - $prevAvgEQI;
        $pct = ($diff / $prevAvgEQI) * 100;
        if ($pct > 1) {
            $eqiTrendText = '▲ +' . number_format($pct, 1) . '%';
            $eqiTrendClass = 'trend-up';
        } elseif ($pct < -1) {
            $eqiTrendText = '▼ ' . number_format(abs($pct), 1) . '%';
            $eqiTrendClass = 'trend-down';
        } else {
            $eqiTrendText = '→ مستقر';
            $eqiTrendClass = 'trend-neutral';
        }
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// EQI History - آخر 6 فصول (للرسم البياني الخطي)
// ═══════════════════════════════════════════════════════════

$chartLabels = [];
$chartSchoolData = [];
$chartOverallData = [];
try {
    $histStmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY start_date DESC LIMIT 6");
    $histSemesters = $histStmt->fetchAll();
    $histSemesters = array_reverse($histSemesters);

    foreach ($histSemesters as $hs) {
        $hsId = (int)$hs['id'];
        $chartLabels[] = $hs['name_ar'];

        // EQI للمدرسة المختارة (أو جميع المدارس)
        $sqi = $db->prepare("SELECT AVG(overall_eqi) FROM equi_results WHERE semester_id = ?" . ($selectedSchoolId > 0 ? " AND school_id = $selectedSchoolId" : ""));
        $sqi->execute([$hsId]);
        $chartSchoolData[] = round((float)($sqi->fetchColumn() ?: 0), 1);

        // متوسط المجموعة
        $oqi = $db->prepare("SELECT AVG(overall_eqi) FROM equi_results WHERE semester_id = ?");
        $oqi->execute([$hsId]);
        $chartOverallData[] = round((float)($oqi->fetchColumn() ?: 0), 1);
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// توزيع المدارس حسب الأداء (للدونات)
// ═══════════════════════════════════════════════════════════

$bucketLabels = [];
$bucketCounts = [];
try {
    $buckets = ['ممتاز' => 0, 'جيد جداً' => 0, 'مقبول' => 0, 'ضعيف' => 0];
    if ($selectedSemesterId > 0) {
        $stmt = $db->prepare("SELECT overall_eqi FROM equi_results WHERE semester_id = ?");
        $stmt->execute([$selectedSemesterId]);
    } elseif ($selectedAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT overall_eqi FROM equi_results WHERE academic_year_id = ?");
        $stmt->execute([$selectedAcademicYearId]);
    } else {
        $stmt = $db->query("SELECT overall_eqi FROM equi_results");
    }
    while ($row = $stmt->fetch()) {
        $r = getEqiRating((float)$row['overall_eqi']);
        if (isset($buckets[$r[0]])) $buckets[$r[0]]++;
    }
    $bucketLabels = array_keys($buckets);
    $bucketCounts = array_values($buckets);
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// أداء المدارس (جدول + اتجاه سنوي)
// ═══════════════════════════════════════════════════════════

$schoolsData = [];
$yearlyTrends = [];
try {
    if ($selectedSemesterId > 0) {
        // فصل محدد: نأخذ EQI لذلك الفصل
        $sql = "
            SELECT s.id, s.name_ar, s.reference_code,
                   COALESCE(eq.overall_eqi, 0) AS eqi_score,
                   eq.overall_eqi AS raw_eqi
            FROM schools s
            LEFT JOIN equi_results eq ON s.id = eq.school_id AND eq.semester_id = ? AND eq.academic_year_id = ?
            WHERE s.is_active = 1 $schoolFilter
            ORDER BY eqi_score DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedSemesterId, $selectedAcademicYearId]);
    } else {
        // جميع الفصول: متوسط EQI للعام
        $sql = "
            SELECT s.id, s.name_ar, s.reference_code,
                   COALESCE(AVG(eq.overall_eqi), 0) AS eqi_score,
                   AVG(eq.overall_eqi) AS raw_eqi
            FROM schools s
            LEFT JOIN equi_results eq ON s.id = eq.school_id AND eq.academic_year_id = ?
            WHERE s.is_active = 1 $schoolFilter
            GROUP BY s.id, s.name_ar, s.reference_code
            ORDER BY eqi_score DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedAcademicYearId]);
    }
    $schoolsData = $stmt->fetchAll();

    // اتجاه سنوي
    if (!empty($schoolsData) && $selectedAcademicYearId > 0) {
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$selectedAcademicYearId]);
        $prevYearId = $stmt->fetchColumn();

        if ($prevYearId) {
            if ($selectedSemesterId > 0) {
                // اتجاه فصلي: نفس الفصل من العام السابق
                $stmt = $db->prepare("
                    SELECT s2.id FROM semesters s2
                    JOIN semesters s1 ON s1.id = ?
                    WHERE s2.academic_year_id = ? AND s2.name_ar = s1.name_ar AND s2.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$selectedSemesterId, $prevYearId]);
                $prevSemId = $stmt->fetchColumn();

                if ($prevSemId) {
                    $stmt = $db->prepare("SELECT school_id, overall_eqi FROM equi_results WHERE semester_id = ? AND academic_year_id = ?");
                    $stmt->execute([$prevSemId, $prevYearId]);
                    while ($row = $stmt->fetch()) {
                        $yearlyTrends[(int)$row['school_id']] = (float)$row['overall_eqi'];
                    }
                }
            } else {
                // اتجاه سنوي: متوسط العام السابق
                $stmt = $db->prepare("SELECT school_id, AVG(overall_eqi) as avg_eqi FROM equi_results WHERE academic_year_id = ? GROUP BY school_id");
                $stmt->execute([$prevYearId]);
                while ($row = $stmt->fetch()) {
                    $yearlyTrends[(int)$row['school_id']] = (float)$row['avg_eqi'];
                }
            }
        }
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// المدارس في الخطر
// ═══════════════════════════════════════════════════════════

$schoolsAtRisk = [];
foreach ($schoolsData as $school) {
    $eqi = (float)$school['eqi_score'];
    $prev = $yearlyTrends[(int)$school['id']] ?? null;

    if ($eqi > 0 && $eqi < 50) {
        $school['risk_reason'] = 'EQI أقل من 50';
        $schoolsAtRisk[] = $school;
    } elseif ($prev !== null && $prev > 0 && $eqi > 0 && $eqi < $prev) {
        $school['risk_reason'] = 'انخفاض عن العام السابق';
        $schoolsAtRisk[] = $school;
    }
}

// ═══════════════════════════════════════════════════════════
// آخر التنبيهات غير المقروءة
// ═══════════════════════════════════════════════════════════

$latestAlerts = [];
try {
    $sql = "
        SELECT a.*, s.name_ar AS school_name
        FROM alerts a
        JOIN schools s ON a.school_id = s.id
        WHERE a.is_read = 0 AND a.is_resolved = 0
        ORDER BY a.created_at DESC LIMIT 5
    ";
    $latestAlerts = $db->query($sql)->fetchAll();
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// الخلاصة التنفيذية
// ═══════════════════════════════════════════════════════════

$summaryParts = [];
$summaryParts[] = "متوسط جودة المجموعة <strong>" . number_format($avgEQI, 1) . "</strong> نقطة";
if ($eqiTrendText) {
    $summaryParts[] = "(<span class=\"$eqiTrendClass\">$eqiTrendText</span>)";
}
$summaryParts[] = "<strong>" . number_format($totalSchools) . "</strong> مدرسة نشطة";
if ($totalStudents > 0) {
    $summaryParts[] = "بإجمالي <strong>" . number_format($totalStudents) . "</strong> طالب";
}
if (!empty($schoolsAtRisk)) {
    $summaryParts[] = "<strong class=\"text-danger\">" . count($schoolsAtRisk) . "</strong> مدرسة تستوجب التدخل";
} else {
    $summaryParts[] = "لا توجد مدارس في خطر";
}
$executiveSummary = implode(' — ', $summaryParts);
if ($selectedSchoolId > 0) {
    $executiveSummary .= " — (<strong>" . e($selectedSchoolName) . "</strong>)";
}
if ($selectedSemesterName) {
    $executiveSummary .= " — الفصل: " . e($selectedSemesterName);
}
?>

<!-- ════════════════════════════════════════════════════════
     تحسينات التصميم
     ════════════════════════════════════════════════════════ -->
<style>
    .strat-filter-bar { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px; }
    .strat-filter-bar .filter-group { flex:1; min-width:180px; }
    .strat-filter-bar .filter-group label { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; margin-bottom:6px; color:#475569; }
    .strat-filter-bar .filter-group label i { color:#1a5632; font-size:14px; }
    .strat-filter-bar .filter-group select { width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; font-size:14px; color:#1e293b; transition:border-color 0.2s, box-shadow 0.2s; cursor:pointer; }
    .strat-filter-bar .filter-group select:hover { border-color:#94a3b8; }
    .strat-filter-bar .filter-group select:focus { border-color:#1a5632; box-shadow:0 0 0 3px rgba(26,86,50,0.15); outline:none; }

    .strat-summary { font-size:15px; padding:16px 22px; margin-bottom:24px; border-radius:10px; border:1px solid #bae6fd; background:linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); color:#0c4a6e; }
    .strat-summary strong { color:#1e293b; }

    .strat-kpi { transition:transform 0.2s ease, box-shadow 0.2s ease; }
    .strat-kpi:hover { transform:translateY(-4px); box-shadow:0 8px 25px rgba(0,0,0,0.1); }
    .strat-kpi .stat-value { font-size:2.2rem; }

    .strat-section-title { font-size:16px; font-weight:700; color:#1e293b; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

    .strat-table-wrap { overflow-x:auto; max-height:480px; overflow-y:auto; border-radius:0 0 12px 12px; }
    .strat-table-wrap table { width:100%; border-collapse:separate; border-spacing:0; }
    .strat-table-wrap thead { position:sticky; top:0; z-index:10; }
    .strat-table-wrap thead th { background:#1a5632; color:#fff; font-weight:600; font-size:13px; padding:12px 16px; text-align:right; white-space:nowrap; }
    .strat-table-wrap thead th:first-child { border-radius:0; }
    .strat-table-wrap tbody tr { transition:background 0.15s; }
    .strat-table-wrap tbody tr:hover { filter:brightness(0.97); }
    .strat-table-wrap tbody td { padding:12px 16px; border-bottom:1px solid #e2e8f0; font-size:14px; }

    .strat-card-icon { display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:10px; font-size:20px; margin-bottom:10px; }

    .str-chart-card .card-body { height:300px; display:flex; align-items:center; justify-content:center; }
    .str-chart-card canvas { max-width:100%; max-height:100%; }

    .strat-risk-item { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; margin-bottom:8px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; transition:background 0.2s; }
    .strat-risk-item:hover { background:#fee2e2; }
    .strat-risk-item .risk-reason { color:#dc2626; font-size:13px; font-weight:600; }

    .strat-alert-item { padding:12px 16px; border-bottom:1px solid #e2e8f0; font-size:13px; transition:background 0.15s; }
    .strat-alert-item:hover { background:#f8fafc; }
    .strat-alert-item:last-child { border-bottom:none; }

    .strat-empty-state { text-align:center; padding:40px 20px; color:#94a3b8; }
    .strat-empty-state p { margin-top:8px; font-size:14px; }
</style>

<!-- ════════════════════════════════════════════════════════
     شريط الفلاتر
     ════════════════════════════════════════════════════════ -->
<div class="strat-filter-bar">
    <form id="filterForm" method="GET" style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> العام الدراسي</label>
            <select name="academic_year_id" onchange="document.getElementById('filterForm').submit();">
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= (int)$ay['id'] === $selectedAcademicYearId ? 'selected' : '' ?>>
                        <?= e($ay['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-book-open"></i> الفصل الدراسي</label>
            <select name="semester_id" onchange="document.getElementById('filterForm').submit();">
                <option value="0" <?= $selectedSemesterId === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                <?php foreach ($allSemesters as $sem): ?>
                    <option value="<?= $sem['id'] ?>" <?= (int)$sem['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                        <?= e($sem['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-school"></i> المدرسة</label>
            <select name="school_id" onchange="document.getElementById('filterForm').submit();">
                <option value="0" <?= $selectedSchoolId === 0 ? 'selected' : '' ?>>الكل</option>
                <?php foreach ($schools as $sch): ?>
                    <option value="<?= $sch['id'] ?>" <?= (int)$sch['id'] === $selectedSchoolId ? 'selected' : '' ?>>
                        <?= e($sch['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<!-- ════════════════════════════════════════════════════════
     الخلاصة التنفيذية
     ════════════════════════════════════════════════════════ -->
<div class="alert alert-info" style="font-size:15px;padding:14px 20px;margin-bottom:24px;border-radius:10px;">
    <?= $executiveSummary ?>
</div>

<!-- ════════════════════════════════════════════════════════
     بطاقات KPI
     ════════════════════════════════════════════════════════ -->
<div class="dashboard-stats" style="margin-bottom:24px;">
    <div class="dashboard-stat" style="border-top:3px solid var(--info);">
        <div class="stat-icon">🏫</div>
        <div class="stat-value" style="color:var(--info);"><?= number_format($totalSchools) ?></div>
        <div class="stat-label">المدارس النشطة</div>
    </div>
    <div class="dashboard-stat" style="border-top:3px solid var(--success);">
        <div class="stat-icon">👥</div>
        <div class="stat-value" style="color:var(--success);"><?= number_format($totalStudents) ?></div>
        <div class="stat-label">الطلاب النشطون</div>
    </div>
    <div class="dashboard-stat" style="border-top:3px solid var(--warning);">
        <div class="stat-icon">📊</div>
        <div class="stat-value" style="color:var(--warning);"><?= number_format($avgAttendance, 1) ?>%</div>
        <div class="stat-label">متوسط الحضور</div>
    </div>
    <div class="dashboard-stat" style="border-top:3px solid var(--primary);">
        <div class="stat-icon">⭐</div>
        <div class="stat-value" style="color:var(--primary);"><?= number_format($avgEQI, 1) ?></div>
        <div class="stat-label">
            متوسط EQI
            <?php if ($eqiTrendText): ?>
                <span class="<?= $eqiTrendClass ?>" style="font-size:13px;font-weight:700;display:block;margin-top:2px;"><?= $eqiTrendText ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     الرسوم البيانية
     ════════════════════════════════════════════════════════ -->
<div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));margin-bottom:24px;">
    <!-- الرسم الخطي لاتجاه EQI -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📈 اتجاه EQI عبر الفصول</h3>
        </div>
        <div class="card-body" style="height:300px;">
            <canvas id="eqiTrendChart"></canvas>
        </div>
    </div>
    <!-- الرسم الدائري لتوزيع المدارس -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🍩 توزيع المدارس حسب الأداء</h3>
        </div>
        <div class="card-body" style="height:300px;">
            <canvas id="eqiDistributionChart"></canvas>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     جدول أداء المدارس
     ════════════════════════════════════════════════════════ -->
<div class="card mb-6">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">📋 أداء المدارس حسب مؤشر جودة التعليم</h3>
        <a href="../analytics/compare.php" class="btn btn-sm btn-light">مقارنة مفصلة</a>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($schoolsData)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <p>لا توجد بيانات للفصل المحدد.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;max-height:460px;overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>EQI</th>
                            <th>المستوى</th>
                            <th>الاتجاه السنوي</th>
                            <th>المعدّل سياقياً</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        $adjustService = new ContextualAdjustmentService($db);
                        foreach ($schoolsData as $s):
                            $eqi = (float)$s['eqi_score'];
                            $rating = $eqi > 0 ? getEqiRating($eqi) : ['لا توجد بيانات', 'secondary', 'secondary'];

                            // الاتجاه السنوي
                            $prevEqiSchool = $yearlyTrends[(int)$s['id']] ?? null;
                            $trendText = '—';
                            $trendClass = 'trend-neutral';
                            if ($prevEqiSchool !== null && $prevEqiSchool > 0 && $eqi > 0) {
                                $diff = $eqi - $prevEqiSchool;
                                $pct = ($diff / $prevEqiSchool) * 100;
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

                            // التعديل السياقي (raw_eqi يحافظ على null إن لم توجد بيانات)
                            $adj = $adjustService->calculateAdjustedSQIWithDetails((int)$s['id'], $selectedAcademicYearId, $s['raw_eqi']);
                            $adjValue = $adj['adjusted_sqi'] ?? null;

                            // لون الصف حسب الأداء
                            if ($eqi > 0) {
                                $r = getEqiRating($eqi);
                                $rowBg = '';
                                if ($r[2] === 'success') $rowBg = 'background-color:#f0fdf4;';
                                elseif ($r[2] === 'primary') $rowBg = 'background-color:#eff6ff;';
                                elseif ($r[2] === 'warning') $rowBg = 'background-color:#fffbeb;';
                                else $rowBg = 'background-color:#fef2f2;';
                            }
                        ?>
                        <tr style="<?= $rowBg ?? '' ?>">
                            <td><strong><?= $rank++ ?></strong></td>
                            <td>
                                <a href="../analytics/school.php?school_id=<?= (int)$s['id'] ?>&semester_id=<?= $selectedSemesterId ?>" style="font-weight:600;text-decoration:none;color:var(--eqas-green);">
                                    <?= e($s['name_ar']) ?>
                                </a>
                                <div style="font-size:11px;color:#999;font-family:monospace;"><?= e($s['reference_code']) ?></div>
                            </td>
                            <td>
                                <?php if ($eqi > 0): ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="width:90px;height:8px;background:#e2e8f0;border-radius:4px;">
                                        <div style="width:<?= min(100, $eqi) ?>%;height:100%;background:<?= $rating[2] === 'success' ? 'var(--success)' : ($rating[2] === 'primary' ? 'var(--primary)' : ($rating[2] === 'warning' ? 'var(--warning)' : 'var(--danger)')) ?>;border-radius:4px;"></div>
                                    </div>
                                    <strong><?= number_format($eqi, 1) ?></strong>
                                </div>
                                <?php else: ?>
                                <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($eqi > 0): ?>
                                <span class="badge badge-<?= $rating[1] ?>"><?= $rating[0] ?></span>
                                <?php else: ?>
                                <span class="badge badge-secondary">لا توجد</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span style="font-weight:bold;font-size:13px;" class="<?= $trendClass ?>"><?= $trendText ?></span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($adjValue !== null && $eqi > 0): ?>
                                <div><strong style="font-size:14px;color:#333;"><?= number_format($adjValue, 1) ?></strong></div>
                                <div style="font-size:10px;color:var(--text-muted);">
                                    <?= $adj['has_context'] ? 'تحد: ' . number_format($adj['challenge_index'], 0) : 'بدون سياق' ?>
                                </div>
                                <?php else: ?>
                                <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     المدارس في خطر + آخر التنبيهات
     ════════════════════════════════════════════════════════ -->
<div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));margin-bottom:24px;">
    <!-- المدارس في خطر -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🚨 المدارس المستوجبة للتدخل</h3>
        </div>
        <div class="card-body">
            <?php if (empty($schoolsAtRisk)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:10px;">✅</div>
                    <p>لا توجد مدارس في خطر حالياً.</p>
                </div>
            <?php else: ?>
                <?php foreach ($schoolsAtRisk as $risk): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;margin-bottom:8px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
                    <div>
                        <strong><?= e($risk['name_ar']) ?></strong>
                        <div style="font-size:11px;color:#999;font-family:monospace;"><?= e($risk['reference_code']) ?></div>
                    </div>
                    <span style="color:#dc2626;font-size:13px;font-weight:600;"><?= e($risk['risk_reason']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- آخر التنبيهات -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card-title">🔔 آخر التنبيهات</h3>
            <a href="../alerts/index.php" class="btn btn-sm btn-light">عرض الكل</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($latestAlerts)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:10px;">🔕</div>
                    <p>لا توجد تنبيهات جديدة.</p>
                </div>
            <?php else: ?>
                <div style="max-height:300px;overflow-y:auto;">
                    <?php foreach ($latestAlerts as $alert): ?>
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border-light);font-size:13px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <span style="font-weight:600;color:var(--eqas-green);"><?= e($alert['school_name']) ?></span>
                                <span style="margin:0 6px;color:#999;">·</span>
                                <span><?= e($alert['title_ar']) ?></span>
                            </div>
                            <span class="badge badge-<?= $alert['severity'] === 'critical' ? 'danger' : ($alert['severity'] === 'danger' ? 'warning' : ($alert['severity'] === 'warning' ? 'primary' : 'secondary')) ?>">
                                <?= e($alert['severity']) ?>
                            </span>
                        </div>
                        <div style="color:var(--text-secondary);font-size:12px;margin-top:4px;display:flex;justify-content:space-between;">
                            <span><?= e(mb_substr($alert['message_ar'], 0, 100)) ?><?= mb_strlen($alert['message_ar']) > 100 ? '...' : '' ?></span>
                            <span style="font-size:11px;"><?= formatDateAr($alert['created_at'], 'short') ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     JavaScript للرسوم البيانية
     ════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ─── الرسم الخطي لاتجاه EQI ─────────────────────────
    const lineCtx = document.getElementById('eqiTrendChart');
    if (lineCtx) {
        const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
        const schoolData = <?= json_encode($chartSchoolData) ?>;
        const overallData = <?= json_encode($chartOverallData) ?>;

        const datasets = [];

        <?php if ($selectedSchoolId > 0): ?>
        // خط المدرسة المختارة
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
        // خط متوسط المجموعة (متقطع)
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
            label: 'متوسط EQI',
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
            data: { labels: labels, datasets: datasets },
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

    // ─── الرسم الدائري لتوزيع المدارس ────────────────────
    const pieCtx = document.getElementById('eqiDistributionChart');
    if (pieCtx) {
        const hasData = <?= array_sum($bucketCounts) > 0 ? 'true' : 'false' ?>;
        if (!hasData) {
            pieCtx.parentElement.innerHTML = '<div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><div style="font-size:48px;margin-bottom:12px;">📊</div><p>لا توجد بيانات كافية للرسم البياني.</p></div>';
        } else {
            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($bucketLabels, JSON_UNESCAPED_UNICODE) ?>,
                    datasets: [{
                        data: <?= json_encode($bucketCounts) ?>,
                        backgroundColor: ['#28a745', '#1a5632', '#ffc107', '#dc3545'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { family: 'Tajawal', size: 13 }, padding: 16, usePointStyle: true }
                        }
                    }
                }
            });
        }
    }
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
