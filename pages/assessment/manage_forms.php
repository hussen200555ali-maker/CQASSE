<?php
/**
 * ============================================================
 * إدارة نماذج التقييم - Form Management
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'إدارة نماذج التقييم';
$breadcrumb = ['التقييمات' => 'index.php', 'إدارة النماذج' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);
$formTypeOptions = $engine->getFormTypeOptions();

// فلتر نوع النموذج
$filterType = $_GET['form_key'] ?? '';

// جلب النماذج
$allForms = $engine->getForms($filterType ?: null);

// معالجة التعيين كافتراضي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $formId = (int)($_POST['form_id'] ?? 0);
        try {
            if ($_POST['action'] === 'set_default') {
                // إزالة الافتراضية عن كل النماذج من نفس النوع
                $stmt = $db->prepare("SELECT form_key FROM assessment_forms WHERE id = ?");
                $stmt->execute([$formId]);
                $key = $stmt->fetchColumn();
                if ($key) {
                    $db->prepare("UPDATE assessment_forms SET is_default = 0 WHERE form_key = ?")->execute([$key]);
                    $db->prepare("UPDATE assessment_forms SET is_default = 1 WHERE id = ?")->execute([$formId]);
                    setFlash('تم تعيين النموذج كافتراضي', 'success');
                }
            } elseif ($_POST['action'] === 'delete') {
                $db->prepare("UPDATE assessment_forms SET is_active = 0 WHERE id = ?")->execute([$formId]);
                setFlash('تم حذف النموذج بنجاح', 'success');
            }
            redirect('manage_forms.php' . ($filterType ? '?form_key=' . urlencode($filterType) : ''));
        } catch (Exception $e) {
            setFlash('خطأ في تنفيذ العملية', 'danger');
        }
    }
}

// تجميع النماذج حسب النوع
$grouped = [];
foreach ($allForms as $f) {
    $grouped[$f['form_key']][] = $f;
}
?>

<div class="section">
    <div class="flex justify-between items-center mb-4">
        <h3 class="section-title" style="margin-bottom:0;">نماذج التقييم</h3>
        <a href="add_form.php" class="btn btn-primary">➕ إنشاء نموذج جديد</a>
    </div>

    <!-- فلاتر -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="form-inline">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="margin-bottom:0; margin-left:8px;">نوع النموذج:</label>
                    <select name="form_key" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="">كل الأنواع</option>
                        <?php foreach ($formTypeOptions as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة النماذج مجمعة حسب النوع -->
    <?php if (empty($allForms)): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:60px 20px;">
                <div style="font-size:48px;margin-bottom:16px;">📋</div>
                <div style="font-size:18px;font-weight:600;margin-bottom:8px;">لا توجد نماذج تقييم</div>
                <p style="color:var(--text-muted);">يمكنك إنشاء نموذج جديد من الزر أعلاه</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $formKey => $forms): ?>
        <div class="card mb-4">
            <div class="card-header" style="background:var(--bg-light, #f8f9fa); border-bottom:2px solid var(--primary);">
                <div class="flex items-center gap-2">
                    <span style="font-size:20px;"><?php echo $engine->getFormTypeIcon($formKey); ?></span>
                    <h4 style="margin:0;font-size:16px;"><?php echo e($formTypeOptions[$formKey] ?? $formKey); ?></h4>
                    <span class="badge" style="background:var(--primary);color:white;font-size:11px;"><?php echo count($forms); ?> نموذج</span>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم النموذج</th>
                            <th>الإصدار</th>
                            <th>عدد الفئات</th>
                            <th>عدد العناصر</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $i => $form): ?>
                        <tr style="<?php echo $form['is_default'] ? 'background:var(--bg-success, #f0fff4);' : ''; ?>">
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <a href="view_form.php?id=<?php echo $form['id']; ?>" style="font-weight:600;">
                                    <?php echo e($form['name_ar']); ?>
                                </a>
                                <?php if ($form['name_en']): ?>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo e($form['name_en']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $form['version'] ? e($form['version']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                            <td><?php echo number_format($form['category_count']); ?></td>
                            <td><?php echo number_format($form['item_count']); ?></td>
                            <td>
                                <?php if ($form['is_default']): ?>
                                    <span class="badge" style="background:#28a745;color:white;font-size:11px;">⭐ افتراضي</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--bg-light,#e9ecef);color:var(--text-muted);font-size:11px;">عادي</span>
                                <?php endif; ?>
                                <?php if ($form['is_system']): ?>
                                    <span class="badge" style="background:#6c757d;color:white;font-size:10px;">نظام</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions" style="white-space:nowrap;">
                                <a href="view_form.php?id=<?php echo $form['id']; ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                <a href="add_form.php?edit=<?php echo $form['id']; ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                <?php if (!$form['is_default']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('تعيين هذا النموذج كافتراضي؟');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_default">
                                    <input type="hidden" name="form_id" value="<?php echo $form['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="تعيين كافتراضي">⭐</button>
                                </form>
                                <?php endif; ?>
                                <?php if (!$form['is_system']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا النموذج؟');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="form_id" value="<?php echo $form['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="حذف">🗑️</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
