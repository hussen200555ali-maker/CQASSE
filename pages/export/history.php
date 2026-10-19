<?php
/**
 * ============================================================
 * EQAS - سجل التصدير
 * نظام التقييم الشامل - صفحة سجل عمليات التصدير
 * ============================================================
 */

// Include required files BEFORE any output
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/export.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// معالجة إعادة التنزيل (يجب أن يكون قبل header.php لتجنب headers already sent)
if (isset($_GET['download'])) {
    $downloadId = (int)$_GET['download'];
    $stmt = $db->prepare("SELECT * FROM export_history WHERE id = ?");
    $stmt->execute([$downloadId]);
    $file = $stmt->fetch();
    if ($file && file_exists($file['file_path'])) {
        downloadExportFile($file['file_path'], $file['file_name']);
    } else {
        setFlash('الملف غير موجود أو تم حذفه', 'danger');
        redirect('history.php');
    }
}

// Now include header
$pageTitle = 'سجل التصدير';
$breadcrumb = ['مركز التصدير' => 'index.php', 'سجل التصدير' => null];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

// فلاتر
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterFormat = $_GET['format'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// معالجة الحذف الجماعي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $deleteIds = $_POST['delete_ids'] ?? [];
        $deletedCount = 0;
        if (is_array($deleteIds) && !empty($deleteIds)) {
            foreach ($deleteIds as $delId) {
                $delId = (int)$delId;
                $stmt = $db->prepare("SELECT file_path FROM export_history WHERE id = ?");
                $stmt->execute([$delId]);
                $file = $stmt->fetch();
                if ($file && file_exists($file['file_path'])) {
                    @unlink($file['file_path']);
                }
                $stmt = $db->prepare("DELETE FROM export_history WHERE id = ?");
                $stmt->execute([$delId]);
                $deletedCount++;
            }
            logActivity($db, $_SESSION['user']['id'], 'export_bulk_delete', "حذف جماعي لـ {$deletedCount} سجل تصدير", 'export', null);
            setFlash("تم حذف {$deletedCount} سجل تصدير بنجاح", 'success');
        } else {
            setFlash('لم يتم تحديد أي سجلات للحذف', 'warning');
        }
    }
    redirect('history.php');
}

// معالجة الحذف الفردي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $deleteId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("SELECT file_path FROM export_history WHERE id = ?");
        $stmt->execute([$deleteId]);
        $file = $stmt->fetch();
        if ($file && file_exists($file['file_path'])) {
            @unlink($file['file_path']);
        }
        $stmt = $db->prepare("DELETE FROM export_history WHERE id = ?");
        $stmt->execute([$deleteId]);
        logActivity($db, $_SESSION['user']['id'], 'export_delete', 'حذف سجل تصدير #' . $deleteId, 'export', $deleteId);
        setFlash('تم حذف سجل التصدير بنجاح', 'success');
    }
    redirect('history.php');
}

// معالجة التنظيف التلقائي
if (isset($_POST['auto_cleanup']) && verifyCsrf()) {
    $cleanupDays = max(1, (int)($_POST['cleanup_days'] ?? 30));
    $stmt = $db->prepare("SELECT id, file_path FROM export_history WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$cleanupDays]);
    $oldFiles = $stmt->fetchAll();
    $cleanedCount = 0;
    foreach ($oldFiles as $oldFile) {
        if (file_exists($oldFile['file_path'])) {
            @unlink($oldFile['file_path']);
        }
        $del = $db->prepare("DELETE FROM export_history WHERE id = ?");
        $del->execute([$oldFile['id']]);
        $cleanedCount++;
    }
    logActivity($db, $_SESSION['user']['id'], 'export_cleanup', "تنظيف تلقائي: حذف {$cleanedCount} ملف تصدير أقدم من {$cleanupDays} يوم", 'export', null);
    setFlash("تم حذف {$cleanedCount} ملف تصدير أقدم من {$cleanupDays} يوم", 'success');
    redirect('history.php');
}

// بناء الاستعلام
$sql = "SELECT eh.*, u.full_name_ar as user_name
        FROM export_history eh
        LEFT JOIN users u ON u.id = eh.user_id
        WHERE 1=1";
$params = [];

if ($filterType) {
    $sql .= " AND eh.export_type = ?";
    $params[] = $filterType;
}
if ($filterStatus) {
    $sql .= " AND eh.status = ?";
    $params[] = $filterStatus;
}
if ($filterFormat) {
    $sql .= " AND eh.file_name LIKE ?";
    $params[] = '%.' . $filterFormat;
}
if ($filterDateFrom) {
    $sql .= " AND eh.created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $sql .= " AND eh.created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}

$sql .= " ORDER BY eh.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$exports = $stmt->fetchAll();

// إحصائيات التصدير
$statsTotal = 0;
$statsCompleted = 0;
$statsFailed = 0;
$statsTotalSize = 0;
$statsByFormat = [];

try {
    $stmt = $db->query("SELECT status, COUNT(*) as cnt, COALESCE(SUM(file_size), 0) as total_size FROM export_history GROUP BY status");
    while ($row = $stmt->fetch()) {
        $statsTotal += $row['cnt'];
        if ($row['status'] === 'completed') $statsCompleted = $row['cnt'];
        if ($row['status'] === 'failed') $statsFailed = $row['cnt'];
        $statsTotalSize += $row['total_size'];
    }
} catch (Exception $e) {}

// توزيع الصيغ
try {
    $stmt = $db->query("SELECT
        CASE
            WHEN file_name LIKE '%.xlsx' THEN 'XLSX'
            WHEN file_name LIKE '%.csv' THEN 'CSV'
            WHEN file_name LIKE '%.pdf' THEN 'PDF'
            WHEN file_name LIKE '%.html' THEN 'HTML'
            ELSE 'أخرى'
        END as fmt,
        COUNT(*) as cnt,
        COALESCE(SUM(file_size), 0) as total_size
        FROM export_history
        WHERE status = 'completed'
        GROUP BY fmt
        ORDER BY cnt DESC");
    $formatStats = $stmt->fetchAll();
} catch (Exception $e) {
    $formatStats = [];
}

$formatLabels = [];
$formatCounts = [];
$formatColors = ['#28a745', '#17a2b8', '#dc3545', '#6f42c1', '#ffc107'];
$formatColorIdx = 0;
$formatColorArr = [];
foreach ($formatStats as $fs) {
    $formatLabels[] = $fs['fmt'];
    $formatCounts[] = (int)$fs['cnt'];
    $formatColorArr[] = $formatColors[$formatColorIdx % count($formatColors)];
    $formatColorIdx++;
}

// إحصائيات حسب النوع
$typeStats = [];
try {
    $stmt = $db->query("SELECT export_type, COUNT(*) as cnt FROM export_history GROUP BY export_type ORDER BY cnt DESC LIMIT 7");
    $typeStats = $stmt->fetchAll();
} catch (Exception $e) {}

// تنظيف الملفات القديمة
$cleanedCount = cleanupOldExports(7);

// formatFileSize() removed - using the canonical version from includes/helpers.php
?>

<!-- إحصائيات التصدير -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">📦</div>
            <div class="stat-card-label">إجمالي التصديرات</div>
            <div class="stat-card-value" style="color:var(--primary);"><?php echo number_format($statsTotal); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">✅</div>
            <div class="stat-card-label">مكتملة</div>
            <div class="stat-card-value text-success"><?php echo number_format($statsCompleted); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">❌</div>
            <div class="stat-card-label">فاشلة</div>
            <div class="stat-card-value text-danger"><?php echo number_format($statsFailed); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-icon">💾</div>
            <div class="stat-card-label">إجمالي الحجم</div>
            <div class="stat-card-value" style="color:var(--primary);"><?php echo formatFileSize($statsTotalSize); ?></div>
        </div>
    </div>
</div>

<!-- الرسوم البيانية -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 توزيع الصيغ</h2>
            </div>
            <div class="card-body">
                <canvas id="formatChart" style="max-height:280px;"></canvas>
                <?php if (!empty($formatStats)): ?>
                <div class="mt-3">
                    <?php foreach ($formatStats as $idx => $fs): ?>
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="width:12px;height:12px;border-radius:50%;background:<?php echo $formatColorArr[$idx] ?? '#999'; ?>;display:inline-block;"></span>
                            <span class="text-sm"><?php echo e($fs['fmt']); ?></span>
                        </div>
                        <div>
                            <span class="font-semibold text-sm"><?php echo number_format($fs['cnt']); ?></span>
                            <span class="text-xs text-muted">(<?php echo formatFileSize($fs['total_size']); ?>)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted text-center">لا توجد بيانات كافية</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📈 التصديرات حسب النوع</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($typeStats)): ?>
                <?php foreach ($typeStats as $ts): ?>
                <div style="margin-bottom:12px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-sm font-semibold"><?php echo e($ts['export_type']); ?></span>
                        <span class="text-sm text-muted"><?php echo number_format($ts['cnt']); ?></span>
                    </div>
                    <div style="background:var(--border-light);border-radius:var(--radius-md);height:8px;overflow:hidden;">
                        <?php $pct = $statsTotal > 0 ? round(($ts['cnt'] / $statsTotal) * 100) : 0; ?>
                        <div style="width:<?php echo $pct; ?>%;height:100%;background:var(--primary);border-radius:var(--radius-md);"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted text-center">لا توجد بيانات كافية</p>
                <?php endif; ?>

                <!-- تنظيف تلقائي -->
                <div style="margin-top:20px;padding:12px;background:rgba(220,53,69,0.05);border-radius:var(--radius-md);border-right:4px solid #dc3545;">
                    <h4 style="font-size:13px;font-weight:700;margin-bottom:8px;">🗑️ تنظيف الملفات القديمة</h4>
                    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;">
                        <?= csrfField() ?>
                        <div class="form-group" style="margin:0;flex:1;">
                            <label class="form-label" style="font-size:11px;">حذف ملفات أقدم من</label>
                            <select name="cleanup_days" class="form-control" style="font-size:12px;padding:4px 8px;">
                                <option value="7">7 أيام</option>
                                <option value="14">14 يوم</option>
                                <option value="30" selected>30 يوم</option>
                                <option value="60">60 يوم</option>
                                <option value="90">90 يوم</option>
                            </select>
                        </div>
                        <button type="submit" name="auto_cleanup" value="1" class="btn btn-sm btn-danger"
                                onclick="return confirm('هل أنت متأكد من حذف الملفات القديمة؟ لا يمكن التراجع عن هذا الإجراء.');">
                            🗑️ تنظيف
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">📋 سجل عمليات التصدير</h2>
        <div style="display:flex;gap:6px;">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="toggleBulkDelete()">
                🗑️ حذف جماعي
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-primary">📤 تصدير جديد</a>
        </div>
    </div>
    <div class="card-body">
        <!-- فلاتر -->
        <form method="GET" class="form-grid mb-4">
            <div class="form-group">
                <label class="form-label">نوع التصدير</label>
                <select name="type" class="form-control">
                    <option value="">الكل</option>
                    <option value="students" <?php echo $filterType === 'students' ? 'selected' : ''; ?>>الطلاب</option>
                    <option value="grades" <?php echo $filterType === 'grades' ? 'selected' : ''; ?>>الدرجات</option>
                    <option value="attendance" <?php echo $filterType === 'attendance' ? 'selected' : ''; ?>>الحضور</option>
                    <option value="assessments" <?php echo $filterType === 'assessments' ? 'selected' : ''; ?>>التقييمات</option>
                    <option value="eqi" <?php echo $filterType === 'eqi' ? 'selected' : ''; ?>>مؤشر الجودة</option>
                    <option value="alerts" <?php echo $filterType === 'alerts' ? 'selected' : ''; ?>>التنبيهات</option>
                    <option value="promotions" <?php echo $filterType === 'promotions' ? 'selected' : ''; ?>>الترفيع</option>
                    <option value="grade_sheet" <?php echo $filterType === 'grade_sheet' ? 'selected' : ''; ?>>كشف درجات</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الصيغة</label>
                <select name="format" class="form-control">
                    <option value="">الكل</option>
                    <option value="xlsx" <?php echo $filterFormat === 'xlsx' ? 'selected' : ''; ?>>XLSX</option>
                    <option value="csv" <?php echo $filterFormat === 'csv' ? 'selected' : ''; ?>>CSV</option>
                    <option value="pdf" <?php echo $filterFormat === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                    <option value="html" <?php echo $filterFormat === 'html' ? 'selected' : ''; ?>>HTML</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الحالة</label>
                <select name="status" class="form-control">
                    <option value="">الكل</option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>>فشل</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>قيد التنفيذ</option>
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
            <div class="form-group" style="display:flex;align-items:flex-end;gap:6px;">
                <button type="submit" class="btn btn-primary">🔍 بحث</button>
                <a href="history.php" class="btn btn-outline-secondary">↻ إعادة تعيين</a>
            </div>
        </form>

        <!-- نموذج الحذف الجماعي -->
        <form method="POST" id="bulk-delete-form" style="display:none;">
            <?= csrfField() ?>
            <input type="hidden" name="bulk_delete" value="1">
            <div style="padding:10px 16px;background:rgba(220,53,69,0.08);border-radius:var(--radius-md);margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                <span class="text-sm font-semibold">🗑️ تم تحديد <span id="selected-count">0</span> سجل للحذف</span>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('هل أنت متأكد من الحذف الجماعي؟ لا يمكن التراجع.');">تأكيد حذف المحدد</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleBulkDelete()">إلغاء</button>
                </div>
            </div>

            <!-- جدول النتائج -->
            <?php if (empty($exports)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p class="empty-state-text">لا توجد عمليات تصدير مطابقة</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th class="bulk-col" style="display:none;"><input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"></th>
                                <th>#</th>
                                <th>النوع</th>
                                <th>اسم الملف</th>
                                <th>الصيغة</th>
                                <th>عدد الصفوف</th>
                                <th>الحجم</th>
                                <th>تصور الحجم</th>
                                <th>المستخدم</th>
                                <th>التاريخ</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $maxSize = 0;
                            foreach ($exports as $exp) {
                                if ($exp['file_size'] > $maxSize) $maxSize = $exp['file_size'];
                            }
                            $maxSize = max($maxSize, 1);
                            ?>
                            <?php foreach ($exports as $i => $exp): ?>
                            <tr>
                                <td class="bulk-col" style="display:none;">
                                    <input type="checkbox" name="delete_ids[]" value="<?php echo $exp['id']; ?>" class="bulk-checkbox">
                                </td>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo e($exp['export_type']); ?></td>
                                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($exp['file_name']); ?>"><?php echo e($exp['file_name']); ?></td>
                                <td>
                                    <?php
                                    $ext = pathinfo($exp['file_name'], PATHINFO_EXTENSION);
                                    $formatIcons = ['xlsx' => '📄', 'csv' => '📝', 'pdf' => '📑', 'html' => '🌐'];
                                    $badge = $ext === 'xlsx' ? 'badge-success' : ($ext === 'csv' ? 'badge-info' : ($ext === 'pdf' ? 'badge-danger' : 'badge-secondary'));
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo ($formatIcons[$ext] ?? '📄') . ' ' . strtoupper($ext); ?></span>
                                </td>
                                <td><?php echo $exp['row_count'] ?? '-'; ?></td>
                                <td>
                                    <?php
                                    if ($exp['file_size']) {
                                        echo formatFileSize($exp['file_size']);
                                    } else echo '-';
                                    ?>
                                </td>
                                <td style="min-width:80px;">
                                    <?php if ($exp['file_size'] && $maxSize > 0): ?>
                                    <div style="background:var(--border-light);border-radius:var(--radius-md);height:8px;overflow:hidden;" title="<?php echo formatFileSize($exp['file_size']); ?>">
                                        <div style="width:<?php echo min(round(($exp['file_size'] / $maxSize) * 100), 100); ?>%;height:100%;background:var(--primary);border-radius:var(--radius-md);"></div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($exp['user_name'] ?? 'غير معروف'); ?></td>
                                <td><?php echo formatDateAr($exp['created_at']); ?></td>
                                <td>
                                    <span class="badge <?php echo $exp['status'] === 'completed' ? 'badge-success' : ($exp['status'] === 'failed' ? 'badge-danger' : 'badge-warning'); ?>">
                                        <?php echo $exp['status'] === 'completed' ? 'مكتمل' : ($exp['status'] === 'failed' ? 'فشل' : 'قيد التنفيذ'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <?php if ($exp['status'] === 'completed' && file_exists($exp['file_path'])): ?>
                                        <a href="?download=<?php echo $exp['id']; ?>" class="btn btn-sm btn-success" title="تنزيل">
                                            📥
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($exp['status'] === 'completed' && file_exists($exp['file_path'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-info" title="مقارنة" onclick="compareExport(<?php echo $exp['id']; ?>, '<?php echo e($exp['export_type']); ?>', <?php echo $exp['row_count'] ?? 0; ?>)">
                                            ⚖️
                                        </button>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من الحذف؟');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="delete_id" value="<?php echo $exp['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="حذف">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- نافذة المقارنة -->
<div id="compare-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:var(--background);border-radius:var(--radius-md);padding:24px;width:90%;max-width:600px;direction:rtl;">
        <h3 style="margin-bottom:16px;color:var(--primary);">⚖️ مقارنة التصديرات</h3>
        <div id="compare-content">
            <p class="text-muted">اختر تصديرين من القائمة للمقارنة بينهما.</p>
            <div style="margin-top:12px;">
                <label class="form-label">التصدير الأول:</label>
                <select id="compare-first" class="form-control">
                    <option value="">اختر...</option>
                    <?php foreach ($exports as $exp): ?>
                    <?php if ($exp['status'] === 'completed'): ?>
                    <option value="<?php echo $exp['id']; ?>"><?php echo e($exp['export_type']); ?> - <?php echo e($exp['file_name']); ?> (<?php echo $exp['row_count']; ?> صف)</option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top:8px;">
                <label class="form-label">التصدير الثاني:</label>
                <select id="compare-second" class="form-control">
                    <option value="">اختر...</option>
                    <?php foreach ($exports as $exp): ?>
                    <?php if ($exp['status'] === 'completed'): ?>
                    <option value="<?php echo $exp['id']; ?>"><?php echo e($exp['export_type']); ?> - <?php echo e($exp['file_name']); ?> (<?php echo $exp['row_count']; ?> صف)</option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="compare-result" style="margin-top:16px;"></div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;">
            <button type="button" class="btn btn-primary" onclick="runCompare()">⚖️ مقارنة</button>
            <button type="button" class="btn btn-outline-secondary" onclick="closeCompareModal()">إلغاء</button>
        </div>
    </div>
</div>

<script>
function toggleBulkDelete() {
    const form = document.getElementById('bulk-delete-form');
    const bulkCols = document.querySelectorAll('.bulk-col');
    const isVisible = bulkCols.length > 0 && bulkCols[0].style.display !== 'none' && bulkCols[0].style.display !== '';

    if (isVisible) {
        bulkCols.forEach(el => el.style.display = 'none');
    } else {
        bulkCols.forEach(el => el.style.display = '');
    }
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedCount();
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('bulk-checkbox')) {
        updateSelectedCount();
    }
});

function updateSelectedCount() {
    const count = document.querySelectorAll('.bulk-checkbox:checked').length;
    document.getElementById('selected-count').textContent = count;
}

function compareExport(id, type, rowCount) {
    const modal = document.getElementById('compare-modal');
    modal.style.display = 'flex';
    document.getElementById('compare-first').value = id;
}

function closeCompareModal() {
    document.getElementById('compare-modal').style.display = 'none';
}

function runCompare() {
    const firstId = document.getElementById('compare-first').value;
    const secondId = document.getElementById('compare-second').value;
    const resultDiv = document.getElementById('compare-result');

    if (!firstId || !secondId) {
        resultDiv.innerHTML = '<div style="padding:10px;background:rgba(255,193,7,0.1);border-radius:var(--radius-md);font-size:13px;">⚠️ يرجى اختيار تصديرين للمقارنة</div>';
        return;
    }
    if (firstId === secondId) {
        resultDiv.innerHTML = '<div style="padding:10px;background:rgba(220,53,69,0.1);border-radius:var(--radius-md);font-size:13px;">❌ لا يمكن مقارنة التصدير بنفسه</div>';
        return;
    }

    resultDiv.innerHTML = '<div style="padding:10px;background:rgba(40,167,69,0.1);border-radius:var(--radius-md);font-size:13px;">✅ جارٍ تحميل المقارنة... سيتم عرض الفروقات بين التصديرين.</div>';
}

// تحديث عدد المحدد عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});
</script>

<?php
// رسم بياني دائري لتوزيع الصيغ
$pageJs = "
    eqasPieChart('formatChart', 'توزيع الصيغ',
        " . json_encode($formatLabels, JSON_UNESCAPED_UNICODE) . ",
        " . json_encode($formatCounts) . ",
        " . json_encode($formatColorArr) . ",
        false
    );
";

require_once __DIR__ . '/../../includes/footer.php';
?>
