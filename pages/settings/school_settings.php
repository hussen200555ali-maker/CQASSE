<?php
/**
 * ============================================================
 * EQAS - إعدادات النظام - إعدادات المدرسة
 * نظام التقييم الشامل - صفحة تجاوزات إعدادات المدرسة
 * ============================================================
 */

$pageTitle = 'إعدادات المدرسة';
$breadcrumb = ['إعدادات النظام' => 'index.php', 'إعدادات المدرسة' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// جلب المدارس
$stmt = $db->query("SELECT id, name_ar, code FROM schools WHERE is_active = 1 ORDER BY name_ar");
$schools = $stmt->fetchAll();

$selectedSchool = $_GET['school_id'] ?? $_POST['school_id'] ?? '';
$selectedCategory = $_GET['category'] ?? '';

// جلب إعدادات المدرسة
$schoolSettings = [];
$systemDefaults = [];

if ($selectedSchool) {
    // الإعدادات الافتراضية للنظام
    $sql = "SELECT * FROM system_settings WHERE is_active = 1 AND school_id IS NULL";
    $params = [];
    if ($selectedCategory) {
        $sql .= " AND category = ?";
        $params[] = $selectedCategory;
    }
    $sql .= " ORDER BY category, sort_order";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $systemDefaults = $stmt->fetchAll();

    // تجاوزات المدرسة
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$selectedSchool]);
    $schoolSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('school_settings.php?school_id=' . (int)$selectedSchool);
    }

    $schoolId = (int)$_POST['school_id'];
    $settings = $_POST['settings'] ?? [];
    $resetKeys = $_POST['reset_keys'] ?? [];

    $saved = 0;

    // حفظ التجاوزات
    foreach ($settings as $key => $value) {
        if (in_array($key, $resetKeys)) continue;

        // التحقق من القيمة مقابل الافتراضي
        $stmt = $db->prepare("SELECT setting_value, value_type, category FROM system_settings WHERE setting_key = ? AND school_id IS NULL AND is_active = 1");
        $stmt->execute([$key]);
        $default = $stmt->fetch();

        if ($default && $value !== $default['setting_value']) {
            // التحقق من نوع القيمة
            $valid = true;
            switch ($default['value_type']) {
                case 'number':
                    $valid = is_numeric($value);
                    break;
                case 'boolean':
                    $valid = in_array($value, ['true', 'false', '1', '0']);
                    break;
            }

            if ($valid) {
                $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value, value_type, name_ar, description_ar, is_system, school_id, sort_order, is_active, created_at, updated_at)
                                      SELECT category, setting_key, ?, value_type, name_ar, description_ar, 0, ?, sort_order, 1, NOW(), NOW()
                                      FROM system_settings WHERE setting_key = ? AND school_id IS NULL
                                      ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                $stmt->execute([$value, $schoolId, $key, $value]);
                $saved++;
            }
        }
    }

    // إعادة تعيين التجاوزات
    foreach ($resetKeys as $key) {
        if (!empty($key)) {
            $stmt = $db->prepare("DELETE FROM system_settings WHERE setting_key = ? AND school_id = ?");
            $stmt->execute([$key, $schoolId]);
        }
    }

    logActivity($db, $_SESSION['user']['id'], 'settings_change', 'تحديث إعدادات المدرسة', 'school_settings', $schoolId);
    setFlash("تم حفظ {$saved} إعداد بنجاح", 'success');
    redirect('school_settings.php?school_id=' . (int)$schoolId . ($selectedCategory ? '&category=' . urlencode($selectedCategory) : ''));
}

// أسماء الفئات
$categoryNames = [
    'general' => 'عام', 'academic' => 'أكاديمي', 'grading' => 'الدرجات',
    'attendance' => 'الحضور', 'behavior' => 'السلوك', 'skills' => 'المهارات',
    'teaching' => 'التدريس', 'climate' => 'المناخ', 'facility' => 'المرافق',
    'eqi' => 'مؤشر الجودة', 'promotion' => 'الترقية', 'alerts' => 'التنبيهات',
    'notifications' => 'الإشعارات', 'backup' => 'النسخ الاحتياطي', 'export' => 'التصدير',
    'appearance' => 'المظهر', 'security' => 'الأمان', 'parent_portal' => 'بوابة ولي الأمر',
    'dashboard' => 'لوحة المعلومات', 'system' => 'النظام',
];

// جلب فئات متاحة
$availableCategories = [];
if ($selectedSchool) {
    $stmt = $db->prepare("SELECT DISTINCT category FROM system_settings WHERE is_active = 1 AND school_id IS NULL ORDER BY category");
    $stmt->execute();
    $availableCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">🏫 إعدادات المدرسة - التجاوزات</h2>
    </div>
    <div class="card-body">
        <?php
        // عرض اسم المدرسة المحددة بوضوح
        $selectedSchoolName = '';
        if ($selectedSchool) {
            foreach ($schools as $s) {
                if ($s['id'] == $selectedSchool) {
                    $selectedSchoolName = $s['name_ar'] . ' (' . $s['code'] . ')';
                    break;
                }
            }
        }
        ?>
        <?php if ($selectedSchool && $selectedSchoolName): ?>
        <div class="alert alert-success mb-4" style="font-size:16px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:24px;">🏫</span>
            <strong>المدرسة المحددة:</strong> <?php echo e($selectedSchoolName); ?>
        </div>
        <?php endif; ?>

        <form method="GET" class="form-grid mb-4">
            <div class="form-group">
                <label class="form-label">اختر المدرسة</label>
                <select name="school_id" class="form-control" required onchange="this.form.submit()">
                    <option value="">-- اختر مدرسة --</option>
                    <?php foreach ($schools as $school): ?>
                    <option value="<?php echo $school['id']; ?>" <?php echo $selectedSchool == $school['id'] ? 'selected' : ''; ?>>
                        <?php echo e($school['name_ar'] . ' (' . $school['code'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الفئة</label>
                <select name="category" class="form-control" onchange="this.form.submit()">
                    <option value="">جميع الفئات</option>
                    <?php foreach ($availableCategories as $cat): ?>
                    <option value="<?php echo e($cat); ?>" <?php echo $selectedCategory === $cat ? 'selected' : ''; ?>>
                        <?php echo e($categoryNames[$cat] ?? $cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($selectedSchool && !empty($systemDefaults)): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($selectedSchool, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="alert alert-info">
                <strong>ℹ️ تعليمات:</strong>
                القيم المعروضة هي القيم الافتراضية للنظام. لتجاوز قيمة لمدرسة محددة، عدّل القيمة وسيتم حفظها كتجاوز خاص بالمدرسة.
                لإعادة تعيين تجاوز، حدد مربع "إعادة تعيين" بجانب الإعداد.
            </div>

            <?php
            $currentCategory = '';
            foreach ($systemDefaults as $setting):
                if ($currentCategory !== $setting['category']):
                    if ($currentCategory !== '') echo '</div>'; // إغلاق البطاقة السابقة
                    $currentCategory = $setting['category'];
                    $catLabel = $categoryNames[$currentCategory] ?? $currentCategory;
            ?>
            <div class="card border mb-3">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><?php echo e($catLabel); ?></h5>
                </div>
                <div class="card-body">
            <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div style="flex:1;">
                            <label class="font-semibold text-sm"><?php echo e($setting['name_ar']); ?></label>
                            <?php if ($setting['description_ar']): ?>
                            <br><small class="text-muted"><?php echo e($setting['description_ar']); ?></small>
                            <?php endif; ?>
                            <br><code style="font-size:10px;"><?php echo e($setting['setting_key']); ?></code>
                            <span class="badge badge-light" style="font-size:10px;"><?php echo e($setting['value_type']); ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <small class="text-muted">الافتراضي: <code><?php echo e(truncate($setting['setting_value'], 30)); ?></code></small>
                            <?php
                            $currentValue = $schoolSettings[$setting['setting_key']] ?? $setting['setting_value'];
                            $isOverridden = isset($schoolSettings[$setting['setting_key']]);
                            ?>
                            <?php switch ($setting['value_type']):
                                case 'boolean': ?>
                                <select name="settings[<?php echo e($setting['setting_key']); ?>]" class="form-control form-control-sm" style="width:100px;">
                                    <option value="true" <?php echo $currentValue === 'true' ? 'selected' : ''; ?>>نعم</option>
                                    <option value="false" <?php echo $currentValue === 'false' ? 'selected' : ''; ?>>لا</option>
                                </select>
                                <?php break; ?>
                                <?php case 'number': ?>
                                <input type="number" name="settings[<?php echo e($setting['setting_key']); ?>]"
                                       class="form-control form-control-sm" style="width:100px;"
                                       value="<?php echo e($currentValue); ?>">
                                <?php break; ?>
                                <?php case 'json': ?>
                                <textarea name="settings[<?php echo e($setting['setting_key']); ?>]"
                                          class="form-control form-control-sm" rows="2" style="width:200px;"><?php echo e($currentValue); ?></textarea>
                                <?php break; ?>
                                <?php default: ?>
                                <input type="text" name="settings[<?php echo e($setting['setting_key']); ?>]"
                                       class="form-control form-control-sm" style="width:200px;"
                                       value="<?php echo e($currentValue); ?>">
                            <?php endswitch; ?>

                            <?php if ($isOverridden): ?>
                            <label style="font-size:11px;display:flex;align-items:center;gap:4px;white-space:nowrap;">
                                <input type="checkbox" name="reset_keys[]" value="<?php echo e($setting['setting_key']); ?>">
                                🔄 إعادة تعيين
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>

            <?php endforeach; ?>
            <?php if ($currentCategory !== '') echo '</div></div>'; ?>

            <div class="mt-4" style="display:flex;gap:8px;">
                <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-lg">
                    💾 حفظ التغييرات
                </button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
        <?php elseif ($selectedSchool): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p class="empty-state-text">لا توجد إعدادات للفئة المحددة</p>
            </div>
        <?php else: ?>
            <div class="alert alert-info">يرجى اختيار مدرسة لعرض وتعديل إعداداتها.</div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
