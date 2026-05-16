<?php
/**
 * ============================================================
 * تحليل أداء الشعبة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تحليل أداء الشعبة';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => 'index.php',
    'تحليل أداء الشعبة' => null
];
$pageCSS = 'analytics.css';

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المجموعة
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// الحصول على المعرفات من الرابط
$schoolId = intval($_GET['school_id'] ?? 0);
$classId = intval($_GET['class_id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

// قائمة المدارس للاختيار
$allSchools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

if (!$schoolId):
    ?>
    <div class="container mx-auto px-4 py-8">
        <div class="page-hero" style="margin-bottom:2rem;">
            <h1>اختر المدرسة أولاً</h1>
            <div class="hero-sub">اختر مدرسة لعرض شعبها وتحليل أدائها</div>
        </div>
        <div class="school-picker">
            <div class="grid-cards">
                <?php foreach ($allSchools as $s): ?>
                <a href="?school_id=<?= $s['id'] ?>" class="pick-card"><h3><?= e($s['name_ar']) ?></h3></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/../../includes/footer.php'; exit; ?>
<?php endif;

// جلب بيانات المدرسة
$schoolStmt = $db->prepare("SELECT s.* FROM schools s WHERE s.id = ? AND s.is_active = 1");
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch();
if (!$school) {
    setFlash('المدرسة غير موجودة أو غير نشطة', 'danger');
    redirect('../group_admin/index.php');
}

// جلب شعب هذه المدرسة
$allClassesForSchool = $db->prepare("
    SELECT c.id, c.name_ar, gl.name_ar AS grade_name 
    FROM classes c 
    JOIN grade_levels gl ON c.grade_level_id = gl.id 
    WHERE c.school_id = ? AND c.is_active = 1 
    ORDER BY gl.level_number, c.name_ar
");
$allClassesForSchool->execute([$schoolId]);
$allClassesForSchool = $allClassesForSchool->fetchAll();

if (!$classId):
    ?>
    <div class="container mx-auto px-4 py-8">
        <div class="page-hero" style="margin-bottom:2rem;">
            <div class="hero-flex">
                <div>
                    <h1>اختر الشعبة</h1>
                    <div class="hero-sub">المدرسة: <?= e($school['name_ar']) ?></div>
                </div>
                <a href="?" class="back-btn">← تغيير المدرسة</a>
            </div>
        </div>
        <div class="class-picker">
            <div class="grid-cards">
                <?php foreach ($allClassesForSchool as $c): ?>
                <a href="?school_id=<?= $schoolId ?>&class_id=<?= $c['id'] ?>" class="pick-card">
                    <h3><?= e($c['name_ar']) ?></h3>
                    <div class="meta"><?= e($c['grade_name']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/../../includes/footer.php'; exit; ?>
<?php endif;

// جلب بيانات الشعبة
$classStmt = $db->prepare("
    SELECT c.*, gl.name_ar AS grade_name, gl.level_number, 
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1) AS enrolled_count
    FROM classes c
    JOIN grade_levels gl ON c.grade_level_id = gl.id
    WHERE c.id = ? AND c.school_id = ? AND c.is_active = 1
");
$classStmt->execute([$classId, $schoolId]);
$class = $classStmt->fetch();
if (!$class) {
    setFlash('الشعبة غير موجودة أو غير نشطة', 'danger');
    redirect("school.php?school_id=$schoolId");
}

// ===== معالجة فلاتر العام والفصل =====
require_once __DIR__ . '/../../includes/filter_helpers.php';
list($selectedAcademicYearId, $selectedSemesterId, $allAcademicYears, $allSemesters, $_) = processAcademicFilters(
    $db, $selectedAcademicYearId, $selectedSemesterId, false
);

// الحصول على اسم الفصل المحدد
$semNameStmt = $db->prepare("SELECT name_ar FROM semesters WHERE id = ?");
$semNameStmt->execute([$selectedSemesterId]);
$selectedSemesterName = $semNameStmt->fetchColumn();

// استعلامات البيانات للشعبة

// 1. عدد الطلاب المسجلين في الشعبة (مكرر أعلاه لكن نعيده للاستخدام)
$enrolledCount = $class['enrolled_count'];

// 2. متوسط scores per subject في الشعبة للفصل المحدد (أو جميع الفصول/السنوات)
if ($selectedSemesterId > 0) {
    $subjectPerformanceStmt = $db->prepare("
        SELECT sub.name_ar as subject_name, 
               AVG(sss.percentage) as avg_percentage, 
               COUNT(*) as student_count,
               MIN(sss.percentage) as min_percentage,
               MAX(sss.percentage) as max_percentage
        FROM student_subject_summary sss
        JOIN subjects sub ON sss.subject_id = sub.id
        WHERE sss.class_id = ? 
          AND sss.semester_id = ?
          AND sss.is_active = 1
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_percentage DESC
    ");
    $subjectPerformanceStmt->execute([$classId, $selectedSemesterId]);
} elseif ($selectedAcademicYearId > 0) {
    $subjectPerformanceStmt = $db->prepare("
        SELECT sub.name_ar as subject_name, 
               AVG(sss.percentage) as avg_percentage, 
               COUNT(*) as student_count,
               MIN(sss.percentage) as min_percentage,
               MAX(sss.percentage) as max_percentage
        FROM student_subject_summary sss
        JOIN subjects sub ON sss.subject_id = sub.id
        WHERE sss.class_id = ? 
          AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)
          AND sss.is_active = 1
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_percentage DESC
    ");
    $subjectPerformanceStmt->execute([$classId, $selectedAcademicYearId]);
} else {
    $subjectPerformanceStmt = $db->prepare("
        SELECT sub.name_ar as subject_name, 
               AVG(sss.percentage) as avg_percentage, 
               COUNT(*) as student_count,
               MIN(sss.percentage) as min_percentage,
               MAX(sss.percentage) as max_percentage
        FROM student_subject_summary sss
        JOIN subjects sub ON sss.subject_id = sub.id
        WHERE sss.class_id = ?
          AND sss.is_active = 1
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_percentage DESC
    ");
    $subjectPerformanceStmt->execute([$classId]);
}
$subjectPerformance = $subjectPerformanceStmt->fetchAll();

// 3. توزيع الدرجات (النسب المئوية) للطلاب في الشعبة - histogram-like
if ($selectedSemesterId > 0) {
    $gradeDistributionStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN percentage < 50 THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN percentage >= 50 AND percentage < 60 THEN 1 ELSE 0 END) as poor,
            SUM(CASE WHEN percentage >= 60 AND percentage < 70 THEN 1 ELSE 0 END) as fair,
            SUM(CASE WHEN percentage >= 70 AND percentage < 80 THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN percentage >= 80 THEN 1 ELSE 0 END) as excellent,
            COUNT(*) as total
        FROM student_subject_summary
        WHERE class_id = ? 
          AND semester_id = ?
          AND is_active = 1
    ");
    $gradeDistributionStmt->execute([$classId, $selectedSemesterId]);
} elseif ($selectedAcademicYearId > 0) {
    $gradeDistributionStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN percentage < 50 THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN percentage >= 50 AND percentage < 60 THEN 1 ELSE 0 END) as poor,
            SUM(CASE WHEN percentage >= 60 AND percentage < 70 THEN 1 ELSE 0 END) as fair,
            SUM(CASE WHEN percentage >= 70 AND percentage < 80 THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN percentage >= 80 THEN 1 ELSE 0 END) as excellent,
            COUNT(*) as total
        FROM student_subject_summary
        WHERE class_id = ? 
          AND semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)
          AND is_active = 1
    ");
    $gradeDistributionStmt->execute([$classId, $selectedAcademicYearId]);
} else {
    $gradeDistributionStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN percentage < 50 THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN percentage >= 50 AND percentage < 60 THEN 1 ELSE 0 END) as poor,
            SUM(CASE WHEN percentage >= 60 AND percentage < 70 THEN 1 ELSE 0 END) as fair,
            SUM(CASE WHEN percentage >= 70 AND percentage < 80 THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN percentage >= 80 THEN 1 ELSE 0 END) as excellent,
            COUNT(*) as total
        FROM student_subject_summary
        WHERE class_id = ?
          AND is_active = 1
    ");
    $gradeDistributionStmt->execute([$classId]);
}
$gradeDistribution = $gradeDistributionStmt->fetch();

// 4. قائمة الطلاب في الشعبة مع تفاصيلهم (للمراجعة)
if ($selectedSemesterId > 0) {
    $studentsStmt = $db->prepare("
        SELECT s.id, s.full_name_ar, s.student_number,
               sss.percentage, sss.letter_grade, sss.grade_point
        FROM students s
        JOIN student_subject_summary sss ON s.id = sss.student_id
        WHERE sss.class_id = ? 
          AND sss.semester_id = ?
          AND sss.is_active = 1
          AND s.is_active = 1
        ORDER BY sss.percentage DESC
    ");
    $studentsStmt->execute([$classId, $selectedSemesterId]);
} elseif ($selectedAcademicYearId > 0) {
    $studentsStmt = $db->prepare("
        SELECT s.id, s.full_name_ar, s.student_number,
               sss.percentage, sss.letter_grade, sss.grade_point
        FROM students s
        JOIN student_subject_summary sss ON s.id = sss.student_id
        WHERE sss.class_id = ? 
          AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)
          AND sss.is_active = 1
          AND s.is_active = 1
        ORDER BY sss.percentage DESC
    ");
    $studentsStmt->execute([$classId, $selectedAcademicYearId]);
} else {
    $studentsStmt = $db->prepare("
        SELECT s.id, s.full_name_ar, s.student_number,
               sss.percentage, sss.letter_grade, sss.grade_point
        FROM students s
        JOIN student_subject_summary sss ON s.id = sss.student_id
        WHERE sss.class_id = ?
          AND sss.is_active = 1
          AND s.is_active = 1
        ORDER BY sss.percentage DESC
    ");
    $studentsStmt->execute([$classId]);
}
$students = $studentsStmt->fetchAll();

// 5. اتجاه أداء الشعبة عبر الفصول (متوسط النسبة المئوية لكل فصل دراسي)
$trendStmt = $db->prepare("
    SELECT sem.name_ar AS semester_name, 
           AVG(sss.percentage) as avg_percentage,
           COUNT(*) as student_count
    FROM student_subject_summary sss
    JOIN semesters sem ON sss.semester_id = sem.id
    WHERE sss.class_id = ? 
      AND sss.is_active = 1
    GROUP BY sem.id, sem.name_ar
    ORDER BY sem.start_date
");
$trendStmt->execute([$classId]);
$trendData = $trendStmt->fetchAll();
?>

        <!-- Main Content -->
        <div class="container mx-auto px-4 py-8">

            <!-- Hero Header -->
            <div class="page-hero">
                <div class="hero-flex">
                    <div class="hero-flex-left">
                        <h1><?= e($class['name_ar']) ?></h1>
                        <div class="hero-sub">
                            <span>الصف: <?= e($class['grade_name']) ?></span>
                            <span class="hero-meta-sep">|</span>
                            <span><?= e($school['name_ar']) ?></span>
                            <span class="hero-meta-sep">|</span>
                            <span><?= e($school['governorate'] ?? '—') ?></span>
                        </div>
                    </div>
                    <a href="school.php?school_id=<?= $schoolId ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>" class="back-btn">← العودة للمدرسة</a>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-card">
                <form id="filterForm" method="GET" class="filter-row">
                    <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <div>
                        <label>العام الدراسي</label>
                        <select name="academic_year_id" onchange="document.getElementById('filterForm').submit();">
                            <?php foreach ($allAcademicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>" <?= (int)$ay['id'] === $selectedAcademicYearId ? 'selected' : '' ?>>
                                    <?= e($ay['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>الفصل الدراسي</label>
                        <select name="semester_id" onchange="document.getElementById('filterForm').submit();">
                            <?php foreach ($allSemesters as $sem): ?>
                                <option value="<?= $sem['id'] ?>" <?= (int)$sem['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                                    <?= e($sem['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="auto-hint">↵ التحديث التلقائي عند تغيير الفلتر</div>
                </form>
            </div>

            <!-- KPI Cards -->
            <?php
            $avgPercentage = $subjectPerformance ? array_reduce($subjectPerformance, function($carry, $item) {
                return $carry + $item['avg_percentage'];
            }, 0) / count($subjectPerformance) : 0;
            ?>
            <div class="kpi-stats-grid">
                <div class="stat-card stat-card-primary">
                    <div class="stat-icon" style="background:rgba(26,86,50,0.08);">📊</div>
                    <div class="stat-value"><?= number_format($avgPercentage, 1) ?>%</div>
                    <div class="stat-label">متوسط النسبة المئوية</div>
                </div>
                <div class="stat-card stat-card-info">
                    <div class="stat-icon" style="background:rgba(23,162,184,0.08);">👨‍🎓</div>
                    <div class="stat-value"><?= number_format($enrolledCount) ?></div>
                    <div class="stat-label">عدد الطلاب المسجلين</div>
                </div>
                <div class="stat-card stat-card-secondary">
                    <div class="stat-icon" style="background:rgba(200,169,81,0.08);">📚</div>
                    <div class="stat-value"><?= count($subjectPerformance) ?></div>
                    <div class="stat-label">عدد المواد</div>
                </div>
                <div class="stat-card stat-card-warning">
                    <div class="stat-icon" style="background:rgba(255,193,7,0.08);">🏫</div>
                    <div class="stat-value" style="font-size:1rem;"><?= e($school['name_ar']) ?></div>
                    <div class="stat-label">المدرسة</div>
                </div>
            </div>

            <!-- Grade Distribution -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📊 توزيع الدرجات</h3>
                    <?php if ($gradeDistribution['total'] > 0): ?>
                    <span class="header-meta">إجمالي: <?= number_format($gradeDistribution['total']) ?> درجة</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($gradeDistribution['total'] == 0): ?>
                        <div class="empty-state">لا توجد بيانات درجات متاحة.</div>
                    <?php else: 
                        $gdColors = ['var(--danger)', 'var(--warning)', 'var(--secondary)', 'var(--primary)', 'var(--info)'];
                        $gdLabels = ['راسب (<50%)', 'ضعيف (50-59)', 'مقبول (60-69)', 'جيد (70-79)', 'ممتاز (80-100)'];
                        $gdKeys = ['failed', 'poor', 'fair', 'good', 'excellent'];
                        $gdTotal = $gradeDistribution['total'];
                    ?>
                    <div class="dist-chart-wrap">
                        <div class="dist-chart-side"><canvas id="gradeDistChart"></canvas></div>
                        <div class="dist-labels">
                            <?php foreach ($gdKeys as $i => $key): 
                                $gdpct = $gdTotal > 0 ? round($gradeDistribution[$key] / $gdTotal * 100, 1) : 0;
                            ?>
                            <div class="dist-label-row">
                                <span class="dist-dot" style="background:<?= $gdColors[$i] ?>;"></span>
                                <span class="dist-name"><?= $gdLabels[$i] ?></span>
                                <span class="dist-num"><?= number_format($gradeDistribution[$key]) ?></span>
                                <span class="dist-pct-sm"><?= $gdpct ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subject Performance -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📚 متوسط أداء المواد</h3>
                    <?php if (!empty($subjectPerformance)): ?>
                    <span class="header-meta">مرتبة تنازلياً</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($subjectPerformance)): ?>
                        <div class="empty-state">لا توجد بيانات مواد متاحة.</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المادة</th>
                                    <th>متوسط النسبة</th>
                                    <th>الحد الأدنى</th>
                                    <th>الحد الأقصى</th>
                                    <th>عدد الطلاب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($subjectPerformance as $subject): 
                                    $avg = (float)$subject['avg_percentage'];
                                    $barColor = $avg >= 80 ? 'var(--success)' : ($avg >= 60 ? 'var(--warning)' : 'var(--danger)');
                                ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><strong><?= e($subject['subject_name']) ?></strong></td>
                                        <td>
                                            <div class="table-bar">
                                                <span class="bar-label" style="color:<?= $barColor ?>;"><?= number_format($avg, 1) ?>%</span>
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width:<?= min($avg, 100) ?>%;background:<?= $barColor ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= number_format($subject['min_percentage'], 1) ?>%</td>
                                        <td><?= number_format($subject['max_percentage'], 1) ?>%</td>
                                        <td><?= number_format($subject['student_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Trend Over Semesters -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📈 اتجاه أداء الشعبة عبر الفصول</h3>
                    <?php if (!empty($trendData)): ?>
                    <span class="header-meta"><?= count($trendData) ?> فصول</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($trendData)): ?>
                        <div class="empty-state">لا توجد بيانات اتجاه متاحة.</div>
                    <?php else: 
                        $trendLabels = array_map(function($d) { return $d['semester_name']; }, $trendData);
                        $trendValues = array_map(function($d) { return (float)$d['avg_percentage']; }, $trendData);
                    ?>
                    <div class="trend-chart-wrap">
                        <canvas id="classTrendChart"></canvas>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الفصل الدراسي</th>
                                    <th>متوسط النسبة</th>
                                    <th>عدد الطلاب</th>
                                    <th>الاتجاه</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $prevAvg = null;
                                $counter = 1;
                                foreach ($trendData as $data): 
                                    $diff = null;
                                    $trendClass = 'trend-badge-neutral';
                                    $trendIcon = '→';
                                    $trendText = 'الأول';
                                    if ($prevAvg !== null) {
                                        $diff = (float)$data['avg_percentage'] - $prevAvg;
                                        if ($diff > 0) {
                                            $trendClass = 'trend-badge-up';
                                            $trendIcon = '▲';
                                            $trendText = '+' . number_format($diff, 1);
                                        } elseif ($diff < 0) {
                                            $trendClass = 'trend-badge-down';
                                            $trendIcon = '▼';
                                            $trendText = number_format($diff, 1);
                                        } else {
                                            $trendClass = 'trend-badge-neutral';
                                            $trendIcon = '→';
                                            $trendText = 'مستقر';
                                        }
                                    }
                                    $prevAvg = (float)$data['avg_percentage'];
                                ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><?= e($data['semester_name']) ?></td>
                                        <td><strong><?= number_format($data['avg_percentage'], 1) ?>%</strong></td>
                                        <td><?= number_format($data['student_count']) ?></td>
                                        <td><span class="trend-badge <?= $trendClass ?>"><?= $trendIcon ?> <?= $trendText ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Student List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">👥 قائمة الطلاب في الشعبة</h3>
                    <?php if (!empty($students)): ?>
                    <span class="header-meta"><?= count($students) ?> طالب</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($students)): ?>
                        <div class="empty-state">لا توجد طلاب مسجلين في الشعبة.</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>اسم الطالب</th>
                                    <th>رقم الطالب</th>
                                    <th>النسبة المئوية</th>
                                    <th>التقدير</th>
                                    <th>نقطة التقدير</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($students as $student): 
                                    $pct = (float)$student['percentage'];
                                    $rowClass = $pct >= 80 ? 'row-high' : ($pct >= 60 ? '' : ($pct > 0 ? 'row-low' : ''));
                                    $rankClass = $counter === 1 ? 'rank-gold' : ($counter === 2 ? 'rank-silver' : ($counter === 3 ? 'rank-bronze' : 'rank-plain'));
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td><span class="rank-num <?= $rankClass ?>"><?= $counter <= 3 ? ['🥇','','🥉'][$counter-1] : $counter ?></span></td>
                                        <td><?= e($student['full_name_ar']) ?></td>
                                        <td style="font-family:monospace;"><?= e($student['student_number']) ?></td>
                                        <td><strong><?= number_format($pct, 1) ?>%</strong></td>
                                        <td><?php
                                            $grade = $student['letter_grade'] ?? '';
                                            $gradeColor = 'var(--text-secondary)';
                                            if ($grade === 'A' || $grade === 'A+') $gradeColor = 'var(--success)';
                                            elseif ($grade === 'B' || $grade === 'B+') $gradeColor = 'var(--primary)';
                                            elseif ($grade === 'C' || $grade === 'C+') $gradeColor = 'var(--warning)';
                                            elseif ($grade === 'D' || $grade === 'D+') $gradeColor = 'var(--danger)';
                                            elseif ($grade === 'F') $gradeColor = 'var(--accent)';
                                            echo $grade ? '<span style="color:' . $gradeColor . ';font-weight:700;">' . e($grade) . '</span>' : '—';
                                        ?></td>
                                        <td><?= number_format($student['grade_point'], 2) ?></td>
                                    </tr>
                                <?php $counter++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            <?php if (!empty($trendData)): ?>
            (function() {
                var labels = <?= json_encode($trendLabels) ?>;
                var values = <?= json_encode($trendValues) ?>;
                function drawChart() {
                    if (typeof eqasLineChart === 'function' && document.getElementById('classTrendChart')) {
                        eqasLineChart('classTrendChart', 'اتجاه الأداء', labels, [
                            { label: 'متوسط النسبة', data: values, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--success').trim() || '#28a745', fill: true, fillColor: 'rgba(40,167,69,0.1)', pointRadius: 5 }
                        ], {
                            scales: {
                                y: { min: 0, max: 100, ticks: { stepSize: 20 } }
                            }
                        });
                    } else {
                        setTimeout(drawChart, 500);
                    }
                }
                drawChart();
            })();
            <?php endif; ?>
        </script>

        <!-- Footer -->
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>