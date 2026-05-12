<?php
/**
 * ============================================================
 * تعطيل/تفعيل طالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تغيير حالة الطالب';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => 'index.php',
    'تغيير حالة الطالب' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// تحديد نطاق المدرسة
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
$isGroupAdmin = ($userRole === 'group_admin');

$studentId = intval($_GET['id'] ?? 0);
if (!$studentId) {
    setFlash('معرف الطالب غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT s.*, gl.name_ar AS grade_name, sc.name_ar AS school_name
                      FROM students s
                      LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                      LEFT JOIN schools sc ON s.school_id = sc.id
                      WHERE s.id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('الطالب غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من أن مدير المدرسة يعدل طلاب مدرسته فقط
if (!$isGroupAdmin && $student['school_id'] != $userSchoolId) {
    setFlash('ليس لديك صلاحية لتعديل طلاب مدرسة أخرى', 'danger');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("toggle.php?id={$studentId}");
    }

    $newStatus = $student['is_active'] ? 0 : 1;

    try {
        $stmt = $db->prepare("UPDATE students SET is_active = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $studentId]);

        $statusText = $newStatus ? 'تفعيل' : 'تعطيل';
        logActivity($db, $_SESSION['user']['id'], 'toggle_student', "{$statusText} الطالب: {$student['full_name_ar']} ({$student['student_number']})", 'student', $studentId);

        setFlash("تم {$statusText} الطالب {$student['full_name_ar']} بنجاح", 'success');
        redirect('index.php');
    } catch (Exception $e) {
        error_log('Student Toggle Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء تحديث حالة الطالب', 'danger');
        redirect('index.php');
    }
}

$actionText = $student['is_active'] ? 'تعطيل' : 'تفعيل';
$actionIcon = $student['is_active'] ? '🚫' : '✅';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $actionIcon ?> تأكيد <?= $actionText ?> الطالب</h3>
    </div>
    <div class="card-body text-center" style="padding:var(--space-8);">
        <div style="font-size:64px;margin-bottom:var(--space-4);"><?= $student['is_active'] ? '⚠️' : '🔄' ?></div>
        <h4>هل أنت متأكد من <?= $actionText ?> هذا الطالب؟</h4>

        <div class="card mt-4 mb-4" style="max-width:400px;margin:var(--space-4) auto;">
            <div class="card-body">
                <table style="width:100%;text-align:right;">
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">رقم الطالب:</td>
                        <td style="padding:var(--space-2);"><code><?= e($student['student_number']) ?></code></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الاسم:</td>
                        <td style="padding:var(--space-2);"><?= e($student['full_name_ar']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">المرحلة:</td>
                        <td style="padding:var(--space-2);"><?= e($student['grade_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">المدرسة:</td>
                        <td style="padding:var(--space-2);"><?= e($student['school_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الحالة الحالية:</td>
                        <td style="padding:var(--space-2);"><?= $student['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-secondary">غير نشط</span>' ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php if ($student['is_active']): ?>
            <p class="text-secondary mb-4">سيتم تعطيل الطالب ولن يظهر في القوائم النشطة. لن يتم حذف أي بيانات.</p>
        <?php else: ?>
            <p class="text-secondary mb-4">سيتم تفعيل الطالب وسيظهر في القوائم النشطة مرة أخرى.</p>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>
            <div class="flex gap-3 justify-center">
                <button type="submit" class="btn btn-<?= $student['is_active'] ? 'danger' : 'success' ?>"><?= $actionIcon ?> نعم، <?= $actionText ?></button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
