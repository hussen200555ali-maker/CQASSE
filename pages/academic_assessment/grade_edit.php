<?php
/**
 * ============================================================
 * EQAS - تعديل الدرجات
 * نظام التقييم الشامل - تعديل الدرجات المُدخلة مسبقاً
 * ============================================================
 * يتضمن:
 * - اختيار الاختبار لتعديل درجاته
 * - عرض الدرجات الحالية في جدول قابل للتعديل
 * - تسجيل التغييرات في سجل النشاطات (activity_logs)
 * - طلب سبب التغيير لكل درجة معدّلة
 * - تمييز الدرجات المعدّلة بلون مختلف
 * - منع التعديل إذا كان الاختبار مقفلاً
 * ============================================================
 */

$pageTitle = 'تعديل الدرجات';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'تعديل الدرجات' => null
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/academic_calculator.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('صلاحية تعديل الدرجات متاحة فقط للمعلمين وفما فوق', 'danger');
    redirect('../../index.php');
}

$calculator = new AcademicCalculator($db);
$academicYearId = getCurrentAcademicYearId($db);
$schoolId = $_SESSION['user']['school_id'] ?? 0;
$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id'] ?? 0;
$currentSemesterId = currentSemesterId($academicYearId);

// ============================================================
// معالجة حفظ التعديلات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_edits') {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح، يرجى المحاولة مرة أخرى', 'danger');
        redirect('grade_edit.php');
    }

    $testId = (int)($_POST['test_id'] ?? 0);
    $editedGrades = $_POST['edited_grades'] ?? []; // مصفوفة [grade_id => new_value]
    $reasons = $_POST['reasons'] ?? []; // مصفوفة [grade_id => reason]

    if ($testId <= 0) {
        setFlash('بيانات غير صالحة', 'danger');
        redirect('grade_edit.php');
    }

    // التحقق من أن الاختبار غير مقفل
    $stmt = $db->prepare("SELECT t.*, c.school_id FROM tests t JOIN classes c ON t.class_id = c.id WHERE t.id = ? AND t.is_active = 1");
    $stmt->execute([$testId]);
    $testInfo = $stmt->fetch();
    
    // التحقق من أن الاختبار ينتمي لنفس المدرسة
    if ($testInfo && $schoolId && (int)$testInfo['school_id'] !== (int)$schoolId) {
        setFlash('الاختبار غير موجود في مدرستك', 'danger');
        redirect('grade_edit.php');
    }

    if (!$testInfo) {
        setFlash('الاختبار غير موجود', 'danger');
        redirect('grade_edit.php');
    }

    if ($testInfo['status'] === 'locked') {
        setFlash('لا يمكن تعديل درجات اختبار مقفل', 'danger');
        redirect('grade_edit.php?test_id=' . $testId);
    }

    // المعلم: السماح بالتعديل فقط في الفصل الحالي
    if ($userRole === 'teacher' && $currentSemesterId && (int)$testInfo['semester_id'] !== (int)$currentSemesterId) {
        setFlash('يمكنك تعديل الدرجات فقط في الفصل الدراسي الحالي', 'danger');
        redirect('grade_edit.php');
    }

    try {
        $db->beginTransaction();
        $changedCount = 0;

        foreach ($editedGrades as $gradeId => $newValue) {
            $gradeId = (int)$gradeId;
            $newValue = (float)$newValue;
            $reason = trim($reasons[$gradeId] ?? '');

            // جلب الدرجة الحالية
            $stmt = $db->prepare("SELECT * FROM grades WHERE id = ? AND is_active = 1");
            $stmt->execute([$gradeId]);
            $currentGrade = $stmt->fetch();

            if (!$currentGrade) continue;

            $oldValue = (float)$currentGrade['grade_value'];

            // تجاهل إذا لم تتغير القيمة
            if (abs($newValue - $oldValue) < 0.01) continue;

            // التحقق من وجود سبب
            if (empty($reason)) {
                throw new Exception("يجب إدخال سبب التغيير للدرجة رقم {$gradeId}");
            }

            // التحقق من الحد الأقصى
            $maxGrade = (float)$testInfo['max_grade'];
            if ($newValue > $maxGrade) {
                $newValue = $maxGrade;
            }
            if ($newValue < 0) {
                $newValue = 0;
            }

            // حساب النسبة الجديدة
            $newPercentage = $maxGrade > 0 ? round(($newValue / $maxGrade) * 100, 2) : 0;
            $letterInfo = $calculator->getLetterGrade($newPercentage);

            // تحديث الدرجة
            $stmt = $db->prepare("
                UPDATE grades 
                SET grade_value = ?, max_value = ?, percentage = ?, letter_grade = ?, graded_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $newValue, $maxGrade, $newPercentage, $letterInfo['letter'],
                $_SESSION['user']['id'] ?? 0, $gradeId
            ]);

            // تسجيل التغيير في سجل النشاطات
            $detailsJson = json_encode([
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'reason' => $reason,
                'grade_id' => $gradeId,
                'test_id' => $testId
            ]);
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, details_json, created_at)
                VALUES (?, 'grade_change', 'grades', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user']['id'] ?? 0,
                $gradeId,
                "تغيير الدرجة من {$oldValue} إلى {$newValue}",
                $detailsJson
            ]);

            $changedCount++;
        }

        $db->commit();

        if ($changedCount > 0) {
            setFlash("تم تعديل {$changedCount} درجة بنجاح وتسجيل التغييرات في السجل", 'success');
            logActivity($db, $_SESSION['user']['id'] ?? 0, 'edit_grades', "تعديل {$changedCount} درجة في الاختبار رقم {$testId}", 'test', $testId);
        } else {
            setFlash('لم يتم إجراء أي تعديل', 'info');
        }
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('حدث خطأ: ' . $e->getMessage(), 'danger');
    }

    redirect('grade_edit.php?test_id=' . $testId);
}

// ============================================================
// جلب البيانات للعرض
// ============================================================

// جلب الفصول
$classes = [];
if ($schoolId) {
    if ($userRole === 'teacher') {
        $stmt = $db->prepare("
            SELECT DISTINCT c.id, c.name_ar, gl.name_ar AS grade_name
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            JOIN subject_teachers st ON st.classroom_id = c.id AND st.teacher_id = ? AND st.is_active = 1
            WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
            UNION
            SELECT DISTINCT c.id, c.name_ar, gl.name_ar AS grade_name
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            WHERE c.teacher_id = ? AND c.is_active = 1 AND c.school_id = ? AND c.academic_year_id = ?
            ORDER BY grade_name, name_ar
        ");
        $stmt->execute([$userId, $schoolId, $academicYearId, $userId, $schoolId, $academicYearId]);
    } else {
        $stmt = $db->prepare("
            SELECT c.id, c.name_ar, gl.name_ar AS grade_name
            FROM classes c
            INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
            WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
            ORDER BY gl.level_number, c.name_ar
        ");
        $stmt->execute([$schoolId, $academicYearId]);
    }
    $classes = $stmt->fetchAll();
}

$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// اختبار محدد
$selectedTestId = (int)($_GET['test_id'] ?? 0);
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedSubjectId = (int)($_GET['subject_id'] ?? 0);
$testData = null;
$gradesList = [];
$changeLogs = [];

if ($selectedTestId > 0) {
    // جلب بيانات الاختبار
    $stmt = $db->prepare("
        SELECT t.*, sub.name_ar AS subject_name, c.name_ar AS class_name,
               at.name_ar AS type_name, sem.name_ar AS semester_name
        FROM tests t
        INNER JOIN subjects sub ON sub.id = t.subject_id
        INNER JOIN classes c ON c.id = t.class_id
        INNER JOIN assessment_types at ON at.id = t.assessment_type_id
        INNER JOIN semesters sem ON sem.id = t.semester_id
        WHERE t.id = ? AND t.is_active = 1" . ($schoolId ? " AND c.school_id = ?" : "") . "
    ");
    $params = [$selectedTestId];
    if ($schoolId) $params[] = $schoolId;
    $stmt->execute($params);
    $testData = $stmt->fetch();

    if ($testData) {
        $selectedClassId = $testData['class_id'];
        $selectedSubjectId = $testData['subject_id'];

        // جلب الدرجات مع بيانات الطلاب
        $stmt = $db->prepare("
            SELECT g.id AS grade_id, g.grade_value, g.max_value, g.percentage, g.letter_grade,
                   s.id AS student_id, s.student_number, s.full_name_ar
            FROM grades g
            INNER JOIN students s ON s.id = g.student_id
            WHERE g.test_id = ? AND g.is_active = 1
            ORDER BY s.full_name_ar
        ");
        $stmt->execute([$selectedTestId]);
        $gradesList = $stmt->fetchAll();

        // جلب سجل التغييرات من activity_logs
        $changeLogs = [];
        $gradeIds = array_column($gradesList, 'grade_id');
        if (!empty($gradeIds)) {
            $placeholders = implode(',', array_fill(0, count($gradeIds), '?'));
            $stmt = $db->prepare("
                SELECT al.id, al.entity_id AS grade_id, al.description, al.details_json,
                       al.user_id AS changed_by, al.created_at, u.full_name_ar AS changed_by_name
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.action = 'grade_change' AND al.entity_type = 'grades' AND al.entity_id IN ({$placeholders})
                ORDER BY al.created_at DESC
            ");
            $stmt->execute($gradeIds);
            $changeLogs = $stmt->fetchAll();

            // إضافة old_value, new_value, reason من details_json
            foreach ($changeLogs as &$log) {
                $details = json_decode($log['details_json'], true) ?: [];
                $log['old_value'] = $details['old_value'] ?? 0;
                $log['new_value'] = $details['new_value'] ?? 0;
                $log['reason'] = $details['reason'] ?? '';
            }
            unset($log);
        }
    }
}

// جلب اختبارات الفصل والمادة
$availableTests = [];
if ($selectedClassId > 0 && $selectedSubjectId > 0) {
    $sql = "
        SELECT t.*, at.name_ar AS type_name,
               (SELECT COUNT(*) FROM grades g WHERE g.test_id = t.id AND g.is_active = 1) AS grade_count
        FROM tests t
        INNER JOIN assessment_types at ON at.id = t.assessment_type_id
        WHERE t.class_id = ? AND t.subject_id = ? AND t.academic_year_id = ? AND t.is_active = 1
    ";
    $params = [$selectedClassId, $selectedSubjectId, $academicYearId];

    // المعلم: يرى فقط اختبارات الفصل الحالي
    if ($userRole === 'teacher' && $currentSemesterId) {
        $sql .= " AND t.semester_id = ?";
        $params[] = $currentSemesterId;
    }

    $sql .= " ORDER BY t.test_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $availableTests = $stmt->fetchAll();
}
?>

<!-- ============================================================
     اختيار الاختبار
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">🔍 اختيار الاختبار للتعديل</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="grade_edit.php">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">الشعبة</label>
                    <select name="class_id" class="form-control">
                        <option value="">-- اختر الشعبة --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $selectedClassId == $cls['id'] ? 'selected' : '' ?>>
                                <?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">المادة</label>
                    <select name="subject_id" class="form-control">
                        <option value="">-- اختر المادة --</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>" <?= $selectedSubjectId == $sub['id'] ? 'selected' : '' ?>>
                                <?= e($sub['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 عرض</button>
                </div>
            </div>
        </form>

        <?php if (!empty($availableTests)): ?>
        <div class="mt-6">
            <h4 style="margin-bottom:var(--space-4);">الاختبارات المتاحة:</h4>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>عنوان الاختبار</th>
                            <th>نوع التقييم</th>
                            <th>الدرجة القصوى</th>
                            <th>الحالة</th>
                            <th>عدد الدرجات</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($availableTests as $tst): ?>
                            <tr class="<?= $selectedTestId == $tst['id'] ? 'row-selected' : '' ?>">
                                <td><?= $i++ ?></td>
                                <td><?= e($tst['title_ar']) ?></td>
                                <td><?= e($tst['type_name']) ?></td>
                                <td><?= $tst['max_grade'] ?></td>
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
                                <td><?= $tst['grade_count'] ?></td>
                                <td>
                                    <a href="grade_edit.php?test_id=<?= $tst['id'] ?>&class_id=<?= $selectedClassId ?>&subject_id=<?= $selectedSubjectId ?>"
                                       class="btn btn-sm btn-warning">
                                        ✏️ تعديل
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     جدول تعديل الدرجات
     ============================================================ -->
<?php if ($testData && !empty($gradesList)): ?>

<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h3 class="card-title">✏️ تعديل درجات: <?= e($testData['title_ar']) ?></h3>
                <div class="text-sm text-secondary mt-1">
                    <?= e($testData['subject_name']) ?> | <?= e($testData['class_name']) ?> |
                    <?= e($testData['type_name']) ?> | الدرجة القصوى: <?= $testData['max_grade'] ?>
                </div>
            </div>
            <?php if ($testData['status'] === 'locked'): ?>
                <span class="badge badge-danger" style="font-size:var(--font-size-base); padding:var(--space-2) var(--space-4);">
                    🔒 الاختبار مقفل - لا يمكن التعديل
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if ($testData['status'] === 'locked'): ?>
            <div class="flash-message flash-danger mb-4">
                ⚠️ لا يمكن تعديل الدرجات لهذا الاختبار لأنه مقفل. تواصل مع مدير النظام لفك القفل.
            </div>
        <?php endif; ?>

        <form method="POST" action="grade_edit.php" id="editForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_edits">
            <input type="hidden" name="test_id" value="<?= $testData['id'] ?>">

            <div class="table-wrapper">
                <table class="data-table" id="editTable">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:80px">رقم الطالب</th>
                            <th>اسم الطالب</th>
                            <th style="width:100px">الدرجة الحالية</th>
                            <th style="width:100px">الدرجة الجديدة</th>
                            <th style="width:80px">النسبة</th>
                            <th style="width:70px">التقدير</th>
                            <th>سبب التغيير <span class="required">*</span></th>
                            <th style="width:50px">تم تغييره</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowNum = 1; foreach ($gradesList as $gr): ?>
                            <tr id="row_<?= $gr['grade_id'] ?>">
                                <td><?= $rowNum++ ?></td>
                                <td><?= e($gr['student_number']) ?></td>
                                <td><strong><?= e($gr['full_name_ar']) ?></strong></td>
                                <td class="text-center">
                                    <span class="old-value" id="old_<?= $gr['grade_id'] ?>"><?= $gr['grade_value'] ?></span>
                                </td>
                                <td>
                                    <?php if ($testData['status'] !== 'locked'): ?>
                                        <input type="number" name="edited_grades[<?= $gr['grade_id'] ?>]"
                                               class="form-control edit-input"
                                               data-grade-id="<?= $gr['grade_id'] ?>"
                                               data-old-value="<?= $gr['grade_value'] ?>"
                                               data-max="<?= $testData['max_grade'] ?>"
                                               value="<?= $gr['grade_value'] ?>"
                                               min="0" max="<?= $testData['max_grade'] ?>"
                                               step="0.25"
                                               onchange="onGradeChange(this)"
                                               oninput="onGradeChange(this)">
                                    <?php else: ?>
                                        <span><?= $gr['grade_value'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" id="pct_<?= $gr['grade_id'] ?>">
                                    <?= $gr['percentage'] ?>%
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= in_array($gr['letter_grade'], ['A+','A','B+','B']) ? 'success' : (in_array($gr['letter_grade'], ['C+','C']) ? 'info' : (in_array($gr['letter_grade'], ['D+','D','E']) ? 'warning' : 'danger')) ?>">
                                        <?= e($gr['letter_grade']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($testData['status'] !== 'locked'): ?>
                                        <input type="text" name="reasons[<?= $gr['grade_id'] ?>]"
                                               class="form-control reason-input"
                                               id="reason_<?= $gr['grade_id'] ?>"
                                               placeholder="سبب التعديل"
                                               disabled
                                               style="font-size:var(--font-size-xs);">
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" id="changed_<?= $gr['grade_id'] ?>">
                                    -
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($testData['status'] !== 'locked'): ?>
                <div class="flex items-center justify-between mt-6 flex-wrap gap-4">
                    <div class="text-sm text-secondary">
                        ⚠️ يجب إدخال سبب التغيير لكل درجة معدّلة
                    </div>
                    <button type="submit" class="btn btn-warning btn-lg" id="saveEditBtn" disabled>
                        💾 حفظ التعديلات
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ============================================================
     سجل التغييرات
     ============================================================ -->
<?php if (!empty($changeLogs)): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📜 سجل تغيير الدرجات</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الدرجة القديمة</th>
                        <th>الدرجة الجديدة</th>
                        <th>الفرق</th>
                        <th>السبب</th>
                        <th>بواسطة</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($changeLogs as $log): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= $log['old_value'] ?></td>
                            <td><?= $log['new_value'] ?></td>
                            <td>
                                <?php $diff = $log['new_value'] - $log['old_value']; ?>
                                <span style="color:<?= $diff > 0 ? 'var(--success)' : 'var(--danger)' ?>">
                                    <?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 2) ?>
                                </span>
                            </td>
                            <td><?= e($log['reason']) ?></td>
                            <td><?= e($log['changed_by_name'] ?? 'غير معروف') ?></td>
                            <td><?= formatDateAr($log['created_at'], 'medium') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($selectedTestId > 0 && !$testData): ?>
<div class="card">
    <div class="card-body">
        <div class="table-empty">
            <div class="table-empty-icon">⚠️</div>
            <div class="table-empty-text">الاختبار المحدد غير موجود</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- أنماط مخصصة -->
<style>
    .row-selected { background-color: rgba(26, 86, 50, 0.08) !important; }
    .edit-input { text-align: center; font-weight: 600; }
    .edit-input.changed { background-color: rgba(255, 193, 7, 0.15); border-color: var(--warning) !important; }
    .reason-input:disabled { opacity: 0.5; background-color: var(--background); }
    .change-marker { color: var(--warning-dark); font-weight: bold; }
    .old-value { color: var(--text-secondary); text-decoration: line-through; }
</style>

<script>
// مقياس الدرجات
const gradeScale = [
    { min: 95, max: 100, letter: 'A+', cls: 'success' },
    { min: 90, max: 94.99, letter: 'A',  cls: 'success' },
    { min: 85, max: 89.99, letter: 'B+', cls: 'success' },
    { min: 80, max: 84.99, letter: 'B',  cls: 'info' },
    { min: 75, max: 79.99, letter: 'C+', cls: 'info' },
    { min: 70, max: 74.99, letter: 'C',  cls: 'info' },
    { min: 65, max: 69.99, letter: 'D+', cls: 'warning' },
    { min: 60, max: 64.99, letter: 'D',  cls: 'warning' },
    { min: 50, max: 59.99, letter: 'E',  cls: 'warning' },
    { min: 0,  max: 49.99, letter: 'F',  cls: 'danger' },
];

let hasChanges = false;

/**
 * رصد تغيير الدرجة
 */
function onGradeChange(input) {
    const gradeId = input.dataset.gradeId;
    const oldValue = parseFloat(input.dataset.oldValue);
    const newValue = parseFloat(input.value);
    const maxGrade = parseFloat(input.dataset.max);
    const reasonInput = document.getElementById('reason_' + gradeId);
    const changedCell = document.getElementById('changed_' + gradeId);
    const pctCell = document.getElementById('pct_' + gradeId);
    const row = document.getElementById('row_' + gradeId);

    // التحقق من الحد
    if (newValue > maxGrade) {
        input.value = maxGrade;
    }

    const hasChanged = Math.abs(newValue - oldValue) >= 0.01;

    if (hasChanged) {
        input.classList.add('changed');
        reasonInput.disabled = false;
        reasonInput.required = true;
        changedCell.innerHTML = '<span class="change-marker">✅ تم التغيير</span>';
        row.style.backgroundColor = 'rgba(255, 193, 7, 0.08)';

        // حساب النسبة الجديدة
        const pct = maxGrade > 0 ? ((newValue / maxGrade) * 100).toFixed(2) : 0;
        pctCell.textContent = pct + '%';

        hasChanges = true;
    } else {
        input.classList.remove('changed');
        reasonInput.disabled = true;
        reasonInput.required = false;
        reasonInput.value = '';
        changedCell.innerHTML = '-';
        row.style.backgroundColor = '';

        // إعادة النسبة الأصلية
        const origPct = maxGrade > 0 ? ((oldValue / maxGrade) * 100).toFixed(2) : 0;
        pctCell.textContent = origPct + '%';
    }

    // تفعيل/تعطيل زر الحفظ
    const saveBtn = document.getElementById('saveEditBtn');
    const changedInputs = document.querySelectorAll('.edit-input.changed');
    saveBtn.disabled = changedInputs.length === 0;
}

// التحقق قبل الحفظ
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const changedInputs = document.querySelectorAll('.edit-input.changed');
            let allHaveReasons = true;

            changedInputs.forEach(input => {
                const gradeId = input.dataset.gradeId;
                const reason = document.getElementById('reason_' + gradeId);
                if (!reason.value.trim()) {
                    reason.classList.add('is-invalid');
                    allHaveReasons = false;
                } else {
                    reason.classList.remove('is-invalid');
                }
            });

            if (!allHaveReasons) {
                e.preventDefault();
                alert('يرجى إدخال سبب التغيير لكل درجة معدّلة');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
