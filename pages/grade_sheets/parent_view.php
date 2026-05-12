<?php
/**
 * ============================================================
 * EQAS - كشوف الدرجات - عرض ولي الأمر
 * نظام التقييم الشامل - عرض مبسط لأولياء الأمور
 * ============================================================
 */

$pageTitle = 'درجات الطالب';
$breadcrumb = ['درجات الطالب' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - ولي الأمر فقط
$userRole = $_SESSION['user']['role'] ?? '';
if ($userRole !== 'parent' && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// جلب طلاب ولي الأمر
$userId = $_SESSION['user']['id'];
$studentId = $_GET['student_id'] ?? '';
$academicYearId = $_GET['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = $_GET['semester_id'] ?? '';

// جلب أبناء ولي الأمر
$myStudents = [];
if ($userRole === 'parent') {
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar, s.grade_level_id, gl.name_ar as grade_name
                          FROM students s
                          JOIN parent_students ps ON ps.student_id = s.id
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          WHERE ps.parent_id = ? AND s.is_active = 1 AND ps.is_active = 1
                          ORDER BY s.full_name_ar");
    $stmt->execute([$userId]);
    $myStudents = $stmt->fetchAll();
} elseif (hasPermission('school_admin')) {
    // مدير المدرسة يمكنه عرض أي طالب
    $schoolId = $_SESSION['user']['school_id'] ?? 0;
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar, s.grade_level_id, gl.name_ar as grade_name
                          FROM students s
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          WHERE s.school_id = ? AND s.is_active = 1
                          ORDER BY s.full_name_ar
                          LIMIT 50");
    $stmt->execute([$schoolId]);
    $myStudents = $stmt->fetchAll();
}

// اختيار أول طالب إذا لم يحدد
if (!$studentId && !empty($myStudents)) {
    $studentId = $myStudents[0]['id'];
}

// جلب السنوات والفصول
$stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
$academicYears = $stmt->fetchAll();

$semesters = [];
if ($academicYearId) {
    $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
    $stmt->execute([$academicYearId]);
    $semesters = $stmt->fetchAll();
    if (!$semesterId && !empty($semesters)) {
        $semesterId = $semesters[0]['id'];
    }
}

// جلب بيانات الطالب
$studentData = null;
$gradeDetails = [];
$behaviorScore = null;
$attendanceSummary = null;
$parentAcknowledgment = null;

if ($studentId && $academicYearId && $semesterId) {
    // معلومات الطالب
    $stmt = $db->prepare("SELECT s.*, gl.name_ar as grade_name, sch.name_ar as school_name
                          FROM students s
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          JOIN schools sch ON sch.id = s.school_id
                          WHERE s.id = ? AND s.is_active = 1");
    $stmt->execute([$studentId]);
    $studentData = $stmt->fetch();

    if ($studentData) {
        // الدرجات
        $stmt = $db->prepare("SELECT g.*, sub.name_ar as subject_name
                              FROM grades g
                              JOIN subjects sub ON sub.id = g.subject_id
                              WHERE g.student_id = ? AND g.academic_year_id = ? AND g.semester_id = ? AND g.is_active = 1
                              ORDER BY sub.name_ar");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $gradeDetails = $stmt->fetchAll();

        // تقييم السلوك
        $stmt = $db->prepare("SELECT be.*, u.full_name_ar as evaluator_name
                              FROM behavior_scores be
                              LEFT JOIN users u ON u.id = be.evaluator_id
                              WHERE be.student_id = ? AND be.academic_year_id = ? AND be.semester_id = ? AND be.is_active = 1
                              ORDER BY be.evaluation_date DESC LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $behaviorScore = $stmt->fetch();

        // ملخص الحضور
        $stmt = $db->prepare("SELECT * FROM attendance_summaries
                              WHERE student_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1
                              LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $attendanceSummary = $stmt->fetch();

        // إقرار ولي الأمر
        $stmt = $db->prepare("SELECT pa.*, p.full_name_ar as parent_name
                              FROM parent_acknowledgments pa
                              JOIN users p ON p.id = pa.parent_id
                              WHERE pa.student_id = ? AND pa.acknowledgment_type = 'grades'
                              AND pa.entity_id = ? AND pa.is_active = 1
                              ORDER BY pa.created_at DESC LIMIT 1");
        $stmt->execute([$studentId, $semesterId]);
        $parentAcknowledgment = $stmt->fetch();
    }
}

// معالجة الإقرار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare("INSERT INTO parent_acknowledgments (parent_id, student_id, acknowledgment_type, entity_id, is_acknowledged, acknowledged_at, notes, is_active, created_at)
                              VALUES (?, ?, 'grades', ?, 1, NOW(), ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE is_acknowledged = 1, acknowledged_at = NOW(), notes = ?");
        $stmt->execute([$userId, $studentId, $semesterId, $notes, $notes]);
        setFlash('تم تسجيل إقرارك بنجاح', 'success');
        redirect('parent_view.php?student_id=' . $studentId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
    }
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">🎓 عرض درجات الطالب</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="form-grid">
            <div class="form-group">
                <label class="form-label">الطالب</label>
                <select name="student_id" class="form-control">
                    <?php foreach ($myStudents as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $studentId == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo e($s['full_name_ar'] . ' - ' . $s['grade_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">السنة الأكاديمية</label>
                <select name="academic_year_id" class="form-control">
                    <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo $year['id']; ?>" <?php echo $academicYearId == $year['id'] ? 'selected' : ''; ?>>
                        <?php echo e($year['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">الفصل الدراسي</label>
                <select name="semester_id" class="form-control">
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?php echo $sem['id']; ?>" <?php echo $semesterId == $sem['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sem['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary">🔍 عرض</button>
            </div>
        </form>
    </div>
</div>

<?php if ($studentData): ?>
<div class="card mt-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="card-title">📋 كشف درجات - <?php echo e($studentData['full_name_ar']); ?></h2>
            <p class="text-sm text-muted"><?php echo e($studentData['school_name'] . ' | ' . $studentData['grade_name']); ?></p>
        </div>
        <button onclick="window.print()" class="btn btn-secondary">🖨️ طباعة</button>
    </div>
    <div class="card-body">
        <!-- ملخص الدرجات -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">المعدل العام</div>
                    <?php
                    $totalGrade = 0;
                    $gradeCount = 0;
                    foreach ($gradeDetails as $gd) {
                        $totalGrade += $gd['grade_value'];
                        $gradeCount++;
                    }
                    $avgGrade = $gradeCount > 0 ? round($totalGrade / $gradeCount, 2) : 0;
                    ?>
                    <div class="stat-card-value <?php echo $avgGrade >= 50 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $avgGrade; ?>%
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">التقييم السلوكي</div>
                    <div class="stat-card-value">
                        <?php echo $behaviorScore ? $behaviorScore['overall_score'] . '%' : 'غير متوفر'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">نسبة الحضور</div>
                    <div class="stat-card-value">
                        <?php echo $attendanceSummary ? $attendanceSummary['attendance_rate'] . '%' : 'غير متوفر'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- تفاصيل الدرجات -->
        <?php if (!empty($gradeDetails)): ?>
        <h4 class="mb-3">📊 تفاصيل الدرجات</h4>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>المادة</th>
                    <th>الدرجة</th>
                    <th>النسبة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gradeDetails as $gd): ?>
                <tr>
                    <td><?php echo e($gd['subject_name']); ?></td>
                    <td class="<?php echo $gd['grade_value'] < 50 ? 'text-danger font-weight-bold' : ''; ?>">
                        <?php echo $gd['grade_value']; ?>
                    </td>
                    <td>
                        <div class="progress" style="height:20px;">
                            <div class="progress-bar <?php echo $gd['grade_value'] >= 50 ? 'bg-success' : 'bg-danger'; ?>"
                                 style="width:<?php echo min($gd['grade_value'], 100); ?>%">
                                <?php echo $gd['grade_value']; ?>%
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $gd['grade_value'] >= 50 ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $gd['grade_value'] >= 50 ? 'ناجح' : 'راسب'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="alert alert-info">لا توجد درجات مسجلة لهذا الفصل الدراسي.</div>
        <?php endif; ?>

        <!-- ملخص الحضور -->
        <?php if ($attendanceSummary): ?>
        <h4 class="mb-3 mt-4">📅 ملخص الحضور</h4>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">إجمالي الأيام</div>
                    <div class="stat-card-value"><?php echo $attendanceSummary['total_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">أيام الحضور</div>
                    <div class="stat-card-value text-success"><?php echo $attendanceSummary['present_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">أيام الغياب</div>
                    <div class="stat-card-value text-danger"><?php echo $attendanceSummary['absent_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">أيام التأخير</div>
                    <div class="stat-card-value text-warning"><?php echo $attendanceSummary['late_days']; ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ملاحظات السلوك -->
        <?php if ($behaviorScore && $behaviorScore['notes']): ?>
        <h4 class="mb-3 mt-4">💬 ملاحظات السلوك</h4>
        <div class="alert alert-light border">
            <?php echo nl2br(e($behaviorScore['notes'])); ?>
        </div>
        <?php endif; ?>

        <!-- إقرار ولي الأمر -->
        <?php if ($userRole === 'parent'): ?>
        <div class="card mt-4" style="border:2px solid var(--primary);">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title text-white">✅ إقرار ولي الأمر</h3>
            </div>
            <div class="card-body">
                <?php if ($parentAcknowledgment && $parentAcknowledgment['is_acknowledged']): ?>
                    <div class="alert alert-success">
                        <strong>✅ تم الإقرار</strong> في <?php echo formatDateAr($parentAcknowledgment['acknowledged_at'], 'full'); ?>
                        <?php if ($parentAcknowledgment['notes']): ?>
                            <br>ملاحظات: <?php echo e($parentAcknowledgment['notes']); ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p>أقر بأنني قد اطلعت على درجات وسلوك وحضور ابني/ابنتي المذكورة أعلاه.</p>
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="form-label">ملاحظات (اختياري)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="أي ملاحظات تود إضافتها..."></textarea>
                        </div>
                        <button type="submit" name="acknowledge" value="1" class="btn btn-primary btn-lg">
                            ✅ إقرار بالاطلاع
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
