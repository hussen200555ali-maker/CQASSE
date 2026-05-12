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

if (!hasPermission('school_admin')) {
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

if (!empty($selectedSchoolIds) && $selectedYear && $selectedSemester) {
    $comparisons = $eqiCalc->compareSchools($selectedSchoolIds, $selectedYear, $selectedSemester);
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📊 مقارنة مؤشر جودة التعليم</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row">
                <div class="form-group">
                    <label class="form-label">السنة الدراسية</label>
                    <select name="academic_year_id" class="form-control">
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <option value="">اختر</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">المدارس (اختر 2 أو أكثر)</label>
                    <select name="schools[]" class="form-control" multiple size="4">
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= in_array((int)$s['id'], $selectedSchoolIds) ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">📊 مقارنة</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($comparisons)): ?>
    <!-- مقارنة بصرية -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📊 مقارنة بصرية</h3>
        </div>
        <div class="card-body">
            <div style="height:350px;">
                <canvas id="compare-chart"></canvas>
            </div>
        </div>
    </div>

    <!-- جدول المقارنة -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 جدول المقارنة التفصيلي</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>البعد</th>
                            <?php foreach ($comparisons as $comp): ?>
                            <th style="text-align:center;"><?= e($comp['school_name']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dimKeys as $i => $key): ?>
                        <tr>
                            <td><strong><?= $dimNames[$i] ?></strong></td>
                            <?php foreach ($comparisons as $comp): 
                                $score = $comp[$key . '_score'];
                                $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                            ?>
                            <td style="text-align:center;">
                                <?php if ($score !== null): ?>
                                    <span style="font-weight:bold; color:<?= $dimRating['color'] ?>;"><?= number_format($score, 1) ?>%</span>
                                    <br><span style="font-size:10px; color:<?= $dimRating['color'] ?>;"><?= $dimRating['text'] ?></span>
                                <?php else: ?>
                                    <span class="text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>المؤشر الكلي</strong></td>
                            <?php foreach ($comparisons as $comp): ?>
                            <td style="text-align:center;">
                                <strong style="font-size:16px; color:<?= $comp['rating']['color'] ?>;"><?= number_format($comp['overall_eqi'], 1) ?></strong>
                                <br><span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $comp['rating']['color'] ?>; color:#fff;"><?= $comp['rating']['text'] ?></span>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($selectedYear && $selectedSemester): ?>
    <div class="card">
        <div class="card-body text-center" style="padding:40px;">
            <div style="font-size:48px;">📊</div>
            <h3>اختر المدارس للمقارنة</h3>
            <p class="text-secondary">حدد مدرستين أو أكثر من القائمة أعلاه ثم اضغط "مقارنة"</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if (!empty($comparisons)):
$chartLabels = json_encode(array_map(function($c) { return $c['school_name']; }, $comparisons), JSON_UNESCAPED_UNICODE);
$datasets = [];
$colors = ['#1a5632', '#17a2b8', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14'];
foreach ($dimKeys as $i => $key) {
    $data = array_map(function($c) use ($key) { return $c[$key . '_score'] ?? 0; }, $comparisons);
    $datasets[] = [
        'label' => $dimNames[$i],
        'data' => $data,
        'backgroundColor' => $colors[$i] . '33',
        'borderColor' => $colors[$i],
        'borderWidth' => 2,
    ];
}
$pageJs = "
new Chart(document.getElementById('compare-chart'), {
    type: 'bar',
    data: {
        labels: $chartLabels,
        datasets: " . json_encode($datasets, JSON_UNESCAPED_UNICODE) . "
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } },
        plugins: { legend: { position: 'top' } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
