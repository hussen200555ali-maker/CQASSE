<?php
/**
 * ============================================================
 * EQAS - الإشعارات - مركز الإشعارات
 * نظام التقييم الشامل - صفحة مركز الإشعارات
 * ============================================================
 */

$pageTitle = 'الإشعارات';
$breadcrumb = ['الإشعارات' => null];

require_once __DIR__ . '/../../includes/header.php';

requireLogin();

// جميع الأدوار يمكنهم رؤية إشعاراتهم
$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3

// فلاتر
$filterType = $_GET['type'] ?? '';
$filterRead = $_GET['read'] ?? '';
$filterSearch = $_GET['search'] ?? '';

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

    if (isset($_POST['export_notifications'])) {
        // تصدير الإشعارات كملف CSV
        $exportSql = "SELECT id, type, title_ar, message_ar, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
        $exportStmt = $db->prepare($exportSql);
        $exportStmt->execute([$userId]);
        $exportData = $exportStmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=notifications_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Arabic
        fputcsv($output, ['المعرف', 'النوع', 'العنوان', 'الرسالة', 'الحالة', 'التاريخ']);
        foreach ($exportData as $row) {
            fputcsv($output, [
                $row['id'],
                $row['type'],
                $row['title_ar'],
                $row['message_ar'],
                $row['is_read'] ? 'مقروء' : 'غير مقروء',
                $row['created_at']
            ]);
        }
        fclose($output);
        logActivity($db, $userId, 'notifications_export', 'تصدير الإشعارات', 'notification', null);
        exit;
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

$sql .= " ORDER BY created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// عدد الإشعارات غير المقروءة - إصلاح استدعاء prepare المكرر
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

// أنواع الإشعارات
$typeLabels = [
    'alert' => ['label' => 'تنبيه', 'icon' => '🚨', 'color' => 'danger'],
    'announcement' => ['label' => 'إعلان', 'icon' => '📢', 'color' => 'info'],
    'reminder' => ['label' => 'تذكير', 'icon' => '⏰', 'color' => 'warning'],
    'system' => ['label' => 'نظام', 'icon' => '⚙️', 'color' => 'secondary'],
    'acknowledgment' => ['label' => 'إقرار', 'icon' => '✅', 'color' => 'success'],
];

// روابط الكيانات المرتبطة
$entityLinks = [
    'student' => '/pages/students/view.php?id=',
    'class' => '/pages/classes/view.php?id=',
    'grade' => '/pages/grade_sheets/view.php?id=',
    'alert' => '/pages/smart_alerts/index.php?id=',
    'promotion' => '/pages/promotion/index.php?id=',
];
?>

<div class="card" style="border-radius: var(--radius-md, 8px);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">📨 الإشعارات <span class="badge badge-danger"><?= $unreadCount ?> جديد</span></h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <!-- زر صوت الإشعارات -->
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
        <!-- بحث في الإشعارات -->
        <form method="GET" class="mb-3">
            <div class="row">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" value="<?= e($filterSearch) ?>" placeholder="بحث في الإشعارات...">
                        <button class="btn btn-outline-secondary" type="submit">🔍</button>
                        <?php if ($filterSearch): ?>
                        <a href="index.php" class="btn btn-outline-danger" title="مسح البحث">✕</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <!-- فلاتر -->
        <div class="btn-group mb-4" style="flex-wrap:wrap;">
            <a href="index.php" class="btn btn-sm <?php echo !$filterType && !$filterRead ? 'btn-primary' : 'btn-outline-primary'; ?>">الكل</a>
            <a href="?read=0" class="btn btn-sm <?php echo $filterRead === '0' ? 'btn-danger' : 'btn-outline-danger'; ?>">غير مقروء (<?php echo $unreadCount; ?>)</a>
            <a href="?read=1" class="btn btn-sm <?php echo $filterRead === '1' ? 'btn-success' : 'btn-outline-success'; ?>">مقروء</a>
            <?php foreach ($typeLabels as $type => $info): ?>
            <a href="?type=<?php echo $type; ?>" class="btn btn-sm <?php echo $filterType === $type ? 'btn-' . $info['color'] : 'btn-outline-' . $info['color']; ?>">
                <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- قائمة الإشعارات مجمعة حسب التاريخ -->
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

            <?php if (count($notifications) >= 100): ?>
            <div class="text-center mt-3">
                <p class="text-muted" style="font-size:12px;">يتم عرض آخر 100 إشعار. استخدم الفلاتر لتضييق النطاق أو صدر الإشعارات لعرض الكل.</p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// إدارة صوت الإشعارات
function toggleNotifSound() {
    const current = localStorage.getItem('eqas_notif_sound') || 'off';
    const next = current === 'on' ? 'off' : 'on';
    localStorage.setItem('eqas_notif_sound', next);
    updateSoundButton(next);
}

function updateSoundButton(state) {
    const icon = document.getElementById('sound-icon');
    const btn = document.getElementById('sound-toggle');
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
    const soundState = localStorage.getItem('eqas_notif_sound') || 'off';
    updateSoundButton(soundState);
});

// تشغيل صوت عند إشعار جديد (إذا كان مفعلاً)
<?php if ($unreadCount > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('eqas_notif_sound') === 'on') {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 800;
            gain.gain.value = 0.1;
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch(e) {}
    }
});
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
