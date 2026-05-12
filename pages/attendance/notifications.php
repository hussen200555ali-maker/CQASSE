<?php
/**
 * ============================================================
 * إشعارات الغياب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض سجل إشعارات الغياب المرسلة لأولياء الأمور
 */

$pageTitle = 'إشعارات الغياب';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'إشعارات الغياب' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// ============================================================
// معالجة تحديد الإشعار كمقروء
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE absence_notifications SET is_read = 1 WHERE id = ?");
            $stmt->execute([$notifId]);
            setFlash('تم تحديث حالة الإشعار', 'success');
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء التحديث', 'danger');
        }
    }
}

// معالجة تحديد الكل كمقروء
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        try {
            $sql = "UPDATE absence_notifications SET is_read = 1 WHERE is_read = 0 AND is_active = 1";
            $params = [];
            if ($schoolId && $userRole !== 'group_admin') {
                $sql .= " AND student_id IN (SELECT id FROM students WHERE school_id = ?)";
                $params[] = $schoolId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            setFlash('تم تحديد جميع الإشعارات كمقروءة', 'success');
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء التحديث', 'danger');
        }
    }
}

// ============================================================
// معالجة إرسال إشعار جديد
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $notifStudent = (int)($_POST['student_id'] ?? 0);
        $notifType    = $_POST['notification_type'] ?? '';
        $notifCount   = (int)($_POST['absence_count'] ?? 0);

        if ($notifStudent && in_array($notifType, ['consecutive', 'threshold', 'weekly'])) {
            try {
                // جلب ولي الأمر
                $stmt = $db->prepare("
                    SELECT ps.parent_id FROM parent_students ps
                    WHERE ps.student_id = ? AND ps.is_active = 1 LIMIT 1
                ");
                $stmt->execute([$notifStudent]);
                $parent = $stmt->fetch();

                if ($parent) {
                    $stmt = $db->prepare("
                        INSERT INTO absence_notifications 
                            (student_id, parent_id, absence_count, notification_type, sent_at, is_read, is_active)
                        VALUES (?, ?, ?, ?, NOW(), 0, 1)
                    ");
                    $stmt->execute([$notifStudent, $parent['parent_id'], $notifCount, $notifType]);
                    setFlash('تم إرسال الإشعار بنجاح', 'success');
                } else {
                    setFlash('لم يتم العثور على ولي أمر لهذا الطالب', 'warning');
                }
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء إرسال الإشعار', 'danger');
            }
        } else {
            setFlash('يرجى ملء جميع الحقول المطلوبة', 'warning');
        }
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================
$filterType = $_GET['type'] ?? '';
$filterRead = $_GET['read'] ?? '';

// بناء الاستعلام
$sql = "SELECT an.*, s.full_name_ar AS student_name, s.student_number,
               s.guardian_name, s.guardian_phone,
               u.full_name_ar AS parent_name
        FROM absence_notifications an
        INNER JOIN students s ON s.id = an.student_id
        LEFT JOIN users u ON u.id = an.parent_id
        WHERE an.is_active = 1";
$params = [];

if ($schoolId && $userRole !== 'group_admin') {
    $sql .= " AND s.school_id = ?";
    $params[] = $schoolId;
}

if ($filterType) {
    $sql .= " AND an.notification_type = ?";
    $params[] = $filterType;
}
if ($filterRead !== '') {
    $sql .= " AND an.is_read = ?";
    $params[] = (int)$filterRead;
}

$sql .= " ORDER BY an.sent_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}

// إحصائيات
$stats = ['total' => 0, 'unread' => 0, 'consecutive' => 0, 'threshold' => 0, 'weekly' => 0];
try {
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN notification_type = 'consecutive' THEN 1 ELSE 0 END) as consecutive,
                SUM(CASE WHEN notification_type = 'threshold' THEN 1 ELSE 0 END) as threshold_count,
                SUM(CASE WHEN notification_type = 'weekly' THEN 1 ELSE 0 END) as weekly
            FROM absence_notifications WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND student_id IN (SELECT id FROM students WHERE school_id = ?)";
        $params[] = $schoolId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $s = $stmt->fetch();
    if ($s) {
        $stats = $s;
    }
} catch (Exception $e) {}

// جلب الطلاب للنموذج
$studentsList = [];
try {
    $sql = "SELECT id, student_number, full_name_ar FROM students WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY full_name_ar LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

// ترجمة نوع الإشعار
function getNotifTypeAr($type) {
    return ['consecutive' => 'غياب متتالي', 'threshold' => 'نسبة دون الحد', 'weekly' => 'تقرير أسبوعي'][$type] ?? $type;
}
?>

<!-- إحصائيات سريعة -->
<div class="section">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700;"><?= $stats['total'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">إجمالي الإشعارات</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--danger);"><?= $stats['unread'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">غير مقروءة</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--warning);"><?= $stats['consecutive'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">غياب متتالي</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:16px;">
                <div style="font-size:24px; font-weight:700; color:var(--info);"><?= $stats['threshold_count'] ?></div>
                <div style="font-size:12px; color:var(--text-muted);">نسبة دون الحد</div>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- قائمة الإشعارات -->
    <div class="lg:col-span-2 section">
        <div class="card">
            <div class="card-header">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <h3 class="card-title">🔔 إشعارات الغياب</h3>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" name="mark_all_read" class="btn btn-sm btn-light"
                                onclick="return confirm('تحديد جميع الإشعارات كمقروءة؟')">
                            ✅ تحديد الكل كمقروء
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <!-- فلاتر -->
                <div style="padding:12px 16px; border-bottom:1px solid var(--border-color); display:flex; gap:8px; flex-wrap:wrap;">
                    <a href="notifications.php" class="btn btn-sm <?= !$filterType ? 'btn-primary' : 'btn-light' ?>">الكل</a>
                    <a href="notifications.php?type=consecutive" class="btn btn-sm <?= $filterType === 'consecutive' ? 'btn-primary' : 'btn-light' ?>">غياب متتالي</a>
                    <a href="notifications.php?type=threshold" class="btn btn-sm <?= $filterType === 'threshold' ? 'btn-primary' : 'btn-light' ?>">نسبة دون الحد</a>
                    <a href="notifications.php?type=weekly" class="btn btn-sm <?= $filterType === 'weekly' ? 'btn-primary' : 'btn-light' ?>">تقرير أسبوعي</a>
                    <a href="notifications.php?read=0" class="btn btn-sm <?= $filterRead === '0' ? 'btn-warning' : 'btn-light' ?>">غير مقروءة</a>
                </div>

                <?php if (empty($notifications)): ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px;">🔔</div>
                    لا توجد إشعارات غياب
                </div>
                <?php else: ?>
                <div style="max-height:500px; overflow-y:auto;">
                    <?php foreach ($notifications as $notif):
                        $typeColor = ['consecutive' => 'warning', 'threshold' => 'danger', 'weekly' => 'info'][$notif['notification_type']] ?? 'secondary';
                        $isRead = (bool)$notif['is_read'];
                    ?>
                    <div style="padding:12px 16px; border-bottom:1px solid var(--border-color); display:flex; gap:12px; align-items:flex-start; background:<?= $isRead ? 'transparent' : 'rgba(13,202,240,0.05)' ?>;">
                        <div style="flex-shrink:0; margin-top:4px;">
                            <span class="badge badge-<?= $typeColor ?>"><?= getNotifTypeAr($notif['notification_type']) ?></span>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-weight:600; display:flex; align-items:center; gap:6px;">
                                <?= e($notif['student_name']) ?>
                                <?php if (!$isRead): ?>
                                <span style="width:8px; height:8px; background:var(--primary); border-radius:50%; display:inline-block;"></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                                رقم الطالب: <?= e($notif['student_number']) ?> • 
                                ولي الأمر: <?= e($notif['parent_name'] ?? $notif['guardian_name'] ?? 'غير محدد') ?>
                                <?php if ($notif['guardian_phone']): ?>
                                • 📞 <?= e($notif['guardian_phone']) ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">
                                عدد أيام الغياب: <strong><?= $notif['absence_count'] ?></strong> يوم
                                • أُرسل: <?= formatDateAr($notif['sent_at'], 'medium') ?>
                            </div>
                        </div>
                        <div style="flex-shrink:0;">
                            <?php if (!$isRead): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                                <button type="submit" name="mark_read" class="btn btn-sm btn-light" title="تحديد كمقروء">
                                    ✅
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="font-size:11px; color:var(--text-muted);">مقروء</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- نموذج إرسال إشعار جديد -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📤 إرسال إشعار جديد</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">الطالب:</label>
                        <select name="student_id" class="form-control" required>
                            <option value="">-- اختر الطالب --</option>
                            <?php foreach ($studentsList as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= e($st['full_name_ar']) ?> (<?= e($st['student_number']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">نوع الإشعار:</label>
                        <select name="notification_type" class="form-control" required>
                            <option value="consecutive">غياب متتالي</option>
                            <option value="threshold">نسبة حضور دون الحد</option>
                            <option value="weekly">تقرير أسبوعي</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">عدد أيام الغياب:</label>
                        <input type="number" name="absence_count" class="form-control" min="1" max="100" value="3" required>
                    </div>
                    <button type="submit" name="send_notification" class="btn btn-primary" style="width:100%;">
                        📤 إرسال الإشعار
                    </button>
                </form>
            </div>
        </div>

        <!-- سجل أولياء الأمور -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h3 class="card-title">👨‍👩‍👧 سجل التواصل</h3>
            </div>
            <div class="card-body">
                <?php
                // جلب آخر الإشعارات المرسلة حسب ولي الأمر
                try {
                    $sql = "SELECT u.full_name_ar AS parent_name, COUNT(*) as notif_count,
                                   MAX(an.sent_at) as last_sent
                            FROM absence_notifications an
                            LEFT JOIN users u ON u.id = an.parent_id
                            WHERE an.is_active = 1";
                    $params = [];
                    if ($schoolId && $userRole !== 'group_admin') {
                        $sql .= " AND an.student_id IN (SELECT id FROM students WHERE school_id = ?)";
                        $params[] = $schoolId;
                    }
                    $sql .= " GROUP BY an.parent_id ORDER BY last_sent DESC LIMIT 10";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $parentHistory = $stmt->fetchAll();
                } catch (Exception $e) {
                    $parentHistory = [];
                }

                if (empty($parentHistory)):
                ?>
                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;">
                    لا يوجد سجل تواصل بعد
                </div>
                <?php else: ?>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php foreach ($parentHistory as $ph): ?>
                    <div style="padding:6px 0; border-bottom:1px solid var(--border-color); font-size:13px;">
                        <div style="font-weight:600;"><?= e($ph['parent_name'] ?? 'غير معروف') ?></div>
                        <div style="color:var(--text-muted);">
                            <?= $ph['notif_count'] ?> إشعار • آخر إرسال: <?= formatDateAr($ph['last_sent'], 'short') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
