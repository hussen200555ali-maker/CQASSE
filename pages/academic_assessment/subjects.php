<?php
/**
 * ============================================================
 * EQAS - إدارة المواد الدراسية
 * نظام التقييم الشامل - إدارة المواد وتعيينها للمراحل
 * ============================================================
 * يتضمن:
 * - قائمة المواد مع المراحل الدراسية المُعيَّنة لها
 * - إضافة مادة جديدة
 * - تعديل مادة
 * - تعيين مواد للمراحل الدراسية (ساعات + درجة النجاح)
 * - رابط لإعداد توزيع الأوزان
 * ============================================================
 */

$pageTitle = 'إدارة المواد الدراسية';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'إدارة المواد' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$academicYearId = getCurrentAcademicYearId($db);

// ============================================================
// معالجة الإجراءات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح', 'danger');
        redirect('subjects.php');
    }

    $action = $_POST['action'] ?? '';

    // إضافة مادة جديدة
    if ($action === 'add_subject') {
        $nameAr = trim($_POST['name_ar'] ?? '');
        $nameEn = trim($_POST['name_en'] ?? '');
        $code = trim($_POST['code'] ?? '');

        if (empty($nameAr) || empty($code)) {
            setFlash('اسم المادة والرمز مطلوبان', 'danger');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO subjects (name_ar, name_en, code, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$nameAr, $nameEn ?: null, $code]);
                setFlash('تم إضافة المادة بنجاح', 'success');
                logActivity($db, $_SESSION['user']['id'] ?? 0, 'add_subject', "إضافة مادة: {$nameAr}", 'subject', $db->lastInsertId());
            } catch (Exception $e) {
                setFlash('حدث خطأ: ' . (strpos($e->getMessage(), 'Duplicate') !== false ? 'رمز المادة موجود مسبقاً' : $e->getMessage()), 'danger');
            }
        }
        redirect('subjects.php');
    }

    // تعديل مادة
    if ($action === 'edit_subject') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $nameAr = trim($_POST['name_ar'] ?? '');
        $nameEn = trim($_POST['name_en'] ?? '');
        $code = trim($_POST['code'] ?? '');

        if ($subjectId <= 0 || empty($nameAr) || empty($code)) {
            setFlash('بيانات غير مكتملة', 'danger');
        } else {
            try {
                $stmt = $db->prepare("UPDATE subjects SET name_ar = ?, name_en = ?, code = ? WHERE id = ?");
                $stmt->execute([$nameAr, $nameEn ?: null, $code, $subjectId]);
                setFlash('تم تعديل المادة بنجاح', 'success');
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء التعديل', 'danger');
            }
        }
        redirect('subjects.php');
    }

    // تعيين مادة لمرحلة دراسية
    if ($action === 'assign_grade') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $gradeLevelId = (int)($_POST['grade_level_id'] ?? 0);
        $hoursPerWeek = (int)($_POST['hours_per_week'] ?? 0);
        $passingGrade = (float)($_POST['passing_grade'] ?? 50);

        if ($subjectId <= 0 || $gradeLevelId <= 0) {
            setFlash('يرجى اختيار المادة والمرحلة الدراسية', 'danger');
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO subject_grade_years (subject_id, grade_level_id, hours_per_week, passing_grade, is_active)
                    VALUES (?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE hours_per_week = VALUES(hours_per_week), passing_grade = VALUES(passing_grade)
                ");
                $stmt->execute([$subjectId, $gradeLevelId, $hoursPerWeek ?: null, $passingGrade]);
                setFlash('تم تعيين المادة للمرحلة بنجاح', 'success');
            } catch (Exception $e) {
                setFlash('حدث خطأ: ' . $e->getMessage(), 'danger');
            }
        }
        redirect('subjects.php');
    }

    // حذف تعيين
    if ($action === 'remove_assignment') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM subject_grade_years WHERE id = ?");
                $stmt->execute([$id]);
                setFlash('تم إزالة التعيين', 'success');
            } catch (Exception $e) {
                setFlash('لا يمكن إزالة التعيين - توجد بيانات مرتبطة', 'danger');
            }
        }
        redirect('subjects.php');
    }
}

// ============================================================
// جلب البيانات
// ============================================================

// جلب المواد مع عدد المراحل المُعيَّنة
$subjectsList = $db->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM subject_grade_years sgy WHERE sgy.subject_id = s.id AND sgy.is_active = 1) AS grade_count
    FROM subjects s
    WHERE s.is_active = 1
    ORDER BY s.name_ar
")->fetchAll();

// جلب المراحل الدراسية
$gradeLevels = $db->query("
    SELECT gl.*, 
           (SELECT COUNT(*) FROM subject_grade_years sgy WHERE sgy.grade_level_id = gl.id AND sgy.is_active = 1) AS subject_count
    FROM grade_levels gl
    WHERE gl.is_active = 1
    ORDER BY gl.level_number
")->fetchAll();

// جلب التعيينات
$assignments = $db->query("
    SELECT sgy.*, s.name_ar AS subject_name, s.code AS subject_code, gl.name_ar AS grade_name, gl.level_number, gl.stage
    FROM subject_grade_years sgy
    INNER JOIN subjects s ON s.id = sgy.subject_id
    INNER JOIN grade_levels gl ON gl.id = sgy.grade_level_id
    WHERE sgy.is_active = 1
    ORDER BY gl.level_number, s.name_ar
")->fetchAll();
?>

<!-- ============================================================
     المواد الدراسية
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">📚 المواد الدراسية</h3>
            <button type="button" class="btn btn-success" onclick="document.getElementById('addSubjectForm').style.display='block'">
                ➕ إضافة مادة جديدة
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج الإضافة -->
        <div id="addSubjectForm" style="display:none; margin-bottom:var(--space-6); padding:var(--space-4); background:var(--background); border-radius:var(--radius-md); border:1px dashed var(--border-dark);">
            <h4 style="margin-bottom:var(--space-4);">➕ إضافة مادة جديدة</h4>
            <form method="POST" action="subjects.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_subject">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">اسم المادة بالعربية <span class="required">*</span></label>
                        <input type="text" name="name_ar" class="form-control" required placeholder="مثال: الرياضيات">
                    </div>
                    <div class="form-group">
                        <label class="form-label">اسم المادة بالإنجليزية</label>
                        <input type="text" name="name_en" class="form-control" placeholder="مثال: Mathematics">
                    </div>
                    <div class="form-group">
                        <label class="form-label">رمز المادة <span class="required">*</span></label>
                        <input type="text" name="code" class="form-control" required placeholder="مثال: MATH" style="direction:ltr; text-align:right;">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end; gap:var(--space-2);">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-light" onclick="document.getElementById('addSubjectForm').style.display='none'">إلغاء</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- جدول المواد -->
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المادة</th>
                        <th>الرمز</th>
                        <th>عدد المراحل</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subjectsList)): ?>
                        <tr><td colspan="5" class="table-empty"><div class="table-empty-icon">📚</div><div class="table-empty-text">لا توجد مواد مسجلة</div></td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($subjectsList as $sub): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><strong><?= e($sub['name_ar']) ?></strong>
                                    <?php if ($sub['name_en']): ?><br><span class="text-sm text-secondary"><?= e($sub['name_en']) ?></span><?php endif; ?>
                                </td>
                                <td><code style="background:var(--background); padding:2px 8px; border-radius:var(--radius-sm);"><?= e($sub['code']) ?></code></td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $sub['grade_count'] > 0 ? 'success' : 'warning' ?>"><?= $sub['grade_count'] ?></span>
                                </td>
                                <td class="actions">
                                    <button type="button" class="btn btn-sm btn-info" onclick="editSubject(<?= $sub['id'] ?>, '<?= e($sub['name_ar']) ?>', '<?= e($sub['name_en'] ?? '') ?>', '<?= e($sub['code']) ?>')" title="تعديل">✏️</button>
                                    <a href="weight_setup.php?subject_id=<?= $sub['id'] ?>" class="btn btn-sm btn-warning" title="توزيع الأوزان">⚖️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     تعيين مواد للمراحل
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">🔗 تعيين المواد للمراحل الدراسية</h3>
            <button type="button" class="btn btn-success" onclick="document.getElementById('assignForm').style.display='block'">
                ➕ تعيين جديد
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج التعيين -->
        <div id="assignForm" style="display:none; margin-bottom:var(--space-6); padding:var(--space-4); background:var(--background); border-radius:var(--radius-md); border:1px dashed var(--border-dark);">
            <h4 style="margin-bottom:var(--space-4);">➕ تعيين مادة لمرحلة دراسية</h4>
            <form method="POST" action="subjects.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_grade">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المادة <span class="required">*</span></label>
                        <select name="subject_id" class="form-control" required>
                            <option value="">-- اختر المادة --</option>
                            <?php foreach ($subjectsList as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= e($sub['name_ar']) ?> (<?= e($sub['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المرحلة الدراسية <span class="required">*</span></label>
                        <select name="grade_level_id" class="form-control" required>
                            <option value="">-- اختر المرحلة --</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= $gl['id'] ?>"><?= e($gl['name_ar']) ?> (<?= $gl['stage'] === 'secondary' ? 'ثانوي' : 'أساس' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">عدد الساعات/أسبوع</label>
                        <input type="number" name="hours_per_week" class="form-control" min="1" max="20" value="4">
                    </div>
                    <div class="form-group">
                        <label class="form-label">درجة النجاح</label>
                        <input type="number" name="passing_grade" class="form-control" min="0" max="100" step="0.5" value="50">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-success">💾 تعيين</button>
                    <button type="button" class="btn btn-light" onclick="document.getElementById('assignForm').style.display='none'">إلغاء</button>
                </div>
            </form>
        </div>

        <!-- جدول التعيينات -->
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>المرحلة</th>
                        <th>المادة</th>
                        <th>الرمز</th>
                        <th>الساعات/أسبوع</th>
                        <th>درجة النجاح</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="6" class="table-empty"><div class="table-empty-icon">🔗</div><div class="table-empty-text">لا توجد تعيينات</div></td></tr>
                    <?php else: ?>
                        <?php $prevGrade = ''; foreach ($assignments as $asgn): ?>
                            <tr>
                                <td>
                                    <strong><?= e($asgn['grade_name']) ?></strong>
                                    <span class="badge badge-<?= $asgn['stage'] === 'secondary' ? 'danger' : 'info' ?>" style="font-size:10px;">
                                        <?= $asgn['stage'] === 'secondary' ? 'ثانوي' : 'أساس' ?>
                                    </span>
                                </td>
                                <td><?= e($asgn['subject_name']) ?></td>
                                <td><code><?= e($asgn['subject_code']) ?></code></td>
                                <td class="text-center"><?= $asgn['hours_per_week'] ?? '-' ?></td>
                                <td class="text-center"><?= $asgn['passing_grade'] ?>%</td>
                                <td>
                                    <form method="POST" action="subjects.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من إزالة هذا التعيين؟');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="remove_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $asgn['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="إزالة">🗑️</button>
                                    </form>
                                    <a href="weight_setup.php?subject_id=<?= $asgn['subject_id'] ?>&grade_level_id=<?= $asgn['grade_level_id'] ?>" class="btn btn-sm btn-warning" title="توزيع الأوزان">⚖️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     نافذة تعديل المادة
     ============================================================ -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; display:none; align-items:center; justify-content:center;">
    <div style="background:var(--surface); border-radius:var(--radius-xl); padding:var(--space-8); width:90%; max-width:500px; box-shadow:var(--shadow-2xl);">
        <h3 style="margin-bottom:var(--space-4);">✏️ تعديل المادة</h3>
        <form method="POST" action="subjects.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_subject">
            <input type="hidden" name="subject_id" id="edit_subject_id">
            <div class="form-group">
                <label class="form-label">اسم المادة بالعربية</label>
                <input type="text" name="name_ar" id="edit_name_ar" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">اسم المادة بالإنجليزية</label>
                <input type="text" name="name_en" id="edit_name_en" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">رمز المادة</label>
                <input type="text" name="code" id="edit_code" class="form-control" required style="direction:ltr; text-align:right;">
            </div>
            <div class="flex gap-3 mt-4">
                <button type="submit" class="btn btn-success">💾 حفظ التعديل</button>
                <button type="button" class="btn btn-light" onclick="closeEditModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * فتح نافذة تعديل المادة
 */
function editSubject(id, nameAr, nameEn, code) {
    document.getElementById('edit_subject_id').value = id;
    document.getElementById('edit_name_ar').value = nameAr;
    document.getElementById('edit_name_en').value = nameEn;
    document.getElementById('edit_code').value = code;
    document.getElementById('editModal').style.display = 'flex';
}

/**
 * إغلاق نافذة التعديل
 */
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
