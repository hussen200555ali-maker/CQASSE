<?php
/**
 * ============================================================
 * تعديل ملاحظة طالب - Edit Student Note
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل ملاحظة';
$breadcrumb = ['الملاحظات' => 'index.php', 'تعديل ملاحظة' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];

$noteId = (int)($_GET['id'] ?? 0);
if (!$noteId) {
    setFlash('معرف الملاحظة غير صالح', 'danger');
    redirect('index.php');
}

// جلب بيانات الملاحظة
$note = null;
try {
    $stmt = $db->prepare("
        SELECT sn.*, s.full_name_ar as student_name, s.student_number,
               c.name_ar as class_name, gl.name_ar as grade_name,
               u.full_name_ar as creator_name
        FROM student_notes sn
        JOIN students s ON sn.student_id = s.id
        JOIN classes c ON sn.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        JOIN users u ON sn.created_by = u.id
        WHERE sn.id = ? AND sn.is_active = 1
    ");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();
} catch (Exception $e) {
    $note = null;
}

if (!$note) {
    setFlash('الملاحظة غير موجودة', 'danger');
    redirect('index.php');
}

// التحقق من الملكية: فقط منشئ الملاحظة أو المدير يمكنه التعديل
if ($note['created_by'] != $userId && !hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية لتعديل هذه الملاحظة', 'danger');
    redirect('index.php');
}

// التحقق من قفل الفصل الدراسي
if (isSemesterLocked($db, $note['semester_id'])) {
    if (!canEditSemester($db, $note['semester_id'], $userRole)) {
        setFlash('الفصل الدراسي مقفل ولا يمكن تعديل الملاحظة', 'danger');
        redirect('index.php');
    }
}

// خيارات أنواع الملاحظات
$noteTypes = [
    'general' => 'عامة',
    'academic' => 'أكاديمية',
    'behavioral' => 'سلوكية',
    'attendance' => 'حضور'
];

// خيارات الأولوية
$priorityOptions = [
    'low' => ['label' => 'منخفضة', 'icon' => '🟢'],
    'medium' => ['label' => 'متوسطة', 'icon' => '🔵'],
    'high' => ['label' => 'عالية', 'icon' => '🟠'],
    'urgent' => ['label' => 'عاجلة', 'icon' => '🔴'],
];

$errors = [];

// معالجة التعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('edit.php?id=' . $noteId);
    }

    $title = trim($_POST['title_ar'] ?? '');
    $content = trim($_POST['content_ar'] ?? '');
    $noteType = $_POST['note_type'] ?? $note['note_type'];
    $priority = $_POST['priority'] ?? $note['priority'];
    $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

    // التحقق من المدخلات
    if (empty($title)) $errors['title_ar'] = 'عنوان الملاحظة مطلوب';
    if (empty($content)) $errors['content_ar'] = 'محتوى الملاحظة مطلوب';
    if (mb_strlen($content) > 5000) $errors['content_ar'] = 'محتوى الملاحظة يتجاوز الحد الأقصى (5000 حرف)';
    if (!in_array($noteType, array_keys($noteTypes))) $noteType = 'general';
    if (!in_array($priority, array_keys($priorityOptions))) $priority = 'medium';

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE student_notes
                SET title_ar = ?, content_ar = ?, note_type = ?, priority = ?, is_pinned = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$title, $content, $noteType, $priority, $isPinned, $noteId]);

            logActivity($db, $userId, 'edit_student_note', 'تعديل ملاحظة: ' . $title . ' - الطالب: ' . $note['student_name'], 'student_note', $noteId);
            setFlash('تم تحديث الملاحظة بنجاح', 'success');
            redirect('index.php');
        } catch (Exception $e) {
            error_log('Note Edit Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء تحديث الملاحظة';
        }
    }

    // تحديث بيانات النموذج في حالة وجود أخطاء
    $note['title_ar'] = $title;
    $note['content_ar'] = $content;
    $note['note_type'] = $noteType;
    $note['priority'] = $priority;
    $note['is_pinned'] = $isPinned;
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">✏️ تعديل ملاحظة</h3>
        <div class="flex gap-2">
            <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <!-- معلومات الملاحظة -->
        <div style="background:var(--bg-light, #f8f9fa); border-radius:var(--radius-md); padding:12px 16px; margin-bottom:20px;">
            <div class="grid grid-3" style="font-size:13px;">
                <div>
                    <span style="color:var(--text-muted);">الطالب:</span>
                    <strong><?= e($note['student_name']) ?></strong>
                    <span style="color:var(--text-muted);">(<?= e($note['student_number']) ?>)</span>
                </div>
                <div>
                    <span style="color:var(--text-muted);">الفصل:</span>
                    <strong><?= e($note['grade_name'] . ' - ' . $note['class_name']) ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted);">أنشئت بواسطة:</span>
                    <strong><?= e($note['creator_name']) ?></strong>
                </div>
            </div>
        </div>

        <form method="POST" data-validate>
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">عنوان الملاحظة <span class="text-danger">*</span></label>
                <input type="text" name="title_ar" class="form-control <?= isset($errors['title_ar']) ? 'is-invalid' : '' ?>"
                       value="<?= e($note['title_ar']) ?>" required maxlength="255">
                <?php if (isset($errors['title_ar'])): ?>
                    <div class="invalid-feedback"><?= e($errors['title_ar']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">محتوى الملاحظة <span class="text-danger">*</span></label>
                <textarea name="content_ar" class="form-control <?= isset($errors['content_ar']) ? 'is-invalid' : '' ?>"
                          rows="6" required maxlength="5000"><?= e($note['content_ar']) ?></textarea>
                <?php if (isset($errors['content_ar'])): ?>
                    <div class="invalid-feedback"><?= e($errors['content_ar']) ?></div>
                <?php endif; ?>
                <small style="color:var(--text-muted);">الحد الأقصى 5000 حرف</small>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">نوع الملاحظة</label>
                    <select name="note_type" class="form-control">
                        <?php foreach ($noteTypes as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $note['note_type'] === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">الأولوية</label>
                    <select name="priority" class="form-control">
                        <?php foreach ($priorityOptions as $key => $info): ?>
                            <option value="<?= $key ?>" <?= $note['priority'] === $key ? 'selected' : '' ?>>
                                <?= $info['icon'] ?> <?= e($info['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="is_pinned" id="is_pinned" class="form-check-input" value="1"
                           <?= !empty($note['is_pinned']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_pinned">📌 تثبيت الملاحظة</label>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
