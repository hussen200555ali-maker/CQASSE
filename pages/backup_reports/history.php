<?php
/**
 * ============================================================
 * EQAS - النسخ الاحتياطي - السجل
 * نظام التقييم الشامل - سجل النسخ الاحتياطية
 * ============================================================
 */

$pageTitle = 'سجل النسخ الاحتياطية';
$breadcrumb = ['النسخ الاحتياطي' => 'index.php', 'السجل' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/backup.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$backupManager = new BackupManager($db);

// فلاتر
$filterType = $_GET['type'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

$filters = [];
if ($filterType) $filters['backup_type'] = $filterType;
if ($filterDateFrom) $filters['date_from'] = $filterDateFrom;
if ($filterDateTo) $filters['date_to'] = $filterDateTo;

$backups = $backupManager->listBackups($filters);

// معالجة الحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $deleteId = (int)$_POST['delete_id'];
        $result = $backupManager->deleteBackup($deleteId);
        setFlash($result['message'], $result['success'] ? 'success' : 'danger');
    }
    redirect('history.php');
}

// معالجة التنزيل
if (isset($_GET['download'])) {
    $downloadId = (int)$_GET['download'];
    $stmt = $db->prepare("SELECT * FROM backup_logs WHERE id = ? AND is_active = 1");
    $stmt->execute([$downloadId]);
    $backup = $stmt->fetch();
    if ($backup && file_exists($backup['file_path'])) {
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($backup['file_path']) . '"');
        header('Content-Length: ' . filesize($backup['file_path']));
        readfile($backup['file_path']);
        exit;
    } else {
        setFlash('الملف غير موجود', 'danger');
        redirect('history.php');
    }
}

// تنظيف النسخ القديمة
if (isset($_GET['cleanup'])) {
    $deleted = $backupManager->cleanupOldBackups(30);
    setFlash("تم حذف {$deleted} نسخة احتياطية قديمة", 'success');
    redirect('history.php');
}

$typeLabels = ['full' => 'كاملة', 'pre_operation' => 'قبل عملية', 'manual' => 'يدوية'];
$typeBadges = ['full' => 'badge-primary', 'pre_operation' => 'badge-warning', 'manual' => 'badge-info'];
?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">📋 سجل النسخ الاحتياطية</h2>
        <a href="?cleanup=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل تريد حذف النسخ القديمة (أكثر من 30 يوم)؟');">
            🧹 تنظيف النسخ القديمة
        </a>
    </div>
    <div class="card-body">
        <!-- فلاتر -->
        <form method="GET" class="form-grid mb-4">
            <div class="form-group">
                <label class="form-label">النوع</label>
                <select name="type" class="form-control">
                    <option value="">الكل</option>
                    <option value="full" <?php echo $filterType === 'full' ? 'selected' : ''; ?>>كاملة</option>
                    <option value="pre_operation" <?php echo $filterType === 'pre_operation' ? 'selected' : ''; ?>>قبل عملية</option>
                    <option value="manual" <?php echo $filterType === 'manual' ? 'selected' : ''; ?>>يدوية</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">من تاريخ</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo e($filterDateFrom); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo e($filterDateTo); ?>">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary">🔍 بحث</button>
            </div>
        </form>

        <!-- جدول النتائج -->
        <?php if (empty($backups)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p class="empty-state-text">لا توجد نسخ احتياطية مطابقة</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>النوع</th>
                            <th>الحجم</th>
                            <th>الجداول</th>
                            <th>الصفوف</th>
                            <th>أنشئ بواسطة</th>
                            <th>التاريخ</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $i => $backup): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <span class="badge <?php echo $typeBadges[$backup['backup_type']] ?? 'badge-secondary'; ?>">
                                    <?php echo $typeLabels[$backup['backup_type']] ?? $backup['backup_type']; ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if ($backup['file_size']) {
                                    $sz = $backup['file_size'];
                                    echo $sz > 1048576 ? round($sz / 1048576, 2) . ' MB' : round($sz / 1024, 1) . ' KB';
                                } else echo '-';
                                ?>
                            </td>
                            <td><?php echo $backup['tables_count'] ?? '-'; ?></td>
                            <td><?php echo $backup['rows_count'] ? number_format($backup['rows_count']) : '-'; ?></td>
                            <td><?php echo e($backup['creator_name'] ?? 'النظام'); ?></td>
                            <td><?php echo formatDateAr($backup['created_at'], 'medium'); ?></td>
                            <td>
                                <span class="badge <?php echo $backup['status'] === 'completed' ? 'badge-success' : ($backup['status'] === 'failed' ? 'badge-danger' : 'badge-warning'); ?>">
                                    <?php echo $backup['status'] === 'completed' ? 'مكتمل' : ($backup['status'] === 'failed' ? 'فشل' : 'قيد التنفيذ'); ?>
                                </span>
                                <?php if ($backup['status'] === 'failed' && $backup['error_message']): ?>
                                <br><small class="text-danger"><?php echo e(truncate($backup['error_message'], 50)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($backup['status'] === 'completed' && file_exists($backup['file_path'])): ?>
                                <a href="?download=<?php echo $backup['id']; ?>" class="btn btn-sm btn-success" title="تنزيل">📥</a>
                                <a href="restore.php?id=<?php echo $backup['id']; ?>" class="btn btn-sm btn-warning" title="استعادة">🔄</a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذه النسخة؟');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="delete_id" value="<?php echo $backup['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="حذف">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
