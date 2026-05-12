<?php
/**
 * ============================================================
 * سجل السلوك - تاريخ الطالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض السجل السلوكي الكامل لطالب محدد مع الرسم البياني
 */

$pageTitle = 'سجل السلوك';
$breadcrumb = ['السلوك' => 'index.php', 'سجل السلوك' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);

// جلب الفصل الدراسي الحالي
$semesterId = null;
try {
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $sem = $stmt->fetch();
    $semesterId = $sem ? $sem['id'] : null;
} catch (Exception $e) {}

// تحميل محسب السلوك
require_once __DIR__ . '/../../includes/behavior_calculator.php';
$behaviorCalc = new BehaviorCalculator($db);

// ============================================================
// جلب بيانات الطالب
// ============================================================
$studentId = (int)($_GET['student_id'] ?? 0);
$classId   = (int)($_GET['class_id'] ?? 0);

$student = null;
$behaviorHistory = null;
$incidents = [];
$runningScores = [];

if ($studentId) {
    try {
        // جلب بيانات الطالب
        $stmt = $db->prepare("
            SELECT s.*, c.name_ar AS class_name, c.id AS class_id
            FROM students s
            LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_active = 1 AND se.status = 'enrolled'
            LEFT JOIN classes c ON c.id = se.class_id
            WHERE s.id = ? AND s.is_active = 1
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if ($student && !$classId) {
            $classId = $student['class_id'];
        }

        // جلب السجل السلوكي الكامل
        if ($classId && $academicYearId && $semesterId) {
            $behaviorHistory = $behaviorCalc->getBehaviorHistory($studentId, $classId, $academicYearId, $semesterId);
            $incidents = $behaviorHistory['incidents'] ?? [];

            // حساب النقاط التراكمي لكل حادثة (للرسم البياني)
            $runningTotal = 100; // البداية
            $runningScores[] = ['date' => date('Y-m-d'), 'score' => 100, 'label' => 'البداية'];
            foreach (array_reverse($incidents) as $inc) {
                if ($inc['incident_type'] === 'positive') {
                    $runningTotal += (float)$inc['points'];
                } else {
                    $runningTotal -= (float)$inc['points'];
                }
                $runningTotal = max(0, min(120, $runningTotal));
                $runningScores[] = [
                    'date' => $inc['incident_date'],
                    'score' => round(min(100, ($runningTotal / 100) * 100), 1),
                    'label' => $inc['category_name']
                ];
            }
        }
    } catch (Exception $e) {
        $student = null;
    }
}

// جلب الفصول والطلاب للفلتر
$classes = [];
$studentsList = [];
try {
    $sql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY name_ar";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classes = $stmt->fetchAll();

    $sql = "SELECT id, student_number, full_name_ar FROM students WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY full_name_ar LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<!-- اختيار الطالب -->
<div class="section">
    <div class="card">
        <div class="card-body">
            <form method="GET" class="form-inline" style="gap:12px; flex-wrap:wrap;">
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الطالب:</label>
                    <select name="student_id" class="form-control" required style="min-width:250px;" onchange="this.form.submit()">
                        <option value="">-- اختر الطالب --</option>
                        <?php foreach ($studentsList as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= $studentId == $st['id'] ? 'selected' : '' ?>>
                            <?= e($st['full_name_ar']) ?> (<?= e($st['student_number']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الفصل:</label>
                    <select name="class_id" class="form-control" style="min-width:200px;">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $classId == $cls['id'] ? 'selected' : '' ?>>
                            <?= e($cls['name_ar']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">📋 عرض السجل</button>
            </form>
        </div>
    </div>
</div>

<?php if ($student && $behaviorHistory): ?>

<!-- معلومات الطالب -->
<div class="section">
    <div class="card">
        <div class="card-body">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <h3 style="margin:0 0 4px 0;"><?= e($student['full_name_ar']) ?></h3>
                    <div style="color:var(--text-muted); font-size:14px;">
                        رقم الطالب: <?= e($student['student_number']) ?> • 
                        <?= e($student['class_name'] ?? 'غير محدد') ?>
                        <?php if ($student['guardian_name']): ?>
                        • ولي الأمر: <?= e($student['guardian_name']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="text-align:center;">
                    <?php
                    $summary = $behaviorHistory['summary'];
                    $currentPct = $summary ? (float)$summary['current_percentage'] : 100;
                    $pctColor = $currentPct >= 60 ? 'success' : ($currentPct >= 50 ? 'warning' : 'danger');
                    ?>
                    <div style="font-size:36px; font-weight:700; color:var(--<?= $pctColor ?>);">
                        <?= number_format($currentPct, 1) ?>%
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">النسبة السلوكية</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- إحصائيات سريعة -->
<div class="section">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php if ($summary): ?>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:14px;">
                <div style="font-size:20px; font-weight:700;"><?= number_format($summary['starting_points'], 0) ?></div>
                <div style="font-size:11px; color:var(--text-muted);">النقاط الابتدائية</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:14px;">
                <div style="font-size:20px; font-weight:700; color:var(--success);">+<?= number_format($summary['earned_points'], 0) ?></div>
                <div style="font-size:11px; color:var(--text-muted);">نقاط مكتسبة</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:14px;">
                <div style="font-size:20px; font-weight:700; color:var(--danger);">-<?= number_format($summary['deducted_points'], 0) ?></div>
                <div style="font-size:11px; color:var(--text-muted);">نقاط مخصومة</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:14px;">
                <div style="font-size:20px; font-weight:700;">
                    <?= $behaviorHistory['positive_count'] ?> / <?= $behaviorHistory['negative_count'] ?>
                </div>
                <div style="font-size:11px; color:var(--text-muted);">إيجابي / سلبي</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- الرسم البياني -->
<?php if (count($runningScores) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center justify-between">
                <h3 class="card-title">📈 تطور النسبة السلوكية</h3>
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()">🖨️ طباعة التقرير</button>
            </div>
        </div>
        <div class="card-body">
            <canvas id="behaviorTimeChart" style="max-height:300px;"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- سجل الحوادث -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📜 سجل الحوادث السلوكية</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($incidents)): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">✅</div>
                <div style="font-size:16px; font-weight:600;">لا توجد حوادث سلوكية مسجلة</div>
                <p>الطالب لم تسجل عليه أي حوادث سلوكية بعد</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper" style="max-height:500px; overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>الفئة</th>
                            <th>النقاط</th>
                            <th>الوصف</th>
                            <th>المُبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $runningPoints = 100;
                        foreach ($incidents as $i => $inc):
                            $isPositive = $inc['incident_type'] === 'positive';
                            if ($isPositive) {
                                $runningPoints += (float)$inc['points'];
                            } else {
                                $runningPoints -= (float)$inc['points'];
                            }
                            $runningPct = round(min(100, (max(0, $runningPoints) / 100) * 100), 1);
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= formatDateAr($inc['incident_date'], 'short') ?></td>
                            <td>
                                <span class="badge badge-<?= $isPositive ? 'success' : 'danger' ?>">
                                    <?= $isPositive ? '✅ إيجابي' : '❌ سلبي' ?>
                                </span>
                            </td>
                            <td style="font-weight:600;"><?= e($inc['category_name']) ?></td>
                            <td>
                                <span style="font-weight:700; color:var(--<?= $isPositive ? 'success' : 'danger' ?>);">
                                    <?= $isPositive ? '+' : '-' ?><?= $inc['points'] ?>
                                </span>
                            </td>
                            <td>
                                <?= e($inc['description'] ?? '—') ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                                    النسبة التراكمية: <?= number_format($runningPct, 1) ?>%
                                </div>
                            </td>
                            <td style="font-size:13px;"><?= e($inc['reporter_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($studentId && !$student): ?>
<div class="section">
    <div style="text-align:center; padding:40px; color:var(--text-muted);">
        <div style="font-size:48px; margin-bottom:12px;">🔍</div>
        لم يتم العثور على الطالب المحدد
    </div>
</div>
<?php elseif (!$studentId): ?>
<div class="section">
    <div style="text-align:center; padding:40px; color:var(--text-muted);">
        <div style="font-size:48px; margin-bottom:12px;">📜</div>
        <div style="font-size:16px; font-weight:600;">اختر طالباً لعرض سجله السلوكي</div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     جافاسكربت - الرسم البياني
     ============================================================ -->
<?php
// تجهيز بيانات الرسم البياني
$chartLabels = [];
$chartData = [];
foreach ($runningScores as $rs) {
    $chartLabels[] = $rs['date'];
    $chartData[] = $rs['score'];
}
$chartLabelsJson = json_encode($chartLabels);
$chartDataJson = json_encode($chartData);

$pageJs = <<<JS
const ctx = document.getElementById('behaviorTimeChart');
if (ctx) {
    const labels = {$chartLabelsJson};
    const data = {$chartDataJson};
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'النسبة السلوكية',
                data: data,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointBackgroundColor: function(context) {
                    const value = context.parsed.y;
                    if (value >= 60) return '#198754';
                    if (value >= 50) return '#ffc107';
                    return '#dc3545';
                }
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    min: 0,
                    max: 100,
                    title: { display: true, text: 'النسبة المئوية', font: { family: 'Tahoma' } }
                },
                x: {
                    title: { display: true, text: 'التاريخ', font: { family: 'Tahoma' } }
                }
            },
            plugins: {
                legend: { labels: { font: { family: 'Tahoma' } } },
                title: { display: false },
                annotation: {
                    annotations: {
                        threshold: {
                            type: 'line',
                            yMin: 60,
                            yMax: 60,
                            borderColor: '#dc3545',
                            borderWidth: 2,
                            borderDash: [6, 6],
                            label: { content: 'الحد الأدنى (60%)', display: true, position: 'end' }
                        }
                    }
                }
            }
        }
    });
}
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
