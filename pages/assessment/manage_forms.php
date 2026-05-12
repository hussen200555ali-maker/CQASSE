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

// التحقق من الصلاحيات - فقط مدير النظام أو مدير المدرسة
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

// فلتر نوع النموذج
$filterType = $_GET['form_key'] ?? ''; // [إصلاح 2026-05-11] form_type → form_key

// جلب النماذج
$forms = $engine->getForms($filterType ?: null);

// معالجة الحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $deleteId = (int)($_POST['form_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE assessment_forms SET is_active = 0 WHERE id = ?");
            $stmt->execute([$deleteId]);
            setFlash('تم حذف النموذج بنجاح', 'success');
            redirect('manage_forms.php');
        } catch (Exception $e) {
            setFlash('خطأ في حذف النموذج', 'danger');
        }
    }
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
                    <select name="form_key" class="form-control" style="width:auto;" onchange="this.form.submit()"> <!-- [إصلاح 2026-05-11] form_type → form_key -->
                        <option value="">الكل</option>
                        <?php foreach ($engine->getFormTypeOptions() as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة النماذج -->
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($forms)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">📋</div>
                    <div class="table-empty-text">لا توجد نماذج تقييم</div>
                    <p style="color:var(--text-muted); font-size:14px;">يمكنك إنشاء نموذج جديد من الزر أعلاه</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم النموذج</th>
                                <th>نوع التقييم</th>
                                <th>عدد الفئات</th>
                                <th>عدد العناصر</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $i => $form): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <a href="view_form.php?id=<?php echo $form['id']; ?>" style="font-weight:600;">
                                        <?php echo e($form['name_ar']); ?>
                                    </a>
                                    <?php if ($form['name_en']): ?>
                                        <div style="font-size:12px; color:var(--text-muted);"><?php echo e($form['name_en']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background:<?php echo $engine->getFormTypeIcon($form['form_key']) === '🎯' ? '#e63946' : 'var(--primary)'; /* [إصلاح 2026-05-11] form_type → form_key */ ?>; color:white; padding:3px 8px; border-radius:12px; font-size:11px;">
                                        <?php echo $engine->getFormTypeIcon($form['form_key']) . ' ' . e($engine->getFormTypeLabel($form['form_key'])); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema) ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($form['category_count']); ?></td>
                                <td><?php echo number_format($form['item_count']); ?></td>
                                <td class="actions">
                                    <a href="view_form.php?id=<?php echo $form['id']; ?>" class="btn btn-sm btn-info" title="عرض">👁️</a>
                                    <a href="add_form.php?edit=<?php echo $form['id']; ?>" class="btn btn-sm btn-warning" title="تعديل">✏️</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا النموذج؟');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="form_id" value="<?php echo $form['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="حذف">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
