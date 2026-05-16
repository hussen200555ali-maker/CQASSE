<?php
/**
 * ============================================================
 * EQAS - عرض تفاصيل حساب المؤشر
 * نظام التقييم الشامل - View EQI Detail
 * ============================================================
 */

$pageTitle = 'تفاصيل مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'تفاصيل' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin') && !hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$schoolId = (int)($_GET['school_id'] ?? 0);
$yearId = (int)($_GET['year_id'] ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);

if (!$schoolId) {
    setFlash('معرفات غير صالحة.', 'danger');
    redirect('index.php');
}

// دعم وضع "جميع السنوات" — اختيار أحدث حساب متاح
if (!$yearId || !$semesterId) {
    try {
        $stmt = $db->prepare("SELECT academic_year_id, semester_id FROM eqi_results WHERE school_id = ? AND is_active = 1 ORDER BY calculated_at DESC LIMIT 1");
        $stmt->execute([$schoolId]);
        $latest = $stmt->fetch();
        if ($latest) {
            $yearId = (int)$latest['academic_year_id'];
            $semesterId = (int)$latest['semester_id'];
        }
    } catch (Exception $e) {}
    if (!$yearId || !$semesterId) {
        setFlash('لا توجد نتائج EQI لهذه المدرسة.', 'warning');
        redirect('index.php');
    }
}

$calc = $eqiCalc->getEQIDimensions($schoolId, $yearId, $semesterId);
if (!$calc) {
    setFlash('لا يوجد حساب لهذه الفترة.', 'warning');
    redirect('index.php');
}

$rating = $eqiCalc->getEQIRating($calc['overall_eqi'] ?? 0);
$dimensions = $eqiCalc->getDimensions();
$recommendations = $eqiCalc->getRecommendations([
    'academic' => $calc['academic_score'],
    'teaching' => $calc['teaching_score'],
    'climate' => $calc['climate_score'],
    'behavior' => $calc['behavior_score'],
    'skills' => $calc['skills_score'],
    'attendance' => $calc['attendance_score'],
]);

// الحساب السابق للمقارنة
$prevCalc = null;
try {
    $stmt = $db->prepare("SELECT er.* FROM eqi_results er 
                          INNER JOIN semesters s ON s.id = er.semester_id
                          WHERE er.school_id = :sid AND er.is_active = 1 
                              AND (er.semester_id < :sem_id OR er.calculated_at < (SELECT calculated_at FROM eqi_results WHERE school_id = :sid2 AND academic_year_id = :yid AND semester_id = :sem_id2))
                          ORDER BY er.calculated_at DESC LIMIT 1");
    $stmt->execute([':sid' => $schoolId, ':sem_id' => $semesterId, ':sid2' => $schoolId, ':yid' => $yearId, ':sem_id2' => $semesterId]);
    $prevCalc = $stmt->fetch();
} catch (Exception $e) {}
?>

<div class="section">
    <!-- المؤشر الرئيسي -->
    <div class="card mb-6">
        <div class="card-header" style="background:<?= $rating['color'] ?>; color:#fff;">
            <div class="flex items-center gap-4">
                <div style="font-size:48px; font-weight:bold;"><?= number_format($calc['overall_eqi'], 1) ?></div>
                <div>
                    <h2 style="margin:0; color:#fff;"><?= $rating['text'] ?></h2>
                    <span style="opacity:0.9;">مؤشر جودة التعليم</span>
                </div>
            </div>
            <a href="index.php" class="btn btn-light btn-sm">↩️ العودة</a>
        </div>
        <div class="card-body">
            <div class="grid grid-3 gap-4">
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">الأكاديمي</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['academic_score'] ?? 0, 1) ?>%</div>
                </div>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">التدريس</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['teaching_score'] ?? 0, 1) ?>%</div>
                </div>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">المناخ</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['climate_score'] ?? 0, 1) ?>%</div>
                </div>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">السلوك</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['behavior_score'] ?? 0, 1) ?>%</div>
                </div>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">المهارات</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['skills_score'] ?? 0, 1) ?>%</div>
                </div>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg);">
                    <div class="text-secondary" style="font-size:12px;">الحضور</div>
                    <div style="font-size:24px; font-weight:bold;"><?= number_format($calc['attendance_score'] ?? 0, 1) ?>%</div>
                </div>
            </div>
            
            <?php if ($prevCalc): ?>
            <div class="mt-4 p-3" style="background:<?= ($calc['overall_eqi'] >= $prevCalc['overall_eqi']) ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)'; ?>; border-radius:var(--radius-md);">
                <span>📊 المقارنة مع الفترة السابقة: </span>
                <strong style="color:<?= ($calc['overall_eqi'] >= $prevCalc['overall_eqi']) ? 'var(--success)' : 'var(--danger)'; ?>">
                    <?= ($calc['overall_eqi'] >= $prevCalc['overall_eqi']) ? '↑' : '↓' ?>
                    <?= number_format(abs($calc['overall_eqi'] - $prevCalc['overall_eqi']), 1) ?>%
                </strong>
                <span class="text-secondary">(السابق: <?= number_format($prevCalc['overall_eqi'], 1) ?>)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- التوصيات -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">💡 التوصيات</h3>
        </div>
        <div class="card-body">
            <div class="flex flex-col gap-4">
                <?php foreach ($recommendations as $key => $rec): 
                    $dim = $dimensions[$key];
                ?>
                <div class="p-4" style="border:1px solid var(--border-light); border-radius:var(--radius-lg); border-right:4px solid <?= $rec['rating']['color'] ?>;">
                    <div class="flex items-center gap-3 mb-2">
                        <span style="font-size:20px;"><?= $dim['icon'] ?></span>
                        <strong><?= $rec['dimension'] ?></strong>
                        <span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $rec['rating']['color'] ?>; color:#fff;"><?= number_format($rec['score'], 1) ?>% - <?= $rec['rating']['text'] ?></span>
                    </div>
                    <p style="margin:0; color:var(--text-secondary); line-height:1.8;"><?= e($rec['recommendation']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- السجل التاريخي -->
    <?php
    $history = $eqiCalc->getEQIHistory($schoolId, 20);
    ?>
    <div class="card mt-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card-title">📈 السجل التاريخي</h3>
            <a href="report.php?school_id=<?= $schoolId ?>&format=csv" class="btn btn-info btn-sm">📥 تصدير CSV</a>
        </div>
        <div class="card-body">
            <?php if (!empty($history)): ?>
            <div style="height:260px;margin-bottom:1.5rem;">
                <canvas id="eqi-history-chart"></canvas>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>السنة</th>
                            <th>الفصل</th>
                            <th>الأكاديمي</th>
                            <th>التدريس</th>
                            <th>المناخ</th>
                            <th>السلوك</th>
                            <th>المهارات</th>
                            <th>الحضور</th>
                            <th>المؤشر الكلي</th>
                            <th>التصنيف</th>
                            <th>تاريخ الحساب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h):
                            $hRating = $eqiCalc->getEQIRating($h['overall_eqi'] ?? 0);
                        ?>
                        <tr>
                            <td><?= e($h['year_name']) ?></td>
                            <td><?= e($h['semester_name']) ?></td>
                            <td><?= $h['academic_score'] !== null ? number_format($h['academic_score'], 1) : '-' ?></td>
                            <td><?= $h['teaching_score'] !== null ? number_format($h['teaching_score'], 1) : '-' ?></td>
                            <td><?= $h['climate_score'] !== null ? number_format($h['climate_score'], 1) : '-' ?></td>
                            <td><?= $h['behavior_score'] !== null ? number_format($h['behavior_score'], 1) : '-' ?></td>
                            <td><?= $h['skills_score'] !== null ? number_format($h['skills_score'], 1) : '-' ?></td>
                            <td><?= $h['attendance_score'] !== null ? number_format($h['attendance_score'], 1) : '-' ?></td>
                            <td><strong style="color:<?= $hRating['color'] ?>;"><?= number_format($h['overall_eqi'], 1) ?></strong></td>
                            <td><span style="padding:2px 8px;border-radius:999px;font-size:11px;background:<?= $hRating['color'] ?>;color:#fff;"><?= $hRating['text'] ?></span></td>
                            <td style="font-size:12px;"><?= formatDateAr($h['calculated_at'], 'short') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center" style="padding:2rem;">
                <div style="font-size:2rem;">📊</div>
                <p class="text-secondary">لا يوجد سجل حسابات سابقة.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if (!empty($history)):
$histLabels = json_encode(array_map(function($h) { return $h['year_name'] . ' - ' . $h['semester_name']; }, array_reverse($history)), JSON_UNESCAPED_UNICODE);
$histData = json_encode(array_map(function($h) { return $h['overall_eqi']; }, array_reverse($history)));
$pageJs = "
new Chart(document.getElementById('eqi-history-chart'), {
    type: 'line',
    data: {
        labels: $histLabels,
        datasets: [{
            label: 'مؤشر جودة التعليم',
            data: $histData,
            borderColor: '#1a5632',
            backgroundColor: 'rgba(26,86,50,0.1)',
            fill: true,
            tension: 0.3,
            borderWidth: 3,
            pointRadius: 5,
            pointBackgroundColor: '#1a5632'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100 } },
        plugins: { legend: { display: false } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
