<?php
/**
 * ============================================================
 * EQAS - لوحة تحكم المعلم
 * نظام التقييم الشامل - Teacher Dashboard
 * ============================================================
 * لوحة تحكم المعلم مع:
 * - فصولي وموادي
 * - حالة إدخال الدرجات
 * - التقييمات المعلقة
 * - تنبيهات الطلاب
 * - درجة تقييم الأداء
 * - مقارنة أداء الفصول
 * - نسبة حضور الفصول
 * - الاختبارات القادمة
 * - الطلاب بحاجة متابعة
 * - ملخص تقييم الأداء
 * - تقدم تقييم المهارات
 * - إجراءات سريعة
 * ============================================================
 */

$pageTitle = 'لوحة تحكم المعلم';
$breadcrumb = ['الرئيسية' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('هذه الصفحة للمعلمين فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$userId = $_SESSION['user']['id'];
$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// فصولي وموادي
// ═══════════════════════════════════════════
$myClasses = [];
try {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar as grade_name,
               (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1 AND se.status = 'enrolled') as student_count
        FROM classes c
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        WHERE c.teacher_id = ? AND c.is_active = 1 AND c.academic_year_id = ?
        ORDER BY gl.level_number, c.name_ar
    ");
    $stmt->execute([$userId, $academicYearId]);
    $myClasses = $stmt->fetchAll();
} catch (Exception $e) { }

// المواد التي أعلمها
$mySubjects = [];
try {
    $stmt = $db->prepare("
        SELECT DISTINCT s.id, s.name_ar, s.code
        FROM teacher_assignments ta
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ? AND ta.is_active = 1 AND ta.academic_year_id = ?
        ORDER BY s.name_ar
    ");
    $stmt->execute([$userId, $academicYearId]);
    $mySubjects = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// حالة إدخال الدرجات
// ═══════════════════════════════════════════
$gradeEntryStatus = ['pending' => 0, 'partial' => 0, 'complete' => 0, 'total' => 0];
try {
    // عدد الاختبارات المخصصة لي
    $stmt = $db->prepare("
        SELECT t.id, t.title_ar, t.status,
               (SELECT COUNT(*) FROM grades g WHERE g.test_id = t.id AND g.is_active = 1) as graded_count,
               (SELECT COUNT(*) FROM student_enrollments se JOIN classes c ON se.class_id = c.id WHERE c.id = t.class_id AND se.is_active = 1 AND se.status = 'enrolled') as total_students
        FROM tests t
        WHERE t.created_by = ? AND t.is_active = 1 AND t.academic_year_id = ?
        ORDER BY t.test_date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId, $academicYearId]);
    $myTests = $stmt->fetchAll();

    foreach ($myTests as $test) {
        $gradeEntryStatus['total']++;
        if ($test['graded_count'] == 0) {
            $gradeEntryStatus['pending']++;
        } elseif ($test['graded_count'] < $test['total_students']) {
            $gradeEntryStatus['partial']++;
        } else {
            $gradeEntryStatus['complete']++;
        }
    }
} catch (Exception $e) {
    $myTests = [];
}

// ═══════════════════════════════════════════
// تقييمات معلقة (ذاتي/أقران)
// ═══════════════════════════════════════════
$pendingEvals = [];
try {
    // تقييم ذاتي
    $stmt = $db->prepare("
        SELECT 'self' as eval_type, 'التقييم الذاتي' as label, af.name_ar as form_name
        FROM assessment_forms af
        WHERE af.form_key = 'teaching_self' AND af.is_active = 1
        AND NOT EXISTS (
            SELECT 1 FROM assessment_responses ar
            WHERE ar.form_id = af.id AND ar.teacher_id = ? AND ar.is_active = 1
        )
    ");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch()) { $pendingEvals[] = $row; }

    // تقييم الأقران - المعلمون الذين يجب تقييمهم
    $stmt = $db->prepare("
        SELECT 'peer' as eval_type, 'تقييم الأقران' as label,
               u.full_name_ar as form_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1 AND u.id != ?
        ORDER BY u.full_name_ar
        LIMIT 5
    ");
    $stmt->execute([$schoolId, $userId]);
    while ($row = $stmt->fetch()) { $pendingEvals[] = $row; }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// تنبيهات طلابية
// ═══════════════════════════════════════════
$studentAlerts = [];
try {
    $stmt = $db->prepare("
        SELECT a.id, a.alert_type, a.severity, a.title_ar, a.message_ar,
               s.full_name_ar as student_name, a.created_at
        FROM alerts a
        JOIN students s ON a.student_id = s.id
        JOIN student_enrollments se ON s.id = se.student_id
        JOIN classes c ON se.class_id = c.id
        WHERE c.teacher_id = ? AND a.is_active = 1 AND a.status = 'open'
        ORDER BY a.severity ASC, a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $studentAlerts = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// درجة تقييم الأداء
// ═══════════════════════════════════════════
$myTeachingScore = ['self' => null, 'peer' => null, 'admin' => null, 'combined' => null];
$evalWeights = ['teaching_self' => 0.20, 'teaching_peer' => 0.30, 'teaching_admin' => 0.50];
try {
    foreach (['teaching_self' => 'self', 'teaching_peer' => 'peer', 'teaching_admin' => 'admin'] as $formKey => $key) {
        $stmt = $db->prepare("
            SELECT AVG(ar.numeric_value) as avg_score
            FROM assessment_responses ar
            JOIN assessment_forms af ON ar.form_id = af.id
            WHERE af.form_key = ? AND ar.teacher_id = ? AND ar.is_active = 1
        ");
        $stmt->execute([$formKey, $userId]);
        $row = $stmt->fetch();
        if ($row && $row['avg_score'] !== null) {
            $myTeachingScore[$key] = round($row['avg_score'], 1);
        }
    }

    // حساب المُجمّع
    $combined = 0; $totalW = 0;
    foreach (['self' => 'teaching_self', 'peer' => 'teaching_peer', 'admin' => 'teaching_admin'] as $key => $formKey) {
        if ($myTeachingScore[$key] !== null) {
            $combined += $myTeachingScore[$key] * $evalWeights[$formKey];
            $totalW += $evalWeights[$formKey];
        }
    }
    $myTeachingScore['combined'] = $totalW > 0 ? round($combined / $totalW, 1) : null;
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// مقارنة أداء الفصول (للمعلم)
// ═══════════════════════════════════════════
$classPerformance = ['labels' => [], 'avgGrades' => [], 'avgAttendance' => []];
try {
    foreach ($myClasses as $cl) {
        $classPerformance['labels'][] = $cl['grade_name'] . ' - ' . $cl['name_ar'];
        // متوسط درجات الفصل
        $stmt = $db->prepare("SELECT AVG(g.percentage) as avg_pct FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.class_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
        $stmt->execute([$cl['id'], $academicYearId]);
        $r = $stmt->fetch();
        $classPerformance['avgGrades'][] = $r && $r['avg_pct'] !== null ? round($r['avg_pct'], 1) : 0;
        // نسبة حضور الفصل
        $stmt = $db->prepare("SELECT AVG(attendance_rate) as avg_rate FROM v_attendance_summary WHERE class_id = ? AND academic_year_id = ?");
        $stmt->execute([$cl['id'], $academicYearId]);
        $r = $stmt->fetch();
        $classPerformance['avgAttendance'][] = $r && $r['avg_rate'] !== null ? round($r['avg_rate'], 1) : 0;
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// نسبة حضور فصول المعلم
// ═══════════════════════════════════════════
$classAttendance = ['labels' => [], 'present' => [], 'absent' => [], 'late' => []];
try {
    foreach ($myClasses as $cl) {
        $classAttendance['labels'][] = $cl['name_ar'];
        $stmt = $db->prepare("SELECT SUM(present_days) as p, SUM(absent_days) as a, SUM(late_days) as l FROM v_attendance_summary WHERE class_id = ? AND academic_year_id = ?");
        $stmt->execute([$cl['id'], $academicYearId]);
        $r = $stmt->fetch();
        $classAttendance['present'][] = $r ? (int)$r['p'] : 0;
        $classAttendance['absent'][] = $r ? (int)$r['a'] : 0;
        $classAttendance['late'][] = $r ? (int)$r['l'] : 0;
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// الاختبارات القادمة
// ═══════════════════════════════════════════
$upcomingTests = [];
try {
    $stmt = $db->prepare("
        SELECT t.title_ar, t.test_date, t.max_grade, c.name_ar as class_name, gl.name_ar as grade_name, s.name_ar as subject_name
        FROM tests t
        JOIN classes c ON t.class_id = c.id
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        JOIN subjects s ON t.subject_id = s.id
        WHERE t.created_by = ? AND t.is_active = 1 AND t.academic_year_id = ?
        AND t.test_date >= CURDATE()
        ORDER BY t.test_date ASC
        LIMIT 8
    ");
    $stmt->execute([$userId, $academicYearId]);
    $upcomingTests = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// الطلاب بحاجة متابعة
// ═══════════════════════════════════════════
$studentsNeedingAttention = [];
try {
    $classIds = array_column($myClasses, 'id');
    if (!empty($classIds)) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        // درجات منخفضة
        $stmt = $db->prepare("
            SELECT s.id, s.full_name_ar, c.name_ar as class_name, gl.name_ar as grade_name,
                   AVG(g.percentage) as avg_grade, 'low_grades' as reason
            FROM students s
            JOIN student_enrollments se ON s.id = se.student_id
            JOIN classes c ON se.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            JOIN grades g ON g.student_id = s.id
            WHERE se.class_id IN ($placeholders) AND se.is_active = 1 AND se.status = 'enrolled'
            AND s.is_active = 1 AND g.is_active = 1
            GROUP BY s.id
            HAVING avg_grade < 50
            ORDER BY avg_grade ASC
            LIMIT 5
        ");
        $stmt->execute($classIds);
        while ($row = $stmt->fetch()) { $row['reason_label'] = 'درجات منخفضة'; $studentsNeedingAttention[] = $row; }

        // حضور ضعيف
        $stmt = $db->prepare("
            SELECT s.id, s.full_name_ar, c.name_ar as class_name, gl.name_ar as grade_name,
                   v.attendance_rate, 'poor_attendance' as reason
            FROM students s
            JOIN student_enrollments se ON s.id = se.student_id
            JOIN classes c ON se.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            JOIN v_attendance_summary v ON v.student_id = s.id AND v.class_id = c.id
            WHERE se.class_id IN ($placeholders) AND se.is_active = 1 AND se.status = 'enrolled'
            AND s.is_active = 1 AND v.attendance_rate < 75
            GROUP BY s.id
            ORDER BY v.attendance_rate ASC
            LIMIT 5
        ");
        $stmt->execute($classIds);
        while ($row = $stmt->fetch()) { $row['reason_label'] = 'حضور ضعيف'; $studentsNeedingAttention[] = $row; }

        // سلوك سيء
        $stmt = $db->prepare("
            SELECT s.id, s.full_name_ar, c.name_ar as class_name, gl.name_ar as grade_name,
                   e.current_percentage as behavior_pct, 'poor_behavior' as reason
            FROM students s
            JOIN student_enrollments se ON s.id = se.student_id
            JOIN classes c ON se.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            JOIN evaluations e ON e.student_id = s.id AND e.class_id = c.id
            WHERE se.class_id IN ($placeholders) AND se.is_active = 1 AND se.status = 'enrolled'
            AND s.is_active = 1 AND e.eval_type = 'behavior_score' AND e.is_active = 1 AND e.current_percentage < 60
            GROUP BY s.id
            ORDER BY e.current_percentage ASC
            LIMIT 5
        ");
        $stmt->execute($classIds);
        while ($row = $stmt->fetch()) { $row['reason_label'] = 'سلوك غير مقبول'; $studentsNeedingAttention[] = $row; }

        // إزالة التكرار
        $seen = [];
        $unique = [];
        foreach ($studentsNeedingAttention as $s) {
            if (!isset($seen[$s['id']])) {
                $seen[$s['id']] = true;
                $unique[] = $s;
            }
        }
        $studentsNeedingAttention = array_slice($unique, 0, 10);
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// عدد الدرجات المُدخلة مؤخراً
// ═══════════════════════════════════════════
$recentGradesCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.created_by = ? AND g.is_active = 1 AND g.graded_by = ? AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute([$userId, $userId]);
    $recentGradesCount = (int)$stmt->fetchColumn();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// تقدم تقييم المهارات
// ═══════════════════════════════════════════
$skillsProgress = ['evaluated' => 0, 'total' => 0, 'percent' => 0];
try {
    if (!empty($classIds)) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.class_id IN ($placeholders) AND se.is_active = 1 AND se.status = 'enrolled' AND s.is_active = 1");
        $stmt->execute($classIds);
        $skillsProgress['total'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(DISTINCT ar.student_id) FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id JOIN student_enrollments se ON ar.student_id = se.student_id WHERE af.form_key = 'skills_checklist' AND ar.teacher_id = ? AND ar.is_active = 1 AND se.class_id IN ($placeholders) AND se.is_active = 1");
        $params = array_merge([$userId], $classIds);
        $stmt->execute($params);
        $skillsProgress['evaluated'] = (int)$stmt->fetchColumn();

        $skillsProgress['percent'] = $skillsProgress['total'] > 0 ? round(($skillsProgress['evaluated'] / $skillsProgress['total']) * 100) : 0;
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// اسم المعلم
// ═══════════════════════════════════════════
$teacherName = $_SESSION['user']['full_name'] ?? 'معلم';
?>

<div class="section">
    <!-- ترحيب -->
    <div class="card mb-6">
        <div class="card-body" style="padding:16px 24px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h2 style="margin:0;font-size:20px;">مرحباً، <?= e($teacherName) ?> 👋</h2>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
                <?= formatDateAr(date('Y-m-d'), 'full') ?>
            </div>
        </div>
    </div>

    <!-- بطاقات ملخصة -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--primary);">
            <div class="stat-icon">📚</div>
            <div class="stat-value" style="color:var(--primary);"><?= count($myClasses) ?></div>
            <div class="stat-label">فصولي</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">📖</div>
            <div class="stat-value" style="color:var(--info);"><?= count($mySubjects) ?></div>
            <div class="stat-label">موادي</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--warning);">
            <div class="stat-icon">⏳</div>
            <div class="stat-value" style="color:var(--warning);"><?= $gradeEntryStatus['pending'] + $gradeEntryStatus['partial'] ?></div>
            <div class="stat-label">اختبارات معلقة</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--danger);">
            <div class="stat-icon">🚨</div>
            <div class="stat-value" style="color:var(--danger);"><?= count($studentAlerts) ?></div>
            <div class="stat-label">تنبيهات طلابية</div>
        </div>
    </div>

    <!-- بطاقات ملخصة إضافية -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">📝</div>
            <div class="stat-value" style="color:var(--success);"><?= $recentGradesCount ?></div>
            <div class="stat-label">درجات مُدخلة (7 أيام)</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">🎯</div>
            <div class="stat-value" style="color:var(--secondary);"><?= $skillsProgress['percent'] ?>%</div>
            <div class="stat-label">تقدم تقييم المهارات</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">📋</div>
            <div class="stat-value" style="color:var(--info);"><?= count($pendingEvals) ?></div>
            <div class="stat-label">تقييمات معلقة</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--warning);">
            <div class="stat-icon">📅</div>
            <div class="stat-value" style="color:var(--warning);"><?= count($upcomingTests) ?></div>
            <div class="stat-label">اختبارات قادمة</div>
        </div>
    </div>

    <div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- فصولي -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📚 فصولي وموادي</h3>
            </div>
            <div class="card-body">
                <?php if (empty($myClasses)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    لم يتم تعيين فصول لك بعد.
                </div>
                <?php else: ?>
                <?php foreach ($myClasses as $cl): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light);">
                    <div>
                        <strong><?= e($cl['grade_name']) ?> - <?= e($cl['name_ar']) ?></strong>
                        <span style="font-size:12px;color:var(--text-muted);margin-right:8px;"><?= $cl['student_count'] ?> طالب</span>
                    </div>
                    <a href="../academic_assessment/grade_entry.php?class_id=<?= $cl['id'] ?>" class="btn btn-sm btn-light">📊 الدرجات</a>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($mySubjects)): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:2px solid var(--border-light);">
                    <strong style="font-size:13px;">المواد:</strong>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;">
                        <?php foreach ($mySubjects as $s): ?>
                        <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:rgba(26,86,50,0.1);color:var(--primary);"><?= e($s['name_ar']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- حالة إدخال الدرجات -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📝 حالة إدخال الدرجات</h3>
            </div>
            <div class="card-body">
                <?php if ($gradeEntryStatus['total'] === 0): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    لا توجد اختبارات حالياً.
                </div>
                <?php else: ?>
                <div class="grid grid-3" style="gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(220,53,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--danger);"><?= $gradeEntryStatus['pending'] ?></div>
                        <div style="font-size:11px;">لم يبدأ</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(255,193,7,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--warning);"><?= $gradeEntryStatus['partial'] ?></div>
                        <div style="font-size:11px;">جزئي</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(40,167,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--success);"><?= $gradeEntryStatus['complete'] ?></div>
                        <div style="font-size:11px;">مكتمل</div>
                    </div>
                </div>

                <!-- آخر الاختبارات -->
                <?php foreach (array_slice($myTests, 0, 5) as $test):
                    $statusIcon = $test['graded_count'] == 0 ? '⏳' : ($test['graded_count'] < $test['total_students'] ? '📝' : '✅');
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border-light);font-size:13px;">
                    <span><?= $statusIcon ?> <?= e($test['title_ar']) ?></span>
                    <span style="color:var(--text-muted);"><?= $test['graded_count'] ?>/<?= $test['total_students'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- تقييمات معلقة -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 التقييمات المعلقة</h3>
            </div>
            <div class="card-body">
                <?php if (empty($pendingEvals)): ?>
                <div style="text-align:center;padding:20px;color:var(--success);">
                    ✅ لا توجد تقييمات معلقة
                </div>
                <?php else: ?>
                <?php foreach ($pendingEvals as $pe): ?>
                <div style="padding:8px 0;border-bottom:1px solid var(--border-light);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span style="font-size:12px;padding:1px 6px;border-radius:var(--radius-full);background:var(--warning);color:var(--text-primary);">
                                <?= $pe['eval_type'] === 'self' ? 'ذاتي' : 'أقران' ?>
                            </span>
                            <span style="font-size:13px;margin-right:6px;"><?= e($pe['label']) ?></span>
                        </div>
                        <span style="font-size:13px;color:var(--text-secondary);"><?= e($pe['form_name'] ?? '') ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:12px;">
                    <a href="../teaching/self_index.php" class="btn btn-primary btn-sm">بدء التقييم الذاتي</a>
                    <a href="../teaching/peer_index.php" class="btn btn-secondary btn-sm" style="margin-right:4px;">تقييم الأقران</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- درجة تقييم الأداء -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⭐ درجة تقييم أدائي</h3>
            </div>
            <div class="card-body" style="text-align:center;">
                <?php if ($myTeachingScore['combined'] !== null):
                    $scoreColor = $myTeachingScore['combined'] < 60 ? 'var(--danger)' : ($myTeachingScore['combined'] < 80 ? 'var(--warning)' : 'var(--success)');
                ?>
                <div style="font-size:48px;font-weight:bold;color:<?= $scoreColor ?>;margin-bottom:4px;">
                    <?= $myTeachingScore['combined'] ?>%
                </div>
                <div style="color:var(--text-secondary);font-size:13px;margin-bottom:16px;">
                    <?= $myTeachingScore['combined'] >= 80 ? 'ممتاز' : ($myTeachingScore['combined'] >= 60 ? 'مقبول' : 'يحتاج تطوير') ?>
                </div>

                <div class="grid grid-3" style="gap:8px;font-size:13px;">
                    <div style="padding:8px;border-radius:var(--radius-md);background:rgba(23,162,184,0.1);">
                        <div style="color:var(--info);font-weight:bold;"><?= $myTeachingScore['self'] ?? '-' ?>%</div>
                        <div>ذاتي (20%)</div>
                    </div>
                    <div style="padding:8px;border-radius:var(--radius-md);background:rgba(255,193,7,0.1);">
                        <div style="color:var(--warning);font-weight:bold;"><?= $myTeachingScore['peer'] ?? '-' ?>%</div>
                        <div>أقران (30%)</div>
                    </div>
                    <div style="padding:8px;border-radius:var(--radius-md);background:rgba(26,86,50,0.1);">
                        <div style="color:var(--primary);font-weight:bold;"><?= $myTeachingScore['admin'] ?? '-' ?>%</div>
                        <div>إدارة (50%)</div>
                    </div>
                </div>

                <?php if ($myTeachingScore['combined'] < 60): ?>
                <div class="alert alert-warning" style="margin-top:12px;font-size:12px;text-align:right;">
                    ⚠️ درجتك أقل من 60%. يُرجى مراجعة خطة التطوير.
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div style="padding:20px;color:var(--text-muted);">
                    <div style="font-size:40px;margin-bottom:8px;">⭐</div>
                    <p>لم يتم تقييم أدائك بعد</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- الاختبارات القادمة -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">📅 الاختبارات القادمة</h3>
                <a href="../academic_assessment/index.php" class="btn btn-sm btn-light">الكل</a>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($upcomingTests)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    لا توجد اختبارات قادمة
                </div>
                <?php else: ?>
                <div style="max-height:280px;overflow-y:auto;">
                    <?php foreach ($upcomingTests as $ut):
                        $daysLeft = (int)((strtotime($ut['test_date']) - time()) / 86400);
                        $urgencyColor = $daysLeft <= 2 ? 'var(--danger)' : ($daysLeft <= 5 ? 'var(--warning)' : 'var(--text-secondary)');
                    ?>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);border-right:3px solid <?= $urgencyColor ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-size:13px;font-weight:600;"><?= e($ut['title_ar']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                    <?= e($ut['grade_name']) ?> - <?= e($ut['class_name']) ?> | <?= e($ut['subject_name']) ?>
                                </div>
                            </div>
                            <div style="text-align:left;">
                                <div style="font-size:12px;color:<?= $urgencyColor ?>;font-weight:600;"><?= formatDateAr($ut['test_date'], 'short') ?></div>
                                <div style="font-size:10px;color:var(--text-muted);">من <?= $ut['max_grade'] ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- الطلاب بحاجة متابعة -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">👁️ طلاب بحاجة متابعة <span style="background:var(--danger);color:white;padding:1px 8px;border-radius:var(--radius-full);font-size:11px;"><?= count($studentsNeedingAttention) ?></span></h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($studentsNeedingAttention)): ?>
                <div style="text-align:center;padding:20px;color:var(--success);">
                    ✅ لا يوجد طلاب بحاجة متابعة حالياً
                </div>
                <?php else: ?>
                <div style="max-height:280px;overflow-y:auto;">
                    <?php foreach ($studentsNeedingAttention as $sa):
                        $reasonColors = ['low_grades' => 'var(--danger)', 'poor_attendance' => 'var(--warning)', 'poor_behavior' => '#fd7e14'];
                        $reasonIcons = ['low_grades' => '📊', 'poor_attendance' => '📅', 'poor_behavior' => '📋'];
                        $rc = $reasonColors[$sa['reason']] ?? 'var(--info)';
                        $ri = $reasonIcons[$sa['reason']] ?? '⚠️';
                    ?>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);border-right:3px solid <?= $rc ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-size:13px;font-weight:600;"><?= $ri ?> <?= e($sa['full_name_ar']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                    <?= e($sa['grade_name']) ?> - <?= e($sa['class_name']) ?>
                                </div>
                            </div>
                            <span style="font-size:11px;padding:2px 8px;border-radius:var(--radius-full);background:<?= $rc ?>;color:white;">
                                <?= e($sa['reason_label']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- تنبيهات طلابية -->
    <?php if (!empty($studentAlerts)): ?>
    <div class="card mt-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card-title">🚨 تنبيهات طلابية</h3>
            <a href="../alerts/index.php" class="btn btn-sm btn-light">عرض الكل</a>
        </div>
        <div class="card-body" style="padding:0;">
            <div style="max-height:250px;overflow-y:auto;">
                <?php foreach ($studentAlerts as $alert):
                    $sevColors = ['critical' => 'var(--danger)', 'warning' => 'var(--warning)', 'info' => 'var(--info)'];
                    $color = $sevColors[$alert['severity'] ?? 'info'] ?? 'var(--info)';
                ?>
                <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);border-right:3px solid <?= $color ?>;">
                    <div style="display:flex;justify-content:space-between;font-size:13px;">
                        <strong><?= e($alert['student_name']) ?></strong>
                        <span style="font-size:11px;color:<?= $color ?>;"><?= getSeverityLabel($alert['severity'] ?? 'info') ?></span>
                    </div>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">
                        <?= e($alert['title_ar'] ?? getAlertTypeLabel($alert['alert_type'] ?? '')) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- رسوم بيانية -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">⭐ تقييم الأداء</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="teachingScoreChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📝 حالة إدخال الدرجات</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="gradeEntryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- رسوم بيانية - صف إضافي -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 مقارنة أداء الفصول</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="classPerformanceChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📅 نسبة الحضور بالفصول</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="classAttendanceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- تقدم تقييم المهارات -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">🎯 تقدم تقييم المهارات</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                <div style="font-size:32px;font-weight:bold;color:var(--primary);"><?= $skillsProgress['percent'] ?>%</div>
                <div>
                    <div style="font-size:14px;"><?= $skillsProgress['evaluated'] ?> من <?= $skillsProgress['total'] ?> طالب تم تقييمهم</div>
                    <div style="font-size:12px;color:var(--text-muted);">تقييم المهارات الأساسية للطلاب</div>
                </div>
            </div>
            <div style="width:100%;height:12px;background:var(--border-light);border-radius:var(--radius-full);">
                <div style="width:<?= $skillsProgress['percent'] ?>%;height:100%;background:var(--primary);border-radius:var(--radius-full);transition:width 0.8s;"></div>
            </div>
            <div style="margin-top:12px;">
                <a href="../skills/evaluate.php" class="btn btn-primary btn-sm">📝 متابعة تقييم المهارات</a>
            </div>
        </div>
    </div>

    <script>
    // تقييم الأداء
    eqasBarChart('teachingScoreChart', '',
        ['ذاتي', 'أقران', 'إدارة', 'مُجمّع'],
        [{
            label: 'الدرجة',
            data: [
                <?= $myTeachingScore['self'] ?? 0 ?>,
                <?= $myTeachingScore['peer'] ?? 0 ?>,
                <?= $myTeachingScore['admin'] ?? 0 ?>,
                <?= $myTeachingScore['combined'] ?? 0 ?>
            ],
            backgroundColor: ['#17a2b8', '#ffc107', '#1a5632', '#c8a951']
        }]
    );

    // حالة إدخال الدرجات
    eqasPieChart('gradeEntryChart', '',
        ['لم يبدأ', 'جزئي', 'مكتمل'],
        [<?= $gradeEntryStatus['pending'] ?>, <?= $gradeEntryStatus['partial'] ?>, <?= $gradeEntryStatus['complete'] ?>],
        ['#dc3545', '#ffc107', '#28a745']
    );

    // مقارنة أداء الفصول
    var cpLabels = <?= json_encode($classPerformance['labels'], JSON_UNESCAPED_UNICODE) ?>;
    var cpGrades = <?= json_encode($classPerformance['avgGrades']) ?>;
    var cpAttendance = <?= json_encode($classPerformance['avgAttendance']) ?>;
    eqasBarChart('classPerformanceChart', '',
        cpLabels,
        [
            {label: 'متوسط الدرجات', data: cpGrades, backgroundColor: '#1a5632'},
            {label: 'نسبة الحضور', data: cpAttendance, backgroundColor: '#17a2b8'}
        ]
    );

    // نسبة الحضور بالفصول
    var caLabels = <?= json_encode($classAttendance['labels'], JSON_UNESCAPED_UNICODE) ?>;
    var caPresent = <?= json_encode($classAttendance['present']) ?>;
    var caAbsent = <?= json_encode($classAttendance['absent']) ?>;
    var caLate = <?= json_encode($classAttendance['late']) ?>;
    eqasBarChart('classAttendanceChart', '',
        caLabels,
        [
            {label: 'حضور', data: caPresent, backgroundColor: '#28a745'},
            {label: 'غياب', data: caAbsent, backgroundColor: '#dc3545'},
            {label: 'تأخير', data: caLate, backgroundColor: '#ffc107'}
        ]
    );
    </script>

    <!-- إجراءات سريعة -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">⚡ إجراءات سريعة</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto" style="gap:12px;">
                <a href="../academic_assessment/grade_entry.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📝</div><div style="font-size:13px;">إدخال الدرجات</div>
                </a>
                <a href="../attendance/daily.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📅</div><div style="font-size:13px;">تسجيل الحضور</div>
                </a>
                <a href="../behavior/evaluate.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📋</div><div style="font-size:13px;">تقييم السلوك</div>
                </a>
                <a href="../teaching/self_index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🪞</div><div style="font-size:13px;">التقييم الذاتي</div>
                </a>
                <a href="../skills/evaluate.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🎯</div><div style="font-size:13px;">تقييم المهارات</div>
                </a>
                <a href="../notes/add.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📌</div><div style="font-size:13px;">إضافة ملاحظة</div>
                </a>
                <a href="../academic_assessment/create_test.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📄</div><div style="font-size:13px;">إنشاء اختبار</div>
                </a>
                <a href="../reports/teacher_report.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📊</div><div style="font-size:13px;">تقرير الأداء</div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
