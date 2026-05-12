<?php
/**
 * ============================================================
 * EQAS - مؤشر جودة التعليم - لوحة التحكم
 * نظام التقييم الشامل - EQI Dashboard
 * ============================================================
 */

$pageTitle = 'مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// الحصول على المدارس
$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    } else {
        $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $userSchoolId]);
    }
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

// الحصول على السنوات
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedSchool = (int)($_GET['school_id'] ?? ($schools[0]['id'] ?? 0));
$selectedYear = (int)($_GET['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$selectedSemester = (int)($_GET['semester_id'] ?? 0);

// الحصول على الفصول
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1");
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
        if (!$selectedSemester && !empty($semesters)) {
            $selectedSemester = $semesters[0]['id'];
        }
    } catch (Exception $e) {}
}

// الحصول على حساب EQI
$currentCalc = null;
$history = [];
if ($selectedSchool && $selectedYear && $selectedSemester) {
    $currentCalc = $eqiCalc->getEQIDimensions($selectedSchool, $selectedYear, $selectedSemester);
    $history = $eqiCalc->getEQIHistory($selectedSchool, 10);
}

$dimensions = $eqiCalc->getDimensions();
$rating = $currentCalc ? $eqiCalc->getEQIRating($currentCalc['overall_eqi'] ?? 0) : null;

// ترتيب المدارس (لمدير النظام)
$ranking = [];
if ($userRole === 'group_admin' && $selectedYear && $selectedSemester) {
    $ranking = $eqiCalc->getSchoolRanking($selectedYear, $selectedSemester, 10);
}
?>

<div class="section">
    <!-- فلاتر -->
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-row">
                <?php if ($userRole === 'group_admin'): ?>
                <div class="form-group">
                    <label class="form-label">المدرسة</label>
                    <select name="school_id" class="form-control">
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $selectedSchool == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
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
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">🔍 عرض</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($currentCalc): ?>
    <!-- المؤشر الرئيسي -->
    <div class="grid grid-3 gap-6 mb-6">
        <!-- مؤشر EQI الكلي -->
        <div class="card">
            <div class="card-body text-center">
                <h4 class="mb-4">مؤشر جودة التعليم</h4>
                <div style="width:180px; height:180px; margin:0 auto; position:relative;">
                    <canvas id="eqi-gauge"></canvas>
                    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center;">
                        <div style="font-size:36px; font-weight:bold; color:<?= $rating['color'] ?>;"><?= number_format($currentCalc['overall_eqi'], 1) ?></div>
                        <div style="font-size:14px; color:<?= $rating['color'] ?>; font-weight:600;"><?= $rating['text'] ?></div>
                    </div>
                </div>
                <div class="mt-4 flex justify-center gap-2">
                    <a href="view.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-primary btn-sm">📊 التفاصيل</a>
                    <a href="calculate.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-light btn-sm">🔄 إعادة حساب</a>
                </div>
            </div>
        </div>
        
        <!-- الأبعاد الستة -->
        <div class="card" style="grid-column:span 2;">
            <div class="card-header">
                <h3 class="card-title">📊 توزيع الأبعاد</h3>
            </div>
            <div class="card-body">
                <div style="height:250px;">
                    <canvas id="eqi-radar"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- تفاصيل الأبعاد -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📋 تفاصيل الأبعاد</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>البعد</th>
                            <th>الدرجة</th>
                            <th>الوزن</th>
                            <th>الدرجة الموزونة</th>
                            <th>التصنيف</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $dimKeys = ['academic' => 'الأداء الأكاديمي', 'teaching' => 'جودة التدريس', 'climate' => 'المناخ المدرسي', 'behavior' => 'السلوك', 'skills' => 'المهارات', 'attendance' => 'الحضور'];
                        $dimIcons = ['academic' => '📚', 'teaching' => '👨‍🏫', 'climate' => '🏫', 'behavior' => '⭐', 'skills' => '🎯', 'attendance' => '📋'];
                        foreach ($dimKeys as $key => $name): 
                            $score = $currentCalc[$key . '_score'];
                            $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                        ?>
                        <tr>
                            <td>
                                <span style="font-size:18px;"><?= $dimIcons[$key] ?></span>
                                <strong><?= $name ?></strong>
                                <span class="text-secondary" style="font-size:12px;">(<?= ($dimensions[$key]['weight'] * 100) ?>%)</span>
                            </td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <span style="font-weight:bold; color:<?= $dimRating['color'] ?>;"><?= number_format($score, 1) ?>%</span>
                                <?php else: ?>
                                    <span class="text-secondary">غير متوفر</span>
                                <?php endif; ?>
                            </td>
                            <td><?= ($dimensions[$key]['weight'] * 100) ?>%</td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <?= number_format($score * $dimensions[$key]['weight'], 2) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dimRating): ?>
                                    <span style="padding:2px 10px; border-radius:var(--radius-full); font-size:12px; background:<?= $dimRating['color'] ?>; color:#fff;"><?= $dimRating['text'] ?></span>
                                <?php else: ?>
                                    <span style="padding:2px 10px; border-radius:var(--radius-full); font-size:12px; background:#6c757d; color:#fff;">غير متوفر</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <span style="color:var(--success);">✅ متوفر</span>
                                <?php else: ?>
                                    <span style="color:var(--danger);">❌ مفقود</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>المؤشر الكلي</strong></td>
                            <td colspan="2"></td>
                            <td><strong style="font-size:16px; color:<?= $rating['color'] ?>;"><?= number_format($currentCalc['overall_eqi'], 2) ?></strong></td>
                            <td><span style="padding:4px 12px; border-radius:var(--radius-full); background:<?= $rating['color'] ?>; color:#fff; font-weight:600;"><?= $rating['text'] ?></span></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <?php if ($userRole === 'group_admin' && !empty($ranking)): ?>
    <!-- ترتيب المدارس -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🏆 ترتيب المدارس</h3>
            <a href="compare.php" class="btn btn-primary btn-sm">📊 مقارنة تفصيلية</a>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr><th>#</th><th>المدرسة</th><th>المؤشر</th><th>التصنيف</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranking as $r): 
                            $rRating = $eqiCalc->getEQIRating($r['overall_eqi']);
                        ?>
                        <tr style="<?= $r['overall_eqi'] == $currentCalc['overall_eqi'] ? 'background:rgba(26,86,50,0.05);' : '' ?>">
                            <td><strong><?= $r['rank'] ?></strong></td>
                            <td><?= e($r['school_name']) ?></td>
                            <td><strong style="color:<?= $rRating['color'] ?>;"><?= number_format($r['overall_eqi'], 1) ?></strong></td>
                            <td><span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $rRating['color'] ?>; color:#fff;"><?= $rRating['text'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($selectedSchool && $selectedYear && $selectedSemester): ?>
    <div class="card">
        <div class="card-body text-center" style="padding:60px 20px;">
            <div style="font-size:48px; margin-bottom:16px;">📊</div>
            <h3>لا يوجد حساب لهذه الفترة</h3>
            <p class="text-secondary">لم يتم حساب مؤشر جودة التعليم لهذه المدرسة في الفترة المحددة.</p>
            <a href="calculate.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-primary mt-4">▶️ حساب المؤشر الآن</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if ($currentCalc):
$pageJs = "
// Radar Chart
new Chart(document.getElementById('eqi-radar'), {
    type: 'radar',
    data: {
        labels: ['الأكاديمي', 'التدريس', 'المناخ', 'السلوك', 'المهارات', 'الحضور'],
        datasets: [{
            label: 'مؤشر الجودة',
            data: [
                " . ($currentCalc['academic_score'] ?? 0) . ",
                " . ($currentCalc['teaching_score'] ?? 0) . ",
                " . ($currentCalc['climate_score'] ?? 0) . ",
                " . ($currentCalc['behavior_score'] ?? 0) . ",
                " . ($currentCalc['skills_score'] ?? 0) . ",
                " . ($currentCalc['attendance_score'] ?? 0) . "
            ],
            backgroundColor: 'rgba(26, 86, 50, 0.2)',
            borderColor: '#1a5632',
            borderWidth: 2,
            pointBackgroundColor: '#1a5632'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            r: {
                beginAtZero: true,
                max: 100,
                ticks: { stepSize: 20, font: { size: 10 } },
                pointLabels: { font: { size: 12 } }
            }
        },
        plugins: { legend: { display: false } }
    }
});

// Doughnut Chart for EQI Score
new Chart(document.getElementById('eqi-gauge'), {
    type: 'doughnut',
    data: {
        datasets: [{
            data: [" . ($currentCalc['overall_eqi'] ?? 0) . ", " . (100 - ($currentCalc['overall_eqi'] ?? 0)) . "],
            backgroundColor: ['" . ($rating['color'] ?? '#6c757d') . "', '#e9ecef'],
            borderWidth: 0,
            circumference: 270,
            rotation: 225,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '75%',
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
