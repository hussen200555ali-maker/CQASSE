<?php
/**
 * ============================================================
 * EQAS - إعداد المواد الدراسية
 * نظام التقييم الشامل - Subject Setup
 * ============================================================
 * تعيين المواد للصفوف الدراسية مع:
 * - تحديد الساعات الأسبوعية ودرجة النجاح
 * - عمليات جماعية
 * - ترتيب المواد
 * ============================================================
 */

$pageTitle = 'إعداد المواد الدراسية';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'المواد الدراسية' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

// ═══════════════════════════════════════════
// معالجة الحفظ
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_subject_grade') {
            // حفظ تعيين مادة لصف دراسي
            $subjectId = (int)$_POST['subject_id'];
            $gradeLevelId = (int)$_POST['grade_level_id'];
            $hoursPerWeek = (int)($_POST['hours_per_week'] ?? 0);
            $passingGrade = (float)($_POST['passing_grade'] ?? 50);

            if ($hoursPerWeek < 0 || $hoursPerWeek > 20) {
                setFlash('عدد الساعات يجب أن يكون بين 0 و 20.', 'warning');
            } elseif ($passingGrade < 0 || $passingGrade > 100) {
                setFlash('درجة النجاح يجب أن تكون بين 0 و 100.', 'warning');
            } else {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO subject_grade_years (subject_id, grade_level_id, hours_per_week, passing_grade, is_active)
                        VALUES (?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE hours_per_week = VALUES(hours_per_week), passing_grade = VALUES(passing_grade)
                    ");
                    $stmt->execute([$subjectId, $gradeLevelId, $hoursPerWeek, $passingGrade]);
                    logActivity($db, $_SESSION['user']['id'], 'setup_subjects', "تعيين مادة رقم {$subjectId} للصف رقم {$gradeLevelId}", 'subject_grade_years', null);
                    setFlash('تم حفظ التعيين بنجاح.', 'success');
                } catch (Exception $e) {
                    setFlash('خطأ في الحفظ: ' . $e->getMessage(), 'danger');
                }
            }
        } elseif ($action === 'bulk_assign') {
            // تعيين جماعي
            $subjectIds = $_POST['subject_ids'] ?? [];
            $gradeLevelIds = $_POST['grade_level_ids'] ?? [];
            $hoursPerWeek = (int)($_POST['bulk_hours'] ?? 2);
            $passingGrade = (float)($_POST['bulk_passing'] ?? 50);

            $count = 0;
            foreach ($subjectIds as $sid) {
                foreach ($gradeLevelIds as $gid) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO subject_grade_years (subject_id, grade_level_id, hours_per_week, passing_grade, is_active)
                            VALUES (?, ?, ?, ?, 1)
                            ON DUPLICATE KEY UPDATE hours_per_week = VALUES(hours_per_week), passing_grade = VALUES(passing_grade)
                        ");
                        $stmt->execute([(int)$sid, (int)$gid, $hoursPerWeek, $passingGrade]);
                        $count++;
                    } catch (Exception $e) { /* تجاهل الأخطاء الفردية */ }
                }
            }
            logActivity($db, $_SESSION['user']['id'], 'setup_subjects_bulk', "تعيين جماعي: {$count} تعيين", 'subject_grade_years', null);
            setFlash("تم تعيين {$count} مادة بنجاح.", 'success');

        } elseif ($action === 'update_order') {
            // تحديث ترتيب المواد
            $orders = $_POST['sort_order'] ?? [];
            foreach ($orders as $id => $order) {
                $stmt = $db->prepare("UPDATE subject_grade_years SET hours_per_week = ? WHERE id = ?");
                $stmt->execute([(int)$order, (int)$id]);
            }
            setFlash('تم تحديث الترتيب.', 'success');

        } elseif ($action === 'delete_assignment') {
            // حذف تعيين
            $id = (int)$_POST['assignment_id'];
            $stmt = $db->prepare("UPDATE subject_grade_years SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            logActivity($db, $_SESSION['user']['id'], 'setup_subjects_delete', "حذف تعيين مادة رقم {$id}", 'subject_grade_years', $id);
            setFlash('تم حذف التعيين.', 'success');
        }

        redirect('subjects.php');
    }
}

// ═══════════════════════════════════════════
// جلب البيانات
// ═══════════════════════════════════════════
$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
$gradeLevels = $db->query("SELECT id, name_ar, level_number, stage FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();

// التعيينات الحالية
$assignments = $db->query("
    SELECT sgy.id, sgy.subject_id, sgy.grade_level_id, sgy.hours_per_week, sgy.passing_grade,
           s.name_ar as subject_name, s.code as subject_code,
           gl.name_ar as grade_name, gl.level_number
    FROM subject_grade_years sgy
    JOIN subjects s ON sgy.subject_id = s.id
    JOIN grade_levels gl ON sgy.grade_level_id = gl.id
    WHERE sgy.is_active = 1
    ORDER BY gl.level_number, s.name_ar
")->fetchAll();

// تجميع حسب الصف
$byGrade = [];
foreach ($assignments as $a) {
    $byGrade[$a['grade_level_id']]['grade_name'] = $a['grade_name'];
    $byGrade[$a['grade_level_id']]['level_number'] = $a['level_number'];
    $byGrade[$a['grade_level_id']]['subjects'][] = $a;
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">📚 إعداد المواد الدراسية</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('add-form').style.display='block'">
                ➕ تعيين مادة جديدة
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>ℹ️ تعليمات:</strong> قم بتعيين المواد لكل صف دراسي مع تحديد عدد الساعات الأسبوعية ودرجة النجاح. الافتراضي: ساعتان أسبوعياً و50% للنجاح.
            </div>

            <!-- نموذج إضافة -->
            <div id="add-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--primary);">
                    <div class="card-header" style="background:var(--primary);color:white;">
                        <h3 class="card-title" style="color:white;">➕ تعيين مادة لصف دراسي</h3>
                    </div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_subject_grade">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">المادة</label>
                                    <select name="subject_id" class="form-control" required>
                                        <option value="">اختر المادة</option>
                                        <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?> (<?= e($s['code']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">الصف الدراسي</label>
                                    <select name="grade_level_id" class="form-control" required>
                                        <option value="">اختر الصف</option>
                                        <?php foreach ($gradeLevels as $gl): ?>
                                        <option value="<?= $gl['id'] ?>"><?= e($gl['name_ar']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">ساعات/أسبوع</label>
                                    <input type="number" name="hours_per_week" class="form-control" value="2" min="1" max="20">
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">درجة النجاح %</label>
                                    <input type="number" name="passing_grade" class="form-control" value="50" min="0" max="100" step="0.5">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary">💾 حفظ</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('add-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- تعيين جماعي -->
            <div style="margin-bottom:20px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('bulk-form').style.display = document.getElementById('bulk-form').style.display === 'none' ? 'block' : 'none'">
                    📦 تعيين جماعي
                </button>
            </div>
            <div id="bulk-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px dashed var(--secondary);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="bulk_assign">
                        <div class="card-body">
                            <h4 style="margin-bottom:12px;">📦 تعيين جماعي للمواد</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">المواد</label>
                                    <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border-light);padding:8px;border-radius:var(--radius-md);">
                                        <?php foreach ($subjects as $s): ?>
                                        <label class="form-check" style="margin-bottom:4px;">
                                            <input type="checkbox" name="subject_ids[]" value="<?= $s['id'] ?>" class="form-check-input">
                                            <span class="form-check-label"><?= e($s['name_ar']) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">الصفوف</label>
                                    <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border-light);padding:8px;border-radius:var(--radius-md);">
                                        <?php foreach ($gradeLevels as $gl): ?>
                                        <label class="form-check" style="margin-bottom:4px;">
                                            <input type="checkbox" name="grade_level_ids[]" value="<?= $gl['id'] ?>" class="form-check-input">
                                            <span class="form-check-label"><?= e($gl['name_ar']) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">ساعات/أسبوع</label>
                                    <input type="number" name="bulk_hours" class="form-control" value="2" min="1" max="20">
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">درجة النجاح %</label>
                                    <input type="number" name="bulk_passing" class="form-control" value="50" min="0" max="100" step="0.5">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-secondary">📦 تعيين جماعي</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('bulk-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- عرض التعيينات الحالية -->
            <?php if (empty($byGrade)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📚</div>
                <p>لم يتم تعيين أي مواد بعد. ابدأ بإضافة تعيين جديد أو استخدام التعيين الجماعي.</p>
            </div>
            <?php else: ?>
            <?php foreach ($byGrade as $gid => $gradeData): ?>
            <div class="card mb-4">
                <div class="card-header" style="background:var(--background);">
                    <h4 style="margin:0;">
                        📖 <?= e($gradeData['grade_name']) ?>
                        <span style="font-size:12px;color:var(--text-muted);">(<?= count($gradeData['subjects']) ?> مادة)</span>
                    </h4>
                </div>
                <div class="card-body" style="padding:0;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>المادة</th>
                                <th>الرمز</th>
                                <th>ساعات/أسبوع</th>
                                <th>درجة النجاح</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradeData['subjects'] as $subj): ?>
                            <tr>
                                <td><strong><?= e($subj['subject_name']) ?></strong></td>
                                <td><code><?= e($subj['subject_code']) ?></code></td>
                                <td><?= $subj['hours_per_week'] ?></td>
                                <td><?= $subj['passing_grade'] ?>%</td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذا التعيين؟');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $subj['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:2px 8px;font-size:11px;">🗑️ حذف</button>
                                    </form>
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
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
