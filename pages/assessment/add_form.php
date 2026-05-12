<?php
/**
 * ============================================================
 * إنشاء/تعديل نموذج تقييم - Add/Edit Assessment Form
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'إنشاء نموذج تقييم';
$breadcrumb = ['التقييمات' => 'index.php', 'إدارة النماذج' => 'manage_forms.php', 'إنشاء نموذج' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

// التحقق من وضع التعديل
$editId = (int)($_GET['edit'] ?? 0);
$existingForm = null;
$existingCategories = [];

if ($editId) {
    $existingForm = $engine->getFormWithItems($editId);
    if (!$existingForm) {
        setFlash('النموذج غير موجود', 'danger');
        redirect('manage_forms.php');
    }
    $existingCategories = $existingForm['categories'] ?? [];
    $pageTitle = 'تعديل نموذج تقييم';
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $nameAr = trim($_POST['name_ar'] ?? '');
            $nameEn = trim($_POST['name_en'] ?? '');
            $formType = $_POST['form_key'] ?? '';  // [إصلاح 2026-05-11] form_type → form_key (تطابق اسم العمود)
            $description = trim($_POST['description'] ?? '');
            
            if (empty($nameAr) || empty($formType)) {
                throw new Exception('اسم النموذج ونوعه مطلوبان');
            }
            
            if ($editId) {
                // تحديث النموذج
                $stmt = $db->prepare("UPDATE assessment_forms SET name_ar = ?, name_en = ?, form_key = ?, description = ? WHERE id = ?"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
                $stmt->execute([$nameAr, $nameEn ?: null, $formType, $description ?: null, $editId]);
                $formId = $editId;
            } else {
                // إنشاء نموذج جديد
                $formId = $engine->createForm([
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn ?: null,
                    'form_key' => $formType,  // [إصلاح 2026-05-11] مفتاح المصفوفة: form_type → form_key (تطابق اسم العمود)
                    'description' => $description ?: null
                ]);
                
                if (!$formId) {
                    throw new Exception('خطأ في إنشاء النموذج');
                }
            }
            
            // حذف الفئات والعناصر القديمة في وضع التعديل
            if ($editId) {
                $db->prepare("DELETE FROM assessment_items WHERE form_id = ?")->execute([$formId]);
                $db->prepare("DELETE FROM assessment_criteria WHERE form_id = ?")->execute([$formId]);  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
            }
            
            // إضافة الفئات والعناصر
            $categories = $_POST['categories'] ?? [];
            $sortOrder = 0;
            foreach ($categories as $catData) {
                $catName = trim($catData['name_ar'] ?? '');
                if (empty($catName)) continue;
                
                $catId = $engine->createCategory([
                    'form_id' => $formId,
                    'name_ar' => $catName,
                    'name_en' => trim($catData['name_en'] ?? '') ?: null,
                    'sort_order' => $sortOrder++
                ]);
                
                if (!$catId) continue;
                
                $items = $catData['items'] ?? [];
                $itemSort = 0;
                foreach ($items as $itemData) {
                    $itemText = trim($itemData['text_ar'] ?? '');
                    if (empty($itemText)) continue;
                    
                    $engine->createItem([
                        'criteria_id' => $catId,  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
                        'form_id' => $formId,
                        'text_ar' => $itemText,
                        'text_en' => trim($itemData['text_en'] ?? '') ?: null,
                        'tool_type' => $itemData['tool_type'] ?? 'likert_5',
                        'weight' => (float)($itemData['weight'] ?? 1),
                        'is_inverse' => isset($itemData['is_inverse']) ? 1 : 0,
                        'max_value' => !empty($itemData['max_value']) ? (float)$itemData['max_value'] : null,
                        'sort_order' => $itemSort++
                    ]);
                }
            }
            
            $db->commit();
            setFlash($editId ? 'تم تحديث النموذج بنجاح' : 'تم إنشاء النموذج بنجاح', 'success');
            redirect('view_form.php?id=' . $formId);
            
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}

$toolOptions = $engine->getToolOptions();
$formTypeOptions = $engine->getFormTypeOptions();
?>

<div class="section">
    <form method="POST" data-validate>
        <?= csrfField() ?>
        
        <!-- معلومات النموذج الأساسية -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 معلومات النموذج</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">اسم النموذج بالعربية <span class="required">*</span></label>
                        <input type="text" name="name_ar" class="form-control" required
                               value="<?php echo e($existingForm['name_ar'] ?? ''); ?>"
                               placeholder="أدخل اسم النموذج بالعربية">
                    </div>
                    <div class="form-group">
                        <label class="form-label">اسم النموذج بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control"
                               value="<?php echo e($existingForm['name_en'] ?? ''); ?>"
                               placeholder="Form name in English" dir="ltr">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">نوع التقييم <span class="required">*</span></label>
                        <select name="form_key" class="form-control" required>  <!-- [إصلاح 2026-05-11] form_type → form_key -->
                            <option value="">-- اختر نوع التقييم --</option>
                            <?php foreach ($formTypeOptions as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($existingForm['form_key'] ?? '') === $key ? 'selected' : ''; /* [إصلاح 2026-05-11] form_type → form_key */ ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوصف</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="وصف مختصر للنموذج"><?php echo e($existingForm['description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- فئات وعناصر التقييم -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📂 فئات وعناصر التقييم</h3>
                <button type="button" class="btn btn-sm btn-success" onclick="addCategory()">➕ إضافة فئة</button>
            </div>
            <div class="card-body" id="categories-container">
                <?php if (!empty($existingCategories)): ?>
                    <?php foreach ($existingCategories as $catIdx => $cat): ?>
                    <div class="card mb-4 category-card" data-cat-index="<?php echo $catIdx; ?>" style="border:2px solid var(--primary);">
                        <div class="card-header" style="background:var(--primary); color:white;">
                            <div class="flex items-center gap-2 flex-1">
                                <span style="font-weight:600;">فئة:</span>
                                <input type="text" name="categories[<?php echo $catIdx; ?>][name_ar]" class="form-control" 
                                       value="<?php echo e($cat['name_ar']); ?>" required
                                       style="max-width:300px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
                                <input type="text" name="categories[<?php echo $catIdx; ?>][name_en]" class="form-control"
                                       value="<?php echo e($cat['name_en'] ?? ''); ?>"
                                       placeholder="Category name" dir="ltr"
                                       style="max-width:200px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
                            </div>
                            <button type="button" class="btn btn-sm" style="background:rgba(220,53,69,0.7); color:white;" onclick="this.closest('.category-card').remove()">🗑️</button>
                        </div>
                        <div class="card-body">
                            <?php foreach ($cat['items'] as $itemIdx => $item): ?>
                            <div class="item-row" style="padding:12px; background:var(--background); border-radius:var(--radius-md); margin-bottom:8px;">
                                <div class="form-row">
                                    <div class="form-group" style="flex:2;">
                                        <label class="form-label">نص العنصر <span class="required">*</span></label>
                                        <input type="text" name="categories[<?php echo $catIdx; ?>][items][<?php echo $itemIdx; ?>][text_ar]" 
                                               class="form-control" value="<?php echo e($item['text_ar']); ?>" required>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label class="form-label">نوع الأداة</label>
                                        <select name="categories[<?php echo $catIdx; ?>][items][<?php echo $itemIdx; ?>][tool_type]" class="form-control">
                                            <?php foreach ($toolOptions as $tKey => $tLabel): ?>
                                                <option value="<?php echo $tKey; ?>" <?php echo $item['tool_type'] === $tKey ? 'selected' : ''; ?>><?php echo e($tLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:0.5;">
                                        <label class="form-label">الوزن</label>
                                        <input type="number" name="categories[<?php echo $catIdx; ?>][items][<?php echo $itemIdx; ?>][weight]" 
                                               class="form-control" value="<?php echo $item['weight']; ?>" min="0.1" step="0.1">
                                    </div>
                                    <div class="form-group" style="flex:0.5;">
                                        <label class="form-label">القيمة القصوى</label>
                                        <input type="number" name="categories[<?php echo $catIdx; ?>][items][<?php echo $itemIdx; ?>][max_value]" 
                                               class="form-control" value="<?php echo $item['max_value'] ?? ''; ?>" min="1" placeholder="تلقائي">
                                    </div>
                                    <div class="form-group" style="flex:0; align-self:flex-end;">
                                        <label class="form-check">
                                            <input type="checkbox" name="categories[<?php echo $catIdx; ?>][items][<?php echo $itemIdx; ?>][is_inverse]" 
                                                   value="1" <?php echo $item['is_inverse'] ? 'checked' : ''; ?> class="form-check-input">
                                            <span class="form-check-label">عكسي</span>
                                        </label>
                                    </div>
                                    <div class="form-group" style="flex:0; align-self:flex-end;">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.item-row').remove()">✕</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm btn-light" onclick="addItem(this, <?php echo $catIdx; ?>)">➕ إضافة عنصر</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- فئة افتراضية فارغة -->
                    <div class="card mb-4 category-card" data-cat-index="0" style="border:2px solid var(--primary);">
                        <div class="card-header" style="background:var(--primary); color:white;">
                            <div class="flex items-center gap-2 flex-1">
                                <span style="font-weight:600;">فئة:</span>
                                <input type="text" name="categories[0][name_ar]" class="form-control" required
                                       placeholder="اسم الفئة بالعربية"
                                       style="max-width:300px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
                                <input type="text" name="categories[0][name_en]" class="form-control"
                                       placeholder="Category name" dir="ltr"
                                       style="max-width:200px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
                            </div>
                            <button type="button" class="btn btn-sm" style="background:rgba(220,53,69,0.7); color:white;" onclick="this.closest('.category-card').remove()">🗑️</button>
                        </div>
                        <div class="card-body items-container">
                            <button type="button" class="btn btn-sm btn-light" onclick="addItem(this, 0)">➕ إضافة عنصر</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- أزرار الحفظ -->
        <div class="flex justify-end gap-3">
            <a href="manage_forms.php" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ النموذج</button>
        </div>
    </form>
</div>

<script>
// عداد الفئات والعناصر
let catCounter = <?php echo !empty($existingCategories) ? count($existingCategories) : 1; ?>;
let itemCounters = {};

/**
 * إضافة فئة جديدة
 */
function addCategory() {
    const idx = catCounter++;
    const toolOptions = <?php echo json_encode($toolOptions, JSON_UNESCAPED_UNICODE); ?>;
    
    let toolOptsHtml = '';
    for (const [key, label] of Object.entries(toolOptions)) {
        toolOptsHtml += `<option value="${key}">${label}</option>`;
    }
    
    const html = `
    <div class="card mb-4 category-card" data-cat-index="${idx}" style="border:2px solid var(--primary);">
        <div class="card-header" style="background:var(--primary); color:white;">
            <div class="flex items-center gap-2 flex-1">
                <span style="font-weight:600;">فئة:</span>
                <input type="text" name="categories[${idx}][name_ar]" class="form-control" required
                       placeholder="اسم الفئة بالعربية"
                       style="max-width:300px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
                <input type="text" name="categories[${idx}][name_en]" class="form-control"
                       placeholder="Category name" dir="ltr"
                       style="max-width:200px; background:rgba(255,255,255,0.2); color:white; border-color:rgba(255,255,255,0.3);">
            </div>
            <button type="button" class="btn btn-sm" style="background:rgba(220,53,69,0.7); color:white;" onclick="this.closest('.category-card').remove()">🗑️</button>
        </div>
        <div class="card-body items-container">
            <button type="button" class="btn btn-sm btn-light" onclick="addItem(this, ${idx})">➕ إضافة عنصر</button>
        </div>
    </div>`;
    
    document.getElementById('categories-container').insertAdjacentHTML('beforeend', html);
}

/**
 * إضافة عنصر جديد لفئة
 */
function addItem(btn, catIdx) {
    if (!itemCounters[catIdx]) itemCounters[catIdx] = 0;
    const itemIdx = itemCounters[catIdx]++;
    const toolOptions = <?php echo json_encode($toolOptions, JSON_UNESCAPED_UNICODE); ?>;
    
    let toolOptsHtml = '';
    for (const [key, label] of Object.entries(toolOptions)) {
        toolOptsHtml += `<option value="${key}">${label}</option>`;
    }
    
    const html = `
    <div class="item-row" style="padding:12px; background:var(--background); border-radius:var(--radius-md); margin-bottom:8px;">
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label class="form-label">نص العنصر <span class="required">*</span></label>
                <input type="text" name="categories[${catIdx}][items][${itemIdx}][text_ar]" class="form-control" required>
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label">نوع الأداة</label>
                <select name="categories[${catIdx}][items][${itemIdx}][tool_type]" class="form-control">
                    ${toolOptsHtml}
                </select>
            </div>
            <div class="form-group" style="flex:0.5;">
                <label class="form-label">الوزن</label>
                <input type="number" name="categories[${catIdx}][items][${itemIdx}][weight]" class="form-control" value="1" min="0.1" step="0.1">
            </div>
            <div class="form-group" style="flex:0.5;">
                <label class="form-label">القيمة القصوى</label>
                <input type="number" name="categories[${catIdx}][items][${itemIdx}][max_value]" class="form-control" min="1" placeholder="تلقائي">
            </div>
            <div class="form-group" style="flex:0; align-self:flex-end;">
                <label class="form-check">
                    <input type="checkbox" name="categories[${catIdx}][items][${itemIdx}][is_inverse]" value="1" class="form-check-input">
                    <span class="form-check-label">عكسي</span>
                </label>
            </div>
            <div class="form-group" style="flex:0; align-self:flex-end;">
                <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.item-row').remove()">✕</button>
            </div>
        </div>
    </div>`;
    
    btn.insertAdjacentHTML('beforebegin', html);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
