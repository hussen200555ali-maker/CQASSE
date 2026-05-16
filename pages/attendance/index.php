<?php
/**
 * ============================================================
 * الحضور والانضباط - لوحة المعلومات
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض إحصائيات الحضور السريعة، النشاط الأخير، والتنبيهات
 */

$pageTitle = 'الحضور والانضباط';
$breadcrumb = ['الحضور والانضباط' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - يجب أن يكون معلم على الأقل
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// الحصول على العام الدراسي والفصل الحالي
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = null;

try {
    // جلب الفصل الدراسي الحالي
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $currentSem = $stmt->fetch();
    if ($currentSem) {
        $semesterId = $currentSem['id'];
    }
} catch (Exception $e) {
    $semesterId = null;
}

// ============================================================
// إحصائيات سريعة
// ============================================================
$stats = [
    'attendance_rate' => 0,
    'absent_today'    => 0,
    'late_today'      => 0,
    'total_students'  => 0,
    'below_threshold' => 0
];

try {
    $today = date('Y-m-d');

    // إجمالي الطلاب النشطين في المدرسة
    $sql = "SELECT COUNT(DISTINCT s.id) FROM students s WHERE s.is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stats['total_students'] = (int)$stmt->fetchColumn();

    // الغائبون اليوم
    $sql = "SELECT COUNT(DISTINCT ad.student_id)
            FROM attendance_daily ad
            INNER JOIN students s ON s.id = ad.student_id
            WHERE ad.date = ? AND ad.status = 'absent' AND ad.is_active = 1 AND s.is_active = 1";
    $params = [$today];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stats['absent_today'] = (int)$stmt->fetchColumn();

    // المتأخرون اليوم
    $sql = "SELECT COUNT(DISTINCT ad.student_id)
            FROM attendance_daily ad
            INNER JOIN students s ON s.id = ad.student_id
            WHERE ad.date = ? AND ad.status = 'late' AND ad.is_active = 1 AND s.is_active = 1";
    $params = [$today];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stats['late_today'] = (int)$stmt->fetchColumn();

    // نسبة الحضور العامة
    if ($academicYearId && $semesterId) {
        $sql = "SELECT AVG(v.attendance_rate) as avg_rate
                FROM v_attendance_summary v
                INNER JOIN students s ON s.id = v.student_id
                WHERE v.academic_year_id = ? AND v.semester_id = ? AND s.is_active = 1";
        $params = [$academicYearId, $semesterId];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $avgRate = $stmt->fetchColumn();
        $stats['attendance_rate'] = $avgRate ? round((float)$avgRate, 1) : 0;
    }

    // عدد الطلاب تحت الحد الأدنى للحضور
    if ($academicYearId && $semesterId) {
        $sql = "SELECT COUNT(DISTINCT v.student_id)
                FROM v_attendance_summary v
                INNER JOIN students s ON s.id = v.student_id
                WHERE v.academic_year_id = ? AND v.semester_id = ?
                    AND v.is_below_threshold = 1 AND s.is_active = 1";
        $params = [$academicYearId, $semesterId];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $stats['below_threshold'] = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    // استخدام القيم الافتراضية
}

// ============================================================
// إعدادات الحضور للمدرسة
// ============================================================
$attendanceSettings = null;
if ($schoolId) {
    try {
        $stmt = $db->prepare("SELECT * FROM attendance_settings WHERE school_id = ? AND is_active = 1");
        $stmt->execute([$schoolId]);
        $attendanceSettings = $stmt->fetch();
    } catch (Exception $e) {
        $attendanceSettings = null;
    }
}
$minRate = $attendanceSettings ? (float)$attendanceSettings['min_attendance_rate'] : 75;
$consecutiveAlert = $attendanceSettings ? (int)$attendanceSettings['consecutive_absence_alert'] : 3;

// ============================================================
// النشاط الأخير للحضور
// ============================================================
$recentActivity = [];
try {
    $sql = "SELECT ad.*, s.full_name_ar AS student_name, s.student_number,
                   c.name_ar AS class_name
            FROM attendance_daily ad
            INNER JOIN students s ON s.id = ad.student_id
            INNER JOIN classes c ON c.id = ad.class_id
            WHERE ad.is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY ad.date DESC, ad.created_at DESC LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recentActivity = $stmt->fetchAll();
} catch (Exception $e) {
    $recentActivity = [];
}

// ============================================================
// تنبيهات الطلاب تحت الحد
// ============================================================
$alertStudents = [];
if ($academicYearId && $semesterId) {
    try {
        $sql = "SELECT v.*, s.full_name_ar AS student_name, s.student_number,
                       c.name_ar AS class_name
                FROM v_attendance_summary v
                INNER JOIN students s ON s.id = v.student_id
                INNER JOIN classes c ON c.id = v.class_id
                WHERE v.academic_year_id = ? AND v.semester_id = ?
                    AND v.is_below_threshold = 1 AND s.is_active = 1";
        $params = [$academicYearId, $semesterId];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY v.attendance_rate ASC LIMIT 15";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $alertStudents = $stmt->fetchAll();
    } catch (Exception $e) {
        $alertStudents = [];
    }
}

// جلب الفصول للفلتر
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
} catch (Exception $e) {
    $classes = [];
}

/**
 * ترجمة حالة الحضور
 */
function getAttendanceStatusAr($status) {
    $labels = [
        'present' => 'حاضر',
        'absent'  => 'غائب',
        'late'    => 'متأخر',
        'excused' => 'معذور'
    ];
    return $labels[$status] ?? $status;
}

/**
 * لون حالة الحضور
 */
function getAttendanceStatusColor($status) {
    $colors = [
        'present' => 'success',
        'absent'  => 'danger',
        'late'    => 'warning',
        'excused' => 'info'
    ];
    return $colors[$status] ?? 'secondary';
}
?>

<!-- ============================================================
     إحصائيات سريعة
     ============================================================ -->
<div class="section">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- نسبة الحضور -->
        <div class="card">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:36px; font-weight:700; color:var(--success);">
                    <?= number_format($stats['attendance_rate'], 1) ?>%
                </div>
                <div style="color:var(--text-secondary); margin-top:4px;">نسبة الحضور العامة</div>
            </div>
        </div>
        <!-- الغائبون اليوم -->
        <div class="card">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:36px; font-weight:700; color:var(--danger);">
                    <?= $stats['absent_today'] ?>
                </div>
                <div style="color:var(--text-secondary); margin-top:4px;">غائبون اليوم</div>
            </div>
        </div>
        <!-- المتأخرون اليوم -->
        <div class="card">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:36px; font-weight:700; color:var(--warning);">
                    <?= $stats['late_today'] ?>
                </div>
                <div style="color:var(--text-secondary); margin-top:4px;">متأخرون اليوم</div>
            </div>
        </div>
        <!-- تحت الحد الأدنى -->
        <div class="card">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:36px; font-weight:700; color:<?= $stats['below_threshold'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                    <?= $stats['below_threshold'] ?>
                </div>
                <div style="color:var(--text-secondary); margin-top:4px;">تحت الحد الأدنى (<?= $minRate ?>%)</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     روابط سريعة
     ============================================================ -->
<div class="section">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="daily.php" class="card" style="text-decoration:none; cursor:pointer; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:32px; margin-bottom:8px;">📋</div>
                <div style="font-weight:600; color:var(--text-primary);">تسجيل الحضور اليومي</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">تسجيل حضور وغياب الطلاب</div>
            </div>
        </a>
        <a href="period.php" class="card" style="text-decoration:none; cursor:pointer; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:32px; margin-bottom:8px;">🕐</div>
                <div style="font-weight:600; color:var(--text-primary);">الحضور بالحصص</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">للمدارس الثانوية</div>
            </div>
        </a>
        <a href="summary.php" class="card" style="text-decoration:none; cursor:pointer; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:32px; margin-bottom:8px;">📊</div>
                <div style="font-weight:600; color:var(--text-primary);">ملخص الحضور</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">تقارير وإحصائيات</div>
            </div>
        </a>
        <a href="notifications.php" class="card" style="text-decoration:none; cursor:pointer; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
            <div class="card-body" style="text-align:center; padding:20px;">
                <div style="font-size:32px; margin-bottom:8px;">🔔</div>
                <div style="font-weight:600; color:var(--text-primary);">إشعارات الغياب</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">تنبيهات أولياء الأمور</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================================
     تنبيهات الطلاب تحت الحد
     ============================================================ -->
<?php if (!empty($alertStudents)): ?>
<div class="section">
    <div class="card">
        <div class="card-header" style="background:var(--danger-light, #fff5f5); border-right:4px solid var(--danger);">
            <h3 class="card-title" style="color:var(--danger);">⚠️ تنبيه: طلاب تحت الحد الأدنى للحضور</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper" style="max-height:300px; overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الطالب</th>
                            <th>الفصل</th>
                            <th>أيام الحضور</th>
                            <th>أيام الغياب</th>
                            <th>نسبة الحضور</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alertStudents as $i => $alert):
                            $rate = (float)$alert['attendance_rate'];
                            $colorClass = $rate < 60 ? 'danger' : 'warning';
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div style="font-weight:600;"><?= e($alert['student_name']) ?></div>
                                <div style="font-size:11px; color:var(--text-muted);"><?= e($alert['student_number']) ?></div>
                            </td>
                            <td><?= e($alert['class_name']) ?></td>
                            <td><?= $alert['present_days'] ?></td>
                            <td style="color:var(--danger); font-weight:600;"><?= $alert['absent_days'] ?></td>
                            <td>
                                <span style="color:var(--<?= $colorClass ?>); font-weight:700;">
                                    <?= number_format($rate, 1) ?>%
                                </span>
                            </td>
                            <td>
                                <?= statusBadge($colorClass === 'danger' ? 'critical' : 'warning', $rate < 60 ? 'خطر' : 'تحذير') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     النشاط الأخير + إعدادات سريعة
     ============================================================ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- النشاط الأخير -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📝 آخر سجلات الحضور</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentActivity)): ?>
                    <div style="text-align:center; padding:40px; color:var(--text-muted);">
                        <div style="font-size:32px; margin-bottom:8px;">📭</div>
                        لا توجد سجلات حضور بعد
                    </div>
                <?php else: ?>
                    <div style="max-height:350px; overflow-y:auto;">
                        <?php foreach ($recentActivity as $activity):
                            $statusColor = getAttendanceStatusColor($activity['status']);
                            $statusAr = getAttendanceStatusAr($activity['status']);
                        ?>
                        <div style="padding:10px 16px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-weight:600; font-size:14px;"><?= e($activity['student_name']) ?></div>
                                <div style="font-size:12px; color:var(--text-muted);">
                                    <?= e($activity['class_name']) ?> • <?= formatDateAr($activity['date'], 'short') ?>
                                </div>
                            </div>
                            <span class="badge badge-<?= $statusColor ?>"><?= $statusAr ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- إعدادات سريعة -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⚙️ إعدادات الحضور</h3>
            </div>
            <div class="card-body">
                <?php if ($attendanceSettings): ?>
                <div style="margin-bottom:16px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-md);">
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                        <span>الحد الأدنى لنسبة الحضور:</span>
                        <strong><?= $attendanceSettings['min_attendance_rate'] ?>%</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                        <span>التأخير يُحسب كغياب:</span>
                        <strong><?= $attendanceSettings['late_count_as_absent'] ? 'نعم' : 'لا' ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                        <span>تنبيه الغياب المتتالي:</span>
                        <strong><?= $attendanceSettings['consecutive_absence_alert'] ?> أيام</strong>
                    </div>
                </div>
                <?php else: ?>
                <div style="padding:12px; background:var(--bg-warning, #fff3cd); border-radius:var(--radius-md); margin-bottom:16px; color:var(--warning);">
                    ⚠️ لم يتم تعيين إعدادات الحضور لهذه المدرسة. يتم استخدام القيم الافتراضية.
                </div>
                <?php endif; ?>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a href="settings.php" class="btn btn-primary">⚙️ إعدادات الحضور</a>
                    <a href="amend.php" class="btn btn-warning">✏️ تعديل سجلات</a>
                </div>

                <!-- الفصول المتاحة -->
                <div style="margin-top:20px;">
                    <h4 style="margin-bottom:8px; font-size:14px; color:var(--text-secondary);">الفصول المتاحة</h4>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        <?php foreach ($classes as $cls): ?>
                        <a href="daily.php?class_id=<?= $cls['id'] ?>" class="btn btn-sm btn-light" style="text-decoration:none;">
                            <?= e($cls['name_ar']) ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($classes)): ?>
                        <span style="color:var(--text-muted); font-size:13px;">لا توجد فصول متاحة</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
