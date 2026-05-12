<?php
/**
 * ============================================================
 * EQAS - النسخ الاحتياطي - إنشاء نسخة
 * نظام التقييم الشامل - صفحة إنشاء نسخة احتياطية
 * ============================================================
 */

$pageTitle = 'إنشاء نسخة احتياطية';
$breadcrumb = ['النسخ الاحتياطي' => 'index.php', 'إنشاء نسخة' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/backup.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$backupManager = new BackupManager($db);
$backupType = $_GET['type'] ?? $_POST['backup_type'] ?? 'full';
$validTypes = ['full', 'pre_operation', 'manual'];
if (!in_array($backupType, $validTypes)) $backupType = 'full';

// جلب الجداول
$tablesInfo = $backupManager->getTablesWithRowCounts();

// معالجة الإنشاء
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('create.php?type=' . $backupType);
    }

    $userId = $_SESSION['user']['id'];
    $result = null;

    switch ($backupType) {
        case 'full':
            $result = $backupManager->createFullBackup($userId);
            break;

        case 'pre_operation':
            $operation = $_POST['operation'] ?? 'general';
            $result = $backupManager->createPreOperationBackup($operation, $userId);
            break;

        case 'manual':
            $selectedTables = $_POST['tables'] ?? [];
            if (empty($selectedTables)) {
                setFlash('يرجى اختيار جدول واحد على الأقل', 'warning');
                redirect('create.php?type=manual');
            }
            $result = $backupManager->createManualBackup($selectedTables, $userId);
            break;
    }

    if ($result && $result['success']) {
        logActivity($db, $userId, 'backup_create', 'إنشاء نسخة احتياطية: ' . $backupType, 'backup', $result['backup_id'] ?? null);
        setFlash('تم إنشاء النسخة الاحتياطية بنجاح! حجم الملف: ' .
            ($result['file_size'] > 1048576 ? round($result['file_size'] / 1048576, 2) . ' MB' : round($result['file_size'] / 1024, 1) . ' KB'), 'success');
        redirect('history.php');
    } else {
        setFlash($result['message'] ?? 'فشل إنشاء النسخة الاحتياطية', 'danger');
    }
}

$typeLabels = [
    'full' => ['label' => 'نسخة كاملة', 'icon' => '💾', 'color' => 'primary', 'desc' => 'نسخ جميع جداول وبيانات قاعدة البيانات بالكامل. يُنصح بها يومياً.'],
    'pre_operation' => ['label' => 'نسخة قبل عملية', 'icon' => '⚠️', 'color' => 'warning', 'desc' => 'نسخ الجداول المتأثرة فقط قبل إجراء عملية حساسة (ترفيع، إغلاق فصل، إلخ).'],
    'manual' => ['label' => 'نسخة يدوية', 'icon' => '🎯', 'color' => 'info', 'desc' => 'اختر الجداول التي تريد نسخها يدوياً. مفيد لتصدير بيانات محددة.'],
];

$currentType = $typeLabels[$backupType];

// تقدير الحجم
$estimatedSize = 0;
foreach ($tablesInfo as $t) {
    $estimatedSize += $t['rows'] * 200; // تقدير تقريبي
}
if ($backupType === 'manual') {
    $estimatedSize = 0;
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?php echo $currentType['icon']; ?> إنشاء <?php echo $currentType['label']; ?></h2>
    </div>
    <div class="card-body">
        <!-- اختيار نوع النسخة -->
        <div class="btn-group mb-4" style="width:100%;">
            <?php foreach ($typeLabels as $type => $info): ?>
            <a href="create.php?type=<?php echo $type; ?>"
               class="btn btn-<?php echo $type === $backupType ? $info['color'] : 'outline-' . $info['color']; ?>"
               style="flex:1;">
                <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-<?php echo $currentType['color']; ?>">
            <strong>ℹ️ <?php echo $currentType['label']; ?>:</strong>
            <?php echo $currentType['desc']; ?>
        </div>

        <form method="POST" id="backup-form">
            <?= csrfField() ?>
            <input type="hidden" name="backup_type" value="<?php echo $backupType; ?>">

            <?php if ($backupType === 'pre_operation'): ?>
            <!-- اختيار العملية -->
            <div class="form-group">
                <label class="form-label">العملية الحساسة</label>
                <select name="operation" class="form-control" required>
                    <option value="promotion">ترفيع الطلاب</option>
                    <option value="semester_close">إغلاق فصل دراسي</option>
                    <option value="eqi_calculate">حساب مؤشر الجودة</option>
                    <option value="bulk_grades">إدخال درجات جماعي</option>
                    <option value="bulk_attendance">تسجيل حضور جماعي</option>
                    <option value="settings_change">تغيير الإعدادات</option>
                    <option value="user_management">إدارة المستخدمين</option>
                    <option value="restore">قبل الاستعادة</option>
                    <option value="general">عملية عامة</option>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($backupType === 'manual'): ?>
            <!-- اختيار الجداول -->
            <div class="form-group">
                <label class="form-label">
                    اختر الجداول
                    <button type="button" class="btn btn-sm btn-link" onclick="toggleAllTables()">تحديد/إلغاء الكل</button>
                </label>
                <div class="row" style="max-height:400px;overflow-y:auto;">
                    <?php foreach ($tablesInfo as $ti): ?>
                    <div class="col-md-4 col-sm-6 mb-2">
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                            <input type="checkbox" name="tables[]" value="<?php echo e($ti['name']); ?>"
                                   class="table-checkbox" <?php echo $ti['rows'] > 0 ? 'checked' : ''; ?>>
                            <span><?php echo e($ti['name']); ?></span>
                            <span class="text-muted" style="font-size:11px;">(<?php echo number_format($ti['rows']); ?>)</span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($backupType === 'full'): ?>
            <!-- معلومات إضافية للنسخة الكاملة -->
            <div class="card bg-light mb-3">
                <div class="card-body">
                    <h5>📊 معلومات النسخة الكاملة</h5>
                    <ul style="margin:0;padding-right:20px;">
                        <li>عدد الجداول: <strong><?php echo count($tablesInfo); ?></strong></li>
                        <li>إجمالي الصفوف: <strong><?php echo number_format(array_sum(array_column($tablesInfo, 'rows'))); ?></strong></li>
                        <li>الحجم المقدّر: <strong><?php echo $estimatedSize > 1048576 ? round($estimatedSize / 1048576, 2) . ' MB' : round($estimatedSize / 1024, 1) . ' KB'; ?></strong></li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- تحذير -->
            <div class="alert alert-warning">
                <strong>⚠️ تنبيه:</strong>
                قد تستغرق العملية بعض الوقت حسب حجم البيانات. لا تغلق الصفحة حتى يتم الانتهاء.
            </div>

            <div class="mt-4" style="display:flex;gap:8px;">
                <button type="submit" name="create_backup" value="1" class="btn btn-<?php echo $currentType['color']; ?> btn-lg">
                    <?php echo $currentType['icon']; ?> إنشاء النسخة الاحتياطية
                </button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAllTables() {
    const checkboxes = document.querySelectorAll('.table-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
