<?php
/**
 * ============================================================
 * EQAS - تاريخ حسابات المؤشر
 * نظام التقييم الشامل - EQI History
 * ============================================================
 */

$pageTitle = 'تاريخ مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'التاريخ' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    } else {
        $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = :id");
        $stmt->execute([':id' => $userSchoolId]);
    }
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedSchool = (int)($_GET['school_id'] ?? ($schools[0]['id'] ?? 0));
$history = [];

if ($selectedSchool) {
    $history = $eqiCalc->getEQIHistory($selectedSchool, 20);
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-inline">
                <label class="form-label" style="margin:0 8px 0 0;">المدرسة:</label>
                <select name="school_id" class="form-control" style="width:auto;">
                    <?php foreach ($schools as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selectedSchool == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">عرض</button>
                <a href="report.php?school_id=<?= $selectedSchool ?>&format=csv" class="btn btn-info" style="margin-right:8px;">📥 تصدير CSV</a>
            </form>
        </div>
    </div>

    <?php if (!empty($history)): ?>
    <!-- مخطط الاتجاه -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📈 اتجاه المؤشر عبر الزمن</h3>
        </div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="eqi-trend"></canvas>
            </div>
        </div>
    </div>

    <!-- جدول التاريخ -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 سجل الحسابات</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
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
                            <td><span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $hRating['color'] ?>; color:#fff;"><?= $hRating['text'] ?></span></td>
                            <td style="font-size:12px;"><?= formatDateAr($h['calculated_at'], 'short') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body text-center" style="padding:40px;">
            <div style="font-size:48px;">📊</div>
            <h3>لا يوجد سجل حسابات</h3>
            <p class="text-secondary">لم يتم حساب المؤشر لهذه المدرسة بعد.</p>
            <a href="calculate.php" class="btn btn-primary mt-4">▶️ حساب المؤشر</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if (!empty($history)):
$labels = json_encode(array_map(function($h) { return $h['year_name'] . ' - ' . $h['semester_name']; }, array_reverse($history)), JSON_UNESCAPED_UNICODE);
$eqiData = json_encode(array_map(function($h) { return $h['overall_eqi']; }, array_reverse($history)));
$pageJs = "
new Chart(document.getElementById('eqi-trend'), {
    type: 'line',
    data: {
        labels: $labels,
        datasets: [{
            label: 'مؤشر جودة التعليم',
            data: $eqiData,
            borderColor: '#1a5632',
            backgroundColor: 'rgba(26, 86, 50, 0.1)',
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
