<?php
/**
 * ============================================================
 * أرشفة/استعادة مستخدم (حذف ناعم)
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'أرشفة/استعادة مستخدم';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'تغيير حالة المستخدم' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    setFlash('معرف المستخدم غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات المستخدم
$stmt = $db->prepare("SELECT u.*, r.display_name_ar AS role_name_ar FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('المستخدم غير موجود', 'danger');
    redirect('index.php');
}

// منع تعطيل نفس المستخدم
if ($userId == $_SESSION['user']['id']) {
    setFlash('لا يمكنك تعطيل حسابك الخاص', 'warning');
    redirect('index.php');
}

// معالجة طلب التعطيل/التفعيل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect("delete.php?id={$userId}");
    }

    $newStatus = $user['is_active'] ? 0 : 1;

     try {
         if ($newStatus === 0) {
              // أرشفة المستخدم (تعطيل)
              $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
              $stmt->execute([$userId]);
              $actionPerformed = 'أرشفة';
          } else {
              // استعادة المستخدم (تفعيل)
              $stmt = $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
              $stmt->execute([$userId]);
              $actionPerformed = 'استعادة';
          }

         logActivity($db, $_SESSION['user']['id'], 'toggle_user_status', "{$actionPerformed} المستخدم: {$user['username']}", 'user', $userId);

         setFlash("تم {$actionPerformed} المستخدم {$user['full_name_ar']} بنجاح", 'success');
         redirect('index.php');
     } catch (Exception $e) {
         error_log('User Toggle Error: ' . $e->getMessage());
         setFlash('حدث خطأ أثناء تحديث حالة المستخدم', 'danger');
         redirect('index.php');
     }
}

$actionText = $user['is_active'] ? 'أرشفة' : 'استعادة';
        $actionIcon = $user['is_active'] ? '🗑️' : '🔄';
        $actionColor = $user['is_active'] ? 'danger' : 'success';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $actionIcon ?> تأكيد <?= $actionText ?> المستخدم</h3>
    </div>
    <div class="card-body text-center" style="padding:var(--space-8);">
        <div style="font-size:64px;margin-bottom:var(--space-4);"><?= $user['is_active'] ? '⚠️' : '🔄' ?></div>

        <h4>هل أنت متأكد من <?= $actionText ?> هذا المستخدم؟</h4>

        <div class="card mt-4 mb-4" style="max-width:400px;margin:var(--space-4) auto;">
            <div class="card-body">
                <table style="width:100%;text-align:right;">
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الاسم الكامل:</td>
                        <td style="padding:var(--space-2);"><?= e($user['full_name_ar']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">اسم المستخدم:</td>
                        <td style="padding:var(--space-2);"><?= e($user['username']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الدور:</td>
                        <td style="padding:var(--space-2);"><?= e($user['role_name_ar']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;padding:var(--space-2);">الحالة الحالية:</td>
                        <td style="padding:var(--space-2);">
                            <?php if ($user['is_active']): ?>
                                <span class="badge badge-success">نشط</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">غير نشط</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

<?php if ($user['is_active']): ?>
             <p class="text-secondary mb-4">سيتم أرشفة حساب المستخدم ولن يتمكن من تسجيل الدخول. لن يتم حذف أي بيانات وسيبقى سجله محفوظًا للمراجعة التاريخية.</p>
         <?php else: ?>
             <p class="text-secondary mb-4">سيتم استعادة حساب المستخدم وسيتمكن من تسجيل الدخول مرة أخرى.</p>
         <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>
            <div class="flex gap-3 justify-center">
                <button type="submit" class="btn btn-<?= $actionColor ?>"><?= $actionIcon ?> نعم، <?= $actionText ?></button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
