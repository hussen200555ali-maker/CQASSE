<?php
/**
 * ============================================================
 * EQAS - إقرار أولياء الأمور - لوحة التحكم
 * نظام التقييم الشامل - صفحة إقرارات أولياء الأمور
 * ============================================================
 */

$pageTitle = 'إقرار أولياء الأمور';
$breadcrumb = ['إقرار أولياء الأمور' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export.php';

requireLogin();

$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3

// فلاتر
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';

// أنواع الإقرارات
$ackTypeLabels = [
    'grades' => ['label' => 'الدرجات', 'icon' => '📊'],
    'promotion' => ['label' => 'الترفيع', 'icon' => '🎓'],
    'behavior' => ['label' => 'السلوك', 'icon' => '📋'],
    'attendance' => ['label' => 'الحضور', 'icon' => '📅'],
];

// معالجة الإرسال الجماعي لطلبات الإقرار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_send'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $sendType = $_POST['send_type'] ?? '';
        $sendClassId = $_POST['send_class_id'] ?? '';

        // إنشاء إقرارات جديدة لأولياء الأمور
        $sql = "SELECT s.id as student_id, ps.parent_id
                FROM students s
                JOIN parent_students ps ON ps.student_id = s.id";
        $params = [];

        $schoolId = $_SESSION['user']['school_id'] ?? 0;
        if ($sendClassId) {
            $sql .= " JOIN student_enrollments se ON se.student_id = s.id AND se.is_active = 1 AND se.status = 'enrolled'";
        }

        $sql .= " WHERE s.is_active = 1 AND ps.is_active = 1";

        if ($schoolId && !hasPermission('group_admin')) {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        if ($sendClassId) {
            $sql .= " AND se.class_id = ?";
            $params[] = $sendClassId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $parentStudentPairs = $stmt->fetchAll();

        $sentCount = 0;
        $insertSql = "INSERT INTO parent_acknowledgments (parent_id, student_id, acknowledgment_type, deadline, is_active, created_at)
                       VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), 1, NOW())";
        $insertStmt = $db->prepare($insertSql);

        foreach ($parentStudentPairs as $pair) {
            $type = $sendType ?: 'grades';
            // التحقق من عدم وجود إقرار مكرر
            $checkStmt = $db->prepare("SELECT id FROM parent_acknowledgments WHERE parent_id = ? AND student_id = ? AND acknowledgment_type = ? AND is_acknowledged = 0 AND is_active = 1 LIMIT 1");
            $checkStmt->execute([$pair['parent_id'], $pair['student_id'], $type]);
            if (!$checkStmt->fetch()) {
                $insertStmt->execute([$pair['parent_id'], $pair['student_id'], $type]);
                $sentCount++;
            }
        }

        logActivity($db, $_SESSION['user']['id'], 'ack_bulk_send', "إرسال جماعي لطلبات إقرار: {$sentCount} طلب", 'acknowledgment', null);
        setFlash("تم إرسال {$sentCount} طلب إقرار بنجاح", 'success');
    }
    redirect('index.php');
}

// معالجة إرسال التذكيرات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminders'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $reminderIds = $_POST['reminder_ids'] ?? [];
        if (is_array($reminderIds) && !empty($reminderIds)) {
            $reminderCount = 0;
            foreach ($reminderIds as $rid) {
                $rid = (int)$rid;
                // في الإنتاج: إرسال إشعار تذكير لولي الأمر
                $stmt = $db->prepare("UPDATE parent_acknowledgments SET reminder_sent_at = NOW(), reminder_count = COALESCE(reminder_count, 0) + 1 WHERE id = ? AND is_acknowledged = 0");
                $stmt->execute([$rid]);
                $reminderCount++;
            }
            logActivity($db, $_SESSION['user']['id'], 'ack_reminder_send', "إرسال تذكيرات: {$reminderCount} تذكير", 'acknowledgment', null);
            setFlash("تم إرسال {$reminderCount} تذكير بنجاح", 'success');
        } else {
            setFlash('لم يتم تحديد أي إقرارات لإرسال التذكير', 'warning');
        }
    }
    redirect('index.php');
}

// معالجة تصدير تقرير الإقرارات
if (isset($_POST['export_report']) && verifyCsrf()) {
    $headers = ['الطالب', 'رقم الطالب', 'ولي الأمر', 'نوع الإقرار', 'الحالة', 'تاريخ الإنشاء', 'تاريخ الإقرار', 'الموعد النهائي'];
    $exportData = [];

    // إعادة جلب البيانات للتصدير
    $exportSql = "SELECT pa.*, s.full_name_ar as student_name, s.student_number,
            p.full_name_ar as parent_name
            FROM parent_acknowledgments pa
            JOIN students s ON s.id = pa.student_id
            JOIN users p ON p.id = pa.parent_id
            WHERE pa.is_active = 1";
    $exportParams = [];
    $schoolId = $_SESSION['user']['school_id'] ?? 0;
    if ($schoolId && !hasPermission('group_admin')) {
        $exportSql .= " AND s.school_id = ?";
        $exportParams[] = $schoolId;
    }
    if ($filterType) { $exportSql .= " AND pa.acknowledgment_type = ?"; $exportParams[] = $filterType; }
    if ($filterStatus === 'pending') { $exportSql .= " AND pa.is_acknowledged = 0"; }
    elseif ($filterStatus === 'acknowledged') { $exportSql .= " AND pa.is_acknowledged = 1"; }
    elseif ($filterStatus === 'overdue') { $exportSql .= " AND pa.is_acknowledged = 0 AND pa.deadline < NOW()"; }

    $exportSql .= " ORDER BY pa.is_acknowledged ASC, pa.created_at DESC";
    $stmt = $db->prepare($exportSql);
    $stmt->execute($exportParams);
    $exportRows = $stmt->fetchAll();

    foreach ($exportRows as $row) {
        $status = $row['is_acknowledged'] ? 'تم الإقرار' : ($row['deadline'] && strtotime($row['deadline']) < time() ? 'متأخر' : 'بانتظار الإقرار');
        $typeInfo = $ackTypeLabels[$row['acknowledgment_type']] ?? ['label' => $row['acknowledgment_type']];
        $exportData[] = [
            $row['student_name'],
            $row['student_number'],
            $row['parent_name'],
            $typeInfo['label'],
            $status,
            $row['created_at'],
            $row['acknowledged_at'] ?: '-',
            $row['deadline'] ?: '-'
        ];
    }

    if (!empty($exportData)) {
        $baseFilename = 'تقرير_إقرارات_أولياء_الأمور_' . date('Ymd');
        $format = $_POST['export_format'] ?? 'xlsx';
        $filters = ['type' => $filterType, 'status' => $filterStatus];
        $exportInfo = createExportFile($db, $_SESSION['user']['id'], 'ack_report', $format, $exportData, $headers, $baseFilename, $filters);
        logActivity($db, $_SESSION['user']['id'], 'ack_export', "تصدير تقرير إقرارات: " . count($exportData) . " سجل", 'acknowledgment', null);
        downloadExportFile($exportInfo['file_path'], $exportInfo['file_name']);
    } else {
        setFlash('لا توجد بيانات للتصدير', 'warning');
    }
}

if ($userRole === 'parent') {
    // عرض ولي الأمر - إقراراته الخاصة
    $parentId = $userId;

    $sql = "SELECT pa.*, s.full_name_ar as student_name, s.student_number
            FROM parent_acknowledgments pa
            JOIN students s ON s.id = pa.student_id
            WHERE pa.parent_id = ? AND pa.is_active = 1";
    $params = [$parentId];

    if ($filterType) { $sql .= " AND pa.acknowledgment_type = ?"; $params[] = $filterType; }
    if ($filterStatus === 'pending') { $sql .= " AND pa.is_acknowledged = 0"; }
    elseif ($filterStatus === 'acknowledged') { $sql .= " AND pa.is_acknowledged = 1"; }
    elseif ($filterStatus === 'overdue') { $sql .= " AND pa.is_acknowledged = 0 AND pa.deadline < NOW()"; }

    $sql .= " ORDER BY pa.is_acknowledged ASC, pa.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $acknowledgments = $stmt->fetchAll();

} else {
    // عرض المدير - جميع الإقرارات
    if (!hasPermission('school_admin')) {
        setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
        redirect('index.php');
    }

    $sql = "SELECT pa.*, s.full_name_ar as student_name, s.student_number,
            p.full_name_ar as parent_name
            FROM parent_acknowledgments pa
            JOIN students s ON s.id = pa.student_id
            JOIN users p ON p.id = pa.parent_id
            WHERE pa.is_active = 1";
    $params = [];

    $schoolId = $_SESSION['user']['school_id'] ?? 0;
    if ($schoolId && !hasPermission('group_admin')) {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }

    if ($filterType) { $sql .= " AND pa.acknowledgment_type = ?"; $params[] = $filterType; }
    if ($filterStatus === 'pending') { $sql .= " AND pa.is_acknowledged = 0"; }
    elseif ($filterStatus === 'acknowledged') { $sql .= " AND pa.is_acknowledged = 1"; }
    elseif ($filterStatus === 'overdue') { $sql .= " AND pa.is_acknowledged = 0 AND pa.deadline < NOW()"; }

    $sql .= " ORDER BY pa.is_acknowledged ASC, pa.created_at DESC LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $acknowledgments = $stmt->fetchAll();
}

// إحصائيات شاملة
$pendingCount = count(array_filter($acknowledgments, function($a) { return !$a['is_acknowledged']; }));
$acknowledgedCount = count(array_filter($acknowledgments, function($a) { return $a['is_acknowledged']; }));
$overdueCount = count(array_filter($acknowledgments, function($a) { return !$a['is_acknowledged'] && isset($a['deadline']) && strtotime($a['deadline']) < time(); }));
$totalCount = count($acknowledgments);
$ackRate = $totalCount > 0 ? round(($acknowledgedCount / $totalCount) * 100, 1) : 0;

// إحصائيات حسب النوع
$statsByType = [];
foreach ($acknowledgments as $ack) {
    $type = $ack['acknowledgment_type'];
    if (!isset($statsByType[$type])) {
        $statsByType[$type] = ['total' => 0, 'acknowledged' => 0, 'pending' => 0];
    }
    $statsByType[$type]['total']++;
    if ($ack['is_acknowledged']) {
        $statsByType[$type]['acknowledged']++;
    } else {
        $statsByType[$type]['pending']++;
    }
}

// بيانات الرسم البياني
$typeLabels = [];
$typeCounts = [];
$typeColors = ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6f42c1'];
$typeColorArr = [];
$colorIdx = 0;
foreach ($statsByType as $type => $stats) {
    $label = $ackTypeLabels[$type]['label'] ?? $type;
    $typeLabels[] = $label;
    $typeCounts[] = $stats['total'];
    $typeColorArr[] = $typeColors[$colorIdx % count($typeColors)];
    $colorIdx++;
}

// نسبة الإقرار حسب الصف (للمديرين فقط)
$classAckRates = [];
if ($userRole !== 'parent' && hasPermission('school_admin')) {
    $classSql = "SELECT c.name_ar as class_name, c.id as class_id,
                 COUNT(pa.id) as total,
                 SUM(CASE WHEN pa.is_acknowledged = 1 THEN 1 ELSE 0 END) as acknowledged
                 FROM classes c
                 LEFT JOIN student_enrollments se ON se.class_id = c.id AND se.is_active = 1 AND se.status = 'enrolled'
                 LEFT JOIN students s ON s.id = se.student_id AND s.is_active = 1
                 LEFT JOIN parent_acknowledgments pa ON pa.student_id = s.id AND pa.is_active = 1
                 WHERE c.is_active = 1";
    $classParams = [];
    if (isset($schoolId) && $schoolId && !hasPermission('group_admin')) {
        $classSql .= " AND c.school_id = ?";
        $classParams[] = $schoolId;
    }
    $classSql .= " GROUP BY c.id, c.name_ar HAVING COUNT(pa.id) > 0 ORDER BY SUM(CASE WHEN pa.is_acknowledged = 1 THEN 1 ELSE 0 END)/COUNT(pa.id) ASC LIMIT 10";
    $stmt = $db->prepare($classSql);
    $stmt->execute($classParams);
    $classAckRates = $stmt->fetchAll();
}

// جلب الفصول للإرسال الجماعي
$classes = [];
if ($userRole !== 'parent') {
    $classQuery = "SELECT id, name_ar FROM classes WHERE is_active = 1";
    $classParams = [];
    if (isset($schoolId) && $schoolId && !hasPermission('group_admin')) {
        $classQuery .= " AND school_id = ?";
        $classParams[] = $schoolId;
    }
    $classQuery .= " ORDER BY name_ar";
    $stmt = $db->prepare($classQuery);
    $stmt->execute($classParams);
    $classes = $stmt->fetchAll();
}
?>

<!-- إحصائيات الإقرارات -->
<div class="row mb-4">
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">📋</div>
            <div class="stat-card-label">الإجمالي</div>
            <div class="stat-card-value"><?php echo $totalCount; ?></div>
        </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">⏳</div>
            <div class="stat-card-label">بانتظار الإقرار</div>
            <div class="stat-card-value text-warning"><?php echo $pendingCount; ?></div>
        </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">✅</div>
            <div class="stat-card-label">تم الإقرار</div>
            <div class="stat-card-value text-success"><?php echo $acknowledgedCount; ?></div>
        </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">⚠️</div>
            <div class="stat-card-label">متأخر</div>
            <div class="stat-card-value text-danger"><?php echo $overdueCount; ?></div>
        </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">📈</div>
            <div class="stat-card-label">نسبة الإقرار</div>
            <div class="stat-card-value" style="color:<?php echo $ackRate >= 70 ? '#28a745' : ($ackRate >= 40 ? '#ffc107' : '#dc3545'); ?>;"><?php echo $ackRate; ?>%</div>
        </div>
    </div>
    <div class="col-md-2 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">📊</div>
            <div class="stat-card-label">الأنواع</div>
            <div class="stat-card-value" style="color:var(--primary);"><?php echo count($statsByType); ?></div>
        </div>
    </div>
</div>

<!-- نسبة الإقرار العامة -->
<div style="margin-bottom:20px;padding:12px 16px;background:rgba(26,86,50,0.05);border-radius:var(--radius-md);border-right:4px solid var(--primary);">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="font-semibold text-sm">نسبة الإقرار العامة</span>
        <span class="font-bold" style="color:<?php echo $ackRate >= 70 ? '#28a745' : ($ackRate >= 40 ? '#ffc107' : '#dc3545'); ?>;"><?php echo $ackRate; ?>%</span>
    </div>
    <div style="background:var(--border-light);border-radius:var(--radius-md);height:12px;overflow:hidden;">
        <div style="width:<?php echo $ackRate; ?>%;height:100%;background:<?php echo $ackRate >= 70 ? '#28a745' : ($ackRate >= 40 ? '#ffc107' : '#dc3545'); ?>;border-radius:var(--radius-md);transition:width 0.5s;"></div>
    </div>
</div>

<!-- الرسوم البيانية -->
<div class="row mb-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 توزيع الإقرارات حسب النوع</h2>
            </div>
            <div class="card-body">
                <canvas id="ackTypeChart" style="max-height:260px;"></canvas>
                <?php if (!empty($statsByType)): ?>
                <div class="mt-3">
                    <?php foreach ($statsByType as $type => $stats): ?>
                    <?php
                    $typeInfo = $ackTypeLabels[$type] ?? ['label' => $type, 'icon' => '📄'];
                    $typeRate = $stats['total'] > 0 ? round(($stats['acknowledged'] / $stats['total']) * 100, 1) : 0;
                    ?>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-sm"><?php echo $typeInfo['icon']; ?> <?php echo e($typeInfo['label']); ?></span>
                        <div>
                            <span class="badge badge-success" style="font-size:10px;">✅ <?php echo $stats['acknowledged']; ?></span>
                            <span class="badge badge-warning" style="font-size:10px;">⏳ <?php echo $stats['pending']; ?></span>
                            <span class="text-xs text-muted">(<?php echo $typeRate; ?>%)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted text-center">لا توجد بيانات كافية</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($userRole !== 'parent' && !empty($classAckRates)): ?>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🏫 نسبة الإقرار حسب الصف</h2>
            </div>
            <div class="card-body">
                <?php foreach ($classAckRates as $cr): ?>
                <?php
                $crRate = $cr['total'] > 0 ? round(($cr['acknowledged'] / $cr['total']) * 100, 1) : 0;
                $crColor = $crRate >= 70 ? '#28a745' : ($crRate >= 40 ? '#ffc107' : '#dc3545');
                ?>
                <div style="margin-bottom:12px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-sm font-semibold"><?php echo e($cr['class_name']); ?></span>
                        <span class="text-sm" style="color:<?php echo $crColor; ?>;"><?php echo $crRate; ?>% (<?php echo $cr['acknowledged']; ?>/<?php echo $cr['total']; ?>)</span>
                    </div>
                    <div style="background:var(--border-light);border-radius:var(--radius-md);height:10px;overflow:hidden;">
                        <div style="width:<?php echo $crRate; ?>%;height:100%;background:<?php echo $crColor; ?>;border-radius:var(--radius-md);"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($userRole !== 'parent'): ?>
    <!-- تتبع الموعد النهائي -->
    <div class="col-md-<?php echo !empty($classAckRates) ? '12' : '7'; ?>">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">⏰ الإقرارات المتأخرة عن الموعد النهائي</h2>
            </div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <?php
                $overdueItems = array_filter($acknowledgments, function($a) {
                    return !$a['is_acknowledged'] && isset($a['deadline']) && strtotime($a['deadline']) < time();
                });
                ?>
                <?php if (empty($overdueItems)): ?>
                    <p class="text-muted text-center">✅ لا توجد إقرارات متأخرة</p>
                <?php else: ?>
                    <?php foreach ($overdueItems as $oack): ?>
                    <?php
                    $typeInfo = $ackTypeLabels[$oack['acknowledgment_type']] ?? ['label' => $oack['acknowledgment_type'], 'icon' => '📄'];
                    $daysOverdue = isset($oack['deadline']) ? floor((time() - strtotime($oack['deadline'])) / 86400) : 0;
                    ?>
                    <div style="padding:8px 12px;margin-bottom:6px;background:rgba(220,53,69,0.06);border-right:3px solid #dc3545;border-radius:var(--radius-md);display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span class="font-semibold text-sm"><?php echo e($oack['student_name']); ?></span>
                            <span class="text-xs text-muted"> - <?php echo $typeInfo['icon']; ?> <?php echo e($typeInfo['label']); ?></span>
                            <?php if (isset($oack['parent_name'])): ?>
                            <br><span class="text-xs text-muted">ولي الأمر: <?php echo e($oack['parent_name']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:left;">
                            <span style="padding:2px 8px;border-radius:var(--radius-md);font-size:11px;font-weight:700;background:#dc354515;color:#dc3545;">
                                ⏰ متأخر <?php echo $daysOverdue; ?> يوم
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">✅ إقرارات أولياء الأمور</h2>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php if ($userRole !== 'parent'): ?>
            <button type="button" class="btn btn-sm btn-primary" onclick="openBulkSendModal()">📨 إرسال جماعي</button>
            <button type="button" class="btn btn-sm btn-outline-warning" onclick="sendReminders()">🔔 إرسال تذكيرات</button>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="openExportModal()">📥 تصدير التقرير</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <!-- فلاتر -->
        <div class="btn-group mb-4" style="flex-wrap:wrap;">
            <a href="index.php" class="btn btn-sm <?php echo !$filterType && !$filterStatus ? 'btn-primary' : 'btn-outline-primary'; ?>">الكل</a>
            <a href="?status=pending" class="btn btn-sm <?php echo $filterStatus === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">⏳ بانتظار الإقرار</a>
            <a href="?status=acknowledged" class="btn btn-sm <?php echo $filterStatus === 'acknowledged' ? 'btn-success' : 'btn-outline-success'; ?>">✅ تم الإقرار</a>
            <a href="?status=overdue" class="btn btn-sm <?php echo $filterStatus === 'overdue' ? 'btn-danger' : 'btn-outline-danger'; ?>">⚠️ متأخر</a>
            <?php foreach ($ackTypeLabels as $type => $info): ?>
            <a href="?type=<?php echo $type; ?>" class="btn btn-sm <?php echo $filterType === $type ? 'btn-info' : 'btn-outline-info'; ?>">
                <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($acknowledgments)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">✅</div>
                <p class="empty-state-text">لا توجد إقرارات</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if ($userRole !== 'parent'): ?>
                            <th style="width:30px;"><input type="checkbox" id="ack-select-all" onchange="toggleAckSelectAll(this)"></th>
                            <?php endif; ?>
                            <th>#</th>
                            <th>الطالب</th>
                            <?php if ($userRole !== 'parent'): ?>
                            <th>ولي الأمر</th>
                            <?php endif; ?>
                            <th>نوع الإقرار</th>
                            <th>الحالة</th>
                            <th>الموعد النهائي</th>
                            <th>تاريخ الإنشاء</th>
                            <th>تاريخ الإقرار</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($acknowledgments as $i => $ack): ?>
                        <?php
                        $isOverdue = !$ack['is_acknowledged'] && isset($ack['deadline']) && strtotime($ack['deadline']) < time();
                        $rowClass = $ack['is_acknowledged'] ? '' : ($isOverdue ? 'table-danger' : 'table-warning');
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <?php if ($userRole !== 'parent'): ?>
                            <td>
                                <?php if (!$ack['is_acknowledged']): ?>
                                <input type="checkbox" class="ack-checkbox" data-id="<?php echo $ack['id']; ?>">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <strong><?php echo e($ack['student_name']); ?></strong>
                                <br><small class="text-muted"><?php echo e($ack['student_number']); ?></small>
                            </td>
                            <?php if ($userRole !== 'parent'): ?>
                            <td><?php echo e($ack['parent_name'] ?? '-'); ?></td>
                            <?php endif; ?>
                            <td>
                                <?php $typeInfo = $ackTypeLabels[$ack['acknowledgment_type']] ?? ['label' => $ack['acknowledgment_type'], 'icon' => '📄']; ?>
                                <span class="badge badge-info"><?php echo $typeInfo['icon']; ?> <?php echo $typeInfo['label']; ?></span>
                            </td>
                            <td>
                                <?php if ($ack['is_acknowledged']): ?>
                                <span class="badge badge-success">✅ تم الإقرار</span>
                                <?php elseif ($isOverdue): ?>
                                <span class="badge badge-danger">⚠️ متأخر عن الموعد</span>
                                <?php else: ?>
                                <span class="badge badge-warning">⏳ بانتظار الإقرار</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ack['deadline'])): ?>
                                <span style="font-size:12px;<?php echo $isOverdue ? 'color:#dc3545;font-weight:700;' : ''; ?>">
                                    <?php echo formatDateAr($ack['deadline'], 'short'); ?>
                                </span>
                                <?php if (isset($ack['reminder_count']) && $ack['reminder_count'] > 0): ?>
                                <br><span style="font-size:10px;color:var(--text-muted);">🔔 <?php echo $ack['reminder_count']; ?> تذكير</span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatDateAr($ack['created_at'], 'short'); ?></td>
                            <td><?php echo $ack['acknowledged_at'] ? formatDateAr($ack['acknowledged_at'], 'short') : '-'; ?></td>
                            <td>
                                <a href="acknowledge.php?id=<?php echo $ack['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    👁️ عرض
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- نافذة الإرسال الجماعي -->
<div id="bulk-send-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--background);border-radius:var(--radius-md);padding:24px;width:90%;max-width:500px;direction:rtl;">
        <h3 style="margin-bottom:16px;color:var(--primary);">📨 إرسال طلبات إقرار جماعية</h3>
        <form method="POST" id="bulk-send-form">
            <?= csrfField() ?>
            <input type="hidden" name="bulk_send" value="1">

            <div class="form-group">
                <label class="form-label">نوع الإقرار</label>
                <select name="send_type" class="form-control">
                    <?php foreach ($ackTypeLabels as $type => $info): ?>
                    <option value="<?php echo $type; ?>"><?php echo $info['icon']; ?> <?php echo e($info['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">الصف (اختياري - اتركه فارغاً للكل)</label>
                <select name="send_class_id" class="form-control">
                    <option value="">جميع الصفوف</option>
                    <?php foreach ($classes as $cls): ?>
                    <option value="<?php echo $cls['id']; ?>"><?php echo e($cls['name_ar']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="padding:10px;background:rgba(255,193,7,0.08);border-radius:var(--radius-md);margin-bottom:12px;font-size:13px;">
                ⚠️ سيتم إنشاء طلبات إقرار لأولياء الأمور الذين ليس لديهم طلبات معلقة من نفس النوع. الموعد النهائي: 7 أيام من الآن.
            </div>

            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="submit" class="btn btn-primary">📨 إرسال</button>
                <button type="button" class="btn btn-outline-secondary" onclick="closeBulkSendModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة تصدير التقرير -->
<div id="export-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--background);border-radius:var(--radius-md);padding:24px;width:90%;max-width:450px;direction:rtl;">
        <h3 style="margin-bottom:16px;color:var(--primary);">📥 تصدير تقرير الإقرارات</h3>
        <form method="POST" id="export-form">
            <?= csrfField() ?>
            <input type="hidden" name="export_report" value="1">

            <div class="form-group">
                <label class="form-label">صيغة التصدير</label>
                <select name="export_format" class="form-control">
                    <option value="xlsx">📄 إكسل (XLSX)</option>
                    <option value="csv">📝 CSV</option>
                </select>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="submit" class="btn btn-primary">📥 تصدير</button>
                <button type="button" class="btn btn-outline-secondary" onclick="closeExportModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- نموذج إرسال التذكيرات (مخفي) -->
<form method="POST" id="reminder-form" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="send_reminders" value="1">
    <div id="reminder-ids-container"></div>
</form>

<script>
function openBulkSendModal() {
    document.getElementById('bulk-send-modal').style.display = 'flex';
}
function closeBulkSendModal() {
    document.getElementById('bulk-send-modal').style.display = 'none';
}

function openExportModal() {
    document.getElementById('export-modal').style.display = 'flex';
}
function closeExportModal() {
    document.getElementById('export-modal').style.display = 'none';
}

function toggleAckSelectAll(checkbox) {
    document.querySelectorAll('.ack-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

function sendReminders() {
    const checked = document.querySelectorAll('.ack-checkbox:checked');
    if (checked.length === 0) {
        alert('يرجى تحديد إقرارات لإرسال التذكيرات إليها');
        return;
    }
    if (!confirm('هل تريد إرسال تذكير لـ ' + checked.length + ' ولي أمر؟')) return;

    const form = document.getElementById('reminder-form');
    const container = document.getElementById('reminder-ids-container');
    container.innerHTML = '';

    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'reminder_ids[]';
        input.value = cb.getAttribute('data-id');
        container.appendChild(input);
    });

    form.submit();
}

// إغلاق النوافذ بالنقر خارجها
['bulk-send-modal', 'export-modal'].forEach(modalId => {
    document.getElementById(modalId).addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>

<?php
// رسم بياني دائري لتوزيع الإقرارات حسب النوع
$pageJs = "
    eqasPieChart('ackTypeChart', 'توزيع الإقرارات حسب النوع',
        " . json_encode($typeLabels, JSON_UNESCAPED_UNICODE) . ",
        " . json_encode($typeCounts) . ",
        " . json_encode($typeColorArr) . ",
        false
    );
";

require_once __DIR__ . '/../../includes/footer.php';
?>
