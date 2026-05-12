<?php
/**
 * ============================================================
 * السنوات الأكاديمية - قائمة السنوات
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'السنوات الأكاديمية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// جلب السنوات الأكاديمية
// [إصلاح 2026-05-11] إزالة LEFT JOIN users و locked_by_name (academic_years لا تحتوي locked_by)
$academicYears = $db->query("
    SELECT ay.*, 
           (SELECT COUNT(*) FROM semesters s WHERE s.academic_year_id = ay.id AND s.is_active = 1) AS semester_count
    FROM academic_years ay
    ORDER BY ay.start_date DESC
")->fetchAll();

/**
 * شارة حالة السنة الأكاديمية
 */
function getStatusBadge($status) {
    $badges = [
        'planning' => '<span class="badge badge-warning">تخطيط</span>',
        'active' => '<span class="badge badge-success">نشطة</span>',
        'closed' => '<span class="badge badge-secondary">مغلقة</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . e($status) . '</span>';
}

/**
 * شارة مستوى القفل
 */
// [إصلاح 2026-05-11] تحويل getLockLevelBadge لاستخدام lock_level فقط بدون isLocked
function getLockLevelBadge($lockLevel = 'none') {
    if ($lockLevel === 'none' || empty($lockLevel)) {
        return '<span class="badge badge-light" style="color:var(--text-secondary);">غير مقفل</span>';
    }
    $badges = [
        'soft' => '<span class="badge badge-info">قفل ناعم</span>',
        'hard' => '<span class="badge badge-warning">قفل صلب</span>',
        'admin_handled' => '<span class="badge badge-danger">قفل إداري</span>'
    ];
    return $badges[$lockLevel] ?? '<span class="badge badge-secondary">مقفل</span>';
}
?>

<div class="card">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">السنوات الأكاديمية</h3>
            <?php if (hasPermission('group_admin')): ?>
                <a href="add.php" class="btn btn-primary">
                    <span>➕</span> إضافة سنة أكاديمية
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم السنة</th>
                        <th>تاريخ البداية</th>
                        <th>تاريخ النهاية</th>
                        <th>الحالة</th>
                        <th>مستوى القفل</th>
                        <th>الفصول</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($academicYears)): ?>
                        <tr>
                            <td colspan="8" class="table-empty">
                                <div class="table-empty-icon">📅</div>
                                <div class="table-empty-text">لا توجد سنوات أكاديمية</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; ?>
                        <?php foreach ($academicYears as $year): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <a href="view.php?id=<?= $year['id'] ?>" style="font-weight:600;">
                                        <?= e($year['name']) ?>
                                    </a>
                                    <?php if ($year['is_current']): ?>
                                        <span class="badge badge-primary" style="font-size:10px;">الحالية</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatDateAr($year['start_date']) ?></td>
                                <td><?= formatDateAr($year['end_date']) ?></td>
                                <td><?= getStatusBadge($year['status']) ?></td>
                                <td>
                                    <?php
                                    // عرض مستوى قفل الفصول الدراسية
                                    // [إصلاح 2026-05-11] إزالة is_locked، فحص lock_level من الفصول فقط
                                    $stmt = $db->prepare("SELECT lock_level FROM semesters WHERE academic_year_id = ? AND is_active = 1 AND lock_level != 'none' ORDER BY FIELD(lock_level, 'admin_handled', 'hard', 'soft') LIMIT 1");
                                    $stmt->execute([$year['id']]);
                                    $semLock = $stmt->fetch();
                                    $semLockLevel = $semLock ? $semLock['lock_level'] : 'none';
                                    $isAnyLocked = ($semLockLevel !== 'none');
                                    echo getLockLevelBadge($semLockLevel);
                                    ?>
                                </td>
                                <td><?= $year['semester_count'] ?></td>
                                <td class="actions">
                                    <a href="view.php?id=<?= $year['id'] ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                    <?php if (hasPermission('group_admin') && $year['status'] !== 'closed'): ?>
                                        <a href="edit.php?id=<?= $year['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                        <a href="lock.php?year_id=<?= $year['id'] ?>" class="btn btn-sm btn-secondary" title="إدارة القفل">🔒</a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('group_admin') && $year['status'] === 'active' && !$isAnyLocked): // [إصلاح 2026-05-11] استخدام $isAnyLocked بدلاً من is_locked ?>
                                        <a href="lock.php?year_id=<?= $year['id'] ?>&action=close" class="btn btn-sm btn-danger" title="إغلاق السنة" onclick="return confirm('هل أنت متأكد من إغلاق هذه السنة الأكاديمية؟')">🔒 إغلاق</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
