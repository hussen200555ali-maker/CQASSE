<?php
/**
 * ============================================================
 * إدارة المدارس - قائمة المدارس
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إدارة المدارس';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => null
];

// تضمين القالب العلوي (يُعرف دوال التحقق من الصلاحية)
require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير النظام
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// إعداد متغيرات الفلترة والبحث
$search = trim($_GET['search'] ?? '');
$governorateFilter = $_GET['governorate'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

// بناء استعلام البحث
$conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $conditions[] = '(s.name_ar LIKE ? OR s.name_en LIKE ? OR s.reference_code LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($governorateFilter)) {
    $conditions[] = 's.governorate = ?';
    $params[] = $governorateFilter;
}

if (!empty($typeFilter)) {
    $conditions[] = 's.school_type = ?';
    $params[] = $typeFilter;
}

if ($statusFilter !== '') {
    if ($statusFilter === 'archived') {
        $conditions[] = 's.deleted_at IS NOT NULL';
    } else {
        $conditions[] = 's.is_active = ?';
        $params[] = intval($statusFilter);
    }
}

$whereClause = implode(' AND ', $conditions);

// حساب إجمالي السجلات
$countSql = "SELECT COUNT(*) FROM schools s WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);
$offset = ($page - 1) * $perPage;

// جلب بيانات المدارس مع عدد الطلاب
$sql = "SELECT s.*, 
        (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.is_active = 1) AS student_count
        FROM schools s
        WHERE {$whereClause}
        ORDER BY s.name_ar ASC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$schools = $stmt->fetchAll();

// جلب المحافظات المتاحة للفلتر
$governorates = $db->query("SELECT DISTINCT governorate FROM schools WHERE governorate IS NOT NULL ORDER BY governorate")->fetchAll(PDO::FETCH_COLUMN);

// أنواع المدارس
$schoolTypes = [
    'basic' => 'أساسية',
    'secondary' => 'ثانوية',
    'combined' => 'مختلطة'
];

/**
 * تسمية نوع المدرسة
 */
function getSchoolTypeAr($type) {
    $types = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
    return $types[$type] ?? $type;
}
?>

<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">قائمة المدارس</h3>
            <a href="add.php" class="btn btn-primary">
                <span>➕</span> إضافة مدرسة جديدة
            </a>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج البحث والفلترة -->
        <form method="GET" action="" class="form-inline mb-4 flex-wrap gap-3">
            <div class="form-group mb-0" style="min-width:200px;flex:1;">
                <input type="text" name="search" class="form-control" placeholder="بحث بالاسم أو الرمز..." value="<?= e($search) ?>">
            </div>
            <div class="form-group mb-0">
                <select name="governorate" class="form-control">
                    <option value="">جميع المحافظات</option>
                    <?php foreach ($governorates as $gov): ?>
                        <option value="<?= e($gov) ?>" <?= $governorateFilter === $gov ? 'selected' : '' ?>><?= e($gov) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <select name="type" class="form-control">
                    <option value="">جميع الأنواع</option>
                    <?php foreach ($schoolTypes as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
<div class="form-group mb-0">
                    <select name="status" class="form-control">
                        <option value="">جميع الحالات</option>
                        <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>نشطة</option>
                        <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>غير نشطة</option>
                        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>مؤرشفة</option>
                    </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 بحث</button>
            <a href="index.php" class="btn btn-light">↻ إعادة تعيين</a>
        </form>

        <!-- جدول البيانات -->
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المدرسة</th>
                        <th>الرمز</th>
                        <th>النوع</th>
                        <th>المحافظة</th>
                        <th>عدد الطلاب</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schools)): ?>
                        <tr>
                            <td colspan="8" class="table-empty">
                                <div class="table-empty-icon">🏫</div>
                                <div class="table-empty-text">لا توجد نتائج مطابقة</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNumber = $offset + 1; ?>
                        <?php foreach ($schools as $school): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <a href="view.php?id=<?= $school['id'] ?>" style="font-weight:600;">
                                        <?= e($school['name_ar']) ?>
                                    </a>
                                </td>
                                <td><code><?= e($school['code'] ?? ($school['reference_code'] ?? '—')) ?></code></td>
                                <td><span class="badge badge-info"><?= getSchoolTypeAr($school['school_type']) ?></span></td>

                                <td><?= e($school['governorate'] ?? '—') ?></td>
                                <td><?= formatNumberAr($school['student_count']) ?></td>
                                <td>
                                    <?php if ($school['is_active']): ?>
                                        <span class="badge badge-success">نشطة</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">غير نشطة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?= $school['id'] ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                    <a href="edit.php?id=<?= $school['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                    <a href="toggle.php?id=<?= $school['id'] ?>" class="btn btn-sm <?= $school['is_active'] ? 'btn-danger' : 'btn-success' ?>" title="<?= $school['is_active'] ? 'تعطيل' : 'تفعيل' ?>" onclick="return confirm('هل أنت متأكد من <?= $school['is_active'] ? 'تعطيل' : 'تفعيل' ?> هذه المدرسة؟')">
                                        <?= $school['is_active'] ? '🚫' : '✅' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- التنقل بين الصفحات -->
        <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between mt-4 flex-wrap gap-3">
                <div class="text-sm text-secondary">
                    عرض <?= $offset + 1 ?> - <?= min($offset + $perPage, $totalRecords) ?> من <?= $totalRecords ?> سجل
                </div>
                <div class="flex gap-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&governorate=<?= urlencode($governorateFilter) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">السابق</a>
                    <?php endif; ?>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&governorate=<?= urlencode($governorateFilter) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-light' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&governorate=<?= urlencode($governorateFilter) ?>&type=<?= urlencode($typeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">التالي</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
