<?php
/**
 * ============================================================
 * تسجيل الحضور اليومي
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * الصفحة الرئيسية لتسجيل حضور وغياب الطلاب يومياً
 * تدعم الوضع الأساسي (يومي) والثانوي (بالحصص)
 */

$pageTitle = 'تسجيل الحضور اليومي';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'تسجيل الحضور اليومي' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);

// جلب الفصل الدراسي الحالي
$semesterId = null;
try {
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $sem = $stmt->fetch();
    $semesterId = $sem ? $sem['id'] : null;
} catch (Exception $e) {
    $semesterId = null;
}

// ============================================================
// معالجة حفظ الحضور
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
    } else {
        $classId = (int)($_POST['class_id'] ?? 0);
        $date    = $_POST['attendance_date'] ?? date('Y-m-d');
        $students = $_POST['students'] ?? [];

        // التحقق من أن الفصل الدراسي ليس مقفلاً
        $canEdit = true;
        if ($semesterId) {
            try {
                $stmt = $db->prepare("SELECT lock_level FROM semesters WHERE id = ? AND is_active = 1");
                $stmt->execute([$semesterId]);
                $lockInfo = $stmt->fetch();
                if ($lockInfo && in_array($lockInfo['lock_level'], ['hard', 'admin_handled'])) {
                    $canEdit = false;
                }
            } catch (Exception $e) {}
        }

        if (!$canEdit) {
            setFlash('لا يمكن تسجيل الحضور - الفصل الدراسي مقفل', 'danger');
        } elseif (empty($classId) || empty($students)) {
            setFlash('يرجى اختيار الفصل وتسجيل الحضور', 'warning');
        } else {
            try {
                $db->beginTransaction();

                $savedCount = 0;
                $absentStudents = []; // لتتبع الغياب المتتالي

                foreach ($students as $studentId => $data) {
                    $status = $data['status'] ?? 'present';
                    $notes  = trim($data['notes'] ?? '');

                    // التحقق من صحة الحالة
                    if (!in_array($status, ['present', 'absent', 'late', 'excused'])) {
                        continue;
                    }

                    // إدراج أو تحديث سجل الحضور (UPSERT)
                    $stmt = $db->prepare("
                        INSERT INTO attendance_daily 
                            (student_id, class_id, academic_year_id, semester_id, date, status, notes, recorded_by, is_active, created_at)
                        VALUES 
                            (:student_id, :class_id, :year_id, :semester_id, :date, :status, :notes, :recorded_by, 1, NOW())
                        ON DUPLICATE KEY UPDATE 
                            status = VALUES(status),
                            notes = VALUES(notes),
                            recorded_by = VALUES(recorded_by)
                    ");
                    $stmt->execute([
                        ':student_id'   => $studentId,
                        ':class_id'     => $classId,
                        ':year_id'      => $academicYearId,
                        ':semester_id'  => $semesterId,
                        ':date'         => $date,
                        ':status'       => $status,
                        ':notes'        => $notes,
                        ':recorded_by'  => $userId
                    ]);
                    $savedCount++;

                    // تتبع الطلاب الغائبين لفحص الغياب المتتالي
                    if ($status === 'absent') {
                        $absentStudents[] = $studentId;
                    }
                }

                // تحديث ملخص الحضور لجميع الطلاب
                foreach ($students as $studentId => $data) {
                    updateAttendanceSummary($db, $studentId, $classId, $academicYearId, $semesterId);
                }

                // فحص الغياب المتتالي وإرسال إشعارات
                $consecutiveAlert = 3; // القيمة الافتراضية
                if ($schoolId) {
                    try {
                        $stmt = $db->prepare("SELECT consecutive_absence_alert FROM attendance_settings WHERE school_id = ? AND is_active = 1");
                        $stmt->execute([$schoolId]);
                        $settings = $stmt->fetch();
                        if ($settings) {
                            $consecutiveAlert = (int)$settings['consecutive_absence_alert'];
                        }
                    } catch (Exception $e) {}
                }

                foreach ($absentStudents as $absentStudentId) {
                    checkConsecutiveAbsence($db, $absentStudentId, $classId, $date, $consecutiveAlert);
                }

                $db->commit();
                setFlash("تم حفظ الحضور بنجاح لـ {$savedCount} طالب", 'success');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('حدث خطأ أثناء حفظ الحضور: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================
$filterClass = $_GET['class_id'] ?? $_POST['class_id'] ?? '';
$filterDate  = $_GET['date'] ?? $_POST['attendance_date'] ?? date('Y-m-d');

// جلب الفصول
$classes = [];
try {
    $sql = "SELECT id, name_ar, grade_level_id FROM classes WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY name_ar";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classes = $stmt->fetchAll();
} catch (Exception $e) {
    $classes = [];
}

// جلب طلاب الفصل المحدد
$students = [];
$existingAttendance = [];

if ($filterClass) {
    try {
        // جلب الطلاب المسجلين في الفصل
        $stmt = $db->prepare("
            SELECT s.id, s.student_number, s.full_name_ar, s.guardian_name, s.guardian_phone
            FROM students s
            INNER JOIN student_enrollments se ON se.student_id = s.id AND se.class_id = ? AND se.is_active = 1 AND se.status = 'enrolled'
            WHERE s.is_active = 1
            ORDER BY s.full_name_ar
        ");
        $stmt->execute([$filterClass]);
        $students = $stmt->fetchAll();

        // جلب سجلات الحضور الموجودة لليوم المحدد
        $stmt = $db->prepare("
            SELECT student_id, status, notes
            FROM attendance_daily
            WHERE class_id = ? AND date = ? AND is_active = 1
        ");
        $stmt->execute([$filterClass, $filterDate]);
        $existingAttendance = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // تحويل مصفوفة الحضور إلى شكل student_id => status
        $tempAttendance = [];
        $stmt = $db->prepare("
            SELECT student_id, status, notes
            FROM attendance_daily
            WHERE class_id = ? AND date = ? AND is_active = 1
        ");
        $stmt->execute([$filterClass, $filterDate]);
        while ($row = $stmt->fetch()) {
            $tempAttendance[$row['student_id']] = ['status' => $row['status'], 'notes' => $row['notes']];
        }
        $existingAttendance = $tempAttendance;
    } catch (Exception $e) {
        $students = [];
        $existingAttendance = [];
    }
}

// جلب إعدادات الحضور
$lateAsAbsent = false;
$consecutiveAlert = 3;
if ($schoolId) {
    try {
        $stmt = $db->prepare("SELECT * FROM attendance_settings WHERE school_id = ? AND is_active = 1");
        $stmt->execute([$schoolId]);
        $settings = $stmt->fetch();
        if ($settings) {
            $lateAsAbsent = (bool)$settings['late_count_as_absent'];
            $consecutiveAlert = (int)$settings['consecutive_absence_alert'];
        }
    } catch (Exception $e) {}
}

// فحص نسبة الحضور للتنبيه
$attendanceRateWarning = false;
if ($filterClass && $academicYearId && $semesterId) {
    try {
        $stmt = $db->prepare("
            SELECT AVG(attendance_rate) as avg_rate
            FROM attendance_summaries
            WHERE class_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1
        ");
        $stmt->execute([$filterClass, $academicYearId, $semesterId]);
        $avgRate = $stmt->fetchColumn();
        if ($avgRate !== false && (float)$avgRate < 75) {
            $attendanceRateWarning = true;
        }
    } catch (Exception $e) {}
}

// ============================================================
// دوال مساعدة
// ============================================================

/**
 * تحديث ملخص الحضور لطالب معين
 */
function updateAttendanceSummary($db, $studentId, $classId, $academicYearId, $semesterId) {
    try {
        // حساب الإحصائيات من سجلات الحضور اليومي
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_days
            FROM attendance_daily
            WHERE student_id = ? AND class_id = ? AND academic_year_id = ? 
                AND semester_id = ? AND is_active = 1
        ");
        $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
        $counts = $stmt->fetch();

        if (!$counts || $counts['total_days'] == 0) return;

        $totalDays   = (int)$counts['total_days'];
        $presentDays = (int)$counts['present_days'];
        $absentDays  = (int)$counts['absent_days'];
        $lateDays    = (int)$counts['late_days'];
        $excusedDays = (int)$counts['excused_days'];

        // حساب نسبة الحضور (الحضور + المعذور / الإجمالي)
        $attendanceRate = round((($presentDays + $excusedDays) / $totalDays) * 100, 2);

        // الحد الأدنى من الإعدادات
        $minRate = 75;
        try {
            $stmt = $db->prepare("
                SELECT s.id FROM classes c 
                INNER JOIN attendance_settings ats ON ats.school_id = c.school_id AND ats.is_active = 1
                WHERE c.id = ?
            ");
            $stmt->execute([$classId]);
            if ($stmt->fetch()) {
                $stmt2 = $db->prepare("
                    SELECT ats.min_attendance_rate FROM attendance_settings ats
                    INNER JOIN classes c ON c.school_id = ats.school_id
                    WHERE c.id = ? AND ats.is_active = 1 LIMIT 1
                ");
                $stmt2->execute([$classId]);
                $minRateRow = $stmt2->fetchColumn();
                if ($minRateRow) $minRate = (float)$minRateRow;
            }
        } catch (Exception $e) {}

        $isBelowThreshold = $attendanceRate < $minRate ? 1 : 0;

        // إدراج أو تحديث ملخص الحضور
        $stmt = $db->prepare("
            INSERT INTO attendance_summaries 
                (student_id, class_id, academic_year_id, semester_id, total_days, 
                 present_days, absent_days, late_days, excused_days, 
                 attendance_rate, is_below_threshold, is_active)
            VALUES 
                (:student_id, :class_id, :year_id, :semester_id, :total_days,
                 :present_days, :absent_days, :late_days, :excused_days,
                 :rate, :below_threshold, 1)
            ON DUPLICATE KEY UPDATE
                total_days = VALUES(total_days),
                present_days = VALUES(present_days),
                absent_days = VALUES(absent_days),
                late_days = VALUES(late_days),
                excused_days = VALUES(excused_days),
                attendance_rate = VALUES(attendance_rate),
                is_below_threshold = VALUES(is_below_threshold)
        ");
        $stmt->execute([
            ':student_id'      => $studentId,
            ':class_id'        => $classId,
            ':year_id'         => $academicYearId,
            ':semester_id'     => $semesterId,
            ':total_days'      => $totalDays,
            ':present_days'    => $presentDays,
            ':absent_days'     => $absentDays,
            ':late_days'       => $lateDays,
            ':excused_days'    => $excusedDays,
            ':rate'            => $attendanceRate,
            ':below_threshold' => $isBelowThreshold
        ]);
    } catch (Exception $e) {
        error_log('EQAS updateAttendanceSummary Error: ' . $e->getMessage());
    }
}

/**
 * فحص الغياب المتتالي وإرسال إشعار
 */
function checkConsecutiveAbsence($db, $studentId, $classId, $currentDate, $threshold) {
    try {
        // عد أيام الغياب المتتالية قبل التاريخ الحالي
        $stmt = $db->prepare("
            SELECT date, status
            FROM attendance_daily
            WHERE student_id = ? AND class_id = ? AND date <= ? AND is_active = 1
            ORDER BY date DESC
            LIMIT ?
        ");
        $stmt->execute([$studentId, $classId, $currentDate, $threshold + 5]);
        $records = $stmt->fetchAll();

        $consecutiveCount = 0;
        foreach ($records as $record) {
            if ($record['status'] === 'absent') {
                $consecutiveCount++;
            } else {
                break; // انقطع الغياب المتتالي
            }
        }

        // إذا وصل الغياب المتتالي للحد، إنشاء إشعار
        if ($consecutiveCount >= $threshold) {
            // جلب ولي الأمر
            $stmt = $db->prepare("
                SELECT ps.parent_id FROM parent_students ps
                WHERE ps.student_id = ? AND ps.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$studentId]);
            $parent = $stmt->fetch();

            if ($parent) {
                // التحقق من عدم وجود إشعار مكرر خلال 3 أيام
                $stmt = $db->prepare("
                    SELECT id FROM absence_notifications
                    WHERE student_id = ? AND notification_type = 'consecutive'
                        AND sent_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) AND is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$studentId]);
                if (!$stmt->fetch()) {
                    $stmt = $db->prepare("
                        INSERT INTO absence_notifications 
                            (student_id, parent_id, absence_count, notification_type, sent_at, is_read, is_active)
                        VALUES (?, ?, ?, 'consecutive', NOW(), 0, 1)
                    ");
                    $stmt->execute([$studentId, $parent['parent_id'], $consecutiveCount]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('EQAS checkConsecutiveAbsence Error: ' . $e->getMessage());
    }
}

// ترجمة حالة الحضور
function getStatusAr($status) {
    $labels = ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'معذور'];
    return $labels[$status] ?? $status;
}
?>

<!-- ============================================================
     تنبيه نسبة الحضور المنخفضة
     ============================================================ -->
<?php if ($attendanceRateWarning): ?>
<div class="section">
    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:var(--radius-md); padding:12px 16px; display:flex; align-items:center; gap:8px;">
        <span style="font-size:20px;">⚠️</span>
        <span style="color:#856404;">تنبيه: متوسط نسبة حضور هذا الفصل أقل من 75%. يرجى متابعة الطلاب المتغيبين.</span>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     نموذج تسجيل الحضور
     ============================================================ -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <h3 class="card-title">📋 تسجيل الحضور اليومي</h3>
                <button type="button" class="btn btn-light" onclick="window.print()" title="طباعة كشف الحضور">🖨️ طباعة</button>
            </div>
        </div>
        <div class="card-body">
            <!-- اختيار الفصل والتاريخ -->
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
                <button type="submit" class="btn btn-primary">عرض الطلاب</button>
            </form>

            <?php if ($lateAsAbsent): ?>
            <div style="background:#fff3cd; padding:8px 12px; border-radius:var(--radius-md); margin-bottom:16px; font-size:13px;">
                ℹ️ ملاحظة: إعداد المدرسة يعتبر التأخير كغياب.
            </div>
            <?php endif; ?>

            <?php if ($filterClass && !empty($students)): ?>
            <!-- نموذج تسجيل الحضور -->
            <form method="POST" id="attendance-form">
                <?= csrfField() ?>
                <input type="hidden" name="class_id" value="<?= e($filterClass) ?>">
                <input type="hidden" name="attendance_date" value="<?= e($filterDate) ?>">

                <!-- أزرار سريعة -->
                <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm btn-success" onclick="markAllAs('present')">
                        ✅ تحديد الكل حاضر
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="markAllAs('absent')">
                        ❌ تحديد الكل غائب
                    </button>
                    <button type="submit" name="save_attendance" class="btn btn-primary" style="margin-right:auto;">
                        💾 حفظ الحضور
                    </button>
                </div>

                <!-- جدول الطلاب -->
                <div class="table-wrapper">
                    <table class="data-table" id="attendance-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th style="min-width:180px;">الطالب</th>
                                <th style="width:80px; text-align:center;">حاضر</th>
                                <th style="width:80px; text-align:center;">غائب</th>
                                <th style="width:80px; text-align:center;">متأخر</th>
                                <th style="width:80px; text-align:center;">معذور</th>
                                <th style="min-width:120px;">ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $student):
                                $currentStatus = $existingAttendance[$student['id']]['status'] ?? 'present';
                                $currentNotes  = $existingAttendance[$student['id']]['notes'] ?? '';
                            ?>
                            <tr data-student-id="<?= $student['id'] ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div style="font-weight:600;"><?= e($student['full_name_ar']) ?></div>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= e($student['student_number']) ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="present"
                                           <?= $currentStatus === 'present' ? 'checked' : '' ?>
                                           class="attendance-radio" data-student="<?= $student['id'] ?>">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="absent"
                                           <?= $currentStatus === 'absent' ? 'checked' : '' ?>
                                           class="attendance-radio" data-student="<?= $student['id'] ?>">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="late"
                                           <?= $currentStatus === 'late' ? 'checked' : '' ?>
                                           class="attendance-radio" data-student="<?= $student['id'] ?>">
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="students[<?= $student['id'] ?>][status]" value="excused"
                                           <?= $currentStatus === 'excused' ? 'checked' : '' ?>
                                           class="attendance-radio" data-student="<?= $student['id'] ?>">
                                </td>
                                <td>
                                    <input type="text" name="students[<?= $student['id'] ?>][notes]" 
                                           class="form-control form-control-sm" 
                                           placeholder="ملاحظة..."
                                           value="<?= e($currentNotes) ?>">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- عداد الحالات -->
                <div style="margin-top:16px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-md); display:flex; gap:20px; flex-wrap:wrap;">
                    <span>✅ حاضر: <strong id="count-present"><?= count(array_filter($students, function($s) use ($existingAttendance) { return ($existingAttendance[$s['id']]['status'] ?? 'present') === 'present'; })) ?></strong></span>
                    <span>❌ غائب: <strong id="count-absent" style="color:var(--danger);"><?= count(array_filter($students, function($s) use ($existingAttendance) { return ($existingAttendance[$s['id']]['status'] ?? '') === 'absent'; })) ?></strong></span>
                    <span>⏰ متأخر: <strong id="count-late" style="color:var(--warning);"><?= count(array_filter($students, function($s) use ($existingAttendance) { return ($existingAttendance[$s['id']]['status'] ?? '') === 'late'; })) ?></strong></span>
                    <span>📝 معذور: <strong id="count-excused" style="color:var(--info);"><?= count(array_filter($students, function($s) use ($existingAttendance) { return ($existingAttendance[$s['id']]['status'] ?? '') === 'excused'; })) ?></strong></span>
                    <span style="margin-right:auto;">الإجمالي: <strong><?= count($students) ?></strong></span>
                </div>

                <!-- زر الحفظ السفلي -->
                <div style="margin-top:16px; text-align:center;">
                    <button type="submit" name="save_attendance" class="btn btn-primary btn-lg">
                        💾 حفظ الحضور لـ <?= count($students) ?> طالب
                    </button>
                </div>
            </form>

            <?php elseif ($filterClass && empty($students)): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📭</div>
                <div style="font-size:16px; font-weight:600;">لا يوجد طلاب مسجلون في هذا الفصل</div>
                <p>يرجى التأكد من تسجيل الطلاب في الفصل أولاً</p>
            </div>
            <?php elseif (!$filterClass): ?>
            <div style="text-align:center; padding:40px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px;">📋</div>
                <div style="font-size:16px; font-weight:600;">اختر الفصل والتاريخ لتسجيل الحضور</div>
                <p>يتم عرض قائمة الطلاب بعد اختيار الفصل</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     جافاسكربت
     ============================================================ -->
<?php
$pageJs = <<<'JS'
// تحديد الكل بحالة معينة
function markAllAs(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(radio => radio.checked = true);
    updateCounters();
}

// تحديث عدادات الحالات
function updateCounters() {
    const statuses = ['present', 'absent', 'late', 'excused'];
    statuses.forEach(status => {
        const checked = document.querySelectorAll(`input[type="radio"][value="${status}"]:checked`);
        const el = document.getElementById(`count-${status}`);
        if (el) el.textContent = checked.length;
    });
}

// الاستماع لتغيير أزرار الراديو
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('attendance-radio')) {
        updateCounters();
        // تلوين الصف حسب الحالة
        const row = e.target.closest('tr');
        const status = e.target.value;
        row.style.background = '';
        if (status === 'absent') {
            row.style.background = 'rgba(220,53,69,0.08)';
        } else if (status === 'late') {
            row.style.background = 'rgba(255,193,7,0.08)';
        } else if (status === 'excused') {
            row.style.background = 'rgba(13,202,240,0.08)';
        }
    }
});

// تأكيد مغادرة الصفحة بدون حفظ
let formChanged = false;
document.getElementById('attendance-form')?.addEventListener('change', () => formChanged = true);
window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'لديك تغييرات غير محفوظة. هل تريد المغادرة؟';
    }
});
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
