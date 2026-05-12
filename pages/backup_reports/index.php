<?php
/**
 * ============================================================
 * EQAS - النسخ الاحتياطي - لوحة التحكم
 * نظام التقييم الشامل - صفحة النسخ الاحتياطي الرئيسية
 * ============================================================
 */

$pageTitle = 'النسخ الاحتياطي';
$breadcrumb = ['النسخ الاحتياطي' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/backup.php';

// التحقق من الصلاحيات - مدير النظام فقط
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$backupManager = new BackupManager($db);

// إحصائيات النسخ الاحتياطي
$stats = $backupManager->getBackupStats();

// آخر النسخ
$recentBackups = $backupManager->listBackups(['limit' => 5]);

// حجم التخزين المستخدم
$backupDirSize = 0;
if (is_dir(EQAS_BACKUPS)) {
    $files = glob(EQAS_BACKUPS . '/*');
    foreach ($files as $file) {
        if (is_file($file)) $backupDirSize += filesize($file);
    }
}

// معلومات النظام
$dbSize = 0;
try {
    $stmt = $db->query("SELECT SUM(data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    $row = $stmt->fetch();
    $dbSize = $row['size'] ?? 0;
} catch (Exception $e) {
    $dbSize = 0;
}

// جداول قاعدة البيانات
$tablesInfo = $backupManager->getTablesWithRowCounts();
$totalRows = array_sum(array_column($tablesInfo, 'rows'));
?>

<!-- إحصائيات سريعة -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">💾</div>
            <div class="stat-card-label">إجمالي النسخ</div>
            <div class="stat-card-value"><?php echo $stats['total_backups']; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">📦</div>
            <div class="stat-card-label">حجم التخزين</div>
            <div class="stat-card-value"><?php echo $backupDirSize > 1048576 ? round($backupDirSize / 1048576, 2) . ' MB' : round($backupDirSize / 1024, 1) . ' KB'; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">🗄️</div>
            <div class="stat-card-label">حجم قاعدة البيانات</div>
            <div class="stat-card-value"><?php echo $dbSize > 1048576 ? round($dbSize / 1048576, 2) . ' MB' : round($dbSize / 1024, 1) . ' KB'; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">🕐</div>
            <div class="stat-card-label">آخر نسخة</div>
            <div class="stat-card-value" style="font-size:14px;">
                <?php echo $stats['last_backup_date'] ? formatDateAr($stats['last_backup_date'], 'medium') : 'لا توجد'; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- أزرار النسخ السريعة -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">⚡ إنشاء نسخة احتياطية</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <a href="create.php?type=full" class="btn btn-primary btn-lg" style="width:100%;padding:20px;">
                            <div style="font-size:32px;">💾</div>
                            <div style="font-weight:600;">نسخة كاملة</div>
                            <div style="font-size:12px;opacity:0.8;">جميع الجداول والبيانات</div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="create.php?type=pre_operation" class="btn btn-warning btn-lg" style="width:100%;padding:20px;">
                            <div style="font-size:32px;">⚠️</div>
                            <div style="font-weight:600;">قبل عملية حساسة</div>
                            <div style="font-size:12px;opacity:0.8;">الجداول المتأثرة فقط</div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="create.php?type=manual" class="btn btn-info btn-lg" style="width:100%;padding:20px;">
                            <div style="font-size:32px;">🎯</div>
                            <div style="font-weight:600;">نسخة يدوية</div>
                            <div style="font-size:12px;opacity:0.8;">اختر الجداول بنفسك</div>
                        </a>
                    </div>
                </div>

                <div class="alert alert-info mt-3">
                    <strong>ℹ️ جدولة النسخ الاحتياطي:</strong>
                    النظام يقوم تلقائياً بإنشاء نسخة كاملة يومياً عند أول تسجيل دخول للمدير.
                    كما يتم إنشاء نسخة تلقائية قبل أي عملية حساسة (ترفيع، إغلاق فصل، تغيير إعدادات).
                </div>
            </div>
        </div>

        <!-- آخر النسخ الاحتياطية -->
        <div class="card mt-4">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 class="card-title">📋 آخر النسخ الاحتياطية</h2>
                <a href="history.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentBackups)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p class="empty-state-text">لا توجد نسخ احتياطية بعد</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>النوع</th>
                                    <th>الحجم</th>
                                    <th>الجداول</th>
                                    <th>الصفوف</th>
                                    <th>التاريخ</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBackups as $backup): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $typeLabels = ['full' => 'كاملة', 'pre_operation' => 'قبل عملية', 'manual' => 'يدوية'];
                                        $typeBadges = ['full' => 'badge-primary', 'pre_operation' => 'badge-warning', 'manual' => 'badge-info'];
                                        ?>
                                        <span class="badge <?php echo $typeBadges[$backup['backup_type']] ?? 'badge-secondary'; ?>">
                                            <?php echo $typeLabels[$backup['backup_type']] ?? $backup['backup_type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        if ($backup['file_size']) {
                                            echo $backup['file_size'] > 1048576 ? round($backup['file_size'] / 1048576, 2) . ' MB' : round($backup['file_size'] / 1024, 1) . ' KB';
                                        } else echo '-';
                                        ?>
                                    </td>
                                    <td><?php echo $backup['tables_count'] ?? '-'; ?></td>
                                    <td><?php echo $backup['rows_count'] ?? '-'; ?></td>
                                    <td><?php echo formatDateAr($backup['created_at'], 'medium'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $backup['status'] === 'completed' ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $backup['status'] === 'completed' ? 'مكتمل' : 'فشل'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- إحصائيات حسب النوع -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 إحصائيات النسخ</h2>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>نسخ كاملة</span>
                    <span class="badge badge-primary"><?php echo $stats['full_count']; ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>نسخ قبل العمليات</span>
                    <span class="badge badge-warning"><?php echo $stats['preop_count']; ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>نسخ يدوية</span>
                    <span class="badge badge-info"><?php echo $stats['manual_count']; ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span>آخر نسخة كاملة</span>
                    <span class="text-sm"><?php echo $stats['last_full_date'] ? formatDateAr($stats['last_full_date'], 'short') : 'لا توجد'; ?></span>
                </div>
            </div>
        </div>

        <!-- جداول قاعدة البيانات -->
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">🗄️ جداول قاعدة البيانات</h2>
            </div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <div class="d-flex justify-content-between py-1 border-bottom font-weight-bold">
                    <span>الجدول</span>
                    <span>الصفوف</span>
                </div>
                <?php foreach ($tablesInfo as $ti): ?>
                <div class="d-flex justify-content-between py-1 border-bottom" style="font-size:12px;">
                    <span><?php echo e($ti['name']); ?></span>
                    <span class="text-muted"><?php echo number_format($ti['rows']); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between py-2 font-weight-bold">
                    <span>الإجمالي</span>
                    <span><?php echo number_format($totalRows); ?> صف</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
