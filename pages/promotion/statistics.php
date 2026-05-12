<?php
/**
 * ============================================================
 * EQAS - إحصائيات الترفيع
 * نظام التقييم الشامل - Promotion Statistics
 * ============================================================
 */

$pageTitle = 'إحصائيات الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'إحصائيات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$selectedYear = $_GET['academic_year_id'] ?? '';
$selectedSemester = $_GET['semester_id'] ?? '';

$stats = $promoEngine->getStatistics($userRole !== 'group_admin' ? $userSchoolId : null, $selectedYear ?: null, $selectedSemester ?: null);
$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();

$academicYears = [];
try { $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC"); $academicYears = $stmt->fetchAll(); } catch (Exception $e) {}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-inline">
                <label class="form-label" style="margin:0 8px 0 0;">السنة:</label>
                <select name="academic_year_id" class="form-control" style="width:auto;">
                    <option value="">الكل</option>
                    <?php foreach ($academicYears as $ay): ?><option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">عرض</button>
            </form>
        </div>
    </div>

    <!-- إجمالي -->
    <div class="grid grid-4 mb-6">
        <?php foreach ($decisionLabels as $key => $label): 
            $count = $stats['by_decision'][$key] ?? 0;
            $pct = $stats['percentages'][$key] ?? 0;
            $badgeMap = ['promoted' => '#28a745', 'conditional' => '#ffc107', 'repeated' => '#dc3545', 'under_review' => '#17a2b8'];
        ?>
        <div class="card">
            <div class="card-body text-center" style="border-top:4px solid <?= $badgeMap[$key] ?>;">
                <div style="font-size:32px; font-weight:bold; color:<?= $badgeMap[$key] ?>;"><?= $count ?></div>
                <div class="text-secondary"><?= $label ?></div>
                <div style="font-size:13px; color:var(--text-muted);"><?= $pct ?>%</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="grid grid-2 gap-6">
        <!-- مخطط دائري -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 توزيع القرارات</h3></div>
            <div class="card-body">
                <div style="height:300px;"><canvas id="decision-pie"></canvas></div>
            </div>
        </div>

        <!-- المتوسطات -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 المتوسطات</h3></div>
            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div>
                        <div class="flex justify-between mb-1">
                            <span>المتوسط الأكاديمي</span>
                            <strong style="color:var(--primary);"><?= $stats['averages']['academic'] ?>%</strong>
                        </div>
                        <div style="background:var(--border-light); border-radius:var(--radius-full); height:12px; overflow:hidden;">
                            <div style="width:<?= $stats['averages']['academic'] ?>%; background:var(--primary); height:100%; border-radius:var(--radius-full);"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span>متوسط السلوك</span>
                            <strong style="color:var(--info);"><?= $stats['averages']['behavior'] ?>%</strong>
                        </div>
                        <div style="background:var(--border-light); border-radius:var(--radius-full); height:12px; overflow:hidden;">
                            <div style="width:<?= $stats['averages']['behavior'] ?>%; background:var(--info); height:100%; border-radius:var(--radius-full);"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span>متوسط الحضور</span>
                            <strong style="color:var(--success);"><?= $stats['averages']['attendance'] ?>%</strong>
                        </div>
                        <div style="background:var(--border-light); border-radius:var(--radius-full); height:12px; overflow:hidden;">
                            <div style="width:<?= $stats['averages']['attendance'] ?>%; background:var(--success); height:100%; border-radius:var(--radius-full);"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
if ($stats['total'] > 0):
$pageJs = "
new Chart(document.getElementById('decision-pie'), {
    type: 'doughnut',
    data: {
        labels: ['" . implode("','", $decisionLabels) . "'],
        datasets: [{
            data: [" . implode(',', array_values($stats['by_decision'])) . "],
            backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#17a2b8'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
