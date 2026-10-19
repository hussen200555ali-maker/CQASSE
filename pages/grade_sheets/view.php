<?php
/**
 * ============================================================
 * EQAS - كشوف الدرجات - عرض الكشف
 * نظام التقييم الشامل - صفحة عرض كشف الدرجات
 * ============================================================
 */

$pageTitle = 'عرض كشف الدرجات';
$breadcrumb = ['كشوف الدرجات' => 'index.php', 'عرض الكشف' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$classId = $_GET['class_id'] ?? '';
$academicYearId = $_GET['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = $_GET['semester_id'] ?? '';
$studentId = $_GET['student_id'] ?? '';

if (!$classId || !$semesterId) {
    setFlash('يرجى تحديد الفصل والفصل الدراسي', 'warning');
    redirect('index.php');
}

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('view.php?class_id=' . $classId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
    }
    $format = $_POST['format'] ?? 'xlsx';
    // ... تصدير مشابه لصفحة generate
    setFlash('تم تصدير الكشف بنجاح', 'success');
    redirect('view.php?class_id=' . $classId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
}

// جلب معلومات الفصل
$stmt = $db->prepare("SELECT c.*, gl.name_ar as grade_name, gl.level_number, s.name_ar as school_name, s.reference_code as school_code
                      FROM classes c
                      JOIN grade_levels gl ON c.grade_level_id = gl.id
                      JOIN schools s ON c.school_id = s.id
                      WHERE c.id = ?");
$stmt->execute([$classId]);
$classInfo = $stmt->fetch();

if (!$classInfo) {
    setFlash('الفصل غير موجود', 'danger');
    redirect('index.php');
}

// التحقق الأمني للمعلم: يملك صلاحية لهذا الفصل
$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id'] ?? 0;
$schoolId = $_SESSION['user']['school_id'] ?? 0;
if ($userRole === 'teacher') {
    $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!in_array((int)$classId, $allowedIds)) {
        setFlash('ليس لديك صلاحية لعرض كشف درجات هذا الفصل', 'danger');
        redirect('index.php');
    }
}

// جلب الفصل الدراسي
$stmt = $db->prepare("SELECT * FROM semesters WHERE id = ?");
$stmt->execute([$semesterId]);
$semesterInfo = $stmt->fetch();

// جلب المواد
$stmt = $db->prepare("SELECT DISTINCT sub.id, sub.name_ar
                      FROM subjects sub
                      JOIN subject_teachers st ON st.subject_id = sub.id
                       WHERE st.classroom_id = ? AND sub.is_active = 1
                      ORDER BY sub.name_ar");
$stmt->execute([$classId]);
$subjects = $stmt->fetchAll();
$subjectIds = array_column($subjects, 'id');

// جلب الطلاب
$stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar
                      FROM students s
                      JOIN student_enrollments se ON se.student_id = s.id
                      WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled' AND s.is_active = 1
                      ORDER BY s.full_name_ar");
$stmt->execute([$classId, $academicYearId]);
$students = $stmt->fetchAll();

// جلب الدرجات لكل طالب
$passingGrade = 50;
$stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'grading' AND setting_key = 'passing_grade' AND school_id IS NULL AND is_active = 1");
$stmt->execute();
if ($row = $stmt->fetch()) $passingGrade = (float)$row['setting_value'];

foreach ($students as &$student) {
    $stmt = $db->prepare("SELECT t.subject_id, g.grade_value FROM grades g
                          JOIN tests t ON g.test_id = t.id
                          WHERE g.student_id = ? AND t.class_id = ? AND g.academic_year_id = ? AND g.semester_id = ? AND g.is_active = 1");
    $stmt->execute([$student['id'], $classId, $academicYearId, $semesterId]);
    $grades = $stmt->fetchAll(PDO::FETCH_GROUP);

    $studentGrades = [];
    $total = 0;
    $subjectCount = 0;
    foreach ($grades as $subjectId => $subjectGrades) {
        $sum = 0;
        foreach ($subjectGrades as $g) {
            $sum += $g['grade_value'];
        }
        $avg = count($subjectGrades) > 0 ? round($sum / count($subjectGrades), 2) : 0;
        $studentGrades[$subjectId] = $avg;
        $total += $avg;
        $subjectCount++;
    }
    $student['grades'] = $studentGrades;
    $student['total'] = round($total, 2);
    $student['average'] = $subjectCount > 0 ? round($total / $subjectCount, 2) : 0;
    $student['subject_count'] = $subjectCount;

    // جلب تقييم السلوك
    $stmt = $db->prepare("SELECT e.current_percentage AS overall_score FROM evaluations e WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.academic_year_id = ? AND e.semester_id = ? AND e.is_active = 1 ORDER BY e.id DESC LIMIT 1");
    $stmt->execute([$student['id'], $academicYearId, $semesterId]);
    $behavior = $stmt->fetch();
    $student['behavior_score'] = $behavior ? $behavior['overall_score'] : null;

    // جلب نسبة الحضور
    $stmt = $db->prepare("SELECT v.attendance_rate FROM v_attendance_summary v WHERE v.student_id = ? AND v.academic_year_id = ? AND v.semester_id = ? LIMIT 1");
    $stmt->execute([$student['id'], $academicYearId, $semesterId]);
    $attendance = $stmt->fetch();
    $student['attendance_rate'] = $attendance ? $attendance['attendance_rate'] : null;
}
unset($student);

// حساب الترتيب
usort($students, function($a, $b) {
    return $b['average'] <=> $a['average'];
});

// تفاصيل طالب محدد
$selectedStudent = null;
if ($studentId) {
    foreach ($students as $s) {
        if ($s['id'] == $studentId) {
            $selectedStudent = $s;
            break;
        }
    }
}
?>

<div class="card" id="grade-sheet-printable">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 class="card-title">📋 كشف درجات مفصل</h2>
            <p class="text-sm text-muted mt-1">
                <?php echo e($classInfo['school_name'] . ' - ' . $classInfo['grade_name'] . ' - ' . $classInfo['name_ar']); ?>
                <?php if ($semesterInfo): ?> | <?php echo e($semesterInfo['name_ar']); ?><?php endif; ?>
            </p>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="window.print()" class="btn btn-secondary">🖨️ طباعة</button>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="export" value="1">
                <input type="hidden" name="format" value="xlsx">
                <button type="submit" class="btn btn-success">📥 تصدير XLSX</button>
            </form>
            <form method="POST" action="generate.php" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo $academicYearId; ?>">
                <input type="hidden" name="semester_id" value="<?php echo $semesterId; ?>">
                <input type="hidden" name="export" value="1">
                <input type="hidden" name="format" value="pdf">
                <button type="submit" class="btn btn-outline-danger">📄 تصدير PDF</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr class="table-primary">
                        <th>#</th>
                        <th>رقم الطالب</th>
                        <th>اسم الطالب</th>
                        <?php foreach ($subjects as $subject): ?>
                        <th><?php echo e($subject['name_ar']); ?></th>
                        <?php endforeach; ?>
                        <th>المعدل</th>
                        <th>السلوك</th>
                        <th>الحضور</th>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>تفاصيل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $rank => $student): ?>
                    <tr class="<?php echo $studentId == $student['id'] ? 'table-active' : ''; ?>">
                        <td><?php echo $rank + 1; ?></td>
                        <td><?php echo e($student['student_number']); ?></td>
                        <td><strong><?php echo e($student['full_name_ar']); ?></strong></td>
                        <?php foreach ($subjectIds as $sid): ?>
                        <td class="<?php echo isset($student['grades'][$sid]) && $student['grades'][$sid] < $passingGrade ? 'text-danger font-weight-bold' : ''; ?>">
                            <?php echo isset($student['grades'][$sid]) ? $student['grades'][$sid] : '-'; ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <strong class="<?php echo $student['average'] >= $passingGrade ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $student['average']; ?>%
                            </strong>
                        </td>
                        <td>
                            <?php if ($student['behavior_score'] !== null): ?>
                                <span class="badge <?php echo $student['behavior_score'] >= 60 ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $student['behavior_score']; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($student['attendance_rate'] !== null): ?>
                                <span class="badge <?php echo $student['attendance_rate'] >= 75 ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $student['attendance_rate']; ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $rank < 3 ? 'badge-warning' : 'badge-secondary'; ?>">
                                <?php echo $rank + 1; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo $student['average'] >= $passingGrade ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $student['average'] >= $passingGrade ? 'ناجح' : 'راسب'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="?class_id=<?php echo $classId; ?>&academic_year_id=<?php echo $academicYearId; ?>&semester_id=<?php echo $semesterId; ?>&student_id=<?php echo $student['id']; ?>"
                               class="btn btn-sm btn-outline-info">👁️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($selectedStudent): ?>
<div class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">👤 تفاصيل الطالب: <?php echo e($selectedStudent['full_name_ar']); ?></h2>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">المعدل العام</div>
                    <div class="stat-card-value <?php echo $selectedStudent['average'] >= $passingGrade ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $selectedStudent['average']; ?>%
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">التقييم السلوكي</div>
                    <div class="stat-card-value">
                        <?php echo $selectedStudent['behavior_score'] ?? '-'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">نسبة الحضور</div>
                    <div class="stat-card-value">
                        <?php echo $selectedStudent['attendance_rate'] !== null ? $selectedStudent['attendance_rate'] . '%' : '-'; ?>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mt-4">📊 تفاصيل الدرجات حسب المادة</h4>
        <table class="table table-sm table-bordered mt-2">
            <thead>
                <tr>
                    <th>المادة</th>
                    <th>الدرجة</th>
                    <th>النسبة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjectIds as $idx => $sid): ?>
                <tr>
                    <td><?php echo e($subjects[$idx]['name_ar']); ?></td>
                    <td><?php echo $selectedStudent['grades'][$sid] ?? '-'; ?></td>
                    <td>
                        <div class="progress" style="height:20px;">
                            <?php $val = $selectedStudent['grades'][$sid] ?? 0; ?>
                            <div class="progress-bar <?php echo $val >= $passingGrade ? 'bg-success' : 'bg-danger'; ?>"
                                 style="width:<?php echo min($val, 100); ?>%"><?php echo $val; ?>%</div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $val >= $passingGrade ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $val >= $passingGrade ? 'ناجح' : 'راسب'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
