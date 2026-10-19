<?php
/**
 * ============================================================
 * EQAS - إدخال الدرجات
 * نظام التقييم الشامل - صفحة إدخال درجات الطلاب
 * ============================================================
 * أهم صفحة في النظام - حيث يقوم المعلمون بإدخال الدرجات
 * يتضمن: اختيار الفصل والمادة والاختبار، إدخال الدرجات،
 * حساب النسبة تلقائياً، عرض التقدير الحرفي، حفظ الدفعات
 * ============================================================
 */

$pageTitle = 'إدخال الدرجات';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'إدخال الدرجات' => null
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/academic_calculator.php';

// التحقق من الصلاحيات - المعلمون ومديرو المدارس فأعلى
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$calculator = new AcademicCalculator($db);
$academicYearId = getCurrentAcademicYearId($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id'] ?? 0;
$schoolId = $_SESSION['user']['school_id'] ?? 0;
$currentSemesterId = currentSemesterId($academicYearId);
// Debug: Show session and computed values on screen (temporary)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo "<div style='background:#fff3cd; border:1px solid #ffeaa7; padding:10px; margin:10px 0;'>";
    echo "<strong>DEBUG grade_entry.php:</strong> ";
    echo "userId={$userId}, role={$userRole}, schoolId={$schoolId}, academicYearId={$academicYearId}";
    echo "</div>";
    
    // Show session contents
    echo "<div style='background:#e2f3ff; border:1px solid #bee3f8; padding:10px; margin:10px 0;'>";
    echo "<strong>SESSION Contents:</strong><br>";
    if (isset($_SESSION['user'])) {
        echo "user: ".htmlspecialchars(print_r($_SESSION['user'], true))."<br>";
    }
    if (isset($_SESSION['academic_year_id'])) {
        echo "academic_year_id: ".$_SESSION['academic_year_id']."<br>";
    }
    if (isset($_SESSION['current_semester_id'])) {
        echo "current_semester_id: ".$_SESSION['current_semester_id']."<br>";
    }
    echo "</div>";
}

// Always show basic debug info in comments for inspection
// DEBUG: userId={$userId}, role={$userRole}, schoolId={$schoolId}, academicYearId={$academicYearId}

// ============================================================
// معالجة إنشاء اختبار جديد
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح، يرجى المحاولة مرة أخرى', 'danger');
        redirect('grade_entry.php');
    }

    // --------------------------------------------------------
    // إنشاء اختبار جديد
    // --------------------------------------------------------
    if ($_POST['action'] === 'create_test') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $assessmentTypeId = (int)($_POST['assessment_type_id'] ?? 0);
        $titleAr = trim($_POST['title_ar'] ?? '');
        $maxGrade = (float)($_POST['max_grade'] ?? 100);
        $testDate = trim($_POST['test_date'] ?? '');
        $semesterId = (int)($_POST['semester_id'] ?? 0);

        // التحقق من البيانات
        $errors = [];
        if (empty($titleAr)) $errors[] = 'عنوان الاختبار مطلوب';
        if ($subjectId <= 0) $errors[] = 'يرجى اختيار المادة';
        if ($classId <= 0) $errors[] = 'يرجى اختيار الفصل';
        if ($assessmentTypeId <= 0) $errors[] = 'يرجى اختيار نوع التقييم';
        if ($maxGrade <= 0) $errors[] = 'الدرجة القصوى يجب أن تكون أكبر من صفر';
        if ($semesterId <= 0) $errors[] = 'يرجى اختيار الفصل الدراسي';

        // التحقق من أن المعلم يملك صلاحية لهذه المادة والفصل
        if ($userRole === 'teacher' && empty($errors)) {
            $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!in_array($classId, $allowedIds)) {
                $errors[] = 'ليس لديك صلاحية لإنشاء اختبار في هذا الفصل';
            }
        }

        // المعلم: يسمح فقط بإنشاء اختبار في الفصل الحالي
        if ($userRole === 'teacher' && empty($errors)) {
            $currentSemesterId = currentSemesterId($academicYearId);
            if ($currentSemesterId && $semesterId !== (int)$currentSemesterId) {
                $errors[] = 'يمكنك إنشاء اختبار فقط في الفصل الدراسي الحالي';
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO tests (subject_id, class_id, assessment_type_id, academic_year_id, semester_id, title_ar, max_grade, test_date, status, created_by, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, 1)
                ");
                $stmt->execute([
                    $subjectId, $classId, $assessmentTypeId, $academicYearId, $semesterId,
                    $titleAr, $maxGrade, $testDate ?: null, $_SESSION['user']['id'] ?? 0
                ]);
                $newTestId = $db->lastInsertId();
                setFlash('تم إنشاء الاختبار بنجاح، يمكنك الآن إدخال الدرجات', 'success');
                logActivity($db, $_SESSION['user']['id'] ?? 0, 'create_test', 'إنشاء اختبار: ' . $titleAr, 'test', $newTestId);
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء إنشاء الاختبار: ' . $e->getMessage(), 'danger');
            }
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }

    // --------------------------------------------------------
    // حفظ الدرجات (حفظ دفعي)
    // --------------------------------------------------------
    if ($_POST['action'] === 'save_grades') {
        $testId = (int)($_POST['test_id'] ?? 0);
        $grades = $_POST['grades'] ?? []; // مصفوفة [student_id => grade_value]

        if ($testId <= 0) {
            setFlash('يرجى اختيار الاختبار', 'danger');
            redirect('grade_entry.php');
        }

        // جلب بيانات الاختبار
        $stmt = $db->prepare("
            SELECT t.*, at.code AS type_code, at.name_ar AS type_name
            FROM tests t
            INNER JOIN assessment_types at ON at.id = t.assessment_type_id
            WHERE t.id = ? AND t.is_active = 1
        ");
        $stmt->execute([$testId]);
        $test = $stmt->fetch();

        if (!$test) {
            setFlash('الاختبار غير موجود', 'danger');
            redirect('grade_entry.php');
        }

        // التحقق من أن المعلم يملك صلاحية لهذا الفصل والمادة
        if ($userRole === 'teacher') {
            $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!in_array((int)$test['class_id'], $allowedIds)) {
                setFlash('ليس لديك صلاحية لتسجيل درجات لهذا الاختبار', 'danger');
                redirect('grade_entry.php');
            }
        }

        // التحقق من أن الاختبار غير مقفل
        if ($test['status'] === 'locked') {
            setFlash('لا يمكن تعديل الدرجات لاختبار مقفل', 'danger');
            redirect('grade_entry.php?test_id=' . $testId);
        }

        // المعلم: يسمح فقط بتسجيل درجات في الفصل الحالي
        if ($userRole === 'teacher') {
            $currentSemesterId = currentSemesterId($academicYearId);
            if ($currentSemesterId && (int)$test['semester_id'] !== (int)$currentSemesterId) {
                setFlash('يمكنك تسجيل الدرجات فقط في الفصل الدراسي الحالي', 'danger');
                redirect('grade_entry.php?test_id=' . $testId);
            }
        }

        try {
            $db->beginTransaction();
            $savedCount = 0;
            $updatedCount = 0;

            foreach ($grades as $studentId => $gradeValue) {
                $studentId = (int)$studentId;
                $gradeValue = (float)$gradeValue;
                $maxGrade = (float)$test['max_grade'];

                // التحقق من أن الدرجة لا تتجاوز الحد الأقصى
                if ($gradeValue < 0) continue;
                if ($gradeValue > $maxGrade) {
                    $gradeValue = $maxGrade; // تصحيح تلقائي
                }

                // حساب النسبة المئوية
                $percentage = $maxGrade > 0 ? round(($gradeValue / $maxGrade) * 100, 2) : 0;

                // جلب التقدير الحرفي
                $letterInfo = $calculator->getLetterGrade($percentage);
                $letterGrade = $letterInfo['letter'];

                // التحقق من وجود درجة سابقة
                $stmt = $db->prepare("
                    SELECT id, grade_value FROM grades 
                    WHERE student_id = ? AND test_id = ? AND is_active = 1
                ");
                $stmt->execute([$studentId, $testId]);
                $existingGrade = $stmt->fetch();

                if ($existingGrade) {
                    // تحديث الدرجة الموجودة
                    $stmt = $db->prepare("
                        UPDATE grades SET grade_value = ?, max_value = ?, percentage = ?, letter_grade = ?, graded_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$gradeValue, $maxGrade, $percentage, $letterGrade, $_SESSION['user']['id'] ?? 0, $existingGrade['id']]);
                    $updatedCount++;
                } else {
                    // إدخال درجة جديدة
                    $stmt = $db->prepare("
                        INSERT INTO grades (student_id, test_id, grade_value, max_value, percentage, letter_grade, graded_by, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$studentId, $testId, $gradeValue, $maxGrade, $percentage, $letterGrade, $_SESSION['user']['id'] ?? 0]);
                    $savedCount++;
                }
            }

            $db->commit();
            $msg = '';
            if ($savedCount > 0) $msg .= "تم إدخال {$savedCount} درجة جديدة. ";
            if ($updatedCount > 0) $msg .= "تم تحديث {$updatedCount} درجة. ";
            if (empty($msg)) $msg = 'لم يتم حفظ أي درجات';
            setFlash($msg, $savedCount > 0 || $updatedCount > 0 ? 'success' : 'warning');
            logActivity($db, $_SESSION['user']['id'] ?? 0, 'save_grades', "حفظ درجات اختبار: {$test['title_ar']}", 'test', $testId);
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('حدث خطأ أثناء حفظ الدرجات: ' . $e->getMessage(), 'danger');
        }

        redirect('grade_entry.php?test_id=' . $testId);
    }
}

// ============================================================
// جلب البيانات للعرض
// ============================================================

// جلب الفصول المتاحة — للمعلم: فقط فصوله المسندة إليه
$classes = [];
if ($schoolId) {
    if ($userRole === 'teacher') {
        $stmt = $db->prepare("
            SELECT DISTINCT c.id, c.name_ar, gl.name_ar AS grade_name, gl.stage
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            JOIN subject_teachers st ON st.classroom_id = c.id AND st.teacher_id = ? AND st.is_active = 1
            WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
            UNION
            SELECT DISTINCT c.id, c.name_ar, gl.name_ar AS grade_name, gl.stage
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            WHERE c.teacher_id = ? AND c.is_active = 1 AND c.school_id = ? AND c.academic_year_id = ?
            ORDER BY grade_name, name_ar
        ");
        $stmt->execute([$userId, $schoolId, $academicYearId, $userId, $schoolId, $academicYearId]);
    } else {
        $stmt = $db->prepare("
            SELECT c.id, c.name_ar, gl.name_ar AS grade_name, gl.stage
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
            ORDER BY gl.level_number, c.name_ar
        ");
        $stmt->execute([$schoolId, $academicYearId]);
    }
    $classes = $stmt->fetchAll();
}

// جلب الفصول الدراسية
$semesters = [];
$stmt = $db->prepare("
    SELECT s.id, s.name_ar FROM semesters s
    WHERE s.academic_year_id = ? AND s.is_active = 1
    ORDER BY s.id
");
$stmt->execute([$academicYearId]);
$semesters = $stmt->fetchAll();

// جلب أنواع التقييم
$assessmentTypes = $db->query("
    SELECT id, name_ar, code, weight_default FROM assessment_types WHERE is_active = 1 ORDER BY sort_order
")->fetchAll();

// جلب المواد — للمعلم: فقط مواده في الفصل المختار
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedSubjectId = (int)($_GET['subject_id'] ?? 0);
if ($userRole === 'teacher' && $selectedClassId > 0) {
    $stmt = $db->prepare("
        SELECT DISTINCT s.id, s.name_ar, s.code
        FROM subjects s
        JOIN subject_teachers st ON st.subject_id = s.id AND st.teacher_id = ? AND st.classroom_id = ? AND st.is_active = 1
        WHERE s.is_active = 1
        ORDER BY s.name_ar
    ");
    $stmt->execute([$userId, $selectedClassId]);
    $subjects = $stmt->fetchAll();
} elseif ($userRole === 'teacher') {
    $stmt = $db->prepare("
        SELECT DISTINCT s.id, s.name_ar, s.code
        FROM subjects s
        JOIN subject_teachers st ON st.subject_id = s.id AND st.teacher_id = ? AND st.is_active = 1
        WHERE s.is_active = 1
        ORDER BY s.name_ar
    ");
    $stmt->execute([$userId]);
    $subjects = $stmt->fetchAll();
} else {
    $subjects = $db->query("
        SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar
    ")->fetchAll();
}

// إذا تم تحديد اختبار
$selectedTest = null;
$testStudents = [];
$existingGrades = [];

$selectedTestId = (int)($_GET['test_id'] ?? 0);

if ($selectedTestId > 0) {
    // جلب بيانات الاختبار
    $stmt = $db->prepare("
        SELECT t.*, sub.name_ar AS subject_name, sub.code AS subject_code,
               c.name_ar AS class_name, at.name_ar AS type_name, at.code AS type_code,
               sem.name_ar AS semester_name
        FROM tests t
        INNER JOIN subjects sub ON sub.id = t.subject_id
        INNER JOIN classes c ON c.id = t.class_id
        INNER JOIN assessment_types at ON at.id = t.assessment_type_id
        INNER JOIN semesters sem ON sem.id = t.semester_id
        WHERE t.id = ? AND t.is_active = 1
    ");
    $stmt->execute([$selectedTestId]);
    $selectedTest = $stmt->fetch();

    if ($selectedTest) {
        $selectedClassId = $selectedTest['class_id'];
        $selectedSubjectId = $selectedTest['subject_id'];

        // جلب طلاب الفصل
        $stmt = $db->prepare("
            SELECT s.id, s.student_number, s.full_name_ar
            FROM student_enrollments se
            INNER JOIN students s ON s.id = se.student_id
            WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled' AND se.is_active = 1 AND s.is_active = 1
            ORDER BY s.full_name_ar
        ");
        $stmt->execute([$selectedTest['class_id'], $academicYearId]);
        $testStudents = $stmt->fetchAll();

        // جلب الدرجات الموجودة
        $stmt = $db->prepare("
            SELECT g.student_id, g.grade_value, g.percentage, g.letter_grade, g.id AS grade_id
            FROM grades g
            WHERE g.test_id = ? AND g.is_active = 1
        ");
        $stmt->execute([$selectedTestId]);
        while ($row = $stmt->fetch()) {
            $existingGrades[$row['student_id']] = $row;
        }
    }
}

// جلب اختبارات الفصل والمادة المحددة
$availableTests = [];
if ($selectedClassId > 0 && $selectedSubjectId > 0) {
    $stmt = $db->prepare("
        SELECT t.*, at.name_ar AS type_name, at.code AS type_code,
               (SELECT COUNT(*) FROM grades g WHERE g.test_id = t.id AND g.is_active = 1) AS grade_count
        FROM tests t
        INNER JOIN assessment_types at ON at.id = t.assessment_type_id
        WHERE t.class_id = ? AND t.subject_id = ? AND t.academic_year_id = ? AND t.is_active = 1
        ORDER BY t.test_date DESC, t.id DESC
    ");
    $stmt->execute([$selectedClassId, $selectedSubjectId, $academicYearId]);
    $availableTests = $stmt->fetchAll();
}
?>

<!-- ============================================================
     محتوى الصفحة
     ============================================================ -->

<!-- بطاقة الاختيارات -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">📋 اختيار الفصل والمادة</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="grade_entry.php" id="filterForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                    <select name="semester_id" class="form-control" id="semester_id">
                        <option value="">-- اختر الفصل الدراسي --</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= ((int)($_GET['semester_id'] ?? ($userRole === 'teacher' ? $currentSemesterId : 0)) == $sem['id']) ? 'selected' : '' ?>>
                                <?= e($sem['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">الشعبة <span class="required">*</span></label>
                    <select name="class_id" class="form-control" id="class_id">
                        <option value="">-- اختر الشعبة --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $selectedClassId == $cls['id'] ? 'selected' : '' ?>>
                                <?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">المادة <span class="required">*</span></label>
                    <select name="subject_id" class="form-control" id="subject_id">
                        <option value="">-- اختر المادة --</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>" <?= $selectedSubjectId == $sub['id'] ? 'selected' : '' ?>>
                                <?= e($sub['name_ar']) ?> (<?= e($sub['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">
                        🔍 عرض الاختبارات
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedClassId > 0 && $selectedSubjectId > 0): ?>

<!-- ============================================================
     قائمة الاختبارات المتاحة
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <h3 class="card-title">📝 الاختبارات المتاحة</h3>
            <button type="button" class="btn btn-success" onclick="toggleNewTestForm()">
                ➕ إنشاء اختبار جديد
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($availableTests)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">📭</div>
                <div class="table-empty-text">لا توجد اختبارات لهذه المادة في هذا الفصل</div>
                <div class="text-sm text-secondary mt-2">قم بإنشاء اختبار جديد لإدخال الدرجات</div>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>عنوان الاختبار</th>
                            <th>نوع التقييم</th>
                            <th>الدرجة القصوى</th>
                            <th>التاريخ</th>
                            <th>الحالة</th>
                            <th>الدرجات المسجلة</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($availableTests as $tst): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><strong><?= e($tst['title_ar']) ?></strong></td>
                                <td><?= e($tst['type_name']) ?></td>
                                <td><?= $tst['max_grade'] ?></td>
                                <td><?= $tst['test_date'] ? formatDateAr($tst['test_date']) : 'غير محدد' ?></td>
                                <td>
                                    <?php
                                    $statusLabels = [
                                        'draft' => '<span class="badge badge-secondary">مسودة</span>',
                                        'published' => '<span class="badge badge-info">منشور</span>',
                                        'locked' => '<span class="badge badge-danger">مقفل</span>'
                                    ];
                                    echo $statusLabels[$tst['status']] ?? e($tst['status']);
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $tst['grade_count'] > 0 ? 'success' : 'warning' ?>">
                                        <?= $tst['grade_count'] ?> درجة
                                    </span>
                                </td>
                                <td>
                                    <a href="grade_entry.php?test_id=<?= $tst['id'] ?>&class_id=<?= $selectedClassId ?>&subject_id=<?= $selectedSubjectId ?>"
                                       class="btn btn-sm btn-primary">
                                        ✏️ إدخال درجات
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- نموذج إنشاء اختبار جديد -->
        <div id="newTestForm" style="display:none; margin-top: var(--space-6); border-top: 1px solid var(--border-light); padding-top: var(--space-6);">
            <h4 style="margin-bottom: var(--space-4);">➕ إنشاء اختبار جديد</h4>
            <form method="POST" action="grade_entry.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_test">
                <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">
                <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                <input type="hidden" name="semester_id" value="<?= $userRole === 'teacher' && $currentSemesterId ? (int)$currentSemesterId : (int)($_GET['semester_id'] ?? ($semesters[0]['id'] ?? '')) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">عنوان الاختبار <span class="required">*</span></label>
                        <input type="text" name="title_ar" class="form-control" placeholder="مثال: اختبار الشهر الأول - رياضيات" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نوع التقييم <span class="required">*</span></label>
                        <select name="assessment_type_id" class="form-control" required>
                            <option value="">-- اختر النوع --</option>
                            <?php foreach ($assessmentTypes as $at): ?>
                                <option value="<?= $at['id'] ?>"><?= e($at['name_ar']) ?> (<?= e($at['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">الدرجة القصوى <span class="required">*</span></label>
                        <input type="number" name="max_grade" class="form-control" value="100" min="1" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">تاريخ الاختبار</label>
                        <input type="date" name="test_date" class="form-control">
                    </div>
                </div>

                <div class="flex gap-4 mt-4">
                    <button type="submit" class="btn btn-success">💾 إنشاء الاختبار</button>
                    <button type="button" class="btn btn-light" onclick="toggleNewTestForm()">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================
     جدول إدخال الدرجات
     ============================================================ -->
<?php if ($selectedTest && !empty($testStudents)): ?>

<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h3 class="card-title">📝 إدخال الدرجات: <?= e($selectedTest['title_ar']) ?></h3>
                <div class="text-sm text-secondary mt-1">
                    <?= e($selectedTest['subject_name']) ?> | <?= e($selectedTest['class_name']) ?> |
                    <?= e($selectedTest['type_name']) ?> | الدرجة القصوى: <?= $selectedTest['max_grade'] ?>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn btn-info btn-sm" onclick="window.print()" title="طباعة">
                    🖨️ طباعة
                </button>
                <?php if ($selectedTest['status'] !== 'locked'): ?>
                    <button type="button" class="btn btn-warning btn-sm" onclick="fillRandomGrades()" title="تعبئة تجريبية">
                        🎲 تعبئة تجريبية
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if ($selectedTest['status'] === 'locked'): ?>
            <div class="flash-message flash-warning mb-4">
                ⚠️ هذا الاختبار مقفل ولا يمكن تعديل الدرجات
            </div>
        <?php endif; ?>

        <form method="POST" action="grade_entry.php" id="gradesForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_grades">
            <input type="hidden" name="test_id" value="<?= $selectedTest['id'] ?>">

            <div class="table-wrapper">
                <table class="data-table" id="gradeEntryTable">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:80px">رقم الطالب</th>
                            <th>اسم الطالب</th>
                            <th style="width:120px">الدرجة <br><small>(من <?= $selectedTest['max_grade'] ?>)</small></th>
                            <th style="width:80px">النسبة %</th>
                            <th style="width:70px">التقدير</th>
                            <th style="width:70px">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowNum = 1; foreach ($testStudents as $student): ?>
                            <?php
                            $existing = $existingGrades[$student['id']] ?? null;
                            $gradeVal = $existing ? $existing['grade_value'] : '';
                            $pct = $existing ? $existing['percentage'] : '';
                            $letter = $existing ? $existing['letter_grade'] : '';
                            $isExisting = !empty($existing);
                            ?>
                            <tr class="<?= $isExisting ? 'grade-existing' : '' ?>" data-student-id="<?= $student['id'] ?>">
                                <td><?= $rowNum++ ?></td>
                                <td><?= e($student['student_number']) ?></td>
                                <td><strong><?= e($student['full_name_ar']) ?></strong></td>
                                <td>
                                    <?php if ($selectedTest['status'] !== 'locked'): ?>
                                        <input type="number" name="grades[<?= $student['id'] ?>]"
                                               class="form-control grade-input"
                                               data-max="<?= $selectedTest['max_grade'] ?>"
                                               data-student="<?= $student['id'] ?>"
                                               value="<?= $gradeVal !== '' ? $gradeVal : '' ?>"
                                               min="0" max="<?= $selectedTest['max_grade'] ?>"
                                               step="0.25"
                                               placeholder="أدخل الدرجة"
                                               onchange="calculatePercentage(this)"
                                               oninput="calculatePercentage(this)">
                                    <?php else: ?>
                                        <span class="font-semibold"><?= $gradeVal ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="percentage-cell" id="pct_<?= $student['id'] ?>">
                                    <?= $pct !== '' ? $pct . '%' : '-' ?>
                                </td>
                                <td class="letter-cell" id="letter_<?= $student['id'] ?>">
                                    <?php if ($letter): ?>
                                        <span class="badge badge-<?= in_array($letter, ['A+', 'A', 'B+', 'B']) ? 'success' : (in_array($letter, ['C+', 'C']) ? 'info' : (in_array($letter, ['D+', 'D']) ? 'warning' : 'danger')) ?>">
                                            <?= e($letter) ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isExisting): ?>
                                        <span class="badge badge-info">مسجلة</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">جديد</span>
                                    <?php endif; ?>
                                    <?php if ($pct !== '' && $pct < 50): ?>
                                        <span class="badge badge-danger mt-1">راسب</span>
                                    <?php elseif ($pct !== '' && $pct >= 50): ?>
                                        <span class="badge badge-success mt-1">ناجح</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:left; font-weight:bold;">
                                إحصائيات سريعة
                            </td>
                            <td id="stats_avg" style="font-weight:bold;">-</td>
                            <td id="stats_pct" style="font-weight:bold;">-</td>
                            <td colspan="2" id="stats_pass" style="font-weight:bold;">-</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($selectedTest['status'] !== 'locked'): ?>
                <div class="flex items-center justify-between mt-6 flex-wrap gap-4">
                    <div class="text-sm text-secondary">
                        عدد الطلاب: <strong><?= count($testStudents) ?></strong> |
                        الدرجات المسجلة: <strong><?= count($existingGrades) ?></strong>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" class="btn btn-primary btn-lg" id="saveBtn">
                            💾 حفظ جميع الدرجات
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ============================================================
     ملخص سريع للدرجات
     ============================================================ -->
<div class="card" id="gradeSummaryCard" style="display:none;">
    <div class="card-header">
        <h3 class="card-title">📊 ملخص الدرجات</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-4">
            <div class="text-center p-4" style="background:rgba(40,167,69,0.1); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--success);" id="summary_pass">0</div>
                <div class="text-sm text-secondary">ناجح</div>
            </div>
            <div class="text-center p-4" style="background:rgba(220,53,69,0.1); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--danger);" id="summary_fail">0</div>
                <div class="text-sm text-secondary">راسب</div>
            </div>
            <div class="text-center p-4" style="background:rgba(26,86,50,0.1); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--primary);" id="summary_avg">0%</div>
                <div class="text-sm text-secondary">متوسط النسبة</div>
            </div>
            <div class="text-center p-4" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--warning-dark);" id="summary_missing">0</div>
                <div class="text-sm text-secondary">بدون درجة</div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($selectedTest && empty($testStudents)): ?>
<div class="card">
    <div class="card-body">
        <div class="table-empty">
            <div class="table-empty-icon">👥</div>
            <div class="table-empty-text">لا يوجد طلاب مسجلين في هذا الفصل</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     الأنماط المخصصة
     ============================================================ -->
<style>
    .grade-existing {
        background-color: rgba(23, 162, 184, 0.05) !important;
    }
    .grade-input {
        text-align: center;
        font-weight: 600;
        font-size: var(--font-size-base);
        padding: var(--space-2) var(--space-3) !important;
    }
    .grade-input:focus {
        background-color: rgba(26, 86, 50, 0.05);
    }
    .grade-input.is-invalid {
        border-color: var(--danger) !important;
        background-color: rgba(220, 53, 69, 0.05);
    }
    .percentage-cell, .letter-cell {
        text-align: center;
        font-weight: 600;
    }
    .grade-pass { color: var(--success); }
    .grade-fail { color: var(--danger); font-weight: bold; }

    @media print {
        .sidebar, .header, .btn, .form-row, #newTestForm, .flash-message { display: none !important; }
        .main-content { margin: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    }
</style>

<!-- ============================================================
     جافاسكربت
     ============================================================ -->
<script>
// مقياس الدرجات  للعرض الفوري
const gradeScale = [
    { min: 95, max: 100, letter: 'A+', class: 'success' },
    { min: 90, max: 94.99, letter: 'A',  class: 'success' },
    { min: 85, max: 89.99, letter: 'B+', class: 'success' },
    { min: 80, max: 84.99, letter: 'B',  class: 'info' },
    { min: 75, max: 79.99, letter: 'C+', class: 'info' },
    { min: 70, max: 74.99, letter: 'C',  class: 'info' },
    { min: 65, max: 69.99, letter: 'D+', class: 'warning' },
    { min: 60, max: 64.99, letter: 'D',  class: 'warning' },
    { min: 50, max: 59.99, letter: 'E',  class: 'warning' },
    { min: 0,  max: 49.99, letter: 'F',  class: 'danger' },
];

/**
 * حساب النسبة المئوية والتقدير الحرفي تلقائياً
 */
function calculatePercentage(input) {
    const maxGrade = parseFloat(input.dataset.max);
    const studentId = input.dataset.student;
    const value = parseFloat(input.value);
    const pctCell = document.getElementById('pct_' + studentId);
    const letterCell = document.getElementById('letter_' + studentId);

    // التحقق من الحد الأقصى
    if (value > maxGrade) {
        input.classList.add('is-invalid');
        input.value = maxGrade;
    } else {
        input.classList.remove('is-invalid');
    }

    if (isNaN(value) || value === '' || input.value === '') {
        pctCell.textContent = '-';
        pctCell.className = 'percentage-cell';
        letterCell.innerHTML = '-';
        updateSummary();
        return;
    }

    // حساب النسبة
    const percentage = maxGrade > 0 ? ((value / maxGrade) * 100).toFixed(2) : 0;
    pctCell.textContent = percentage + '%';
    pctCell.className = 'percentage-cell ' + (percentage >= 50 ? 'grade-pass' : 'grade-fail');

    // تحديد التقدير الحرفي
    const grade = gradeScale.find(g => percentage >= g.min && percentage <= g.max);
    if (grade) {
        letterCell.innerHTML = `<span class="badge badge-${grade.class}">${grade.letter}</span>`;
    } else {
        letterCell.innerHTML = '-';
    }

    updateSummary();
}

/**
 * تحديث ملخص الدرجات
 */
function updateSummary() {
    const inputs = document.querySelectorAll('.grade-input');
    let total = 0, count = 0, passCount = 0, failCount = 0, missingCount = 0;

    inputs.forEach(input => {
        const val = parseFloat(input.value);
        const max = parseFloat(input.dataset.max);
        if (!isNaN(val) && input.value !== '') {
            count++;
            const pct = (val / max) * 100;
            total += pct;
            if (pct >= 50) passCount++;
            else failCount++;
        } else {
            missingCount++;
        }
    });

    const avg = count > 0 ? (total / count).toFixed(1) : 0;

    // تحديث الإحصائيات
    const statsAvg = document.getElementById('stats_avg');
    const statsPct = document.getElementById('stats_pct');
    const statsPass = document.getElementById('stats_pass');

    if (statsAvg) statsAvg.textContent = count > 0 ? avg : '-';
    if (statsPct) statsPct.textContent = count > 0 ? avg + '%' : '-';
    if (statsPass) statsPass.innerHTML = count > 0 ?
        `<span style="color:var(--success)">${passCount} ناجح</span> / <span style="color:var(--danger)">${failCount} راسب</span>` : '-';

    // تحديث بطاقة الملخص
    const summaryCard = document.getElementById('gradeSummaryCard');
    if (count > 0 && summaryCard) {
        summaryCard.style.display = 'block';
        document.getElementById('summary_pass').textContent = passCount;
        document.getElementById('summary_fail').textContent = failCount;
        document.getElementById('summary_avg').textContent = avg + '%';
        document.getElementById('summary_missing').textContent = missingCount;
    }
}

/**
 * تبديل عرض نموذج إنشاء اختبار جديد
 */
function toggleNewTestForm() {
    const form = document.getElementById('newTestForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

/**
 * تعبئة درجات تجريبية (للاختبار فقط)
 */
function fillRandomGrades() {
    if (!confirm('هل تريد تعبئة درجات تجريبية عشوائية؟')) return;
    const inputs = document.querySelectorAll('.grade-input');
    inputs.forEach(input => {
        const max = parseFloat(input.dataset.max);
        const randomGrade = (Math.random() * max).toFixed(1);
        input.value = randomGrade;
        calculatePercentage(input);
    });
}

// تحديث الملخص عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', updateSummary);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
