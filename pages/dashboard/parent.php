<?php
/**
 * ============================================================
 * EQAS - لوحة ولي الأمر
 * نظام التقييم الشامل - Parent Dashboard with Live Alerts
 * ============================================================
 * لوحة تحكم ولي الأمر مع:
 * - اختيار الطالب (إذا كان لديه أكثر من طالب)
 * - تنبيهات فورية مرمزة بالألوان حسب الخطورة
 * - ملخص أداء الطالب (أكاديمي، سلوك، حضور، ترفيع)
 * - الإقرارات المعلقة
 * - رسم بياني لاتجاه الدرجات
 * - تقويم الحضور الشهري
 * - مؤشر درجة السلوك البصري
 * - الفعاليات والاختبارات القادمة
 * - مقارنة بين الأبناء
 * - إعلانات المدرسة
 * - تحديث تلقائي كل 60 ثانية
 * - تصميم متجاوب للجوال
 * ============================================================
 */

$pageTitle = 'لوحة ولي الأمر';
$breadcrumb = ['لوحة ولي الأمر' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الدور
if (!hasPermission('parent')) {
    setFlash('هذه الصفحة مخصصة لأولياء الأمور فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$userId = $_SESSION['user']['id'];

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
$stmt->execute([$userId]);
$children = $stmt->fetchAll();

// اختيار الطالب الحالي
$selectedStudentId = $_GET['student_id'] ?? '';
if ($selectedStudentId) {
    $selectedStudentId = (int)$selectedStudentId;
} elseif (!empty($children)) {
    $selectedStudentId = $children[0]['id'];
}

// بيانات الطالب المحدد
$currentChild = null;
if ($selectedStudentId) {
    foreach ($children as $child) {
        if ($child['id'] == $selectedStudentId) {
            $currentChild = $child;
            break;
        }
    }
}

// ═══════════════════════════════════════════
// جلب التنبيهات الفورية
// ═══════════════════════════════════════════
$alerts = [];
$unreadAlertCount = 0;
if ($currentChild) {
    // جلب التنبيهات من جدول alerts
    $stmt = $db->prepare("
        SELECT id, alert_type, severity, title_ar, message_ar, created_at, is_read
        FROM alerts
        WHERE student_id = ? AND is_resolved = 0
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$selectedStudentId]);
    $alerts = $stmt->fetchAll();

    // جلب إشعارات الغياب (إذا كان الجدول موجوداً)
    $absenceNotifs = [];
    try {
        $stmt = $db->prepare("
            SELECT 'absence' as alert_type, 'warning' as severity,
                   CONCAT('غياب متتالي: ', days_absent, ' يوم') as title_ar,
                   CONCAT('تم تسجيل غياب الطالب لمدة ', days_absent, ' يوم متتالي') as message_ar,
                   notification_date as created_at, is_read
            FROM absence_notifications
            WHERE student_id = ? AND is_active = 1
            ORDER BY notification_date DESC
            LIMIT 10
        ");
        $stmt->execute([$selectedStudentId]);
        $absenceNotifs = $stmt->fetchAll();
    } catch (Exception $e) {}

    // دمج الإشعارات
    $alerts = array_merge($alerts, $absenceNotifs);

    // ترتيب زمني (الأحدث أولاً)
    usort($alerts, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // عدد غير المقروءة
    $unreadAlertCount = count(array_filter($alerts, function($a) { return empty($a['is_read']); }));

    // تصفية حسب النوع
    $filterSeverity = $_GET['severity'] ?? '';
    if ($filterSeverity) {
        $alerts = array_filter($alerts, function($a) use ($filterSeverity) {
            return ($a['severity'] ?? 'info') === $filterSeverity;
        });
    }
}

// ═══════════════════════════════════════════
// ملخص أداء الطالب
// ═══════════════════════════════════════════
$academicData = null;
$behaviorScore = null;
$attendanceRate = null;
$promotionStatus = null;

if ($currentChild) {
    $academicYearId = currentAcademicYearId();

    // متوسط الدرجات الأكاديمية
    try {
        $stmt = $db->prepare("
            SELECT AVG(g.percentage) as avg_percentage
            FROM grades g
            JOIN tests t ON g.test_id = t.id
            WHERE g.student_id = ? AND g.is_active = 1
            AND t.academic_year_id = ?
        ");
        $stmt->execute([$selectedStudentId, $academicYearId]);
        $academicData = $stmt->fetch();
    } catch (Exception $e) {
        $academicData = null;
    }

    // درجة السلوك
    try {
        $stmt = $db->prepare("
            SELECT be.percentage as behavior_pct
            FROM behavior_scores be
            WHERE be.student_id = ? AND be.is_active = 1
            ORDER BY be.evaluated_at DESC LIMIT 1
        ");
        $stmt->execute([$selectedStudentId]);
        $behaviorRow = $stmt->fetch();
        $behaviorScore = $behaviorRow ? $behaviorRow['behavior_pct'] : null;
    } catch (Exception $e) {
        $behaviorScore = null;
    }

    // نسبة الحضور
    try {
        $stmt = $db->prepare("
            SELECT attendance_rate
            FROM v_attendance_summary
            WHERE student_id = ? AND is_active = 1
            ORDER BY academic_year_id DESC, semester_id DESC LIMIT 1
        ");
        $stmt->execute([$selectedStudentId]);
        $attRow = $stmt->fetch();
        $attendanceRate = $attRow ? $attRow['attendance_rate'] : null;
    } catch (Exception $e) {
        $attendanceRate = null;
    }

    // حالة الترفيع
    try {
        $stmt = $db->prepare("
            SELECT decision, status
            FROM promotions
            WHERE student_id = ? AND is_active = 1
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$selectedStudentId]);
        $promoRow = $stmt->fetch();
        $promotionStatus = $promoRow ? $promoRow : null;
    } catch (Exception $e) {
        $promotionStatus = null;
    }
}

// ═══════════════════════════════════════════
// الإقرارات المعلقة
// ═══════════════════════════════════════════
$pendingAcknowledgments = [];
if ($currentChild) {
    try {
        $stmt = $db->prepare("
            SELECT ps.id, s.full_name_ar as student_name,
                   ps.ack_grade, ps.ack_behavior, ps.ack_attendance, ps.ack_promotion,
                   ps.last_ack_at, ps.created_at
            FROM parent_students ps
            JOIN students s ON s.id = ps.student_id
            WHERE ps.parent_id = ? AND ps.is_active = 1 AND s.is_active = 1
            AND (ps.ack_grade = 0 OR ps.ack_behavior = 0 OR ps.ack_attendance = 0 OR ps.ack_promotion = 0)
            ORDER BY ps.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $pendingAcknowledgments = $stmt->fetchAll();
    } catch (Exception $e) {
        $pendingAcknowledgments = [];
    }
}

// ═══════════════════════════════════════════
// الإشعارات الأخيرة
// ═══════════════════════════════════════════
$recentNotifications = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM notifications
        WHERE user_id = ? AND is_active = 1
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentNotifications = $stmt->fetchAll();
} catch (Exception $e) {
    $recentNotifications = [];
}

// ═══════════════════════════════════════════
// اتجاه الدرجات (خط زمني)
// ═══════════════════════════════════════════
$gradesTrend = ['labels' => [], 'values' => []];
if ($currentChild) {
    try {
        $stmt = $db->prepare("
            SELECT t.title_ar, t.test_date, g.percentage
            FROM grades g
            JOIN tests t ON g.test_id = t.id
            WHERE g.student_id = ? AND g.is_active = 1 AND t.academic_year_id = ?
            ORDER BY t.test_date ASC
            LIMIT 20
        ");
        $stmt->execute([$selectedStudentId, $academicYearId]);
        while ($row = $stmt->fetch()) {
            $gradesTrend['labels'][] = $row['title_ar'];
            $gradesTrend['values'][] = round($row['percentage'], 1);
        }
    } catch (Exception $e) { }
}

// ═══════════════════════════════════════════
// تقويم الحضور الشهري (الشهر الحالي)
// ═══════════════════════════════════════════
$attendanceCalendar = [];
if ($currentChild) {
    try {
        $stmt = $db->prepare("
            SELECT date, status
            FROM attendance_daily
            WHERE student_id = ? AND is_active = 1
            AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE())
            ORDER BY date ASC
        ");
        $stmt->execute([$selectedStudentId]);
        while ($row = $stmt->fetch()) {
            $attendanceCalendar[$row['date']] = $row['status'];
        }
    } catch (Exception $e) { }
}

// ═══════════════════════════════════════════
// الاختبارات والفعاليات القادمة
// ═══════════════════════════════════════════
$upcomingEvents = [];
if ($currentChild) {
    try {
        $stmt = $db->prepare("
            SELECT t.title_ar, t.test_date, s.name_ar as subject_name, t.max_grade
            FROM tests t
            JOIN subjects s ON t.subject_id = s.id
            JOIN student_enrollments se ON se.class_id = t.class_id
            WHERE se.student_id = ? AND se.is_active = 1 AND t.is_active = 1
            AND t.academic_year_id = ? AND t.test_date >= CURDATE()
            ORDER BY t.test_date ASC
            LIMIT 8
        ");
        $stmt->execute([$selectedStudentId, $academicYearId]);
        $upcomingEvents = $stmt->fetchAll();
    } catch (Exception $e) { }
}

// ═══════════════════════════════════════════
// مقارنة بين الأبناء
// ═══════════════════════════════════════════
$childComparison = [];
if (count($children) > 1) {
    foreach ($children as $child) {
        $cId = $child['id'];
        $cData = ['name' => $child['full_name_ar'], 'academic' => 0, 'behavior' => 100, 'attendance' => 100];
        try {
            $stmt = $db->prepare("SELECT AVG(g.percentage) as avg_pct FROM grades g JOIN tests t ON g.test_id = t.id WHERE g.student_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
            $stmt->execute([$cId, $academicYearId]);
            $r = $stmt->fetch();
            if ($r && $r['avg_pct'] !== null) $cData['academic'] = round($r['avg_pct'], 1);
        } catch (Exception $e) {}
        try {
            $stmt = $db->prepare("SELECT current_percentage as behavior_pct FROM behavior_scores WHERE student_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cId]);
            $r = $stmt->fetch();
            if ($r) $cData['behavior'] = round($r['behavior_pct'], 1);
        } catch (Exception $e) {}
        try {
            $stmt = $db->prepare("SELECT attendance_rate FROM v_attendance_summary WHERE student_id = ? AND is_active = 1 ORDER BY academic_year_id DESC, semester_id DESC LIMIT 1");
            $stmt->execute([$cId]);
            $r = $stmt->fetch();
            if ($r && $r['attendance_rate'] !== null) $cData['attendance'] = round($r['attendance_rate'], 1);
        } catch (Exception $e) {}
        $childComparison[] = $cData;
    }
}

// ═══════════════════════════════════════════
// إعلانات المدرسة
// ═══════════════════════════════════════════
$schoolAnnouncements = [];
if ($currentChild) {
    try {
        $stmt = $db->prepare("
            SELECT title_ar, message_ar, created_at
            FROM notifications
            WHERE user_id = ? AND type = 'info' AND is_active = 1
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $schoolAnnouncements = $stmt->fetchAll();
    } catch (Exception $e) { }
}

// ألوان الخطورة
$severityColors = [
    'critical' => ['bg' => '#ffebee', 'border' => '#c62828', 'icon' => '🚨', 'text' => '#b71c1c'],
    'danger'   => ['bg' => '#fff3e0', 'border' => '#e65100', 'icon' => '⚠️', 'text' => '#bf360c'],
    'warning'  => ['bg' => '#fff8e1', 'border' => '#f57f17', 'icon' => '⚡', 'text' => '#e65100'],
    'info'     => ['bg' => '#e3f2fd', 'border' => '#1565c0', 'icon' => 'ℹ️', 'text' => '#0d47a1'],
];
$severityLabels = ['critical' => 'حرج', 'danger' => 'خطر', 'warning' => 'تحذير', 'info' => 'معلومات'];
?>

<style>
/* تنسيقات خاصة بلوحة ولي الأمر */
.alert-card {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    border-right: 4px solid;
    margin-bottom: 8px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: transform var(--transition-fast);
    cursor: pointer;
}
.alert-card:hover {
    transform: translateX(-4px);
    box-shadow: var(--shadow-sm);
}
.alert-card .alert-icon {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}
.alert-card .alert-body {
    flex: 1;
}
.alert-card .alert-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
}
.alert-card .alert-message {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 4px;
}
.alert-card .alert-time {
    font-size: 11px;
    color: var(--text-muted);
}
.stat-card {
    text-align: center;
    padding: 20px 16px;
    border-radius: var(--radius-lg);
    background: var(--surface);
    border: 1px solid var(--border-light);
    transition: all var(--transition-base);
}
.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.stat-card .stat-icon {
    font-size: 28px;
    margin-bottom: 8px;
}
.stat-card .stat-value {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 4px;
}
.stat-card .stat-label {
    font-size: 13px;
    color: var(--text-secondary);
}
.severity-filter-btn {
    padding: 6px 16px;
    border-radius: var(--radius-full);
    border: 1px solid var(--border);
    background: var(--surface);
    cursor: pointer;
    font-size: 13px;
    transition: all var(--transition-fast);
}
.severity-filter-btn:hover, .severity-filter-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
.child-selector {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.child-tab {
    padding: 8px 20px;
    border-radius: var(--radius-full);
    border: 2px solid var(--border);
    background: var(--surface);
    cursor: pointer;
    font-size: 14px;
    transition: all var(--transition-fast);
}
.child-tab:hover {
    border-color: var(--primary-light);
}
.child-tab.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
.behavior-indicator {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}
.behavior-ring {
    width: 80px; height: 80px;
    border-radius: var(--radius-full);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 700;
    border: 5px solid;
}
.cal-day {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px; height: 32px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    margin: 2px;
    transition: all var(--transition-fast);
}
.cal-day.present { background: rgba(40,167,69,0.2); color: #155724; }
.cal-day.absent { background: rgba(220,53,69,0.2); color: #721c24; }
.cal-day.late { background: rgba(255,193,7,0.2); color: #856404; }
.cal-day.excused { background: rgba(23,162,184,0.2); color: #0c5460; }
.cal-day.empty { background: transparent; color: var(--text-muted); }
@media (max-width: 768px) {
    .stat-card .stat-value { font-size: 22px; }
    .alert-card { padding: 10px 12px; }
}
</style>

<div class="section">
    <?php if (empty($children)): ?>
    <!-- لا يوجد أبناء مسجلين -->
    <div class="card">
        <div class="card-body" style="text-align:center;padding:60px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">👨‍👩‍👧‍👦</div>
            <h3>لا يوجد أبناء مسجلين</h3>
            <p style="color:var(--text-secondary);">لم يتم ربط حسابك بأي طالب بعد. يرجى التواصل مع إدارة المدرسة.</p>
        </div>
    </div>
    <?php else: ?>

    <!-- ═══════════════════════════════════════════
         اختيار الطالب
         ═══════════════════════════════════════════ -->
    <?php if (count($children) > 1): ?>
    <div class="child-selector">
        <?php foreach ($children as $child): ?>
        <a href="?student_id=<?= $child['id'] ?>"
           class="child-tab <?= $child['id'] == $selectedStudentId ? 'active' : '' ?>">
            👤 <?= e($child['full_name_ar']) ?>
            <span style="font-size:11px;opacity:0.8;"><?= e($child['grade_name']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($currentChild): ?>

    <!-- ═══════════════════════════════════════════
         قسم التنبيهات الفورية
         ═══════════════════════════════════════════ -->
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <h3 class="card-title">🔔 التنبيهات الفورية</h3>
                <?php if ($unreadAlertCount > 0): ?>
                <span style="background:var(--accent);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:12px;font-weight:bold;">
                    <?= $unreadAlertCount ?> جديد
                </span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <a href="?student_id=<?= $selectedStudentId ?>" class="severity-filter-btn <?= !$filterSeverity ? 'active' : '' ?>">الكل</a>
                <a href="?student_id=<?= $selectedStudentId ?>&severity=critical" class="severity-filter-btn <?= $filterSeverity === 'critical' ? 'active' : '' ?>">🚨 حرج</a>
                <a href="?student_id=<?= $selectedStudentId ?>&severity=warning" class="severity-filter-btn <?= $filterSeverity === 'warning' ? 'active' : '' ?>">⚡ تحذير</a>
                <a href="?student_id=<?= $selectedStudentId ?>&severity=info" class="severity-filter-btn <?= $filterSeverity === 'info' ? 'active' : '' ?>">ℹ️ معلومات</a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($alerts)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <div style="font-size:40px;margin-bottom:8px;">✅</div>
                <p>لا توجد تنبيهات حالياً</p>
            </div>
            <?php else: ?>
            <div style="max-height:400px;overflow-y:auto;" id="alerts-container">
                <?php foreach ($alerts as $alert):
                    $severity = $alert['severity'] ?? 'info';
                    $sc = $severityColors[$severity] ?? $severityColors['info'];
                ?>
                <div class="alert-card" style="background:<?= $sc['bg'] ?>;border-right-color:<?= $sc['border'] ?>;">
                    <div class="alert-icon"><?= $sc['icon'] ?></div>
                    <div class="alert-body">
                        <div class="alert-title" style="color:<?= $sc['text'] ?>;">
                            <?= e($alert['title_ar'] ?? getAlertTypeLabel($alert['alert_type'] ?? '')) ?>
                            <span style="font-size:10px;padding:1px 6px;border-radius:var(--radius-full);background:<?= $sc['border'] ?>;color:white;margin-right:6px;">
                                <?= $severityLabels[$severity] ?? '' ?>
                            </span>
                        </div>
                        <div class="alert-message"><?= e($alert['message_ar'] ?? '') ?></div>
                        <div class="alert-time"><?= formatDateAr($alert['created_at'], 'medium') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         بطاقات ملخص أداء الطالب
         ═══════════════════════════════════════════ -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));">
        <!-- الأداء الأكاديمي -->
        <div class="stat-card" style="border-top:4px solid var(--primary);">
            <div class="stat-icon">📊</div>
            <div class="stat-value" style="color:var(--primary);">
                <?= $academicData && $academicData['avg_percentage'] !== null ? number_format($academicData['avg_percentage'], 1) . '%' : '-' ?>
            </div>
            <div class="stat-label">الأداء الأكاديمي</div>
            <div style="margin-top:8px;">
                <?php if ($academicData && $academicData['avg_percentage'] !== null):
                    $avg = $academicData['avg_percentage'];
                    $barColor = $avg >= 70 ? 'var(--success)' : ($avg >= 50 ? 'var(--warning)' : 'var(--danger)');
                ?>
                <div style="width:100%;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= min(100, $avg) ?>%;height:100%;background:<?= $barColor ?>;border-radius:var(--radius-full);transition:width var(--transition-slow);"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- درجة السلوك -->
        <div class="stat-card" style="border-top:4px solid var(--secondary);">
            <div class="stat-icon">📋</div>
            <div class="stat-value" style="color:var(--secondary);">
                <?= $behaviorScore !== null ? number_format($behaviorScore, 1) . '%' : '-' ?>
            </div>
            <div class="stat-label">درجة السلوك</div>
            <div style="margin-top:8px;">
                <?php if ($behaviorScore !== null):
                    $barColor = $behaviorScore >= 70 ? 'var(--success)' : ($behaviorScore >= 50 ? 'var(--warning)' : 'var(--danger)');
                ?>
                <div style="width:100%;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= min(100, $behaviorScore) ?>%;height:100%;background:<?= $barColor ?>;border-radius:var(--radius-full);"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- نسبة الحضور -->
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-icon">📅</div>
            <div class="stat-value" style="color:var(--info);">
                <?= $attendanceRate !== null ? number_format($attendanceRate, 1) . '%' : '-' ?>
            </div>
            <div class="stat-label">نسبة الحضور</div>
            <div style="margin-top:8px;">
                <?php if ($attendanceRate !== null):
                    $barColor = $attendanceRate >= 85 ? 'var(--success)' : ($attendanceRate >= 70 ? 'var(--warning)' : 'var(--danger)');
                ?>
                <div style="width:100%;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= min(100, $attendanceRate) ?>%;height:100%;background:<?= $barColor ?>;border-radius:var(--radius-full);"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- حالة الترفيع -->
        <div class="stat-card" style="border-top:4px solid <?= $promotionStatus ? ($promotionStatus['decision'] === 'promoted' ? 'var(--success)' : ($promotionStatus['decision'] === 'repeated' ? 'var(--danger)' : 'var(--warning)')) : 'var(--border)' ?>;">
            <div class="stat-icon">🎓</div>
            <div class="stat-value" style="font-size:18px;">
                <?php
                if ($promotionStatus) {
                    $decisionLabels = ['promoted' => 'مرفع', 'repeated' => 'معيد', 'conditional' => 'مشروط', 'under_review' => 'قيد المراجعة'];
                    echo e($decisionLabels[$promotionStatus['decision']] ?? $promotionStatus['decision']);
                } else {
                    echo '-';
                }
                ?>
            </div>
            <div class="stat-label">حالة الترفيع</div>
            <div style="margin-top:8px;">
                <?php if ($promotionStatus):
                    $statusLabel = $promotionStatus['status'] === 'approved' ? 'معتمد ✅' : ($promotionStatus['status'] === 'rejected' ? 'مرفوض ❌' : 'مقترح ⏳');
                ?>
                <span style="font-size:11px;color:var(--text-muted);"><?= $statusLabel ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         الإقرارات المعلقة
         ═══════════════════════════════════════════ -->
    <?php if (!empty($pendingAcknowledgments)): ?>
    <div class="card mb-6" style="border-right:4px solid var(--warning);">
        <div class="card-header">
            <h3 class="card-title">📝 الإقرارات المعلقة <span style="background:var(--warning);color:var(--text-primary);padding:1px 8px;border-radius:var(--radius-full);font-size:11px;"><?= count($pendingAcknowledgments) ?></span></h3>
        </div>
        <div class="card-body">
            <?php foreach ($pendingAcknowledgments as $ack): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border-light);">
                <div>
                    <strong>إقرار قرار ترفيع</strong><br>
                    <span style="font-size:12px;color:var(--text-muted);">تاريخ الإرسال: <?= formatDateAr($ack['created_at'], 'medium') ?></span>
                </div>
                <a href="../parent_ack/acknowledge.php?id=<?= $ack['id'] ?>" class="btn btn-primary btn-sm">عرض والإقرار</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         اتجاه الدرجات + مؤشر السلوك
         ═══════════════════════════════════════════ -->
    <div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- رسم بياني لاتجاه الدرجات -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📈 اتجاه الدرجات</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <?php if (!empty($gradesTrend['labels'])): ?>
                <canvas id="gradesTrendChart"></canvas>
                <?php else: ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:8px;">📈</div>
                    <p>لا توجد درجات مسجلة بعد</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- مؤشر درجة السلوك البصري -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 مؤشر السلوك</h3>
            </div>
            <div class="card-body" style="text-align:center;">
                <?php if ($behaviorScore !== null):
                    $behColor = $behaviorScore >= 80 ? 'var(--success)' : ($behaviorScore >= 60 ? 'var(--warning)' : 'var(--danger)');
                    $behLabel = $behaviorScore >= 80 ? 'ممتاز' : ($behaviorScore >= 60 ? 'مقبول' : 'يحتاج تحسين');
                    $behIcon = $behaviorScore >= 80 ? '😊' : ($behaviorScore >= 60 ? '😐' : '😟');
                ?>
                <div style="margin-bottom:16px;">
                    <div class="behavior-ring" style="border-color:<?= $behColor ?>;color:<?= $behColor ?>;">
                        <?= number_format($behaviorScore, 0) ?>%
                    </div>
                </div>
                <div style="font-size:24px;"><?= $behIcon ?></div>
                <div style="font-size:18px;font-weight:600;color:<?= $behColor ?>;margin-top:4px;"><?= $behLabel ?></div>
                <div style="margin-top:16px;">
                    <div style="width:100%;height:10px;background:var(--border-light);border-radius:var(--radius-full);">
                        <div style="width:<?= min(100, $behaviorScore) ?>%;height:100%;background:<?= $behColor ?>;border-radius:var(--radius-full);transition:width 1s;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:4px;">
                        <span>ضعيف</span><span>مقبول</span><span>ممتاز</span>
                    </div>
                </div>
                <?php else: ?>
                <div style="padding:30px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:8px;">📋</div>
                    <p>لم يتم تقييم السلوك بعد</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         تقويم الحضور + الاختبارات القادمة
         ═══════════════════════════════════════════ -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- تقويم الحضور الشهري -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📅 تقويم الحضور - <?= formatDateAr(date('Y-m-d'), 'month') ?></h3>
            </div>
            <div class="card-body">
                <?php
                $currentMonth = date('n');
                $currentYear = date('Y');
                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
                $firstDayOfWeek = (date('w', mktime(0,0,0,$currentMonth,1,$currentYear)) + 6) % 7; // Monday=0
                $dayNames = ['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمع', 'سبت'];
                $statusLabels = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'بعذر'];
                $statusColors = ['present' => 'present', 'absent' => 'absent', 'late' => 'late', 'excused' => 'excused'];
                ?>
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:8px;">
                    <?php foreach ($dayNames as $dn): ?>
                    <div style="font-size:11px;font-weight:600;color:var(--text-secondary);padding:4px;"><?= $dn ?></div>
                    <?php endforeach; ?>
                </div>
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;justify-items:center;">
                    <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                    <div class="cal-day empty"></div>
                    <?php endfor; ?>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $d);
                        $status = $attendanceCalendar[$dateStr] ?? null;
                        $calClass = $status ? ($statusColors[$status] ?? 'empty') : 'empty';
                        $isToday = $dateStr === date('Y-m-d');
                    ?>
                    <div class="cal-day <?= $calClass ?>" style="<?= $isToday ? 'outline:2px solid var(--primary);outline-offset:-2px;' : '' ?>" title="<?= $dateStr ?><?= $status ? ' - ' . $statusLabels[$status] : '' ?>">
                        <?= $d ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <!-- دليل الألوان -->
                <div style="display:flex;gap:16px;justify-content:center;margin-top:12px;font-size:11px;">
                    <span><span class="cal-day present" style="width:16px;height:16px;font-size:9px;">✓</span> حاضر</span>
                    <span><span class="cal-day absent" style="width:16px;height:16px;font-size:9px;">✗</span> غائب</span>
                    <span><span class="cal-day late" style="width:16px;height:16px;font-size:9px;">⏰</span> متأخر</span>
                    <span><span class="cal-day excused" style="width:16px;height:16px;font-size:9px;">!</span> بعذر</span>
                </div>
            </div>
        </div>

        <!-- الاختبارات والفعاليات القادمة -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 الاختبارات القادمة</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($upcomingEvents)): ?>
                <div style="text-align:center;padding:30px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:8px;">📋</div>
                    <p>لا توجد اختبارات قادمة</p>
                </div>
                <?php else: ?>
                <div style="max-height:320px;overflow-y:auto;">
                    <?php foreach ($upcomingEvents as $evt):
                        $daysLeft = (int)((strtotime($evt['test_date']) - time()) / 86400);
                        $urgencyColor = $daysLeft <= 2 ? 'var(--danger)' : ($daysLeft <= 5 ? 'var(--warning)' : 'var(--text-secondary)');
                    ?>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);border-right:3px solid <?= $urgencyColor ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-size:13px;font-weight:600;"><?= e($evt['title_ar']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= e($evt['subject_name']) ?> | الدرجة القصوى: <?= $evt['max_grade'] ?></div>
                            </div>
                            <div style="text-align:left;">
                                <div style="font-size:12px;color:<?= $urgencyColor ?>;font-weight:600;"><?= formatDateAr($evt['test_date'], 'short') ?></div>
                                <?php if ($daysLeft <= 7): ?>
                                <div style="font-size:10px;color:var(--text-muted);">بعد <?= $daysLeft ?> يوم</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- رسم بياني للحضور -->
    <?php if (!empty($children)): ?>
    <?php
    // جمع بيانات الأبناء للرسم البياني
    $childChartData = [];
    foreach ($children as $child) {
        $cId = $child['id'];
        $cAcademic = 0; $cBehavior = 100; $cAttendance = 100;
        try {
            $stmt = $db->prepare("SELECT AVG(g.percentage) as avg_pct FROM grades g JOIN tests t ON g.test_id = t.id WHERE g.student_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
            $stmt->execute([$cId, currentAcademicYearId()]);
            $r = $stmt->fetch();
            if ($r && $r['avg_pct'] !== null) $cAcademic = round($r['avg_pct'], 1);
        } catch (Exception $e) {}
        try {
            $stmt = $db->prepare("SELECT current_percentage as behavior_pct FROM behavior_scores WHERE student_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cId]);
            $r = $stmt->fetch();
            if ($r) $cBehavior = round($r['behavior_pct'], 1);
        } catch (Exception $e) {}
        try {
            $stmt = $db->prepare("SELECT attendance_rate FROM v_attendance_summary WHERE student_id = ? AND is_active = 1 ORDER BY academic_year_id DESC, semester_id DESC LIMIT 1");
            $stmt->execute([$cId]);
            $r = $stmt->fetch();
            if ($r && $r['attendance_rate'] !== null) $cAttendance = round($r['attendance_rate'], 1);
        } catch (Exception $e) {}
        $childChartData[] = [
            'name' => $child['full_name_ar'],
            'academic' => $cAcademic,
            'behavior' => $cBehavior,
            'attendance' => $cAttendance
        ];
    }
    ?>
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">📊 نظرة عامة على الأبناء</h3>
        </div>
        <div class="card-body" style="height:300px;">
            <canvas id="parentOverviewChart"></canvas>
        </div>
    </div>

    <script>
    // نظرة عامة على الأبناء
    var childLabels = [];
    var childAcademic = [];
    var childBehavior = [];
    var childAttendance = [];
    <?php foreach ($childChartData as $cd): ?>
    childLabels.push('<?= e($cd['name']) ?>');
    childAcademic.push(<?= $cd['academic'] ?>);
    childBehavior.push(<?= $cd['behavior'] ?>);
    childAttendance.push(<?= $cd['attendance'] ?>);
    <?php endforeach; ?>

    eqasBarChart('parentOverviewChart', '',
        childLabels,
        [
            {label: 'أكاديمي', data: childAcademic, backgroundColor: '#1a5632'},
            {label: 'سلوك', data: childBehavior, backgroundColor: '#c8a951'},
            {label: 'حضور', data: childAttendance, backgroundColor: '#17a2b8'}
        ]
    );
    </script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         مقارنة بين الأبناء (تفصيلية)
         ═══════════════════════════════════════════ -->
    <?php if (count($children) > 1 && !empty($childComparison)): ?>
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">📊 مقارنة بين الأبناء</h3>
        </div>
        <div class="card-body" style="height:300px;">
            <canvas id="childComparisonChart"></canvas>
        </div>
    </div>

    <script>
    var compLabels = [];
    var compAcademic = [];
    var compBehavior = [];
    var compAttendance = [];
    <?php foreach ($childComparison as $cc): ?>
    compLabels.push('<?= e($cc['name']) ?>');
    compAcademic.push(<?= $cc['academic'] ?>);
    compBehavior.push(<?= $cc['behavior'] ?>);
    compAttendance.push(<?= $cc['attendance'] ?>);
    <?php endforeach; ?>

    eqasBarChart('childComparisonChart', '',
        compLabels,
        [
            {label: 'أكاديمي', data: compAcademic, backgroundColor: '#1a5632'},
            {label: 'سلوك', data: compBehavior, backgroundColor: '#c8a951'},
            {label: 'حضور', data: compAttendance, backgroundColor: '#17a2b8'}
        ]
    );
    </script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         رسم بياني لاتجاه الدرجات
         ═══════════════════════════════════════════ -->
    <?php if (!empty($gradesTrend['labels'])): ?>
    <script>
    eqasLineChart('gradesTrendChart', '',
        <?= json_encode($gradesTrend['labels'], JSON_UNESCAPED_UNICODE) ?>,
        [{
            label: 'الدرجة',
            data: <?= json_encode($gradesTrend['values']) ?>,
            borderColor: '#1a5632',
            fill: true,
            fillColor: '#1a563220',
            tension: 0.3,
            pointRadius: 5
        }]
    );
    </script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         الإشعارات الأخيرة والروابط السريعة
         ═══════════════════════════════════════════ -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(350px,1fr));">
        <!-- الإشعارات الأخيرة -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">📨 آخر الإشعارات</h3>
                <a href="../notifications/index.php" class="btn btn-sm btn-light">عرض الكل</a>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentNotifications)): ?>
                <div style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد إشعارات</div>
                <?php else: ?>
                <div style="max-height:300px;overflow-y:auto;">
                    <?php foreach ($recentNotifications as $notif): ?>
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border-light);<?= empty($notif['is_read']) ? 'background:rgba(26,86,50,0.03);' : '' ?>">
                        <div style="font-size:13px;font-weight:<?= empty($notif['is_read']) ? '600' : '400' ?>;">
                            <?= e($notif['title'] ?? $notif['message'] ?? '') ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                            <?= formatDateAr($notif['created_at'], 'short') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- الروابط السريعة -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⚡ روابط سريعة</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-2" style="gap:12px;">
                    <a href="../grade_sheets/parent_view.php?student_id=<?= $selectedStudentId ?>" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">📊</div>
                        <div style="font-size:13px;">عرض الدرجات</div>
                    </a>
                    <a href="../parent_ack/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">✅</div>
                        <div style="font-size:13px;">الإقرارات</div>
                    </a>
                    <a href="../grade_sheets/parent_view.php?student_id=<?= $selectedStudentId ?>&section=attendance" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">📅</div>
                        <div style="font-size:13px;">سجل الحضور</div>
                    </a>
                    <a href="../grade_sheets/parent_view.php?student_id=<?= $selectedStudentId ?>&section=behavior" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">📋</div>
                        <div style="font-size:13px;">تقييم السلوك</div>
                    </a>
                    <a href="../notifications/preferences.php" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">🔔</div>
                        <div style="font-size:13px;">تفضيلات الإشعارات</div>
                    </a>
                    <a href="../grade_sheets/parent_view.php?student_id=<?= $selectedStudentId ?>&section=skills" class="btn btn-light" style="text-align:center;padding:16px;">
                        <div style="font-size:24px;margin-bottom:4px;">🎯</div>
                        <div style="font-size:13px;">تقييم المهارات</div>
                    </a>
                </div>

                <!-- معلومات الطالب -->
                <div style="margin-top:16px;padding:12px;background:var(--background);border-radius:var(--radius-md);">
                    <h4 style="font-size:14px;margin-bottom:8px;">👤 معلومات الطالب</h4>
                    <div style="font-size:13px;color:var(--text-secondary);line-height:1.8;">
                        <div><strong>الاسم:</strong> <?= e($currentChild['full_name_ar']) ?></div>
                        <div><strong>الرقم:</strong> <?= e($currentChild['student_number']) ?></div>
                        <div><strong>الصف:</strong> <?= e($currentChild['grade_name']) ?></div>
                        <div><strong>المدرسة:</strong> <?= e($currentChild['school_name']) ?></div>
                        <div><strong>صلة القرابة:</strong>
                            <?php
                            $relLabels = ['father' => 'أب', 'mother' => 'أم', 'guardian' => 'وصي', 'other' => 'أخرى'];
                            echo e($relLabels[$currentChild['relationship']] ?? $currentChild['relationship']);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         إعلانات المدرسة
         ═══════════════════════════════════════════ -->
    <?php if (!empty($schoolAnnouncements)): ?>
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">📢 إعلانات المدرسة</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php foreach ($schoolAnnouncements as $ann): ?>
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-light);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-size:14px;font-weight:600;"><?= e($ann['title_ar']) ?></div>
                    <span style="font-size:11px;color:var(--text-muted);"><?= formatDateAr($ann['created_at'], 'short') ?></span>
                </div>
                <?php if (!empty($ann['message_ar'])): ?>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= e($ann['message_ar']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:16px;">👤</div>
            <p>يرجى اختيار طالب من القائمة أعلاه</p>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
// تحديث تلقائي كل 60 ثانية
$pageJs = "
// تحديث تلقائي للتنبيهات
setInterval(function() {
    var studentId = '{$selectedStudentId}';
    if (!studentId) return;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '../../api/parent_alerts.php?student_id=' + studentId + '&t=' + Date.now(), true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success && data.alerts_html) {
                    document.getElementById('alerts-container').innerHTML = data.alerts_html;
                }
                // تحديث عداد غير المقروء
                if (data.unread_count !== undefined) {
                    var badges = document.querySelectorAll('.header-action-badge');
                    // تحديث الشارة في الرأسية
                }
            } catch(e) {}
        }
    };
    xhr.send();
}, 60000);
";
require_once __DIR__ . '/../../includes/footer.php';
