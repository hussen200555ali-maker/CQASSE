<?php
/**
 * ============================================================
 * أرشفة/استعادة مدرسة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'أرشفة/استعادة مدرسة';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => 'index.php',
    'تغيير حالة المدرسة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$schoolId = intval($_GET['id'] ?? 0);
if (!$schoolId) {
    setFlash('معرف المدرسة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('المدرسة غير موجودة', 'danger');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("toggle.php?id={$schoolId}");
    }

    // If currently active, we archive; if archived, we restore.
    $isCurrentlyActive = (bool)$school['is_active'];

    try {
        if ($isCurrentlyActive) {
            $stmt = $db->prepare("UPDATE schools SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$schoolId]);
            $actionPerformed = 'أرشفة';
            $statusText = 'الأرشفة';
        } else {
            $stmt = $db->prepare("UPDATE schools SET is_active = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$schoolId]);
            $actionPerformed = 'استعادة';
            $statusText = 'الاستعادة';
        }

        logActivity($db, $_SESSION['user']['id'], 'toggle_school', "{$actionPerformed} المدرسة: {$school['name_ar']}", 'school', $schoolId);

        setFlash("تم {$actionPerformed} المدرسة {$school['name_ar']} بنجاح", 'success');
        redirect('index.php');
    } catch (Exception $e) {
        error_log('School Toggle Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء تحديث حالة المدرسة', 'danger');
        redirect('index.php');
    }
}

$isCurrentlyActive = (bool)$school['is_active'];
$actionText = $isCurrentlyActive ? 'أرشفة' : 'استعادة';
$actionIcon = $isCurrentlyActive ? '🗑️' : '🔄';
$actionColor = $isCurrentlyActive ? 'danger' : 'success';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $actionIcon ?> تأكيد <?= $actionText ?> المدرسة</h3>
    </div>
    <div class="card-body text-center" style="padding:var(--space-8);">
        <div style="font-size:64px;margin-bottom:var(--space-4);"><?= $school['is_active'] ? '⚠️' : '🔄' ?></div>
        <h4>هل أنت متأكد من <?= $actionText ?> هذه المدرسة؟</h4>

        <div class="card mt-4 mb-4" style="max-width:400px;margin:var(--space-4) auto;">
            <div class="card-body">
                <table style="width:100%;text-align:right;">
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">اسم المدرسة:</td>
                        <td style="padding:var(--space-2);"><?= e($school['name_ar']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الرمز:</td>
                        <td style="padding:var(--space-2);"><code><?= e($school['code'] ?? ($school['reference_code'] ?? '—')) ?></code></td>

                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الحالة الحالية:</td>
                        <td style="padding:var(--space-2);"><?= $school['is_active'] ? '<span class="badge badge-success">نشطة</span>' : '<span class="badge badge-secondary">غير نشطة</span>' ?></td>
                    </tr>
                </table>
            </div>
        </div>

<?php if ($isCurrentlyActive): ?>
             <p class="text-secondary mb-4">سيتم أرشفة المدرسة ولن تظهر في القوائم النشطة. لن يتم حذف أي بيانات وسيبقى سجلها محفوظًا للمراجعة التاريخية.</p>
         <?php else: ?>
             <p class="text-secondary mb-4">سيتم استعادة المدرسة وستظهر في القوائم النشطة مرة أخرى.</p>
         <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>
            <div class="flex gap-3 justify-center">
                <button type="submit" class="btn btn-<?= $school['is_active'] ? 'danger' : 'success' ?>"><?= $actionIcon ?> نعم، <?= $actionText ?></button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
