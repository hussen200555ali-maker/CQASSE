<?php
/**
 * ============================================================
 * EQAS - إعدادات التنبيهات
 * نظام التقييم الشامل - Alert Settings
 * ============================================================
 */

$pageTitle = 'إعدادات التنبيهات';
$breadcrumb = ['التنبيهات الذكية' => 'index.php', 'الإعدادات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);
$settings = $alertEngine->getSettings();

// معالجة حفظ الإعدادات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $newSettings = [
            'academic_pass' => (float)($_POST['academic_pass'] ?? 50),
            'behavior_pass' => (float)($_POST['behavior_pass'] ?? 60),
            'attendance_pass' => (float)($_POST['attendance_pass'] ?? 75),
            'sudden_drop_percentage' => (float)($_POST['sudden_drop_percentage'] ?? 20),
            'consecutive_absence_days' => (int)($_POST['consecutive_absence_days'] ?? 3),
        ];
        
        if ($alertEngine->updateSettings($newSettings)) {
            setFlash('تم حفظ الإعدادات بنجاح.', 'success');
            $settings = $newSettings;
        } else {
            setFlash('حدث خطأ أثناء حفظ الإعدادات.', 'danger');
        }
        redirect('settings.php');
    }
}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ إعدادات التنبيهات</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                
                <!-- حدود العتبات -->
                <h4 class="mb-4" style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:8px;">📏 حدود العتبات</h4>
                
                <div class="form-row mb-6">
                    <div class="form-group">
                        <label class="form-label">حد النجاح الأكاديمي (%) <span class="required">*</span></label>
                        <input type="number" name="academic_pass" class="form-control" 
                               value="<?= e($settings['academic_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى للدرجة الأكاديمية. الطلاب أقل من هذا الحد سيحصلون على تنبيه.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حد السلوك المقبول (%) <span class="required">*</span></label>
                        <input type="number" name="behavior_pass" class="form-control" 
                               value="<?= e($settings['behavior_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى لدرجة السلوك. الطلاب أقل من هذا الحد سيحصلون على تنبيه.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حد الحضور المطلوب (%) <span class="required">*</span></label>
                        <input type="number" name="attendance_pass" class="form-control" 
                               value="<?= e($settings['attendance_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى لنسبة الحضور. الطلاب أقل من هذا الحد سيحصلون على تنبيه.</span>
                    </div>
                </div>
                
                <div class="divider mb-6"></div>
                
                <!-- إعدادات الأنماط -->
                <h4 class="mb-4" style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:8px;">🔄 إعدادات الأنماط والاتجاهات</h4>
                
                <div class="form-row mb-6">
                    <div class="form-group">
                        <label class="form-label">نسبة الانخفاض المفاجئ (%) <span class="required">*</span></label>
                        <input type="number" name="sudden_drop_percentage" class="form-control" 
                               value="<?= e($settings['sudden_drop_percentage']) ?>" min="5" max="50" step="1" required>
                        <span class="form-text">إذا انخفض أداء الطالب بهذه النسبة أو أكثر مقارنة بالفترة السابقة، يتم إنشاء تنبيه.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">أيام الغياب المتتالي <span class="required">*</span></label>
                        <input type="number" name="consecutive_absence_days" class="form-control" 
                               value="<?= e($settings['consecutive_absence_days']) ?>" min="1" max="10" step="1" required>
                        <span class="form-text">عدد أيام الغياب المتتالي الذي يعتبر نمطاً مقلقاً.</span>
                    </div>
                </div>
                
                <div class="divider mb-6"></div>
                
                <!-- خريطة الخطورة -->
                <h4 class="mb-4" style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:8px;">🎨 خريطة مستويات الخطورة</h4>
                
                <div class="grid grid-4 gap-4 mb-6">
                    <div class="p-4" style="background:#dc3545; color:#fff; border-radius:var(--radius-lg); text-align:center;">
                        <div style="font-size:20px; font-weight:bold;">🔴 حرج</div>
                        <div style="font-size:12px; margin-top:4px;">أكاديمي &lt; 25%<br>سلوك &lt; 30%<br>حضور &lt; 40%</div>
                    </div>
                    <div class="p-4" style="background:#fd7e14; color:#fff; border-radius:var(--radius-lg); text-align:center;">
                        <div style="font-size:20px; font-weight:bold;">🟠 خطر</div>
                        <div style="font-size:12px; margin-top:4px;">أكاديمي &lt; 35%<br>سلوك &lt; 45%<br>حضور &lt; 55%</div>
                    </div>
                    <div class="p-4" style="background:#ffc107; color:#000; border-radius:var(--radius-lg); text-align:center;">
                        <div style="font-size:20px; font-weight:bold;">🟡 تحذير</div>
                        <div style="font-size:12px; margin-top:4px;">أكاديمي &lt; 45%<br>سلوك &lt; 55%<br>حضور &lt; 65%</div>
                    </div>
                    <div class="p-4" style="background:#17a2b8; color:#fff; border-radius:var(--radius-lg); text-align:center;">
                        <div style="font-size:20px; font-weight:bold;">🔵 معلوماتي</div>
                        <div style="font-size:12px; margin-top:4px;">أكاديمي &lt; 50%<br>سلوك &lt; 60%<br>حضور &lt; 75%</div>
                    </div>
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">💾 حفظ الإعدادات</button>
                    <a href="settings.php" class="btn btn-light">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
