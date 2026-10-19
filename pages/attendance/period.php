<?php
/**
 * ============================================================
 * الحضور بالحصص (الثانوي)
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * تسجيل حضور الطلاب لكل حصة دراسية (للمدارس الثانوية)
 */

$pageTitle = 'الحضور بالحصص (الثانوي)';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'الحضور بالحصص' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = null;
try {
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $sem = $stmt->fetch();
    $semesterId = $sem ? $sem['id'] : null;
} catch (Exception $e) {}

// ============================================================
// معالجة حفظ الحضور بالحصص
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_period_attendance'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
    } else {
        $classId     = (int)($_POST['class_id'] ?? 0);
        $date        = $_POST['period_date'] ?? date('Y-m-d');
        $periodNum   = (int)($_POST['period_number'] ?? 1);
        $students    = $_POST['students'] ?? [];

        // فحص أمان: المعلم فقط لفصوله المسندة
        if ($userRole === 'teacher') {
            $assignedClasses = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!in_array($classId, $assignedClasses)) {
                setFlash('ليس لديك صلاحية لتسجيل الحضور في هذا الفصل', 'danger');
                redirect('period.php');
            }
        }

        if (empty($classId) || empty($students)) {
            setFlash('يرجى اختيار الفصل وتسجيل الحضور', 'warning');
        } else {
            try {
                $db->beginTransaction();
                $savedCount = 0;

                foreach ($students as $studentId => $data) {
                    $status = $data['status'] ?? 'present';
                    $notes  = trim($data['notes'] ?? '');

                    if (!in_array($status, ['present', 'absent', 'late', 'excused'])) continue;

                    // إدراج أو تحديث سجل الحضور بالحصة
                    $stmt = $db->prepare("
                        INSERT INTO attendance_daily 
                            (student_id, class_id, academic_year_id, semester_id, date, period_number, status, notes, recorded_by, is_active)
                        VALUES 
                            (:student_id, :class_id, :year_id, :semester_id, :date, :period, :status, :notes, :recorded_by, 1)
                        ON DUPLICATE KEY UPDATE 
                            status = VALUES(status),
                            notes = VALUES(notes),
                            recorded_by = VALUES(recorded_by)
                    ");
                    $stmt->execute([
                        ':student_id'  => $studentId,
                        ':class_id'    => $classId,
                        ':year_id'     => $academicYearId,
                        ':semester_id' => $semesterId,
                        ':date'        => $date,
                        ':period'      => $periodNum,
                        ':status'      => $status,
                        ':notes'       => $notes,
                        ':recorded_by' => $userId
                    ]);
                    $savedCount++;
                }

                // تحديث ملخص الحضور اليومي من الحصص
                updateDailyFromPeriods($db, $classId, $date, $academicYearId, $semesterId);

                $db->commit();
                setFlash("تم حفظ حضور الحصة {$periodNum} بنجاح لـ {$savedCount} طالب", 'success');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('حدث خطأ أثناء حفظ الحضور: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

/**
 * تحديث الحضور اليومي من سجلات الحصص
 * القاعدة: إذا غاب الطالب في أي حصة، يُعتبر غائباً لليوم
 * إذا تأخر في أي حصة، يُعتبر متأخراً لليوم
 */
function updateDailyFromPeriods($db, $classId, $date, $academicYearId, $semesterId) {
    try {
        // جلب جميع الطلاب الذين سُجل حضورهم بالحصص لهذا اليوم
        $stmt = $db->prepare("
            SELECT student_id,
                   COUNT(*) as total_periods,
                   SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_periods,
                   SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_periods,
                   SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_periods,
                   SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_periods
            FROM attendance_daily
            WHERE class_id = ? AND date = ? AND period_number IS NOT NULL AND is_active = 1
            GROUP BY student_id
        ");
        $stmt->execute([$classId, $date]);
        $periodSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($periodSummary as $summary) {
            // تحديد الحالة اليومية
            $dailyStatus = 'present';
            if ($summary['absent_periods'] > 0) {
                $dailyStatus = 'absent';
            } elseif ($summary['late_periods'] > 0) {
                $dailyStatus = 'late';
            } elseif ($summary['excused_periods'] > 0 && $summary['present_periods'] == 0) {
                $dailyStatus = 'excused';
            }

            // تحديث أو إدراج الحضور اليومي
            $stmt = $db->prepare("
                INSERT INTO attendance_daily 
                    (student_id, class_id, academic_year_id, semester_id, date, status, notes, recorded_by, is_active, created_at)
                VALUES (:student_id, :class_id, :year_id, :semester_id, :date, :status, 'تلقائي من الحصص', NULL, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                ':student_id'  => $summary['student_id'],
                ':class_id'    => $classId,
                ':year_id'     => $academicYearId,
                ':semester_id' => $semesterId,
                ':date'        => $date,
                ':status'      => $dailyStatus
            ]);
        }
    } catch (Exception $e) {
        error_log('EQAS updateDailyFromPeriods Error: ' . $e->getMessage());
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================
$filterClass  = $_GET['class_id'] ?? $_POST['class_id'] ?? '';
$filterDate   = $_GET['date'] ?? $_POST['period_date'] ?? date('Y-m-d');
$filterPeriod = (int)($_GET['period'] ?? $_POST['period_number'] ?? 1);

// جلب الفصول — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql = "SELECT c.id, c.name_ar FROM classes c WHERE c.id IN ($ph) AND c.is_active = 1 ORDER BY c.name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($allowedIds);
            $classes = $stmt->fetchAll();
        }
    } else {
        $sql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
        $params = [];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY name_ar";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// جلب طلاب الفصل المحدد
$students = [];
$existingPeriodAttendance = [];

if ($filterClass) {
    try {
        $roleFilterStudents = buildRoleFilter($db, $userRole, $userId, $schoolId, 'se.class_id');

        $stmt = $db->prepare("
            SELECT s.id, s.student_number, s.full_name_ar
            FROM students s
            INNER JOIN student_enrollments se ON se.student_id = s.id AND se.class_id = ? AND se.is_active = 1 AND se.status = 'enrolled'
            WHERE s.is_active = 1
            {$roleFilterStudents['sql']}
            ORDER BY s.full_name_ar
        ");
        $stmt->execute(array_merge([$filterClass], $roleFilterStudents['params']));
        $students = $stmt->fetchAll();

        // جلب سجلات الحضور بالحصة المحددة
        $roleFilterAtt = buildRoleFilter($db, $userRole, $userId, $schoolId, 'ad.class_id');
        $stmt = $db->prepare("
            SELECT ad.student_id, ad.status, ad.notes
            FROM attendance_daily ad
            INNER JOIN students s ON s.id = ad.student_id
            WHERE ad.class_id = ? AND ad.date = ? AND ad.period_number = ? AND ad.is_active = 1
            {$roleFilterAtt['sql']}
        ");
        $stmt->execute(array_merge([$filterClass, $filterDate, $filterPeriod], $roleFilterAtt['params']));
        while ($row = $stmt->fetch()) {
            $existingPeriodAttendance[$row['student_id']] = ['status' => $row['status'], 'notes' => $row['notes']];
        }

        // إذا لم توجد بيانات في هذه الحصة، نحاول جلبها من الحصة السابقة
        if (empty($existingPeriodAttendance) && $filterPeriod > 1) {
            $prevPeriod = (int)($_GET['copy_from'] ?? 0);
            if ($prevPeriod > 0 && $prevPeriod < $filterPeriod) {
                $stmt = $db->prepare("
                    SELECT ad.student_id, ad.status, ad.notes
                    FROM attendance_daily ad
                    INNER JOIN students s ON s.id = ad.student_id
                    WHERE ad.class_id = ? AND ad.date = ? AND ad.period_number = ? AND ad.is_active = 1
                    {$roleFilterAtt['sql']}
                ");
                $stmt->execute(array_merge([$filterClass, $filterDate, $prevPeriod], $roleFilterAtt['params']));
                while ($row = $stmt->fetch()) {
                    $existingPeriodAttendance[$row['student_id']] = ['status' => $row['status'], 'notes' => $row['notes']];
                }
            }
        }
    } catch (Exception $e) {}
}

// جلب عدد الحصص المتاحة (من سجلات موجودة أو افتراضي 7)
$maxPeriods = 7;
$completedPeriods = [];
try {
    $stmt = $db->prepare("
        SELECT MAX(period_number) as max_period 
        FROM attendance_daily 
        WHERE class_id = ? AND date = ? AND is_active = 1
    ");
    $stmt->execute([$filterClass, $filterDate]);
    $maxResult = $stmt->fetch();
    if ($maxResult && $maxResult['max_period']) {
        $maxPeriods = max(7, (int)$maxResult['max_period']);
    }
    // جلب مؤشرات إكمال الحصص
    if ($filterClass && $filterDate) {
        $stmt = $db->prepare("
            SELECT period_number FROM attendance_daily 
            WHERE class_id = ? AND date = ? AND period_number IS NOT NULL AND is_active = 1 
            GROUP BY period_number
        ");
        $stmt->execute([$filterClass, $filterDate]);
        $completedPeriods = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🕐 الحضور بالحصص (الثانوي)</h3>
        </div>
        <div class="card-body">
            <!-- اختيار الفصل والتاريخ والحصة -->
            <form method="GET" class="form-inline" style="margin-bottom:20px; gap:12px; flex-wrap:wrap;">
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الفصل:</label>
                    <select name="class_id" class="form-control" required style="min-width:200px;">
                        <option value="">-- اختر الفصل --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $filterClass == $cls['id'] ? 'selected' : '' ?>>
                                <?= e($cls['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">التاريخ:</label>
                    <input type="date" name="date" class="form-control" value="<?= e($filterDate) ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الحصة:</label>
                    <select name="period" class="form-control" style="width:auto;">
                        <?php for ($p = 1; $p <= $maxPeriods; $p++): 
                            $completed = in_array($p, $completedPeriods);
                        ?>
                        <option value="<?= $p ?>" <?= $filterPeriod === $p ? 'selected' : '' ?>>
                            <?= $completed ? '✅' : '⏳' ?> الحصة <?= $p ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">عرض</button>
            </form>

            <?php if ($filterClass && !empty($students)): ?>
            <form method="POST" id="period-attendance-form">
                <?= csrfField() ?>
                <input type="hidden" name="class_id" value="<?= e($filterClass) ?>">
                <input type="hidden" name="period_date" value="<?= e($filterDate) ?>">
                <input type="hidden" name="period_number" value="<?= e($filterPeriod) ?>">

                <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
                    <span style="font-weight:600; margin-left:8px;">الحصة <?= $filterPeriod ?> - <?= formatDateAr($filterDate, 'medium') ?></span>
                    <button type="button" class="btn btn-sm btn-success" onclick="markAllPeriod('present')">✅ الكل حاضر</button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="markAllPeriod('absent')">❌ الكل غائب</button>
                    <?php if ($filterPeriod > 1): ?>
                    <button type="button" class="btn btn-sm btn-light" onclick="copyFromPrevPeriod(<?= $filterPeriod ?>)">📋 نسخ من الحصة <?= $filterPeriod - 1 ?></button>
                    <?php endif; ?>
                    <button type="submit" name="save_period_attendance" class="btn btn-primary" style="margin-right:auto;">💾 حفظ</button>
                </div>

                <div class="table-wrapper">
                    <table class="data-table" id="period-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>الطالب</th>
                                <th style="width:80px; text-align:center;">حاضر</th>
                                <th style="width:80px; text-align:center;">غائب</th>
                                <th style="width:80px; text-align:center;">متأخر</th>
                                <th style="width:80px; text-align:center;">معذور</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $student):
                                $curStatus = $existingPeriodAttendance[$student['id']]['status'] ?? 'present';
                                $curNotes  = $existingPeriodAttendance[$student['id']]['notes'] ?? '';
                            ?>
                            <tr data-student-id="<?= $student['id'] ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <a href="../students/view.php?id=<?= $student['id'] ?>" style="font-weight:600; color:inherit; text-decoration:none;"><?= e($student['full_name_ar']) ?></a>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= e($student['student_number']) ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="present"
                                           <?= $curStatus === 'present' ? 'checked' : '' ?> class="period-radio">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="absent"
                                           <?= $curStatus === 'absent' ? 'checked' : '' ?> class="period-radio">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="late"
                                           <?= $curStatus === 'late' ? 'checked' : '' ?> class="period-radio">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="excused"
                                           <?= $curStatus === 'excused' ? 'checked' : '' ?> class="period-radio">
                                </td>
                                <td>
                                    <input type="text" name="students[<?= $student['id'] ?>][notes]"
                                           class="form-control form-control-sm" placeholder="ملاحظة..."
                                           value="<?= e($curNotes) ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- عداد الحالات -->
                <div style="margin-top:16px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-md); display:flex; gap:20px; flex-wrap:wrap;">
                    <span>✅ حاضر: <strong id="period-count-present"><?= count(array_filter($students, function($s) use ($existingPeriodAttendance) { return ($existingPeriodAttendance[$s['id']]['status'] ?? 'present') === 'present'; })) ?></strong></span>
                    <span>❌ غائب: <strong id="period-count-absent" style="color:var(--danger);"><?= count(array_filter($students, function($s) use ($existingPeriodAttendance) { return ($existingPeriodAttendance[$s['id']]['status'] ?? '') === 'absent'; })) ?></strong></span>
                    <span>⏰ متأخر: <strong id="period-count-late" style="color:var(--warning);"><?= count(array_filter($students, function($s) use ($existingPeriodAttendance) { return ($existingPeriodAttendance[$s['id']]['status'] ?? '') === 'late'; })) ?></strong></span>
                    <span>📝 معذور: <strong id="period-count-excused" style="color:var(--info);"><?= count(array_filter($students, function($s) use ($existingPeriodAttendance) { return ($existingPeriodAttendance[$s['id']]['status'] ?? '') === 'excused'; })) ?></strong></span>
                    <span style="margin-right:auto;">الإجمالي: <strong><?= count($students) ?></strong></span>
                </div>

                <div style="margin-top:16px; text-align:center;">
                    <button type="submit" name="save_period_attendance" class="btn btn-primary btn-lg">
                        💾 حفظ حضور الحصة <?= $filterPeriod ?>
                    </button>
                </div>
            </form>

            <?php elseif ($filterClass && empty($students)): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📭</div>
                لا يوجد طلاب مسجلون في هذا الفصل
            </div>
            <?php elseif (!$filterClass): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">🕐</div>
                اختر الفصل والتاريخ والحصة لتسجيل الحضور
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
input.period-radio { width:24px; height:24px; cursor:pointer; }
input.period-radio:focus { outline:2px solid #c8a951; outline-offset:2px; }
.btn-loading { opacity:0.7; pointer-events:none; }
</style>

<?php
$pageJs = <<<'JS'
window.markAllPeriod = function(status) {
    document.querySelectorAll(`input.period-radio[value="${status}"]`).forEach(r => { r.checked = true; r.dispatchEvent(new Event('change')); });
    updatePeriodCounters();
};

// عداد الحالات
function updatePeriodCounters() {
    var statuses = ['present', 'absent', 'late', 'excused'];
    statuses.forEach(function(s) {
        var c = document.querySelectorAll('input.period-radio[value="' + s + '"]:checked').length;
        var el = document.getElementById('period-count-' + s);
        if (el) el.textContent = c;
    });
}

// تلوين الصف عند تغيير الحالة
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('period-radio')) {
        updatePeriodCounters();
        var row = e.target.closest('tr');
        var status = e.target.value;
        row.style.background = '';
        if (status === 'absent') row.style.background = 'rgba(220,53,69,0.08)';
        else if (status === 'late') row.style.background = 'rgba(255,193,7,0.08)';
        else if (status === 'excused') row.style.background = 'rgba(13,202,240,0.08)';
    }
});

// نسخ من الحصة السابقة — إعادة تحميل مع copy_from
window.copyFromPrevPeriod = function(currentPeriod) {
    var prev = currentPeriod - 1;
    if (!confirm('سيتم نسخ حالات الحضور من الحصة ' + prev + '. هل تريد المتابعة؟')) return;
    var url = new URL(window.location.href);
    url.searchParams.set('copy_from', prev);
    window.location.href = url.toString();
};

// اختصارات لوحة المفاتيح (1-4)
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
    var keyMap = { '1': 'present', '2': 'absent', '3': 'late', '4': 'excused' };
    var status = keyMap[e.key];
    if (status) { e.preventDefault(); markAllPeriod(status); return; }
    if (e.key === 'Enter' && e.target.name && e.target.name.indexOf('[notes]') !== -1) {
        var nextRow = e.target.closest('tr')?.nextElementSibling;
        if (nextRow) { e.preventDefault(); var r = nextRow.querySelector('input[type="radio"]'); if (r) r.focus(); }
    }
});

// حالة التحميل لزر الحفظ
document.getElementById('period-attendance-form')?.addEventListener('submit', function() {
    var btns = this.querySelectorAll('button[type="submit"]');
    btns.forEach(function(b) { b.disabled = true; b.classList.add('btn-loading'); b.innerHTML = '⏳ جاري الحفظ...'; });
});
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
