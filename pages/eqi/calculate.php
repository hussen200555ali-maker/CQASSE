<?php
/**
 * ============================================================
 * EQAS - حساب مؤشر جودة التعليم
 * نظام التقييم الشامل - Calculate EQI
 * ============================================================
 */

$pageTitle = 'حساب مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'حساب المؤشر' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// المدارس
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

// السنوات
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedSchool = (int)($_GET['school_id'] ?? $_POST['school_id'] ?? ($schools[0]['id'] ?? 0));
$selectedYear = (int)($_GET['academic_year_id'] ?? $_POST['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$selectedSemester = (int)($_GET['semester_id'] ?? $_POST['semester_id'] ?? 0);

// الفصول
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1");
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$result = null;

// معالجة الحساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);
        
        if (!$schoolId || !$yearId || !$semesterId) {
            setFlash('يرجى اختيار المدرسة والسنة والفصل الدراسي.', 'warning');
        } else {
            $result = $eqiCalc->calculate($schoolId, $yearId, $semesterId);
            
            if ($result['eqi'] !== null) {
                setFlash(sprintf('تم حساب المؤشر بنجاح: %.2f (%s)', $result['eqi'], $result['rating']['text']), 'success');
            } else {
                setFlash($result['message'] ?? 'لا تتوفر بيانات كافية للحساب.', 'warning');
            }
        }
    }
}

$dimensions = $eqiCalc->getDimensions();
?>

<div class="section">
    <!-- نموذج الحساب -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">▶️ حساب مؤشر جودة التعليم</h3>
        </div>
        <div class="card-body">
            <div class="mb-4 p-4" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg); border-right:4px solid var(--primary);">
                <p style="margin:0;"><strong>📐 طريقة الحساب الهجينة:</strong> يتم إعادة توزيع أوزان الأبعاد المفقودة بنسبة 50% تناسبية + 50% متساوية بين الأبعاد المتاحة.</p>
            </div>
            
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المدرسة <span class="required">*</span></label>
                        <select name="school_id" class="form-control" required>
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $selectedSchool == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية <span class="required">*</span></label>
                        <select name="academic_year_id" class="form-control" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="calculate" value="1" class="btn btn-primary btn-lg" onclick="return confirm('هل تريد حساب مؤشر جودة التعليم؟ سيتم استبدال أي حساب سابق.')">
                    ▶️ حساب المؤشر
                </button>
            </form>
        </div>
    </div>

    <!-- نتائج الحساب -->
    <?php if ($result !== null): ?>
    <div class="card mb-6">
        <div class="card-header" style="background:<?= $result['rating']['color'] ?? '#6c757d' ?>; color:#fff;">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">📊</span>
                <div>
                    <h3 style="margin:0; color:#fff;"><?= number_format($result['eqi'] ?? 0, 2) ?> - <?= $result['rating']['text'] ?? 'غير محدد' ?></h3>
                    <span style="opacity:0.9;">مؤشر جودة التعليم</span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($result['missing_dimensions'])): ?>
            <div class="mb-4 p-3" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-md); border-right:4px solid var(--warning);">
                <p style="margin:0;"><strong>⚠️ أبعاد مفقودة:</strong> <?= implode('، ', $result['redistribution_info']['missing_names'] ?? []) ?></p>
                <p style="margin:4px 0 0; font-size:13px; color:var(--text-secondary);">تم إعادة توزيع الوزن (<?= number_format($result['redistribution_info']['missing_weight'] * 100, 1) ?>%) بالطريقة الهجينة.</p>
            </div>
            <?php endif; ?>

            <!-- مخطط رادار -->
            <div style="height:300px; max-width:400px; margin:0 auto 24px;">
                <canvas id="result-radar"></canvas>
            </div>

            <!-- تفاصيل الأبعاد -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>البعد</th>
                            <th>الدرجة</th>
                            <th>الوزن الأصلي</th>
                            <th>الوزن الفعلي</th>
                            <th>الدرجة الموزونة</th>
                            <th>التصنيف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dimensions as $key => $dim): 
                            $detail = $result['dimension_details'][$key] ?? null;
                            $score = $detail['score'] ?? null;
                            $origWeight = $dim['weight'] * 100;
                            $effWeight = isset($result['weights'][$key]) ? $result['weights'][$key] * 100 : 0;
                            $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                        ?>
                        <tr>
                            <td><?= $dim['icon'] ?> <strong><?= $dim['name'] ?></strong></td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <span style="font-weight:bold; color:<?= $dimRating['color'] ?>;"><?= number_format($score, 1) ?>%</span>
                                <?php else: ?>
                                    <span class="text-secondary" style="font-style:italic;">غير متوفر ❌</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($origWeight, 1) ?>%</td>
                            <td>
                                <?= number_format($effWeight, 1) ?>%
                                <?php if ($effWeight != $origWeight): ?>
                                    <span style="color:var(--warning); font-size:11px;">(↑)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <?= number_format($score * ($result['weights'][$key] ?? 0), 2) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dimRating): ?>
                                    <span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $dimRating['color'] ?>; color:#fff;"><?= $dimRating['text'] ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>المجموع</strong></td>
                            <td></td>
                            <td>100%</td>
                            <td>100%</td>
                            <td><strong style="font-size:16px; color:<?= $result['rating']['color'] ?>;"><?= number_format($result['eqi'], 2) ?></strong></td>
                            <td><span style="padding:4px 12px; border-radius:var(--radius-full); background:<?= $result['rating']['color'] ?>; color:#fff; font-weight:600;"><?= $result['rating']['text'] ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div class="mt-4 text-center">
                <a href="view.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-primary">📊 عرض التفاصيل الكاملة</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if ($result && $result['eqi'] !== null):
$pageJs = "
new Chart(document.getElementById('result-radar'), {
    type: 'radar',
    data: {
        labels: ['" . implode("','", array_map(function($d) { return $d['name']; }, $dimensions)) . "'],
        datasets: [{
            label: 'مؤشر الجودة',
            data: [" . implode(',', array_map(function($key) use ($result) { return $result['scores'][$key] ?? 0; }, array_keys($dimensions))) . "],
            backgroundColor: 'rgba(26, 86, 50, 0.2)',
            borderColor: '#1a5632',
            borderWidth: 2,
            pointBackgroundColor: '#1a5632'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { r: { beginAtZero: true, max: 100 } },
        plugins: { legend: { display: false } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
