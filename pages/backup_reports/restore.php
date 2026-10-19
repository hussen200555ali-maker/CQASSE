<?php
/**
 * ============================================================
 * EQAS - النسخ الاحتياطي - استعادة
 * نظام التقييم الشامل - صفحة استعادة من نسخة احتياطية
 * ============================================================
 */

$pageTitle = 'استعادة من نسخة احتياطية';
$breadcrumb = ['النسخ الاحتياطي' => 'index.php', 'استعادة' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/backup.php';

// التحقق من الصلاحيات - مدير النظام فقط
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$backupManager = new BackupManager($db);

// قائمة النسخ الاحتياطية المتاحة للاستعادة
$backups = $backupManager->listBackups(['status' => 'completed']);

// معالجة الاستعادة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('restore.php');
    }

    $backupId = (int)$_POST['backup_id'];
    $confirmText = trim($_POST['confirm_text'] ?? '');

    // التحقق من نص التأكيد
    if ($confirmText !== 'استعادة') {
        setFlash('يرجى كتابة كلمة "استعادة" للتأكيد', 'warning');
        redirect('restore.php');
    }

    // جلب معلومات النسخة
    $backup = $backupManager->getBackupById($backupId);

    if (!$backup) {
        setFlash('النسخة الاحتياطية غير موجودة', 'danger');
        redirect('restore.php');
    }

    // تحذير للنسخ الكبيرة
    $fileSizeMB = ($backup['file_size'] ?? 0) / (1024 * 1024);
    if ($fileSizeMB > BACKUP_MAX_SIZE_MB) {
        if (!isset($_POST['confirm_large'])) {
            setFlash('الملف كبير جداً (' . round($fileSizeMB, 2) . ' MB). يرجى تأكيد الاستعادة.', 'warning');
            redirect('restore.php');
        }
    }

    // تنفيذ الاستعادة
    $result = $backupManager->restoreBackup($backup['file_path'], $_SESSION['user']['id']);

    if ($result['success']) {
        logActivity($db, $_SESSION['user']['id'], 'backup_restore', 'استعادة من نسخة: ' . basename($backup['file_path']), 'backup', $backupId);
        setFlash('تمت الاستعادة بنجاح! عدد الاستعلامات المنفذة: ' . $result['queries_executed'], 'success');
    } else {
        setFlash('فشل الاستعادة: ' . $result['message'], 'danger');
    }
    redirect('history.php');
}

// معلومات نسخة محددة
$selectedBackup = null;
$backupInfo = null;
$selectedId = $_GET['id'] ?? '';
if ($selectedId) {
    $selectedBackup = $backupManager->getBackupById($selectedId);
    if ($selectedBackup) {
        $backupInfo = $backupManager->getBackupInfo($selectedBackup['file_path']);
    }
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">🔄 استعادة من نسخة احتياطية</h2>
    </div>
    <div class="card-body">
        <div class="alert alert-danger">
            <strong>⚠️ تحذير هام:</strong>
            عملية الاستعادة ستستبدل البيانات الحالية بالبيانات من النسخة الاحتياطية.
            سيتم إنشاء نسخة احتياطية تلقائياً قبل الاستعادة.
            <strong>هذه العملية لا يمكن التراجع عنها!</strong>
        </div>

        <form method="POST" id="restore-form">
            <?= csrfField() ?>

            <!-- اختيار النسخة -->
            <div class="form-group">
                <label class="form-label">اختر النسخة الاحتياطية</label>
                <select name="backup_id" class="form-control" required id="backup-select">
                    <option value="">-- اختر نسخة احتياطية --</option>
                    <?php foreach ($backups as $backup): ?>
                    <option value="<?php echo $backup['id']; ?>" <?php echo $selectedId == $backup['id'] ? 'selected' : ''; ?>
                            data-size="<?php echo $backup['file_size'] ?? 0; ?>">
                        <?php
                        $typeLabels = ['full' => 'كاملة', 'pre_operation' => 'قبل عملية', 'manual' => 'يدوية'];
                        echo formatDateAr($backup['created_at'], 'medium') . ' - ' .
                             ($typeLabels[$backup['backup_type']] ?? $backup['backup_type']) . ' - ' .
                             ($backup['file_size'] ? round($backup['file_size'] / 1024, 1) . ' KB' : 'غير معروف');
                        ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selectedBackup): ?>
            <!-- معلومات النسخة المحددة -->
            <div class="card bg-light mb-3">
                <div class="card-body">
                    <h5>📋 معلومات النسخة المحددة</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>النوع:</strong> <?php echo $typeLabels[$selectedBackup['backup_type']] ?? $selectedBackup['backup_type']; ?></p>
                            <p><strong>التاريخ:</strong> <?php echo formatDateAr($selectedBackup['created_at'], 'full'); ?></p>
                            <p><strong>الحجم:</strong>
                                <?php
                                $sz = $selectedBackup['file_size'] ?? 0;
                                echo $sz > 1048576 ? round($sz / 1048576, 2) . ' MB' : round($sz / 1024, 1) . ' KB';
                                ?>
                            </p>
                            <p><strong>أنشئ بواسطة:</strong> <?php echo e($selectedBackup['creator_name'] ?? 'غير معروف'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>عدد الجداول:</strong> <?php echo $selectedBackup['tables_count'] ?? '-'; ?></p>
                            <p><strong>عدد الصفوف:</strong> <?php echo number_format($selectedBackup['rows_count'] ?? 0); ?></p>
                            <?php if ($backupInfo && $backupInfo['success'] && !empty($backupInfo['info']['tables'])): ?>
                            <p><strong>الجداول:</strong></p>
                            <div style="max-height:100px;overflow-y:auto;font-size:11px;">
                                <?php echo implode(', ', $backupInfo['info']['tables']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $fileSizeMB = ($selectedBackup['file_size'] ?? 0) / (1024 * 1024);
            if ($fileSizeMB > BACKUP_MAX_SIZE_MB): ?>
            <div class="alert alert-warning">
                <strong>⚠️ تحذير:</strong> حجم النسخة كبير (<?php echo round($fileSizeMB, 2); ?> MB).
                قد تستغرق الاستعادة وقتاً طويلاً.
                <input type="hidden" name="confirm_large" value="1">
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- تأكيد الاستعادة -->
            <div class="form-group">
                <label class="form-label">
                    اكتب <strong>"استعادة"</strong> لتأكيد العملية
                </label>
                <input type="text" name="confirm_text" class="form-control" required
                       placeholder='اكتب "استعادة" هنا للتأكيد'
                       pattern="^استعادة$"
                       title='يجب كتابة كلمة "استعادة" بالضبط'>
            </div>

            <div class="mt-4" style="display:flex;gap:8px;">
                <button type="submit" name="restore_backup" value="1" class="btn btn-danger btn-lg"
                        onclick="return confirm('هل أنت متأكد تماماً من الاستعادة؟ هذا سيستبدل البيانات الحالية!');">
                    🔄 استعادة البيانات
                </button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
