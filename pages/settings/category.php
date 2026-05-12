<?php
/**
 * ============================================================
 * EQAS - إعدادات النظام - فئة الإعدادات
 * نظام التقييم الشامل - صفحة فئة إعدادات محددة
 * ============================================================
 */

$pageTitle = 'إعدادات الفئة';
$breadcrumb = ['إعدادات النظام' => 'index.php', 'فئة الإعدادات' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$category = $_GET['cat'] ?? '';
$highlight = $_GET['highlight'] ?? '';

if (empty($category)) {
    setFlash('يرجى تحديد فئة الإعدادات', 'warning');
    redirect('index.php');
}

// أسماء الفئات بالعربية
$categoryNames = [
    'general' => ['label' => 'عام', 'icon' => '🌐'],
    'academic' => ['label' => 'أكاديمي', 'icon' => '📚'],
    'grading' => ['label' => 'الدرجات', 'icon' => '📊'],
    'attendance' => ['label' => 'الحضور', 'icon' => '📅'],
    'behavior' => ['label' => 'السلوك', 'icon' => '📋'],
    'skills' => ['label' => 'المهارات', 'icon' => '🎯'],
    'teaching' => ['label' => 'التدريس', 'icon' => '👨‍🏫'],
    'climate' => ['label' => 'المناخ المدرسي', 'icon' => '🌤️'],
    'facility' => ['label' => 'المرافق', 'icon' => '🏫'],
    'eqi' => ['label' => 'مؤشر الجودة', 'icon' => '📈'],
    'promotion' => ['label' => 'الترقية', 'icon' => '🎓'],
    'alerts' => ['label' => 'التنبيهات', 'icon' => '🚨'],
    'notifications' => ['label' => 'الإشعارات', 'icon' => '📨'],
    'backup' => ['label' => 'النسخ الاحتياطي', 'icon' => '💾'],
    'export' => ['label' => 'التصدير', 'icon' => '📤'],
    'appearance' => ['label' => 'المظهر', 'icon' => '🎨'],
    'security' => ['label' => 'الأمان', 'icon' => '🔒'],
    'parent_portal' => ['label' => 'بوابة ولي الأمر', 'icon' => '👨‍👩‍👧'],
    'dashboard' => ['label' => 'لوحة المعلومات', 'icon' => '📊'],
    'system' => ['label' => 'النظام', 'icon' => '⚙️'],
];

$catInfo = $categoryNames[$category] ?? ['label' => $category, 'icon' => '📄'];
$pageTitle = $catInfo['label'];
$breadcrumb = ['إعدادات النظام' => 'index.php', $catInfo['label'] => null];

// جلب إعدادات الفئة
$stmt = $db->prepare("SELECT * FROM system_settings WHERE category = ? AND school_id IS NULL AND is_active = 1 ORDER BY sort_order");
$stmt->execute([$category]);
$settings = $stmt->fetchAll();

if (empty($settings)) {
    setFlash('لا توجد إعدادات في هذه الفئة', 'warning');
    redirect('index.php');
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('category.php?cat=' . $category);
    }

    $updatedSettings = $_POST['settings'] ?? [];
    $saved = 0;

    foreach ($settings as $setting) {
        $key = $setting['setting_key'];
        if (!isset($updatedSettings[$key])) continue;

        $newValue = trim($updatedSettings[$key]);

        // التحقق من نوع القيمة
        $valid = true;
        switch ($setting['value_type']) {
            case 'number':
                $valid = is_numeric($newValue);
                break;
            case 'boolean':
                $valid = in_array($newValue, ['true', 'false']);
                break;
            case 'json':
                json_decode($newValue);
                $valid = json_last_error() === JSON_ERROR_NONE;
                break;
            case 'string':
                $valid = mb_strlen($newValue) <= 1000;
                break;
        }

        if ($valid && $newValue !== $setting['setting_value']) {
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newValue, $setting['id']]);
            $saved++;
        }
    }

    logActivity($db, $_SESSION['user']['id'], 'settings_change', 'تحديث إعدادات فئة: ' . $category, 'settings', null);
    setFlash("تم تحديث {$saved} إعداد بنجاح", 'success');
    redirect('category.php?cat=' . $category);
}

// معالجة إعادة التعيين
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_setting'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('category.php?cat=' . $category);
    }

    $settingId = (int)$_POST['reset_setting'];

    // جلب القيمة الافتراضية من الإعدادات الأساسية
    $stmt = $db->prepare("SELECT * FROM system_settings WHERE id = ? AND category = ?");
    $stmt->execute([$settingId, $category]);
    $setting = $stmt->fetch();

    if ($setting) {
        // لا يمكن إعادة تعيين إعدادات النظام - فقط التجاوزات
        setFlash('لا يمكن إعادة تعيين إعدادات النظام الأساسية', 'warning');
    }

    redirect('category.php?cat=' . $category);
}
?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title"><?php echo $catInfo['icon']; ?> <?php echo e($catInfo['label']); ?></h2>
        <a href="index.php" class="btn btn-sm btn-outline-primary">← العودة للفئات</a>
    </div>
    <div class="card-body">
        <form method="POST" id="settings-form">
            <?= csrfField() ?>

            <?php foreach ($settings as $setting): ?>
            <div class="setting-item mb-4 p-3 border rounded <?php echo $highlight == $setting['id'] ? 'border-primary bg-light' : ''; ?>"
                 id="setting-<?php echo $setting['id']; ?>">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 style="margin:0;font-size:15px;"><?php echo e($setting['name_ar']); ?></h5>
                        <?php if ($setting['description_ar']): ?>
                        <p class="text-muted text-sm mb-0"><?php echo e($setting['description_ar']); ?></p>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;margin-top:4px;">
                            <code style="font-size:10px;background:#f5f5f5;padding:2px 6px;border-radius:3px;">
                                <?php echo e($setting['setting_key']); ?>
                            </code>
                            <span class="badge badge-light" style="font-size:10px;"><?php echo e($setting['value_type']); ?></span>
                            <?php if ($setting['is_system']): ?>
                            <span class="badge badge-secondary" style="font-size:10px;">نظام</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top:8px;">
                    <?php switch ($setting['value_type']):
                        case 'boolean': ?>
                        <div style="display:flex;gap:16px;align-items:center;">
                            <label style="display:flex;align-items:center;gap:4px;">
                                <input type="radio" name="settings[<?php echo e($setting['setting_key']); ?>]" value="true"
                                       <?php echo $setting['setting_value'] === 'true' ? 'checked' : ''; ?>>
                                نعم
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;">
                                <input type="radio" name="settings[<?php echo e($setting['setting_key']); ?>]" value="false"
                                       <?php echo $setting['setting_value'] === 'false' ? 'checked' : ''; ?>>
                                لا
                            </label>
                        </div>
                        <?php break; ?>
                        <?php case 'number': ?>
                        <input type="number" name="settings[<?php echo e($setting['setting_key']); ?>]"
                               class="form-control" style="max-width:200px;"
                               value="<?php echo e($setting['setting_value']); ?>"
                               step="any">
                        <?php break; ?>
                        <?php case 'json': ?>
                        <textarea name="settings[<?php echo e($setting['setting_key']); ?>]"
                                  class="form-control" rows="4" style="font-family:monospace;font-size:12px;"
                        ><?php echo e($setting['setting_value']); ?></textarea>
                        <?php break; ?>
                        <?php default: ?>
                        <input type="text" name="settings[<?php echo e($setting['setting_key']); ?>]"
                               class="form-control" style="max-width:400px;"
                               value="<?php echo e($setting['setting_value']); ?>">
                    <?php endswitch; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="mt-4" style="display:flex;gap:8px;">
                <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-lg">
                    💾 حفظ الإعدادات
                </button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php if ($highlight): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('setting-<?php echo $highlight; ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
