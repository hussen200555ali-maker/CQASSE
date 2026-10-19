<?php
/**
 * ============================================================
 * سياسات المجموعة الموحدة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'سياسات المجموعة الموحدة';
$breadcrumb = [
    'الإدارة' => null,
    'الإعدادات' => 'index.php',
    'سياسات المجموعة الموحدة' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المجموعة
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../../index.php');
}

// معالجة النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('group_policy.php');
    }

    // جمع الإعدادات من النموذج
    $settingsToSave = [
        // الترميز اللوني
        'policy_color_green_from' => $_POST['color_green_from'] ?? '',
        'policy_color_green_to' => $_POST['color_green_to'] ?? '',
        'policy_color_yellow_from' => $_POST['color_yellow_from'] ?? '',
        'policy_color_yellow_to' => $_POST['color_yellow_to'] ?? '',
        'policy_color_red_from' => $_POST['color_red_from'] ?? '',
        'policy_color_red_to' => $_POST['color_red_to'] ?? '',

        // نسب النجاح والرسوب
        'policy_pass_rate_academic' => $_POST['pass_rate_academic'] ?? '',
        'policy_pass_rate_behavior' => $_POST['pass_rate_behavior'] ?? '',
        'policy_pass_rate_attendance' => $_POST['pass_rate_attendance'] ?? '',

        // سياسات الإشعارات العامة
        'policy_notifications_enabled' => isset($_POST['notifications_enabled']) ? 1 : 0,
        'policy_notification_email_enabled' => isset($_POST['notification_email_enabled']) ? 1 : 0,
        'policy_notification_sms_enabled' => isset($_POST['notification_sms_enabled']) ? 1 : 0,
        'policy_notification_app_enabled' => isset($_POST['notification_app_enabled']) ? 1 : 0,
    ];

    try {
        foreach ($settingsToSave as $key => $value) {
            // التحقق مما إذا كان الإعداد موجودًا
            $stmt = $db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                // تحديث
                $updateStmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
                $updateStmt->execute([$value, $key]);
            } else {
                // إدخال جديد
                $insertStmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, name_ar, description_ar, category) VALUES (?, ?, ?, ?, ?)");
                $category = 'policy';
                $nameAr = '';
                $description = '';
                if (strpos($key, 'color_') !== false) {
                    $category = 'policy_colors';
                    $nameAr = 'إعداد الترميز اللوني';
                    $description = 'إعداد الترميز اللوني للسياسات';
                } elseif (strpos($key, 'pass_rate') !== false) {
                    $category = 'policy_rates';
                    $nameAr = 'نسبة النجاح';
                    $description = 'نسبة النجاح للسياسات';
                } elseif (strpos($key, 'notification') !== false) {
                    $category = 'policy_notifications';
                    $nameAr = 'إعدادات الإشعارات';
                    $description = 'إعدادات الإشعارات العامة';
                }
                $insertStmt->execute([$key, $value, $nameAr, $description, $category]);
            }
        }

        setFlash('تم حفظ السياسات بنجاح', 'success');
        redirect('group_policy.php');
    } catch (Exception $e) {
        error_log('Group Policy Save Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء حفظ السياسات', 'danger');
        redirect('group_policy.php');
    }
}

// جلب الإعدادات الحالية للعرض في النموذج
$currentSettings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - EQAS</title>
    <link rel="stylesheet" href="<?= EQAS_ASSETS_URL ?>/css/app.css">
    <style>
        .policy-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .policy-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .policy-section h2 {
            color: #1e293b;
            margin-top: 0;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .policy-section h2 i {
            font-size: 1.25rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        .form-input,
        .form-select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.95rem;
        }
        .form-input.color {
            width: 80px;
            padding: 0.25rem;
        }
        .color-preview {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid #ccc;
            margin-left: 0.5rem;
            vertical-align: middle;
        }
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .form-check-input {
            width: 1rem;
            height: 1rem;
        }
        .form-check-label {
            cursor: pointer;
            user-select: none;
        }
        .action-btn {
            margin-top: 2rem;
        }
        .note {
            font-size: 0.875rem;
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="min-h-screen bg-gray-50">
        <!-- Header -->
        <?php require_once __DIR__ . '/../../includes/header.php'; ?>

        <!-- Main Content -->
        <div class="container mx-auto px-4 py-8">
            <!-- Page Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
                <p class="text-gray-600">تحديد السياسات الموحدة للمجموعة التي تُطبق على جميع المدارس</p>
            </div>

            <!-- الإشعار -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <?php unset($_SESSION['flash_message']); ?>
            <?php endif; ?>

            <!-- نموذج السياسات -->
            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="policy-header">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas fa-shield-alt"></i>
                        سياسات الترميز اللوني
                    </h2>
                    <p class="text-sm text-gray-500">
                        تعريف النطاقات اللونية لمؤشرات الأداء مثل EQI ومتوسط الدرجات وغيرها.
                    </p>
                </div>

                <div class="policy-section">
                    <h2><i class="fas fa-tint"></i> الترميز اللوني لمؤشر جودة التعليم (EQI)</h2>
                    <div class="grid gap-4 md:grid-cols-3">
                        <!-- الأخضر -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">الحد الأدنى للأخضر (ممتاز)</label>
                                <input type="number" class="form-input" name="color_green_from" value="<?= e($currentSettings['policy_color_green_from'] ?? '80') ?>" min="0" max="100">
                            </div>
                            <div class="form-group">
                                <label class="form-label">الحد الأقصى للأخضر (ممتاز)</label>
                                <input type="number" class="form-input" name="color_green_to" value="<?= e($currentSettings['policy_color_green_to'] ?? '100') ?>" min="0" max="100">
                            </div>
                            <div class="mt-2">
                                <span class="color-preview" style="background-color: #10b981;"></span> الأخضر (ممتاز)
                            </div>
                        </div>
                        <!-- الأصفر -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">الحد الأدنى للأصفر (جيد)</label>
                                <input type="number" class="form-input" name="color_yellow_from" value="<?= e($currentSettings['policy_color_yellow_from'] ?? '65') ?>" min="0" max="100">
                            </div>
                            <div class="form-group">
                                <label class="form-label">الحد الأقصى للأصفر (جيد)</label>
                                <input type="number" class="form-input" name="color_yellow_to" value="<?= e($currentSettings['policy_color_yellow_to'] ?? '79') ?>" min="0" max="100">
                            </div>
                            <div class="mt-2">
                                <span class="color-preview" style="background-color: #f59e0b;"></span> الأصفر (جيد)
                            </div>
                        </div>
                        <!-- الأحمر -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">الحد الأدنى للأحمر (مقبول/ضعيف)</label>
                                <input type="number" class="form-input" name="color_red_from" value="<?= e($currentSettings['policy_color_red_from'] ?? '0') ?>" min="0" max="100">
                            </div>
                            <div class="form-group">
                                <label class="form-label">الحد الأقصى للأحمر (مقبول/ضعيف)</label>
                                <input type="number" class="form-input" name="color_red_to" value="<?= e($currentSettings['policy_color_red_to'] ?? '49') ?>" min="0" max="100">
                            </div>
                            <div class="mt-2">
                                <span class="color-preview" style="background-color: #ef4444;"></span> الأحمر (مقبول/ضعيف)
                            </div>
                        </div>
                    </div>
                    <p class="note">
                        الملاحظة: يتم تطبيق هذه النطاقات على مؤشرات مثل EQI ومتوسط الدرجات وغيرها في التقارير واللوحات.
                    </p>
                </div>

                <div class="policy-section">
                    <h2><i class="fas fa-percentage"></i> نسب النجاح والرسوب العامة</h2>
                    <div class="grid gap-4 md:grid-cols-3">
                        <!-- النجاح الأكاديمي -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">نسبة النجاح الأكاديمية (%)</label>
                                <input type="number" class="form-input" name="pass_rate_academic" value="<?= e($currentSettings['policy_pass_rate_academic'] ?? '50') ?>" min="0" max="100">
                            </div>
                            <p class="note">النسبة التي يُعتبر الطالب ناجحًا فيها في المواد الأكاديمية.</p>
                        </div>
                        <!-- النجاح السلوكي -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">نسبة النجاح السلوكية (%)</label>
                                <input type="number" class="form-input" name="pass_rate_behavior" value="<?= e($currentSettings['policy_pass_rate_behavior'] ?? '60') ?>" min="0" max="100">
                            </div>
                            <p class="note">النسبة التي يُعتبر الطالب ناجحًا فيها في السلوك.</p>
                        </div>
                        <!-- النجاح في الحضور -->
                        <div>
                            <div class="form-group">
                                <label class="form-label">نسبة النجاح في الحضور (%)</label>
                                <input type="number" class="form-input" name="pass_rate_attendance" value="<?= e($currentSettings['policy_pass_rate_attendance'] ?? '75') ?>" min="0" max="100">
                            </div>
                            <p class="note">النسبة التي يُعتبر الطالب منتظمًا فيها في الحضور.</p>
                        </div>
                    </div>
                </div>

                <div class="policy-section">
                    <h2><i class="fas fa-bell"></i> سياسات الإشعارات العامة</h2>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="notifications_enabled" name="notifications_enabled" <?= isset($currentSettings['policy_notifications_enabled']) && $currentSettings['policy_notifications_enabled'] == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notifications_enabled">تفعيل نظام الإشعارات العامة</label>
                    </div>
                    <div class="indent-list mt-3" <?= isset($currentSettings['policy_notifications_enabled']) && $currentSettings['policy_notifications_enabled'] == 1 ? '' : 'style="opacity:0.5;"' ?>>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="notification_email_enabled" name="notification_email_enabled" <?= isset($currentSettings['policy_notification_email_enabled']) && $currentSettings['policy_notification_email_enabled'] == 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notification_email_enabled">إشعارات عبر البريد الإلكتروني</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="notification_sms_enabled" name="notification_sms_enabled" <?= isset($currentSettings['policy_notification_sms_enabled']) && $currentSettings['policy_notification_sms_enabled'] == 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notification_sms_enabled">إشعارات عبر الرسائل النصية (SMS)</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="notification_app_enabled" name="notification_app_enabled" <?= isset($currentSettings['policy_notification_app_enabled']) && $currentSettings['policy_notification_app_enabled'] == 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notification_app_enabled">إشعارات عبر التطبيق</label>
                        </div>
                    </div>
                    <p class="note mt-2">
                        هذه الإعدادات تتحكم في القنوات العامة للإشعارات التي يمكن تفعيلها أو تعطيلها للمجموعة بأكملها.
                    </p>
                </div>

                <div class="mt-6 text-right">
                    <button type="submit" class="btn btn-primary action-btn">
                        <i class="fas fa-save"></i> حفظ السياسات
                    </button>
                    <a href="../../index.php" class="btn btn-light ml-2">العودة إلى لوحة القيادة</a>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    </body>
</html>