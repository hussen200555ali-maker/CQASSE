<?php
/**
 * ============================================================
 * EQAS - إعدادات الترفيع
 * نظام التقييم الشامل - Promotion Settings
 * ============================================================
 */

$pageTitle = 'إعدادات الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'الإعدادات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$settings = $promoEngine->getSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('خطأ أمني.', 'danger'); }
    else {
        $newSettings = [
            'academic_pass' => (float)($_POST['academic_pass'] ?? 50),
            'behavior_pass' => (float)($_POST['behavior_pass'] ?? 60),
            'attendance_pass' => (float)($_POST['attendance_pass'] ?? 75),
        ];
        if ($promoEngine->updateSettings($newSettings)) {
            setFlash('تم حفظ الإعدادات بنجاح.', 'success');
            $settings = $newSettings;
        } else {
            setFlash('خطأ في الحفظ.', 'danger');
        }
        redirect('settings.php');
    }
}
?>

<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">⚙️ إعدادات الترفيع</h3></div>
        <div class="card-body">
            <div class="mb-6 p-4" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-lg); border-right:4px solid var(--primary);">
                <p style="margin:0;"><strong>📏 المعايير الإلزامية للترفيع:</strong></p>
                <ul style="margin:8px 0 0 20px;">
                    <li>الدرجة الأكاديمية ≥ الحد المحدد → ترفيع/مشروط</li>
                    <li>درجة السلوك ≥ الحد المحدد → ترفيع/مشروط</li>
                    <li>نسبة الحضور ≥ الحد المحدد → ترفيع/مشروط</li>
                    <li>أخفق في معيار واحد = ترفيع مشروط (مع خطة تحسين)</li>
                    <li>أخفق في معيارين أو أكثر = إعادة</li>
                </ul>
            </div>
            
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row mb-6">
                    <div class="form-group">
                        <label class="form-label">حد النجاح الأكاديمي (%) <span class="required">*</span></label>
                        <input type="number" name="academic_pass" class="form-control" value="<?= e($settings['academic_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى للدرجة الأكاديمية للنجاح. القيمة الافتراضية: 50%</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حد السلوك المقبول (%) <span class="required">*</span></label>
                        <input type="number" name="behavior_pass" class="form-control" value="<?= e($settings['behavior_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى لدرجة السلوك. القيمة الافتراضية: 60%</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">حد الحضور المطلوب (%) <span class="required">*</span></label>
                        <input type="number" name="attendance_pass" class="form-control" value="<?= e($settings['attendance_pass']) ?>" min="0" max="100" step="1" required>
                        <span class="form-text">الحد الأدنى لنسبة الحضور. القيمة الافتراضية: 75%</span>
                    </div>
                </div>
                
                <div class="grid grid-4 gap-4 mb-6">
                    <div class="p-4 text-center" style="background:rgba(40,167,69,0.1); border-radius:var(--radius-lg); border:2px solid var(--success);">
                        <div style="font-size:20px;">🎓</div>
                        <strong style="color:var(--success);">مرفّع</strong>
                        <div style="font-size:12px; color:var(--text-secondary);">3/3 معايير</div>
                    </div>
                    <div class="p-4 text-center" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-lg); border:2px solid var(--warning);">
                        <div style="font-size:20px;">⚠️</div>
                        <strong style="color:#856404;">مشروط</strong>
                        <div style="font-size:12px; color:var(--text-secondary);">2/3 معايير فقط</div>
                    </div>
                    <div class="p-4 text-center" style="background:rgba(220,53,69,0.1); border-radius:var(--radius-lg); border:2px solid var(--danger);">
                        <div style="font-size:20px;">🔁</div>
                        <strong style="color:var(--danger);">معيد</strong>
                        <div style="font-size:12px; color:var(--text-secondary);">1/3 معايير أو أقل</div>
                    </div>
                    <div class="p-4 text-center" style="background:rgba(23,162,184,0.1); border-radius:var(--radius-lg); border:2px solid var(--info);">
                        <div style="font-size:20px;">❓</div>
                        <strong style="color:var(--info);">قيد المراجعة</strong>
                        <div style="font-size:12px; color:var(--text-secondary);">بيانات غير مكتملة</div>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
