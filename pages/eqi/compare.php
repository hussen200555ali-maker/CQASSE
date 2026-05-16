<?php
/**
 * ============================================================
 * EQAS - مقارنة المؤشر
 * نظام التقييم الشامل - Compare EQI
 * ============================================================
 */

$pageTitle = 'مقارنة مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'مقارنة' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';

// الحصول على المدارس
$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    } else {
        $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = ? AND is_active = 1 ORDER BY name_ar");
        $stmt->execute([$_SESSION['user']['school_id'] ?? 0]);
    }
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

// السنوات
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedYear = (int)($_GET['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$selectedSemester = (int)($_GET['semester_id'] ?? 0);
$selectedSchools = $_GET['schools'] ?? [];
$selectedSchoolIds = array_map('intval', (array)$selectedSchools);

$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1");
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// تنفيذ المقارنة
$comparisons = [];
$allDimensions = $eqiCalc->getDimensions();
$dimKeys = array_keys($allDimensions);
$dimNames = array_map(function($d) { return $d['name']; }, $allDimensions);

$adjustedResults = [];
if (!empty($selectedSchoolIds) && $selectedYear && $selectedSemester) {
    $comparisons = $eqiCalc->compareSchools($selectedSchoolIds, $selectedYear, $selectedSemester);

    $adjustService = new ContextualAdjustmentService($db);

    $rawMap = [];
    foreach ($comparisons as $comp) {
        $rawMap[$comp['school_id']] = $comp['overall_eqi'];
    }

    $schoolIds = array_map(function($c) { return $c['school_id']; }, $comparisons);
    $adjustedResults = $adjustService->batchCalculateAdjustedSQI($schoolIds, $selectedYear, $rawMap);
}
?>

<style>
.comp-hero {
    background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%);
    border-radius: 16px; padding: 2rem 2.5rem; margin-bottom: 1.5rem;
    color: #fff; position: relative; overflow: hidden;
}
.comp-hero::before {
    content: ''; position: absolute; top: -50%; left: -15%;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(200,169,81,0.12) 0%, transparent 70%);
    border-radius: 50%;
}
.comp-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.comp-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
.f-c {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden;
}
.f-c-hd {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; gap: 0.5rem;
}
.f-c-hd h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.f-c-bd { padding: 1.5rem; }
.f-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
.f-grp { }
.f-grp label { font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.35rem; display: block; }
.f-grp select {
    border: 1px solid #dee2e6; border-radius: 8px; padding: 0.5rem 1rem;
    min-width: 180px; font-size: 0.9rem; color: #1a1a2e; background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.f-grp select:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.12); outline: none; }
.f-grp select[multiple] { min-height: 100px; }
.btn-comp {
    display: inline-flex; align-items: center; gap: 0.35rem;
    background: #1a5632; color: #fff; padding: 0.5rem 1.25rem;
    border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-comp:hover { background: #0f3a20; }
.chart-box {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden;
}
.chart-box-hd {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; justify-content: space-between;
}
.chart-box-hd h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.chart-box-bd { padding: 1.5rem; }
.comp-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.comp-table th {
    background: #f8f9fa; font-weight: 600; text-align: center; padding: 0.7rem 0.6rem;
    font-size: 0.75rem; color: #6c757d; border-bottom: 2px solid #dee2e6;
}
.comp-table th:first-child { text-align: right; padding-right: 1rem; border-radius: 0 8px 0 0; }
.comp-table th:last-child { border-radius: 8px 0 0 0; }
.comp-table td { padding: 0.75rem 0.6rem; border-bottom: 1px solid #e9ecef; font-size: 0.85rem; text-align: center; }
.comp-table td:first-child { text-align: right; padding-right: 1rem; }
.comp-table tbody tr { transition: background 0.15s; }
.comp-table tbody tr:hover { background: #f1f3f5; }
.comp-table tbody tr:last-child td { border-bottom: none; }
.comp-table .best-row { background: rgba(40,167,69,0.04); }
.comp-table .worst-row { background: rgba(220,53,69,0.04); }
.cscore { font-weight: 700; }
.cscore .sdot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-left: 0.3rem; }
.chal-lbl { font-size: 0.7rem; color: #6c757d; }
.empty-comp {
    text-align: center; padding: 3rem 1.5rem; color: #6c757d;
}
.empty-comp .eci { font-size: 3rem; margin-bottom: 0.75rem; }
.empty-comp h3 { font-size: 1.1rem; margin-bottom: 0.5rem; color: #1a1a2e; }
@media (max-width:768px) {
    .comp-hero { padding: 1.25rem; }
    .f-row { flex-direction: column; }
    .f-grp select { min-width: 100%; }
}
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="comp-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>مقارنة مؤشر جودة التعليم</h1>
                <div class="hero-sub">قارن أداء المدارس في الأبعاد الستة والمؤشر الكلي</div>
            </div>
            <a href="index.php" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">← العودة</a>
        </div>
    </div>

    <!-- Form -->
    <div class="f-c">
        <div class="f-c-hd"><h3>📊 مقارنة مؤشر جودة التعليم</h3></div>
        <div class="f-c-bd">
            <form method="GET" class="f-row">
                <div class="f-grp">
                    <label>السنة الدراسية</label>
                    <select name="academic_year_id" onchange="this.form.submit()">
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-grp">
                    <label>الفصل الدراسي</label>
                    <select name="semester_id" onchange="this.form.submit()">
                        <option value="">اختر</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-grp" style="flex:2;min-width:250px;">
                    <label>المدارس (اختر 2 أو أكثر)</label>
                    <select name="schools[]" multiple size="4">
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= in_array((int)$s['id'], $selectedSchoolIds) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f-grp" style="padding-top:0.25rem;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-comp">📊 مقارنة</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($comparisons)): 
        // Find best and worst overall EQI
        $bestId = null; $worstId = null; $bestVal = -1; $worstVal = 999;
        foreach ($comparisons as $comp) {
            if ($comp['overall_eqi'] > $bestVal) { $bestVal = $comp['overall_eqi']; $bestId = $comp['school_id']; }
            if ($comp['overall_eqi'] < $worstVal) { $worstVal = $comp['overall_eqi']; $worstId = $comp['school_id']; }
        }
    ?>
    <!-- Chart -->
    <div class="chart-box">
        <div class="chart-box-hd"><h3>📊 مقارنة بصرية</h3></div>
        <div class="chart-box-bd">
            <div style="height:340px;">
                <canvas id="compare-chart"></canvas>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="chart-box">
        <div class="chart-box-hd">
            <h3>📋 جدول المقارنة التفصيلي</h3>
            <span style="font-size:0.8rem;color:#6c757d;"><?= count($comparisons) ?> مدرسة</span>
        </div>
        <div class="chart-box-bd" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="comp-table">
                    <thead>
                        <tr>
                            <th style="min-width:160px;">المدرسة</th>
                            <?php foreach ($dimKeys as $key): ?>
                            <th><?= $dimNames[$key] ?></th>
                            <?php endforeach; ?>
                            <th>المؤشر الكلي</th>
                            <th>المعدّل سياقياً</th>
                            <th>التحدي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comparisons as $comp): 
                            $sid = $comp['school_id'];
                            $adj = $adjustedResults[$sid] ?? null;
                            $adjValue = $adj ? $adj['adjusted_sqi'] : null;
                            $adjRating = $adjValue !== null ? $eqiCalc->getEQIRating($adjValue) : null;
                            $rowClass = $sid === $bestId ? 'best-row' : ($sid === $worstId ? 'worst-row' : '');
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong style="color:<?= $comp['rating']['color'] ?>;"><?= e($comp['school_name']) ?></strong></td>
                            <?php foreach ($dimKeys as $key): 
                                $score = $comp[$key . '_score'];
                                $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                                $barColor = $score >= 80 ? '#28a745' : ($score >= 50 ? '#ffc107' : ($score !== null ? '#dc3545' : '#dee2e6'));
                            ?>
                            <td>
                                <?php if ($score !== null): ?>
                                    <div style="display:flex;align-items:center;gap:0.3rem;justify-content:center;">
                                        <span style="font-weight:600;color:<?= $dimRating['color'] ?>;font-size:0.85rem;"><?= number_format($score, 1) ?></span>
                                        <span style="display:inline-block;width:40px;height:5px;background:#e9ecef;border-radius:3px;overflow:hidden;">
                                            <span style="display:block;height:100%;width:<?= min($score,100) ?>%;background:<?= $barColor ?>;border-radius:3px;"></span>
                                        </span>
                                    </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td>
                                <span class="cscore" style="color:<?= $comp['rating']['color'] ?>;">
                                    <span class="sdot" style="background:<?= $comp['rating']['color'] ?>;"></span>
                                    <?= number_format($comp['overall_eqi'], 1) ?>
                                </span>
                                <br><span style="padding:1px 6px;border-radius:999px;font-size:0.7rem;background:<?= $comp['rating']['color'] ?>20;color:<?= $comp['rating']['color'] ?>;"><?= $comp['rating']['text'] ?></span>
                            </td>
                            <td>
                                <?php if ($adjValue !== null): ?>
                                    <span style="font-weight:700;color:<?= $adjRating['color'] ?? '#333' ?>;"><?= number_format($adjValue, 1) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($adj && $adj['has_context']): ?>
                                    <span style="font-weight:700;font-size:0.95rem;color:<?= $adj['challenge_index'] > 50 ? '#dc3545' : ($adj['challenge_index'] > 25 ? '#ffc107' : '#28a745') ?>;">
                                        <?= number_format($adj['challenge_index'], 0) ?>
                                    </span>
                                    <br><span class="chal-lbl"><?= $adj['challenge_index'] > 50 ? 'مرتفع' : ($adj['challenge_index'] > 25 ? 'متوسط' : 'منخفض') ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($selectedYear && $selectedSemester): ?>
    <div class="chart-box">
        <div class="empty-comp">
            <div class="eci">📊</div>
            <h3>اختر المدارس للمقارنة</h3>
            <p style="font-size:0.9rem;">حدد مدرستين أو أكثر من القائمة أعلاه ثم اضغط "مقارنة"</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if (!empty($comparisons)):
$chartLabels = json_encode(array_values($dimNames), JSON_UNESCAPED_UNICODE);
$datasets = [];
$schoolColors = ['#1a5632', '#17a2b8', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'];
foreach ($comparisons as $j => $comp) {
    $data = array_map(function($key) use ($comp) { return $comp[$key . '_score'] ?? 0; }, $dimKeys);
    $c = $schoolColors[$j % count($schoolColors)];
    $datasets[] = ['label' => $comp['school_name'], 'data' => $data, 'backgroundColor' => $c . '33', 'borderColor' => $c, 'borderWidth' => 2];
}
$pageJs = "
new Chart(document.getElementById('compare-chart'), {
    type: 'bar',
    data: { labels: $chartLabels, datasets: " . json_encode($datasets, JSON_UNESCAPED_UNICODE) . " },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } },
        plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
