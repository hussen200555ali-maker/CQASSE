<?php
/**
 * ============================================================
 * عرض نموذج تقييم - View Assessment Form
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض نموذج التقييم';
$breadcrumb = ['التقييمات' => 'index.php', 'إدارة النماذج' => 'manage_forms.php', 'عرض النموذج' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$formId = (int)($_GET['id'] ?? 0);
if (!$formId) {
    setFlash('معرف النموذج غير صالح', 'danger');
    redirect('manage_forms.php');
}

$form = $engine->getFormWithItems($formId);
if (!$form) {
    setFlash('النموذج غير موجود', 'danger');
    redirect('manage_forms.php');
}

// إحصائيات الاستجابات
$responseStats = [];
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total, 
               COUNT(DISTINCT responded_by) as evaluators,
               MIN(response_date) as first_response,
               MAX(response_date) as last_response
        FROM assessment_responses 
        WHERE form_id = ? AND is_active = 1
    ");
    $stmt->execute([$formId]);
    $responseStats = $stmt->fetch();
} catch (Exception $e) {
    $responseStats = ['total' => 0, 'evaluators' => 0, 'first_response' => null, 'last_response' => null];
}

$totalItems = 0;
foreach ($form['categories'] as $cat) {
    $totalItems += count($cat['items']);
}
?>

<!-- تفاصيل النموذج -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:28px;"><?php echo $engine->getFormTypeIcon($form['form_key']); /* [إصلاح 2026-05-11] form_type → form_key */ ?></span>
                <div>
                    <h3 class="card-title"><?php echo e($form['name_ar']); ?></h3>
                    <?php if ($form['name_en']): ?>
                        <div style="font-size:13px; color:var(--text-muted);"><?php echo e($form['name_en']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="add_form.php?edit=<?php echo $form['id']; ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                <a href="manage_forms.php" class="btn btn-light btn-sm">↩️ رجوع</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-3 mb-4">
                <div>
                    <span style="font-size:13px; color:var(--text-muted);">نوع التقييم</span>
                    <div style="font-weight:600;"><?php echo e($engine->getFormTypeLabel($form['form_key'])); /* [إصلاح 2026-05-11] form_type → form_key */ ?></div>
                </div>
                <div>
                    <span style="font-size:13px; color:var(--text-muted);">عدد الفئات</span>
                    <div style="font-weight:600;"><?php echo count($form['categories']); ?></div>
                </div>
                <div>
                    <span style="font-size:13px; color:var(--text-muted);">عدد العناصر</span>
                    <div style="font-weight:600;"><?php echo $totalItems; ?></div>
                </div>
            </div>
            <?php if ($form['description']): ?>
                <div style="margin-top:12px;">
                    <span style="font-size:13px; color:var(--text-muted);">الوصف</span>
                    <p style="margin-top:4px;"><?php echo e($form['description']); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- إحصائيات الاستجابات -->
<div class="section">
    <div class="grid grid-4">
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:24px; font-weight:700; color:var(--primary);"><?php echo number_format($responseStats['total'] ?? 0); ?></div>
                <div style="font-size:13px; color:var(--text-secondary);">إجمالي الاستجابات</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:24px; font-weight:700; color:var(--info);"><?php echo number_format($responseStats['evaluators'] ?? 0); ?></div>
                <div style="font-size:13px; color:var(--text-secondary);">المقيّمون</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">أول استجابة</div>
                <div style="font-weight:600;"><?php echo $responseStats['first_response'] ? formatDateAr($responseStats['first_response'], 'short') : '—'; ?></div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:13px; color:var(--text-secondary);">آخر استجابة</div>
                <div style="font-weight:600;"><?php echo $responseStats['last_response'] ? formatDateAr($responseStats['last_response'], 'short') : '—'; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- فئات وعناصر النموذج -->
<div class="section">
    <h3 class="section-title">هيكل النموذج</h3>
    <?php foreach ($form['categories'] as $catIndex => $category): ?>
    <div class="card mb-4">
        <div class="card-header" style="background:var(--primary); color:white;">
            <h4 style="margin:0; color:white; font-size:15px;">
                <?php echo ($catIndex + 1) . '. ' . e($category['name_ar']); ?>
            </h4>
            <span style="font-size:12px; color:rgba(255,255,255,0.8);"><?php echo count($category['items']); ?> عنصر</span>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($category['items'])): ?>
                <div style="text-align:center; padding:20px; color:var(--text-muted);">لا توجد عناصر في هذه الفئة</div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>العنصر</th>
                            <th>نوع الأداة</th>
                            <th style="width:80px;">الوزن</th>
                            <th style="width:80px;">عكسي</th>
                            <th style="width:80px;">القيمة القصوى</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category['items'] as $itemIndex => $item): ?>
                        <tr>
                            <td><?php echo $itemIndex + 1; ?></td>
                            <td><?php echo e($item['text_ar']); ?></td>
                            <td>
                                <span class="badge" style="background:var(--background); color:var(--text-primary); padding:2px 8px; border:1px solid var(--border); border-radius:12px; font-size:11px;">
                                    <?php echo e($engine->getToolLabel($item['tool_type'])); ?>
                                </span>
                            </td>
                            <td style="text-align:center;"><?php echo number_format($item['weight'], 1); ?></td>
                            <td style="text-align:center;">
                                <?php if ($item['is_inverse']): ?>
                                    <span style="color:var(--warning);">نعم</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">لا</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;"><?php echo $item['max_value'] ? number_format($item['max_value']) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
