<?php
/**
 * ============================================================
 * EQAS - عرض تنبيه
 * نظام التقييم الشامل - View Alert Detail
 * ============================================================
 */

$pageTitle = 'تفاصيل التنبيه';
$breadcrumb = ['التنبيهات الذكية' => 'index.php', 'تفاصيل التنبيه' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);
$alertId = (int)($_GET['id'] ?? 0);

if (!$alertId) {
    setFlash('معرف التنبيه غير صالح.', 'danger');
    redirect('index.php');
}

$alert = $alertEngine->getAlert($alertId);

if (!$alert) {
    setFlash('التنبيه غير موجود.', 'danger');
    redirect('index.php');
}

// السماح للمدير العام بمشاهدة جميع التنبيهات
$userRole = $_SESSION['user']['role'] ?? '';
if ($userRole !== 'group_admin' && $alert['school_id'] != $_SESSION['user']['school_id']) {
    setFlash('ليس لديك صلاحية لعرض هذا التنبيه.', 'danger');
    redirect('index.php');
}

// تحديد كمقروء عند الفتح
if (!$alert['is_read']) {
    $alertEngine->markAsRead($alertId);
}

// الحصول على التنبيهات المرتبطة
$relatedAlerts = [];
if ($alert['student_id']) {
    $relatedAlerts = $alertEngine->getRelatedAlerts($alert['student_id'], $alertId, 5);
}

// معالجة حل التنبيه
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        if ($_POST['action'] === 'resolve') {
            $notes = trim($_POST['notes'] ?? '');
            $alertEngine->resolveAlert($alertId, $_SESSION['user']['id'], $notes);
            setFlash('تم حل التنبيه بنجاح.', 'success');
            redirect('view.php?id=' . $alertId);
        }
    }
    redirect('view.php?id=' . $alertId);
}

$severityStyles = [
    'critical' => ['bg' => '#dc3545', 'color' => '#fff', 'label' => 'حرج', 'icon' => '🔴'],
    'danger'   => ['bg' => '#fd7e14', 'color' => '#fff', 'label' => 'خطر', 'icon' => '🟠'],
    'warning'  => ['bg' => '#ffc107', 'color' => '#000', 'label' => 'تحذير', 'icon' => '🟡'],
    'info'     => ['bg' => '#17a2b8', 'color' => '#fff', 'label' => 'معلوماتي', 'icon' => '🔵'],
];
$sevStyle = $severityStyles[$alert['severity']] ?? $severityStyles['info'];

$typeLabels = ['academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور', 'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع'];
$typeIcons = ['academic' => '📚', 'behavior' => '⭐', 'attendance' => '📋', 'eqi' => '📊', 'promotion' => '📈'];
?>

<div class="section">
    <!-- معلومات التنبيه الرئيسية -->
    <div class="card mb-6">
        <div class="card-header" style="background:<?= $sevStyle['bg'] ?>; color:<?= $sevStyle['color'] ?>;">
            <div class="flex items-center gap-3">
                <span style="font-size:28px;"><?= $sevStyle['icon'] ?></span>
                <div>
                    <h3 style="margin:0; color:<?= $sevStyle['color'] ?>;"><?= e($alert['title_ar']) ?></h3>
                    <span style="font-size:13px; opacity:0.9;"><?= $sevStyle['label'] ?> · <?= $typeIcons[$alert['alert_type']] ?? '' ?> <?= $typeLabels[$alert['alert_type']] ?? '' ?></span>
                </div>
            </div>
            <div class="flex gap-2">
                <?php if (!$alert['is_resolved']): ?>
                <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('resolve-form').style.display='block'">✅ حل التنبيه</button>
                <?php else: ?>
                <span class="btn btn-success btn-sm" disabled>✅ تم الحل</span>
                <?php endif; ?>
                <a href="index.php" class="btn btn-light btn-sm">↩️ العودة</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-2 gap-6">
                <div>
                    <h4 style="margin-bottom:12px;">📋 تفاصيل التنبيه</h4>
                    <div class="flex flex-col gap-3">
                        <div>
                            <span class="text-secondary" style="font-size:13px;">الرسالة:</span>
                            <p style="margin:4px 0 0; line-height:1.8;"><?= e($alert['message_ar']) ?></p>
                        </div>
                        <div class="divider"></div>
                        <div class="grid grid-2 gap-3">
                            <div>
                                <span class="text-secondary" style="font-size:12px;">المدرسة:</span>
                                <div style="font-weight:500;"><?= e($alert['school_name'] ?? 'غير محدد') ?></div>
                            </div>
                            <div>
                                <span class="text-secondary" style="font-size:12px;">الطالب:</span>
                                <div style="font-weight:500;"><?= e($alert['student_name'] ?? 'غير محدد') ?></div>
                            </div>
                            <div>
                                <span class="text-secondary" style="font-size:12px;">رقم الطالب:</span>
                                <div><?= e($alert['student_number'] ?? '-') ?></div>
                            </div>
                            <div>
                                <span class="text-secondary" style="font-size:12px;">تاريخ الإنشاء:</span>
                                <div><?= formatDateAr($alert['created_at'], 'medium') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 style="margin-bottom:12px;">📊 حالة التنبيه</h4>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center gap-2">
                            <span>📩</span>
                            <span class="text-secondary">القراءة:</span>
                            <span style="font-weight:500; color:<?= $alert['is_read'] ? 'var(--success)' : 'var(--warning)' ?>">
                                <?= $alert['is_read'] ? 'مقروء' : 'غير مقروء' ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span>✅</span>
                            <span class="text-secondary">الحل:</span>
                            <span style="font-weight:500; color:<?= $alert['is_resolved'] ? 'var(--success)' : 'var(--danger)' ?>">
                                <?= $alert['is_resolved'] ? 'محلول' : 'غير محلول' ?>
                            </span>
                        </div>
                        <?php if ($alert['is_resolved']): ?>
                        <div class="flex items-center gap-2">
                            <span>👤</span>
                            <span class="text-secondary">تم الحل بواسطة:</span>
                            <span style="font-weight:500;"><?= e($alert['resolver_name'] ?? 'غير معروف') ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span>📅</span>
                            <span class="text-secondary">تاريخ الحل:</span>
                            <span><?= formatDateAr($alert['resolved_at'], 'medium') ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نموذج حل التنبيه -->
    <?php if (!$alert['is_resolved']): ?>
    <div class="card mb-6" id="resolve-form" style="display:none;">
        <div class="card-header">
            <h3 class="card-title">✅ حل التنبيه</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="resolve">
                <div class="form-group">
                    <label class="form-label">ملاحظات الحل</label>
                    <textarea name="notes" class="form-control" rows="4" placeholder="أدخل ملاحظات حول كيفية حل التنبيه..."></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-success">✅ تأكيد الحل</button>
                    <button type="button" class="btn btn-light" onclick="document.getElementById('resolve-form').style.display='none'">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- التنبيهات المرتبطة -->
    <?php if (!empty($relatedAlerts)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔗 تنبيهات مرتبطة</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الخطورة</th>
                            <th>النوع</th>
                            <th>العنوان</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th>عرض</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($relatedAlerts as $rel): 
                            $relSev = $severityStyles[$rel['severity']] ?? $severityStyles['info'];
                        ?>
                        <tr>
                            <td>
                                <span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $relSev['bg'] ?>; color:<?= $relSev['color'] ?>">
                                    <?= $relSev['label'] ?>
                                </span>
                            </td>
                            <td><?= $typeIcons[$rel['alert_type']] ?? '' ?> <?= $typeLabels[$rel['alert_type']] ?? '' ?></td>
                            <td><?= e(truncate($rel['title_ar'], 50)) ?></td>
                            <td><?= $rel['is_resolved'] ? '✅ محلول' : '⏳ معلق' ?></td>
                            <td style="font-size:12px;"><?= formatDateAr($rel['created_at'], 'short') ?></td>
                            <td><a href="view.php?id=<?= $rel['id'] ?>" class="btn btn-sm btn-light">👁️</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
