<?php
/**
 * ============================================================
 * EQAS - إعداد الفصول والشعب
 * نظام التقييم الشامل - Class/Section Setup
 * ============================================================
 */

$pageTitle = 'إعداد الفصول والشعب';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'الفصول والشعب' => null];

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
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_class') {
            $name = trim($_POST['name_ar'] ?? '');
            $gradeLevelId = (int)$_POST['grade_level_id'];
            $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
            $capacity = (int)($_POST['capacity'] ?? 30);

            if (empty($name) || !$gradeLevelId) {
                setFlash('يرجى ملء جميع الحقول المطلوبة.', 'warning');
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO classes (name_ar, academic_year_id, grade_level_id, school_id, teacher_id, capacity, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$name, $academicYearId, $gradeLevelId, $schoolId, $teacherId, $capacity]);
                    logActivity($db, $_SESSION['user']['id'], 'setup_class_create', "إنشاء فصل: {$name}", 'classes', $db->lastInsertId());
                    setFlash('تم إنشاء الفصل بنجاح.', 'success');
                } catch (Exception $e) {
                    setFlash('خطأ في الإنشاء: ' . $e->getMessage(), 'danger');
                }
            }

        } elseif ($action === 'update_class') {
            $id = (int)$_POST['class_id'];
            $name = trim($_POST['name_ar'] ?? '');
            $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
            $capacity = (int)($_POST['capacity'] ?? 30);

            $stmt = $db->prepare("UPDATE classes SET name_ar = ?, teacher_id = ?, capacity = ? WHERE id = ?");
            $stmt->execute([$name, $teacherId, $capacity, $id]);
            logActivity($db, $_SESSION['user']['id'], 'setup_class_update', "تحديث فصل رقم {$id}", 'classes', $id);
            setFlash('تم تحديث الفصل بنجاح.', 'success');

        } elseif ($action === 'delete_class') {
            $id = (int)$_POST['class_id'];
            $stmt = $db->prepare("UPDATE classes SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم حذف الفصل.', 'success');

        } elseif ($action === 'bulk_create') {
            $gradeLevelId = (int)$_POST['grade_level_id'];
            $sectionCount = (int)($_POST['section_count'] ?? 1);
            $capacity = (int)($_POST['capacity'] ?? 30);
            $sectionNames = ['أ', 'ب', 'ج', 'د', 'هـ', 'و', 'ز', 'ح', 'ط', 'ي'];

            $stmt = $db->prepare("SELECT name_ar FROM grade_levels WHERE id = ?");
            $stmt->execute([$gradeLevelId]);
            $gradeName = $stmt->fetchColumn();

            $count = 0;
            for ($i = 0; $i < $sectionCount && $i < 10; $i++) {
                $className = $gradeName . ' / ' . $sectionNames[$i];
                try {
                    $stmt = $db->prepare("INSERT INTO classes (name_ar, academic_year_id, grade_level_id, school_id, capacity, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$className, $academicYearId, $gradeLevelId, $schoolId, $capacity]);
                    $count++;
                } catch (Exception $e) { /* تجاهل */ }
            }
            logActivity($db, $_SESSION['user']['id'], 'setup_class_bulk', "إنشاء {$count} فصل جماعياً", 'classes', null);
            setFlash("تم إنشاء {$count} فصل بنجاح.", 'success');

        } elseif ($action === 'assign_teacher') {
            $classId = (int)$_POST['class_id'];
            $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
            $stmt = $db->prepare("UPDATE classes SET teacher_id = ? WHERE id = ?");
            $stmt->execute([$teacherId, $classId]);
            setFlash('تم تعيين المعلم المشرف.', 'success');
        }

        redirect('sections.php');
    }
}

// جلب البيانات
$gradeLevels = $db->query("SELECT id, name_ar, level_number FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();
$teachers = $db->query("SELECT u.id, u.full_name_ar FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.is_active = 1 ORDER BY u.full_name_ar")->fetchAll();

// الفصول الحالية
$stmt = $db->prepare("
    SELECT c.*, gl.name_ar as grade_name, gl.level_number,
           u.full_name_ar as teacher_name,
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1 AND se.status = 'enrolled') as student_count
    FROM classes c
    JOIN grade_levels gl ON c.grade_level_id = gl.id
    LEFT JOIN users u ON c.teacher_id = u.id
    WHERE c.is_active = 1 AND c.academic_year_id = ?
    ORDER BY gl.level_number, c.name_ar
");
$stmt->execute([$academicYearId]);
$classes = $stmt->fetchAll();

// تجميع حسب الصف
$byGrade = [];
foreach ($classes as $c) {
    $byGrade[$c['grade_level_id']]['grade_name'] = $c['grade_name'];
    $byGrade[$c['grade_level_id']]['classes'][] = $c;
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h2 class="card-title">🏫 إعداد الفصول والشعب</h2>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-form').style.display='block'">➕ فصل جديد</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('bulk-form').style.display='block'">📦 إنشاء جماعي</button>
            </div>
        </div>
        <div class="card-body">
            <!-- نموذج إنشاء فصل -->
            <div id="create-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--primary);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_class">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">اسم الفصل <span class="required">*</span></label>
                                    <input type="text" name="name_ar" class="form-control" placeholder="مثال: الأول / أ" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">الصف الدراسي <span class="required">*</span></label>
                                    <select name="grade_level_id" class="form-control" required>
                                        <option value="">اختر الصف</option>
                                        <?php foreach ($gradeLevels as $gl): ?>
                                        <option value="<?= $gl['id'] ?>"><?= e($gl['name_ar']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">المعلم المشرف</label>
                                    <select name="teacher_id" class="form-control">
                                        <option value="">بدون مشرف</option>
                                        <?php foreach ($teachers as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= e($t['full_name_ar']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">السعة</label>
                                    <input type="number" name="capacity" class="form-control" value="30" min="5" max="60">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary">💾 إنشاء</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('create-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- إنشاء جماعي -->
            <div id="bulk-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px dashed var(--secondary);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="bulk_create">
                        <div class="card-body">
                            <h4 style="margin-bottom:12px;">📦 إنشاء فصول جماعياً لصف دراسي</h4>
                            <div class="form-row">
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
                                    <label class="form-label">عدد الشعب</label>
                                    <input type="number" name="section_count" class="form-control" value="3" min="1" max="10">
                                </div>
                                <div class="form-group" style="max-width:120px;">
                                    <label class="form-label">سعة كل فصل</label>
                                    <input type="number" name="capacity" class="form-control" value="30" min="5" max="60">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-secondary">📦 إنشاء جماعي</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('bulk-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- عرض الفصول الحالية -->
            <?php if (empty($byGrade)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">🏫</div>
                <p>لم يتم إنشاء أي فصول بعد لهذا العام الدراسي.</p>
            </div>
            <?php else: ?>
            <?php foreach ($byGrade as $gid => $gradeData): ?>
            <div class="card mb-4">
                <div class="card-header" style="background:var(--background);">
                    <h4 style="margin:0;">📖 <?= e($gradeData['grade_name']) ?> (<?= count($gradeData['classes']) ?> شعبة)</h4>
                </div>
                <div class="card-body" style="padding:0;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>اسم الفصل</th>
                                <th>المعلم المشرف</th>
                                <th>السعة</th>
                                <th>عدد الطلاب</th>
                                <th>النسبة</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gradeData['classes'] as $cl):
                                $fillPercent = $cl['capacity'] > 0 ? round(($cl['student_count'] / $cl['capacity']) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= e($cl['name_ar']) ?></strong></td>
                                <td><?= e($cl['teacher_name'] ?? 'غير معين') ?></td>
                                <td><?= $cl['capacity'] ?></td>
                                <td><?= $cl['student_count'] ?></td>
                                <td>
                                    <div style="width:80px;height:6px;background:var(--border-light);border-radius:var(--radius-full);display:inline-block;vertical-align:middle;">
                                        <div style="width:<?= min(100, $fillPercent) ?>%;height:100%;background:<?= $fillPercent > 90 ? 'var(--danger)' : ($fillPercent > 70 ? 'var(--warning)' : 'var(--success)') ?>;border-radius:var(--radius-full);"></div>
                                    </div>
                                    <span style="font-size:11px;margin-right:4px;"><?= $fillPercent ?>%</span>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذا الفصل؟');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_class">
                                        <input type="hidden" name="class_id" value="<?= $cl['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:2px 8px;font-size:11px;">🗑️</button>
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
