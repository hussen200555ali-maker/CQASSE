<?php
/**
 * ============================================================
 * إعدادات الحضور
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * إعدادات الحضور لكل مدرسة: الحد الأدنى، التأخير، التنبيهات
 */

$pageTitle = 'إعدادات الحضور';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'الإعدادات' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المدرسة ومدير النظام
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية لتعديل إعدادات الحضور', 'danger');
    redirect('index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

if (!$schoolId && $userRole !== 'group_admin') {
    setFlash('لم يتم تعيين مدرسة لحسابك', 'danger');
    redirect('index.php');
}

// ============================================================
// معالجة حفظ الإعدادات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $targetSchool = (int)($_POST['school_id'] ?? $schoolId);
        $minRate      = (float)($_POST['min_attendance_rate'] ?? 75);
        $lateAsAbsent = (int)($_POST['late_count_as_absent'] ?? 0);
        $consecutiveAlert = (int)($_POST['consecutive_absence_alert'] ?? 3);

        // التحقق من القيم
        if ($minRate < 50 || $minRate > 100) {
            setFlash('الحد الأدنى لنسبة الحضور يجب أن يكون بين 50% و 100%', 'danger');
        } elseif ($consecutiveAlert < 1 || $consecutiveAlert > 30) {
            setFlash('عدد أيام الغياب المتتالي للتنبيه يجب أن يكون بين 1 و 30', 'danger');
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO attendance_settings 
                        (school_id, min_attendance_rate, late_count_as_absent, consecutive_absence_alert, is_active)
                    VALUES (?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        min_attendance_rate = VALUES(min_attendance_rate),
                        late_count_as_absent = VALUES(late_count_as_absent),
                        consecutive_absence_alert = VALUES(consecutive_absence_alert)
                ");
                $stmt->execute([$targetSchool, $minRate, $lateAsAbsent, $consecutiveAlert]);

                logActivity($db, $userId, 'attendance_settings_update', 'تحديث إعدادات الحضور', 'schools', $targetSchool);
                setFlash('تم حفظ إعدادات الحضور بنجاح', 'success');
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء حفظ الإعدادات: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// ============================================================
// جلب الإعدادات الحالية
// ============================================================
$currentSettings = [
    'min_attendance_rate'     => 75,
    'late_count_as_absent'    => 0,
    'consecutive_absence_alert' => 3
];

if ($schoolId) {
    try {
        $stmt = $db->prepare("SELECT * FROM attendance_settings WHERE school_id = ? AND is_active = 1");
        $stmt->execute([$schoolId]);
        $saved = $stmt->fetch();
        if ($saved) {
            $currentSettings = $saved;
        }
    } catch (Exception $e) {}
}

// جلب المدارس لمدير النظام
$schools = [];
if ($userRole === 'group_admin') {
    try {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
        $schools = $stmt->fetchAll();
    } catch (Exception $e) {}
}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ إعدادات الحضور</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>

                <?php if ($userRole === 'group_admin' && !empty($schools)): ?>
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label">المدرسة:</label>
                    <select name="school_id" class="form-control" style="max-width:400px;" onchange="this.form.submit()">
                        <?php foreach ($schools as $sch): ?>
                        <option value="<?= $sch['id'] ?>" <?= $schoolId == $sch['id'] ? 'selected' : '' ?>>
                            <?= e($sch['name_ar']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                        اختر المدرسة لتعديل إعداداتها
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="school_id" value="<?= e($schoolId) ?>">
                <?php endif; ?>

                <!-- الحد الأدنى لنسبة الحضور -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" for="min_attendance_rate">
                        📊 الحد الأدنى لنسبة الحضور (%)
                    </label>
                    <input type="number" id="min_attendance_rate" name="min_attendance_rate"
                           class="form-control" style="max-width:200px;"
                           min="50" max="100" step="0.5"
                           value="<?= e($currentSettings['min_attendance_rate']) ?>">
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                        النسبة المئوية الدنيا المطلوبة للحضور (الافتراضي: 75%). الطلاب الذين تقل نسبتهم عن هذا الحد يُعتبرون تحت الخطر.
                    </div>
                </div>

                <!-- التأخير يُحسب كغياب -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="late_count_as_absent" value="1"
                               <?= $currentSettings['late_count_as_absent'] ? 'checked' : '' ?>
                               style="width:20px; height:20px;">
                        <span>⏰ احتساب التأخير كغياب</span>
                    </label>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px; margin-right:28px;">
                        عند تفعيل هذا الخيار، يُحسب كل يوم تأخير كيوم غياب في نسبة الحضور
                    </div>
                </div>

                <!-- عدد أيام الغياب المتتالي للتنبيه -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label class="form-label" for="consecutive_absence_alert">
                        🔔 عدد أيام الغياب المتتالي للتنبيه
                    </label>
                    <input type="number" id="consecutive_absence_alert" name="consecutive_absence_alert"
                           class="form-control" style="max-width:200px;"
                           min="1" max="30"
                           value="<?= e($currentSettings['consecutive_absence_alert']) ?>">
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                        عدد أيام الغياب المتتالية التي يبعث بعدها النظام إشعاراً تلقائياً لولي الأمر (الافتراضي: 3 أيام)
                    </div>
                </div>

                <!-- معاينة الإعدادات -->
                <div style="background:var(--bg-secondary); padding:16px; border-radius:var(--radius-md); margin-bottom:20px;">
                    <h4 style="margin-bottom:12px; font-size:14px;">📋 معاينة الإعدادات</h4>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                        <div style="padding:8px; background:white; border-radius:var(--radius-sm); text-align:center;">
                            <div style="font-size:24px; font-weight:700; color:var(--primary);"
                                 id="preview-rate"><?= e($currentSettings['min_attendance_rate']) ?>%</div>
                            <div style="font-size:12px; color:var(--text-muted);">الحد الأدنى للحضور</div>
                        </div>
                        <div style="padding:8px; background:white; border-radius:var(--radius-sm); text-align:center;">
                            <div style="font-size:24px; font-weight:700;"
                                 id="preview-late" style="color:<?= $currentSettings['late_count_as_absent'] ? 'var(--danger)' : 'var(--success)' ?>">
                                <?= $currentSettings['late_count_as_absent'] ? 'نعم' : 'لا' ?>
                            </div>
                            <div style="font-size:12px; color:var(--text-muted);">التأخير = غياب</div>
                        </div>
                        <div style="padding:8px; background:white; border-radius:var(--radius-sm); text-align:center;">
                            <div style="font-size:24px; font-weight:700; color:var(--warning);"
                                 id="preview-consecutive"><?= e($currentSettings['consecutive_absence_alert']) ?></div>
                            <div style="font-size:12px; color:var(--text-muted);">أيام للتنبيه</div>
                        </div>
                    </div>
                </div>

                <div style="display:flex; gap:8px;">
                    <button type="submit" name="save_settings" class="btn btn-primary btn-lg">
                        💾 حفظ الإعدادات
                    </button>
                    <a href="index.php" class="btn btn-light">رجوع</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageJs = <<<'JS'
// تحديث المعاينة في الوقت الفعلي
const rateInput = document.getElementById('min_attendance_rate');
const lateInput = document.querySelector('input[name="late_count_as_absent"]');
const consecutiveInput = document.getElementById('consecutive_absence_alert');

if (rateInput) rateInput.addEventListener('input', function() {
    document.getElementById('preview-rate').textContent = this.value + '%';
});
if (lateInput) lateInput.addEventListener('change', function() {
    const el = document.getElementById('preview-late');
    el.textContent = this.checked ? 'نعم' : 'لا';
    el.style.color = this.checked ? 'var(--danger)' : 'var(--success)';
});
if (consecutiveInput) consecutiveInput.addEventListener('input', function() {
    document.getElementById('preview-consecutive').textContent = this.value;
});
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
