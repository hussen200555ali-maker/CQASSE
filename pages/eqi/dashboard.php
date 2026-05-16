<?php
/**
 * ============================================================
 * EQAS - مركز قيادة مؤشر جودة التعليم (EQI Command Center)
 * نظام التقييم الشامل - EQI Leadership Dashboard
 * ============================================================
 */

$pageTitle = 'مركز قيادة EQI';
$breadcrumb = [
    'مؤشر جودة التعليم' => 'index.php',
    'مركز القيادة' => null,
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

requireRole('group_admin');

$eqiCalc = new EQICalculator($db);
$contextualService = new ContextualAdjustmentService($db);

// ═══════════════════════════════════════════════════════════
// معالجة الفلاتر
// ═══════════════════════════════════════════════════════════

$selectedYear = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemester = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

if (!isset($_GET['academic_year_id'])) {
    $selectedYear = (int)currentAcademicYearId();
}
if (!isset($_GET['semester_id']) && $selectedYear > 0) {
    $selectedSemester = (int)currentSemesterId($selectedYear);
}
// جميع السنوات: إجبار semester على 0 وإخفاء القائمة
$allYearsMode = ($selectedYear === 0);
if ($allYearsMode) {
    $selectedSemester = 0;
}

// ─── قوائم ──────────────────────────────────────────────
$years = [];
try {
    $years = $db->query("SELECT id, name AS name_ar FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {}

$semesters = [];
if ($selectedYear > 0 && !$allYearsMode) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// ─── بيانات ──────────────────────────────────────────────
$schoolsList = [];
try {
    $schoolsList = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// KPI - إحصائيات سريعة
// ═══════════════════════════════════════════════════════════

$avgEqi = 0;
$bestSchool = null;
$worstSchool = null;
$atRiskCount = 0;
$allSchoolResults = [];

try {
    if ($selectedSemester > 0) {
        $sql = "SELECT eq.*, s.name_ar AS school_name, s.reference_code AS school_code
                FROM equi_results eq
                JOIN schools s ON s.id = eq.school_id
                WHERE eq.academic_year_id = ? AND eq.semester_id = ? AND eq.is_active = 1
                ORDER BY eq.overall_eqi DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedYear, $selectedSemester]);
    } elseif (!$allYearsMode) {
        $sql = "SELECT AVG(eq.overall_eqi) AS avg_eqi, s.id, s.name_ar, s.reference_code
                FROM equi_results eq
                JOIN schools s ON s.id = eq.school_id
                WHERE eq.academic_year_id = ? AND eq.is_active = 1
                GROUP BY s.id, s.name_ar, s.reference_code
                ORDER BY avg_eqi DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedYear]);
    } else {
        $sql = "SELECT AVG(eq.overall_eqi) AS avg_eqi, s.id, s.name_ar, s.reference_code
                FROM equi_results eq
                JOIN schools s ON s.id = eq.school_id
                WHERE eq.is_active = 1
                GROUP BY s.id, s.name_ar, s.reference_code
                ORDER BY avg_eqi DESC";
        $stmt = $db->query($sql);
    }
    $allSchoolResults = $stmt->fetchAll();

    $scores = array_filter(array_column($allSchoolResults, $selectedSemester > 0 ? 'overall_eqi' : 'avg_eqi'), fn($v) => $v > 0);
    if (!empty($scores)) {
        $avgEqi = round(array_sum($scores) / count($scores), 1);
        $atRiskCount = count(array_filter($scores, fn($v) => $v < 50));

        $bestIdx = array_key_first($allSchoolResults);
        $worstIdx = array_key_last($allSchoolResults);
        $bestSchool = $allSchoolResults[$bestIdx] ?? null;
        $worstSchool = $allSchoolResults[$worstIdx] ?? null;
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// اتجاه EQI السنوي (للاتجاه في البطاقة)
// ═══════════════════════════════════════════════════════════

$prevAvgEqi = null;
$eqiTrendText = '';
$eqiTrendClass = '';

try {
    if ($allYearsMode) {
        // جميع السنوات: لا يوجد اتجاه
    } elseif ($selectedSemester > 0) {
        $stmt = $db->prepare("SELECT name_ar, start_date FROM semesters WHERE id = ?");
        $stmt->execute([$selectedSemester]);
        $semInfo = $stmt->fetch();

        if ($semInfo) {
            $stmt = $db->prepare("SELECT AVG(eq.overall_eqi)
                FROM equi_results eq
                JOIN semesters s ON eq.semester_id = s.id
                WHERE s.name_ar = ? AND s.start_date < ? AND s.is_active = 1 AND eq.is_active = 1");
            $stmt->execute([$semInfo['name_ar'], $semInfo['start_date']]);
            $prevAvgEqi = (float)($stmt->fetchColumn() ?: 0);
        }
    } elseif ($selectedYear > 0) {
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$selectedYear]);
        $prevYearId = $stmt->fetchColumn();

        if ($prevYearId) {
            $stmt = $db->prepare("SELECT AVG(overall_eqi) FROM equi_results WHERE academic_year_id = ? AND is_active = 1");
            $stmt->execute([$prevYearId]);
            $prevAvgEqi = (float)($stmt->fetchColumn() ?: 0);
        }
    }

    if ($prevAvgEqi > 0 && $avgEqi > 0) {
        $diff = $avgEqi - $prevAvgEqi;
        $pct = ($diff / $prevAvgEqi) * 100;
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
// تاريخ EQI لآخر 6 فصول (للمخطط الخطي)
// ═══════════════════════════════════════════════════════════

$chartLabels = [];
$chartEqiAvg = [];

try {
    if (!$allYearsMode && $selectedYear > 0) {
        $stmt = $db->prepare("SELECT id, name_ar, academic_year_id FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $db->query("SELECT id, name_ar, academic_year_id FROM semesters WHERE is_active = 1 ORDER BY start_date DESC LIMIT 6");
    }
    $histSems = $stmt->fetchAll();
    $histSems = array_reverse($histSems);

    foreach ($histSems as $hs) {
        $chartLabels[] = $hs['name_ar'];
        $sqi = $db->prepare("SELECT AVG(overall_eqi) FROM equi_results WHERE semester_id = ? AND is_active = 1");
        $sqi->execute([(int)$hs['id']]);
        $chartEqiAvg[] = round((float)($sqi->fetchColumn() ?: 0), 1);
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// أداء المدارس مع التعديل السياقي والاتجاه السنوي
// ═══════════════════════════════════════════════════════════

$schoolPerformance = [];
$atRiskSchools = [];
$yearlyTrends = [];

try {
    if ($selectedSemester > 0) {
        $sql = "SELECT s.id, s.name_ar, s.reference_code,
                       eq.overall_eqi AS raw_eqi,
                       eq.academic_score, eq.teaching_score, eq.climate_score,
                       eq.behavior_score, eq.skills_score, eq.attendance_score
                FROM schools s
                LEFT JOIN equi_results eq ON s.id = eq.school_id AND eq.semester_id = ? AND eq.academic_year_id = ?
                WHERE s.is_active = 1
                ORDER BY eq.overall_eqi DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedSemester, $selectedYear]);
    } elseif (!$allYearsMode) {
        $sql = "SELECT s.id, s.name_ar, s.reference_code,
                       AVG(eq.overall_eqi) AS raw_eqi,
                       AVG(eq.academic_score) AS academic_score,
                       AVG(eq.teaching_score) AS teaching_score,
                       AVG(eq.climate_score) AS climate_score,
                       AVG(eq.behavior_score) AS behavior_score,
                       AVG(eq.skills_score) AS skills_score,
                       AVG(eq.attendance_score) AS attendance_score
                FROM schools s
                LEFT JOIN equi_results eq ON s.id = eq.school_id AND eq.academic_year_id = ?
                WHERE s.is_active = 1
                GROUP BY s.id, s.name_ar, s.reference_code
                ORDER BY raw_eqi DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$selectedYear]);
    } else {
        $sql = "SELECT s.id, s.name_ar, s.reference_code,
                       AVG(eq.overall_eqi) AS raw_eqi,
                       AVG(eq.academic_score) AS academic_score,
                       AVG(eq.teaching_score) AS teaching_score,
                       AVG(eq.climate_score) AS climate_score,
                       AVG(eq.behavior_score) AS behavior_score,
                       AVG(eq.skills_score) AS skills_score,
                       AVG(eq.attendance_score) AS attendance_score
                FROM schools s
                LEFT JOIN equi_results eq ON s.id = eq.school_id
                WHERE s.is_active = 1
                GROUP BY s.id, s.name_ar, s.reference_code
                ORDER BY raw_eqi DESC";
        $stmt = $db->query($sql);
    }
    $schoolPerformance = $stmt->fetchAll();

    // اتجاه سنوي لكل مدرسة
    if (!empty($schoolPerformance) && $selectedYear > 0 && !$allYearsMode) {
        $stmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([$selectedYear]);
        $prevYearId = $stmt->fetchColumn();

        if ($prevYearId) {
            if ($selectedSemester > 0) {
                $stmt = $db->prepare("SELECT s2.id FROM semesters s2 JOIN semesters s1 ON s1.id = ? WHERE s2.academic_year_id = ? AND s2.name_ar = s1.name_ar AND s2.is_active = 1 LIMIT 1");
                $stmt->execute([$selectedSemester, $prevYearId]);
                $prevSemId = $stmt->fetchColumn();

                if ($prevSemId) {
                    $stmt = $db->prepare("SELECT school_id, overall_eqi FROM equi_results WHERE semester_id = ? AND academic_year_id = ? AND is_active = 1");
                    $stmt->execute([$prevSemId, $prevYearId]);
                    while ($row = $stmt->fetch()) {
                        $yearlyTrends[(int)$row['school_id']] = (float)$row['overall_eqi'];
                    }
                }
            } else {
                $stmt = $db->prepare("SELECT school_id, AVG(overall_eqi) AS avg_eqi FROM equi_results WHERE academic_year_id = ? AND is_active = 1 GROUP BY school_id");
                $stmt->execute([$prevYearId]);
                while ($row = $stmt->fetch()) {
                    $yearlyTrends[(int)$row['school_id']] = (float)$row['avg_eqi'];
                }
            }
        }
    }

    // تحديد المدارس في الخطر
    foreach ($schoolPerformance as $sp) {
        $eqi = (float)($sp['raw_eqi'] ?? 0);
        if ($eqi > 0 && $eqi < 50) {
            $sp['risk_reason'] = 'EQI أقل من 50';
            $atRiskSchools[] = $sp;
        }
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// آخر التنبيهات
// ═══════════════════════════════════════════════════════════

$latestAlerts = [];
try {
    $stmt = $db->query("SELECT a.*, s.name_ar AS school_name FROM alerts a JOIN schools s ON a.school_id = s.id WHERE a.is_read = 0 AND a.is_resolved = 0 ORDER BY a.created_at DESC LIMIT 5");
    $latestAlerts = $stmt->fetchAll();
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// الخلاصة التنفيذية
// ═══════════════════════════════════════════════════════════

$summaryParts = [];
$summaryParts[] = "متوسط مؤشر الجودة <strong>" . number_format($avgEqi, 1) . "</strong>";
if ($eqiTrendText) {
    $summaryParts[] = "(<span class=\"$eqiTrendClass\">$eqiTrendText</span>)";
}
$summaryParts[] = "<strong>" . count($schoolsList) . "</strong> مدرسة";
if (!empty($allSchoolResults)) {
    $summaryParts[] = "<strong>" . count($allSchoolResults) . "</strong> مدرسة ذات نتائج";
}
if (!empty($atRiskSchools)) {
    $summaryParts[] = "<strong class=\"text-danger\">" . count($atRiskSchools) . "</strong> مدرسة في الخطر";
} else {
    $summaryParts[] = "لا توجد مدارس في الخطر";
}
$executiveSummary = implode(' — ', $summaryParts);

// اسم الفصل المختار
$selectedSemName = 'جميع الفصول';
if ($selectedSemester > 0) {
    foreach ($semesters as $s) {
        if ((int)$s['id'] === $selectedSemester) { $selectedSemName = $s['name_ar']; break; }
    }
}
$selectedYearName = 'جميع السنوات';
if (!$allYearsMode) {
    foreach ($years as $y) {
        if ((int)$y['id'] === $selectedYear) { $selectedYearName = $y['name_ar']; break; }
    }
}
$executiveSummary .= " — " . e($selectedYearName) . " / " . e($selectedSemName);
?>

<style>
    .eqi-hero { background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%); border-radius: 16px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; color: #fff; position: relative; overflow: hidden; }
    .eqi-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
    .eqi-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
    .filter-card { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 1.5rem; border: 1px solid #dee2e6; }
    .filter-card label { font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.35rem; display: block; }
    .filter-card select { border: 1px solid #dee2e6; border-radius: 8px; padding: 0.5rem 1rem; min-width: 200px; font-size: 0.9rem; color: #1a1a2e; background: #fff; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; }
    .filter-card select:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.12); outline: none; }
    .chart-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden; transition: box-shadow 0.2s; }
    .chart-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
    .chart-card-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; justify-content: space-between; }
    .chart-card-header h2, .chart-card-header h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
    .chart-card-body { padding: 1.5rem; }
    .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .kpi-item { border-radius: 12px; padding: 1.25rem; position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .kpi-item:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
    .kpi-item .kpi-ico { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 0.75rem; }
    .kpi-item .kpi-num { font-size: 1.8rem; font-weight: 800; line-height: 1.2; }
    .kpi-item .kpi-lbl { font-size: 0.8rem; font-weight: 500; opacity: 0.85; margin-top: 0.2rem; }
    .kpi-item .kpi-sub { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; }
    .kpi-green { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); color: #1b5e20; }
    .kpi-green .kpi-ico { background: rgba(26,86,50,0.15); }
    .kpi-gold { background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%); color: #7c5a00; }
    .kpi-gold .kpi-ico { background: rgba(200,169,81,0.2); }
    .kpi-teal { background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%); color: #004d40; }
    .kpi-teal .kpi-ico { background: rgba(0,150,136,0.15); }
    .kpi-red { background: linear-gradient(135deg, #fbe9e7 0%, #ffccbc 100%); color: #b71c1c; }
    .kpi-red .kpi-ico { background: rgba(183,28,28,0.12); }
    .table-wrap { overflow-x: auto; }
    .school-table { width: 100%; border-collapse: collapse; }
    .school-table th { background: #f8f9fa; font-weight: 600; text-align: right; padding: 0.75rem 0.85rem; font-size: 0.77rem; color: #475569; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .school-table td { padding: 0.65rem 0.85rem; border-bottom: 1px solid #f1f5f9; font-size: 0.83rem; vertical-align: middle; }
    .school-table tbody tr { transition: background 0.15s; cursor: pointer; }
    .school-table tbody tr:hover { background: #f8fafc; }
    .school-table tbody tr.row-excellent { border-right: 3px solid #28a745; }
    .school-table tbody tr.row-good { border-right: 3px solid #17a2b8; }
    .school-table tbody tr.row-fair { border-right: 3px solid #ffc107; }
    .school-table tbody tr.row-poor { border-right: 3px solid #dc3545; background: #fff5f5; }
    .trend-up { color: #15803d; font-weight: 700; }
    .trend-down { color: #dc2626; font-weight: 700; }
    .trend-neutral { color: #6b7280; font-weight: 600; }
    .badge-rating { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; }
    .badge-excellent { background: #dcfce7; color: #166534; }
    .badge-very-good { background: #e0f2fe; color: #075985; }
    .badge-fair { background: #fef9c3; color: #854d0e; }
    .badge-poor { background: #fee2e2; color: #991b1b; }
    .empty-box { text-align: center; padding: 3rem 1.5rem; color: #6c757d; }
    .empty-box .empty-ico { font-size: 3rem; margin-bottom: 0.75rem; }
    .summary-bar { font-size: 0.9rem; padding: 0.75rem 1.25rem; margin-bottom: 1.5rem; border-radius: 8px; border: 1px solid #bae6fd; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); color: #0c4a6e; }
    .summary-bar strong { color: #1e293b; }
    .alert-item { padding: 0.6rem 0.8rem; border-radius: 8px; margin-bottom: 0.4rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; }
    .alert-critical { background: #fef2f2; border-right: 3px solid #dc2626; color: #991b1b; }
    .alert-warning { background: #fffbeb; border-right: 3px solid #f59e0b; color: #92400e; }
    .alert-info { background: #f0f9ff; border-right: 3px solid #0ea5e9; color: #075985; }
    .chart-container { position: relative; height: 300px; }
    .chart-container-sm { position: relative; height: 250px; }
    .toggle-wrap { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: #6c757d; }
    .toggle-wrap input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #1a5632; }
    .action-btn { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.7rem; border-radius: 6px; font-size: 0.77rem; font-weight: 600; text-decoration: none; transition: all 0.15s; border: none; cursor: pointer; }
    .action-btn-primary { background: #1a5632; color: #fff; }
    .action-btn-primary:hover { background: #0f3a20; color: #fff; }
    .action-btn-outline { background: transparent; border: 1px solid #cbd5e1; color: #475569; }
    .action-btn-outline:hover { background: #f1f5f9; border-color: #94a3b8; }
    .action-btn-sm { font-size: 0.72rem; padding: 0.2rem 0.5rem; }
    .section-title { font-size: 1rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    @media (max-width:768px) {
        .kpi-row { grid-template-columns: repeat(2,1fr); }
        .filter-card select { min-width: 100%; }
    }
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="eqi-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>مركز قيادة مؤشر جودة التعليم</h1>
                <div class="hero-sub">لوحة قيادة استراتيجية — نظرة شاملة على أداء المدارس عبر مؤشر جودة التعليم (EQI)</div>
            </div>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="calculate.php" class="btn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;font-size:0.85rem;padding:0.4rem 1rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">🔄 حساب المؤشر</a>
                <a href="compare.php" class="btn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;font-size:0.85rem;padding:0.4rem 1rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">📊 مقارنة</a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <label>السنة الدراسية</label>
                <select name="academic_year_id" onchange="this.form.submit()">
                    <option value="0" <?= $selectedYear === 0 ? 'selected' : '' ?>>جميع السنوات</option>
                    <?php foreach ($years as $y): ?>
                    <option value="<?= $y['id'] ?>" <?= $selectedYear == $y['id'] ? 'selected' : '' ?>><?= e($y['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$allYearsMode): ?>
            <div>
                <label>الفصل الدراسي</label>
                <select name="semester_id" onchange="this.form.submit()">
                    <option value="0" <?= $selectedSemester === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="font-size:0.8rem;color:#6c757d;padding-top:0.5rem;">↵ التحديث التلقائي</div>
        </form>
    </div>

    <!-- Executive Summary -->
    <div class="summary-bar"><?= $executiveSummary ?></div>

    <?php if (!empty($allSchoolResults)): ?>

    <!-- KPI Row -->
    <div class="kpi-row">
        <div class="kpi-item kpi-green">
            <div class="kpi-ico">📊</div>
            <div class="kpi-num"><?= number_format($avgEqi, 1) ?></div>
            <div class="kpi-lbl">متوسط مؤشر الجودة</div>
            <div class="kpi-sub">
                <?php if ($eqiTrendText): ?>
                <span class="<?= $eqiTrendClass ?>"><?= $eqiTrendText ?> عن العام السابق</span>
                <?php else: ?>
                جميع المدارس
                <?php endif; ?>
            </div>
        </div>
        <div class="kpi-item kpi-gold">
            <div class="kpi-ico">🏆</div>
            <div class="kpi-num"><?= e($bestSchool['school_name'] ?? '—') ?></div>
            <div class="kpi-lbl">أفضل مدرسة</div>
            <div class="kpi-sub"><?= number_format((float)($bestSchool[$selectedSemester > 0 ? 'overall_eqi' : 'avg_eqi'] ?? 0), 1) ?> نقطة</div>
        </div>
        <div class="kpi-item kpi-red">
            <div class="kpi-ico">⚠️</div>
            <div class="kpi-num"><?= $atRiskCount ?></div>
            <div class="kpi-lbl">مدارس في الخطر</div>
            <div class="kpi-sub">EQI أقل من 50 نقطة</div>
        </div>
        <div class="kpi-item kpi-teal">
            <div class="kpi-ico">🏫</div>
            <div class="kpi-num"><?= count($allSchoolResults) ?></div>
            <div class="kpi-lbl">مدارس ذات نتائج</div>
            <div class="kpi-sub">من أصل <?= count($schoolsList) ?> مدرسة</div>
        </div>
    </div>

    <!-- Charts Row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:1.5rem;margin-bottom:1.5rem;">
        <!-- Bar Chart: EQI per School -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📊 أداء المدارس</h3>
                <div class="toggle-wrap">
                    <input type="checkbox" id="toggleAdjusted" onchange="toggleAdjustedEqi()">
                    <label for="toggleAdjusted" style="margin:0;font-size:0.77rem;">إظهار المعدّل سياقياً</label>
                </div>
            </div>
            <div class="chart-card-body">
                <div class="chart-container">
                    <canvas id="schoolBarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Line Chart: Trend -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📈 اتجاه EQI عبر الفصول</h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container">
                    <canvas id="trendLineChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- School Table -->
    <div class="chart-card">
        <div class="chart-card-header">
            <h3>🏫 أداء المدارس</h3>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button onclick="exportCSV()" class="action-btn action-btn-outline">📥 تصدير CSV</button>
            </div>
        </div>
        <div class="chart-card-body" style="padding:0;">
            <div class="table-wrap">
                <table class="school-table" id="schoolTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>الكود</th>
                            <th>الخام (Raw)</th>
                            <th>المعدّل سياقياً</th>
                            <th>التصنيف</th>
                            <th>الاتجاه السنوي</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; ?>
                        <?php foreach ($schoolPerformance as $sp): ?>
                        <?php
                            $rawEqi = (float)($sp['raw_eqi'] ?? 0);
                            $adjustedData = $contextualService->calculateAdjustedSQIWithDetails((int)$sp['id'], $selectedYear, $rawEqi > 0 ? $rawEqi : null);
                            $adjustedEqi = $adjustedData ? $adjustedData['adjusted_sqi'] : $rawEqi;
                            $rating = $eqiCalc->getEQIRating($rawEqi);
                            $rowClass = $rating['class'] === 'excellent' ? 'row-excellent' : ($rating['class'] === 'very-good' ? 'row-good' : ($rating['class'] === 'fair' ? 'row-fair' : 'row-poor'));

                            // الاتجاه السنوي
                            $prevEqi = $yearlyTrends[(int)$sp['id']] ?? null;
                            $trendText = '—';
                            $trendClass = '';
                            if ($prevEqi !== null && $prevEqi > 0 && $rawEqi > 0) {
                                $diff = $rawEqi - $prevEqi;
                                $pct = ($diff / $prevEqi) * 100;
                                if ($pct > 1) { $trendText = '▲ +' . number_format($pct, 1) . '%'; $trendClass = 'trend-up'; }
                                elseif ($pct < -1) { $trendText = '▼ ' . number_format(abs($pct), 1) . '%'; $trendClass = 'trend-down'; }
                                else { $trendText = '→ مستقر'; $trendClass = 'trend-neutral'; }
                            }
                        ?>
                        <?php $viewLink = 'view.php?school_id=' . (int)$sp['id'] . '&year_id=' . ($allYearsMode ? 0 : $selectedYear) . '&semester_id=' . ($allYearsMode ? 0 : $selectedSemester); ?>
                        <tr class="<?= $rowClass ?>" onclick="window.location='<?= $viewLink ?>'">
                            <td><?= $i++ ?></td>
                            <td><strong><?= e($sp['name_ar'] ?? '') ?></strong></td>
                            <td style="color:#6c757d;font-size:0.75rem;"><?= e($sp['reference_code'] ?? '') ?></td>
                            <td><strong><?= $rawEqi > 0 ? number_format($rawEqi, 1) : '—' ?></strong></td>
                            <td>
                                <?php if ($rawEqi > 0): ?>
                                <strong><?= number_format($adjustedEqi, 1) ?></strong>
                                <?php if ($adjustedData && $adjustedData['has_context']): ?>
                                <span style="font-size:0.7rem;color:#6c757d;">(تحدّي <?= $adjustedData['challenge_index'] ?>)</span>
                                <?php endif; ?>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rawEqi > 0): ?>
                                <span class="badge-rating badge-<?= $rating['class'] ?>"><?= $rating['text'] ?></span>
                                <?php else: ?>
                                <span style="color:#94a3b8;font-size:0.78rem;">لا توجد بيانات</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= $trendClass ?>"><?= $trendText ?></span>
                            </td>
                            <td>
                                <a href="view.php?school_id=<?= (int)$sp['id'] ?>&year_id=<?= $allYearsMode ? 0 : $selectedYear ?>&semester_id=<?= $allYearsMode ? 0 : $selectedSemester ?>" class="action-btn action-btn-primary action-btn-sm" onclick="event.stopPropagation()">التفاصيل</a>
                                <a href="calculate.php?school_id=<?= (int)$sp['id'] ?>&year_id=<?= $allYearsMode ? 0 : $selectedYear ?>&semester_id=<?= $allYearsMode ? 0 : $selectedSemester ?>" class="action-btn action-btn-outline action-btn-sm" onclick="event.stopPropagation()">حساب</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- At-Risk Schools + Alerts -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

        <!-- At-Risk Schools -->
        <div class="chart-card">
            <div class="chart-card-header"><h3>⚠️ المدارس في الخطر</h3></div>
            <div class="chart-card-body">
                <?php if (!empty($atRiskSchools)): ?>
                    <?php foreach ($atRiskSchools as $ars): ?>
                    <?php $riskRating = $eqiCalc->getEQIRating((float)($ars['raw_eqi'] ?? 0)); ?>
                    <div class="alert-item alert-critical">
                        <span style="font-weight:700;"><?= e($ars['name_ar'] ?? '') ?></span>
                        <span style="font-size:0.75rem;">— EQI: <?= number_format((float)($ars['raw_eqi'] ?? 0), 1) ?></span>
                        <span style="font-size:0.7rem;color:#6c757d;">(<?= $ars['risk_reason'] ?? '' ?>)</span>
                        <a href="view.php?school_id=<?= (int)$ars['id'] ?>&year_id=<?= $allYearsMode ? 0 : $selectedYear ?>&semester_id=<?= $allYearsMode ? 0 : $selectedSemester ?>" style="margin-right:auto;font-size:0.72rem;color:#1a5632;text-decoration:none;">تفحص ←</a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-box" style="padding:1.5rem;">
                        <div style="font-size:2rem;margin-bottom:0.5rem;">✅</div>
                        <div style="font-size:0.85rem;">لا توجد مدارس في الخطر — جميع المدارس تحقق EQI >= 50</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Alerts -->
        <div class="chart-card">
            <div class="chart-card-header"><h3>🔔 آخر التنبيهات</h3></div>
            <div class="chart-card-body">
                <?php if (!empty($latestAlerts)): ?>
                    <?php foreach ($latestAlerts as $alert): ?>
                    <?php $alertClass = $alert['severity'] === 'critical' ? 'alert-critical' : ($alert['severity'] === 'warning' ? 'alert-warning' : 'alert-info'); ?>
                    <div class="alert-item <?= $alertClass ?>">
                        <span style="font-weight:600;font-size:0.78rem;"><?= e($alert['school_name'] ?? '') ?></span>
                        <span style="font-size:0.75rem;color:#6c757d;">— <?= e(mb_substr($alert['title_ar'] ?? '', 0, 60)) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-box" style="padding:1.5rem;">
                        <div style="font-size:2rem;margin-bottom:0.5rem;">🔔</div>
                        <div style="font-size:0.85rem;">لا توجد تنبيهات غير مقروءة</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- No Data -->
    <div class="chart-card">
        <div class="chart-card-body">
            <div class="empty-box">
                <div class="empty-ico">📊</div>
                <h3>لا توجد بيانات EQI</h3>
                <p style="color:#6c757d;"><?= $allYearsMode ? 'لم يتم حساب مؤشر جودة التعليم لأي مدرسة بعد.' : 'لم يتم حساب مؤشر جودة التعليم لأي مدرسة في هذا الفصل الدراسي.' ?></p>
                <div style="margin-top:1rem;">
                    <a href="calculate.php" class="action-btn action-btn-primary">حساب المؤشر الآن</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// ═══════════════════════════════════════════════════════════════════
// المخططات
// ═══════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    initBarChart();
    initLineChart();
});

<?php
// بناء بيانات المخططات
$barLabels = [];
$barRawData = [];
$barAdjData = [];
$barColors = [];

foreach ($schoolPerformance as $sp) {
    $rawEqi = (float)($sp['raw_eqi'] ?? 0);
    $adjustedData = $contextualService->calculateAdjustedSQIWithDetails((int)$sp['id'], $selectedYear, $rawEqi > 0 ? $rawEqi : null);
    $adjustedEqi = $adjustedData ? $adjustedData['adjusted_sqi'] : $rawEqi;
    $rating = $eqiCalc->getEQIRating($rawEqi);

    $barLabels[] = $sp['name_ar'] ?? 'مدرسة';
    $barRawData[] = $rawEqi > 0 ? $rawEqi : null;
    $barAdjData[] = $rawEqi > 0 ? $adjustedEqi : null;
    $barColors[] = $rating['color'];
}
$barLabelsJson = json_encode($barLabels);
$barRawDataJson = json_encode($barRawData);
$barAdjDataJson = json_encode($barAdjData);
$barColorsJson = json_encode($barColors);
$chartLabelsJson = json_encode($chartLabels);
$chartEqiAvgJson = json_encode($chartEqiAvg);
?>

function initBarChart() {
    var ctx = document.getElementById('schoolBarChart').getContext('2d');
    window.schoolChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= $barLabelsJson ?>,
            datasets: [{
                label: 'EQI الخام',
                data: <?= $barRawDataJson ?>,
                backgroundColor: <?= $barColorsJson ?>,
                borderRadius: 4,
                barPercentage: 0.65,
                hidden: false,
            }, {
                label: 'EQI المعدّل',
                data: <?= $barAdjDataJson ?>,
                backgroundColor: 'rgba(26, 86, 50, 0.5)',
                borderColor: '#1a5632',
                borderWidth: 1,
                borderRadius: 4,
                barPercentage: 0.65,
                hidden: true,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.x !== null ? context.parsed.x.toFixed(1) + ' نقطة' : 'لا توجد بيانات';
                        }
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, max: 100, title: { display: true, text: 'EQI (0-100)' } },
                y: { ticks: { font: { size: 10 } } }
            }
        }
    });
}

function initLineChart() {
    var ctx = document.getElementById('trendLineChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                label: 'متوسط EQI',
                data: <?= $chartEqiAvgJson ?>,
                borderColor: '#1a5632',
                backgroundColor: 'rgba(26, 86, 50, 0.08)',
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#1a5632',
                pointRadius: 4,
                pointHoverRadius: 6,
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'معدل EQI: ' + context.parsed.y.toFixed(1);
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'EQI' } }
            }
        }
    });
}

// ═══════════════════════════════════════════════════════════════════
// تبديل EQI الخام / المعدّل
// ═══════════════════════════════════════════════════════════════════

function toggleAdjustedEqi() {
    if (!window.schoolChart) return;
    var checked = document.getElementById('toggleAdjusted').checked;
    window.schoolChart.data.datasets[0].hidden = checked;
    window.schoolChart.data.datasets[1].hidden = !checked;
    window.schoolChart.update();
}

// ═══════════════════════════════════════════════════════════════════
// تصدير CSV
// ═══════════════════════════════════════════════════════════════════

function exportCSV() {
    var table = document.getElementById('schoolTable');
    if (!table) return;

    var rows = [];
    var headers = [];
    var ths = table.querySelectorAll('thead th');
    for (var i = 0; i < ths.length - 1; i++) {
        headers.push(ths[i].textContent.trim());
    }
    rows.push(headers.join(','));

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var trs = tbody.querySelectorAll('tr');
    for (var i = 0; i < trs.length; i++) {
        var tds = trs[i].querySelectorAll('td');
        var row = [];
        for (var j = 0; j < tds.length - 1; j++) {
            var text = tds[j].textContent.trim().replace(/,/g, '');
            row.push(text);
        }
        rows.push(row.join(','));
    }

    var csvContent = '\uFEFF' + rows.join('\n');
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'eqi_school_comparison_<?= $allYearsMode ? 'all' : $selectedYear ?>_<?= $allYearsMode ? 'all' : $selectedSemester ?>.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
