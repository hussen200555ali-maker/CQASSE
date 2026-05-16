<?php
/**
 * ============================================================
 * EQAS - سجل النشاط
 * نظام التقييم الشامل - صفحة سجل نشاطات النظام
 * ============================================================
 */

$pageTitle = 'سجل النشاط';
$breadcrumb = ['النسخ الاحتياطي' => 'index.php', 'سجل النشاط' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// فلاتر
$filterUser = $_GET['user_id'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterEntityType = $_GET['entity_type'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

// بناء الاستعلام
$sql = "SELECT al.*, u.full_name_ar as user_name, u.username
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM activity_logs al WHERE 1=1";
$params = [];
$countParams = [];

if ($filterUser) {
    $sql .= " AND al.user_id = ?";
    $countSql .= " AND al.user_id = ?";
    $params[] = $filterUser;
    $countParams[] = $filterUser;
}
if ($filterAction) {
    $sql .= " AND al.action LIKE ?";
    $countSql .= " AND al.action LIKE ?";
    $params[] = "%$filterAction%";
    $countParams[] = "%$filterAction%";
}
if ($filterEntityType) {
    $sql .= " AND al.entity_type = ?";
    $countSql .= " AND al.entity_type = ?";
    $params[] = $filterEntityType;
    $countParams[] = $filterEntityType;
}
if ($filterDateFrom) {
    $sql .= " AND al.created_at >= ?";
    $countSql .= " AND al.created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
    $countParams[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $sql .= " AND al.created_at <= ?";
    $countSql .= " AND al.created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
    $countParams[] = $filterDateTo . ' 23:59:59';
}
if ($filterSearch) {
    $sql .= " AND (al.action LIKE ? OR al.details LIKE ? OR u.full_name_ar LIKE ?)";
    $countSql .= " AND (al.action LIKE ? OR al.details LIKE ? OR u.full_name_ar LIKE ?)";
    $searchParam = "%$filterSearch%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

// عدد النتائج
$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// تطبيق الترقيم
$offset = ($page - 1) * $perPage;
$sql .= " ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// جلب المستخدمين للفلتر
$stmt = $db->query("SELECT id, full_name_ar, username FROM users WHERE is_active = 1 ORDER BY full_name_ar LIMIT 100");
$users = $stmt->fetchAll();

// جلب أنواع الكيانات
$entityTypes = ['user', 'student', 'school', 'class', 'grade', 'attendance', 'assessment', 'backup', 'settings', 'notification', 'promotion'];

// أنواع الإجراءات
$actionTypes = [
    'login' => 'تسجيل دخول', 'logout' => 'تسجيل خروج',
    'backup_create' => 'إنشاء نسخة', 'backup_restore' => 'استعادة نسخة',
    'user_create' => 'إنشاء مستخدم', 'user_edit' => 'تعديل مستخدم', 'user_delete' => 'حذف مستخدم',
    'student_create' => 'إنشاء طالب', 'student_edit' => 'تعديل طالب',
    'grade_create' => 'إدخال درجة', 'grade_edit' => 'تعديل درجة',
    'settings_change' => 'تغيير إعدادات',
    'export' => 'تصدير', 'promotion_decide' => 'قرار ترفيع',
];

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_log'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('activity_log.php');
    }

    $format = $_POST['format'] ?? 'xlsx';
    $headers = ['#', 'المستخدم', 'الإجراء', 'الكيان', 'رقم الكيان', 'عنوان IP', 'التاريخ'];

    $exportSql = "SELECT al.id, u.full_name_ar, al.action, al.entity_type, al.entity_id, al.ip_address, al.created_at
                  FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1";
    $exportParams = [];
    if ($filterUser) { $exportSql .= " AND al.user_id = ?"; $exportParams[] = $filterUser; }
    if ($filterDateFrom) { $exportSql .= " AND al.created_at >= ?"; $exportParams[] = $filterDateFrom . ' 00:00:00'; }
    if ($filterDateTo) { $exportSql .= " AND al.created_at <= ?"; $exportParams[] = $filterDateTo . ' 23:59:59'; }
    $exportSql .= " ORDER BY al.created_at DESC LIMIT 10000";

    $stmt = $db->prepare($exportSql);
    $stmt->execute($exportParams);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);

    $baseFilename = 'سجل_النشاط_' . date('Ymd');
    $exportInfo = createExportFile($db, $_SESSION['user']['id'], 'activity_log', $format, $data, $headers, $baseFilename);
    downloadExportFile($exportInfo['file_path'], $exportInfo['file_name']);
}
?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">📜 سجل نشاطات النظام</h2>
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="export_log" value="1">
            <input type="hidden" name="format" value="xlsx">
            <button type="submit" class="btn btn-sm btn-success">📥 تصدير السجل</button>
        </form>
    </div>
    <div class="card-body">
        <!-- فلاتر -->
        <form method="GET" class="form-grid mb-4">
            <div class="form-group">
                <label class="form-label">المستخدم</label>
                <select name="user_id" class="form-control">
                    <option value="">الكل</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                        <?php echo e($u['full_name_ar'] . ' (' . $u['username'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الإجراء</label>
                <input type="text" name="action" class="form-control" value="<?php echo e($filterAction); ?>" placeholder="بحث بالإجراء...">
            </div>
            <div class="form-group">
                <label class="form-label">نوع الكيان</label>
                <select name="entity_type" class="form-control">
                    <option value="">الكل</option>
                    <?php foreach ($entityTypes as $et): ?>
                    <option value="<?php echo $et; ?>" <?php echo $filterEntityType === $et ? 'selected' : ''; ?>>
                        <?php echo e($et); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">بحث</label>
                <input type="text" name="search" class="form-control" value="<?php echo e($filterSearch); ?>" placeholder="بحث سريع...">
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

        <p class="text-muted text-sm">إجمالي السجلات: <?php echo number_format($totalRecords); ?></p>

        <!-- جدول النتائج -->
        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p class="empty-state-text">لا توجد سجلات نشاط مطابقة</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المستخدم</th>
                            <th>الإجراء</th>
                            <th>الكيان</th>
                            <th>عنوان IP</th>
                            <th>التاريخ</th>
                            <th>التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $i => $act): ?>
                        <tr>
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td><?php echo e($act['user_name'] ?? $act['username'] ?? 'النظام'); ?></td>
                            <td>
                                <span class="badge badge-secondary">
                                    <?php echo e($actionTypes[$act['action']] ?? $act['action']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($act['entity_type']): ?>
                                <span class="text-muted"><?php echo e($act['entity_type']); ?>
                                    <?php if ($act['entity_id']): ?>#<?php echo $act['entity_id']; ?><?php endif; ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:11px;"><?php echo e($act['ip_address'] ?? '-'); ?></code></td>
                            <td style="white-space:nowrap;"><?php echo formatDateAr($act['created_at'], 'medium'); ?></td>
                            <td>
                                 <?php if (!empty($act['details'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="alert(JSON.stringify(<?php echo htmlspecialchars(json_encode($act['details']), ENT_QUOTES); ?>, null, 2));">
                                    📋
                                </button>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ترقيم الصفحات -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">السابق</a>
                    </li>
                    <?php endif; ?>

                    <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">التالي</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
