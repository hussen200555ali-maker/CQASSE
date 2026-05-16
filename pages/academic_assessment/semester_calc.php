<?php
/**
 * ============================================================
 * EQAS - حساب نتائج الفصل
 * نظام التقييم الشامل - حساب نتائج الفصل الدراسي
 * ============================================================
 * يتضمن:
 * - اختيار الفصل والفصل الدراسي
 * - زر حساب النتائج مع تأكيد
 * - عرض كل طالب: المجموع، المعدل، المواد الراسبة، الترتيب
 * - حساب دفعي باستخدام AcademicCalculator
 * - قفل النتائج بعد الحساب
 * - طباعة تقرير نتائج الفصل
 * - تصدير إلى إكسيل
 * ============================================================
 */

ob_start();

$pageTitle = 'حساب نتائج الفصل';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'حساب نتائج الفصل' => null
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/academic_calculator.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('صلاحية حساب النتائج متاحة فقط لمديري المدارس فما فوق', 'danger');
    redirect('../../index.php');
}

$calculator = new AcademicCalculator($db);
$academicYearId = getCurrentAcademicYearId($db);
$schoolId = $_SESSION['user']['school_id'] ?? 0;

// ============================================================
// معالجة حساب النتائج
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح', 'danger');
        redirect('semester_calc.php');
    }

    $action = $_POST['action'] ?? '';

    // --------------------------------------------------------
    // حساب النتائج
    // --------------------------------------------------------
    if ($action === 'calculate') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);

        if ($classId <= 0 || $semesterId <= 0) {
            setFlash('يرجى اختيار الفصل والفصل الدراسي', 'danger');
            redirect('semester_calc.php');
        }

        try {
            // جلب طلاب الفصل
            $stmt = $db->prepare("
                SELECT s.id, s.full_name_ar, s.student_number, s.grade_level_id
                FROM student_enrollments se
                INNER JOIN students s ON s.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled'
                    AND se.is_active = 1 AND s.is_active = 1
            ");
            $stmt->execute([$classId, $academicYearId]);
            $students = $stmt->fetchAll();

            if (empty($students)) {
                setFlash('لا يوجد طلاب في هذا الفصل', 'warning');
                redirect('semester_calc.php?class_id=' . $classId . '&semester_id=' . $semesterId);
            }

            $db->beginTransaction();
            $calculatedCount = 0;
            $errors = [];

            foreach ($students as $student) {
                // جلب مواد الفصل للطالب
                $stmt = $db->prepare("
                    SELECT DISTINCT sub.id AS subject_id
                    FROM subject_teachers st
                    INNER JOIN subjects sub ON sub.id = st.subject_id
                     WHERE st.classroom_id = ? AND st.academic_year_id = ?
                ");
                $stmt->execute([$classId, $academicYearId]);
                $subjects = $stmt->fetchAll();

                $totalPercentage = 0;
                $totalHours = 0;
                $totalWeightedPoints = 0;
                $failedSubjects = 0;
                $subjectCount = 0;

                foreach ($subjects as $subject) {
                    // حساب النسبة لكل مادة
                    $percentage = $calculator->calculateSubjectPercentage(
                        $student['id'], $subject['subject_id'], $classId, $academicYearId, $semesterId
                    );

                    // جلب عدد الساعات
                    $stmt = $db->prepare("
                        SELECT hours_per_week FROM subject_grade_years
                        WHERE subject_id = ? AND grade_level_id = ? AND is_active = 1 LIMIT 1
                    ");
                    $stmt->execute([$subject['subject_id'], $student['grade_level_id']]);
                    $hoursRow = $stmt->fetch();
                    $hours = $hoursRow ? max(1, (float)$hoursRow['hours_per_week']) : 1;

                    $letterInfo = $calculator->getLetterGrade($percentage);

                    $totalPercentage += $percentage * $hours;
                    $totalHours += $hours;
                    $totalWeightedPoints += $letterInfo['point'] * $hours;
                    $subjectCount++;

                    if ($percentage < 50) {
                        $failedSubjects++;
                    }
                }

                // حساب المعدل التراكمي
                $gpa = $totalHours > 0 ? round($totalWeightedPoints / $totalHours, 2) : 0;
                $overallPercentage = $totalHours > 0 ? round($totalPercentage / $totalHours, 2) : 0;

                // حفظ/تحديث درجات الفصل
                $stmt = $db->prepare("
                    INSERT INTO semester_scores (student_id, academic_year_id, semester_id, gpa, total_percentage, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE gpa = VALUES(gpa), total_percentage = VALUES(total_percentage), is_active = 1
                ");
                $stmt->execute([$student['id'], $academicYearId, $semesterId, $gpa, $overallPercentage]);

                // حساب المواد الراسبة
                $carryOverResult = $calculator->calculateCarryOver($student['id'], $academicYearId, $semesterId);

                $calculatedCount++;
            }

            // حساب الترتيب
            $rankResult = $calculator->calculateClassRank($classId, $academicYearId, $semesterId);

            $db->commit();
            setFlash("تم حساب نتائج {$calculatedCount} طالب بنجاح وتم تحديث الترتيب", 'success');
            logActivity($db, $_SESSION['user']['id'] ?? 0, 'calculate_semester', "حساب نتائج الفصل الدراسي", 'class', $classId);

        } catch (Exception $e) {
            $db->rollBack();
            setFlash('حدث خطأ أثناء الحساب: ' . $e->getMessage(), 'danger');
        }

        redirect('semester_calc.php?class_id=' . $classId . '&semester_id=' . $semesterId);
    }

    // --------------------------------------------------------
    // قفل النتائج
    // --------------------------------------------------------
    if ($action === 'lock') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);

        try {
            // قفل جميع اختبارات الفصل
            $stmt = $db->prepare("
                UPDATE tests SET status = 'locked'
                WHERE class_id = ? AND semester_id = ? AND academic_year_id = ? AND is_active = 1
            ");
            $stmt->execute([$classId, $semesterId, $academicYearId]);

            setFlash('تم قفل نتائج الفصل بنجاح - لا يمكن تعديل الدرجات بعد الآن', 'success');
            logActivity($db, $_SESSION['user']['id'] ?? 0, 'lock_semester', "قفل نتائج الفصل الدراسي", 'class', $classId);
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء قفل النتائج: ' . $e->getMessage(), 'danger');
        }

        redirect('semester_calc.php?class_id=' . $classId . '&semester_id=' . $semesterId);
    }
}

// ============================================================
// جلب البيانات
// ============================================================
$classes = [];
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar AS grade_name, gl.stage, gl.level_number
        FROM classes c
        INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
        WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
        ORDER BY gl.level_number, c.name_ar
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classes = $stmt->fetchAll();
}

$semesters = [];
$stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY id");
$stmt->execute([$academicYearId]);
$semesters = $stmt->fetchAll();

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedSemesterId = (int)($_GET['semester_id'] ?? 0);

// جلب نتائج الفصل
$semesterResults = [];
$classStage = '';
$isLocked = false;

if ($selectedClassId > 0 && $selectedSemesterId > 0) {
    // معلومات الفصل
    $stmt = $db->prepare("
        SELECT c.*, gl.stage, gl.level_number, gl.name_ar AS grade_name
        FROM classes c
        INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
        WHERE c.id = ?
    ");
    $stmt->execute([$selectedClassId]);
    $classInfo = $stmt->fetch();
    $classStage = $classInfo ? $classInfo['stage'] : '';

    // جلب نتائج الطلاب
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.full_name_ar,
               ss.gpa, ss.total_percentage, ss.rank_in_grade, ss.carry_over_subjects
        FROM student_enrollments se
        INNER JOIN students s ON s.id = se.student_id
        LEFT JOIN semester_scores ss ON ss.student_id = s.id AND ss.academic_year_id = ? AND ss.semester_id = ?
        WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled'
            AND se.is_active = 1 AND s.is_active = 1
        ORDER BY ss.gpa DESC, ss.total_percentage DESC
    ");
    $stmt->execute([$academicYearId, $selectedSemesterId, $selectedClassId, $academicYearId]);
    $semesterResults = $stmt->fetchAll();

    // التحقق من القفل
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tests
        WHERE class_id = ? AND semester_id = ? AND academic_year_id = ? AND status = 'locked' AND is_active = 1
    ");
    $stmt->execute([$selectedClassId, $selectedSemesterId, $academicYearId]);
    $lockedCount = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tests
        WHERE class_id = ? AND semester_id = ? AND academic_year_id = ? AND is_active = 1
    ");
    $stmt->execute([$selectedClassId, $selectedSemesterId, $academicYearId]);
    $totalTests = (int)$stmt->fetchColumn();
    $isLocked = ($totalTests > 0 && $lockedCount === $totalTests);
}

// ============================================================
// معالجة التصدير
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($semesterResults)) {
    ob_end_clean(); // مسح المخرجات المخزنة
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=semester_results_' . date('Ymd') . '.csv');
    echo "\xEF\xBB\xBF";

    echo implode(',', ['الترتيب', 'رقم الطالب', 'اسم الطالب', 'النسبة الإجمالية', 'المعدل التراكمي', 'المواد الراسبة', 'الحالة']) . "\n";

    $rank = 1;
    foreach ($semesterResults as $sr) {
        $carryOvers = json_decode($sr['carry_over_subjects'], true) ?? [];
        $carryNames = array_map(function($c) { return $c['subject_name'] ?? ''; }, $carryOvers);
        $gpa = $sr['gpa'] ?? 0;
        $pct = $sr['total_percentage'] ?? 0;
        $status = $pct >= 50 ? 'ناجح' : 'راسب';

        echo implode(',', [
            $rank++, $sr['student_number'], $sr['full_name_ar'],
            $pct ? round($pct, 2) . '%' : '-',
            $gpa ? round($gpa, 2) : '-',
            implode(' | ', $carryNames) ?: 'لا يوجد',
            $status
        ]) . "\n";
    }
    exit;
}
?>

<!-- ============================================================
     نموذج الاختيار
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">🎓 حساب نتائج الفصل الدراسي</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="semester_calc.php" class="form-inline">
            <div class="form-group">
                <label class="form-label">الفصل الدراسي</label>
                <select name="semester_id" class="form-control" required>
                    <option value="">-- اختر --</option>
                    <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $selectedSemesterId == $sem['id'] ? 'selected' : '' ?>>
                            <?= e($sem['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الشعبة</label>
                <select name="class_id" class="form-control" required>
                    <option value="">-- اختر --</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selectedClassId == $cls['id'] ? 'selected' : '' ?>>
                            <?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?>
                            (<?= $cls['stage'] === 'secondary' ? 'ثانوي' : 'أساس' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
        </form>
    </div>
</div>

<?php if ($selectedClassId > 0 && $selectedSemesterId > 0): ?>

<!-- ============================================================
     أزرار الحساب والقفل والتصدير
     ============================================================ -->
<div class="flex gap-3 mb-6 flex-wrap">
    <?php if (!$isLocked): ?>
        <form method="POST" action="semester_calc.php" onsubmit="return confirm('⚠️ هل أنت متأكد من حساب نتائج الفصل؟ سيتم إعادة حساب جميع النسب والمعدلات والترتيب.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="calculate">
            <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
            <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
            <button type="submit" class="btn btn-primary btn-lg">
                🧮 حساب نتائج الفصل
            </button>
        </form>

        <form method="POST" action="semester_calc.php" onsubmit="return confirm('🔒 هل أنت متأكد من قفل النتائج؟ لا يمكن التراجع عن هذا الإجراء ولن يتمكن المعلمون من تعديل الدرجات.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="lock">
            <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
            <input type="hidden" name="semester_id" value="<?= $selectedSemesterId ?>">
            <button type="submit" class="btn btn-danger">
                🔒 قفل النتائج
            </button>
        </form>
    <?php else: ?>
        <span class="badge badge-danger" style="font-size:var(--font-size-base); padding:var(--space-3) var(--space-5);">
            🔒 النتائج مقفلة - لا يمكن إعادة الحساب أو التعديل
        </span>
    <?php endif; ?>

    <a href="semester_calc.php?class_id=<?= $selectedClassId ?>&semester_id=<?= $selectedSemesterId ?>&export=excel"
       class="btn btn-success">
        📥 تصدير إلى إكسيل
    </a>
    <button type="button" class="btn btn-info" onclick="window.print()">🖨️ طباعة</button>
</div>

<!-- ============================================================
     معلومات المرحلة
     ============================================================ -->
<div class="card mb-6">
    <div class="card-body">
        <div class="grid grid-3">
            <div class="text-center p-3" style="background:rgba(26,86,50,0.06); border-radius:var(--radius-md);">
                <div class="font-bold"><?= $classStage === 'secondary' ? 'الثانوي' : 'الأساس' ?></div>
                <div class="text-sm text-secondary">المرحلة الدراسية</div>
            </div>
            <div class="text-center p-3" style="background:rgba(23,162,184,0.06); border-radius:var(--radius-md);">
                <div class="font-bold"><?= count($semesterResults) ?> طالب</div>
                <div class="text-sm text-secondary">عدد الطلاب</div>
            </div>
            <div class="text-center p-3" style="background:rgba(255,193,7,0.06); border-radius:var(--radius-md);">
                <div class="font-bold"><?= $isLocked ? 'مقفلة 🔒' : 'مفتوحة ✅' ?></div>
                <div class="text-sm text-secondary">حالة النتائج</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     جدول النتائج
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">📋 نتائج الفصل الدراسي</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table" id="semesterTable">
                <thead>
                    <tr>
                        <th style="width:50px">الترتيب</th>
                        <th style="width:80px">رقم الطالب</th>
                        <th>اسم الطالب</th>
                        <th style="width:120px">النسبة الإجمالية</th>
                        <th style="width:100px">المعدل التراكمي</th>
                        <th style="width:80px">المواد الراسبة</th>
                        <th style="width:150px">تفاصيل المواد الراسبة</th>
                        <th style="width:100px">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($semesterResults)): ?>
                        <tr>
                            <td colspan="8" class="table-empty">
                                <div class="table-empty-icon">📭</div>
                                <div class="table-empty-text">لا توجد نتائج محسوبة بعد - اضغط على زر "حساب نتائج الفصل"</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $rank = 1;
                        $passTotal = 0;
                        $failTotal = 0;
                        ?>
                        <?php foreach ($semesterResults as $idx => $sr): ?>
                            <?php
                            $gpa = (float)($sr['gpa'] ?? 0);
                            $pct = (float)($sr['total_percentage'] ?? 0);
                            $carryOvers = json_decode($sr['carry_over_subjects'], true) ?? [];
                            $carryCount = count($carryOvers);
                            $isPassing = $pct >= 50;

                            if ($isPassing) $passTotal++; else $failTotal++;

                            $letterInfo = $calculator->getLetterGrade($pct);
                            ?>
                            <tr style="<?= !$isPassing ? 'background-color:rgba(220,53,69,0.05)' : '' ?>">
                                <td class="text-center">
                                    <?php if ($rank <= 3): ?>
                                        <span style="font-size:1.2em;"><?= ['🥇','🥈','🥉'][$rank-1] ?></span>
                                    <?php else: ?>
                                        <?= $rank ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($sr['student_number']) ?></td>
                                <td><strong><?= e($sr['full_name_ar']) ?></strong></td>
                                <td class="text-center">
                                    <strong style="color:<?= $isPassing ? 'var(--success)' : 'var(--danger)' ?>; font-size:var(--font-size-base);">
                                        <?= $pct ? round($pct, 2) . '%' : '-' ?>
                                    </strong>
                                    <br>
                                    <span class="badge badge-<?= in_array($letterInfo['letter'], ['A+','A','B+','B']) ? 'success' : (in_array($letterInfo['letter'], ['C+','C']) ? 'info' : 'warning') ?>" style="font-size:10px;">
                                        <?= e($letterInfo['letter']) ?> - <?= e($letterInfo['name_ar']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong style="color:var(--primary);"><?= $gpa ? number_format($gpa, 2) : '-' ?></strong>
                                    <div class="text-xs text-secondary">/4.00</div>
                                </td>
                                <td class="text-center">
                                    <?php if ($carryCount > 0): ?>
                                        <span class="badge badge-<?= $carryCount > 2 ? 'danger' : 'warning' ?>"><?= $carryCount ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($carryCount > 0): ?>
                                        <div class="text-xs" style="max-height:60px; overflow-y:auto;">
                                            <?php foreach ($carryOvers as $co): ?>
                                                <span class="badge badge-danger mr-1" style="font-size:10px; margin:2px;">
                                                    <?= e($co['subject_name'] ?? 'غير معروف') ?> (<?= ($co['percentage'] ?? 0) ?>%)
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-success text-sm">لا توجد مواد راسبة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isPassing): ?>
                                        <span class="badge badge-success">✅ ناجح</span>
                                    <?php else: ?>
                                        <?php if ($classStage === 'basic' && $carryCount <= 2): ?>
                                            <span class="badge badge-warning">⚠️ ترفيع مشروط</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">❌ راسب</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $rank++; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($semesterResults)): ?>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:left;"><strong>ملخص</strong></td>
                        <td class="text-center"><strong>النسبة: <?= round(array_sum(array_column($semesterResults, 'total_percentage')) / max(count($semesterResults), 1), 2) ?>%</strong></td>
                        <td class="text-center"><strong>المعدل: <?= round(array_sum(array_map(function($r){return (float)($r['gpa']??0);}, $semesterResults)) / max(count($semesterResults), 1), 2) ?></strong></td>
                        <td colspan="2" class="text-center">
                            <span style="color:var(--success);"><?= $passTotal ?> ناجح</span> /
                            <span style="color:var(--danger);"><?= $failTotal ?> راسب</span>
                        </td>
                        <td class="text-center">
                            <strong><?= round(($passTotal / max(count($semesterResults), 1)) * 100, 1) ?>%</strong> نسبة النجاح
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     ملاحظات النظام
     ============================================================ -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">ℹ️ ملاحظات نظام التقييم</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <div style="padding:var(--space-4); background:rgba(40,167,69,0.06); border-radius:var(--radius-md);">
                <h4 style="color:var(--success); margin-bottom:var(--space-2);">📚 المرحلة الأساسية (1-9)</h4>
                <ul style="padding-right:var(--space-6); line-height:2;">
                    <li>يُرحّل الطالب عادياً مع حد أقصى <strong>مادتين راسبتين</strong> (carry over)</li>
                    <li>إذا رسب في أكثر من مادتين يُعاد السنة</li>
                    <li>المواد الراسبة تُنقل للعام القادم</li>
                </ul>
            </div>
            <div style="padding:var(--space-4); background:rgba(220,53,69,0.06); border-radius:var(--radius-md);">
                <h4 style="color:var(--danger); margin-bottom:var(--space-2);">🏫 المرحلة الثانوية (10-12)</h4>
                <ul style="padding-right:var(--space-6); line-height:2;">
                    <li><strong>لا ترفيع مع مواد راسبة</strong> - يجب اجتياز جميع المواد</li>
                    <li>يُسمح باختبارات تعويضية (حد أقصى محاولتين)</li>
                    <li>الرسوب في أي مادة يعني إعادة السنة</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- أنماط الطباعة -->
<style>
    @media print {
        .sidebar, .header, .btn, .flash-message, form { display: none !important; }
        .main-content { margin: 0 !important; }
    }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
