<?php
/**
 * ============================================================
 * إدارة المستخدمين - قائمة المستخدمين
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

// تعيين عنوان الصفحة ومسار التنقل
$pageTitle = 'إدارة المستخدمين';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => null
];

// تضمين القالب العلوي
require_once __DIR__ . '/../../includes/header.php';

// تحديد نوع المستخدم
$isSchoolAdmin = hasPermission('school_admin') && !hasPermission('group_admin');

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// إعداد متغيرات الفلترة والبحث
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$schoolFilter = $_GET['school'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

// بناء استعلام البحث
$conditions = ['1=1'];
$params = [];

// school_admin يرى فقط معلمي مدرسته
if ($isSchoolAdmin) {
    $conditions[] = 'u.school_id = ?';
    $params[] = currentSchoolId();
    $conditions[] = 'r.name = ?';
    $params[] = 'teacher';
}

if (!empty($search)) {
    $conditions[] = '(u.username LIKE ? OR u.full_name_ar LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($roleFilter) && !$isSchoolAdmin) {
    $conditions[] = 'r.name = ?';
    $params[] = $roleFilter;
}

if (!empty($schoolFilter) && !$isSchoolAdmin) {
    $conditions[] = 'u.school_id = ?';
    $params[] = $schoolFilter;
}

if ($statusFilter !== '') {
    if ($statusFilter === 'archived') {
        $conditions[] = 'u.deleted_at IS NOT NULL';
    } else {
        $conditions[] = 'u.is_active = ?';
        $params[] = intval($statusFilter);
    }
}

$whereClause = implode(' AND ', $conditions);

// حساب إجمالي السجلات
$countSql = "SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);
$offset = ($page - 1) * $perPage;

// جلب بيانات المستخدمين
$sql = "SELECT u.*, r.name AS role_name, r.display_name_ar AS role_name_ar, s.name_ar AS school_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN schools s ON u.school_id = s.id
        WHERE {$whereClause}
        ORDER BY u.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// جلب الأدوار المتاحة للفلتر
$roles = $db->query("SELECT id, name, display_name_ar AS name_ar FROM roles WHERE is_active = 1 ORDER BY id")->fetchAll();

// جلب المدارس المتاحة للفلتر
$schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

/**
 * الحصول على لون شارة الدور
 */
function getRoleBadgeClass($roleName) {
    $badges = [
        'group_admin' => 'badge-danger',
        'school_admin' => 'badge-primary',
        'teacher' => 'badge-success',
        'parent' => 'badge-warning'
    ];
    return $badges[$roleName] ?? 'badge-secondary';
}
?>

<!-- شريط الأدوات -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">قائمة المستخدمين</h3>
            <div class="flex gap-2">
                <a href="add.php" class="btn btn-primary">
                    <span>➕</span> إضافة مستخدم جديد
                </a>
                <?php if (!$isSchoolAdmin): ?>
                <a href="import.php" class="btn btn-secondary">
                    <span>📥</span> استيراد
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج البحث والفلترة -->
        <form method="GET" action="" class="form-inline mb-4 flex-wrap gap-3">
            <div class="form-group mb-0" style="min-width:200px;flex:1;">
                <input type="text" name="search" class="form-control" placeholder="بحث بالاسم أو اسم المستخدم..." value="<?= e($search) ?>">
            </div>
            <div class="form-group mb-0">
                <select name="role" class="form-control">
                    <option value="">جميع الأدوار</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role['name']) ?>" <?= $roleFilter === $role['name'] ? 'selected' : '' ?>>
                            <?= e($role['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$isSchoolAdmin): ?>
            <div class="form-group mb-0">
                <select name="school" class="form-control">
                    <option value="">جميع المدارس</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= $school['id'] ?>" <?= $schoolFilter == $school['id'] ? 'selected' : '' ?>>
                            <?= e($school['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
<div class="form-group mb-0">
                    <select name="status" class="form-control">
                        <option value="">جميع الحالات</option>
                        <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>نشط</option>
                        <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>غير نشط</option>
                        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>مؤرشف</option>
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
                        <th>اسم المستخدم</th>
                        <th>الاسم الكامل</th>
                        <th>الدور</th>
                        <th>المدرسة</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="table-empty">
                                <div class="table-empty-icon">👥</div>
                                <div class="table-empty-text">لا توجد نتائج مطابقة</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNumber = $offset + 1; ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <a href="view.php?id=<?= $user['id'] ?>" style="font-weight:600;">
                                        <?= e($user['username']) ?>
                                    </a>
                                </td>
                                <td><?= e($user['full_name_ar']) ?></td>
                                <td>
                                    <span class="badge <?= getRoleBadgeClass($user['role_name']) ?>">
                                        <?= e($user['role_name_ar'] ?? getRoleLabel($user['role_name'])) ?>
                                    </span>
                                </td>
                                <td><?= e($user['school_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge badge-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">غير نشط</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                    <?php if ($isSchoolAdmin): ?>
                                        <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                    <?php else: ?>
                                        <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                        <a href="delete.php?id=<?= $user['id'] ?>" class="btn btn-sm <?= $user['is_active'] ? 'btn-danger' : 'btn-success' ?>" title="<?= $user['is_active'] ? 'تعطيل' : 'تفعيل' ?>" onclick="return confirm('هل أنت متأكد من <?= $user['is_active'] ? 'تعطيل' : 'تفعيل' ?> هذا المستخدم؟')">
                                            <?= $user['is_active'] ? '🚫' : '✅' ?>
                                        </a>
                                        <a href="reset_password.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-secondary" title="إعادة تعيين كلمة المرور">🔑</a>
                                    <?php endif; ?>
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
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&school=<?= urlencode($schoolFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">السابق</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>';
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&school=<?= urlencode($schoolFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-light' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>'; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>&school=<?= urlencode($schoolFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">التالي</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
