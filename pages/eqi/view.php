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

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$schoolId = (int)($_GET['school_id'] ?? 0);
$yearId = (int)($_GET['year_id'] ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);

if (!$schoolId || !$yearId || !$semesterId) {
    setFlash('معرفات غير صالحة.', 'danger');
    redirect('index.php');
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
    $stmt = $db->prepare("SELECT ec.* FROM eqi_calculations ec 
                          INNER JOIN semesters s ON s.id = ec.semester_id
                          WHERE ec.school_id = :sid AND ec.is_active = 1 
                              AND (ec.semester_id < :sem_id OR ec.calculated_at < (SELECT calculated_at FROM eqi_calculations WHERE school_id = :sid2 AND academic_year_id = :yid AND semester_id = :sem_id2))
                          ORDER BY ec.calculated_at DESC LIMIT 1");
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
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
