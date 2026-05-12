<?php
/**
 * ============================================================
 * عرض تفاصيل سنة أكاديمية
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تفاصيل السنة الأكاديمية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'تفاصيل السنة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$yearId = intval($_GET['id'] ?? 0);
if (!$yearId) {
    setFlash('معرف السنة غير صحيح', 'danger');
    redirect('index.php');
}

// [إصلاح 2026-05-11] إزالة LEFT JOIN users و locked_by_name (academic_years لا تحتوي locked_by)
$stmt = $db->prepare("SELECT ay.* FROM academic_years ay WHERE ay.id = ?");
$stmt->execute([$yearId]);
$year = $stmt->fetch();

if (!$year) {
    setFlash('السنة الأكاديمية غير موجودة', 'danger');
    redirect('index.php');
}

// جلب الفصول الدراسية
$stmt = $db->prepare("SELECT s.*, u.full_name_ar AS locked_by_name FROM semesters s LEFT JOIN users u ON s.locked_by = u.id WHERE s.academic_year_id = ? AND s.is_active = 1 ORDER BY s.start_date");
$stmt->execute([$yearId]);
$semesters = $stmt->fetchAll();

// إحصائيات
$studentCount = 0;
$classCount = 0;
$assessmentCount = 0;

try {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se JOIN classes c ON se.class_id = c.id WHERE c.academic_year_id = ? AND se.is_active = 1");
    $stmt->execute([$yearId]);
    $studentCount = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $classCount = $stmt->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM assessment_responses WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $assessmentCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// شارات الحالة
$statusBadges = [
    'planning' => '<span class="badge badge-warning">تخطيط</span>',
    'active' => '<span class="badge badge-success">نشطة</span>',
    'closed' => '<span class="badge badge-secondary">مغلقة</span>'
];

$lockLevelBadges = [
    'none' => '<span class="badge badge-light" style="color:var(--text-secondary);">مفتوح</span>',
    'soft' => '<span class="badge badge-info">قفل ناعم</span>',
    'hard' => '<span class="badge badge-warning">قفل صلب</span>',
    'admin_handled' => '<span class="badge badge-danger">قفل إداري</span>'
];
?>

<!-- بطاقة معلومات السنة -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">📅 <?= e($year['name']) ?></h3>
            <div class="flex gap-2">
                <?php if (hasPermission('group_admin') && $year['status'] !== 'closed'): ?>
                    <a href="edit.php?id=<?= $yearId ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                    <a href="lock.php?year_id=<?= $yearId ?>" class="btn btn-secondary btn-sm">🔒 إدارة القفل</a>
                <?php endif; ?>
                <a href="semesters.php?year_id=<?= $yearId ?>" class="btn btn-info btn-sm">📚 إدارة الفصول</a>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <table class="data-table" style="box-shadow:none;">
                <tbody>
                    <tr><td style="font-weight:600;width:160px;">اسم السنة</td><td><?= e($year['name']) ?></td></tr>
                    <tr><td style="font-weight:600;">تاريخ البداية</td><td><?= formatDateAr($year['start_date'], 'medium') ?></td></tr>
                    <tr><td style="font-weight:600;">تاريخ النهاية</td><td><?= formatDateAr($year['end_date'], 'medium') ?></td></tr>
                </tbody>
            </table>
            <table class="data-table" style="box-shadow:none;">
                <tbody>
                    <tr><td style="font-weight:600;width:160px;">الحالة</td><td><?= $statusBadges[$year['status']] ?? e($year['status']) ?></td></tr>
                    <tr>
                        <td style="font-weight:600;">السنة الحالية</td>
                        <td><?= $year['is_current'] ? '<span class="badge badge-primary">نعم</span>' : '<span class="badge badge-light">لا</span>' ?></td>
                    </tr>
                    <?php
                    // [إصلاح 2026-05-11] فحص lock_level من الفصول بدلاً من is_locked و locked_by_name
                    $stmt = $db->prepare("SELECT lock_level, locked_by FROM semesters WHERE academic_year_id = ? AND is_active = 1 AND lock_level != 'none' ORDER BY FIELD(lock_level, 'admin_handled', 'hard', 'soft') LIMIT 1");
                    $stmt->execute([$yearId]);
                    $yearLockInfo = $stmt->fetch();
                    $yearIsLocked = $yearLockInfo ? true : false;
                    $yearLockLevel = $yearLockInfo ? $yearLockInfo['lock_level'] : 'none';
                    ?>
                    <tr>
                        <td style="font-weight:600;">مقفلة</td>
                        <td><?= $yearIsLocked ? ($lockLevelBadges[$yearLockLevel] ?? '<span class="badge badge-danger">نعم</span>') : '<span class="badge badge-success">لا</span>' ?></td>
                    </tr>
                    <?php if ($yearIsLocked && $yearLockInfo['locked_by']): ?>
                        <?php
                        $stmt2 = $db->prepare("SELECT full_name_ar FROM users WHERE id = ?");
                        $stmt2->execute([$yearLockInfo['locked_by']]);
                        $lockedByName = $stmt2->fetchColumn();
                        ?>
                        <?php if ($lockedByName): ?>
                            <tr><td style="font-weight:600;">تم القفل بواسطة</td><td><?= e($lockedByName) ?></td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- بطاقات الإحصائيات -->
<div class="grid grid-3 mb-6" style="gap:var(--space-4);">
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">🎓</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--primary);"><?= formatNumberAr($studentCount) ?></div>
            <div class="text-sm text-secondary">طالب مسجل</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">🏫</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--info);"><?= formatNumberAr($classCount) ?></div>
            <div class="text-sm text-secondary">فصل دراسي</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">📋</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--success);"><?= formatNumberAr($assessmentCount) ?></div>
            <div class="text-sm text-secondary">تقييم مكتمل</div>
        </div>
    </div>
</div>

<!-- قائمة الفصول الدراسية -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📚 الفصول الدراسية</h3>
    </div>
    <div class="card-body">
        <?php if (empty($semesters)): ?>
            <p class="text-secondary text-center">لا توجد فصول دراسية</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الفصل</th>
                            <th>تاريخ البداية</th>
                            <th>تاريخ النهاية</th>
                            <th>الحالة</th>
                            <th>مستوى القفل</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; ?>
                        <?php foreach ($semesters as $sem): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td style="font-weight:600;"><?= e($sem['name_ar']) ?></td>
                                <td><?= formatDateAr($sem['start_date']) ?></td>
                                <td><?= formatDateAr($sem['end_date']) ?></td>
                                <td>
                                    <?php if ($sem['is_current']): ?>
                                        <span class="badge badge-primary">الحالي</span>
                                    <?php else: ?>
                                        <span class="badge badge-light">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $lockLevelBadges[$sem['lock_level']] ?? e($sem['lock_level']) ?></td>
                                <td class="actions">
                                    <?php if (hasPermission('group_admin')): ?>
                                        <a href="lock.php?semester_id=<?= $sem['id'] ?>&year_id=<?= $yearId ?>" class="btn btn-sm btn-secondary" title="إدارة القفل">🔒</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
