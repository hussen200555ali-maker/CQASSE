<?php
/**
 * ============================================================
 * نقاط السلوك - نظرة عامة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض نقاط السلوك لجميع الطلاب في فصل محدد
 * مع ترتيب وتصنيف حسب النسبة المئوية
 */

$pageTitle = 'نقاط السلوك';
$breadcrumb = ['السلوك' => 'index.php', 'نقاط السلوك' => null];

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

// جلب الفصول — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql = "SELECT c.id, c.name_ar FROM classes c WHERE c.id IN ($ph) AND c.is_active = 1 ORDER BY c.name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($allowedIds);
            $classes = $stmt->fetchAll();
        }
    } else {
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
    }
} catch (Exception $e) {}

// ============================================================
// جلب نقاط السلوك
// ============================================================
$filterClass = $_GET['class_id'] ?? '';
$sortBy = $_GET['sort'] ?? 'percentage';

// فحص أمان: المعلم فقط لصفوفه المسندة
if ($userRole === 'teacher' && $filterClass) {
    $assignedClasses = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!in_array($filterClass, $assignedClasses)) {
        $filterClass = 0;
    }
}

$scores = [];
if ($filterClass && $academicYearId && $semesterId) {
    try {
        $sql = "SELECT e.*, s.full_name_ar AS student_name, s.student_number, c.name_ar AS class_name
                FROM evaluations e
                INNER JOIN students s ON s.id = e.student_id
                INNER JOIN classes c ON c.id = e.class_id
                WHERE e.eval_type = 'behavior_score' AND e.class_id = ? AND e.academic_year_id = ? AND e.semester_id = ?
                    AND e.is_active = 1 AND s.is_active = 1";
        $params = [$filterClass, $academicYearId, $semesterId];

        // ترتيب
        switch ($sortBy) {
            case 'percentage_asc': $sql .= " ORDER BY e.current_percentage ASC"; break;
            case 'percentage_desc': $sql .= " ORDER BY e.current_percentage DESC"; break;
            case 'deducted': $sql .= " ORDER BY e.deducted_points DESC"; break;
            case 'name': default: $sql .= " ORDER BY s.full_name_ar ASC"; break;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $scores = $stmt->fetchAll();
    } catch (Exception $e) {
        $scores = [];
    }
}

// إحصائيات
$stats = [
    'total'       => count($scores),
    'passing'     => 0,
    'failing'     => 0,
    'avg_rate'    => 0,
    'green'       => 0, // >= 60%
    'yellow'      => 0, // 50-59%
    'red'         => 0  // < 50%
];

$totalPct = 0;
foreach ($scores as $s) {
    $pct = (float)$s['current_percentage'];
    $totalPct += $pct;

    if ($s['is_passing']) {
        $stats['passing']++;
    } else {
        $stats['failing']++;
    }

    if ($pct >= 60) $stats['green']++;
    elseif ($pct >= 50) $stats['yellow']++;
    else $stats['red']++;
}

if ($stats['total'] > 0) {
    $stats['avg_rate'] = round($totalPct / $stats['total'], 1);
}
?>

<!-- إحصائيات سريعة -->
<?php if (!empty($scores)): ?>
<div class="section">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700;"><?= $stats['total'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">إجمالي الطلاب</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--success);"><?= $stats['passing'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">مستوون (≥60%)</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--danger);"><?= $stats['failing'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">دون المعيار (<60%)</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--primary);"><?= number_format($stats['avg_rate'], 1) ?>%</div>
                <div style="font-size:12px; color:var(--text-muted);">متوسط النسبة</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <h3 class="card-title">📊 نقاط السلوك</h3>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-light" onclick="window.print()">🖨️ طباعة</button>
                    <button type="button" class="btn btn-success" onclick="exportToExcel()">📥 تصدير</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <!-- فلاتر -->
            <form method="GET" class="form-inline" style="margin-bottom:20px; gap:12px; flex-wrap:wrap;">
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الفصل:</label>
                    <select name="class_id" class="form-control" required style="min-width:200px;">
                        <option value="">-- اختر الفصل --</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $filterClass == $cls['id'] ? 'selected' : '' ?>>
                            <?= e($cls['name_ar']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">ترتيب:</label>
                    <select name="sort" class="form-control" style="width:auto;">
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>بالاسم</option>
                        <option value="percentage_desc" <?= $sortBy === 'percentage_desc' ? 'selected' : '' ?>>النسبة (تنازلي)</option>
                        <option value="percentage_asc" <?= $sortBy === 'percentage_asc' ? 'selected' : '' ?>>النسبة (تصاعدي)</option>
                        <option value="deducted" <?= $sortBy === 'deducted' ? 'selected' : '' ?>>الأكثر خصماً</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">📊 عرض</button>
            </form>

            <?php if (!empty($scores)): ?>
            <!-- رسم بياني -->
            <div style="margin-bottom:24px; max-width:350px; margin-left:auto; margin-right:auto;">
                <canvas id="behaviorChart"></canvas>
            </div>

            <!-- جدول النقاط -->
            <div class="table-wrapper">
                <table class="data-table" id="scores-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الطالب</th>
                            <th style="text-align:center;">النقاط الابتدائية</th>
                            <th style="text-align:center;">المكتسبة (+)</th>
                            <th style="text-align:center;">المخصومة (-)</th>
                            <th style="text-align:center;">النسبة</th>
                            <th>الحالة</th>
                            <th>تفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scores as $i => $row):
                            $pct = (float)$row['current_percentage'];
                            $colorClass = $pct >= 60 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
                            $statusLabel = $pct >= 60 ? 'مقبول' : ($pct >= 50 ? 'تحذير' : 'خطر');
                            // حساب النقاط الحالية من الأعمدة المتوفرة
                            $currentPoints = (float)$row['starting_points'] + (float)$row['earned_points'] - (float)$row['deducted_points'];
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div style="font-weight:600;"><?= e($row['student_name']) ?></div>
                                <div style="font-size:11px; color:var(--text-muted);"><?= e($row['student_number']) ?></div>
                            </td>
                            <td style="text-align:center;"><?= number_format($row['starting_points'], 0) ?></td>
                            <td style="text-align:center; color:var(--success); font-weight:600;">+<?= number_format($row['earned_points'], 0) ?></td>
                            <td style="text-align:center; color:var(--danger); font-weight:600;">-<?= number_format($row['deducted_points'], 0) ?></td>
                            <td style="text-align:center;">
                                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                                    <div style="width:50px; height:6px; background:var(--bg-secondary); border-radius:3px; overflow:hidden;">
                                        <div style="height:100%; width:<?= min(100, $pct) ?>%; background:var(--<?= $colorClass ?>); border-radius:3px;"></div>
                                    </div>
                                    <span style="font-weight:700; color:var(--<?= $colorClass ?>); min-width:50px;">
                                        <?= number_format($pct, 1) ?>%
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?= statusBadge(
                                    $colorClass === 'success' ? 'active' : ($colorClass === 'warning' ? 'conditional' : 'critical'),
                                    $statusLabel
                                ) ?>
                            </td>
                            <td>
                                <a href="history.php?student_id=<?= $row['student_id'] ?>&class_id=<?= $filterClass ?>"
                                   class="btn btn-sm btn-info" title="عرض السجل">👁️</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($filterClass): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📊</div>
                <div style="font-size:16px; font-weight:600;">لا توجد سجلات سلوك لهذا الفصل</div>
                <p style="margin-top:8px;">يمكنك <a href="incident.php?class_id=<?= e($filterClass) ?>">تسجيل سلوك جديد</a> لإنشاء السجلات</p>
            </div>
            <?php else: ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📊</div>
                اختر الفصل لعرض نقاط السلوك
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     جافاسكربت
     ============================================================ -->
<?php
$pageJs = <<<JS
// رسم بياني لتوزيع نسب السلوك
const ctx = document.getElementById('behaviorChart');
if (ctx) {
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['مقبول (≥60%)', 'تحذير (50-59%)', 'خطر (<50%)'],
            datasets: [{
                data: [{$stats['green']}, {$stats['yellow']}, {$stats['red']}],
                backgroundColor: ['#198754', '#ffc107', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { family: 'Tahoma' } } },
                title: { display: true, text: 'توزيع نسب السلوك', font: { family: 'Tahoma', size: 16 } }
            }
        }
    });
}

window.exportToExcel = function() {
    const table = document.getElementById('scores-table');
    if (!table) return;
    let csv = '\\uFEFF';
    table.querySelectorAll('tr').forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        cells.forEach(cell => rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"'));
        csv += rowData.join(',') + '\\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'behavior_scores_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
