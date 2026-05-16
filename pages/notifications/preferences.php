<?php
/**
 * ============================================================
 * EQAS - الإشعارات - التفضيلات
 * نظام التقييم الشامل - صفحة تفضيلات الإشعارات
 * ============================================================
 */

$pageTitle = 'تفضيلات الإشعارات';
$breadcrumb = ['الإشعارات' => 'index.php', 'تفضيلات الإشعارات' => null];

require_once __DIR__ . '/../../includes/header.php';

// أي مستخدم مسجل يمكنه الوصول (صفحة شخصية) - v2.0.3
requireLogin();

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role_id'] ?? 0;

// أنواع الإشعارات
$notifierTypes = [
    'alert' => ['label' => 'التنبيهات', 'icon' => '🚨', 'desc' => 'تنبيهات الدرجات والسلوك والحضور'],
    'announcement' => ['label' => 'الإعلانات', 'icon' => '📢', 'desc' => 'إعلانات وإشعارات عامة من الإدارة'],
    'reminder' => ['label' => 'التذكيرات', 'icon' => '⏰', 'desc' => 'تذكيرات بالمواعيد والمهام'],
    'system' => ['label' => 'إشعارات النظام', 'icon' => '⚙️', 'desc' => 'تحديثات النظام والصيانة'],
    'acknowledgment' => ['label' => 'الإقرارات', 'icon' => '✅', 'desc' => 'إقرارات أولياء الأمور والطلاب'],
];

// إعدادات الأدوار
$roleLabels = [
    1 => ['label' => 'مدير النظام', 'icon' => '👑'],
    2 => ['label' => 'مدير المدرسة', 'icon' => '🏫'],
    3 => ['label' => 'معلم', 'icon' => '👨‍🏫'],
    4 => ['label' => 'ولي أمر', 'icon' => '👨‍👧'],
    5 => ['label' => 'طالب', 'icon' => '🎓'],
];

// خيارات التكرار
$frequencyOptions = [
    'immediate' => ['label' => 'فوري', 'desc' => 'استلام الإشعارات مباشرة عند حدوثها', 'icon' => '⚡'],
    'daily' => ['label' => 'ملخص يومي', 'desc' => 'استلام ملخص يومي بالإشعارات', 'icon' => '📅'],
    'weekly' => ['label' => 'ملخص أسبوعي', 'desc' => 'استلام ملخص أسبوعي بالإشعارات', 'icon' => '📆'],
];

// جلب التفضيلات الحالية (is_enabled للقيم البوليانية، value_string للنصوص)
$stmt = $db->prepare("SELECT notification_type, is_enabled, value_string FROM user_preferences WHERE user_id = ?");
$stmt->execute([$userId]);
$prefRows = $stmt->fetchAll();
$prefsBool = []; // boolean values
$prefsStr = [];  // string values
foreach ($prefRows as $row) {
    $prefsBool[$row['notification_type']] = (int)$row['is_enabled'];
    if ($row['value_string'] !== null) {
        $prefsStr[$row['notification_type']] = $row['value_string'];
    }
}

// تعيين القيم الافتراضية للأنواع البوليانية
foreach ($notifierTypes as $type => $info) {
    if (!isset($prefsBool[$type])) {
        $prefsBool[$type] = 1; // مفعّل افتراضياً
    }
}

// إعداد البريد الإلكتروني والتطبيق
$emailEnabled = isset($prefsBool['email']) ? (bool)$prefsBool['email'] : true;
$inAppEnabled = isset($prefsBool['in_app']) ? (bool)$prefsBool['in_app'] : true;

// إعدادات متقدمة (تقرأ من value_string أو fallback للقيم الافتراضية)
$quietHoursFrom = $prefsStr['quiet_from'] ?? '22:00';
$quietHoursTo = $prefsStr['quiet_to'] ?? '07:00';
$dndMode = isset($prefsBool['dnd']) ? (bool)$prefsBool['dnd'] : false;
$frequency = $prefsStr['frequency'] ?? 'immediate';

// إعدادات الأدوار
$roleSettings = [];
foreach ($roleLabels as $roleId => $roleInfo) {
    $key = 'role_' . $roleId;
    $roleSettings[$roleId] = isset($prefsBool[$key]) ? (bool)$prefsBool[$key] : true;
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('preferences.php');
    }

    $saved = 0;
    foreach ($notifierTypes as $type => $info) {
        $isEnabled = isset($_POST['notif_' . $type]) ? 1 : 0;

        $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, created_at, updated_at)
                              VALUES (?, ?, ?, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE is_enabled = ?, updated_at = NOW()");
        $stmt->execute([$userId, $type, $isEnabled, $isEnabled]);
        $saved++;
    }

    // تفضيلات البريد والتطبيق
    $emailVal = isset($_POST['email_enabled']) ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, created_at, updated_at)
                          VALUES (?, 'email', ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE is_enabled = ?, updated_at = NOW()");
    $stmt->execute([$userId, $emailVal, $emailVal]);

    $inAppVal = isset($_POST['in_app_enabled']) ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, created_at, updated_at)
                          VALUES (?, 'in_app', ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE is_enabled = ?, updated_at = NOW()");
    $stmt->execute([$userId, $inAppVal, $inAppVal]);

    // ساعات الهدوء (تخزن في value_string)
    $quietFrom = $_POST['quiet_from'] ?? '22:00';
    $quietTo = $_POST['quiet_to'] ?? '07:00';
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, value_string, created_at, updated_at)
                          VALUES (?, 'quiet_from', 1, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE value_string = ?, updated_at = NOW()");
    $stmt->execute([$userId, $quietFrom, $quietFrom]);
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, value_string, created_at, updated_at)
                          VALUES (?, 'quiet_to', 1, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE value_string = ?, updated_at = NOW()");
    $stmt->execute([$userId, $quietTo, $quietTo]);

    // وضع عدم الإزعاج (قيمة بوليانية في is_enabled)
    $dndVal = isset($_POST['dnd_mode']) ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, created_at, updated_at)
                          VALUES (?, 'dnd', ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE is_enabled = ?, updated_at = NOW()");
    $stmt->execute([$userId, $dndVal, $dndVal]);

    // تكرار الإشعارات (يخزن في value_string)
    $freqVal = $_POST['frequency'] ?? 'immediate';
    if (!in_array($freqVal, ['immediate', 'daily', 'weekly'])) $freqVal = 'immediate';
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, value_string, created_at, updated_at)
                          VALUES (?, 'frequency', 1, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE value_string = ?, updated_at = NOW()");
    $stmt->execute([$userId, $freqVal, $freqVal]);

    // إعدادات الأدوار (قيم بوليانية في is_enabled)
    foreach ($roleLabels as $roleId => $roleInfo) {
        $key = 'role_' . $roleId;
        $roleVal = isset($_POST['role_' . $roleId]) ? 1 : 0;
        $stmt = $db->prepare("INSERT INTO user_preferences (user_id, notification_type, is_enabled, created_at, updated_at)
                              VALUES (?, ?, ?, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE is_enabled = ?, updated_at = NOW()");
        $stmt->execute([$userId, $key, $roleVal, $roleVal]);
    }

    logActivity($db, $userId, 'settings_change', 'تحديث تفضيلات الإشعارات', 'notification', null);
    setFlash('تم حفظ تفضيلات الإشعارات بنجاح', 'success');
    redirect('preferences.php');
}
?>

<div class="card" style="border-radius: var(--radius-md, 8px);">
    <div class="card-header">
        <h2 class="card-title">⚙️ تفضيلات الإشعارات</h2>
    </div>
    <div class="card-body">
        <form method="POST" id="prefs-form">
            <?= csrfField() ?>

            <!-- وضع عدم الإزعاج -->
            <div class="card border mb-4" style="border-radius: var(--radius-md, 8px); border-color: var(--danger, #dc3545);">
                <div class="card-body d-flex align-items-center justify-content-between" style="padding:16px 20px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span style="font-size:32px;">🔕</span>
                        <div>
                            <strong style="font-size:16px;">وضع عدم الإزعاج</strong>
                            <p class="text-muted text-sm mb-0">إيقاف جميع الإشعارات مؤقتاً. لن تستلم أي إشعار عند تفعيل هذا الوضع.</p>
                        </div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="dnd_mode" value="1" <?= $dndMode ? 'checked' : '' ?>
                               id="dnd-toggle" onchange="toggleDND(this)">
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <!-- طرق الإشعار -->
            <h4 class="mb-3">📱 طرق استلام الإشعارات</h4>
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card border" style="border-radius: var(--radius-md, 8px);">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <strong>📨 إشعارات داخل التطبيق</strong>
                                <p class="text-muted text-sm mb-0">استلام الإشعارات داخل النظام</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="in_app_enabled" value="1" <?php echo $inAppEnabled ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border" style="border-radius: var(--radius-md, 8px);">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <strong>📧 إشعارات البريد الإلكتروني</strong>
                                <p class="text-muted text-sm mb-0">استلام الإشعارات عبر البريد الإلكتروني</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="email_enabled" value="1" <?php echo $emailEnabled ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- تكرار الإشعارات -->
            <h4 class="mb-3">⏱️ تكرار الإشعارات</h4>
            <div class="row mb-4">
                <?php foreach ($frequencyOptions as $freqKey => $freqInfo): ?>
                <div class="col-md-4 mb-2">
                    <label style="cursor:pointer;width:100%;">
                        <div class="card border" style="border-radius: var(--radius-md, 8px); transition: all 0.2s; <?= $frequency === $freqKey ? 'border-color:var(--primary, #16a34a); background:var(--primary, #16a34a)08;' : '' ?>"
                             class="freq-card">
                            <div class="card-body text-center" style="padding:16px;">
                                <input type="radio" name="frequency" value="<?= $freqKey ?>"
                                       <?= $frequency === $freqKey ? 'checked' : '' ?>
                                       style="margin-bottom:8px;" onchange="highlightFreq(this)">
                                <div style="font-size:28px;"><?= $freqInfo['icon'] ?></div>
                                <strong style="display:block;margin-top:4px;"><?= e($freqInfo['label']) ?></strong>
                                <small class="text-muted"><?= e($freqInfo['desc']) ?></small>
                            </div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ساعات الهدوء -->
            <h4 class="mb-3">🌙 ساعات الهدوء</h4>
            <div class="card border mb-4" style="border-radius: var(--radius-md, 8px);">
                <div class="card-body" style="padding:20px;">
                    <p class="text-muted mb-3" style="font-size:13px;">
                        لن تستلم إشعارات خلال هذه الفترة. سيتم تأجيل الإشعارات حتى انتهاء ساعات الهدوء.
                    </p>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">🕐 من الساعة</label>
                                <input type="time" name="quiet_from" class="form-control" value="<?= e($quietHoursFrom) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">🕐 إلى الساعة</label>
                                <input type="time" name="quiet_to" class="form-control" value="<?= e($quietHoursTo) ?>">
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div style="padding:8px 16px;background:var(--bg-light, #f8f9fa);border-radius:var(--radius-md, 8px);font-size:13px;">
                                🌙 وضع الهدوء: <strong><?= e($quietHoursFrom) ?></strong> - <strong><?= e($quietHoursTo) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- أنواع الإشعارات -->
            <h4 class="mb-3">🔔 أنواع الإشعارات</h4>
            <div class="row">
                <?php foreach ($notifierTypes as $type => $info): ?>
                <div class="col-md-6 mb-3">
                    <div class="card border" style="border-radius: var(--radius-md, 8px);">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-size:28px;"><?php echo $info['icon']; ?></span>
                                <div>
                                    <strong><?php echo e($info['label']); ?></strong>
                                    <p class="text-muted text-sm mb-0"><?php echo e($info['desc']); ?></p>
                                </div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_<?php echo $type; ?>" value="1"
                                       <?php echo $preferences[$type] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- إعدادات الأدوار -->
            <h4 class="mb-3 mt-4">👥 إعدادات الإشعارات حسب الدور</h4>
            <p class="text-muted mb-3" style="font-size:13px;">
                تحكم في استلام الإشعارات الصادرة من كل دور. إيقاف دور معين يعني عدم استلام إشعارات من المستخدمين بهذا الدور.
            </p>
            <div class="row">
                <?php foreach ($roleLabels as $roleId => $roleInfo): ?>
                <div class="col-md-4 col-sm-6 mb-3">
                    <div class="card border" style="border-radius: var(--radius-md, 8px);">
                        <div class="card-body d-flex align-items-center justify-content-between" style="padding:12px 16px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:22px;"><?= $roleInfo['icon'] ?></span>
                                <strong style="font-size:14px;"><?= e($roleInfo['label']) ?></strong>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="role_<?= $roleId ?>" value="1"
                                       <?= $roleSettings[$roleId] ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4" style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-lg">
                    💾 حفظ التفضيلات
                </button>
                <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<style>
.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 26px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 26px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    right: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: var(--primary, #16a34a);
}
input:checked + .slider:before {
    transform: translateX(-24px);
}

/* تأثير وضع عدم الإزعاج */
.dnd-active {
    opacity: 0.5;
    pointer-events: none;
    position: relative;
}
.dnd-active::after {
    content: '🔕 وضع عدم الإزعاج مفعّل';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 16px;
    font-weight: bold;
    color: var(--danger, #dc3545);
    background: white;
    padding: 8px 16px;
    border-radius: var(--radius-md, 8px);
    border: 2px solid var(--danger, #dc3545);
}
</style>

<script>
// تبديل وضع عدم الإزعاج
function toggleDND(checkbox) {
    const sections = ['prefs-form'];
    const dndSections = document.querySelectorAll('.row.mb-4, .row.mb-3, h4.mb-3');

    if (checkbox.checked) {
        if (confirm('سيتم إيقاف جميع الإشعارات عند تفعيل وضع عدم الإزعاج. هل أنت متأكد؟')) {
            dndSections.forEach(el => {
                if (!el.closest('.card.border.mb-4[style*="danger"]')) {
                    el.classList.add('dnd-active');
                }
            });
        } else {
            checkbox.checked = false;
        }
    } else {
        dndSections.forEach(el => {
            el.classList.remove('dnd-active');
        });
    }
}

// تمييز خيار التكرار
function highlightFreq(radio) {
    document.querySelectorAll('.freq-card').forEach(card => {
        card.style.borderColor = 'var(--border-light, #dee2e6)';
        card.style.background = '';
    });
    radio.closest('.card').style.borderColor = 'var(--primary, #16a34a)';
    radio.closest('.card').style.background = 'var(--primary, #16a34a)08';
}

// تهيئة حالة عدم الإزعاج
document.addEventListener('DOMContentLoaded', function() {
    const dndToggle = document.getElementById('dnd-toggle');
    if (dndToggle && dndToggle.checked) {
        const dndSections = document.querySelectorAll('.row.mb-4, .row.mb-3, h4.mb-3');
        dndSections.forEach(el => {
            if (!el.closest('.card.border.mb-4[style*="danger"]')) {
                el.classList.add('dnd-active');
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
