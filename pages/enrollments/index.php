<?php
/**
 * ============================================================
 * تسجيل الطلاب - قائمة التسجيلات
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض جميع تسجيلات الطلاب مع إمكانية البحث والفلترة والتنقل بين الصفحات
 * مدرس المدرسة يرى فقط تسجيلات مدرسته
 * مدير النظام يرى جميع التسجيلات
 * ============================================================
 */

// تعيين عنوان الصفحة ومسار التنقل
$pageTitle = 'تسجيل الطلاب';
$breadcrumb = [
    'الإدارة' => null,
    'تسجيل الطلاب' => null
];

// تضمين القالب العلوي
require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - يجب أن يكون مدير مدرسة على الأقل
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// متغيرات المستخدم والسياق
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// إعداد متغيرات الفلترة والبحث
$search       = trim($_GET['search'] ?? '');
$academicYearFilter = intval($_GET['academic_year'] ?? 0);
$classFilter  = intval($_GET['class'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$page         = max(1, intval($_GET['page'] ?? 1));
$perPage      = 15;

// بناء شروط الاستعلام
$conditions = ['se.is_active = 1'];
$params     = [];

// نطاق المدرسة: مدير المدرسة يرى فقط مدرسته
if ($schoolId && $userRole !== 'group_admin') {
    $conditions[] = 'c.school_id = ?';
    $params[]     = $schoolId;
}

// بحث بالنص
if (!empty($search)) {
    $conditions[] = '(s.full_name_ar LIKE ? OR s.student_number LIKE ? OR c.name_ar LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// فلتر العام الدراسي
if ($academicYearFilter > 0) {
    $conditions[] = 'se.academic_year_id = ?';
    $params[]     = $academicYearFilter;
}

// فلتر الفصل
if ($classFilter > 0) {
    $conditions[] = 'se.class_id = ?';
    $params[]     = $classFilter;
}

// فلتر الحالة
if (!empty($statusFilter) && in_array($statusFilter, ['enrolled', 'transferred', 'dropped'])) {
    $conditions[] = 'se.status = ?';
    $params[]     = $statusFilter;
}

$whereClause = implode(' AND ', $conditions);

// حساب إجمالي السجلات
$countSql = "SELECT COUNT(*) FROM student_enrollments se
             INNER JOIN students s ON s.id = se.student_id
             INNER JOIN classes c ON c.id = se.class_id
             INNER JOIN academic_years ay ON ay.id = se.academic_year_id
             WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRecords = (int)$stmt->fetchColumn();
$totalPages   = ceil($totalRecords / $perPage);
$offset       = ($page - 1) * $perPage;

// جلب بيانات التسجيلات
$sql = "SELECT se.*,
               s.full_name_ar AS student_name,
               s.student_number,
               c.name_ar AS class_name,
               c.capacity AS class_capacity,
               ay.name AS academic_year_name,
               gl.name_ar AS grade_name
        FROM student_enrollments se
        INNER JOIN students s ON s.id = se.student_id
        INNER JOIN classes c ON c.id = se.class_id
        INNER JOIN academic_years ay ON ay.id = se.academic_year_id
        LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
        WHERE {$whereClause}
        ORDER BY se.enrollment_date DESC, s.full_name_ar ASC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$enrollments = $stmt->fetchAll();

// جلب السنوات الدراسية للفلتر
$academicYears = [];
try {
    $aySql = "SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC";
    $academicYears = $db->query($aySql)->fetchAll();
} catch (Exception $e) {
    $academicYears = [];
}

// جلب الفصول للفلتر (نطاق المدرسة)
$classes = [];
try {
    $clSql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
    $clParams = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $clSql .= " AND school_id = ?";
        $clParams[] = $schoolId;
    }
    $clSql .= " ORDER BY name_ar";
    $stmt = $db->prepare($clSql);
    $stmt->execute($clParams);
    $classes = $stmt->fetchAll();
} catch (Exception $e) {
    $classes = [];
}

// إحصائيات سريعة
$stats = [
    'total_enrolled'   => 0,
    'total_transferred' => 0,
    'total_dropped'    => 0,
    'total_active'     => 0
];
try {
    $statsConditions = ['se.is_active = 1'];
    $statsParams     = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $statsConditions[] = 'c.school_id = ?';
        $statsParams[]     = $schoolId;
    }
    $statsWhere = implode(' AND ', $statsConditions);

    $statsSql = "SELECT se.status, COUNT(*) AS cnt
                 FROM student_enrollments se
                 INNER JOIN classes c ON c.id = se.class_id
                 WHERE {$statsWhere}
                 GROUP BY se.status";
    $stmt = $db->prepare($statsSql);
    $stmt->execute($statsParams);
    $statsRows = $stmt->fetchAll();
    foreach ($statsRows as $row) {
        switch ($row['status']) {
            case 'enrolled':    $stats['total_enrolled']    = (int)$row['cnt']; break;
            case 'transferred': $stats['total_transferred'] = (int)$row['cnt']; break;
            case 'dropped':     $stats['total_dropped']     = (int)$row['cnt']; break;
        }
    }
    $stats['total_active'] = $stats['total_enrolled'] + $stats['total_transferred'] + $stats['total_dropped'];
} catch (Exception $e) {
    // استخدام القيم الافتراضية
}

/**
 * لون شارة حالة التسجيل
 */
function getEnrollmentStatusBadge($status) {
    $badges = [
        'enrolled'    => 'badge-success',
        'transferred' => 'badge-warning',
        'dropped'     => 'badge-danger'
    ];
    return $badges[$status] ?? 'badge-secondary';
}

/**
 * عدد الطلاب المسجلين في فصل معين
 */
function getClassEnrollmentCount($db, $classId) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM student_enrollments WHERE class_id = ? AND status = 'enrolled' AND is_active = 1");
        $stmt->execute([$classId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
?>

<!-- ============================================================
     إحصائيات سريعة
     ============================================================ -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:16px;">
            <div style="font-size:28px; font-weight:700; color:var(--success);">
                <?= number_format($stats['total_enrolled']) ?>
            </div>
            <div style="color:var(--text-secondary); margin-top:2px; font-size:13px;">مسجلون</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:16px;">
            <div style="font-size:28px; font-weight:700; color:var(--warning);">
                <?= number_format($stats['total_transferred']) ?>
            </div>
            <div style="color:var(--text-secondary); margin-top:2px; font-size:13px;">منقولون</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:16px;">
            <div style="font-size:28px; font-weight:700; color:var(--danger);">
                <?= number_format($stats['total_dropped']) ?>
            </div>
            <div style="color:var(--text-secondary); margin-top:2px; font-size:13px;">منسحبون</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:16px;">
            <div style="font-size:28px; font-weight:700; color:var(--eqas-green, #1a5632);">
                <?= number_format($stats['total_active']) ?>
            </div>
            <div style="color:var(--text-secondary); margin-top:2px; font-size:13px;">إجمالي السجلات</div>
        </div>
    </div>
</div>

<!-- ============================================================
     جدول التسجيلات
     ============================================================ -->
<div class="card">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">قائمة تسجيلات الطلاب</h3>
            <div class="flex gap-2">
                <a href="add.php" class="btn btn-primary">
                    <span>➕</span> تسجيل طالب جديد
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج البحث والفلترة -->
        <form method="GET" action="" class="form-inline mb-4 flex-wrap gap-3">
            <div class="form-group mb-0" style="min-width:200px;flex:1;">
                <input type="text" name="search" class="form-control" placeholder="بحث باسم الطالب أو رقمه أو الفصل..." value="<?= e($search) ?>">
            </div>
            <div class="form-group mb-0">
                <select name="academic_year" class="form-control">
                    <option value="">جميع السنوات</option>
                    <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $academicYearFilter == $ay['id'] ? 'selected' : '' ?>>
                            <?= e($ay['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <select name="class" class="form-control">
                    <option value="">جميع الفصول</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $classFilter == $cls['id'] ? 'selected' : '' ?>>
                            <?= e($cls['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <select name="status" class="form-control">
                    <option value="">جميع الحالات</option>
                    <option value="enrolled" <?= $statusFilter === 'enrolled' ? 'selected' : '' ?>>مسجل</option>
                    <option value="transferred" <?= $statusFilter === 'transferred' ? 'selected' : '' ?>>منقول</option>
                    <option value="dropped" <?= $statusFilter === 'dropped' ? 'selected' : '' ?>>منسحب</option>
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
                        <th>الطالب</th>
                        <th>الفصل</th>
                        <th>الصف الدراسي</th>
                        <th>العام الدراسي</th>
                        <th>تاريخ التسجيل</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($enrollments)): ?>
                        <tr>
                            <td colspan="8" class="table-empty">
                                <div class="table-empty-icon">📋</div>
                                <div class="table-empty-text">لا توجد تسجيلات مطابقة</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNumber = $offset + 1; ?>
                        <?php foreach ($enrollments as $en): ?>
                            <tr>
                                <td><?= $rowNumber++ ?></td>
                                <td>
                                    <a href="<?= baseUrl('pages/users/view.php?id=') ?><?= $en['student_id'] ?>" style="font-weight:600;">
                                        <?= e($en['student_name']) ?>
                                    </a>
                                    <div style="font-size:11px; color:var(--text-muted);">
                                        <?= e($en['student_number']) ?>
                                    </div>
                                </td>
                                <td><?= e($en['class_name']) ?></td>
                                <td><?= e($en['grade_name'] ?? '—') ?></td>
                                <td><?= e($en['academic_year_name']) ?></td>
                                <td><?= formatDateAr($en['enrollment_date'], 'short') ?></td>
                                <td>
                                    <span class="badge <?= getEnrollmentStatusBadge($en['status']) ?>">
                                        <?= e(getStatusLabel($en['status'])) ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="edit.php?id=<?= $en['id'] ?>" class="btn btn-sm btn-warning" title="تعديل الحالة">✏️</a>
                                    <?php if ($en['status'] === 'enrolled'): ?>
                                        <a href="edit.php?id=<?= $en['id'] ?>&action=transfer" class="btn btn-sm btn-info" title="نقل إلى فصل آخر">🔄</a>
                                    <?php endif; ?>
                                    <a href="<?= baseUrl('pages/users/view.php?id=') ?><?= $en['student_id'] ?>" class="btn btn-sm btn-secondary" title="عرض بيانات الطالب">👁️</a>
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
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&academic_year=<?= $academicYearFilter ?>&class=<?= $classFilter ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">السابق</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>';
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&academic_year=<?= $academicYearFilter ?>&class=<?= $classFilter ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-light' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages) echo '<span class="btn btn-sm btn-light" style="cursor:default;">...</span>'; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&academic_year=<?= $academicYearFilter ?>&class=<?= $classFilter ?>&status=<?= urlencode($statusFilter) ?>" class="btn btn-sm btn-light">التالي</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
