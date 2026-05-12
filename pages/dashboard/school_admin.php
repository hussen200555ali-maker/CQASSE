<?php
/**
 * ============================================================
 * EQAS - لوحة تحكم مدير المدرسة
 * نظام التقييم الشامل - School Admin Dashboard
 * ============================================================
 * لوحة تحكم مدير المدرسة مع:
 * - مقياس مؤشر جودة التعليم + رادار
 * - إحصائيات سريعة (طلاب، حضور، معدل، سلوك)
 * - تقدم إكمال التقييمات
 * - ملخص التنبيهات
 * - نظرة عامة على الترفيع
 * - رسم بياني للحضور الأسبوعي
 * - توزيع الحوادث السلوكية
 * - توزيع الدرجات
 * - المهام المعلقة
 * - مقارنة أداء الفصول
 * - خط زمني للنشاطات الأخيرة
 * - إجراءات سريعة
 * ============================================================
 */

$pageTitle = 'لوحة تحكم مدير المدرسة';
$breadcrumb = ['الرئيسية' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('هذه الصفحة لمديري المدارس فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// إحصائيات أساسية
// ═══════════════════════════════════════════
$counts = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => 0];
try {
    $counts['students'] = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
    $counts['students']->execute([$schoolId]); $counts['students'] = $counts['students']->fetchColumn();

    $counts['teachers'] = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1");
    $counts['teachers']->execute([$schoolId]); $counts['teachers'] = $counts['teachers']->fetchColumn();

    $counts['classes'] = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND academic_year_id = ? AND is_active = 1");
    $counts['classes']->execute([$schoolId, $academicYearId]); $counts['classes'] = $counts['classes']->fetchColumn();

    $counts['subjects'] = $db->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// إحصائيات سريعة إضافية (نسبة الحضور، المعدل، السلوك)
// ═══════════════════════════════════════════
$quickStats = ['attendanceRate' => null, 'avgGpa' => null, 'behaviorScore' => null];
try {
    $stmt = $db->prepare("SELECT AVG(attendance_rate) as avg_rate FROM attendance_summaries WHERE is_active = 1 AND academic_year_id = ? AND class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1)");
    $stmt->execute([$academicYearId, $schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_rate'] !== null) $quickStats['attendanceRate'] = round($r['avg_rate'], 1);
} catch (Exception $e) { }

try {
    $stmt = $db->prepare("SELECT AVG(g.percentage) as avg_pct FROM grades g JOIN tests t ON g.test_id = t.id JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
    $stmt->execute([$schoolId, $academicYearId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_pct'] !== null) $quickStats['avgGpa'] = round($r['avg_pct'], 1);
} catch (Exception $e) { }

try {
    $stmt = $db->prepare("SELECT AVG(current_percentage) as avg_pct FROM behavior_scores WHERE is_active = 1 AND academic_year_id = ? AND class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1)");
    $stmt->execute([$academicYearId, $schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg_pct'] !== null) $quickStats['behaviorScore'] = round($r['avg_pct'], 1);
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// مؤشر جودة التعليم
// ═══════════════════════════════════════════
$eqiScore = null;
$eqiDimensions = [];
try {
    $stmt = $db->prepare("SELECT * FROM eqi_calculations WHERE school_id = ? AND is_active = 1 ORDER BY calculated_at DESC LIMIT 1");
    $stmt->execute([$schoolId]);
    $eqiRow = $stmt->fetch();
    if ($eqiRow) {
        $eqiScore = round($eqiRow['score'], 1);
    }
} catch (Exception $e) { }

// أبعاد EQI (محاكاة من بيانات التقييمات)
$dimensionScores = [];
$dimensionNames = ['المناخ المدرسي' => 'climate', 'المرافق' => 'facility', 'أداء المعلمين' => 'teaching', 'التحصيل الأكاديمي' => 'academic', 'السلوك' => 'behavior', 'الحضور' => 'attendance'];
foreach ($dimensionNames as $label => $key) {
    $dimensionScores[$key] = null;
}

try {
    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['climate'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['facility'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key IN ('teaching_self','teaching_peer','teaching_admin') AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['teaching'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;

    $stmt = $db->prepare("SELECT AVG(g.percentage) as avg FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.school_id = ? AND g.is_active = 1");
    $stmt->execute([$schoolId]); $r = $stmt->fetch(); $dimensionScores['academic'] = ($r && $r['avg'] !== null) ? round($r['avg'], 1) : null;
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// تقدم التقييمات
// ═══════════════════════════════════════════
$assessmentProgress = [
    'climate' => ['label' => 'المناخ المدرسي', 'done' => false],
    'facility' => ['label' => 'المرافق', 'done' => false],
    'teaching' => ['label' => 'تقييم المعلمين', 'done' => false],
    'behavior' => ['label' => 'السلوك', 'done' => false],
    'skills' => ['label' => 'المهارات', 'done' => false],
];
foreach ($dimensionScores as $key => $val) {
    if ($val !== null && isset($assessmentProgress[$key])) {
        $assessmentProgress[$key]['done'] = true;
    }
}
$completedAssessments = count(array_filter($assessmentProgress, function($a) { return $a['done']; }));
$totalAssessments = count($assessmentProgress);
$assessmentPercent = $totalAssessments > 0 ? round(($completedAssessments / $totalAssessments) * 100) : 0;

// ═══════════════════════════════════════════
// التنبيهات
// ═══════════════════════════════════════════
$alertSummary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
try {
    $stmt = $db->prepare("SELECT severity, COUNT(*) as cnt FROM alerts WHERE school_id = ? AND is_active = 1 AND status = 'open' GROUP BY severity");
    $stmt->execute([$schoolId ?: 0]);
    while ($row = $stmt->fetch()) {
        $sev = $row['severity'] ?? 'info';
        if (isset($alertSummary[$sev])) $alertSummary[$sev] = $row['cnt'];
        $alertSummary['total'] += $row['cnt'];
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// الترفيع
// ═══════════════════════════════════════════
$promoStats = ['proposed' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
try {
    $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM promotion_decisions WHERE school_id = ? AND is_active = 1 GROUP BY status");
    $stmt->execute([$schoolId]);
    while ($row = $stmt->fetch()) {
        if (isset($promoStats[$row['status']])) $promoStats[$row['status']] = $row['cnt'];
        $promoStats['total'] += $row['cnt'];
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// بيانات الحضور الأسبوعي (آخر 7 أيام)
// ═══════════════════════════════════════════
$weeklyAttendance = ['labels' => [], 'present' => [], 'absent' => [], 'late' => []];
try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $dayName = formatDateAr($date, 'short');
        $weeklyAttendance['labels'][] = $dayName;

        $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance_daily WHERE date = ? AND class_id IN (SELECT id FROM classes WHERE school_id = ? AND is_active = 1) AND is_active = 1 GROUP BY status");
        $stmt->execute([$date, $schoolId]);
        $dayData = ['present' => 0, 'absent' => 0, 'late' => 0];
        while ($row = $stmt->fetch()) {
            if (isset($dayData[$row['status']])) $dayData[$row['status']] = (int)$row['cnt'];
        }
        $weeklyAttendance['present'][] = $dayData['present'];
        $weeklyAttendance['absent'][] = $dayData['absent'];
        $weeklyAttendance['late'][] = $dayData['late'];
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// توزيع الحوادث السلوكية حسب الفئة
// ═══════════════════════════════════════════
$behaviorByCategory = ['labels' => [], 'counts' => [], 'colors' => []];
try {
    $stmt = $db->prepare("SELECT bc.name_ar, bc.type, COUNT(bi.id) as cnt FROM behavior_incidents bi JOIN behavior_categories bc ON bi.category_id = bc.id JOIN classes c ON bi.class_id = c.id WHERE c.school_id = ? AND bi.is_active = 1 AND bi.academic_year_id = ? GROUP BY bi.category_id ORDER BY cnt DESC LIMIT 8");
    $stmt->execute([$schoolId, $academicYearId]);
    $catColors = ['positive' => '#28a745', 'negative' => '#dc3545'];
    while ($row = $stmt->fetch()) {
        $behaviorByCategory['labels'][] = $row['name_ar'];
        $behaviorByCategory['counts'][] = (int)$row['cnt'];
        $behaviorByCategory['colors'][] = $catColors[$row['type']] ?? '#17a2b8';
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// توزيع الدرجات (مجموعات النسب المئوية)
// ═══════════════════════════════════════════
$gradeDistribution = ['labels' => ['0-19%', '20-39%', '40-49%', '50-59%', '60-69%', '70-79%', '80-89%', '90-100%'], 'counts' => [0,0,0,0,0,0,0,0]];
try {
    $stmt = $db->prepare("SELECT g.percentage FROM grades g JOIN tests t ON g.test_id = t.id JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND g.is_active = 1 AND t.academic_year_id = ?");
    $stmt->execute([$schoolId, $academicYearId]);
    while ($row = $stmt->fetch()) {
        $p = $row['percentage'];
        if ($p < 20) $gradeDistribution['counts'][0]++;
        elseif ($p < 40) $gradeDistribution['counts'][1]++;
        elseif ($p < 50) $gradeDistribution['counts'][2]++;
        elseif ($p < 60) $gradeDistribution['counts'][3]++;
        elseif ($p < 70) $gradeDistribution['counts'][4]++;
        elseif ($p < 80) $gradeDistribution['counts'][5]++;
        elseif ($p < 90) $gradeDistribution['counts'][6]++;
        else $gradeDistribution['counts'][7]++;
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// المهام المعلقة
// ═══════════════════════════════════════════
$pendingTasks = [];
// قرارات الترفيع المعلقة
if ($promoStats['proposed'] > 0) {
    $pendingTasks[] = ['icon' => '🎓', 'label' => 'قرارات ترفيع معلقة', 'count' => $promoStats['proposed'], 'link' => '../promotion/index.php', 'color' => 'var(--warning)'];
}
// تنبيهات غير معترف بها
if ($alertSummary['critical'] > 0) {
    $pendingTasks[] = ['icon' => '🚨', 'label' => 'تنبيهات حرجة', 'count' => $alertSummary['critical'], 'link' => '../alerts/index.php', 'color' => 'var(--danger)'];
}
// إقرارات أولياء الأمور المعلقة
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM parent_acknowledgments pa JOIN students s ON pa.student_id = s.id WHERE s.school_id = ? AND pa.is_acknowledged = 0 AND pa.is_active = 1");
    $stmt->execute([$schoolId]);
    $unacked = (int)$stmt->fetchColumn();
    if ($unacked > 0) {
        $pendingTasks[] = ['icon' => '📝', 'label' => 'إقرارات معلقة', 'count' => $unacked, 'link' => '../parent_ack/index.php', 'color' => 'var(--info)'];
    }
} catch (Exception $e) { }
// اختبارات بدون درجات
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tests t JOIN classes c ON t.class_id = c.id WHERE c.school_id = ? AND t.is_active = 1 AND t.academic_year_id = ? AND t.status = 'draft'");
    $stmt->execute([$schoolId, $academicYearId]);
    $draftTests = (int)$stmt->fetchColumn();
    if ($draftTests > 0) {
        $pendingTasks[] = ['icon' => '📝', 'label' => 'اختبارات مسودة', 'count' => $draftTests, 'link' => '../academic_assessment/index.php', 'color' => 'var(--secondary)'];
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// مقارنة أداء الفصول
// ═══════════════════════════════════════════
$classComparison = [];
try {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar as grade_name,
               (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1 AND se.status = 'enrolled') as student_count,
               (SELECT AVG(g.percentage) FROM grades g JOIN tests t ON g.test_id = t.id WHERE t.class_id = c.id AND g.is_active = 1) as avg_grade,
               (SELECT AVG(ats.attendance_rate) FROM attendance_summaries ats WHERE ats.class_id = c.id AND ats.is_active = 1) as avg_attendance,
               (SELECT AVG(bs.current_percentage) FROM behavior_scores bs WHERE bs.class_id = c.id AND bs.is_active = 1) as avg_behavior
        FROM classes c
        JOIN grade_levels gl ON c.grade_level_id = gl.id
        WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
        ORDER BY gl.level_number, c.name_ar
        LIMIT 15
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classComparison = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// النشاطات الأخيرة
// ═══════════════════════════════════════════
$recentActivities = [];
try {
    $stmt = $db->prepare("
        SELECT al.*, u.full_name_ar as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.school_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$schoolId]);
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) { }

// اسم المدرسة
$schoolName = '';
try {
    $stmt = $db->prepare("SELECT name_ar FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $schoolName = $stmt->fetchColumn();
} catch (Exception $e) { }
?>

<style>
.dashboard-stat {
    text-align: center; padding: 20px 16px; border-radius: var(--radius-lg);
    background: var(--surface); border: 1px solid var(--border-light);
    transition: all var(--transition-base);
}
.dashboard-stat:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.dashboard-stat .stat-icon { font-size: 28px; margin-bottom: 8px; }
.dashboard-stat .stat-value { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
.dashboard-stat .stat-label { font-size: 13px; color: var(--text-secondary); }
.eqi-gauge { position: relative; width: 180px; height: 180px; margin: 0 auto; }
.eqi-gauge-value { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
.progress-item { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
.progress-item:last-child { border-bottom: none; }
.task-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border-light); }
.task-item:last-child { border-bottom: none; }
.task-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; border-radius: var(--radius-full); font-size: 12px; font-weight: 700; color: white; padding: 0 6px; }
.timeline-item { display: flex; gap: 12px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid var(--border-light); }
.timeline-item:last-child { border-bottom: none; }
.timeline-dot { width: 10px; height: 10px; border-radius: var(--radius-full); margin-top: 5px; flex-shrink: 0; }
.class-perf-cell { padding: 6px 8px; text-align: center; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; }
</style>

<div class="section">
    <!-- عنوان المدرسة -->
    <div class="card mb-6">
        <div class="card-body" style="padding:12px 24px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h2 style="margin:0;font-size:20px;">🏫 <?= e($schoolName) ?></h2>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
                <?= formatDateAr(date('Y-m-d'), 'full') ?>
            </div>
        </div>
    </div>

    <!-- بطاقات الإحصائيات السريعة -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--primary);">
            <div class="stat-icon">👥</div>
            <div class="stat-value" style="color:var(--primary);"><?= number_format($counts['students']) ?></div>
            <div class="stat-label">الطلاب</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">📅</div>
            <div class="stat-value" style="color:var(--success);"><?= $quickStats['attendanceRate'] !== null ? $quickStats['attendanceRate'] . '%' : '-' ?></div>
            <div class="stat-label">نسبة الحضور</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">📊</div>
            <div class="stat-value" style="color:var(--info);"><?= $quickStats['avgGpa'] !== null ? $quickStats['avgGpa'] . '%' : '-' ?></div>
            <div class="stat-label">متوسط الدرجات</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">📋</div>
            <div class="stat-value" style="color:var(--secondary);"><?= $quickStats['behaviorScore'] !== null ? $quickStats['behaviorScore'] . '%' : '-' ?></div>
            <div class="stat-label">معدل السلوك</div>
        </div>
    </div>

    <!-- إحصائيات ثانوية -->
    <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">👨‍🏫</div>
            <div class="stat-value" style="color:var(--success);"><?= number_format($counts['teachers']) ?></div>
            <div class="stat-label">المعلمون</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">📚</div>
            <div class="stat-value" style="color:var(--info);"><?= number_format($counts['classes']) ?></div>
            <div class="stat-label">الفصول</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">📖</div>
            <div class="stat-value" style="color:var(--secondary);"><?= number_format($counts['subjects']) ?></div>
            <div class="stat-label">المواد</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--danger);">
            <div class="stat-icon">🚨</div>
            <div class="stat-value" style="color:var(--danger);"><?= $alertSummary['total'] ?></div>
            <div class="stat-label">تنبيهات نشطة</div>
        </div>
    </div>

    <div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- مقياس مؤشر جودة التعليم -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">📊 مؤشر جودة التعليم</h3>
                <a href="../eqi/report.php" class="btn btn-sm btn-light">التقرير</a>
            </div>
            <div class="card-body" style="text-align:center;">
                <?php if ($eqiScore !== null):
                    $rating = getEqiRating($eqiScore);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="font-size:64px;font-weight:bold;color:var(--<?= $rating[2] ?>);"><?= $eqiScore ?>%</div>
                    <span class="badge badge-<?= $rating[1] ?>" style="font-size:14px;"><?= $rating[0] ?></span>
                </div>
                <div style="width:100%;height:12px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= $eqiScore ?>%;height:100%;background:var(--<?= $rating[2] ?>);border-radius:var(--radius-full);transition:width 1s;"></div>
                </div>
                <?php else: ?>
                <div style="padding:30px;color:var(--text-muted);">
                    <div style="font-size:48px;margin-bottom:12px;">📊</div>
                    <p>لم يتم حساب المؤشر بعد</p>
                    <a href="../eqi/calculate.php" class="btn btn-primary btn-sm">بدء الحساب</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- تقدم التقييمات -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 تقدم التقييمات</h3>
            </div>
            <div class="card-body">
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="font-size:36px;font-weight:bold;color:var(--primary);"><?= $assessmentPercent ?>%</div>
                    <div style="color:var(--text-secondary);font-size:13px;"><?= $completedAssessments ?> من <?= $totalAssessments ?> مكتمل</div>
                </div>
                <div style="width:100%;height:8px;background:var(--border-light);border-radius:var(--radius-full);margin-bottom:16px;">
                    <div style="width:<?= $assessmentPercent ?>%;height:100%;background:var(--primary);border-radius:var(--radius-full);"></div>
                </div>
                <?php foreach ($assessmentProgress as $key => $ap): ?>
                <div class="progress-item">
                    <span style="width:20px;"><?= $ap['done'] ? '✅' : '⏳' ?></span>
                    <span style="flex:1;"><?= $ap['label'] ?></span>
                    <span style="font-size:12px;color:<?= $ap['done'] ? 'var(--success)' : 'var(--text-muted)' ?>;">
                        <?= $ap['done'] ? 'مكتمل' : 'بانتظار' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- التنبيهات -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">🚨 التنبيهات</h3>
                <a href="../alerts/index.php" class="btn btn-sm btn-light">الكل</a>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(220,53,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--danger);"><?= $alertSummary['critical'] ?></div>
                        <div style="font-size:11px;color:var(--danger);">حرج</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(255,193,7,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--warning);"><?= $alertSummary['warning'] ?></div>
                        <div style="font-size:11px;color:var(--warning);">تحذير</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(23,162,184,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--info);"><?= $alertSummary['info'] ?></div>
                        <div style="font-size:11px;color:var(--info);">معلومات</div>
                    </div>
                </div>
                <?php if ($alertSummary['total'] === 0): ?>
                <div style="text-align:center;color:var(--success);padding:12px;">✅ لا توجد تنبيهات نشطة</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- الترفيع -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">🎓 الترفيع</h3>
                <a href="../promotion/index.php" class="btn btn-sm btn-light">التفاصيل</a>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:12px;margin-bottom:16px;">
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(23,162,184,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--info);"><?= $promoStats['proposed'] ?></div>
                        <div style="font-size:11px;">مقترح</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(40,167,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--success);"><?= $promoStats['approved'] ?></div>
                        <div style="font-size:11px;">معتمد</div>
                    </div>
                    <div style="text-align:center;padding:8px;border-radius:var(--radius-md);background:rgba(220,53,69,0.1);">
                        <div style="font-size:24px;font-weight:bold;color:var(--danger);"><?= $promoStats['rejected'] ?></div>
                        <div style="font-size:11px;">مرفوض</div>
                    </div>
                </div>
                <?php if ($promoStats['proposed'] > 0): ?>
                <a href="../promotion/batch_approve.php" class="btn btn-warning btn-sm" style="width:100%;">⚡ اعتماد القرارات المعلقة (<?= $promoStats['proposed'] ?>)</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- المهام المعلقة -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📌 المهام المعلقة <span style="background:var(--danger);color:white;padding:1px 8px;border-radius:var(--radius-full);font-size:11px;"><?= count($pendingTasks) ?></span></h3>
            </div>
            <div class="card-body">
                <?php if (empty($pendingTasks)): ?>
                <div style="text-align:center;padding:20px;color:var(--success);">
                    ✅ لا توجد مهام معلقة
                </div>
                <?php else: ?>
                <?php foreach ($pendingTasks as $task): ?>
                <a href="<?= $task['link'] ?>" style="text-decoration:none;color:inherit;">
                    <div class="task-item">
                        <span style="font-size:20px;"><?= $task['icon'] ?></span>
                        <span style="flex:1;font-size:14px;"><?= e($task['label']) ?></span>
                        <span class="task-badge" style="background:<?= $task['color'] ?>;"><?= $task['count'] ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- أبعاد مؤشر الجودة (أفقي) -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📐 تفصيل أبعاد المؤشر</h3>
            </div>
            <div class="card-body" style="height:300px;">
                <canvas id="eqiDimBarChart"></canvas>
            </div>
        </div>
    </div>

    <!-- رسوم بيانية - الصف الأول -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 أبعاد مؤشر الجودة</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="eqiRadarChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🚨 توزيع التنبيهات</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="alertsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- رسوم بيانية - الصف الثاني -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- الحضور الأسبوعي -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📅 نظرة عامة على الحضور (آخر 7 أيام)</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="weeklyAttendanceChart"></canvas>
            </div>
        </div>
        <!-- توزيع الحوادث السلوكية -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 ملخص الحوادث السلوكية</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="behaviorCategoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- رسوم بيانية - الصف الثالث -->
    <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(380px,1fr));">
        <!-- توزيع الدرجات -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 توزيع الدرجات</h3>
            </div>
            <div class="card-body" style="height:320px;">
                <canvas id="gradeDistChart"></canvas>
            </div>
        </div>
        <!-- النشاطات الأخيرة -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🕐 آخر النشاطات</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div style="max-height:300px;overflow-y:auto;">
                    <?php if (empty($recentActivities)): ?>
                    <div style="text-align:center;padding:30px;color:var(--text-muted);">لا توجد نشاطات مسجلة</div>
                    <?php else: ?>
                    <?php foreach ($recentActivities as $act):
                        $actColors = ['create' => 'var(--success)', 'update' => 'var(--info)', 'delete' => 'var(--danger)', 'login' => 'var(--primary)', 'default' => 'var(--secondary)'];
                        $dotColor = $actColors[$act['action'] ?? 'default'] ?? $actColors['default'];
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot" style="background:<?= $dotColor ?>;"></div>
                        <div style="flex:1;">
                            <div style="display:flex;justify-content:space-between;">
                                <strong style="font-size:12px;"><?= e($act['user_name'] ?? 'نظام') ?></strong>
                                <span style="font-size:10px;color:var(--text-muted);"><?= formatDateAr($act['created_at'], 'short') ?></span>
                            </div>
                            <div style="color:var(--text-secondary);font-size:12px;margin-top:2px;"><?= e($act['description'] ?? $act['action']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- مقارنة أداء الفصول -->
    <?php if (!empty($classComparison)): ?>
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">📚 مقارنة أداء الفصول</h3>
        </div>
        <div class="card-body" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;min-width:600px;">
                <thead>
                    <tr>
                        <th>الصف</th>
                        <th>الفصل</th>
                        <th>الطلاب</th>
                        <th>متوسط الدرجات</th>
                        <th>نسبة الحضور</th>
                        <th>معدل السلوك</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classComparison as $cl):
                        $gradeVal = $cl['avg_grade'] !== null ? round($cl['avg_grade'], 1) : null;
                        $attVal = $cl['avg_attendance'] !== null ? round($cl['avg_attendance'], 1) : null;
                        $behVal = $cl['avg_behavior'] !== null ? round($cl['avg_behavior'], 1) : null;
                        $gradeColor = $gradeVal !== null ? ($gradeVal >= 70 ? 'var(--success)' : ($gradeVal >= 50 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                        $attColor = $attVal !== null ? ($attVal >= 85 ? 'var(--success)' : ($attVal >= 70 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                        $behColor = $behVal !== null ? ($behVal >= 70 ? 'var(--success)' : ($behVal >= 50 ? 'var(--warning)' : 'var(--danger)')) : 'var(--text-muted)';
                    ?>
                    <tr>
                        <td><?= e($cl['grade_name']) ?></td>
                        <td><?= e($cl['name_ar']) ?></td>
                        <td style="text-align:center;"><?= $cl['student_count'] ?></td>
                        <td><span class="class-perf-cell" style="color:<?= $gradeColor ?>;background:<?= $gradeVal !== null ? ($gradeVal >= 70 ? 'rgba(40,167,69,0.1)' : ($gradeVal >= 50 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $gradeVal !== null ? $gradeVal . '%' : '-' ?></span></td>
                        <td><span class="class-perf-cell" style="color:<?= $attColor ?>;background:<?= $attVal !== null ? ($attVal >= 85 ? 'rgba(40,167,69,0.1)' : ($attVal >= 70 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $attVal !== null ? $attVal . '%' : '-' ?></span></td>
                        <td><span class="class-perf-cell" style="color:<?= $behColor ?>;background:<?= $behVal !== null ? ($behVal >= 70 ? 'rgba(40,167,69,0.1)' : ($behVal >= 50 ? 'rgba(255,193,7,0.1)' : 'rgba(220,53,69,0.1)')) : 'transparent' ?>;"><?= $behVal !== null ? $behVal . '%' : '-' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // رادار أبعاد المؤشر
    var dimData = {
        dimensions: <?= json_encode($dimensionScores, JSON_UNESCAPED_UNICODE) ?>,
        overall: <?= $eqiScore ?: 0 ?>
    };
    eqasEqiRadar('eqiRadarChart', dimData);

    // توزيع التنبيهات
    eqasPieChart('alertsChart', '',
        ['حرج', 'تحذير', 'معلومات'],
        [<?= $alertSummary['critical'] ?>, <?= $alertSummary['warning'] ?>, <?= $alertSummary['info'] ?>],
        ['#dc3545', '#ffc107', '#17a2b8']
    );

    // أبعاد المؤشر - شريط أفقي
    var dimLabels = <?= json_encode(array_keys($dimensionNames), JSON_UNESCAPED_UNICODE) ?>;
    var dimValues = [];
    <?php foreach ($dimensionNames as $label => $key): ?>
    dimValues.push(<?= $dimensionScores[$key] ?? 0 ?>);
    <?php endforeach; ?>
    eqasBarChart('eqiDimBarChart', '',
        dimLabels,
        [{label: 'الدرجة', data: dimValues, backgroundColor: ['#1a5632','#c8a951','#17a2b8','#28a745','#fd7e14','#6f42c1']}],
        {indexAxis: 'y'}
    );

    // الحضور الأسبوعي
    eqasBarChart('weeklyAttendanceChart', '',
        <?= json_encode($weeklyAttendance['labels'], JSON_UNESCAPED_UNICODE) ?>,
        [
            {label: 'حاضر', data: <?= json_encode($weeklyAttendance['present']) ?>, backgroundColor: '#28a745'},
            {label: 'غائب', data: <?= json_encode($weeklyAttendance['absent']) ?>, backgroundColor: '#dc3545'},
            {label: 'متأخر', data: <?= json_encode($weeklyAttendance['late']) ?>, backgroundColor: '#ffc107'}
        ]
    );

    // الحوادث السلوكية حسب الفئة
    eqasPieChart('behaviorCategoryChart', '',
        <?= json_encode($behaviorByCategory['labels'], JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($behaviorByCategory['counts']) ?>,
        <?= json_encode($behaviorByCategory['colors']) ?>,
        false
    );

    // توزيع الدرجات
    eqasBarChart('gradeDistChart', '',
        <?= json_encode($gradeDistribution['labels']) ?>,
        [{
            label: 'عدد الطلاب',
            data: <?= json_encode($gradeDistribution['counts']) ?>,
            backgroundColor: ['#dc3545','#dc3545','#fd7e14','#ffc107','#ffc107','#17a2b8','#28a745','#1a5632']
        }]
    );
    });
    </script>

    <!-- إجراءات سريعة -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">⚡ إجراءات سريعة</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto" style="gap:12px;">
                <a href="../setup/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">⚙️</div><div style="font-size:13px;">الإعدادات الهيكلية</div>
                </a>
                <a href="../supervision/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📋</div><div style="font-size:13px;">التقييمات الإشرافية</div>
                </a>
                <a href="../academic_assessment/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📊</div><div style="font-size:13px;">التقييم الأكاديمي</div>
                </a>
                <a href="../attendance/daily.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📅</div><div style="font-size:13px;">الحضور اليومي</div>
                </a>
                <a href="../behavior/evaluate.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📋</div><div style="font-size:13px;">تقييم السلوك</div>
                </a>
                <a href="../alerts/run_checks.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🚨</div><div style="font-size:13px;">فحص التنبيهات</div>
                </a>
                <a href="../reports/school_report.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📄</div><div style="font-size:13px;">تقرير المدرسة</div>
                </a>
                <a href="../promotion/index.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🎓</div><div style="font-size:13px;">إدارة الترفيع</div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
