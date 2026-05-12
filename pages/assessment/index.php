<?php
/**
 * ============================================================
 * لوحة التقييمات - Assessment Dashboard
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'لوحة التقييمات';
$breadcrumb = ['التقييمات' => '', 'لوحة التقييمات' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// تحميل محرك التقييم
require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

// جلب الإحصائيات
$stats = $engine->getAssessmentStats();

// أنواع النماذج السبعة
$formTypes = [
    'behavior_eval' => ['label' => 'التقييم السلوكي', 'icon' => '🎯', 'color' => '#e63946', 'url' => '../behavior/index.php', 'desc' => 'تقييم السلوك الطلابي وفق معايير محددة'],
    'skills_checklist' => ['label' => 'قائمة المهارات', 'icon' => '✅', 'color' => '#28a745', 'url' => '../skills/index.php', 'desc' => 'تقييم المهارات الأساسية والتحصيلية'],
    'teaching_self' => ['label' => 'التقييم الذاتي للتدريس', 'icon' => '🪞', 'color' => '#17a2b8', 'url' => '../teaching/self_index.php', 'desc' => 'التقييم الذاتي لأداء المعلم'],
    'teaching_peer' => ['label' => 'تقييم الأقران', 'icon' => '👥', 'color' => '#6f42c1', 'url' => '../teaching/peer_index.php', 'desc' => 'تقييم المعلمين لبعضهم البعض'],
    'teaching_admin' => ['label' => 'التقييم الإشرافي', 'icon' => '👔', 'color' => '#1a5632', 'url' => '../teaching/admin_index.php', 'desc' => 'تقييم الإدارة لأداء المعلمين'],
    'climate_survey' => ['label' => 'استبيان المناخ المدرسي', 'icon' => '🌡️', 'color' => '#fd7e14', 'url' => '../climate/index.php', 'desc' => 'قياس مناخ المدرسة والبيئة التعليمية'],
    'facility_checklist' => ['label' => 'فحص المرافق المدرسية', 'icon' => '🏫', 'color' => '#20c997', 'url' => '../facility/index.php', 'desc' => 'فحص جودة المرافق والبنية التحتية']
];

// جلب آخر النشاطات
$recentActivity = [];
try {
    $stmt = $db->query("
        SELECT ar.response_date, af.name_ar as form_name, af.form_key,
               COUNT(*) as response_count
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE ar.is_active = 1
        GROUP BY ar.form_id, DATE(ar.response_date)
        ORDER BY ar.response_date DESC
        LIMIT 10
    "); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
    $recentActivity = $stmt->fetchAll();
} catch (Exception $e) {
    $recentActivity = [];
}
?>

<!-- إحصائيات عامة -->
<div class="section">
    <div class="grid grid-4">
        <!-- إجمالي النماذج -->
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:36px; margin-bottom:8px;">📋</div>
                <div style="font-size:28px; font-weight:700; color:var(--primary);"><?php echo number_format($stats['total_forms']); ?></div>
                <div style="font-size:14px; color:var(--text-secondary);">نماذج التقييم</div>
            </div>
        </div>
        <!-- إجمالي الاستجابات -->
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:36px; margin-bottom:8px;">📝</div>
                <div style="font-size:28px; font-weight:700; color:var(--info);"><?php echo number_format($stats['total_responses']); ?></div>
                <div style="font-size:14px; color:var(--text-secondary);">استجابة مسجلة</div>
            </div>
        </div>
        <!-- أنواع التقييم -->
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:36px; margin-bottom:8px;">📊</div>
                <div style="font-size:28px; font-weight:700; color:var(--success);">7</div>
                <div style="font-size:14px; color:var(--text-secondary);">أنواع التقييم</div>
            </div>
        </div>
        <!-- أدوات القياس -->
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:36px; margin-bottom:8px;">🔧</div>
                <div style="font-size:28px; font-weight:700; color:var(--secondary);">10</div>
                <div style="font-size:14px; color:var(--text-secondary);">أدوات قياس</div>
            </div>
        </div>
    </div>
</div>

<!-- بطاقات أنواع التقييم -->
<div class="section">
    <h3 class="section-title">أنواع التقييمات</h3>
    <div class="grid grid-auto-lg">
        <?php foreach ($formTypes as $typeKey => $type): 
            $typeStats = $stats['by_type'][$typeKey] ?? ['form_count' => 0, 'response_count' => 0];
        ?>
        <a href="<?php echo $type['url']; ?>" class="card" style="text-decoration:none; border-top:4px solid <?php echo $type['color']; ?>;">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-3">
                    <span style="font-size:32px;"><?php echo $type['icon']; ?></span>
                    <div>
                        <h4 style="margin:0; font-size:16px; color:var(--text-primary);"><?php echo $type['label']; ?></h4>
                    </div>
                </div>
                <p style="font-size:13px; color:var(--text-secondary); margin-bottom:12px;"><?php echo $type['desc']; ?></p>
                <div class="flex justify-between" style="font-size:12px; color:var(--text-muted);">
                    <span>النماذج: <?php echo $typeStats['form_count']; ?></span>
                    <span>الاستجابات: <?php echo $typeStats['response_count']; ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- آخر النشاطات -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">آخر نشاطات التقييم</h3>
            <a href="manage_forms.php" class="btn btn-sm btn-light">إدارة النماذج</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($recentActivity)): ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px; opacity:0.3;">📭</div>
                    <p>لا توجد نشاطات تقييم حتى الآن</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>النموذج</th>
                                <th>نوع التقييم</th>
                                <th>عدد الاستجابات</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActivity as $activity): ?>
                            <tr>
                                <td><?php echo e($activity['form_name']); ?></td>
                                <td><?php echo e($engine->getFormTypeLabel($activity['form_key'])); /* [إصلاح 2026-05-11] form_type → form_key */ ?></td>
                                <td><?php echo number_format($activity['response_count']); ?></td>
                                <td><?php echo formatDateAr($activity['response_date'], 'medium'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- الوصول السريع -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">أدوات التقييم المتاحة</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto-sm">
                <?php foreach ($engine->getToolOptions() as $toolKey => $toolLabel): ?>
                <div style="padding:8px 12px; background:var(--background); border-radius:var(--radius-md); font-size:13px;">
                    <span style="font-weight:600;"><?php echo e($toolLabel); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
