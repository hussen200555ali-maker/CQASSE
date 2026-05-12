<?php
/**
 * ============================================================
 * إدارة قفل الفصول الدراسية
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * 
 * نظام القفل ثلاثي المستويات:
 * - قفل ناعم (soft): يمنع إدخال بيانات جديدة، يسمح بالعرض
 * - قفل صلب (hard): يمنع جميع التعديلات، المدير يمكنه العرض فقط
 * - قفل إداري (admin_handled): القفل النهائي، فقط مدير المجموعة يمكنه فتحه
 * ============================================================
 */

$pageTitle = 'إدارة القفل';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'إدارة القفل' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير النظام
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// تحديد ما إذا كان القفل لفصل دراسي أو سنة أكاديمية
$semesterId = intval($_GET['semester_id'] ?? 0);
$yearId = intval($_GET['year_id'] ?? 0);
$action = $_GET['action'] ?? '';

if (!$semesterId && !$yearId) {
    setFlash('يرجى تحديد فصل دراسي أو سنة أكاديمية', 'danger');
    redirect('index.php');
}

// جلب بيانات الفصل الدراسي
$semester = null;
$academicYear = null;

if ($semesterId) {
    $stmt = $db->prepare("SELECT s.*, ay.name AS year_name, ay.id AS year_id, ay.status AS year_status FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.id = ?");
    $stmt->execute([$semesterId]);
    $semester = $stmt->fetch();

    if (!$semester) {
        setFlash('الفصل الدراسي غير موجود', 'danger');
        redirect('index.php');
    }
    $yearId = $semester['year_id'];
}

// جلب بيانات السنة الأكاديمية
$stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
$stmt->execute([$yearId]);
$academicYear = $stmt->fetch();

if (!$academicYear) {
    setFlash('السنة الأكاديمية غير موجودة', 'danger');
    redirect('index.php');
}

// جلب جميع فصول السنة
$semesters = $db->prepare("SELECT * FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
$semesters->execute([$yearId]);
$allSemesters = $semesters->fetchAll();

// وصف مستويات القفل
$lockDescriptions = [
    'none' => ['label' => 'مفتوح', 'badge' => 'badge-success', 'desc' => 'الفصل مفتوح بالكامل. يمكن إدخال وتعديل البيانات.'],
    'soft' => ['label' => 'قفل ناعم', 'badge' => 'badge-info', 'desc' => 'يمنع إدخال بيانات جديدة. يسمح بعرض البيانات الموجودة فقط. يمكن للمدير فتحه.'],
    'hard' => ['label' => 'قفل صلب', 'badge' => 'badge-warning', 'desc' => 'يمنع جميع التعديلات. يسمح بالعرض فقط. يمكن لمدير المجموعة فتحه.'],
    'admin_handled' => ['label' => 'قفل إداري', 'badge' => 'badge-danger', 'desc' => 'القفل النهائي. فقط مدير المجموعة يمكنه فتحه. يُستخدم عند إغلاق السنة نهائياً.']
];

// ترتيب المستويات
$lockOrder = ['none' => 0, 'soft' => 1, 'hard' => 2, 'admin_handled' => 3];

// معالجة طلب القفل/الفتح
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("lock.php?year_id={$yearId}" . ($semesterId ? "&semester_id={$semesterId}" : ""));
    }

    $targetSemesterId = intval($_POST['semester_id'] ?? 0);
    $newLockLevel = $_POST['lock_level'] ?? '';
    $confirmAction = $_POST['confirm_action'] ?? '';

    if ($confirmAction !== 'yes') {
        setFlash('لم يتم تأكيد العملية', 'warning');
        redirect("lock.php?year_id={$yearId}" . ($semesterId ? "&semester_id={$semesterId}" : ""));
    }

    // التحقق من المستوى الجديد
    if (!in_array($newLockLevel, ['none', 'soft', 'hard', 'admin_handled'])) {
        setFlash('مستوى القفل غير صحيح', 'danger');
        redirect("lock.php?year_id={$yearId}");
    }

    // جلب بيانات الفصل المستهدف
    $stmt = $db->prepare("SELECT * FROM semesters WHERE id = ? AND academic_year_id = ?");
    $stmt->execute([$targetSemesterId, $yearId]);
    $targetSemester = $stmt->fetch();

    if (!$targetSemester) {
        setFlash('الفصل الدراسي غير موجود', 'danger');
        redirect("lock.php?year_id={$yearId}");
    }

    // التحقق من صلاحية فتح القفل الإداري
    if ($targetSemester['lock_level'] === 'admin_handled' && $newLockLevel !== 'admin_handled') {
        if (!hasPermission('group_admin')) {
            setFlash('فقط مدير المجموعة يمكنه فتح القفل الإداري', 'danger');
            redirect("lock.php?year_id={$yearId}");
        }
    }

    try {
        // [إصلاح 2026-05-11] إزالة is_locked من UPDATE (العمود غير موجود في schema)
        // جدول semesters يحتوي فقط على: lock_level, locked_at, locked_by
        $lockedAt = ($newLockLevel !== 'none') ? date('Y-m-d H:i:s') : null;
        $lockedBy = ($newLockLevel !== 'none') ? $_SESSION['user']['id'] : null;

        $stmt = $db->prepare("UPDATE semesters SET lock_level = ?, locked_at = ?, locked_by = ? WHERE id = ?");
        $stmt->execute([$newLockLevel, $lockedAt, $lockedBy, $targetSemesterId]);

        // [إصلاح 2026-05-11] جدول academic_years لا يحتوي على is_locked/locked_at/locked_by
        // فقط نحدّث status إلى 'closed' إذا كان القفل الإداري مطلوباً
        // لا نحدّث أعمدة غير موجودة في schema
        if ($action === 'close' && $newLockLevel === 'admin_handled') {
            $stmt = $db->prepare("UPDATE academic_years SET status = 'closed' WHERE id = ?");
            $stmt->execute([$yearId]);
        }

        $lockLabel = $lockDescriptions[$newLockLevel]['label'] ?? $newLockLevel;
        logActivity($db, $_SESSION['user']['id'], 'lock_semester', "تغيير قفل الفصل الدراسي ({$targetSemester['name_ar']}) إلى: {$lockLabel}", 'semester', $targetSemesterId);

        setFlash("تم تغيير مستوى القفل إلى: {$lockLabel}", 'success');
        redirect("view.php?id={$yearId}");
    } catch (Exception $e) {
        error_log('Lock Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء تغيير القفل', 'danger');
    }
}

// تقدم القفل الحالي
$currentTarget = $semester ?? $allSemesters[0] ?? null;
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🔒 إدارة قفل الفصول الدراسية - <?= e($academicYear['name']) ?></h3>
        <a href="view.php?id=<?= $yearId ?>" class="btn btn-light btn-sm">↩ العودة</a>
    </div>
    <div class="card-body">
        <!-- شرح نظام القفل -->
        <div class="card mb-6" style="background:var(--background);">
            <div class="card-body">
                <h5 class="mb-3">📋 نظام القفل ثلاثي المستويات</h5>
                <div class="grid grid-4" style="gap:var(--space-3);">
                    <?php foreach ($lockDescriptions as $key => $info): ?>
                        <div style="text-align:center;padding:var(--space-3);border:1px solid var(--border-light);border-radius:var(--radius-md);">
                            <span class="badge <?= $info['badge'] ?>" style="display:block;margin-bottom:var(--space-2);"><?= $info['label'] ?></span>
                            <p class="text-xs text-secondary" style="margin:0;"><?= $info['desc'] ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 text-center text-sm text-secondary">
                    ترتيب المستويات: مفتوح → قفل ناعم → قفل صلب → قفل إداري
                </div>
            </div>
        </div>

        <!-- قائمة الفصول الدراسية مع إجراءات القفل -->
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الفصل الدراسي</th>
                        <th>الفترة</th>
                        <th>مستوى القفل الحالي</th>
                        <th>قفل بواسطة</th>
                        <th>تاريخ القفل</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($allSemesters as $sem): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td style="font-weight:600;"><?= e($sem['name_ar']) ?></td>
                            <td class="text-sm"><?= formatDateAr($sem['start_date']) ?> — <?= formatDateAr($sem['end_date']) ?></td>
                            <td>
                                <span class="badge <?= $lockDescriptions[$sem['lock_level']]['badge'] ?? 'badge-secondary' ?>">
                                    <?= $lockDescriptions[$sem['lock_level']]['label'] ?? e($sem['lock_level']) ?>
                                </span>
                            </td>
                            <td class="text-sm">
                                <?php
                                if ($sem['locked_by']) {
                                    $stmt = $db->prepare("SELECT full_name_ar FROM users WHERE id = ?");
                                    $stmt->execute([$sem['locked_by']]);
                                    echo e($stmt->fetchColumn());
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="text-sm"><?= $sem['locked_at'] ? formatDateAr($sem['locked_at'], 'medium') : '—' ?></td>
                            <td>
                                <!-- نموذج تغيير القفل -->
                                <form method="POST" action="lock.php?year_id=<?= $yearId ?>&semester_id=<?= $sem['id'] ?><?= $action ? "&action={$action}" : '' ?>" 
                                      onsubmit="return confirmLockChange(this);">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                                    <div class="flex gap-2 items-center">
                                        <select name="lock_level" class="form-control" style="width:auto;min-width:120px;">
                                            <?php foreach ($lockDescriptions as $key => $info): ?>
                                                <option value="<?= $key ?>" <?= $sem['lock_level'] === $key ? 'selected' : '' ?>>
                                                    <?= $info['label'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="confirm_action" value="no" class="confirm-input">
                                        <button type="submit" class="btn btn-sm btn-primary">تطبيق</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageJs = <<<JS
    function confirmLockChange(form) {
        const select = form.querySelector('select[name="lock_level"]');
        const confirmInput = form.querySelector('.confirm-input');
        const selectedText = select.options[select.selectedIndex].text;
        
        const messages = {
            'none': 'هل أنت متأكد من فتح القفل؟ سيتم السماح بتعديل البيانات.',
            'soft': '⚠️ هل أنت متأكد من تطبيق القفل الناعم؟ لن يتمكن المستخدمون من إدخال بيانات جديدة.',
            'hard': '⚠️ هل أنت متأكد من تطبيق القفل الصلب؟ لن يتمكن المستخدمون من تعديل أي بيانات.',
            'admin_handled': '🚨 تحذير: هل أنت متأكد من تطبيق القفل الإداري؟ هذا القفل نهائي ولا يمكن فتحه إلا من قبل مدير المجموعة.'
        };
        
        const selectedValue = select.value;
        const message = messages[selectedValue] || 'هل أنت متأكد؟';
        
        if (confirm(message)) {
            confirmInput.value = 'yes';
            return true;
        }
        return false;
    }
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
