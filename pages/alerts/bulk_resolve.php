<?php
/**
 * ============================================================
 * EQAS - حل جماعي للتنبيهات
 * نظام التقييم الشامل - Bulk Resolve Alerts
 * ============================================================
 */

$pageTitle = 'حل جماعي للتنبيهات';
$breadcrumb = ['التنبيهات الذكية' => 'index.php', 'حل جماعي' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// فلاتر للحصول على التنبيهات غير المحلولة
$filters = [
    'is_resolved' => '0',
    'per_page' => 100,
];
if (in_array($userRole, ['school_admin', 'teacher']) && $userSchoolId) {
    $filters['school_id'] = $userSchoolId;
}

if (!empty($_GET['alert_type'])) $filters['alert_type'] = $_GET['alert_type'];
if (!empty($_GET['severity'])) $filters['severity'] = $_GET['severity'];

$alerts = $alertEngine->getAlerts($filters);

// معالجة الحل الجماعي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $selectedIds = $_POST['alert_ids'] ?? [];
        
        if (empty($selectedIds)) {
            setFlash('يرجى اختيار تنبيه واحد على الأقل.', 'warning');
        } else {
            $selectedIds = array_map('intval', $selectedIds);
            
            if ($_POST['action'] === 'resolve_selected') {
                $resolved = $alertEngine->resolveMultiple($selectedIds, $_SESSION['user']['id']);
                setFlash(sprintf('تم حل %d تنبيه بنجاح.', $resolved), 'success');
            } elseif ($_POST['action'] === 'mark_read_selected') {
                $marked = $alertEngine->markMultipleAsRead($selectedIds);
                setFlash(sprintf('تم تحديد %d تنبيه كمقروء.', $marked), 'success');
            }
        }
        redirect('bulk_resolve.php');
    }
}

$severityStyles = [
    'critical' => ['bg' => '#dc3545', 'color' => '#fff', 'label' => 'حرج'],
    'danger'   => ['bg' => '#fd7e14', 'color' => '#fff', 'label' => 'خطر'],
    'warning'  => ['bg' => '#ffc107', 'color' => '#000', 'label' => 'تحذير'],
    'info'     => ['bg' => '#17a2b8', 'color' => '#fff', 'label' => 'معلوماتي'],
];
$typeLabels = ['academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور', 'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع'];
?>

<div class="section">
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title">✅ حل جماعي للتنبيهات</h3>
            <a href="index.php" class="btn btn-light btn-sm">↩️ العودة للقائمة</a>
        </div>
        <div class="card-body">
            <div class="mb-4 p-3" style="background:rgba(40,167,69,0.1); border-radius:var(--radius-md); border-right:4px solid var(--success);">
                <p style="margin:0;">اختر التنبيهات التي تريد حلها ثم اضغط على زر "حل المحدد". يمكنك أيضاً تحديد الكل أو تصفية حسب النوع أو الخطورة.</p>
            </div>
            
            <form method="GET" class="form-inline mb-4">
                <select name="alert_type" class="form-control" style="width:auto;">
                    <option value="">جميع الأنواع</option>
                    <?php foreach ($typeLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($_GET['alert_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="severity" class="form-control" style="width:auto;">
                    <option value="">جميع المستويات</option>
                    <?php foreach ($severityStyles as $k => $s): ?>
                    <option value="<?= $k ?>" <?= ($_GET['severity'] ?? '') === $k ? 'selected' : '' ?>><?= $s['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">🔍 تصفية</button>
            </form>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        
        <div class="card">
            <div class="card-header">
                <div class="flex items-center gap-3">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" id="select-all" onclick="toggleAll(this)">
                        <span class="form-check-label" style="font-weight:bold;">تحديد الكل</span>
                    </label>
                    <span class="text-secondary">(<?= count($alerts) ?> تنبيه غير محلول)</span>
                </div>
                <div class="flex gap-2">
                    <button type="submit" name="action" value="mark_read_selected" class="btn btn-info btn-sm" onclick="return confirmAction('تحديد كمقروء')">👁️ تحديد كمقروء</button>
                    <button type="submit" name="action" value="resolve_selected" class="btn btn-success btn-sm" onclick="return confirmAction('حل')">✅ حل المحدد</button>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($alerts)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">✅</div>
                    <div class="table-empty-text">لا توجد تنبيهات غير محلولة</div>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>الخطورة</th>
                                <th>النوع</th>
                                <th>العنوان</th>
                                <th>الطالب</th>
                                <th>المدرسة</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): 
                                $sev = $severityStyles[$alert['severity']] ?? $severityStyles['info'];
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="alert_ids[]" value="<?= $alert['id'] ?>" class="form-check-input alert-checkbox">
                                </td>
                                <td>
                                    <span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $sev['bg'] ?>; color:<?= $sev['color'] ?>"><?= $sev['label'] ?></span>
                                </td>
                                <td><?= $typeLabels[$alert['alert_type']] ?? '' ?></td>
                                <td><?= e(truncate($alert['title_ar'], 50)) ?></td>
                                <td><?= e($alert['student_name'] ?? '-') ?></td>
                                <td><?= e($alert['school_name'] ?? '-') ?></td>
                                <td style="font-size:12px;"><?= formatDateAr($alert['created_at'], 'short') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php
$pageJs = "
function toggleAll(el) {
    document.querySelectorAll('.alert-checkbox').forEach(cb => cb.checked = el.checked);
}
function confirmAction(action) {
    var checked = document.querySelectorAll('.alert-checkbox:checked').length;
    if (checked === 0) { alert('يرجى اختيار تنبيه واحد على الأقل.'); return false; }
    return confirm('هل تريد " + action + " " + checked + " تنبيه؟');
}
";
require_once __DIR__ . '/../../includes/footer.php';
