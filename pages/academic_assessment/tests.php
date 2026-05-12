<?php
/**
 * ============================================================
 * EQAS - إدارة الاختبارات
 * نظام التقييم الشامل - إنشاء وتعديل ونشر وقفل الاختبارات
 * ============================================================
 * يتضمن:
 * - قائمة الاختبارات مع فلاتر (فصل، مادة، نوع، حالة)
 * - إنشاء اختبار جديد
 * - تعديل اختبار (إذا كان مسودة)
 * - نشر اختبار (يقفل الأوزان)
 * - قفل اختبار (يمنع تغيير الدرجات)
 * - عرض ملخص نتائج الاختبار
 * ============================================================
 */

$pageTitle = 'إدارة الاختبارات';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'إدارة الاختبارات' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$academicYearId = getCurrentAcademicYearId($db);
$schoolId = $_SESSION['user']['school_id'] ?? 0;

// ============================================================
// معالجة الإجراءات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح', 'danger');
        redirect('tests.php');
    }

    $action = $_POST['action'] ?? '';

    // إنشاء اختبار جديد
    if ($action === 'create') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $assessmentTypeId = (int)($_POST['assessment_type_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);
        $titleAr = trim($_POST['title_ar'] ?? '');
        $maxGrade = (float)($_POST['max_grade'] ?? 100);
        $testDate = trim($_POST['test_date'] ?? '');

        if (empty($titleAr) || $subjectId <= 0 || $classId <= 0 || $assessmentTypeId <= 0 || $semesterId <= 0) {
            setFlash('جميع الحقول مطلوبة', 'danger');
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO tests (subject_id, class_id, assessment_type_id, academic_year_id, semester_id, title_ar, max_grade, test_date, status, created_by, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, 1)
                ");
                $stmt->execute([
                    $subjectId, $classId, $assessmentTypeId, $academicYearId, $semesterId,
                    $titleAr, $maxGrade, $testDate ?: null, $_SESSION['user']['id'] ?? 0
                ]);
                setFlash('تم إنشاء الاختبار بنجاح', 'success');
                logActivity($db, $_SESSION['user']['id'] ?? 0, 'create_test', "إنشاء اختبار: {$titleAr}", 'test', $db->lastInsertId());
            } catch (Exception $e) {
                setFlash('حدث خطأ: ' . $e->getMessage(), 'danger');
            }
        }
        redirect('tests.php');
    }

    // تعديل اختبار
    if ($action === 'update') {
        $testId = (int)($_POST['test_id'] ?? 0);
        $titleAr = trim($_POST['title_ar'] ?? '');
        $maxGrade = (float)($_POST['max_grade'] ?? 100);
        $testDate = trim($_POST['test_date'] ?? '');

        if ($testId <= 0 || empty($titleAr)) {
            setFlash('بيانات غير مكتملة', 'danger');
        } else {
            // التحقق من حالة الاختبار
            $stmt = $db->prepare("SELECT status FROM tests WHERE id = ? AND is_active = 1");
            $stmt->execute([$testId]);
            $test = $stmt->fetch();

            if ($test && $test['status'] === 'draft') {
                try {
                    $stmt = $db->prepare("UPDATE tests SET title_ar = ?, max_grade = ?, test_date = ? WHERE id = ?");
                    $stmt->execute([$titleAr, $maxGrade, $testDate ?: null, $testId]);
                    setFlash('تم تعديل الاختبار بنجاح', 'success');
                } catch (Exception $e) {
                    setFlash('حدث خطأ أثناء التعديل', 'danger');
                }
            } else {
                setFlash('لا يمكن تعديل اختبار منشور أو مقفل', 'danger');
            }
        }
        redirect('tests.php');
    }

    // نشر اختبار
    if ($action === 'publish') {
        $testId = (int)($_POST['test_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE tests SET status = 'published' WHERE id = ? AND status = 'draft'");
            $stmt->execute([$testId]);
            if ($stmt->rowCount() > 0) {
                setFlash('تم نشر الاختبار بنجاح - تم قفل الإعدادات', 'success');
                logActivity($db, $_SESSION['user']['id'] ?? 0, 'publish_test', "نشر اختبار", 'test', $testId);
            } else {
                setFlash('لا يمكن نشر هذا الاختبار', 'warning');
            }
        } catch (Exception $e) {
            setFlash('حدث خطأ', 'danger');
        }
        redirect('tests.php');
    }

    // قفل اختبار
    if ($action === 'lock') {
        $testId = (int)($_POST['test_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE tests SET status = 'locked' WHERE id = ? AND status IN ('draft', 'published')");
            $stmt->execute([$testId]);
            if ($stmt->rowCount() > 0) {
                setFlash('تم قفل الاختبار بنجاح - لا يمكن تعديل الدرجات', 'success');
                logActivity($db, $_SESSION['user']['id'] ?? 0, 'lock_test', "قفل اختبار", 'test', $testId);
            } else {
                setFlash('لا يمكن قفل هذا الاختبار', 'warning');
            }
        } catch (Exception $e) {
            setFlash('حدث خطأ', 'danger');
        }
        redirect('tests.php');
    }
}

// ============================================================
// جلب البيانات مع فلاتر
// ============================================================
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterType = (int)($_GET['type_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterSemester = (int)($_GET['semester_id'] ?? 0);

// بناء الاستعلام
$where = ["t.academic_year_id = ?"];
$params = [$academicYearId];

if ($schoolId) {
    $where[] = "c.school_id = ?";
    $params[] = $schoolId;
}
if ($filterClass) { $where[] = "t.class_id = ?"; $params[] = $filterClass; }
if ($filterSubject) { $where[] = "t.subject_id = ?"; $params[] = $filterSubject; }
if ($filterType) { $where[] = "t.assessment_type_id = ?"; $params[] = $filterType; }
if ($filterStatus) { $where[] = "t.status = ?"; $params[] = $filterStatus; }
if ($filterSemester) { $where[] = "t.semester_id = ?"; $params[] = $filterSemester; }

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT t.*, sub.name_ar AS subject_name, sub.code AS subject_code,
           c.name_ar AS class_name, at.name_ar AS type_name, at.code AS type_code,
           sem.name_ar AS semester_name,
           (SELECT COUNT(*) FROM grades g WHERE g.test_id = t.id AND g.is_active = 1) AS grade_count,
           (SELECT ROUND(AVG(g.percentage), 1) FROM grades g WHERE g.test_id = t.id AND g.is_active = 1) AS avg_percentage,
           u.full_name_ar AS creator_name
    FROM tests t
    INNER JOIN subjects sub ON sub.id = t.subject_id
    INNER JOIN classes c ON c.id = t.class_id
    INNER JOIN assessment_types at ON at.id = t.assessment_type_id
    INNER JOIN semesters sem ON sem.id = t.semester_id
    LEFT JOIN users u ON u.id = t.created_by
    WHERE {$whereClause} AND t.is_active = 1
    ORDER BY t.test_date DESC, t.id DESC
");
$stmt->execute($params);
$testsList = $stmt->fetchAll();

// جلب بيانات الفلاتر
$classes = [];
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar AS grade_name
        FROM classes c INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
        WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
        ORDER BY gl.level_number
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classes = $stmt->fetchAll();
}

$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
$assessmentTypes = $db->query("SELECT id, name_ar, code FROM assessment_types WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
$semesters = [];
$stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1");
$stmt->execute([$academicYearId]);
$semesters = $stmt->fetchAll();
?>

<!-- ============================================================
     فلاتر البحث
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">🔍 فلترة الاختبارات</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="tests.php">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= $filterSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الشعبة</label>
                    <select name="class_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $filterClass == $cls['id'] ? 'selected' : '' ?>><?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">المادة</label>
                    <select name="subject_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>" <?= $filterSubject == $sub['id'] ? 'selected' : '' ?>><?= e($sub['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">نوع التقييم</label>
                    <select name="type_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($assessmentTypes as $at): ?>
                            <option value="<?= $at['id'] ?>" <?= $filterType == $at['id'] ? 'selected' : '' ?>><?= e($at['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة</label>
                    <select name="status" class="form-control">
                        <option value="">الكل</option>
                        <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>مسودة</option>
                        <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>منشور</option>
                        <option value="locked" <?= $filterStatus === 'locked' ? 'selected' : '' ?>>مقفل</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 تطبيق</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     قائمة الاختبارات
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">📝 الاختبارات (<?= count($testsList) ?>)</h3>
            <button type="button" class="btn btn-success" onclick="document.getElementById('createTestForm').style.display='block'">
                ➕ إنشاء اختبار جديد
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- نموذج إنشاء اختبار -->
        <div id="createTestForm" style="display:none; margin-bottom:var(--space-6); padding:var(--space-4); background:var(--background); border-radius:var(--radius-md); border:1px dashed var(--border-dark);">
            <h4 style="margin-bottom:var(--space-4);">➕ إنشاء اختبار جديد</h4>
            <form method="POST" action="tests.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">عنوان الاختبار <span class="required">*</span></label>
                        <input type="text" name="title_ar" class="form-control" required placeholder="مثال: اختبار نهاية الفصل">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الشعبة <span class="required">*</span></label>
                        <select name="class_id" class="form-control" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>"><?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المادة <span class="required">*</span></label>
                        <select name="subject_id" class="form-control" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= e($sub['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">نوع التقييم <span class="required">*</span></label>
                        <select name="assessment_type_id" class="form-control" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($assessmentTypes as $at): ?>
                                <option value="<?= $at['id'] ?>"><?= e($at['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">-- اختر --</option>
                            <?php foreach ($semesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>"><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الدرجة القصوى <span class="required">*</span></label>
                        <input type="number" name="max_grade" class="form-control" value="100" min="1" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">تاريخ الاختبار</label>
                        <input type="date" name="test_date" class="form-control">
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-success">💾 إنشاء</button>
                    <button type="button" class="btn btn-light" onclick="document.getElementById('createTestForm').style.display='none'">إلغاء</button>
                </div>
            </form>
        </div>

        <!-- جدول الاختبارات -->
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان الاختبار</th>
                        <th>المادة</th>
                        <th>الشعبة</th>
                        <th>النوع</th>
                        <th>الدرجة القصوى</th>
                        <th>التاريخ</th>
                        <th>الحالة</th>
                        <th>الدرجات</th>
                        <th>المتوسط</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($testsList)): ?>
                        <tr><td colspan="11" class="table-empty"><div class="table-empty-icon">📝</div><div class="table-empty-text">لا توجد اختبارات</div></td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($testsList as $tst): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><strong><?= e($tst['title_ar']) ?></strong></td>
                                <td><?= e($tst['subject_name']) ?></td>
                                <td><?= e($tst['class_name']) ?></td>
                                <td><?= e($tst['type_name']) ?></td>
                                <td class="text-center"><?= $tst['max_grade'] ?></td>
                                <td><?= $tst['test_date'] ? formatDateAr($tst['test_date'], 'short') : '-' ?></td>
                                <td>
                                    <?php
                                    $sMap = [
                                        'draft' => '<span class="badge badge-secondary">مسودة</span>',
                                        'published' => '<span class="badge badge-info">منشور</span>',
                                        'locked' => '<span class="badge badge-danger">مقفل 🔒</span>'
                                    ];
                                    echo $sMap[$tst['status']] ?? e($tst['status']);
                                    ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $tst['grade_count'] > 0 ? 'success' : 'warning' ?>"><?= $tst['grade_count'] ?: 0 ?></span>
                                </td>
                                <td class="text-center"><?= $tst['avg_percentage'] ? $tst['avg_percentage'] . '%' : '-' ?></td>
                                <td class="actions">
                                    <a href="grade_entry.php?test_id=<?= $tst['id'] ?>" class="btn btn-sm btn-primary" title="إدخال درجات">✏️</a>
                                    <?php if ($tst['status'] === 'draft'): ?>
                                        <form method="POST" action="tests.php" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="publish">
                                            <input type="hidden" name="test_id" value="<?= $tst['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-info" title="نشر" onclick="return confirm('هل تريد نشر هذا الاختبار؟')">📢</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($tst['status'] !== 'locked'): ?>
                                        <form method="POST" action="tests.php" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="lock">
                                            <input type="hidden" name="test_id" value="<?= $tst['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="قفل" onclick="return confirm('⚠️ هل تريد قفل الاختبار؟ لا يمكن التراجع.')">🔒</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
