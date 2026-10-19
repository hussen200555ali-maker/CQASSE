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

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية لتعديل سجلات الحضور.', 'danger');
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
                // جلب السجل الحالي مع التحقق من ملكية المدرسة
                $stmt = $db->prepare("SELECT ad.*, c.school_id, s.full_name_ar AS student_name FROM attendance_daily ad JOIN classes c ON ad.class_id = c.id JOIN students s ON s.id = ad.student_id WHERE ad.id = ? AND ad.is_active = 1");
                $stmt->execute([$attendanceId]);
                $currentRecord = $stmt->fetch();

                if (!$currentRecord) {
                    setFlash('سجل الحضور غير موجود', 'danger');
                } elseif ($schoolId && $userRole !== 'group_admin' && $currentRecord['school_id'] != $schoolId) {
                    setFlash('ليس لديك صلاحية لتعديل هذا السجل', 'danger');
                } elseif ($userRole === 'teacher' && !in_array($currentRecord['class_id'], getTeacherAssignedClassIds($db, $userId, $schoolId))) {
                    setFlash('ليس لديك صلاحية لتعديل هذا السجل', 'danger');
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

                        // قفل الصف وإضافة التعديل إلى amendment_log (JSON array)
                        $stmt = $db->prepare("SELECT amendment_log FROM attendance_daily WHERE id = ? FOR UPDATE");
                        $stmt->execute([$attendanceId]);
                        $logRow = $stmt->fetch();
                        if (!$logRow) throw new Exception('سجل الحضور غير موجود');

                        $entry = json_encode([
                            'old_status' => $currentRecord['status'],
                            'new_status' => $newStatus,
                            'reason' => $reason,
                            'amended_by' => $userId,
                            'created_at' => date('Y-m-d H:i:s')
                        ], JSON_UNESCAPED_UNICODE);

                        $existingLog = $logRow['amendment_log'];
                        if (empty($existingLog)) {
                            $newLog = '[' . $entry . ']';
                        } else {
                            $newLog = substr($existingLog, 0, -1) . ',' . $entry . ']';
                        }

                        // تحديث سجل الحضور مع التعديلات
                        $stmt = $db->prepare("UPDATE attendance_daily SET amendment_log = ?, status = ? WHERE id = ?");
                        $stmt->execute([$newLog, $newStatus, $attendanceId]);

                        // تحديث ملخص الحضور
                        updateAmendedSummary($db, $currentRecord['student_id'], $currentRecord['class_id'],
                            $currentRecord['academic_year_id'], $currentRecord['semester_id']);

                        $db->commit();

                        // إشعار مدير المدرسة بتعديل الحضور
                        try {
                            $teacherName = $_SESSION['user']['full_name'] ?? 'المعلم';
                            $studentName = $currentRecord['student_name'] ?? 'غير معروف';
                            $oldStatusAr = attendanceStatusAr($currentRecord['status']);
                            $newStatusAr = attendanceStatusAr($newStatus);
                            $notifMessage = "قام المعلم {$teacherName} بتعديل حضور الطالب {$studentName} من ({$oldStatusAr}) إلى ({$newStatusAr}). السبب: {$reason}";

                            $stmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'school_admin')");
                            $stmt->execute([$currentRecord['school_id']]);
                            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read, created_at) VALUES (?, 'info', ?, ?, 'attendance_daily', ?, 0, NOW())");
                            while ($row = $stmt->fetch()) {
                                $notifStmt->execute([$row['id'], 'تعديل سجل حضور', $notifMessage, $currentRecord['id'] ?? 0]);
                            }
                        } catch (Exception $e) {
                            error_log('amend.php - فشل إشعار التعديل: ' . $e->getMessage());
                        }

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
        if ($userRole === 'teacher') {
            $teacherClassIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!empty($teacherClassIds)) {
                $ph = implode(',', array_fill(0, count($teacherClassIds), '?'));
                $sql .= " AND ad.class_id IN ($ph)";
                $params = array_merge($params, $teacherClassIds);
            }
        }
        $sql .= " ORDER BY ad.date DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attendanceRecords = $stmt->fetchAll();
    } catch (Exception $e) {
        $attendanceRecords = [];
    }
}

// جلب تاريخ التعديلات من amendment_log
$amendmentHistory = [];
if (!empty($attendanceRecords)) {
    try {
        foreach ($attendanceRecords as $record) {
            if (!empty($record['amendment_log'])) {
                $entries = json_decode($record['amendment_log'], true);
                if (is_array($entries)) {
                    foreach ($entries as &$entry) {
                        $entry['attendance_id'] = $record['id'];
                    }
                    unset($entry);
                    $amendmentHistory = array_merge($amendmentHistory, $entries);
                }
            }
        }
        if (!empty($amendmentHistory)) {
            usort($amendmentHistory, function($a, $b) {
                return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
            });
            $amenderIds = array_unique(array_filter(array_column($amendmentHistory, 'amended_by')));
            if (!empty($amenderIds)) {
                $ph = implode(',', array_fill(0, count($amenderIds), '?'));
                $stmt = $db->prepare("SELECT id, full_name_ar FROM users WHERE id IN ($ph)");
                $stmt->execute(array_values($amenderIds));
                $userNames = [];
                while ($u = $stmt->fetch()) {
                    $userNames[$u['id']] = $u['full_name_ar'];
                }
                foreach ($amendmentHistory as &$entry) {
                    $entry['amender_name'] = $userNames[$entry['amended_by']] ?? 'غير معروف';
                }
                unset($entry);
            }
        }
    } catch (Exception $e) {
        error_log('amend.php - فشل قراءة سجل التعديلات: ' . $e->getMessage());
    }
}

// جلب الفصول والطلاب للفلتر
$classes = [];
$studentsList = [];
try {
    $sql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        if ($userRole === 'teacher') {
            $teacherClassIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!empty($teacherClassIds)) {
                $placeholders = implode(',', array_fill(0, count($teacherClassIds), '?'));
                $sql .= " AND id IN ($placeholders)";
                $params = $teacherClassIds;
            } else {
                $sql .= " AND 1=0";
            }
        } else {
            $sql .= " AND school_id = ?";
            $params[] = $schoolId;
        }
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
    if ($userRole === 'teacher') {
        $sql .= " AND id IN (
            SELECT DISTINCT se.student_id FROM student_enrollments se
            JOIN subject_teachers st ON st.classroom_id = se.class_id AND st.teacher_id = ? AND st.is_active = 1
            UNION
            SELECT DISTINCT se.student_id FROM student_enrollments se
            JOIN classes c ON c.id = se.class_id AND c.teacher_id = ? AND c.academic_year_id = ?
        )";
        $params = array_merge($params, [$userId, $userId, $academicYearId]);
    }
    $sql .= " ORDER BY full_name_ar LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

// الدوال المساعدة موجودة في includes/helpers.php
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
                                    <a href="../students/view.php?id=<?= $record['student_id'] ?>" style="font-weight:600; color:inherit; text-decoration:none;"><?= e($record['student_name']) ?></a>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= e($record['student_number']) ?></div>
                                </td>
                                <td><?= e($record['class_name']) ?></td>
                                <td><?= formatDateAr($record['date'], 'short') ?></td>
                                <td>
                                    <span class="badge badge-<?= attendanceStatusColor($record['status']) ?>">
                                        <?= attendanceStatusAr($record['status']) ?>
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
                            <td><span class="badge badge-<?= attendanceStatusColor($amend['old_status']) ?>"><?= attendanceStatusAr($amend['old_status']) ?></span></td>
                            <td><span class="badge badge-<?= attendanceStatusColor($amend['new_status']) ?>"><?= attendanceStatusAr($amend['new_status']) ?></span></td>
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
