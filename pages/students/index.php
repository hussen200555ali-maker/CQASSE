<?php
/**
 * ============================================================
 * إدارة الطلاب - قائمة الطلاب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

// تعيين عنوان الصفحة ومسار التنقل
$pageTitle = 'إدارة الطلاب';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => null
];

// تضمين القالب العلوي
require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - مدير النظام ومدير المدرسة
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// تحديد نطاق المدرسة
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
$isGroupAdmin = ($userRole === 'group_admin');

// إعداد متغيرات الفلترة والبحث
$search = trim($_GET['search'] ?? '');
$schoolFilter = $_GET['school'] ?? '';
$gradeFilter = $_GET['grade'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

// بناء شروط الاستعلام
$conditions = ['1=1'];
$params = [];

// نطاق المدرسة: مدير المدرسة يرى طلاب مدرسته فقط
if (!$isGroupAdmin) {
    $conditions[] = 's.school_id = ?';
    $params[] = $userSchoolId;
}

if (!empty($search)) {
    $conditions[] = '(s.student_number LIKE ? OR s.full_name_ar LIKE ? OR s.full_name_en LIKE ? OR s.guardian_name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($schoolFilter) && $isGroupAdmin) {
    $conditions[] = 's.school_id = ?';
    $params[] = $schoolFilter;
}

if (!empty($gradeFilter)) {
    $conditions[] = 's.grade_level_id = ?';
    $params[] = $gradeFilter;
}

if ($statusFilter !== '') {
    $conditions[] = 's.is_active = ?';
    $params[] = intval($statusFilter);
}

$whereClause = implode(' AND ', $conditions);

// حساب إجمالي السجلات
$countSql = "SELECT COUNT(*) FROM students s WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);
$offset = ($page - 1) * $perPage;

// جلب بيانات الطلاب
$sql = "SELECT s.*, gl.name_ar AS grade_name, gl.level_number, gl.stage, sc.name_ar AS school_name
        FROM students s
        LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
        LEFT JOIN schools sc ON s.school_id = sc.id
        WHERE {$whereClause}
        ORDER BY s.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// جلب المدارس المتاحة للفلتر (مدير النظام فقط)
$schools = [];
if ($isGroupAdmin) {
    $schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
}

// جلب المراحل الدراسية للفلتر
$gradeLevels = $db->query("SELECT id, name_ar, level_number, stage FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();
?>

<!-- شريط الأدوات -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">قائمة الطلاب</h3>
            <div class="flex gap-2">
                <a href="add.php" class="btn btn-primary">
                    <span>➕</span> إضافة طالب جديد
                </a>
                <a href="import.php" class="btn btn-secondary">
                    <span>📥</span> استيراد
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج البحث والفلترة -->
        <form method="GET" action="" class="form-inline mb-4 flex-wrap gap-3">
            <div class="form-group mb-0" style="min-width:200px;flex:1;">
                <input type="text" name="search" class="form-control" placeholder="بحث برقم الطالب أو الاسم أو ولي الأمر..." value="<?= e($search) ?>">
            </div>
            <?php if ($isGroupAdmin): ?>
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
                <select name="grade" class="form-control">
                    <option value="">جميع المراحل</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= $gl['id'] ?>" <?= $gradeFilter == $gl['id'] ? 'selected' : '' ?>>
                            <?= e($gl['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <select name="status" class="form-control">
                    <option value="">جميع الحالات</option>
                    <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>نشط</option>
                    <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>غير نشط</option>
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
                        <th>رقم الطالب</th>
                        <th>الاسم الكامل</th>
                        <th>المرحلة الدراسية</th>
                        <?php if ($isGroupAdmin): ?>
                        <th>المدرسة</th>
                        <?php endif; ?>
                        <th>ولي الأمر</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="<?= $isGroupAdmin ? 8 : 7 ?>" class="table-empty">
                                <div class="table-empty-icon">🎓</div>
                                <div class="table-empty-text">لا توجد نتائج مطابقة</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNumber = $offset + 1; ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <a href="view.php?id=<?= $student['id'] ?>" style="font-weight:600;">
                                        <?= e($student['student_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($student['full_name_ar']) ?></td>
                                <td>
                                    <span class="badge badge-info">
                                        <?= e($student['grade_name'] ?? '—') ?>
                                    </span>
                                </td>
                                <?php if ($isGroupAdmin): ?>
                                <td><?= e($student['school_name'] ?? '—') ?></td>
                                <?php endif; ?>
                                <td><?= e($student['guardian_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ($student['is_active']): ?>
                                        <span class="badge badge-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">غير نشط</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="view.php?id=<?= $student['id'] ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                    <a href="edit.php?id=<?= $student['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                    <?php if ($student['is_active']): ?>
                                        <a href="toggle.php?id=<?= $student['id'] ?>" class="btn btn-sm btn-danger" title="تعطيل" onclick="return confirm('هل أنت متأكد من تعطيل هذا الطالب؟')">🚫</a>
                                    <?php else: ?>
                                        <a href="toggle.php?id=<?= $student['id'] ?>" class="btn btn-sm btn-success" title="تفعيل" onclick="return confirm('هل أنت متأكد من تفعيل هذا الطالب؟')">✅</a>
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
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&school=<?= urlencode($schoolFilter) ?>&grade=<?= urlencode($gradeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">السابق</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>';
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&school=<?= urlencode($schoolFilter) ?>&grade=<?= urlencode($gradeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-light' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>'; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&school=<?= urlencode($schoolFilter) ?>&grade=<?= urlencode($gradeFilter) ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">التالي</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
