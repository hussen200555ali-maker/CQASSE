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
$section = $_GET['section'] ?? '';

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
$skillsData = null;

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
                              JOIN tests t ON t.id = g.test_id
                              JOIN subjects sub ON sub.id = t.subject_id
                              WHERE g.student_id = ? AND g.academic_year_id = ? AND g.semester_id = ? AND g.is_active = 1
                              ORDER BY sub.name_ar");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $gradeDetails = $stmt->fetchAll();

        // تقييم السلوك
        $stmt = $db->prepare("SELECT be.*, be.current_percentage as overall_score
                              FROM behavior_scores be
                              WHERE be.student_id = ? AND be.academic_year_id = ? AND be.semester_id = ? AND be.is_active = 1
                              ORDER BY be.updated_at DESC LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $behaviorScore = $stmt->fetch();

        // ملخص الحضور
        $stmt = $db->prepare("SELECT *
                              FROM v_attendance_summary
                              WHERE student_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1
                              LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $attendanceSummary = $stmt->fetch();

        // تقييم المهارات
        $stmt = $db->prepare("SELECT e.id, e.combined_score as overall_score, e.created_at as evaluation_date
                              FROM evaluations e
                              WHERE e.student_id = ? AND e.academic_year_id = ? AND e.semester_id = ?
                                AND e.eval_type = 'skills_checklist' AND e.is_active = 1
                              ORDER BY e.created_at DESC LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $skillsData = $stmt->fetch();

        // إقرار ولي الأمر
        $stmt = $db->prepare("SELECT ps.ack_grade as is_acknowledged, ps.last_ack_at as acknowledged_at,
                              p.full_name_ar as parent_name
                              FROM parent_students ps
                              JOIN users p ON p.id = ps.parent_id
                              WHERE ps.student_id = ? AND ps.parent_id = ?");
        $stmt->execute([$studentId, $userId]);
        $parentAcknowledgment = $stmt->fetch();
    }
}

// معالجة الإقرار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare("UPDATE parent_students SET ack_grade = 1, last_ack_at = NOW() WHERE student_id = ? AND parent_id = ?");
        $stmt->execute([$studentId, $userId]);
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

<style>
.parent-tabs { display:flex; gap:2px; margin-bottom:20px; border-bottom:2px solid #e9ecef; }
.parent-tabs a { display:block; padding:6px 14px; text-decoration:none; font-size:13px; border-radius:4px 4px 0 0; transition:all .15s; color:#495057; background:#f8f9fa; border:1px solid #e9ecef; border-bottom:none; margin-bottom:-2px; }
.parent-tabs a:hover { background:#e9ecef; }
.parent-tabs a.active { background:#1a5632; color:#fff; border-color:#1a5632; }
.section-card { border:1px solid #e9ecef; border-radius:6px; margin-bottom:16px; overflow:hidden; }
.section-card .card-header { padding:10px 16px; background:#f8f9fa; border-bottom:1px solid #e9ecef; font-size:14px; font-weight:600; }
.section-card .card-body { padding:16px; }
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
.stat-item { text-align:center; padding:12px 8px; background:#f8f9fa; border-radius:6px; }
.stat-item .label { font-size:11px; color:#6c757d; margin-bottom:4px; }
.stat-item .value { font-size:20px; font-weight:700; }
.ack-card { border:2px solid #1a5632; border-radius:8px; margin-top:20px; }
.ack-card .card-header { background:#1a5632; color:#fff; padding:12px 16px; font-weight:700; font-size:15px; border-radius:6px 6px 0 0; }
.ack-card .card-body { padding:20px; }
</style>

<!-- أقسام التنقل -->
<?php if ($studentData): ?>
<div class="parent-tabs">
    <a href="#section-grades" class="tab-link active" data-section="grades">📊 الدرجات</a>
    <a href="#section-behavior" class="tab-link" data-section="behavior">📋 السلوك</a>
    <a href="#section-skills" class="tab-link" data-section="skills">🎯 المهارات</a>
    <a href="#section-attendance" class="tab-link" data-section="attendance">📅 الحضور</a>
</div>

<!-- ============================================================
     قسم الدرجات
     ============================================================ -->
<div class="section-card" id="section-grades">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <strong>📊 الدرجات - <?php echo e($studentData['full_name_ar']); ?></strong>
            <span style="font-size:11px;color:#6c757d;margin-right:8px;"><?php echo e($studentData['school_name'] . ' | ' . $studentData['grade_name']); ?></span>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-secondary">🖨️ طباعة</button>
    </div>
    <div class="card-body">
        <?php
        $totalGrade = 0;
        $gradeCount = 0;
        foreach ($gradeDetails as $gd) {
            $totalGrade += $gd['grade_value'];
            $gradeCount++;
        }
        $avgGrade = $gradeCount > 0 ? round($totalGrade / $gradeCount, 2) : 0;
        ?>
        <?php if (!empty($gradeDetails)): ?>
        <table class="table table-bordered table-sm" style="font-size:13px;">
            <thead class="table-primary">
                <tr>
                    <th>المادة</th>
                    <th style="width:80px;">الدرجة</th>
                    <th style="width:160px;">النسبة</th>
                    <th style="width:70px;">الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gradeDetails as $gd): ?>
                <tr>
                    <td><?php echo e($gd['subject_name']); ?></td>
                    <td class="text-center <?php echo $gd['grade_value'] < 50 ? 'text-danger fw-bold' : ''; ?>">
                        <?php echo $gd['grade_value']; ?>
                    </td>
                    <td>
                        <div class="progress" style="height:16px;">
                            <div class="progress-bar <?php echo $gd['grade_value'] >= 50 ? 'bg-success' : 'bg-danger'; ?>"
                                 style="width:<?php echo min($gd['grade_value'], 100); ?>%;font-size:11px;line-height:16px;">
                                <?php echo $gd['grade_value']; ?>%
                            </div>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $gd['grade_value'] >= 50 ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $gd['grade_value'] >= 50 ? 'ناجح' : 'راسب'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="stat-grid" style="margin-top:12px;">
            <div class="stat-item">
                <div class="label">المعدل العام</div>
                <div class="value" style="color:<?php echo $avgGrade >= 50 ? '#28a745' : '#dc3545'; ?>"><?php echo $avgGrade; ?>%</div>
            </div>
            <div class="stat-item">
                <div class="label">التقييم السلوكي</div>
                <div class="value" style="color:<?php echo $behaviorScore && $behaviorScore['overall_score'] >= 70 ? '#28a745' : '#dc3545'; ?>">
                    <?php echo $behaviorScore ? $behaviorScore['overall_score'] . '%' : '—'; ?>
                </div>
            </div>
            <div class="stat-item">
                <div class="label">نسبة الحضور</div>
                <div class="value" style="color:<?php echo $attendanceSummary && $attendanceSummary['attendance_rate'] >= 85 ? '#28a745' : ($attendanceSummary && $attendanceSummary['attendance_rate'] >= 70 ? '#ffc107' : '#dc3545'); ?>">
                    <?php echo $attendanceSummary ? $attendanceSummary['attendance_rate'] . '%' : '—'; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="padding:10px 14px;font-size:13px;margin:0;">لا توجد درجات مسجلة لهذا الفصل الدراسي.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     قسم السلوك
     ============================================================ -->
<div class="section-card" id="section-behavior">
    <div class="card-header"><strong>📋 تقييم السلوك</strong></div>
    <div class="card-body">
        <?php if ($behaviorScore): ?>
        <div class="stat-grid">
            <div class="stat-item">
                <div class="label">درجة السلوك</div>
                <div class="value" style="color:<?php echo $behaviorScore['overall_score'] >= 70 ? '#28a745' : ($behaviorScore['overall_score'] >= 50 ? '#ffc107' : '#dc3545'); ?>">
                    <?php echo $behaviorScore['overall_score']; ?>%
                </div>
            </div>
            <div class="stat-item">
                <div class="label">الحالة</div>
                <div class="value" style="font-size:16px;">
                    <span class="badge <?php echo $behaviorScore['is_passing'] ? 'badge-success' : 'badge-danger'; ?>">
                        <?php echo $behaviorScore['is_passing'] ? '✅ مقبول' : '❌ غير مقبول'; ?>
                    </span>
                </div>
            </div>
        </div>
        <?php if (!empty($behaviorScore['notes'])): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f8f9fa;border-radius:4px;font-size:13px;">
            <strong>💬 ملاحظات:</strong><br><?php echo nl2br(e($behaviorScore['notes'])); ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="alert alert-info" style="padding:10px 14px;font-size:13px;margin:0;">لا توجد بيانات سلوك مسجلة لهذا الفصل الدراسي.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     قسم المهارات
     ============================================================ -->
<div class="section-card" id="section-skills">
    <div class="card-header"><strong>🎯 تقييم المهارات</strong></div>
    <div class="card-body">
        <?php if ($skillsData): ?>
        <div class="stat-grid">
            <div class="stat-item">
                <div class="label">الدرجة الكلية</div>
                <div class="value" style="color:<?php echo $skillsData['overall_score'] >= 70 ? '#28a745' : ($skillsData['overall_score'] >= 50 ? '#ffc107' : '#dc3545'); ?>">
                    <?php echo $skillsData['overall_score']; ?>%
                </div>
            </div>
            <div class="stat-item">
                <div class="label">تاريخ التقييم</div>
                <div class="value" style="font-size:16px;"><?php echo $skillsData['evaluation_date'] ? formatDateAr($skillsData['evaluation_date'], 'short') : '—'; ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="padding:10px 14px;font-size:13px;margin:0;">لا توجد بيانات مهارات مسجلة لهذا الفصل الدراسي.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     قسم الحضور
     ============================================================ -->
<div class="section-card" id="section-attendance">
    <div class="card-header"><strong>📅 سجل الحضور</strong></div>
    <div class="card-body">
        <?php if ($attendanceSummary): ?>
        <div class="stat-grid">
            <div class="stat-item">
                <div class="label">إجمالي الأيام</div>
                <div class="value"><?php echo $attendanceSummary['total_days']; ?></div>
            </div>
            <div class="stat-item">
                <div class="label">أيام الحضور</div>
                <div class="value" style="color:#28a745;"><?php echo $attendanceSummary['present_days']; ?></div>
            </div>
            <div class="stat-item">
                <div class="label">أيام الغياب</div>
                <div class="value" style="color:#dc3545;"><?php echo $attendanceSummary['absent_days']; ?></div>
            </div>
            <div class="stat-item">
                <div class="label">أيام التأخير</div>
                <div class="value" style="color:#ffc107;"><?php echo $attendanceSummary['late_days']; ?></div>
            </div>
        </div>
        <div class="stat-grid" style="margin-top:12px;">
            <div class="stat-item">
                <div class="label">نسبة الحضور</div>
                <div class="value" style="color:<?php echo $attendanceSummary['attendance_rate'] >= 85 ? '#28a745' : ($attendanceSummary['attendance_rate'] >= 70 ? '#ffc107' : '#dc3545'); ?>">
                    <?php echo $attendanceSummary['attendance_rate']; ?>%
                </div>
            </div>
            <div class="stat-item">
                <div class="label">التقييم</div>
                <div class="value" style="font-size:16px;">
                    <span class="badge <?php echo $attendanceSummary['attendance_rate'] >= 85 ? 'badge-success' : ($attendanceSummary['attendance_rate'] >= 70 ? 'badge-warning' : 'badge-danger'); ?>">
                        <?php echo $attendanceSummary['attendance_rate'] >= 85 ? 'ممتاز' : ($attendanceSummary['attendance_rate'] >= 70 ? 'مقبول' : 'ضعيف'); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="padding:10px 14px;font-size:13px;margin:0;">لا توجد بيانات حضور مسجلة لهذا الفصل الدراسي.</div>
        <?php endif; ?>
    </div>
</div>

<!-- إقرار ولي الأمر -->
<?php if ($userRole === 'parent'): ?>
<div class="ack-card">
    <div class="card-header">✅ إقرار ولي الأمر</div>
    <div class="card-body">
        <?php if ($parentAcknowledgment && $parentAcknowledgment['is_acknowledged']): ?>
            <div class="alert alert-success" style="padding:12px 16px;margin:0;">
                ✅ <strong>تم الإقرار</strong> في <?php echo formatDateAr($parentAcknowledgment['acknowledged_at'], 'full'); ?>
            </div>
        <?php else: ?>
            <p style="margin:0 0 12px;font-size:14px;">أقر بأنني قد اطلعت على درجات وسلوك وحضور ابني/ابنتي المذكورة أعلاه.</p>
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom:10px;">
                    <textarea name="notes" class="form-control" rows="2" placeholder="ملاحظات (اختياري)"></textarea>
                </div>
                <button type="submit" name="acknowledge" value="1" class="btn btn-primary">✅ إقرار بالاطلاع</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.parent-tabs a');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            tabs.forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            var target = this.getAttribute('href');
            var el = document.querySelector(target);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    var section = new URLSearchParams(window.location.search).get('section');
    if (section) {
        var targetEl = document.getElementById('section-' + section);
        if (targetEl) {
            setTimeout(function() {
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                tabs.forEach(function(t) {
                    t.classList.toggle('active', t.getAttribute('data-section') === section);
                });
            }, 300);
        }
    }
});
</script>

<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
