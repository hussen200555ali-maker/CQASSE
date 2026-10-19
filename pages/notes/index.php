<?php
/**
 * ============================================================
 * EQAS - الملاحظات - قائمة الملاحظات
 * نظام التقييم الشامل - صفحة الملاحظات
 * ============================================================
 */

$pageTitle = 'الملاحظات';
$breadcrumb = ['الملاحظات' => null];

require_once __DIR__ . '/../../includes/header.php';

requireLogin();

// [إصلاح v2.0.3] فحص الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3

// فلاتر
$filterPriority = $_GET['priority'] ?? '';
$filterNoteType = $_GET['note_type'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterPinned = isset($_GET['pinned']) && $_GET['pinned'] === '1';

// الصفحة الحالية للتصفح
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// معالجة التثبيت/إلغاء التثبيت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_pin'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }
    $pinId = (int)$_POST['toggle_pin'];
    $currentPin = (int)$_POST['current_pin'] ?? 0;
    $newPin = $currentPin ? 0 : 1;
    $stmt = $db->prepare("UPDATE student_notes SET is_pinned = ? WHERE id = ? AND created_by = ?");
    $stmt->execute([$newPin, $pinId, $userId]);
    logActivity($db, $userId, 'note_pin', ($newPin ? 'تثبيت' : 'إلغاء تثبيت') . ' ملاحظة #' . $pinId, 'note', $pinId);
    setFlash($newPin ? 'تم تثبيت الملاحظة' : 'تم إلغاء تثبيت الملاحظة', 'success');
    redirect('index.php');
}

// معالجة الحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }
    $deleteId = (int)$_POST['delete_id'];
    $stmt = $db->prepare("UPDATE student_notes SET is_active = 0 WHERE id = ? AND created_by = ?");
    $stmt->execute([$deleteId, $userId]);
    logActivity($db, $userId, 'note_delete', 'حذف ملاحظة #' . $deleteId, 'note', $deleteId);
    setFlash('تم حذف الملاحظة', 'success');
    redirect('index.php');
}

// معالجة الحذف الجماعي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }
    $selectedIds = $_POST['selected_notes'] ?? [];
    if (!empty($selectedIds) && is_array($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_map('intval', $selectedIds);
        array_unshift($params, $userId);
        $stmt = $db->prepare("UPDATE student_notes SET is_active = 0 WHERE created_by = ? AND id IN ($placeholders)");
        $stmt->execute($params);
        $count = count($selectedIds);
        logActivity($db, $userId, 'note_bulk_delete', "حذف جماعي لـ {$count} ملاحظة", 'note', null);
        setFlash("تم حذف {$count} ملاحظة بنجاح", 'success');
    }
    redirect('index.php');
}

// إحصائيات الملاحظات
$stmt = $db->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
    SUM(CASE WHEN is_pinned = 1 THEN 1 ELSE 0 END) as pinned_count,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as recent_count
    FROM student_notes WHERE created_by = ? AND is_active = 1");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

// بناء الاستعلام
$sql = "SELECT n.*,
        s.full_name_ar as entity_name,
        s.student_number,
        s.school_id as note_school_id,
        c.name_ar as class_name
        FROM student_notes n
        LEFT JOIN students s ON n.student_id = s.id
        LEFT JOIN classes c ON n.class_id = c.id
        WHERE n.created_by = ? AND n.is_active = 1";
$params = [$userId];

if ($filterPriority) {
    $sql .= " AND n.priority = ?";
    $params[] = $filterPriority;
}
if ($filterNoteType) {
    $sql .= " AND n.note_type = ?";
    $params[] = $filterNoteType;
}
if ($filterSearch) {
    $sql .= " AND (n.title_ar LIKE ? OR n.content_ar LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}
if ($filterDateFrom) {
    $sql .= " AND n.created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $sql .= " AND n.created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}
if ($filterPinned) {
    $sql .= " AND n.is_pinned = 1";
}

// عدد النتائج الكلي للتصفح
$countParams = $params;
$countStmt = $db->prepare("SELECT COUNT(*) FROM student_notes n WHERE n.created_by = ? AND n.is_active = 1"
    . ($filterPriority ? " AND n.priority = ?" : "")
    . ($filterNoteType ? " AND n.note_type = ?" : "")
    . ($filterSearch ? " AND (n.title_ar LIKE ? OR n.content_ar LIKE ?)" : "")
    . ($filterDateFrom ? " AND n.created_at >= ?" : "")
    . ($filterDateTo ? " AND n.created_at <= ?" : "")
    . ($filterPinned ? " AND n.is_pinned = 1" : ""));
$countStmt->execute($countParams);
$totalNotes = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalNotes / $perPage));

$sql .= " ORDER BY n.is_pinned DESC, FIELD(n.priority, 'urgent', 'high', 'medium', 'low'), n.created_at DESC";
$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

// تسميات الأولويات
$priorityLabels = [
    'low' => ['label' => 'منخفضة', 'color' => 'secondary', 'icon' => '🟢', 'bg' => '#e8f5e9'],
    'medium' => ['label' => 'متوسطة', 'color' => 'info', 'icon' => '🔵', 'bg' => '#e3f2fd'],
    'high' => ['label' => 'عالية', 'color' => 'warning', 'icon' => '🟠', 'bg' => '#fff3e0'],
    'urgent' => ['label' => 'عاجلة', 'color' => 'danger', 'icon' => '🔴', 'bg' => '#fce4ec'],
];

// أنواع الملاحظات
$noteTypeLabels = [
    'general' => 'عامة',
    'academic' => 'أكاديمية',
    'behavioral' => 'سلوكية',
    'attendance' => 'حضور',
];
?>

<!-- شريط الإحصائيات -->
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-2">
        <div class="card border" style="border-radius: var(--radius-md, 8px); overflow:hidden;">
            <div class="card-body text-center" style="padding:16px;">
                <div style="font-size:28px;">📝</div>
                <div style="font-size:24px;font-weight:700;color:var(--primary);"><?= e($stats['total']) ?></div>
                <div class="text-muted" style="font-size:12px;">إجمالي الملاحظات</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card border" style="border-radius: var(--radius-md, 8px); overflow:hidden; border-right: 4px solid var(--danger, #dc3545);">
            <div class="card-body text-center" style="padding:16px;">
                <div style="font-size:28px;">🔴</div>
                <div style="font-size:24px;font-weight:700;color:var(--danger, #dc3545);"><?= e($stats['urgent_count']) ?></div>
                <div class="text-muted" style="font-size:12px;">ملاحظات عاجلة</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card border" style="border-radius: var(--radius-md, 8px); overflow:hidden; border-right: 4px solid var(--warning, #f0ad4e);">
            <div class="card-body text-center" style="padding:16px;">
                <div style="font-size:28px;">📌</div>
                <div style="font-size:24px;font-weight:700;color:var(--warning, #f0ad4e);"><?= e($stats['pinned_count']) ?></div>
                <div class="text-muted" style="font-size:12px;">ملاحظات مثبتة</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card border" style="border-radius: var(--radius-md, 8px); overflow:hidden; border-right: 4px solid var(--info, #17a2b8);">
            <div class="card-body text-center" style="padding:16px;">
                <div style="font-size:28px;">🆕</div>
                <div style="font-size:24px;font-weight:700;color:var(--info, #17a2b8);"><?= e($stats['recent_count']) ?></div>
                <div class="text-muted" style="font-size:12px;">آخر ٧ أيام</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 class="card-title">📝 الملاحظات</h2>
        <div style="display:flex;gap:8px;">
            <a href="?pinned=1" class="btn btn-sm <?= $filterPinned ? 'btn-warning' : 'btn-outline-warning' ?>">📌 المثبتة</a>
            <a href="add.php" class="btn btn-primary">➕ إضافة ملاحظة</a>
        </div>
    </div>
    <div class="card-body">
        <!-- بحث وفلاتر -->
        <form method="GET" id="filter-form">
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" value="<?php echo e($filterSearch); ?>" placeholder="بحث في الملاحظات...">
                        <button class="btn btn-outline-secondary" type="submit">🔍</button>
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="priority" class="form-control" onchange="this.form.submit()">
                        <option value="">كل الأولويات</option>
                        <?php foreach ($priorityLabels as $key => $info): ?>
                        <option value="<?php echo $key; ?>" <?php echo $filterPriority === $key ? 'selected' : ''; ?>>
                            <?php echo $info['icon']; ?> <?php echo $info['label']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="note_type" class="form-control" onchange="this.form.submit()">
                        <option value="">كل الأنواع</option>
                        <?php foreach ($noteTypeLabels as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $filterNoteType === $key ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>" placeholder="من تاريخ" onchange="this.form.submit()">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>" placeholder="إلى تاريخ" onchange="this.form.submit()">
                </div>
            </div>
            <?php if ($filterPinned): ?>
                <input type="hidden" name="pinned" value="1">
            <?php endif; ?>
        </form>

        <!-- إجراءات جماعية -->
        <form method="POST" id="bulk-form" onsubmit="return confirm('هل أنت متأكد من الحذف الجماعي؟');">
            <?= csrfField() ?>
            <div class="d-flex justify-content-between align-items-center mb-3" style="border-bottom:1px solid var(--border-light, #dee2e6); padding-bottom:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                        <span>تحديد الكل</span>
                    </label>
                    <span id="selected-count" class="text-muted" style="font-size:12px;"></span>
                </div>
                <button type="submit" name="bulk_delete" value="1" class="btn btn-sm btn-outline-danger" id="bulk-delete-btn" style="display:none;">
                    🗑️ حذف المحدد (<span id="bulk-count">0</span>)
                </button>
            </div>

            <!-- قائمة الملاحظات -->
            <?php if (empty($notes)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p class="empty-state-text">لا توجد ملاحظات. <a href="add.php">أضف ملاحظة جديدة</a></p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($notes as $note): ?>
                    <?php $priority = $priorityLabels[$note['priority']] ?? $priorityLabels['medium']; ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card border" style="border-radius: var(--radius-md, 8px); border-right:4px solid var(--<?= $priority['color'] ?>, #666) !important; background: <?= $note['is_pinned'] ? $priority['bg'] : 'white' ?>; position:relative; transition: box-shadow 0.2s;">
                            <?php if ($note['is_pinned']): ?>
                            <div style="position:absolute;top:8px;left:8px;font-size:14px;" title="ملاحظة مثبتة">📌</div>
                            <?php endif; ?>
                            <div class="card-body" style="padding:14px;">
                                <div style="display:flex;align-items:start;gap:6px;">
                                    <input type="checkbox" name="selected_notes[]" value="<?= $note['id'] ?>" class="note-checkbox" style="margin-top:4px;" onchange="updateBulkActions()">
                                    <div style="flex:1;">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="card-title mb-0" style="font-size:15px; <?= $note['is_pinned'] ? 'font-weight:700;' : '' ?>">
                                                <?php echo e($note['title_ar']); ?>
                                            </h5>
                                            <div style="display:flex;gap:2px;">
                                                <!-- زر التثبيت -->
                                                <form method="POST" style="display:inline;">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="toggle_pin" value="<?= $note['id'] ?>">
                                                    <input type="hidden" name="current_pin" value="<?= $note['is_pinned'] ?? 0 ?>">
                                                    <button type="submit" class="btn btn-sm btn-link" style="padding:0;font-size:12px;" title="<?= $note['is_pinned'] ? 'إلغاء التثبيت' : 'تثبيت' ?>">
                                                        <?= $note['is_pinned'] ? '📌' : '📍' ?>
                                                    </button>
                                                </form>
                                                <!-- زر الحذف -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('حذف الملاحظة؟');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="delete_id" value="<?php echo $note['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-link text-danger" style="padding:0;font-size:12px;">🗑️</button>
                                                </form>
                                            </div>
                                        </div>
                                        <p class="card-text text-muted" style="font-size:13px;">
                                            <?php echo nl2br(e(truncate($note['content_ar'], 150))); ?>
                                        </p>
                                        <?php if ($note['entity_name']): ?>
                                        <div class="mb-2">
                                            <span class="badge" style="background:var(--border-light, #e9ecef); color:var(--text-muted, #6c757d);">
                                                📎 <?php echo e($noteTypeLabels[$note['note_type']] ?? $note['note_type']); ?>:
                                                <?php echo e($note['entity_name']); ?>
                                                <?php if ($note['student_number']): ?>
                                                (<?php echo e($note['student_number']); ?>)
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($note['class_name']): ?>
                                            <span class="badge" style="background:var(--bg-light, #f0f0f0); color:var(--text-muted, #6c757d); font-size:10px;">
                                                🏫 <?php echo e($note['class_name']); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted"><?php echo formatDateAr($note['created_at'], 'short'); ?></small>
                                            <span class="badge" style="background:<?= $priority['bg'] ?>; color:var(--<?= $priority['color'] ?>, #666); font-size:11px;">
                                                <?php echo $priority['icon']; ?> <?php echo $priority['label']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </form>

        <!-- التصفح -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="تصفح الملاحظات" class="mt-3">
            <ul class="pagination justify-content-center" style="gap:4px;">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&priority=<?= e($filterPriority) ?>&note_type=<?= e($filterNoteType) ?>&search=<?= urlencode($filterSearch) ?>&date_from=<?= e($filterDateFrom) ?>&date_to=<?= e($filterDateTo) ?><?= $filterPinned ? '&pinned=1' : '' ?>">« السابق</a>
                </li>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&priority=<?= e($filterPriority) ?>&note_type=<?= e($filterNoteType) ?>&search=<?= urlencode($filterSearch) ?>&date_from=<?= e($filterDateFrom) ?>&date_to=<?= e($filterDateTo) ?><?= $filterPinned ? '&pinned=1' : '' ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&priority=<?= e($filterPriority) ?>&note_type=<?= e($filterNoteType) ?>&search=<?= urlencode($filterSearch) ?>&date_from=<?= e($filterDateFrom) ?>&date_to=<?= e($filterDateTo) ?><?= $filterPinned ? '&pinned=1' : '' ?>">التالي »</a>
                </li>
                <?php endif; ?>
            </ul>
            <p class="text-center text-muted" style="font-size:12px;">
                عرض <?= $offset + 1 ?> - <?= min($offset + $perPage, $totalNotes) ?> من <?= $totalNotes ?> ملاحظة
            </p>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
// تحديد الكل
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.note-checkbox');
    checkboxes.forEach(cb => { cb.checked = source.checked; });
    updateBulkActions();
}

// تحديث إجراءات الحذف الجماعي
function updateBulkActions() {
    const checked = document.querySelectorAll('.note-checkbox:checked');
    const btn = document.getElementById('bulk-delete-btn');
    const countSpan = document.getElementById('bulk-count');
    const countLabel = document.getElementById('selected-count');
    if (checked.length > 0) {
        btn.style.display = 'inline-block';
        countSpan.textContent = checked.length;
        countLabel.textContent = 'تم تحديد ' + checked.length;
    } else {
        btn.style.display = 'none';
        countLabel.textContent = '';
    }
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
