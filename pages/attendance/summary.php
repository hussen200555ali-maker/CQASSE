<?php
/**
 * ============================================================
 * ملخص الحضور
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض ملخص الحضور لكل الطلاب مع الرسوم البيانية والتصدير
 */

$pageTitle = 'ملخص الحضور';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'ملخص الحضور' => null];

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

// ============================================================
// معالجة إعادة حساب الملخص
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $recalcClass = (int)($_POST['class_id'] ?? 0);
        $recalcSem   = (int)($_POST['semester_id'] ?? 0);
        $recalcYear  = (int)($_POST['academic_year_id'] ?? $academicYearId);

        if ($recalcClass && $recalcSem && $recalcYear) {
            try {
                // جلب جميع الطلاب في الفصل
                $stmt = $db->prepare("
                    SELECT DISTINCT ad.student_id
                    FROM attendance_daily ad
                    WHERE ad.class_id = ? AND ad.academic_year_id = ? AND ad.semester_id = ? AND ad.is_active = 1
                ");
                $stmt->execute([$recalcClass, $recalcYear, $recalcSem]);
                $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $recalcCount = 0;
                foreach ($studentIds as $sid) {
                    recalcStudentSummary($db, $sid, $recalcClass, $recalcYear, $recalcSem);
                    $recalcCount++;
                }
                setFlash("تم إعادة حساب ملخص الحضور لـ {$recalcCount} طالب", 'success');
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء إعادة الحساب', 'danger');
            }
        }
    }
}

/**
 * إعادة حساب ملخص حضور طالب
 */
function recalcStudentSummary($db, $studentId, $classId, $academicYearId, $semesterId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_days
            FROM attendance_daily
            WHERE student_id = ? AND class_id = ? AND academic_year_id = ? 
                AND semester_id = ? AND is_active = 1
        ");
        $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
        $c = $stmt->fetch();

        if (!$c || $c['total_days'] == 0) return;

        $rate = round((($c['present_days'] + $c['excused_days']) / $c['total_days']) * 100, 2);
        $minRate = 75;

        $stmt = $db->prepare("
            INSERT INTO attendance_summaries 
                (student_id, class_id, academic_year_id, semester_id, total_days,
                 present_days, absent_days, late_days, excused_days, attendance_rate, is_below_threshold, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                total_days = VALUES(total_days), present_days = VALUES(present_days),
                absent_days = VALUES(absent_days), late_days = VALUES(late_days),
                excused_days = VALUES(excused_days), attendance_rate = VALUES(attendance_rate),
                is_below_threshold = VALUES(is_below_threshold)
        ");
        $stmt->execute([
            $studentId, $classId, $academicYearId, $semesterId,
            $c['total_days'], $c['present_days'], $c['absent_days'], $c['late_days'], $c['excused_days'],
            $rate, $rate < $minRate ? 1 : 0
        ]);
    } catch (Exception $e) {
        error_log('EQAS recalcStudentSummary Error: ' . $e->getMessage());
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================
$filterClass   = $_GET['class_id'] ?? '';
$filterSemester = $_GET['semester_id'] ?? '';
$sortBy = $_GET['sort'] ?? 'name';

// جلب الفصول
$classes = [];
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
} catch (Exception $e) {}

// جلب الفصول الدراسية
$semesters = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب ملخص الحضور
$summaryData = [];
if ($filterClass && $filterSemester && $academicYearId) {
    try {
        $sql = "SELECT ats.*, s.full_name_ar AS student_name, s.student_number, c.name_ar AS class_name
                FROM attendance_summaries ats
                INNER JOIN students s ON s.id = ats.student_id
                INNER JOIN classes c ON c.id = ats.class_id
                WHERE ats.class_id = ? AND ats.semester_id = ? AND ats.academic_year_id = ?
                    AND ats.is_active = 1 AND s.is_active = 1";
        $params = [$filterClass, $filterSemester, $academicYearId];

        // ترتيب
        switch ($sortBy) {
            case 'rate_asc':  $sql .= " ORDER BY ats.attendance_rate ASC"; break;
            case 'rate_desc': $sql .= " ORDER BY ats.attendance_rate DESC"; break;
            case 'absent':    $sql .= " ORDER BY ats.absent_days DESC"; break;
            default:          $sql .= " ORDER BY s.full_name_ar ASC"; break;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $summaryData = $stmt->fetchAll();
    } catch (Exception $e) {
        $summaryData = [];
    }
}

// إحصائيات التوزيع للرسم البياني
$rateDistribution = [
    'green'  => 0,  // >= 75%
    'yellow' => 0,  // 60-74%
    'red'    => 0   // < 60%
];
foreach ($summaryData as $row) {
    $rate = (float)$row['attendance_rate'];
    if ($rate >= 75) $rateDistribution['green']++;
    elseif ($rate >= 60) $rateDistribution['yellow']++;
    else $rateDistribution['red']++;
}

// جلب إعداد الحد الأدنى
$minRate = 75;
if ($schoolId) {
    try {
        $stmt = $db->prepare("SELECT min_attendance_rate FROM attendance_settings WHERE school_id = ? AND is_active = 1");
        $stmt->execute([$schoolId]);
        $s = $stmt->fetchColumn();
        if ($s) $minRate = (float)$s;
    } catch (Exception $e) {}
}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <h3 class="card-title">📊 ملخص الحضور</h3>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-light" onclick="window.print()">🖨️ طباعة</button>
                    <button type="button" class="btn btn-success" onclick="exportToExcel()">📥 تصدير Excel</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <!-- فلاتر البحث -->
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
                    <label class="form-label" style="margin-left:8px;">الفصل الدراسي:</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">-- اختر --</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $filterSemester == $sem['id'] ? 'selected' : '' ?>>
                            <?= e($sem['name_ar']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">ترتيب:</label>
                    <select name="sort" class="form-control" style="width:auto;">
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>بالاسم</option>
                        <option value="rate_asc" <?= $sortBy === 'rate_asc' ? 'selected' : '' ?>>النسبة (تصاعدي)</option>
                        <option value="rate_desc" <?= $sortBy === 'rate_desc' ? 'selected' : '' ?>>النسبة (تنازلي)</option>
                        <option value="absent" <?= $sortBy === 'absent' ? 'selected' : '' ?>>الغياب (الأكثر)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">📊 عرض</button>
            </form>

            <?php if (!empty($summaryData)): ?>
            <!-- الرسم البياني -->
            <div style="margin-bottom:24px; max-width:400px; margin-left:auto; margin-right:auto;">
                <canvas id="attendanceChart"></canvas>
            </div>

            <!-- جدول الملخص -->
            <div class="table-wrapper">
                <table class="data-table" id="summary-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الطالب</th>
                            <th>إجمالي الأيام</th>
                            <th>حاضر</th>
                            <th>غائب</th>
                            <th>متأخر</th>
                            <th>معذور</th>
                            <th>نسبة الحضور</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryData as $i => $row):
                            $rate = (float)$row['attendance_rate'];
                            $rateColor = $rate >= 75 ? 'success' : ($rate >= 60 ? 'warning' : 'danger');
                            $statusLabel = $rate >= 75 ? 'مقبول' : ($rate >= 60 ? 'تحذير' : 'خطر');
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div style="font-weight:600;"><?= e($row['student_name']) ?></div>
                                <div style="font-size:11px; color:var(--text-muted);"><?= e($row['student_number']) ?></div>
                            </td>
                            <td><?= $row['total_days'] ?></td>
                            <td style="color:var(--success);"><?= $row['present_days'] ?></td>
                            <td style="color:var(--danger); font-weight:600;"><?= $row['absent_days'] ?></td>
                            <td style="color:var(--warning);"><?= $row['late_days'] ?></td>
                            <td style="color:var(--info);"><?= $row['excused_days'] ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="width:60px; height:8px; background:var(--bg-secondary); border-radius:4px; overflow:hidden;">
                                        <div style="height:100%; width:<?= min(100, $rate) ?>%; background:var(--<?= $rateColor ?>); border-radius:4px;"></div>
                                    </div>
                                    <span style="font-weight:700; color:var(--<?= $rateColor ?>);"><?= number_format($rate, 1) ?>%</span>
                                </div>
                            </td>
                            <td>
                                <?= statusBadge($rateColor === 'success' ? 'active' : ($rateColor === 'warning' ? 'conditional' : 'critical'), $statusLabel) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700; background:var(--bg-secondary);">
                            <td colspan="2">الإجمالي</td>
                            <td><?= array_sum(array_column($summaryData, 'total_days')) ?></td>
                            <td style="color:var(--success);"><?= array_sum(array_column($summaryData, 'present_days')) ?></td>
                            <td style="color:var(--danger);"><?= array_sum(array_column($summaryData, 'absent_days')) ?></td>
                            <td style="color:var(--warning);"><?= array_sum(array_column($summaryData, 'late_days')) ?></td>
                            <td style="color:var(--info);"><?= array_sum(array_column($summaryData, 'excused_days')) ?></td>
                            <td>
                                <?php
                                $avgRate = count($summaryData) > 0
                                    ? round(array_sum(array_column($summaryData, 'attendance_rate')) / count($summaryData), 1)
                                    : 0;
                                ?>
                                <span style="font-weight:700;"><?= number_format($avgRate, 1) ?>%</span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- نموذج إعادة الحساب -->
            <div style="margin-top:20px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-md);">
                <form method="POST" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="class_id" value="<?= e($filterClass) ?>">
                    <input type="hidden" name="semester_id" value="<?= e($filterSemester) ?>">
                    <input type="hidden" name="academic_year_id" value="<?= e($academicYearId) ?>">
                    <button type="submit" name="recalculate" class="btn btn-sm btn-warning"
                            onclick="return confirm('هل تريد إعادة حساب ملخص الحضور لجميع الطلاب؟')">
                        🔄 إعادة حساب الملخص
                    </button>
                    <span style="font-size:12px; color:var(--text-muted); margin-right:8px;">
                        الحد الأدنى المطلوب: <?= $minRate ?>%
                    </span>
                </form>
            </div>

            <?php elseif ($filterClass && $filterSemester): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📭</div>
                لا توجد بيانات حضور لهذا الفصل في الفصل الدراسي المحدد
            </div>
            <?php else: ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📊</div>
                اختر الفصل والفصل الدراسي لعرض ملخص الحضور
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     جافاسكربت - الرسم البياني والتصدير
     ============================================================ -->
<?php
$pageJs = <<<JS
// رسم بياني لتوزيع نسب الحضور
const ctx = document.getElementById('attendanceChart');
if (ctx) {
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['مقبول (≥75%)', 'تحذير (60-74%)', 'خطر (<60%)'],
            datasets: [{
                data: [{$rateDistribution['green']}, {$rateDistribution['yellow']}, {$rateDistribution['red']}],
                backgroundColor: ['#198754', '#ffc107', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { family: 'Tahoma' } } },
                title: { display: true, text: 'توزيع نسب الحضور', font: { family: 'Tahoma', size: 16 } }
            }
        }
    });
}

// تصدير إلى Excel (CSV)
function exportToExcel() {
    const table = document.getElementById('summary-table');
    if (!table) return;
    
    let csv = '\uFEFF'; // BOM للعربية
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const rowData = [];
        cells.forEach(cell => {
            rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv += rowData.join(',') + '\\n';
    });
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'attendance_summaries_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
