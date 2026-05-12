<?php
/**
 * ============================================================
 * EQAS - الملاحظات - إضافة ملاحظة
 * نظام التقييم الشامل - صفحة إضافة ملاحظة جديدة
 * ============================================================
 */

$pageTitle = 'إضافة ملاحظة';
$breadcrumb = ['الملاحظات' => 'index.php', 'إضافة ملاحظة' => null];

require_once __DIR__ . '/../../includes/header.php';

// [إصلاح v2.0.3] فحص الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$userId = $_SESSION['user']['id'];

// جلب أنواع الكيانات والبيانات
$entityTypes = [
    'student' => 'طالب',
    'class' => 'فصل',
    'teacher' => 'معلم',
    'school' => 'مدرسة',
];

$priorityOptions = [
    'low' => ['label' => 'منخفضة', 'icon' => '🟢'],
    'medium' => ['label' => 'متوسطة', 'icon' => '🔵'],
    'high' => ['label' => 'عالية', 'icon' => '🟠'],
    'urgent' => ['label' => 'عاجلة', 'icon' => '🔴'],
];

// جلب الطلاب
$students = [];
$schoolId = $_SESSION['user']['school_id'] ?? 0;
if ($schoolId) {
    $stmt = $db->prepare("SELECT id, full_name_ar FROM students WHERE school_id = ? AND is_active = 1 ORDER BY full_name_ar LIMIT 200");
    $stmt->execute([$schoolId]);
} else {
    $stmt = $db->query("SELECT id, full_name_ar FROM students WHERE is_active = 1 ORDER BY full_name_ar LIMIT 500");
}
$students = $stmt->fetchAll();

// جلب الفصول
$classes = [];
if ($schoolId) {
    $stmt = $db->prepare("SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.school_id = ? AND c.is_active = 1 ORDER BY gl.level_number");
    $stmt->execute([$schoolId]);
} else {
    $stmt = $db->query("SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.is_active = 1 ORDER BY gl.level_number LIMIT 200");
}
$classes = $stmt->fetchAll();

// جلب المعلمين
$teachers = [];
if ($schoolId) {
    $stmt = $db->prepare("SELECT u.id, u.full_name_ar FROM users u WHERE u.school_id = ? AND u.role_id = 3 AND u.is_active = 1 ORDER BY u.full_name_ar");
    $stmt->execute([$schoolId]);
} else {
    $stmt = $db->query("SELECT u.id, u.full_name_ar FROM users u WHERE u.role_id = 3 AND u.is_active = 1 ORDER BY u.full_name_ar LIMIT 200");
}
$teachers = $stmt->fetchAll();

// جلب المدارس
$schools = [];
$stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
$schools = $stmt->fetchAll();

// جلب المرفقات المرجعية المتاحة
$attachments = [];
$stmt = $db->prepare("SELECT id, file_name, file_type FROM attachments WHERE school_id = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$schoolId ?: 0]);
$attachments = $stmt->fetchAll();

// جلب المسودات المحفوظة
$drafts = [];
$stmt = $db->prepare("SELECT id, title_ar, content_ar, priority, updated_at FROM notes WHERE user_id = ? AND is_active = 0 AND is_draft = 1 ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$drafts = $stmt->fetchAll();

// معالجة حفظ المسودة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('add.php');
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $priority = $_POST['priority'] ?? 'medium';

    if (empty($title)) $title = 'مسودة بدون عنوان';

    $stmt = $db->prepare("INSERT INTO notes (user_id, related_entity_type, related_entity_id, title_ar, content_ar, priority, is_active, is_draft, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW())");
    $stmt->execute([$userId, $entityType ?: 'general', $entityId ?: 0, $title, $content, $priority]);

    $draftId = $db->lastInsertId();
    logActivity($db, $userId, 'note_draft', 'حفظ مسودة: ' . $title, 'note', $draftId);
    setFlash('تم حفظ المسودة بنجاح', 'info');
    redirect('add.php');
}

// معالجة الإضافة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('add.php');
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $priority = $_POST['priority'] ?? 'medium';
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    // التحقق من المدخلات
    $errors = [];
    if (empty($title)) $errors[] = 'عنوان الملاحظة مطلوب';
    if (empty($content)) $errors[] = 'محتوى الملاحظة مطلوب';
    if (mb_strlen($content) > 5000) $errors[] = 'محتوى الملاحظة يتجاوز الحد الأقصى (5000 حرف)';
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) $priority = 'medium';
    if (!in_array($entityType, array_keys($entityTypes))) {
        $entityType = '';
        $entityId = 0;
    }

    if (empty($errors)) {
        $stmt = $db->prepare("INSERT INTO notes (user_id, related_entity_type, related_entity_id, title_ar, content_ar, priority, is_active, is_draft, attachment_id, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, NOW(), NOW())");
        $stmt->execute([$userId, $entityType ?: 'general', $entityId ?: 0, $title, $content, $priority, $attachmentId ?: null]);

        $noteId = $db->lastInsertId();
        logActivity($db, $userId, 'note_create', 'إضافة ملاحظة: ' . $title, 'note', $noteId);
        setFlash('تم إضافة الملاحظة بنجاح', 'success');
        redirect('index.php');
    } else {
        foreach ($errors as $error) {
            setFlash($error, 'danger');
        }
    }
}
?>

<!-- المسودات المحفوظة -->
<?php if (!empty($drafts)): ?>
<div class="card mb-3" style="border-radius: var(--radius-md, 8px);">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title" style="font-size:16px;">📂 المسودات المحفوظة (<?= count($drafts) ?>)</h3>
        <button type="button" class="btn btn-sm btn-link" onclick="document.getElementById('drafts-list').style.display = document.getElementById('drafts-list').style.display === 'none' ? 'block' : 'none';">
            عرض/إخفاء
        </button>
    </div>
    <div class="card-body" id="drafts-list" style="display:none; padding:8px 16px;">
        <div class="list-group">
            <?php foreach ($drafts as $draft): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center" style="padding:8px 12px;">
                <div>
                    <strong style="font-size:14px;"><?= e($draft['title_ar']) ?></strong>
                    <small class="text-muted mr-2">· <?= formatDateAr($draft['updated_at'], 'short') ?></small>
                    <?php if ($draft['priority'] !== 'medium'): ?>
                    <span class="badge badge-<?= $priorityOptions[$draft['priority']]['color'] ?? 'secondary' ?>" style="font-size:10px;">
                        <?= $priorityOptions[$draft['priority']]['icon'] ?? '' ?> <?= $priorityOptions[$draft['priority']]['label'] ?? '' ?>
                    </span>
                    <?php endif; ?>
                </div>
                <form method="POST" onsubmit="return confirm('تحميل هذه المسودة؟ سيتم استبدال المحتوى الحالي.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="load_draft_id" value="<?= $draft['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary" title="تحميل المسودة">📋 تحميل</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- نموذج الإضافة -->
    <div class="col-lg-8">
        <div class="card" style="border-radius: var(--radius-md, 8px);">
            <div class="card-header">
                <h2 class="card-title">➕ إضافة ملاحظة جديدة</h2>
            </div>
            <div class="card-body">
                <form method="POST" id="note-form">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label">عنوان الملاحظة <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" id="note-title" required
                               value="<?php echo e($_POST['title'] ?? ''); ?>"
                               placeholder="أدخل عنوان الملاحظة" maxlength="255"
                               oninput="updatePreview(); updateCharCount('title-count', this.value, 255);">
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">الحد الأقصى 255 حرف</small>
                            <small id="title-count" class="text-muted">0/255</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">محتوى الملاحظة <span class="text-danger">*</span></label>

                        <!-- شريط أدوات التنسيق -->
                        <div class="btn-toolbar mb-2" style="border:1px solid var(--border-light, #dee2e6); border-radius: var(--radius-md, 8px) var(--radius-md, 8px) 0 0; padding:6px 8px; background:var(--bg-light, #f8f9fa);">
                            <div class="btn-group btn-group-sm" style="gap:2px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('bold')" title="عريض">
                                    <strong>B</strong>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('italic')" title="مائل">
                                    <em>I</em>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('underline')" title="تسطير">
                                    <u>U</u>
                                </button>
                            </div>
                            <div class="btn-group btn-group-sm mr-2" style="gap:2px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('ul')" title="قائمة نقطية">
                                    •≡
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('ol')" title="قائمة مرقمة">
                                    1≡
                                </button>
                            </div>
                            <div class="btn-group btn-group-sm mr-2" style="gap:2px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('heading')" title="عنوان فرعي">
                                    H
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('quote')" title="اقتباس">
                                    "
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="insertFormat('divider')" title="خط فاصل">
                                    ─
                                </button>
                            </div>
                        </div>

                        <textarea name="content" class="form-control" rows="8" required id="note-content"
                                  placeholder="اكتب محتوى الملاحظة هنا..."
                                  oninput="updatePreview(); updateCharCount('content-count', this.value, 5000);"
                                  style="border-radius: 0 0 var(--radius-md, 8px) var(--radius-md, 8px);"><?php echo e($_POST['content'] ?? ''); ?></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">يمكنك استخدام التنسيق البسيط</small>
                            <small id="content-count" class="text-muted">0/5000</small>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">الأولوية</label>
                            <select name="priority" class="form-control" id="note-priority" onchange="updatePreview();">
                                <?php foreach ($priorityOptions as $key => $info): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($_POST['priority'] ?? 'medium') === $key ? 'selected' : ''; ?>>
                                    <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">الكيان المرتبط</label>
                            <select name="entity_type" class="form-control" id="entity-type-select">
                                <option value="">بدون ارتباط</option>
                                <?php foreach ($entityTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($_POST['entity_type'] ?? '') === $key ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" id="entity-id-group" style="display:none;">
                            <label class="form-label">اختر <span id="entity-label"></span></label>
                            <select name="entity_id" class="form-control" id="entity-id-select">
                                <option value="">-- اختر --</option>
                            </select>
                        </div>
                    </div>

                    <!-- مرفق مرجعي -->
                    <?php if (!empty($attachments)): ?>
                    <div class="form-group">
                        <label class="form-label">📎 مرفق مرجعي (اختياري)</label>
                        <select name="attachment_id" class="form-control">
                            <option value="">بدون مرفق</option>
                            <?php foreach ($attachments as $att): ?>
                            <option value="<?= e($att['id']) ?>" <?= (($_POST['attachment_id'] ?? '') == $att['id']) ? 'selected' : ''; ?>>
                                📄 <?= e($att['file_name']) ?> (<?= e($att['file_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="mt-4" style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" name="add_note" value="1" class="btn btn-primary btn-lg">
                            💾 حفظ الملاحظة
                        </button>
                        <button type="submit" name="save_draft" value="1" class="btn btn-outline-secondary btn-lg">
                            📂 حفظ كمسودة
                        </button>
                        <a href="index.php" class="btn btn-light btn-lg">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- المعاينة -->
    <div class="col-lg-4">
        <div class="card" style="border-radius: var(--radius-md, 8px); position:sticky; top:20px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title" style="font-size:16px;">👁️ معاينة</h3>
                <small class="text-muted">مباشرة</small>
            </div>
            <div class="card-body" id="preview-panel" style="min-height:200px;">
                <div id="preview-empty" class="text-center text-muted" style="padding:40px 0;">
                    <div style="font-size:36px;">📝</div>
                    <p>ابدأ بالكتابة لرؤية المعاينة</p>
                </div>
                <div id="preview-content" style="display:none;">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 id="preview-title" style="font-size:16px;font-weight:700;"></h5>
                        <span id="preview-priority-badge" class="badge" style="font-size:11px;"></span>
                    </div>
                    <div id="preview-text" style="font-size:14px;line-height:1.8;white-space:pre-wrap;"></div>
                    <hr style="border-color:var(--border-light, #dee2e6);">
                    <small class="text-muted">ستظهر الملاحظة بهذا الشكل تقريباً</small>
                </div>
            </div>
        </div>

        <!-- نصائح -->
        <div class="card mt-3" style="border-radius: var(--radius-md, 8px);">
            <div class="card-body" style="padding:12px 16px;">
                <h6 style="font-size:14px;margin-bottom:8px;">💡 نصائح</h6>
                <ul style="font-size:12px;color:var(--text-muted, #6c757d);margin-bottom:0;padding-right:16px;">
                    <li>استخدم الأولوية العاجلة للملاحظات المهمة جداً</li>
                    <li>اربط الملاحظة بالكيان المعني لسهولة الوصول</li>
                    <li>يمكنك حفظ المسودة والعودة إليها لاحقاً</li>
                    <li>استخدم أدوات التنسيق لتنظيم المحتوى</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
const entityData = {
    student: <?php echo json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => $s['full_name_ar']]; }, $students), JSON_UNESCAPED_UNICODE); ?>,
    class: <?php echo json_encode(array_map(function($c) { return ['id' => $c['id'], 'name' => $c['grade_name'] . ' - ' . $c['name_ar']]; }, $classes), JSON_UNESCAPED_UNICODE); ?>,
    teacher: <?php echo json_encode(array_map(function($t) { return ['id' => $t['id'], 'name' => $t['full_name_ar']]; }, $teachers), JSON_UNESCAPED_UNICODE); ?>,
    school: <?php echo json_encode(array_map(function($s) { return ['id' => $s['id'], 'name' => $s['name_ar']]; }, $schools), JSON_UNESCAPED_UNICODE); ?>
};

const entityLabels = <?php echo json_encode($entityTypes, JSON_UNESCAPED_UNICODE); ?>;

const priorityInfo = {
    low: { label: 'منخفضة', icon: '🟢', color: '#28a745' },
    medium: { label: 'متوسطة', icon: '🔵', color: '#17a2b8' },
    high: { label: 'عالية', icon: '🟠', color: '#fd7e14' },
    urgent: { label: 'عاجلة', icon: '🔴', color: '#dc3545' }
};

document.getElementById('entity-type-select').addEventListener('change', function() {
    const type = this.value;
    const group = document.getElementById('entity-id-group');
    const select = document.getElementById('entity-id-select');
    const label = document.getElementById('entity-label');

    if (type && entityData[type]) {
        group.style.display = 'block';
        label.textContent = entityLabels[type] || type;
        select.innerHTML = '<option value="">-- اختر --</option>';
        entityData[type].forEach(item => {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            select.appendChild(option);
        });
    } else {
        group.style.display = 'none';
    }
});

// تحديث المعاينة
function updatePreview() {
    const title = document.getElementById('note-title').value.trim();
    const content = document.getElementById('note-content').value.trim();
    const priority = document.getElementById('note-priority').value;
    const emptyEl = document.getElementById('preview-empty');
    const contentEl = document.getElementById('preview-content');

    if (!title && !content) {
        emptyEl.style.display = 'block';
        contentEl.style.display = 'none';
        return;
    }

    emptyEl.style.display = 'none';
    contentEl.style.display = 'block';

    document.getElementById('preview-title').textContent = title || 'بدون عنوان';

    const pInfo = priorityInfo[priority] || priorityInfo.medium;
    const badge = document.getElementById('preview-priority-badge');
    badge.textContent = pInfo.icon + ' ' + pInfo.label;
    badge.style.background = pInfo.color + '22';
    badge.style.color = pInfo.color;

    // تنسيق النص للمعاينة
    let formatted = content
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/__(.*?)__/g, '<u>$1</u>')
        .replace(/^### (.+)$/gm, '<strong style="font-size:15px;">$1</strong>')
        .replace(/^---$/gm, '<hr style="border-color:var(--border-light, #dee2e6);margin:8px 0;">');

    document.getElementById('preview-text').innerHTML = formatted;
}

// عداد الأحرف
function updateCharCount(elementId, text, max) {
    const el = document.getElementById(elementId);
    const len = text.length;
    el.textContent = len + '/' + max;
    if (len > max * 0.9) {
        el.style.color = 'var(--danger, #dc3545)';
    } else if (len > max * 0.7) {
        el.style.color = 'var(--warning, #f0ad4e)';
    } else {
        el.style.color = 'var(--text-muted, #6c757d)';
    }
}

// إدراج تنسيق
function insertFormat(type) {
    const textarea = document.getElementById('note-content');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selected = textarea.value.substring(start, end);
    let replacement = '';

    switch(type) {
        case 'bold':
            replacement = '**' + (selected || 'نص عريض') + '**';
            break;
        case 'italic':
            replacement = '*' + (selected || 'نص مائل') + '*';
            break;
        case 'underline':
            replacement = '__' + (selected || 'نص مسطر') + '__';
            break;
        case 'ul':
            replacement = '\n• ' + (selected || 'عنصر قائمة');
            break;
        case 'ol':
            replacement = '\n1. ' + (selected || 'عنصر قائمة');
            break;
        case 'heading':
            replacement = '\n### ' + (selected || 'عنوان فرعي');
            break;
        case 'quote':
            replacement = '\n> ' + (selected || 'اقتباس');
            break;
        case 'divider':
            replacement = '\n---\n';
            break;
    }

    textarea.value = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
    textarea.focus();
    textarea.selectionStart = start + replacement.length;
    textarea.selectionEnd = start + replacement.length;
    updatePreview();
    updateCharCount('content-count', textarea.value, 5000);
}

// تهيئة العدادات
document.addEventListener('DOMContentLoaded', function() {
    const titleEl = document.getElementById('note-title');
    const contentEl = document.getElementById('note-content');
    if (titleEl.value) updateCharCount('title-count', titleEl.value, 255);
    if (contentEl.value) updateCharCount('content-count', contentEl.value, 5000);
    updatePreview();
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
