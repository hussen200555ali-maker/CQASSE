<?php
/**
 * ============================================================
 * EQAS - كشوف الدرجات - إنشاء كشف
 * نظام التقييم الشامل - صفحة إنشاء كشف درجات
 * ============================================================
 */

ob_start();

$pageTitle = 'إنشاء كشف درجات';
$breadcrumb = ['كشوف الدرجات' => 'index.php', 'إنشاء كشف' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// المعاملات
$classId = $_GET['class_id'] ?? $_POST['class_id'] ?? '';
$academicYearId = $_GET['academic_year_id'] ?? $_POST['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = $_GET['semester_id'] ?? $_POST['semester_id'] ?? '';

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('generate.php?class_id=' . $classId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
    }

    $format = $_POST['format'] ?? 'xlsx';
    $gradeData = generateGradeData($db, $classId, $academicYearId, $semesterId);

    if (!empty($gradeData['students'])) {
        $headers = array_merge(['#', 'رقم الطالب', 'اسم الطالب'], $gradeData['subjects'], ['المجموع', 'المعدل', 'الترتيب', 'الحالة']);
        $rows = [];
        foreach ($gradeData['students'] as $rank => $student) {
            $row = [$rank + 1, $student['student_number'], $student['full_name_ar']];
            foreach ($gradeData['subject_ids'] as $sid) {
                $row[] = $student['grades'][$sid] ?? '-';
            }
            $row[] = $student['total'];
            $row[] = $student['average'];
            $row[] = $rank + 1;
            $row[] = $student['status'];
            $rows[] = $row;
        }

        $baseFilename = 'كشف_درجات_' . $gradeData['class_info']['class_name'];

        // دعم تصدير PDF
        if ($format === 'pdf') {
            require_once __DIR__ . '/../../includes/export.php';
            $pdfResult = exportGradeSheetPDF($db, $gradeData, $baseFilename . '.pdf');
            if ($pdfResult['success']) {
                ob_end_clean(); // مسح المخرجات المخزنة
                if ($pdfResult['method'] === 'html_print') {
                    header('Content-Type: text/html; charset=utf-8');
                    readfile($pdfResult['file_path']);
                } else {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $pdfResult['file_name'] . '"');
                    header('Content-Length: ' . filesize($pdfResult['file_path']));
                    readfile($pdfResult['file_path']);
                }
                @unlink($pdfResult['file_path']);
                exit;
            }
            setFlash('فشل تصدير PDF: ' . ($pdfResult['message'] ?? 'خطأ غير معروف'), 'danger');
            redirect('generate.php?class_id=' . $classId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
        }

        $exportInfo = createExportFile($db, $_SESSION['user']['id'], 'grade_sheet', $format, $rows, $headers, $baseFilename, ['class_id' => $classId]);
        downloadExportFile($exportInfo['file_path'], $exportInfo['file_name']);
    }
}

// جلب البيانات
$academicYears = [];
$stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
$academicYears = $stmt->fetchAll();

// جلب الفصول المتاحة
$classes = [];
$schoolId = $_SESSION['user']['school_id'] ?? 0;
if (hasPermission('group_admin') && !$schoolId) {
    $stmt = $db->prepare("SELECT c.id, c.name_ar, gl.name_ar as grade_name, s.name_ar as school_name
                          FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id
                          JOIN schools s ON c.school_id = s.id
                          WHERE c.academic_year_id = ? AND c.is_active = 1
                          ORDER BY s.name_ar, gl.level_number");
    $stmt->execute([$academicYearId]);
} else {
    $stmt = $db->prepare("SELECT c.id, c.name_ar, gl.name_ar as grade_name, s.name_ar as school_name
                          FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id
                          JOIN schools s ON c.school_id = s.id
                          WHERE c.academic_year_id = ? AND c.school_id = ? AND c.is_active = 1
                          ORDER BY gl.level_number, c.name_ar");
    $stmt->execute([$academicYearId, $schoolId]);
}
$classes = $stmt->fetchAll();

// جلب الفصول الدراسية
$semesters = [];
if ($academicYearId) {
    $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
    $stmt->execute([$academicYearId]);
    $semesters = $stmt->fetchAll();
}

// توليد بيانات الكشف (مع تخزين مؤقت)
$gradeData = null;
$snapshotId = null;
if ($classId && $academicYearId && $semesterId) {
    // محاولة جلب لقطة محفوظة أولاً
    $gradeData = getCachedSnapshot($db, $classId, $semesterId);
    if (!$gradeData) {
        $gradeData = generateGradeData($db, $classId, $academicYearId, $semesterId);
        if ($gradeData) {
            $snapshotId = saveGradeSheetSnapshot($db, $gradeData, $_SESSION['user']['id']);
        }
    } else {
        // اللقطة من الذاكرة المؤقتة
        $snapshotId = 'cached';
    }
}

/**
 * توليد بيانات كشف الدرجات
 */
function generateGradeData($db, $classId, $academicYearId, $semesterId) {
    // معلومات الفصل
    $stmt = $db->prepare("SELECT c.*, gl.name_ar as grade_name, s.name_ar as school_name
                          FROM classes c
                          JOIN grade_levels gl ON c.grade_level_id = gl.id
                          JOIN schools s ON c.school_id = s.id
                          WHERE c.id = ?");
    $stmt->execute([$classId]);
    $classInfo = $stmt->fetch();
    if (!$classInfo) return null;

    // المواد الدراسية للصف
    $stmt = $db->prepare("SELECT DISTINCT sub.id, sub.name_ar
                          FROM subjects sub
                          JOIN subject_teachers st ON st.subject_id = sub.id
                           WHERE st.classroom_id = ? AND st.academic_year_id = ? AND sub.is_active = 1
                          ORDER BY sub.name_ar");
    $stmt->execute([$classId, $academicYearId]);
    $subjects = $stmt->fetchAll();
    $subjectIds = array_column($subjects, 'id');
    $subjectNames = array_column($subjects, 'name_ar');

    // الطلاب المسجلين
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar
                          FROM students s
                          JOIN student_enrollments se ON se.student_id = s.id
                          WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled' AND s.is_active = 1
                          ORDER BY s.full_name_ar");
    $stmt->execute([$classId, $academicYearId]);
    $students = $stmt->fetchAll();

    // درجات الطلاب
    foreach ($students as &$student) {
        $stmt = $db->prepare("SELECT subject_id, grade_value FROM grades
                              WHERE student_id = ? AND class_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1");
        $stmt->execute([$student['id'], $classId, $academicYearId, $semesterId]);
        $grades = $stmt->fetchAll();

        $studentGrades = [];
        $total = 0;
        $subjectCount = 0;
        foreach ($grades as $g) {
            $studentGrades[$g['subject_id']] = $g['grade_value'];
            $total += $g['grade_value'];
            $subjectCount++;
        }
        $student['grades'] = $studentGrades;
        $student['total'] = $subjectCount > 0 ? $total : 0;
        $student['average'] = $subjectCount > 0 ? round($total / $subjectCount, 2) : 0;
        $student['subject_count'] = $subjectCount;
        $student['status'] = $student['average'] >= 50 ? 'ناجح' : 'راسب';
    }
    unset($student);

    // حساب الترتيب
    usort($students, function($a, $b) {
        return $b['average'] <=> $a['average'];
    });

    // جلب إعداد درجة النجاح
    $passingGrade = 50;
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'grading' AND setting_key = 'passing_grade' AND school_id IS NULL AND is_active = 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) $passingGrade = (float)$row['setting_value'];

    return [
        'class_info' => $classInfo,
        'subjects' => $subjectNames,
        'subject_ids' => $subjectIds,
        'students' => $students,
        'passing_grade' => $passingGrade,
        'semester_id' => $semesterId,
        'academic_year_id' => $academicYearId
    ];
}

/**
 * حفظ لقطة كشف الدرجات في قاعدة البيانات
 * @param PDO $db اتصال قاعدة البيانات
 * @param array $gradeData بيانات الكشف
 * @param int $userId معرف المستخدم
 * @return int|null معرف اللقطة
 */
function saveGradeSheetSnapshot($db, $gradeData, $userId) {
    try {
        $snapshotData = json_encode($gradeData, JSON_UNESCAPED_UNICODE);
        $checksum = md5($snapshotData);

        // التحقق من وجود لقطة مطابقة
        $stmt = $db->prepare("SELECT id FROM grade_sheet_snapshots 
                              WHERE class_id = ? AND semester_id = ? AND checksum = ? AND is_active = 1
                              LIMIT 1");
        $stmt->execute([
            $gradeData['class_info']['id'] ?? 0,
            $gradeData['semester_id'] ?? 0,
            $checksum
        ]);
        $existing = $stmt->fetch();
        if ($existing) return $existing['id'];

        // حفظ لقطة جديدة
        $stmt = $db->prepare("INSERT INTO grade_sheet_snapshots 
                              (school_id, class_id, academic_year_id, semester_id, snapshot_data, checksum, student_count, subject_count, generated_by, generated_at, is_active)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)");
        $stmt->execute([
            $gradeData['class_info']['school_id'] ?? $gradeData['class_info']['id'] ?? 0,
            $gradeData['class_info']['id'] ?? 0,
            $gradeData['academic_year_id'] ?? 0,
            $gradeData['semester_id'] ?? 0,
            $snapshotData,
            $checksum,
            count($gradeData['students'] ?? []),
            count($gradeData['subjects'] ?? []),
            $userId
        ]);
        return $db->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * جلب لقطة كشف درجات محفوظة
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $classId معرف الفصل
 * @param int $semesterId معرف الفصل الدراسي
 * @return array|null بيانات اللقطة أو null
 */
function getCachedSnapshot($db, $classId, $semesterId) {
    try {
        $stmt = $db->prepare("SELECT * FROM grade_sheet_snapshots 
                              WHERE class_id = ? AND semester_id = ? AND is_active = 1
                              ORDER BY generated_at DESC LIMIT 1");
        $stmt->execute([$classId, $semesterId]);
        $snapshot = $stmt->fetch();
        if (!$snapshot) return null;

        // التحقق من صلاحية اللقطة (أقل من ساعة)
        $generatedAt = new DateTime($snapshot['generated_at']);
        $now = new DateTime();
        $diff = $now->diff($generatedAt);
        $totalMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

        if ($totalMinutes > 60) return null; // انتهت صلاحية اللقطة

        return json_decode($snapshot['snapshot_data'], true);
    } catch (Exception $e) {
        return null;
    }
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">📝 إنشاء كشف درجات جديد</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="form-grid">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">السنة الأكاديمية</label>
                <select name="academic_year_id" class="form-control" required>
                    <option value="">اختر السنة</option>
                    <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo $year['id']; ?>" <?php echo $academicYearId == $year['id'] ? 'selected' : ''; ?>>
                        <?php echo e($year['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">الفصل الدراسي</label>
                <select name="semester_id" class="form-control" required>
                    <option value="">اختر الفصل</option>
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?php echo $sem['id']; ?>" <?php echo $semesterId == $sem['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sem['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">الفصل (الصف)</label>
                <select name="class_id" class="form-control" required>
                    <option value="">اختر الفصل</option>
                    <?php foreach ($classes as $cls): ?>
                    <option value="<?php echo $cls['id']; ?>" <?php echo $classId == $cls['id'] ? 'selected' : ''; ?>>
                        <?php echo e(($cls['school_name'] ?? '') . ' - ' . $cls['grade_name'] . ' - ' . $cls['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary">📊 عرض الكشف</button>
            </div>
        </form>
    </div>
</div>

<?php if ($gradeData && !empty($gradeData['students'])): ?>
<div class="card mt-4" id="grade-sheet-printable">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="card-title">📋 كشف درجات - <?php echo e($gradeData['class_info']['school_name']); ?></h2>
            <p class="text-sm text-muted mt-1">
                <?php echo e($gradeData['class_info']['grade_name'] . ' - ' . $gradeData['class_info']['class_name']); ?>
                | درجة النجاح: <?php echo $gradeData['passing_grade']; ?>%
            </p>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="window.print()" class="btn btn-secondary">🖨️ طباعة</button>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo $academicYearId; ?>">
                <input type="hidden" name="semester_id" value="<?php echo $semesterId; ?>">
                <input type="hidden" name="export" value="1">
                <input type="hidden" name="format" value="xlsx">
                <button type="submit" class="btn btn-success">📥 تصدير XLSX</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr class="table-primary">
                        <th rowspan="2" style="vertical-align:middle;">#</th>
                        <th rowspan="2" style="vertical-align:middle;">رقم الطالب</th>
                        <th rowspan="2" style="vertical-align:middle;">اسم الطالب</th>
                        <th colspan="<?php echo count($gradeData['subjects']); ?>">المواد الدراسية</th>
                        <th rowspan="2" style="vertical-align:middle;">المجموع</th>
                        <th rowspan="2" style="vertical-align:middle;">المعدل</th>
                        <th rowspan="2" style="vertical-align:middle;">الترتيب</th>
                        <th rowspan="2" style="vertical-align:middle;">الحالة</th>
                    </tr>
                    <tr class="table-primary">
                        <?php foreach ($gradeData['subjects'] as $subjectName): ?>
                        <th><?php echo e($subjectName); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gradeData['students'] as $rank => $student): ?>
                    <tr>
                        <td><?php echo $rank + 1; ?></td>
                        <td><?php echo e($student['student_number']); ?></td>
                        <td><strong><?php echo e($student['full_name_ar']); ?></strong></td>
                        <?php foreach ($gradeData['subject_ids'] as $sid): ?>
                        <td class="<?php echo isset($student['grades'][$sid]) && $student['grades'][$sid] < $gradeData['passing_grade'] ? 'text-danger' : ''; ?>">
                            <?php echo isset($student['grades'][$sid]) ? $student['grades'][$sid] : '-'; ?>
                        </td>
                        <?php endforeach; ?>
                        <td><strong><?php echo $student['total']; ?></strong></td>
                        <td>
                            <strong class="<?php echo $student['average'] >= $gradeData['passing_grade'] ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $student['average']; ?>%
                            </strong>
                        </td>
                        <td>
                            <span class="badge <?php echo $rank < 3 ? 'badge-warning' : 'badge-secondary'; ?>">
                                <?php echo $rank + 1; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo $student['average'] >= $gradeData['passing_grade'] ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $student['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="3"><strong>الإحصائيات</strong></td>
                        <?php foreach ($gradeData['subject_ids'] as $sid): ?>
                        <td>
                            <?php
                            $subjectGrades = array_filter(array_map(function($s) use ($sid) {
                                return $s['grades'][$sid] ?? null;
                            }, $gradeData['students']), function($v) { return $v !== null; });
                            echo !empty($subjectGrades) ? round(array_sum($subjectGrades) / count($subjectGrades), 1) : '-';
                            ?>
                        </td>
                        <?php endforeach; ?>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php elseif ($classId && $semesterId): ?>
<div class="card mt-4">
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <p class="empty-state-text">لا توجد بيانات درجات لهذا الفصل في الفصل الدراسي المحدد.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
