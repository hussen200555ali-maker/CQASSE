<?php
/**
 * ============================================================
 * EQAS - تعيين المعلمين
 * نظام التقييم الشامل - Teacher Assignment
 * ============================================================
 */

$pageTitle = 'تعيين المعلمين';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'تعيين المعلمين' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$academicYearId = currentAcademicYearId();
$schoolId = currentSchoolId();

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'assign_teacher_subject') {
            $teacherId = (int)$_POST['teacher_id'];
            $subjectId = (int)$_POST['subject_id'];
            $classId = (int)$_POST['class_id'];

            if ($teacherId && $subjectId && $classId) {
                try {
                    // إنشاء جدول تعيينات المعلمين إذا لم يكن موجوداً
                    $db->exec("CREATE TABLE IF NOT EXISTS teacher_assignments (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        teacher_id INT UNSIGNED NOT NULL,
                        subject_id INT UNSIGNED NOT NULL,
                        class_id INT UNSIGNED NOT NULL,
                        academic_year_id INT UNSIGNED NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uk_teacher_subj_class_year (teacher_id, subject_id, class_id, academic_year_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $stmt = $db->prepare("
                        INSERT INTO teacher_assignments (teacher_id, subject_id, class_id, academic_year_id, is_active)
                        VALUES (?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE is_active = 1
                    ");
                    $stmt->execute([$teacherId, $subjectId, $classId, $academicYearId]);
                    logActivity($db, $_SESSION['user']['id'], 'setup_teacher_assign', "تعيين معلم رقم {$teacherId} للمادة {$subjectId} بالفصل {$classId}", 'teacher_assignments', null);
                    setFlash('تم التعيين بنجاح.', 'success');
                } catch (Exception $e) {
                    setFlash('خطأ: ' . $e->getMessage(), 'danger');
                }
            } else {
                setFlash('يرجى ملء جميع الحقول.', 'warning');
            }

        } elseif ($action === 'remove_assignment') {
            $id = (int)$_POST['assignment_id'];
            $stmt = $db->prepare("UPDATE teacher_assignments SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم إلغاء التعيين.', 'success');
        }

        redirect('teachers_assign.php');
    }
}

// جلب البيانات
$teachers = $db->query("
    SELECT u.id, u.full_name_ar
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'teacher' AND u.is_active = 1
    ORDER BY u.full_name_ar
")->fetchAll();

$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

$classes = $db->query("
    SELECT c.id, c.name_ar, gl.name_ar as grade_name
    FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id
    WHERE c.is_active = 1 AND c.academic_year_id = {$academicYearId}
    ORDER BY gl.level_number, c.name_ar
")->fetchAll();

// التعيينات الحالية
$assignments = [];
try {
    $assignments = $db->query("
        SELECT ta.id, ta.teacher_id, ta.subject_id, ta.class_id,
               u.full_name_ar as teacher_name,
               s.name_ar as subject_name, s.code as subject_code,
               c.name_ar as class_name, gl.name_ar as grade_name
        FROM teacher_assignments ta
        JOIN users u ON ta.teacher_id = u.id
        JOIN subjects s ON ta.subject_id = s.id
        JOIN classes c ON ta.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        WHERE ta.is_active = 1 AND ta.academic_year_id = {$academicYearId}
        ORDER BY u.full_name_ar, s.name_ar
    ")->fetchAll();
} catch (Exception $e) {
    $assignments = [];
}

// الحمل التدريسي لكل معلم
$teacherLoad = [];
foreach ($assignments as $a) {
    if (!isset($teacherLoad[$a['teacher_id']])) {
        $teacherLoad[$a['teacher_id']] = ['name' => $a['teacher_name'], 'count' => 0, 'subjects' => []];
    }
    $teacherLoad[$a['teacher_id']]['count']++;
    $teacherLoad[$a['teacher_id']]['subjects'][] = $a['subject_name'];
}

// تغطية المواد - هل كل مادة لها معلم؟
$coveredSubjects = [];
$uncoveredSubjects = [];
foreach ($classes as $cl) {
    foreach ($subjects as $sub) {
        $covered = false;
        foreach ($assignments as $a) {
            if ($a['class_id'] == $cl['id'] && $a['subject_id'] == $sub['id']) {
                $covered = true;
                break;
            }
        }
        $key = $cl['grade_name'] . ' - ' . $cl['name_ar'] . ' / ' . $sub['name_ar'];
        if ($covered) {
            $coveredSubjects[] = $key;
        } else {
            $uncoveredSubjects[] = $key;
        }
    }
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">👨‍🏫 تعيين المعلمين</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('assign-form').style.display='block'">➕ تعيين جديد</button>
        </div>
        <div class="card-body">
            <!-- نموذج التعيين -->
            <div id="assign-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--primary);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="assign_teacher_subject">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">المعلم <span class="required">*</span></label>
                                    <select name="teacher_id" class="form-control" required>
                                        <option value="">اختر المعلم</option>
                                        <?php foreach ($teachers as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= e($t['full_name_ar']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">المادة <span class="required">*</span></label>
                                    <select name="subject_id" class="form-control" required>
                                        <option value="">اختر المادة</option>
                                        <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?> (<?= e($s['code']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">الفصل <span class="required">*</span></label>
                                    <select name="class_id" class="form-control" required>
                                        <option value="">اختر الفصل</option>
                                        <?php foreach ($classes as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"><?= e($cl['grade_name'] . ' - ' . $cl['name_ar']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary">💾 تعيين</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('assign-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- نظرة عامة على الحمل التدريسي -->
            <?php if (!empty($teacherLoad)): ?>
            <div class="card mb-4">
                <div class="card-header" style="background:var(--background);">
                    <h4 style="margin:0;">📊 نظرة عامة على الحمل التدريسي</h4>
                </div>
                <div class="card-body" style="padding:0;">
                    <table class="data-table">
                        <thead>
                            <tr><th>المعلم</th><th>عدد التعيينات</th><th>المواد</th><th>الحالة</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teacherLoad as $tid => $data):
                                $loadStatus = $data['count'] > 8 ? 'ثقيل' : ($data['count'] > 5 ? 'متوسط' : 'خفيف');
                                $loadColor = $data['count'] > 8 ? 'var(--danger)' : ($data['count'] > 5 ? 'var(--warning)' : 'var(--success)');
                            ?>
                            <tr>
                                <td><strong><?= e($data['name']) ?></strong></td>
                                <td><?= $data['count'] ?></td>
                                <td style="font-size:12px;"><?= implode('، ', array_unique($data['subjects'])) ?></td>
                                <td><span style="color:<?= $loadColor ?>;font-weight:600;"><?= $loadStatus ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- تغطية المواد -->
            <?php if (!empty($uncoveredSubjects)): ?>
            <div class="alert alert-warning">
                <strong>⚠️ مواد بدون معلم (<?= count($uncoveredSubjects) ?>):</strong>
                <div style="max-height:100px;overflow-y:auto;margin-top:4px;font-size:12px;">
                    <?= implode('، ', array_slice($uncoveredSubjects, 0, 20)) ?>
                    <?php if (count($uncoveredSubjects) > 20): ?> ... و<?= count($uncoveredSubjects) - 20 ?> أخرى<?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-success">
                <strong>✅ تغطية كاملة:</strong> جميع المواد لديها معلمون معينون.
            </div>
            <?php endif; ?>

            <!-- جدول التعيينات -->
            <?php if (!empty($assignments)): ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr><th>المعلم</th><th>المادة</th><th>الفصل</th><th>إجراء</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><strong><?= e($a['teacher_name']) ?></strong></td>
                            <td><?= e($a['subject_name']) ?></td>
                            <td><?= e($a['grade_name'] . ' - ' . $a['class_name']) ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('إلغاء التعيين؟');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_assignment">
                                    <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:2px 8px;font-size:11px;">❌ إلغاء</button>
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

<?php
require_once __DIR__ . '/../../includes/footer.php';
