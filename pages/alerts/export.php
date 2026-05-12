<?php
/**
 * ============================================================
 * EQAS - تصدير التنبيهات
 * نظام التقييم الشامل - Export Alerts
 * ============================================================
 */

$pageTitle = 'تصدير التنبيهات';
$breadcrumb = ['التنبيهات الذكية' => 'index.php', 'تصدير' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $filters = [];
        if (!empty($_POST['school_id'])) $filters['school_id'] = $_POST['school_id'];
        if (!empty($_POST['alert_type'])) $filters['alert_type'] = $_POST['alert_type'];
        if (!empty($_POST['severity'])) $filters['severity'] = $_POST['severity'];
        if (isset($_POST['is_resolved']) && $_POST['is_resolved'] !== '') $filters['is_resolved'] = $_POST['is_resolved'];
        
        $format = $_POST['format'] ?? 'csv';
        
        $csv = $alertEngine->exportAlerts($filters, $format);
        
        if ($csv) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="alerts_export_' . date('Y-m-d_His') . '.csv"');
            echo $csv;
            exit;
        } else {
            setFlash('لا توجد بيانات للتصدير.', 'warning');
        }
    }
}

$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
        $schools = $stmt->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📥 تصدير تقرير التنبيهات</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                
                <div class="form-row">
                    <?php if ($userRole === 'group_admin'): ?>
                    <div class="form-group">
                        <label class="form-label">المدرسة</label>
                        <select name="school_id" class="form-control">
                            <option value="">جميع المدارس</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">نوع التنبيه</label>
                        <select name="alert_type" class="form-control">
                            <option value="">جميع الأنواع</option>
                            <option value="academic">أكاديمي</option>
                            <option value="behavior">سلوكي</option>
                            <option value="attendance">حضور</option>
                            <option value="eqi">مؤشر الجودة</option>
                            <option value="promotion">ترفيع</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">مستوى الخطورة</label>
                        <select name="severity" class="form-control">
                            <option value="">جميع المستويات</option>
                            <option value="critical">حرج</option>
                            <option value="danger">خطر</option>
                            <option value="warning">تحذير</option>
                            <option value="info">معلوماتي</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حالة الحل</label>
                        <select name="is_resolved" class="form-control">
                            <option value="">الكل</option>
                            <option value="0">غير محلول</option>
                            <option value="1">محلول</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">صيغة التصدير</label>
                    <div class="flex gap-4">
                        <label class="form-check">
                            <input type="radio" name="format" value="csv" checked class="form-check-input">
                            <span class="form-check-label">CSV (Excel)</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" name="export" value="1" class="btn btn-primary btn-lg">
                    📥 تصدير التقرير
                </button>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
