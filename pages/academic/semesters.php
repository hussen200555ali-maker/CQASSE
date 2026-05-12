<?php
/**
 * ============================================================
 * إدارة الفصول الدراسية لسنة أكاديمية محددة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إدارة الفصول الدراسية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'إدارة الفصول الدراسية' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$yearId = intval($_GET['year_id'] ?? 0);
if (!$yearId) {
    setFlash('يرجى تحديد السنة الأكاديمية', 'danger');
    redirect('index.php');
}

// جلب بيانات السنة الأكاديمية
$stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
$stmt->execute([$yearId]);
$academicYear = $stmt->fetch();

if (!$academicYear) {
    setFlash('السنة الأكاديمية غير موجودة', 'danger');
    redirect('index.php');
}

// تحديد نطاق المدرسة (school_admin يرى إحصائيات مدرسته فقط)
$schoolFilterId = null;
if (hasPermission('school_admin') && !hasPermission('group_admin')) {
    $schoolFilterId = currentSchoolId();
}

// جلب الفصول الدراسية مع الإحصائيات
$params = [$yearId];
$classFilter = '';
if ($schoolFilterId) {
    $classFilter = ' AND c.school_id = ?';
    $params[] = $schoolFilterId;
}

$stmt = $db->prepare("SELECT s.*, u.full_name_ar AS locked_by_name,
                      (SELECT COUNT(*) FROM classes c WHERE c.academic_year_id = s.academic_year_id AND c.is_active = 1{$classFilter}) AS class_count
                      FROM semesters s
                      LEFT JOIN users u ON s.locked_by = u.id
                      WHERE s.academic_year_id = ? AND s.is_active = 1
                      ORDER BY s.start_date");
$stmt->execute($params);
$semesters = $stmt->fetchAll();

// شارات مستوى القفل
$lockBadges = [
    'none' => '<span class="badge badge-success">مفتوح</span>',
    'soft' => '<span class="badge badge-info">قفل ناعم</span>',
    'hard' => '<span class="badge badge-warning">قفل صلب</span>',
    'admin_handled' => '<span class="badge badge-danger">قفل إداري</span>'
];

// معالجة تحديد الفصل الحالي (group_admin فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_current'])) {
    if (!hasPermission('group_admin')) {
        setFlash('ليس لديك صلاحية تغيير الفصل الدراسي الحالي', 'danger');
        redirect("semesters.php?year_id={$yearId}");
    }

    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("semesters.php?year_id={$yearId}");
    }

    $targetSemesterId = intval($_POST['semester_id'] ?? 0);

    // إزالة الحالية من جميع فصول السنة
    $db->prepare("UPDATE semesters SET is_current = 0 WHERE academic_year_id = ?")->execute([$yearId]);

    // تعيين الفصل المحدد كحالي
    if ($targetSemesterId) {
        $stmt = $db->prepare("UPDATE semesters SET is_current = 1 WHERE id = ? AND academic_year_id = ?");
        $stmt->execute([$targetSemesterId, $yearId]);

        logActivity($db, $_SESSION['user']['id'], 'set_current_semester', "تعيين الفصل الدراسي الحالي للسنة: {$academicYear['name']}", 'semester', $targetSemesterId);
    }

    setFlash('تم تحديث الفصل الدراسي الحالي', 'success');
    redirect("semesters.php?year_id={$yearId}");
}

// معالجة تبديل حالة القفل (اختصار)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_lock'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("semesters.php?year_id={$yearId}");
    }

    if (!hasPermission('group_admin')) {
        setFlash('ليس لديك صلاحية تغيير حالة القفل', 'danger');
        redirect("semesters.php?year_id={$yearId}");
    }

    $targetSemesterId = intval($_POST['semester_id'] ?? 0);
    $targetLevel = $_POST['target_level'] ?? '';

    if (!in_array($targetLevel, ['none', 'soft', 'hard', 'admin_handled'])) {
        setFlash('مستوى القفل غير صحيح', 'danger');
        redirect("semesters.php?year_id={$yearId}");
    }

    // [إصلاح 2026-05-11] إزالة is_locked من UPDATE
    $stmt = $db->prepare("UPDATE semesters SET lock_level = ?, locked_at = ?, locked_by = ? WHERE id = ?");
    $stmt->execute([
        $targetLevel,
        $targetLevel !== 'none' ? date('Y-m-d H:i:s') : null,
        $targetLevel !== 'none' ? $_SESSION['user']['id'] : null,
        $targetSemesterId
    ]);

    $lockLabels = ['none' => 'مفتوح', 'soft' => 'قفل ناعم', 'hard' => 'قفل صلب', 'admin_handled' => 'قفل إداري'];
    logActivity($db, $_SESSION['user']['id'], 'toggle_semester_lock', "تغيير قفل الفصل إلى: {$lockLabels[$targetLevel]}", 'semester', $targetSemesterId);

    setFlash("تم تغيير حالة القفل إلى: {$lockLabels[$targetLevel]}", 'success');
    redirect("semesters.php?year_id={$yearId}");
}

// إحصائيات لكل فصل
$semesterStats = [];
foreach ($semesters as $sem) {
    $stats = ['enrollments' => 0, 'assessments' => 0];

    try {
        // عدد التسجيلات (مفلترة حسب المدرسة لـ school_admin)
        $enrollSql = "SELECT COUNT(*) FROM student_enrollments se JOIN classes c ON se.class_id = c.id WHERE c.academic_year_id = ? AND se.is_active = 1";
        $enrollParams = [$yearId];
        if ($schoolFilterId) {
            $enrollSql .= " AND c.school_id = ?";
            $enrollParams[] = $schoolFilterId;
        }
        $stmt = $db->prepare($enrollSql);
        $stmt->execute($enrollParams);
        $stats['enrollments'] = $stmt->fetchColumn();
    } catch (Exception $e) {}

    try {
        // عدد التقييمات (مفلترة حسب المدرسة لـ school_admin)
        $assessSql = "SELECT COUNT(*) FROM assessment_responses WHERE semester_id = ? AND is_active = 1";
        $assessParams = [$sem['id']];
        if ($schoolFilterId) {
            $assessSql .= " AND school_id = ?";
            $assessParams[] = $schoolFilterId;
        }
        $stmt = $db->prepare($assessSql);
        $stmt->execute($assessParams);
        $stats['assessments'] = $stmt->fetchColumn();
    } catch (Exception $e) {}

    $semesterStats[$sem['id']] = $stats;
}
?>

<!-- بطاقة معلومات السنة -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">📚 فصول السنة: <?= e($academicYear['name']) ?></h3>
            <div class="flex gap-2">
                <a href="view.php?id=<?= $yearId ?>" class="btn btn-info btn-sm">👁️ عرض السنة</a>
                <?php if (hasPermission('group_admin')): ?>
                    <a href="lock.php?year_id=<?= $yearId ?>" class="btn btn-secondary btn-sm">🔒 إدارة القفل</a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-3 mb-6" style="gap:var(--space-4);">
            <div class="text-sm"><strong>الفترة:</strong> <?= formatDateAr($academicYear['start_date']) ?> — <?= formatDateAr($academicYear['end_date']) ?></div>
            <div class="text-sm"><strong>الحالة:</strong>
                <?php
                $statusLabels = ['planning' => '<span class="badge badge-warning">تخطيط</span>', 'active' => '<span class="badge badge-success">نشطة</span>', 'closed' => '<span class="badge badge-secondary">مغلقة</span>'];
                echo $statusLabels[$academicYear['status']] ?? e($academicYear['status']);
                ?>
            </div>
            <div class="text-sm"><strong>عدد الفصول:</strong> <?= count($semesters) ?></div>
        </div>

        <?php if (empty($semesters)): ?>
            <p class="text-secondary text-center">لا توجد فصول دراسية لهذه السنة</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الفصل</th>
                            <th>تاريخ البداية</th>
                            <th>تاريخ النهاية</th>
                            <th>الحالي</th>
                            <th>مستوى القفل</th>
                            <th>التسجيلات</th>
                            <th>التقييمات</th>
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
                                        <span class="badge badge-primary">✓ الحالي</span>
                                    <?php elseif (hasPermission('group_admin')): ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                                            <button type="submit" name="set_current" class="btn btn-sm btn-light" title="تعيين كحالي">تعيين</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $lockBadges[$sem['lock_level']] ?? e($sem['lock_level']) ?>
                                    <?php if ($sem['locked_by_name']): ?>
                                        <div class="text-xs text-secondary">بواسطة: <?= e($sem['locked_by_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatNumberAr($semesterStats[$sem['id']]['enrollments']) ?></td>
                                <td><?= formatNumberAr($semesterStats[$sem['id']]['assessments']) ?></td>
                                <td class="actions">
                                    <?php if (hasPermission('group_admin')): ?>
                                        <!-- تبديل سريع للقفل -->
                                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من تغيير حالة القفل؟');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                                            <?php if ($sem['lock_level'] === 'none'): ?>
                                                <input type="hidden" name="target_level" value="soft">
                                                <button type="submit" name="toggle_lock" class="btn btn-sm btn-info" title="قفل ناعم">🔒</button>
                                            <?php elseif ($sem['lock_level'] === 'soft'): ?>
                                                <input type="hidden" name="target_level" value="hard">
                                                <button type="submit" name="toggle_lock" class="btn btn-sm btn-warning" title="قفل صلب">🔒🔒</button>
                                            <?php elseif ($sem['lock_level'] === 'hard'): ?>
                                                <input type="hidden" name="target_level" value="admin_handled">
                                                <button type="submit" name="toggle_lock" class="btn btn-sm btn-danger" title="قفل إداري">🔒🔒🔒</button>
                                            <?php else: ?>
                                                <input type="hidden" name="target_level" value="none">
                                                <button type="submit" name="toggle_lock" class="btn btn-sm btn-success" title="فتح القفل">🔓</button>
                                            <?php endif; ?>
                                        </form>
                                        <a href="lock.php?semester_id=<?= $sem['id'] ?>&year_id=<?= $yearId ?>" class="btn btn-sm btn-secondary" title="إدارة القفل">⚙️</a>
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
