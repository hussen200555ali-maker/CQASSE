<?php
/**
 * ============================================================
 * EQAS - إشعارات الغياب (موحّد عبر جدول notifications)
 * نظام التقييم الشامل لجودة التعليم المدرسي
 * ============================================================
 * تم إعادة كتابتها لاستخدام جدول notifications بدلاً من
 * absence_notifications (غير موجود في قاعدة البيانات).
 * لمزيد من التفاصيل راجع AGENTS.md.
 */

$pageTitle = 'إشعارات الغياب';
$breadcrumb = ['الحضور والانضباط' => 'index.php', 'إشعارات الغياب' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? currentAcademicYearId();

// شرط تقييد الطلاب للمعلم
$teacherStudentWhere = '';
$teacherStudentParams = [];
if ($userRole === 'teacher' && $schoolId) {
    $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
    if (!empty($allowedIds)) {
        $ph = implode(',', array_fill(0, count($allowedIds), '?'));
        $teacherStudentWhere = " AND s.id IN (SELECT se.student_id FROM student_enrollments se WHERE se.class_id IN ($ph) AND se.status = 'enrolled')";
        $teacherStudentParams = $allowedIds;
    }
}

// ============================================================
// معالجة تحديد الإشعار كمقروء
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        try {
            $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
            setFlash('تم تحديث حالة الإشعار', 'success');
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء التحديث', 'danger');
        }
    }
}

// معالجة تحديد الكل كمقروء
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        try {
            $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE type LIKE 'absence_%' AND user_id = ? AND is_read = 0";
            $db->prepare($sql)->execute([$userId]);
            setFlash('تم تحديد جميع الإشعارات كمقروءة', 'success');
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء التحديث', 'danger');
        }
    }
}

// ============================================================
// معالجة إرسال إشعار جديد
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $notifStudent = (int)($_POST['student_id'] ?? 0);
        $notifType    = $_POST['notification_type'] ?? '';
        $notifCount   = (int)($_POST['absence_count'] ?? 0);

        if ($notifStudent && in_array($notifType, ['consecutive', 'threshold', 'weekly'])) {
            try {
                $stmt = $db->prepare("SELECT id, school_id, full_name_ar FROM students WHERE id = ?");
                $stmt->execute([$notifStudent]);
                $student = $stmt->fetch();
                if (!$student) {
                    setFlash('الطالب غير موجود', 'warning');
                } elseif ($schoolId && $userRole !== 'group_admin' && $student['school_id'] != $schoolId) {
                    setFlash('ليس لديك صلاحية لإرسال إشعار لهذا الطالب', 'danger');
                } elseif ($userRole === 'teacher') {
                    $check = $db->prepare("SELECT 1 FROM student_enrollments se
                        JOIN subject_teachers st ON st.classroom_id = se.class_id AND st.teacher_id = ? AND st.is_active = 1
                        WHERE se.student_id = ? AND se.is_active = 1 AND se.status = 'enrolled'
                        UNION
                        SELECT 1 FROM student_enrollments se
                        JOIN classes c ON c.id = se.class_id AND c.teacher_id = ? AND c.academic_year_id = ?
                        WHERE se.student_id = ? AND se.is_active = 1 AND se.status = 'enrolled'");
                    $check->execute([$userId, $notifStudent, $userId, $academicYearId, $notifStudent]);
                    if (!$check->fetch()) {
                        setFlash('ليس لديك صلاحية لإرسال إشعار لهذا الطالب', 'danger');
                        redirect('notifications.php');
                    }
                }

                $stmt = $db->prepare("SELECT ps.parent_id FROM parent_students ps WHERE ps.student_id = ? AND ps.is_active = 1 LIMIT 1");
                $stmt->execute([$notifStudent]);
                $parent = $stmt->fetch();
                if ($parent) {
                    $typeLabels = [
                        'consecutive' => 'غياب متتالي',
                        'threshold' => 'نسبة دون الحد',
                        'weekly' => 'تقرير أسبوعي',
                    ];
                    $titleAr = $typeLabels[$notifType] ?? 'إشعار غياب';
                    $messageAr = sprintf('تم تسجيل %d يوم غياب للطالب/ة %s (%s).', $notifCount, $student['full_name_ar'], $titleAr);
                    $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read, created_at)
                                          VALUES (?, ?, ?, ?, 'student', ?, 0, NOW())");
                    $stmt->execute([$parent['parent_id'], 'absence_' . $notifType, $titleAr, $messageAr, $notifStudent]);
                    setFlash('تم إرسال الإشعار بنجاح', 'success');
                } else {
                    setFlash('لم يتم العثور على ولي أمر لهذا الطالب', 'warning');
                }
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء إرسال الإشعار', 'danger');
            }
        } else {
            setFlash('يرجى ملء جميع الحقول المطلوبة', 'warning');
        }
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================
$filterType = $_GET['type'] ?? '';
$filterRead = $_GET['read'] ?? '';

$sql = "SELECT n.id, n.type, n.title_ar, n.message_ar, n.is_read, n.created_at,
               s.id AS student_id, s.full_name_ar AS student_name, s.student_number,
               s.guardian_name, s.guardian_phone,
               u.full_name_ar AS parent_name, n.related_entity_id
        FROM notifications n
        INNER JOIN students s ON s.id = n.related_entity_id
        LEFT JOIN users u ON u.id = n.user_id
        WHERE n.type LIKE 'absence_%' AND n.related_entity_type = 'student'";
$params = [];

if ($schoolId && $userRole !== 'group_admin') {
    $sql .= " AND s.school_id = ?";
    $params[] = $schoolId;
}
if ($userRole === 'teacher') { $sql .= $teacherStudentWhere; $params = array_merge($params, $teacherStudentParams); }

if ($filterType) {
    $sql .= " AND n.type = ?";
    $params[] = 'absence_' . $filterType;
}
if ($filterRead !== '') {
    $sql .= " AND n.is_read = ?";
    $params[] = (int)$filterRead;
}

$sql .= " ORDER BY n.created_at DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}

// إحصائيات
$stats = ['total' => 0, 'unread' => 0, 'consecutive' => 0, 'threshold_count' => 0, 'weekly' => 0];
try {
    $sql = "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN n.is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN n.type = 'absence_consecutive' THEN 1 ELSE 0 END) as consecutive,
                SUM(CASE WHEN n.type = 'absence_threshold' THEN 1 ELSE 0 END) as threshold_count,
                SUM(CASE WHEN n.type = 'absence_weekly' THEN 1 ELSE 0 END) as weekly
            FROM notifications n
            INNER JOIN students s ON s.id = n.related_entity_id
            WHERE n.type LIKE 'absence_%' AND n.related_entity_type = 'student'";
    $params = [];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    if ($userRole === 'teacher') {
        $sql .= " AND s.id IN (
            SELECT DISTINCT se.student_id FROM student_enrollments se
            JOIN subject_teachers st ON st.classroom_id = se.class_id AND st.teacher_id = ? AND st.is_active = 1
            UNION
            SELECT DISTINCT se.student_id FROM student_enrollments se
            JOIN classes c ON c.id = se.class_id AND c.teacher_id = ? AND c.academic_year_id = ?
        )";
        $params = array_merge($params, [$userId, $userId, $academicYearId]);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $s = $stmt->fetch();
    if ($s) { $stats = $s; }
} catch (Exception $e) {}

// جلب الطلاب للنموذج
$studentsList = [];
try {
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
?>
<!-- إحصائيات سريعة -->
<style>
.stat-card-modern { flex:1; min-width:110px; background:var(--card-bg,#fff); border-radius:10px; padding:10px 14px 8px; text-align:center; position:relative; overflow:hidden; transition:transform .15s, box-shadow .15s; cursor:default; }
.stat-card-modern:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.08); }
.stat-card-modern .stat-bar { position:absolute; top:0; left:0; right:0; height:3px; }
.stat-card-modern .stat-icon { font-size:22px; margin-bottom:2px; }
.stat-card-modern .stat-value { font-size:22px; font-weight:700; line-height:1.2; }
.stat-card-modern .stat-label { font-size:11px; color:var(--text-muted); margin-top:2px; }
</style>
<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
    <div class="stat-card-modern" style="box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div class="stat-bar" style="background:linear-gradient(90deg,#667eea,#764ba2);"></div>
        <div class="stat-icon" style="color:#667eea;"><i class="fas fa-bell"></i></div>
        <div class="stat-value"><?= $stats['total'] ?></div>
        <div class="stat-label">إجمالي الإشعارات</div>
    </div>
    <div class="stat-card-modern" style="box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div class="stat-bar" style="background:linear-gradient(90deg,#f093fb,#f5576c);"></div>
        <div class="stat-icon" style="color:#f5576c;"><i class="fas fa-envelope-open-text"></i></div>
        <div class="stat-value" style="color:#f5576c;"><?= $stats['unread'] ?></div>
        <div class="stat-label">غير مقروءة</div>
    </div>
    <div class="stat-card-modern" style="box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div class="stat-bar" style="background:linear-gradient(90deg,#f6d365,#fda085);"></div>
        <div class="stat-icon" style="color:#fda085;"><i class="fas fa-calendar-times"></i></div>
        <div class="stat-value" style="color:#e67e22;"><?= $stats['consecutive'] ?></div>
        <div class="stat-label">غياب متتالي</div>
    </div>
    <div class="stat-card-modern" style="box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div class="stat-bar" style="background:linear-gradient(90deg,#4facfe,#00f2fe);"></div>
        <div class="stat-icon" style="color:#4facfe;"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-value" style="color:#3498db;"><?= $stats['threshold_count'] ?></div>
        <div class="stat-label">نسبة دون الحد</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- قائمة الإشعارات -->
    <div class="lg:col-span-2 section">
        <div class="card">
            <div class="card-header">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <h3 class="card-title">🔔 إشعارات الغياب</h3>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" name="mark_all_read" class="btn btn-sm btn-light"
                                onclick="return confirm('تحديد جميع الإشعارات كمقروءة؟')">
                            ✅ تحديد الكل كمقروء
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div style="padding:12px 16px; border-bottom:1px solid var(--border-color); display:flex; gap:8px; flex-wrap:wrap;">
                    <a href="notifications.php" class="btn btn-sm <?= !$filterType ? 'btn-primary' : 'btn-light' ?>">الكل</a>
                    <a href="notifications.php?type=consecutive" class="btn btn-sm <?= $filterType === 'consecutive' ? 'btn-primary' : 'btn-light' ?>">غياب متتالي</a>
                    <a href="notifications.php?type=threshold" class="btn btn-sm <?= $filterType === 'threshold' ? 'btn-primary' : 'btn-light' ?>">نسبة دون الحد</a>
                    <a href="notifications.php?type=weekly" class="btn btn-sm <?= $filterType === 'weekly' ? 'btn-primary' : 'btn-light' ?>">تقرير أسبوعي</a>
                    <a href="notifications.php?read=0" class="btn btn-sm <?= $filterRead === '0' ? 'btn-warning' : 'btn-light' ?>">غير مقروءة</a>
                </div>

                <?php if (empty($notifications)): ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px;">🔔</div>
                    لا توجد إشعارات غياب
                </div>
                <?php else: ?>
                <div style="max-height:500px; overflow-y:auto;">
                    <?php foreach ($notifications as $notif):
                        $rawType = str_replace('absence_', '', $notif['type']);
                        $typeColor = ['consecutive' => 'warning', 'threshold' => 'danger', 'weekly' => 'info'][$rawType] ?? 'secondary';
                        $isRead = (bool)$notif['is_read'];
                    ?>
                    <div style="padding:12px 16px; border-bottom:1px solid var(--border-color); display:flex; gap:12px; align-items:flex-start; background:<?= $isRead ? 'transparent' : 'rgba(13,202,240,0.05)' ?>;">
                        <div style="flex-shrink:0; margin-top:4px;">
                            <span class="badge badge-<?= $typeColor ?>"><?= e($notif['title_ar']) ?></span>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-weight:600; display:flex; align-items:center; gap:6px;">
                                <a href="../students/view.php?id=<?= $notif['student_id'] ?>" style="color:inherit; text-decoration:none;"><?= e($notif['student_name']) ?></a>
                                <?php if (!$isRead): ?>
                                <span style="width:8px; height:8px; background:var(--primary); border-radius:50%; display:inline-block;"></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                                رقم الطالب: <?= e($notif['student_number']) ?> •
                                ولي الأمر: <?= e($notif['parent_name'] ?? $notif['guardian_name'] ?? 'غير محدد') ?>
                                <?php if ($notif['guardian_phone']): ?>
                                • 📞 <?= e($notif['guardian_phone']) ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">
                                <?= e($notif['message_ar']) ?>
                                • أُرسل: <?= formatDateAr($notif['created_at'], 'medium') ?>
                            </div>
                        </div>
                        <div style="flex-shrink:0;">
                            <?php if (!$isRead): ?>
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                                <button type="submit" name="mark_read" class="btn btn-sm btn-light" title="تحديد كمقروء">✅</button>
                            </form>
                            <?php else: ?>
                            <span style="font-size:11px; color:var(--text-muted);">مقروء</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- نموذج إرسال إشعار جديد -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📤 إرسال إشعار جديد</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="form-group" style="margin-bottom:10px; position:relative;">
                        <label class="form-label">الطالب:</label>
                        <input type="text" id="student-search" class="form-control" placeholder="ابحث باسم الطالب..." required autocomplete="off" style="font-size:13px;">
                        <input type="hidden" name="student_id" id="student-id">
                        <div id="student-results" style="position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--border-color); border-radius:0 0 8px 8px; max-height:200px; overflow-y:auto; display:none; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <div class="form-group" style="flex:2; margin-bottom:10px;">
                            <label class="form-label">نوع الإشعار:</label>
                            <select name="notification_type" class="form-control" required style="font-size:13px;">
                                <option value="consecutive">غياب متتالي</option>
                                <option value="threshold">نسبة دون الحد</option>
                                <option value="weekly">تقرير أسبوعي</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1; margin-bottom:10px;">
                            <label class="form-label">أيام الغياب:</label>
                            <input type="number" name="absence_count" class="form-control" min="1" max="100" value="3" required style="font-size:13px;">
                        </div>
                    </div>
                    <button type="submit" name="send_notification" class="btn btn-primary" style="width:100%; padding:8px; font-size:13px;">
                        📤 إرسال الإشعار
                    </button>
                </form>
            </div>
        </div>

        <!-- سجل أولياء الأمور -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <h3 class="card-title">👨‍👩‍👧 سجل التواصل</h3>
            </div>
            <div class="card-body">
                <?php
                try {
                    $sql = "SELECT u.full_name_ar AS parent_name, COUNT(*) as notif_count,
                                   MAX(n.created_at) as last_sent
                            FROM notifications n
                            LEFT JOIN users u ON u.id = n.user_id
                            INNER JOIN students s ON s.id = n.related_entity_id
                            WHERE n.type LIKE 'absence_%' AND n.related_entity_type = 'student'";
                    $params = [];
                    if ($schoolId && $userRole !== 'group_admin') {
                        $sql .= " AND s.school_id = ?";
                        $params[] = $schoolId;
                    }
                    if ($userRole === 'teacher') {
                        $sql .= $teacherStudentWhere;
                        $params = array_merge($params, $teacherStudentParams);
                    }
                    $sql .= " GROUP BY n.user_id ORDER BY last_sent DESC LIMIT 10";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $parentHistory = $stmt->fetchAll();
                } catch (Exception $e) {
                    $parentHistory = [];
                }

                if (empty($parentHistory)):
                ?>
                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:13px;">
                    لا يوجد سجل تواصل بعد
                </div>
                <?php else: ?>
                <div style="max-height:200px; overflow-y:auto;">
                    <?php foreach ($parentHistory as $ph): ?>
                    <div style="padding:6px 0; border-bottom:1px solid var(--border-color); font-size:13px;">
                        <div style="font-weight:600;"><?= e($ph['parent_name'] ?? 'غير معروف') ?></div>
                        <div style="color:var(--text-muted);">
                            <?= $ph['notif_count'] ?> إشعار • آخر إرسال: <?= formatDateAr($ph['last_sent'], 'short') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var searchInput = document.getElementById('student-search');
    var hiddenInput = document.getElementById('student-id');
    var resultsDiv = document.getElementById('student-results');
    if (!searchInput) return;
    var timeout = null;

    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        var q = this.value.trim();
        if (q.length < 2) { resultsDiv.style.display = 'none'; hiddenInput.value = ''; return; }
        timeout = setTimeout(function() {
            fetch('../../pages/students/search_ajax.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultsDiv.innerHTML = '';
                    if (data.length === 0) {
                        resultsDiv.innerHTML = '<div style="padding:8px 12px; color:var(--text-muted);">لا توجد نتائج</div>';
                    } else {
                        data.forEach(function(st) {
                            var item = document.createElement('div');
                            item.style.cssText = 'padding:8px 12px; cursor:pointer; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;';
                            item.innerHTML = '<span>' + st.name_ar + '</span><span style="color:var(--text-muted); font-size:12px;">' + (st.student_id || '') + '</span>';
                            item.dataset.id = st.id;
                            item.dataset.name = st.name_ar;
                            item.addEventListener('click', function() {
                                hiddenInput.value = this.dataset.id;
                                searchInput.value = this.dataset.name;
                                resultsDiv.style.display = 'none';
                            });
                            resultsDiv.appendChild(item);
                        });
                    }
                    resultsDiv.style.display = 'block';
                })
                .catch(function() { resultsDiv.style.display = 'none'; });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
