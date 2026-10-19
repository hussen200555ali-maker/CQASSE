<?php
/**
 * ============================================================
 * EQAS - الإشعارات - مركز الإشعارات
 * نظام التقييم الشامل - صفحة مركز الإشعارات
 * ============================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

// معالجة التصدير - يجب أن يكون قبل أي مخرجات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_notifications'])) {
    $userId = $_SESSION['user']['id'];
    $filterType = $_GET['type'] ?? '';
    $filterRead = $_GET['read'] ?? '';
    $filterSearch = $_GET['search'] ?? '';

    $exportSql = "SELECT id, type, title_ar, message_ar, is_read, created_at FROM notifications WHERE user_id = ?";
    $exportParams = [$userId];
    if ($filterType) { $exportSql .= " AND type = ?"; $exportParams[] = $filterType; }
    if ($filterRead === '0') { $exportSql .= " AND is_read = 0"; }
    elseif ($filterRead === '1') { $exportSql .= " AND is_read = 1"; }
    if ($filterSearch) { $exportSql .= " AND (title_ar LIKE ? OR message_ar LIKE ?)"; $exportParams[] = "%$filterSearch%"; $exportParams[] = "%$filterSearch%"; }
    $exportSql .= " ORDER BY created_at DESC";
    $exportStmt = $db->prepare($exportSql);
    $exportStmt->execute($exportParams);
    $exportData = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=notifications_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['المعرف', 'النوع', 'العنوان', 'الرسالة', 'الحالة', 'التاريخ']);
    $typeLabelMap = [
        'alert' => 'تنبيه', 'announcement' => 'إعلان', 'reminder' => 'تذكير',
        'system' => 'نظام', 'acknowledgment' => 'إقرار', 'info' => 'معلومات',
        'ack_request' => 'طلب إقرار', 'parent_link' => 'ربط ولي أمر',
    ];
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['id'],
            $typeLabelMap[$row['type']] ?? $row['type'],
            $row['title_ar'],
            $row['message_ar'],
            $row['is_read'] ? 'مقروء' : 'غير مقروء',
            $row['created_at'],
        ]);
    }
    fclose($output);
    exit;
}

$pageTitle = 'مركز الإشعارات';
$breadcrumb = ['مركز الإشعارات' => null];

$tab = $_GET['tab'] ?? 'notifications';

require_once __DIR__ . '/../../includes/header.php';

requireLogin();

$userId = $_SESSION['user']['id'] ?? null; // يجب قبل استخدامه

$showAlerts = in_array($tab, ['alerts', 'unified']);

if ($showAlerts) {
    require_once __DIR__ . '/../../includes/alerts.php';
    $alertEngine = new AlertEngine($db);
    $userRole = $_SESSION['user']['role'] ?? '';
    $userSchoolId = $_SESSION['user']['school_id'] ?? null;

    // تنظيف تلقائي للإشعارات القديمة (مرة كل 24 ساعة)
    $lastCleanup = $_SESSION['notif_cleanup_time'] ?? 0;
    if (time() - $lastCleanup > 86400) {
        $alertEngine->cleanupOldRecords();
        $_SESSION['notif_cleanup_time'] = time();
    }
    $alertFilters = [
        'school_id' => $_GET['alert_school'] ?? (in_array($userRole, ['school_admin', 'teacher']) ? $userSchoolId : ''),
        'alert_type' => $_GET['alert_type'] ?? '',
        'severity' => $_GET['severity'] ?? '',
        'is_read' => $_GET['alert_read'] ?? '',
        'is_resolved' => $_GET['alert_resolved'] ?? '',
        'page' => max(1, (int)($_GET['alert_page'] ?? 1)),
    ];

    // تطبيق الفلترة حسب الدور على استعلام التنبيهات
    $alertFilters['where'] = $alertFilters['where'] ?? [];
    $alertFilters['params'] = $alertFilters['params'] ?? [];
    $alertFilters = $alertEngine->applyRoleFilter($alertFilters, $userRole, $userId);

    $alerts = $alertEngine->getAlerts($alertFilters);
    $totalAlerts = $alertEngine->getAlertsCount($alertFilters);
    $unresolvedAlerts = $alertEngine->getUnreadCount($_SESSION['user']['id'] ?? null);

    // جلب المدارس للفلتر (فقط لـ group_admin)
    $alertSchools = [];
    if ($userRole === 'group_admin') {
        try {
            $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
            $alertSchools = $stmt->fetchAll();
        } catch (Exception $e) {}
    }
}

// جميع الأدوار يمكنهم رؤية إشعاراتهم
$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3

// فلاتر
$filterType = $_GET['type'] ?? '';
$filterRead = $_GET['read'] ?? '';
$filterSearch = $_GET['search'] ?? '';

// Pagination للإشعارات
$notifPage = max(1, (int)($_GET['notif_page'] ?? 1));
$notifPerPage = 50;
$notifOffset = ($notifPage - 1) * $notifPerPage;

// معالجة تحديد كمقروء
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }

    if (isset($_POST['mark_read'])) {
        $notifId = (int)$_POST['mark_read'];
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }

    if (isset($_POST['mark_unread'])) {
        $notifId = (int)$_POST['mark_unread'];
        $stmt = $db->prepare("UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }

    if (isset($_POST['mark_all_read'])) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        logActivity($db, $userId, 'notifications_read_all', 'تحديد جميع الإشعارات كمقروءة', 'notification', null);
        setFlash('تم تحديد جميع الإشعارات كمقروءة', 'success');
        redirect('index.php');
    }

    if (isset($_POST['delete_notif'])) {
        $notifId = (int)$_POST['delete_notif'];
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }

    redirect('index.php');
}

// بناء الاستعلام
$sql = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$userId];

if ($filterType) {
    $sql .= " AND type = ?";
    $params[] = $filterType;
}
if ($filterRead === '0') {
    $sql .= " AND is_read = 0";
} elseif ($filterRead === '1') {
    $sql .= " AND is_read = 1";
}
if ($filterSearch) {
    $sql .= " AND (title_ar LIKE ? OR message_ar LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

// إجمالي الإشعارات للـ pagination
$countSql = "SELECT COUNT(*) FROM notifications WHERE user_id = ?";
$countParams = [$userId];
if ($filterType) {
    $countSql .= " AND type = ?";
    $countParams[] = $filterType;
}
if ($filterRead === '0') {
    $countSql .= " AND is_read = 0";
} elseif ($filterRead === '1') {
    $countSql .= " AND is_read = 1";
}
if ($filterSearch) {
    $countSql .= " AND (title_ar LIKE ? OR message_ar LIKE ?)";
    $countParams[] = "%$filterSearch%";
    $countParams[] = "%$filterSearch%";
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalNotifications = (int)$countStmt->fetchColumn();
$totalNotifPages = max(1, (int)ceil($totalNotifications / $notifPerPage));

$sql .= " ORDER BY created_at DESC LIMIT $notifPerPage OFFSET $notifOffset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// عدد الإشعارات غير المقروءة
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = $stmt->fetchColumn();

// تجميع الإشعارات حسب التاريخ
$groupedNotifications = [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('-7 days'));

foreach ($notifications as $notif) {
    $notifDate = date('Y-m-d', strtotime($notif['created_at']));
    if ($notifDate === $today) {
        $groupedNotifications['اليوم'][] = $notif;
    } elseif ($notifDate === $yesterday) {
        $groupedNotifications['أمس'][] = $notif;
    } elseif ($notifDate >= $weekStart) {
        $groupedNotifications['هذا الأسبوع'][] = $notif;
    } else {
        $groupedNotifications['أقدم'][] = $notif;
    }
}

// أنواع الإشعارات (متوافقة مع VARCHAR — بدون ENUM)
$typeLabels = [
    'alert' => ['label' => 'تنبيه', 'icon' => '🚨', 'color' => 'danger'],
    'announcement' => ['label' => 'إعلان', 'icon' => '📢', 'color' => 'info'],
    'reminder' => ['label' => 'تذكير', 'icon' => '⏰', 'color' => 'warning'],
    'system' => ['label' => 'نظام', 'icon' => '⚙️', 'color' => 'secondary'],
    'acknowledgment' => ['label' => 'إقرار', 'icon' => '✅', 'color' => 'success'],
    'info' => ['label' => 'معلومات', 'icon' => 'ℹ️', 'color' => 'info'],
    'ack_request' => ['label' => 'طلب إقرار', 'icon' => '📝', 'color' => 'warning'],
    'parent_link' => ['label' => 'ربط ولي أمر', 'icon' => '🔗', 'color' => 'success'],
];

// روابط الكيانات المرتبطة
$entityLinks = [
    'student' => EQAS_URL . '/pages/students/view.php?id=',
    'class' => EQAS_URL . '/pages/classes/view.php?id=',
    'grade' => EQAS_URL . '/pages/grade_sheets/view.php?id=',
    'alert' => EQAS_URL . '/pages/alerts/index.php?id=',
    'promotion' => EQAS_URL . '/pages/promotion/index.php?id=',
    'teacher' => EQAS_URL . '/pages/users/view.php?id=',
    'school' => EQAS_URL . '/pages/schools/view.php?id=',
    'improvement_plan' => EQAS_URL . '/pages/supervision/improvement_plan.php?id=',
];
?><!-- ... نهاية PHP --><?php if ($tab !== 'notifications' && $tab !== 'unified'): $unreadCount = 0; endif; ?>

<!-- ════════════════════════════════════════════
     تبويبات مركز الإشعارات
     ════════════════════════════════════════════ -->
<div class="mb-4">
    <div class="btn-group" style="flex-wrap:wrap;">
        <a href="?tab=notifications" class="btn btn-sm <?php echo $tab === 'notifications' ? 'btn-primary' : 'btn-outline-primary'; ?>">📨 إشعاراتي</a>
        <a href="?tab=alerts" class="btn btn-sm <?php echo $tab === 'alerts' ? 'btn-danger' : 'btn-outline-danger'; ?>">🚨 تنبيهات النظام</a>
        <a href="?tab=unified" class="btn btn-sm <?php echo $tab === 'unified' ? 'btn-success' : 'btn-outline-success'; ?>">📋 الكل</a>
    </div>
</div>

<?php if ($tab === 'notifications' || $tab === 'unified'): ?>
<!-- ════════════════════════════════════════════
     الإشعارات الشخصية
     ════════════════════════════════════════════ -->
<div class="card" style="border-radius: var(--radius-md, 8px);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">📨 الإشعارات <span class="badge badge-danger"><?= $unreadCount ?> جديد</span></h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sound-toggle"
                    onclick="toggleNotifSound()" title="تشغيل/إيقاف صوت الإشعارات">
                <span id="sound-icon">🔇</span> الصوت
            </button>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <button type="submit" name="mark_all_read" value="1" class="btn btn-sm btn-outline-success"
                        <?= $unreadCount == 0 ? 'disabled' : '' ?>>
                    ✅ تحديد الكل كمقروء
                </button>
            </form>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <button type="submit" name="export_notifications" value="1" class="btn btn-sm btn-outline-info" title="تصدير الإشعارات">
                    📥 تصدير
                </button>
            </form>
            <a href="preferences.php" class="btn btn-sm btn-outline-primary">⚙️ تفضيلات</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="mb-3">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" value="<?= e($filterSearch) ?>" placeholder="بحث في الإشعارات...">
                        <button class="btn btn-outline-secondary" type="submit">🔍</button>
                        <?php if ($filterSearch): ?>
                        <a href="?tab=<?= e($tab) ?>" class="btn btn-outline-danger" title="مسح البحث">✕</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <div class="btn-group mb-4" style="flex-wrap:wrap;">
            <a href="?tab=<?= e($tab) ?>" class="btn btn-sm <?php echo !$filterType && !$filterRead ? 'btn-primary' : 'btn-outline-primary'; ?>">الكل</a>
            <a href="?tab=<?= e($tab) ?>&amp;read=0" class="btn btn-sm <?php echo $filterRead === '0' ? 'btn-danger' : 'btn-outline-danger'; ?>">غير مقروء (<?php echo $unreadCount; ?>)</a>
            <a href="?tab=<?= e($tab) ?>&amp;read=1" class="btn btn-sm <?php echo $filterRead === '1' ? 'btn-success' : 'btn-outline-success'; ?>">مقروء</a>
            <?php foreach ($typeLabels as $type => $info): ?>
            <a href="?tab=<?= e($tab) ?>&amp;type=<?php echo $type; ?>" class="btn btn-sm <?php echo $filterType === $type ? 'btn-' . $info['color'] : 'btn-outline-' . $info['color']; ?>">
                <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p class="empty-state-text">لا توجد إشعارات</p>
            </div>
        <?php else: ?>
            <?php foreach ($groupedNotifications as $groupLabel => $groupNotifs): ?>
            <div class="mb-3">
                <h6 style="font-size:13px;color:var(--text-muted, #6c757d);padding:8px 0 4px;border-bottom:1px solid var(--border-light, #dee2e6);margin:0;">
                    📅 <?= e($groupLabel) ?> <span class="badge badge-light" style="font-size:10px;"><?= count($groupNotifs) ?></span>
                </h6>
                <div class="notification-list">
                    <?php foreach ($groupNotifs as $notif): ?>
                    <?php
                    $typeInfo = $typeLabels[$notif['type']] ?? ['label' => $notif['type'], 'icon' => '📨', 'color' => 'secondary'];
                    ?>
                    <div class="notification-item d-flex justify-content-between align-items-start p-3 border-bottom <?= !$notif['is_read'] ? 'bg-light' : '' ?>"
                         style="<?php echo !$notif['is_read'] ? 'border-right:4px solid var(--primary);' : ''; ?> border-radius: var(--radius-md, 8px); margin:4px 0; transition: background 0.2s;"
                         onmouseenter="this.style.background='var(--bg-light, #f8f9fa)'" onmouseleave="this.style.background=''">
                        <div style="display:flex;gap:12px;flex:1;">
                            <div style="font-size:24px;"><?php echo $typeInfo['icon']; ?></div>
                            <div style="flex:1;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <h5 style="margin:0;font-size:14px;" class="<?php echo !$notif['is_read'] ? 'font-weight-bold' : ''; ?>">
                                        <?php echo e($notif['title_ar']); ?>
                                        <span class="badge badge-<?php echo $typeInfo['color']; ?> ml-2" style="font-size:10px;"><?php echo $typeInfo['label']; ?></span>
                                    </h5>
                                </div>
                                <p style="margin:4px 0;font-size:13px;" class="text-muted"><?php echo e($notif['message_ar']); ?></p>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <small class="text-muted"><?php echo formatDateAr($notif['created_at'], 'medium'); ?></small>
                                    <?php if ($notif['related_entity_type'] && $notif['related_entity_id'] && isset($entityLinks[$notif['related_entity_type']])): ?>
                                    <a href="<?= e($entityLinks[$notif['related_entity_type']]) . $notif['related_entity_id'] ?>"
                                       class="text-muted" style="font-size:11px;text-decoration:underline;">
                                        📎 عرض التفاصيل
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:4px;flex-shrink:0;">
                            <?php if (!$notif['is_read']): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="mark_read" value="<?php echo $notif['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="تحديد كمقروء">✅</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="mark_unread" value="<?php echo $notif['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="تحديد كغير مقروء">📭</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف الإشعار؟');">
                                <?= csrfField() ?>
                                <input type="hidden" name="delete_notif" value="<?php echo $notif['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف">🗑️</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($totalNotifPages > 1): ?>
            <div class="text-center mt-3">
                <nav>
                    <ul class="pagination pagination-sm justify-content-center" style="gap:4px;">
                        <?php for ($p = 1; $p <= $totalNotifPages; $p++): ?>
                        <li class="page-item <?= $p === $notifPage ? 'active' : '' ?>">
                            <a class="page-link" href="?tab=<?= e($tab) ?>&amp;type=<?= e($filterType) ?>&amp;read=<?= e($filterRead) ?>&amp;search=<?= e($filterSearch) ?>&amp;notif_page=<?= $p ?>"><?= $p ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <p class="text-muted" style="font-size:12px;">إجمالي <?= $totalNotifications ?> إشعار — صفحة <?= $notifPage ?> من <?= $totalNotifPages ?></p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?><!-- /notifications tab -->

<?php if ($tab === 'alerts' || $tab === 'unified'): ?>
<!-- ════════════════════════════════════════════
     تنبيهات النظام
     ════════════════════════════════════════════ -->
<div class="card mt-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">🚨 تنبيهات النظام <span class="badge badge-danger"><?= $unresolvedAlerts ?? 0 ?> غير محلولة</span></h2>
    </div>
    <div class="card-body">
        <!-- فلاتر التنبيهات -->
        <form method="GET" class="mb-3">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="form-row" style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php if ($userRole === 'group_admin'): ?>
                <div class="form-group" style="min-width:160px;">
                    <select name="alert_school" class="form-control">
                        <option value="">جميع المدارس</option>
                        <?php foreach ($alertSchools ?? [] as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($alertFilters['school_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" style="min-width:140px;">
                    <select name="alert_type" class="form-control">
                        <option value="">جميع الأنواع</option>
                        <?php
                        $notifAllowedTypes = $alertEngine->getRoleBasedFilter($userRole, $userId);
                        $notifTypeList = is_array($notifAllowedTypes['alert_types']) ? $notifAllowedTypes['alert_types'] : ['academic','behavior','attendance','promotion','eqi','climate','teaching','improvement_plan'];
                        $notifTypeLabels = [
                            'academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور',
                            'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع',
                            'climate' => 'المناخ المدرسي', 'teaching' => 'تقييم المعلمين',
                            'improvement_plan' => 'خطط التحسين'
                        ];
                        foreach ($notifTypeList as $nt): ?>
                        <option value="<?= $nt ?>" <?= ($alertFilters['alert_type'] ?? '') === $nt ? 'selected' : '' ?>><?= $notifTypeLabels[$nt] ?? $nt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:140px;">
                    <select name="severity" class="form-control">
                        <option value="">جميع المستويات</option>
                        <option value="info" <?= ($alertFilters['severity'] ?? '') === 'info' ? 'selected' : '' ?>>معلوماتي</option>
                        <option value="warning" <?= ($alertFilters['severity'] ?? '') === 'warning' ? 'selected' : '' ?>>تحذير</option>
                        <option value="danger" <?= ($alertFilters['severity'] ?? '') === 'danger' ? 'selected' : '' ?>>خطر</option>
                        <option value="critical" <?= ($alertFilters['severity'] ?? '') === 'critical' ? 'selected' : '' ?>>حرج</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">🔍 فلترة</button>
                <a href="?tab=<?= e($tab) ?>" class="btn btn-sm btn-outline-secondary">✕ مسح</a>
            </div>
        </form>

        <?php if (empty($alerts ?? [])): ?>
            <div class="empty-state">
                <div class="empty-state-icon">✅</div>
                <p class="empty-state-text">لا توجد تنبيهات</p>
            </div>
        <?php else: ?>
        <div style="max-height:500px;overflow-y:auto;">
            <?php foreach ($alerts as $alert): ?>
            <?php
            $severityColors = ['critical' => 'danger', 'danger' => 'danger', 'warning' => 'warning', 'info' => 'info'];
            $sevColor = $severityColors[$alert['severity']] ?? 'secondary';
            $sevIcons = ['critical' => '🔴', 'danger' => '🟠', 'warning' => '🟡', 'info' => '🔵'];
            $sevIcon = $sevIcons[$alert['severity']] ?? '⚪';
            $typeLabels = ['academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور', 'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع', 'climate' => 'المناخ المدرسي', 'teaching' => 'تقييم المعلمين', 'improvement_plan' => 'خطط التحسين', 'low_grade' => 'درجات', 'failing_subject' => 'رسوب', 'high_absence' => 'غياب', 'consecutive_absence' => 'غياب متكرر', 'poor_behavior' => 'سلوك', 'promotion_risk' => 'ترفيع', 'eqi_decline' => 'انخفاض EQI', 'attendance_below_threshold' => 'حضور', 'behavior_below_threshold' => 'سلوك'];
            $typeLabel = $typeLabels[$alert['alert_type']] ?? $alert['alert_type'];
            ?>
            <div class="notification-item d-flex justify-content-between align-items-start p-3 border-bottom"
                 style="border-right:4px solid var(--<?= $sevColor ?>); border-radius: var(--radius-md, 8px); margin:4px 0;">
                <div style="display:flex;gap:12px;flex:1;">
                    <div style="font-size:20px;"><?= $sevIcon ?></div>
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h5 style="margin:0;font-size:14px;">
                                <?= e($alert['title_ar']) ?>
                                <span class="badge badge-<?= $sevColor ?>" style="font-size:10px;"><?= e($typeLabel) ?></span>
                                <?php if ($alert['school_name'] ?? false): ?>
                                <span style="font-size:11px;color:var(--text-muted);">🏫 <?= e($alert['school_name']) ?></span>
                                <?php endif; ?>
                            </h5>
                        </div>
                        <p style="margin:4px 0;font-size:13px;" class="text-muted"><?= e($alert['message_ar']) ?></p>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <small class="text-muted"><?= formatDateAr($alert['created_at'], 'medium') ?></small>
                            <?php if (!$alert['is_read']): ?>
                            <span class="badge badge-warning" style="font-size:9px;">جديد</span>
                            <?php endif; ?>
                            <?php if ($alert['is_resolved']): ?>
                            <span class="badge badge-success" style="font-size:9px;">تم الحل</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?><!-- /alerts tab -->

<script>
// إدارة صوت الإشعارات مع دعم المتصفحات الحديثة (user gesture)
(function() {
    let audioCtx = null;

    function playNotifSound() {
        if (localStorage.getItem('eqas_notif_sound') !== 'on') return;
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.frequency.value = 800;
            gain.gain.value = 0.1;
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
        } catch(e) {}
    }

    function updateSoundButton(state) {
        const icon = document.getElementById('sound-icon');
        const btn = document.getElementById('sound-toggle');
        if (!icon || !btn) return;
        if (state === 'on') {
            icon.textContent = '🔊';
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-outline-success');
        } else {
            icon.textContent = '🔇';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-outline-secondary');
        }
    }

    // تهيئة زر الصوت
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.getElementById('sound-toggle')) return;
        const soundState = localStorage.getItem('eqas_notif_sound') || 'off';
        updateSoundButton(soundState);
    });

    // ربط زر الصوت
    window.toggleNotifSound = function() {
        const current = localStorage.getItem('eqas_notif_sound') || 'off';
        const next = current === 'on' ? 'off' : 'on';
        localStorage.setItem('eqas_notif_sound', next);
        updateSoundButton(next);
        if (next === 'on') playNotifSound();
    };

    <?php if ($unreadCount > 0): ?>
    // تأجيل الصوت لأول تفاعل مستخدم (للمتصفحات الحديثة تمنع auto-play)
    const firstInteraction = function() {
        playNotifSound();
        document.removeEventListener('click', firstInteraction);
        document.removeEventListener('keydown', firstInteraction);
    };
    document.addEventListener('click', firstInteraction);
    document.addEventListener('keydown', firstInteraction);
    <?php endif; ?>
})();
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
