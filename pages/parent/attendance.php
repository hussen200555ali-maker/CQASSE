<?php
/**
 * ============================================================
 * EQAS - حضور أبناء ولي الأمر
 * نظام التقييم الشامل - عرض بيانات الحضور لجميع الأبناء
 * ============================================================
 * يعرض بيانات الحضور لجميع الأبناء:
 * - ملخص الحضور من attendance_summary
 * - آخر 30 يوم من attendance_daily
 * - عرض تقويمي للحضور
 * - ملخص شهري مع النسب
 * - تنبيه إذا انخفض الحضور عن 75%
 * ============================================================
 */

$pageTitle = 'حضور الأبناء';
$breadcrumb = ['لوحة ولي الأمر' => baseUrl('pages/dashboard/parent.php'), 'حضور الأبناء' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحية
if (!hasPermission('parent')) {
    setFlash('هذه الصفحة مخصصة لأولياء الأمور فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$parentId = $_SESSION['user']['id'];

// ═══════════════════════════════════════════
// جلب أبناء ولي الأمر
// ═══════════════════════════════════════════
$stmt = $db->prepare("
    SELECT s.id, s.full_name_ar, s.student_number, s.grade_level_id,
           gl.name_ar as grade_name, sc.name_ar as school_name,
           ps.relationship
    FROM parent_students ps
    JOIN students s ON ps.student_id = s.id
    JOIN grade_levels gl ON s.grade_level_id = gl.id
    JOIN schools sc ON s.school_id = sc.id
    WHERE ps.parent_id = ? AND ps.is_active = 1 AND s.is_active = 1
    ORDER BY gl.level_number, s.full_name_ar
");
$stmt->execute([$parentId]);
$children = $stmt->fetchAll();

// إذا لم يكن هناك أبناء
if (empty($children)):
?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center;padding:60px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">👨‍👩‍👧‍👦</div>
            <h3>لا يوجد طلاب مرتبطين بحسابك</h3>
            <p style="color:var(--text-secondary);margin-bottom:20px;">يرجى التواصل مع إدارة المدرسة.</p>
            <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-primary">
                <i class="fas fa-home"></i> العودة للرئيسية
            </a>
        </div>
    </div>
</div>
<?php
else:

$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// جلب البيانات لكل طفل
// ═══════════════════════════════════════════
$childrenAttendance = [];
foreach ($children as $child) {
    $sid = $child['id'];
    $data = [
        'summary' => null,
        'daily' => [],
        'monthly' => [],
        'low_alert' => false
    ];

    // 1. ملخص الحضور من attendance_summary
    try {
        $stmt = $db->prepare("
            SELECT ats.id, ats.total_days, ats.present_days, ats.absent_days,
                   ats.late_days, ats.excused_days, ats.attendance_rate,
                   ats.is_below_threshold, sem.name_ar as semester_name
            FROM attendance_summary ats
            LEFT JOIN semesters sem ON ats.semester_id = sem.id
            WHERE ats.student_id = ? AND ats.is_active = 1
            AND ats.academic_year_id = ?
            ORDER BY ats.semester_id DESC
            LIMIT 1
        ");
        $stmt->execute([$sid, $academicYearId]);
        $data['summary'] = $stmt->fetch();
        if ($data['summary'] && (float)$data['summary']['attendance_rate'] < 75) {
            $data['low_alert'] = true;
        }
    } catch (Exception $e) {}

    // 2. بيانات الحضور اليومي - آخر 30 يوم
    try {
        $stmt = $db->prepare("
            SELECT date, status
            FROM attendance_daily
            WHERE student_id = ? AND is_active = 1
            AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY date ASC
        ");
        $stmt->execute([$sid]);
        $dailyRows = $stmt->fetchAll();
        foreach ($dailyRows as $row) {
            $data['daily'][$row['date']] = $row['status'];
        }
    } catch (Exception $e) {}

    // 3. ملخص شهري
    try {
        $stmt = $db->prepare("
            SELECT
                DATE_FORMAT(date, '%Y-%m') as month_key,
                DATE_FORMAT(date, '%m/%Y') as month_label,
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count
            FROM attendance_daily
            WHERE student_id = ? AND is_active = 1
            AND academic_year_id = ?
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY month_key ASC
        ");
        $stmt->execute([$sid, $academicYearId]);
        $data['monthly'] = $stmt->fetchAll();
    } catch (Exception $e) {}

    $childrenAttendance[$sid] = $data;
}

$statusLabels = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بعذر'
];
$statusIcons = [
    'present' => '✓',
    'absent' => '✗',
    'late' => '⏰',
    'excused' => '!'
];
$statusColors = [
    'present' => ['bg' => 'rgba(40,167,69,0.15)', 'color' => '#155724', 'dot' => '#28a745'],
    'absent' => ['bg' => 'rgba(220,53,69,0.15)', 'color' => '#721c24', 'dot' => '#dc3545'],
    'late' => ['bg' => 'rgba(255,193,7,0.15)', 'color' => '#856404', 'dot' => '#ffc107'],
    'excused' => ['bg' => 'rgba(23,162,184,0.15)', 'color' => '#0c5460', 'dot' => '#17a2b8']
];

/**
 * لون نسبة الحضور
 */
function getAttendanceColor($rate) {
    if ($rate >= 90) return '#28a745';
    if ($rate >= 75) return '#ffc107';
    return '#dc3545';
}
function getAttendanceLabel($rate) {
    if ($rate >= 90) return 'ممتاز';
    if ($rate >= 75) return 'مقبول';
    return 'منخفض';
}
?>

<style>
.attendance-card {
    margin-bottom: 24px;
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    border: 1px solid var(--border-light, #e5e7eb);
}
.attendance-card .child-header {
    background: linear-gradient(135deg, #1a5632 0%, #1e6b3e 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.attendance-card .child-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}
.att-rate-ring {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 800;
    border: 5px solid;
    margin: 0 auto 8px;
}
.att-stat-box {
    text-align: center;
    padding: 12px 8px;
    border-radius: var(--radius-md, 6px);
    border: 1px solid var(--border-light, #e5e7eb);
}
.att-stat-box .stat-num {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 2px;
}
.att-stat-box .stat-label {
    font-size: 11px;
    color: var(--text-secondary, #666);
}
.cal-day {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: var(--radius-sm, 4px);
    font-size: 12px;
    margin: 2px;
    transition: all 0.2s;
    cursor: default;
}
.cal-day.present { background: rgba(40,167,69,0.15); color: #155724; font-weight:600; }
.cal-day.absent { background: rgba(220,53,69,0.15); color: #721c24; font-weight:600; }
.cal-day.late { background: rgba(255,193,7,0.15); color: #856404; font-weight:600; }
.cal-day.excused { background: rgba(23,162,184,0.15); color: #0c5460; font-weight:600; }
.cal-day.empty { background: transparent; color: var(--text-muted, #ccc); }
.cal-day.weekend { background: rgba(0,0,0,0.04); color: var(--text-muted, #aaa); }
.monthly-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light, #e5e7eb);
    flex-wrap: wrap;
}
.monthly-row:last-child {
    border-bottom: none;
}
.alert-box {
    padding: 14px 18px;
    border-radius: var(--radius-md, 6px);
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: rgba(220,53,69,0.08);
    border-right: 4px solid #dc3545;
}
.alert-box .alert-icon { font-size: 20px; flex-shrink: 0; }
.alert-box .alert-text { font-size: 14px; color: #721c24; }
.section-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border-light, #e5e7eb);
}
.section-title-row h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--eqas-green, #1a5632);
}
@media (max-width: 768px) {
    .att-rate-ring { width: 70px; height: 70px; font-size: 17px; }
    .cal-day { width: 28px; height: 28px; font-size: 10px; }
    .att-stat-box .stat-num { font-size: 18px; }
}
</style>

<div class="section">
    <!-- زر العودة -->
    <div style="margin-bottom:16px;">
        <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-outline-primary">
            <i class="fas fa-arrow-right"></i> العودة للرئيسية
        </a>
    </div>

    <?php foreach ($children as $child):
        $sid = $child['id'];
        $adata = $childrenAttendance[$sid];
        $summary = $adata['summary'];
        $attRate = $summary ? (float)$summary['attendance_rate'] : null;
        $attColor = $attRate !== null ? getAttendanceColor($attRate) : '#999';
        $attLabel = $attRate !== null ? getAttendanceLabel($attRate) : '';
    ?>
    <div class="attendance-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-user-clock" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div style="font-size:13px;opacity:0.85;">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                </div>
            </div>
            <?php if ($summary): ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;opacity:0.8;">الفصل:</span>
                <span style="font-size:13px;font-weight:600;"><?php echo e($summary['semester_name'] ?? '-'); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding:20px;">

            <!-- تنبيه انخفاض الحضور -->
            <?php if ($adata['low_alert']): ?>
            <div class="alert-box">
                <div class="alert-icon">🚨</div>
                <div class="alert-text">
                    <strong>تنبيه: نسبة الحضور منخفضة!</strong><br>
                    نسبة حضور الطالب أقل من 75%. يرجى متابعة الأمر مع إدارة المدرسة لضمان تحسين الحضور.
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 مؤشر الحضور والإحصائيات
                 ═══════════════════════════════════════════ -->
            <?php if ($summary): ?>
            <div style="text-align:center;margin-bottom:24px;">
                <div class="att-rate-ring" style="border-color:<?php echo $attColor; ?>;color:<?php echo $attColor; ?>;">
                    <?php echo number_format($attRate, 0); ?>%
                </div>
                <div style="font-size:18px;font-weight:700;color:<?php echo $attColor; ?>;"><?php echo $attLabel; ?></div>
                <div style="max-width:300px;margin:10px auto 0;">
                    <div style="width:100%;height:10px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                        <div style="width:<?php echo min(100, $attRate); ?>%;height:100%;background:<?php echo $attColor; ?>;border-radius:var(--radius-full,20px);transition:width 0.8s;"></div>
                    </div>
                </div>
            </div>

            <!-- بطاقات الإحصائيات -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-bottom:24px;">
                <div class="att-stat-box">
                    <div class="stat-num" style="color:var(--eqas-green,#1a5632);"><?php echo $summary['total_days']; ?></div>
                    <div class="stat-label">إجمالي الأيام</div>
                </div>
                <div class="att-stat-box">
                    <div class="stat-num" style="color:#28a745;"><?php echo $summary['present_days']; ?></div>
                    <div class="stat-label">أيام الحضور</div>
                </div>
                <div class="att-stat-box">
                    <div class="stat-num" style="color:#dc3545;"><?php echo $summary['absent_days']; ?></div>
                    <div class="stat-label">أيام الغياب</div>
                </div>
                <div class="att-stat-box">
                    <div class="stat-num" style="color:#ffc107;"><?php echo $summary['late_days']; ?></div>
                    <div class="stat-label">أيام التأخير</div>
                </div>
                <div class="att-stat-box">
                    <div class="stat-num" style="color:#17a2b8;"><?php echo $summary['excused_days']; ?></div>
                    <div class="stat-label">غياب بعذر</div>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted,#999);margin-bottom:16px;">
                <div style="font-size:40px;margin-bottom:8px;">📅</div>
                <p>لا توجد بيانات حضور لهذا الطالب بعد</p>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 تقويم الحضور - آخر 30 يوم
                 ═══════════════════════════════════════════ -->
            <?php if (!empty($adata['daily'])): ?>
            <div style="margin-bottom:24px;">
                <div class="section-title-row">
                    <h4><i class="fas fa-calendar-alt" style="margin-left:6px;"></i>تقويم الحضور - آخر 30 يوم</h4>
                </div>
                <?php
                // بناء تقويم آخر 30 يوم
                $endDate = new DateTime();
                $startDate = (clone $endDate)->modify('-29 days');
                $interval = new DateInterval('P1D');
                $period = new DatePeriod($startDate, $interval, $endDate->modify('+1 day'));

                $dayNames = ['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمع', 'سبت'];
                ?>
                <!-- دليل الألوان -->
                <div style="display:flex;gap:16px;justify-content:center;margin-bottom:12px;font-size:11px;flex-wrap:wrap;">
                    <?php foreach ($statusLabels as $sKey => $sLabel): ?>
                    <span style="display:flex;align-items:center;gap:4px;">
                        <span class="cal-day <?= $sKey ?>" style="width:18px;height:18px;font-size:9px;"><?= $statusIcons[$sKey] ?></span>
                        <?= $sLabel ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:2px;">
                    <?php foreach ($period as $day):
                        $dateStr = $day->format('Y-m-d');
                        $dayOfWeek = (int)$day->format('w');
                        $status = $adata['daily'][$dateStr] ?? null;
                        $isWeekend = ($dayOfWeek === 5); // الجمعة
                        $isToday = ($dateStr === date('Y-m-d'));

                        if ($isWeekend) {
                            $calClass = 'weekend';
                        } elseif ($status) {
                            $calClass = $status;
                        } else {
                            $calClass = 'empty';
                        }
                    ?>
                    <div class="cal-day <?= $calClass ?>"
                         style="<?= $isToday ? 'outline:2px solid var(--eqas-green,#1a5632);outline-offset:-2px;' : '' ?>"
                         title="<?= $dateStr ?><?= $status ? ' - ' . $statusLabels[$status] : '' ?>"
                    >
                        <?= $day->format('j') ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 ملخص شهري
                 ═══════════════════════════════════════════ -->
            <?php if (!empty($adata['monthly'])): ?>
            <div>
                <div class="section-title-row">
                    <h4><i class="fas fa-chart-bar" style="margin-left:6px;"></i>ملخص الحضور الشهري</h4>
                </div>
                <?php foreach ($adata['monthly'] as $mRow):
                    $mTotal = (int)$mRow['total_days'];
                    $mPresent = (int)$mRow['present_count'];
                    $mAbsent = (int)$mRow['absent_count'];
                    $mLate = (int)$mRow['late_count'];
                    $mExcused = (int)$mRow['excused_count'];
                    $mRate = $mTotal > 0 ? round(($mPresent / $mTotal) * 100, 1) : 0;
                    $mColor = getAttendanceColor($mRate);
                    $mLowAlert = $mRate < 75;
                ?>
                <div class="monthly-row">
                    <div style="min-width:80px;font-weight:600;font-size:13px;">
                        <?php echo e($mRow['month_label']); ?>
                        <?php if ($mLowAlert): ?>
                        <span style="color:#dc3545;font-size:10px;">⚠️</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:150px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span style="font-size:11px;color:var(--text-muted,#999);">
                                حاضر: <?= $mPresent ?> | غائب: <?= $mAbsent ?> | متأخر: <?= $mLate ?> | بعذر: <?= $mExcused ?>
                            </span>
                            <span style="font-size:13px;font-weight:700;color:<?= $mColor ?>;"><?= $mRate ?>%</span>
                        </div>
                        <div style="width:100%;height:8px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                            <div style="width:<?= min(100, $mRate) ?>%;height:100%;background:<?= $mColor ?>;border-radius:var(--radius-full,20px);transition:width 0.5s;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
