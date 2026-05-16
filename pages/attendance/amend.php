<?php
/**
 * ============================================================
 * تعديل سجلات الحضور
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * تعديل سجلات الحضور مع تسجيل السبب والمراجعة
 */

$pageTitle = 'تعديل سجلات الحضور';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'تعديل السجلات' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المدرسة ومدير النظام
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية لتعديل سجلات الحضور. فقط مدير المدرسة أو مدير النظام يمكنهم التعديل.', 'danger');
    redirect('index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);

// ============================================================
// معالجة تعديل الحضور
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amend_attendance'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $attendanceId = (int)($_POST['attendance_id'] ?? 0);
        $newStatus    = $_POST['new_status'] ?? '';
        $reason       = trim($_POST['reason'] ?? '');

        if (empty($attendanceId) || empty($newStatus) || empty($reason)) {
            setFlash('يرجى ملء جميع الحقول المطلوبة (الحالة الجديدة وسبب التعديل)', 'warning');
        } elseif (!in_array($newStatus, ['present', 'absent', 'late', 'excused'])) {
            setFlash('حالة الحضور غير صالحة', 'danger');
        } else {
            try {
                // جلب السجل الحالي
                $stmt = $db->prepare("SELECT * FROM attendance_daily WHERE id = ? AND is_active = 1");
                $stmt->execute([$attendanceId]);
                $currentRecord = $stmt->fetch();

                if (!$currentRecord) {
                    setFlash('سجل الحضور غير موجود', 'danger');
                } else {
                    // التحقق من أن الفصل الدراسي ليس مقفلاً
                    $stmt = $db->prepare("SELECT lock_level FROM semesters WHERE id = ? AND is_active = 1");
                    $stmt->execute([$currentRecord['semester_id']]);
                    $lockInfo = $stmt->fetch();
                    if ($lockInfo && in_array($lockInfo['lock_level'], ['hard', 'admin_handled'])) {
                        setFlash('لا يمكن تعديل الحضور - الفصل الدراسي مقفل', 'danger');
                    } elseif ($currentRecord['status'] === $newStatus) {
                        setFlash('الحالة الجديدة مطابقة للحالة الحالية - لا حاجة للتعديل', 'warning');
                    } else {
                        $db->beginTransaction();

                        // تسجيل التعديل في جدول التعديلات
                        $stmt = $db->prepare("
                            INSERT INTO attendance_amendments 
                                (attendance_id, old_status, new_status, reason, amended_by, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $attendanceId,
                            $currentRecord['status'],
                            $newStatus,
                            $reason,
                            $userId
                        ]);

                        // تحديث سجل الحضور
                        $stmt = $db->prepare("UPDATE attendance_daily SET status = ? WHERE id = ?");
                        $stmt->execute([$newStatus, $attendanceId]);

                        // تحديث ملخص الحضور
                        updateAmendedSummary($db, $currentRecord['student_id'], $currentRecord['class_id'],
                            $currentRecord['academic_year_id'], $currentRecord['semester_id']);

                        $db->commit();

                        // تسجيل النشاط
                        logActivity($db, $userId, 'attendance_amend',
                            "تعديل حضور الطالب رقم {$currentRecord['student_id']} من {$currentRecord['status']} إلى {$newStatus}",
                            'attendance_daily', $attendanceId);

                        setFlash('تم تعديل سجل الحضور بنجاح', 'success');
                    }
                }
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('حدث خطأ أثناء التعديل: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

/**
 * تحديث ملخص الحضور بعد التعديل
 */
function updateAmendedSummary($db, $studentId, $classId, $academicYearId, $semesterId) {
    try {
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
        $c = $stmt->fetch();

        if (!$c || $c['total_days'] == 0) return;

        $rate = round((($c['present_days'] + $c['excused_days']) / $c['total_days']) * 100, 2);
        $minRate = 75;

        // ملاحظة: v_attendance_summary هو VIEW يقرأ من attendance_daily
        // لا حاجة لإدخال بيانات ملخص - العرض يتم عبر الـ View تلقائياً
    } catch (Exception $e) {
        error_log('EQAS updateAmendedSummary Error: ' . $e->getMessage());
    }
}

// ============================================================
// جلب بيانات البحث
// ============================================================
$searchStudent = $_GET['student_id'] ?? '';
$searchDate    = $_GET['date'] ?? '';
$searchClass   = $_GET['class_id'] ?? '';

$attendanceRecords = [];
$amendmentHistory  = [];

// البحث عن سجلات حضور
if ($searchStudent || ($searchDate && $searchClass)) {
    try {
        $sql = "SELECT ad.*, s.full_name_ar AS student_name, s.student_number,
                       c.name_ar AS class_name
                FROM attendance_daily ad
                INNER JOIN students s ON s.id = ad.student_id
                INNER JOIN classes c ON c.id = ad.class_id
                WHERE ad.is_active = 1";
        $params = [];

        if ($searchStudent) {
            $sql .= " AND ad.student_id = ?";
            $params[] = $searchStudent;
        }
        if ($searchDate) {
            $sql .= " AND ad.date = ?";
            $params[] = $searchDate;
        }
        if ($searchClass) {
            $sql .= " AND ad.class_id = ?";
            $params[] = $searchClass;
        }
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY ad.date DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attendanceRecords = $stmt->fetchAll();
    } catch (Exception $e) {
        $attendanceRecords = [];
    }
}

// جلب تاريخ التعديلات
if (!empty($attendanceRecords)) {
    try {
        $recordIds = array_column($attendanceRecords, 'id');
        if (!empty($recordIds)) {
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
            $stmt = $db->prepare("
                SELECT aa.*, u.full_name_ar AS amender_name
                FROM attendance_amendments aa
                LEFT JOIN users u ON u.id = aa.amended_by
                WHERE aa.attendance_id IN ($placeholders)
                ORDER BY aa.created_at DESC
            ");
            $stmt->execute($recordIds);
            $amendmentHistory = $stmt->fetchAll();
        }
    } catch (Exception $e) {}
}

// جلب الفصول والطلاب للفلتر
$classes = [];
$studentsList = [];
try {
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

    // جلب الطلاب للبحث
    $sql = "SELECT id, student_number, full_name_ar FROM students WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND school_id = ?";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY full_name_ar LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

function getStatusAr($s) {
    return ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'معذور'][$s] ?? $s;
}
function getStatusColor($s) {
    return ['present' => 'success', 'absent' => 'danger', 'late' => 'warning', 'excused' => 'info'][$s] ?? 'secondary';
}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">✏️ تعديل سجلات الحضور</h3>
        </div>
        <div class="card-body">
            <div style="background:#fff3cd; padding:10px 14px; border-radius:var(--radius-md); margin-bottom:16px; font-size:13px; color:#856404;">
                ⚠️ تنبيه: جميع التعديلات يتم تسجيلها مع سبب التعديل وهوية المعدّل. لا يمكن تعديل سجلات الفصول الدراسية المقفلة.
            </div>

            <!-- نموذج البحث -->
            <form method="GET" class="form-inline" style="margin-bottom:20px; gap:12px; flex-wrap:wrap;">
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الطالب:</label>
                    <select name="student_id" class="form-control" style="min-width:200px;">
                        <option value="">-- اختر الطالب --</option>
                        <?php foreach ($studentsList as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= $searchStudent == $st['id'] ? 'selected' : '' ?>>
                            <?= e($st['full_name_ar']) ?> (<?= e($st['student_number']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">الفصل:</label>
                    <select name="class_id" class="form-control" style="min-width:160px;">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $searchClass == $cls['id'] ? 'selected' : '' ?>>
                            <?= e($cls['name_ar']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-left:8px;">التاريخ:</label>
                    <input type="date" name="date" class="form-control" value="<?= e($searchDate) ?>">
                </div>
                <button type="submit" class="btn btn-primary">🔍 بحث</button>
            </form>

            <?php if (!empty($attendanceRecords)): ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الطالب</th>
                            <th>الفصل</th>
                            <th>التاريخ</th>
                            <th>الحالة الحالية</th>
                            <th>تعديل إلى</th>
                            <th>سبب التعديل</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceRecords as $i => $record): ?>
                        <tr>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="attendance_id" value="<?= $record['id'] ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div style="font-weight:600;"><?= e($record['student_name']) ?></div>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= e($record['student_number']) ?></div>
                                </td>
                                <td><?= e($record['class_name']) ?></td>
                                <td><?= formatDateAr($record['date'], 'short') ?></td>
                                <td>
                                    <span class="badge badge-<?= getStatusColor($record['status']) ?>">
                                        <?= getStatusAr($record['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <select name="new_status" class="form-control form-control-sm" required>
                                        <?php foreach (['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'معذور'] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $record['status'] === $val ? 'disabled' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="reason" class="form-control form-control-sm"
                                           placeholder="سبب التعديل..." required style="min-width:150px;">
                                </td>
                                <td>
                                    <button type="submit" name="amend_attendance" class="btn btn-sm btn-warning"
                                            onclick="return confirm('هل أنت متأكد من تعديل هذا السجل؟')">
                                        ✏️ تعديل
                                    </button>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif (isset($_GET['student_id']) || isset($_GET['date'])): ?>
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                <div style="font-size:32px; margin-bottom:8px;">🔍</div>
                لا توجد سجلات حضور مطابقة للبحث
            </div>
            <?php else: ?>
            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                <div style="font-size:32px; margin-bottom:8px;">✏️</div>
                ابحث عن سجلات الحضور لتعديلها
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- تاريخ التعديلات -->
<?php if (!empty($amendmentHistory)): ?>
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 سجل التعديلات</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>السبب</th>
                            <th>بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($amendmentHistory as $amend): ?>
                        <tr>
                            <td><?= formatDateAr($amend['created_at'], 'medium') ?></td>
                            <td><span class="badge badge-<?= getStatusColor($amend['old_status']) ?>"><?= getStatusAr($amend['old_status']) ?></span></td>
                            <td><span class="badge badge-<?= getStatusColor($amend['new_status']) ?>"><?= getStatusAr($amend['new_status']) ?></span></td>
                            <td><?= e($amend['reason']) ?></td>
                            <td><?= e($amend['amender_name'] ?? 'غير معروف') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
