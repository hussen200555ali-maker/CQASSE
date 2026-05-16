<?php
/**
 * ============================================================
 * تحليل أداء المادة — صفحة موحدة احترافية
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تحليل المادة';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => 'index.php',
    'تحليل المادة' => null
];
$pageCSS = 'analytics.css';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filter_helpers.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// === جلب المعلمات ===
$schoolId = intval($_GET['school_id'] ?? 0);
$subjectId = intval($_GET['subject_id'] ?? 0);
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

// === معالجة فلاتر العام والفصل ===
list($selectedAcademicYearId, $selectedSemesterId, $allAcademicYears, $allSemesters, $selectedSemesterName) = processAcademicFilters(
    $db, $selectedAcademicYearId, $selectedSemesterId
);

// === جلب المدارس مع عدد المواد ===
$schoolsStmt = $db->query("
    SELECT s.id, s.name_ar, s.reference_code, s.governorate, s.school_type,
           COUNT(DISTINCT sub.id) AS subject_count
    FROM schools s
    LEFT JOIN classes c ON s.id = c.school_id AND c.is_active = 1
    LEFT JOIN subject_grade_years sgy ON c.grade_level_id = sgy.grade_level_id
    LEFT JOIN subjects sub ON sgy.subject_id = sub.id AND sub.is_active = 1
    WHERE s.is_active = 1
    GROUP BY s.id, s.name_ar, s.reference_code, s.governorate, s.school_type
    ORDER BY s.name_ar
");
$allSchools = $schoolsStmt->fetchAll();

// === بيانات المدرسة المحددة ===
$school = null;
if ($schoolId > 0) {
    $schoolStmt = $db->prepare("SELECT id, name_ar, reference_code, governorate, school_type FROM schools WHERE id = ? AND is_active = 1");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        setFlash('المدرسة غير موجودة أو غير نشطة', 'danger');
        redirect('index.php');
    }
}

// === جلب المواد مع الإحصائيات (عند اختيار مدرسة) ===
$allSubjects = [];
$subjectLimit = isset($_GET['show_all_subjects']) ? 9999 : 10;
$hasMoreSubjects = false;

if ($schoolId > 0) {
    // بناء شرط الفلتر للفصل/العام
    $semesterCondition = '';
    $params = [$schoolId];

    if ($selectedSemesterId > 0) {
        $semesterCondition = 'AND sss.semester_id = ?';
        $params[] = $selectedSemesterId;
    } elseif ($selectedAcademicYearId > 0) {
        $semesterCondition = 'AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ?)';
        $params[] = $selectedAcademicYearId;
    }

    $subjectsSql = "
        SELECT sub.id, sub.name_ar,
               COUNT(DISTINCT sss.student_id) AS student_count,
               COUNT(DISTINCT sss.class_id) AS class_count,
               COALESCE(AVG(sss.percentage), 0) AS avg_pct
        FROM subjects sub
        JOIN subject_grade_years sgy ON sub.id = sgy.subject_id
        JOIN classes c ON sgy.grade_level_id = c.grade_level_id AND c.school_id = ? AND c.is_active = 1
        LEFT JOIN student_subject_summary sss ON sub.id = sss.subject_id AND c.id = sss.class_id AND sss.is_active = 1 $semesterCondition
        WHERE sub.is_active = 1
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_pct DESC, sub.name_ar
    ";

    // نجلب عدد + 1 لمعرفة هل هناك المزيد
    $allSubjectsStmt = $db->prepare($subjectsSql);
    $allSubjectsStmt->execute($params);
    $allSubjectsRaw = $allSubjectsStmt->fetchAll();

    if (count($allSubjectsRaw) > $subjectLimit) {
        $hasMoreSubjects = true;
        $allSubjects = array_slice($allSubjectsRaw, 0, $subjectLimit);
    } else {
        $allSubjects = $allSubjectsRaw;
    }
} elseif ($subjectId > 0) {
    // عند اختيار مادة بدون مدرسة — نجلب المادة المحددة فقط لعرضها في القائمة
    $allSubjectsStmt = $db->prepare("SELECT id, name_ar FROM subjects WHERE id = ? AND is_active = 1");
    $allSubjectsStmt->execute([$subjectId]);
    $allSubjects = $allSubjectsStmt->fetchAll();
}

// === بيانات المادة المحددة ===
$subject = null;
if ($subjectId > 0) {
    $subjectStmt = $db->prepare("SELECT id, name_ar FROM subjects WHERE id = ? AND is_active = 1");
    $subjectStmt->execute([$subjectId]);
    $subject = $subjectStmt->fetch();
    if (!$subject) {
        setFlash('المادة غير موجودة', 'danger');
        redirect("?school_id=$schoolId");
    }
}

// === دوال مساعدة ===
$schoolTypeLabels = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
$schoolTypeIcons = ['basic' => '🏫', 'secondary' => '🏛️', 'combined' => '🏬'];

function getPerfBadge($avgPct) {
    if ($avgPct >= 75) return ['class' => 'perf-badge-excellent', 'label' => 'ممتاز', 'color' => 'var(--success)'];
    if ($avgPct >= 50) return ['class' => 'perf-badge-fair', 'label' => 'متوسط', 'color' => 'var(--warning)'];
    return ['class' => 'perf-badge-poor', 'label' => 'ضعيف', 'color' => 'var(--danger)'];
}

// === التحليل عبر المدارس (يظهر عند اختيار مادة) ===
$crossSchoolData = [];
$crossTotalStudents = 0;
$crossOverallAvg = 0;
$crossBestSchool = null;
$crossWorstSchool = null;
$crossTotalExcellent = 0;
$crossTotalGood = 0;
$crossTotalFair = 0;
$crossTotalPoor = 0;
$crossTotalFailed = 0;

if ($subjectId > 0) {
    $crossSql = "
        SELECT 
            s.id AS school_id, s.name_ar AS school_name, s.reference_code,
            COUNT(DISTINCT sss.student_id) AS student_count,
            COUNT(DISTINCT sss.class_id) AS class_count,
            ROUND(AVG(sss.percentage), 1) AS avg_pct,
            ROUND(MIN(sss.percentage), 1) AS min_pct,
            ROUND(MAX(sss.percentage), 1) AS max_pct,
            SUM(CASE WHEN sss.percentage >= 80 THEN 1 ELSE 0 END) AS excellent,
            SUM(CASE WHEN sss.percentage >= 70 AND sss.percentage < 80 THEN 1 ELSE 0 END) AS good,
            SUM(CASE WHEN sss.percentage >= 60 AND sss.percentage < 70 THEN 1 ELSE 0 END) AS fair,
            SUM(CASE WHEN sss.percentage >= 50 AND sss.percentage < 60 THEN 1 ELSE 0 END) AS poor,
            SUM(CASE WHEN sss.percentage < 50 THEN 1 ELSE 0 END) AS failed
        FROM subjects sub
        JOIN subject_grade_years sgy ON sub.id = sgy.subject_id
        JOIN classes c ON sgy.grade_level_id = c.grade_level_id AND c.is_active = 1
        JOIN student_subject_summary sss ON sub.id = sss.subject_id AND c.id = sss.class_id AND sss.is_active = 1
        JOIN schools s ON c.school_id = s.id AND s.is_active = 1
        WHERE sub.id = ? AND sub.is_active = 1";

    $crossParams = [$subjectId];

    if ($selectedSemesterId > 0) {
        $crossSql .= " AND sss.semester_id = ?";
        $crossParams[] = $selectedSemesterId;
    } elseif ($selectedAcademicYearId > 0) {
        $crossSql .= " AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)";
        $crossParams[] = $selectedAcademicYearId;
    }

    $crossSql .= " GROUP BY s.id, s.name_ar, s.reference_code ORDER BY avg_pct DESC";

    $crossStmt = $db->prepare($crossSql);
    $crossStmt->execute($crossParams);
    $crossSchoolData = $crossStmt->fetchAll();

    if (!empty($crossSchoolData)) {
        $crossTotalStudents = array_sum(array_column($crossSchoolData, 'student_count'));
        $crossWeightedSum = 0;
        foreach ($crossSchoolData as $row) {
            if ($row['student_count'] > 0) {
                $crossWeightedSum += $row['avg_pct'] * $row['student_count'];
            }
        }
        $crossOverallAvg = $crossTotalStudents > 0 ? round($crossWeightedSum / $crossTotalStudents, 1) : 0;
        $crossBestSchool = $crossSchoolData[0];
        $crossWorstSchool = end($crossSchoolData);
        $crossTotalExcellent = array_sum(array_column($crossSchoolData, 'excellent'));
        $crossTotalGood = array_sum(array_column($crossSchoolData, 'good'));
        $crossTotalFair = array_sum(array_column($crossSchoolData, 'fair'));
        $crossTotalPoor = array_sum(array_column($crossSchoolData, 'poor'));
        $crossTotalFailed = array_sum(array_column($crossSchoolData, 'failed'));
    }
}

$showCrossSchool = ($subjectId > 0 && !empty($crossSchoolData));

// === تحليل المادة (فقط عند اختيار مدرسة + مادة) ===
$summary = null;
$classData = [];
$trendData = [];
$totalStudents = 0;
$avgPct = 0;

if ($schoolId > 0 && $subjectId > 0) {
    // ملخص الأداء
    if ($selectedSemesterId > 0) {
        $summaryStmt = $db->prepare("
            SELECT 
                AVG(sss.percentage) AS avg_pct,
                MIN(sss.percentage) AS min_pct,
                MAX(sss.percentage) AS max_pct,
                COUNT(DISTINCT sss.student_id) AS student_count,
                COUNT(DISTINCT sss.class_id) AS class_count,
                SUM(CASE WHEN sss.percentage < 50 THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN sss.percentage >= 50 AND sss.percentage < 60 THEN 1 ELSE 0 END) AS poor,
                SUM(CASE WHEN sss.percentage >= 60 AND sss.percentage < 70 THEN 1 ELSE 0 END) AS fair,
                SUM(CASE WHEN sss.percentage >= 70 AND sss.percentage < 80 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN sss.percentage >= 80 THEN 1 ELSE 0 END) AS excellent
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.semester_id = ? AND sss.is_active = 1
        ");
        $summaryStmt->execute([$schoolId, $subjectId, $selectedSemesterId]);
    } elseif ($selectedAcademicYearId > 0) {
        $summaryStmt = $db->prepare("
            SELECT 
                AVG(sss.percentage) AS avg_pct,
                MIN(sss.percentage) AS min_pct,
                MAX(sss.percentage) AS max_pct,
                COUNT(DISTINCT sss.student_id) AS student_count,
                COUNT(DISTINCT sss.class_id) AS class_count,
                SUM(CASE WHEN sss.percentage < 50 THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN sss.percentage >= 50 AND sss.percentage < 60 THEN 1 ELSE 0 END) AS poor,
                SUM(CASE WHEN sss.percentage >= 60 AND sss.percentage < 70 THEN 1 ELSE 0 END) AS fair,
                SUM(CASE WHEN sss.percentage >= 70 AND sss.percentage < 80 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN sss.percentage >= 80 THEN 1 ELSE 0 END) AS excellent
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1) AND sss.is_active = 1
        ");
        $summaryStmt->execute([$schoolId, $subjectId, $selectedAcademicYearId]);
    } else {
        $summaryStmt = $db->prepare("
            SELECT 
                AVG(sss.percentage) AS avg_pct,
                MIN(sss.percentage) AS min_pct,
                MAX(sss.percentage) AS max_pct,
                COUNT(DISTINCT sss.student_id) AS student_count,
                COUNT(DISTINCT sss.class_id) AS class_count,
                SUM(CASE WHEN sss.percentage < 50 THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN sss.percentage >= 50 AND sss.percentage < 60 THEN 1 ELSE 0 END) AS poor,
                SUM(CASE WHEN sss.percentage >= 60 AND sss.percentage < 70 THEN 1 ELSE 0 END) AS fair,
                SUM(CASE WHEN sss.percentage >= 70 AND sss.percentage < 80 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN sss.percentage >= 80 THEN 1 ELSE 0 END) AS excellent
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.is_active = 1
        ");
        $summaryStmt->execute([$schoolId, $subjectId]);
    }
    $summary = $summaryStmt->fetch();
    $totalStudents = $summary['student_count'] ?? 0;
    $avgPct = $summary['avg_pct'] ?? 0;

    // أداء الشعب
    if ($selectedSemesterId > 0) {
        $classStmt = $db->prepare("
            SELECT c.id, c.name_ar AS class_name, gl.name_ar AS grade_name,
                   AVG(sss.percentage) AS avg_pct,
                   COUNT(DISTINCT sss.student_id) AS student_count,
                   MIN(sss.percentage) AS min_pct,
                   MAX(sss.percentage) AS max_pct
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.semester_id = ? AND sss.is_active = 1
            GROUP BY c.id, c.name_ar, gl.name_ar
            ORDER BY avg_pct DESC
        ");
        $classStmt->execute([$schoolId, $subjectId, $selectedSemesterId]);
    } elseif ($selectedAcademicYearId > 0) {
        $classStmt = $db->prepare("
            SELECT c.id, c.name_ar AS class_name, gl.name_ar AS grade_name,
                   AVG(sss.percentage) AS avg_pct,
                   COUNT(DISTINCT sss.student_id) AS student_count,
                   MIN(sss.percentage) AS min_pct,
                   MAX(sss.percentage) AS max_pct
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1) AND sss.is_active = 1
            GROUP BY c.id, c.name_ar, gl.name_ar
            ORDER BY avg_pct DESC
        ");
        $classStmt->execute([$schoolId, $subjectId, $selectedAcademicYearId]);
    } else {
        $classStmt = $db->prepare("
            SELECT c.id, c.name_ar AS class_name, gl.name_ar AS grade_name,
                   AVG(sss.percentage) AS avg_pct,
                   COUNT(DISTINCT sss.student_id) AS student_count,
                   MIN(sss.percentage) AS min_pct,
                   MAX(sss.percentage) AS max_pct
            FROM student_subject_summary sss
            JOIN classes c ON sss.class_id = c.id
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            WHERE c.school_id = ? AND sss.subject_id = ? AND sss.is_active = 1
            GROUP BY c.id, c.name_ar, gl.name_ar
            ORDER BY avg_pct DESC
        ");
        $classStmt->execute([$schoolId, $subjectId]);
    }
    $classData = $classStmt->fetchAll();

    // الاتجاه الزمني
    $trendStmt = $db->prepare("
        SELECT sem.name_ar AS semester_name, sem.start_date,
               AVG(sss.percentage) AS avg_pct,
               COUNT(DISTINCT sss.student_id) AS student_count
        FROM student_subject_summary sss
        JOIN semesters sem ON sss.semester_id = sem.id
        JOIN classes c ON sss.class_id = c.id
        WHERE c.school_id = ? AND sss.subject_id = ? AND sss.is_active = 1
        GROUP BY sem.id, sem.name_ar, sem.start_date
        ORDER BY sem.start_date
    ");
    $trendStmt->execute([$schoolId, $subjectId]);
    $trendData = $trendStmt->fetchAll();
}

$showAnalysis = ($schoolId > 0 && $subjectId > 0 && $totalStudents > 0);
$showEmptyState = ($schoolId == 0 || $subjectId == 0);
$showNoCrossData = ($subjectId > 0 && empty($crossSchoolData));
?>

<div class="container mx-auto px-4 py-8">
    <!-- Hero Header -->
    <div class="page-hero">
        <div class="hero-flex">
            <div>
                <h1>
                    <?php if ($subject): ?>
                        <?= e($subject['name_ar']) ?>
                    <?php else: ?>
                        📖 تحليل المادة
                    <?php endif; ?>
                </h1>
                <div class="hero-sub">
                    <?php if ($subject): ?>
                        <?= e($selectedSemesterName) ?>
                        <?php if ($school): ?>
                            <span class="hero-meta-sep">|</span>
                            المدرسة: <?= e($school['name_ar']) ?>
                        <?php elseif ($showCrossSchool): ?>
                            <span class="hero-meta-sep">|</span>
                            <?= count($crossSchoolData) ?> مدرسة تدرس المادة
                        <?php endif; ?>
                    <?php elseif ($school): ?>
                        المدرسة: <?= e($school['name_ar']) ?>
                        <span class="hero-meta-sep">|</span>
                        اختر مادة لعرض التحليل
                    <?php else: ?>
                        اختر المدرسة والمادة لعرض تحليل شامل للأداء
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($schoolId > 0): ?>
                <a href="index.php" class="back-btn">← العودة للتحليلات</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-card">
        <form id="filterForm" method="GET" class="filter-row">
            <div class="filter-select-group">
                <label>المدرسة:</label>
                <select name="school_id" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="0">— اختر المدرسة —</option>
                    <?php foreach ($allSchools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $schoolId ? 'selected' : '' ?>>
                            <?= e($s['name_ar']) ?> (<?= $s['subject_count'] ?> مادة)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-select-group">
                <label>المادة:</label>
                <?php if ($schoolId > 0): ?>
                <select name="subject_id" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="0">— اختر المادة —</option>
                    <?php foreach ($allSubjects as $sub):
                        $badge = getPerfBadge($sub['avg_pct']);
                    ?>
                        <option value="<?= $sub['id'] ?>" <?= (int)$sub['id'] === $subjectId ? 'selected' : '' ?>>
                            <?= e($sub['name_ar']) ?> — <?= $sub['student_count'] ?> طالب | <?= $sub['class_count'] ?> شعبة | <?= number_format($sub['avg_pct'], 1) ?>%
                        </option>
                    <?php endforeach; ?>
                    <?php if ($hasMoreSubjects): ?>
                        <option value="" disabled>─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─</option>
                        <option value="0" <?= !isset($_GET['show_all_subjects']) ? '' : 'selected' ?>>... عرض <?= count($allSubjectsRaw) - $subjectLimit ?> مادة أخرى</option>
                    <?php endif; ?>
                </select>
                <?php if ($hasMoreSubjects && !isset($_GET['show_all_subjects'])): ?>
                    <a href="?school_id=<?= $schoolId ?>&show_all_subjects=1&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="show-all-link">عرض كل المواد (<?= count($allSubjectsRaw) ?>)</a>
                <?php endif; ?>
                <?php else: ?>
                <select name="subject_id" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="0">— اختر المادة —</option>
                    <?php
                    $allSubjectsGlobal = $db->query("SELECT id, name_ar FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
                    foreach ($allSubjectsGlobal as $sub):
                    ?>
                        <option value="<?= $sub['id'] ?>" <?= (int)$sub['id'] === $subjectId ? 'selected' : '' ?>>
                            <?= e($sub['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="filter-select-group">
                <label>العام الدراسي:</label>
                <select name="academic_year_id" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                    <?php foreach ($allAcademicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= (int)$ay['id'] === $selectedAcademicYearId ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-select-group">
                <label>الفصل الدراسي:</label>
                <select name="semester_id" class="filter-select" onchange="document.getElementById('filterForm').submit();">
                    <?php foreach ($allSemesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= (int)$sem['id'] === $selectedSemesterId ? 'selected' : '' ?>><?= e($sem['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="auto-hint">↵ التحديث التلقائي عند تغيير الفلتر</div>
        </form>
    </div>

    <!-- Empty State -->
    <?php if ($showEmptyState && !$showCrossSchool): ?>
        <div class="empty-analysis">
            <div class="empty-analysis-icon">📊</div>
            <h3>
                <?php if ($schoolId == 0 && $subjectId == 0): ?>
                    اختر المادة لعرض التحليل عبر المدارس
                <?php elseif ($schoolId == 0): ?>
                    يتم عرض التحليل عبر المدارس أدناه
                <?php else: ?>
                    اختر المادة لعرض التحليل
                <?php endif; ?>
            </h3>
            <p>
                <?php if ($schoolId == 0 && $subjectId == 0): ?>
                    استخدم قائمة "المادة" أعلاه لاختيار المادة التي تريد تحليل أدائها عبر جميع المدارس.
                <?php elseif ($schoolId == 0): ?>
                    يتم عرض أداء المادة عبر جميع المدارس في القسم أدناه.
                    <br>اختر مدرسة من القائمة لعرض التحليل التفصيلي لهذه المدرسة.
                <?php else: ?>
                    استخدم قائمة "المادة" أعلاه لاختيار المادة التي تريد تحليل أدائها.
                    <br>تظهر الإحصائيات بجانب كل مادة لمساعدتك في الاختيار.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- No cross-school data -->
    <?php if ($showNoCrossData): ?>
        <div class="empty-analysis">
            <div class="empty-analysis-icon">📭</div>
            <h3>لا توجد بيانات كافية لهذه المادة عبر المدارس</h3>
            <p>لا توجد نتائج مسجلة لهذه المادة في الفترة المحددة. تأكد من اختيار فصل دراسي يحتوي على بيانات.</p>
        </div>
    <?php endif; ?>

    <!-- Cross-School Analysis Section -->
    <?php if ($showCrossSchool): ?>
        <!-- KPIs -->
        <div class="kpi-stats-grid">
            <div class="stat-card stat-card-primary">
                <div class="stat-icon">🏫</div>
                <div class="stat-value"><?= count($crossSchoolData) ?></div>
                <div class="stat-label">مدرسة تدرس المادة</div>
            </div>
            <div class="stat-card stat-card-info">
                <div class="stat-icon">👨‍🎓</div>
                <div class="stat-value"><?= number_format($crossTotalStudents) ?></div>
                <div class="stat-label">إجمالي الطلاب</div>
            </div>
            <div class="stat-card stat-card-success">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?= number_format($crossOverallAvg, 1) ?>%</div>
                <div class="stat-label">المتوسط العام</div>
            </div>
            <div class="stat-card <?= $crossBestSchool && $crossBestSchool['avg_pct'] >= 75 ? 'stat-card-success' : ($crossBestSchool && $crossBestSchool['avg_pct'] >= 50 ? 'stat-card-warning' : 'stat-card-danger') ?>">
                <div class="stat-icon">🏆 / 📉</div>
                <div class="stat-value">
                    <?php if ($crossBestSchool): ?>
                        <?= number_format($crossBestSchool['avg_pct'], 1) ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="stat-label">
                    <?php if ($crossBestSchool && $crossWorstSchool): ?>
                        أفضل: <?= e($crossBestSchool['school_name']) ?> | أضعف: <?= e($crossWorstSchool['school_name']) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Grade Distribution Summary -->
        <?php if ($crossTotalStudents > 0): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 التوزيع الكلي للدرجات عبر المدارس</h3>
            </div>
            <div class="card-body">
                <div class="grade-dist-summary">
                    <?php
                    $crossDistBuckets = [
                        ['label' => 'ممتاز (80+)', 'count' => $crossTotalExcellent, 'color' => 'var(--info)'],
                        ['label' => 'جيد (70-79)', 'count' => $crossTotalGood, 'color' => 'var(--primary)'],
                        ['label' => 'مقبول (60-69)', 'count' => $crossTotalFair, 'color' => 'var(--secondary)'],
                        ['label' => 'ضعيف (50-59)', 'count' => $crossTotalPoor, 'color' => 'var(--warning)'],
                        ['label' => 'راسب (<50)', 'count' => $crossTotalFailed, 'color' => 'var(--danger)'],
                    ];
                    ?>
                    <?php foreach ($crossDistBuckets as $dbkt): ?>
                    <?php $pct = $crossTotalStudents > 0 ? number_format($dbkt['count']/$crossTotalStudents*100,1) : 0; ?>
                    <span class="dist-segment" style="border-right: 3px solid <?= $dbkt['color'] ?>;">
                        <?= $dbkt['label'] ?>: <strong><?= number_format($dbkt['count']) ?></strong> (<?= $pct ?>%)
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bar Chart -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 مقارنة أداء المدارس — <?= e($subject['name_ar']) ?></h3>
            </div>
            <div class="card-body">
                <div class="trend-chart-wrap">
                    <canvas id="crossSchoolChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Comparison Table -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 جدول مقارنة المدارس</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table cross-school-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المدرسة</th>
                                <th>الطلاب</th>
                                <th>الشعب</th>
                                <th>المتوسط</th>
                                <th>أدنى</th>
                                <th>أعلى</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($crossSchoolData as $cs): ?>
                            <?php $badge = getPerfBadge($cs['avg_pct']); ?>
                            <tr>
                                <td><?= $counter++ ?></td>
                                <td>
                                    <?= e($cs['school_name']) ?>
                                    <span class="perf-badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                </td>
                                <td><?= number_format($cs['student_count']) ?></td>
                                <td><?= number_format($cs['class_count']) ?></td>
                                <td>
                                    <div class="table-bar">
                                        <div class="bar-track">
                                            <div class="bar-fill" style="width:<?= $cs['avg_pct'] ?>%;background:<?= $badge['color'] ?>;"></div>
                                        </div>
                                        <span class="bar-label"><?= number_format($cs['avg_pct'], 1) ?>%</span>
                                    </div>
                                </td>
                                <td><?= number_format($cs['min_pct'], 1) ?>%</td>
                                <td><?= number_format($cs['max_pct'], 1) ?>%</td>
                                <td>
                                    <a href="?school_id=<?= $cs['school_id'] ?>&subject_id=<?= $subjectId ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="btn btn-sm btn-light">تفاصيل</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- School Analysis Content (existing) -->
    <?php if ($showAnalysis): ?>
        <!-- Summary Metrics -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🏫 تحليل المدرسة: <?= e($school['name_ar']) ?> — <?= e($selectedSemesterName) ?></h3>
            </div>
            <div class="card-body">
                <div class="metrics-grid">
                    <div class="metric-box">
                        <div class="metric-value"><?= number_format($avgPct, 1) ?>%</div>
                        <div class="metric-label">متوسط النسبة</div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-value"><?= number_format($summary['min_pct'], 1) ?>%</div>
                        <div class="metric-label">الحد الأدنى</div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-value"><?= number_format($summary['max_pct'], 1) ?>%</div>
                        <div class="metric-label">الحد الأقصى</div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-value"><?= number_format($totalStudents) ?></div>
                        <div class="metric-label">عدد الطلاب</div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-value"><?= number_format($summary['class_count']) ?></div>
                        <div class="metric-label">عدد الشعب</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grade Distribution -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 توزيع الدرجات</h3>
            </div>
            <div class="card-body">
                <?php
                $buckets = [
                    ['label' => 'راسب (<50)', 'pct' => $summary['failed'], 'color' => 'var(--danger)'],
                    ['label' => 'ضعيف (50-59)', 'pct' => $summary['poor'], 'color' => 'var(--warning)'],
                    ['label' => 'مقبول (60-69)', 'pct' => $summary['fair'], 'color' => 'var(--secondary)'],
                    ['label' => 'جيد (70-79)', 'pct' => $summary['good'], 'color' => 'var(--primary)'],
                    ['label' => 'ممتاز (80+)', 'pct' => $summary['excellent'], 'color' => 'var(--info)'],
                ];
                ?>
                <div class="dist-chart-wrap">
                    <div class="dist-chart-side">
                        <canvas id="gradeDistChart"></canvas>
                    </div>
                    <div class="dist-labels">
                        <?php foreach ($buckets as $b): ?>
                        <?php $pct = $totalStudents > 0 ? number_format($b['pct']/$totalStudents*100,1) : 0; ?>
                        <div class="dist-label-row">
                            <span class="dist-dot" style="background:<?= $b['color'] ?>;"></span>
                            <span class="dist-name"><?= $b['label'] ?></span>
                            <span class="dist-num"><?= $b['pct'] ?></span>
                            <span class="dist-pct-sm"><?= $pct ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Class Performance -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🏫 أداء المادة حسب الشعب</h3>
            </div>
            <div class="card-body">
                <?php if (empty($classData)): ?>
                    <p class="text-gray-500">لا توجد شعب متاحة لهذه المادة في الفصل المحدد.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الشعبة</th>
                                    <th>الصف</th>
                                    <th>المتوسط</th>
                                    <th>الحد الأدنى</th>
                                    <th>الحد الأقصى</th>
                                    <th>الطلاب</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($classData as $cd): ?>
                                <?php
                                $barColor = $cd['avg_pct'] >= 80 ? 'var(--success)' : ($cd['avg_pct'] >= 60 ? 'var(--primary)' : ($cd['avg_pct'] >= 50 ? 'var(--warning)' : 'var(--danger)'));
                                ?>
                                <tr>
                                    <td><?= $counter++ ?></td>
                                    <td><?= e($cd['class_name']) ?></td>
                                    <td><?= e($cd['grade_name']) ?></td>
                                    <td>
                                        <div class="table-bar">
                                            <div class="bar-track"><div class="bar-fill" style="width:<?= $cd['avg_pct'] ?>%;background:<?= $barColor ?>;"></div></div>
                                            <span class="bar-label"><?= number_format($cd['avg_pct'], 1) ?>%</span>
                                        </div>
                                    </td>
                                    <td><?= number_format($cd['min_pct'], 1) ?>%</td>
                                    <td><?= number_format($cd['max_pct'], 1) ?>%</td>
                                    <td><?= number_format($cd['student_count']) ?></td>
                                    <td><a href="class.php?school_id=<?= $schoolId ?>&class_id=<?= $cd['id'] ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>" class="btn btn-sm btn-light">تحليل</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Trend -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📈 اتجاه أداء المادة عبر الفصول</h3>
            </div>
            <div class="card-body">
                <?php if (count($trendData) < 2): ?>
                    <p class="text-gray-500">بيانات غير كافية لعرض الاتجاه (يلزم فصلان دراسيان على الأقل).</p>
                <?php else: ?>
                    <div class="trend-chart-wrap">
                        <canvas id="subjectTrendChart"></canvas>
                    </div>
                    <div class="overflow-x-auto mt-4">
                        <table class="data-table">
                            <thead>
                                <tr><th>#</th><th>الفصل</th><th>المتوسط</th><th>الطلاب</th><th>الاتجاه</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $prev = null;
                                $c2 = 1;
                                foreach ($trendData as $d): 
                                    $trend = '—';
                                    $tClass = 'trend-neutral';
                                    if ($prev !== null) {
                                        $diff = $d['avg_pct'] - $prev;
                                        if ($diff > 2) { $trend = '▲ ' . number_format($diff, 1); $tClass = 'trend-up'; }
                                        elseif ($diff < -2) { $trend = '▼ ' . number_format(abs($diff), 1); $tClass = 'trend-down'; }
                                        else { $trend = '→ مستقر'; }
                                    }
                                    $prev = $d['avg_pct'];
                                ?>
                                <tr>
                                    <td><?= $c2++ ?></td>
                                    <td><?= e($d['semester_name']) ?></td>
                                    <td><?= number_format($d['avg_pct'], 1) ?>%</td>
                                    <td><?= number_format($d['student_count']) ?></td>
                                    <td><span class="<?= $tClass ?>"><?= $trend ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($schoolId > 0 && $subjectId > 0 && !$showAnalysis): ?>
        <!-- No data for selected subject -->
        <div class="empty-analysis">
            <div class="empty-analysis-icon">📭</div>
            <h3>لا توجد بيانات أداء لهذه المادة</h3>
            <p>لا توجد نتائج مسجلة للمادة في الفترة المحددة. تأكد من اختيار فصل دراسي يحتوي على بيانات.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<?php if ($showCrossSchool || ($showAnalysis && (count($trendData) >= 2 || $totalStudents > 0))): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($showCrossSchool): ?>
    var crossSchoolLabels = <?= json_encode(array_column($crossSchoolData, 'school_name')) ?>;
    var crossSchoolValues = <?= json_encode(array_column($crossSchoolData, 'avg_pct')) ?>;
    var crossSchoolIds = <?= json_encode(array_column($crossSchoolData, 'school_id')) ?>;
    var crossSubjectId = <?= $subjectId ?>;
    var crossAcademicYearId = <?= $selectedAcademicYearId ?>;
    var crossSemesterId = <?= $selectedSemesterId ?>;

    var crossCtx = document.getElementById('crossSchoolChart');
    if (crossCtx) {
        new Chart(crossCtx, {
            type: 'bar',
            data: {
                labels: crossSchoolLabels,
                datasets: [{
                    label: 'متوسط الأداء %',
                    data: crossSchoolValues,
                    backgroundColor: crossSchoolValues.map(v => v >= 75 ? 'rgba(40,167,69,0.8)' : (v >= 50 ? 'rgba(255,193,7,0.8)' : 'rgba(220,53,69,0.8)')),
                    borderColor: crossSchoolValues.map(v => v >= 75 ? '#28a745' : (v >= 50 ? '#ffc107' : '#dc3545')),
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return ctx.parsed.x + '%'; },
                            afterLabel: function(ctx) {
                                var idx = ctx.dataIndex;
                                var row = <?= json_encode($crossSchoolData) ?>[idx];
                                return 'طلاب: ' + row.student_count + ' | أدنى: ' + row.min_pct + '% | أعلى: ' + row.max_pct + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { callback: function(v) { return v + '%'; } } },
                    y: { grid: { display: false } }
                },
                onClick: function(evt, elements) {
                    if (elements.length > 0) {
                        var idx = elements[0].index;
                        var schoolId = crossSchoolIds[idx];
                        var params = new URLSearchParams({
                            school_id: schoolId,
                            subject_id: crossSubjectId,
                            academic_year_id: crossAcademicYearId,
                            semester_id: crossSemesterId
                        });
                        window.location.href = '?' + params.toString();
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($totalStudents > 0): ?>
    var distCtx = document.getElementById('gradeDistChart');
    if (distCtx) {
        new Chart(distCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach ($buckets as $b): ?>'<?= addslashes($b['label']) ?>',<?php endforeach; ?>],
                datasets: [{
                    data: [<?php foreach ($buckets as $b): ?><?= $b['pct'] ?>,<?php endforeach; ?>],
                    backgroundColor: [<?php foreach ($buckets as $b): ?>'<?= $b['color'] ?>',<?php endforeach; ?>],
                    borderWidth: 2,
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--surface').trim() || '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) { var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0); var pct = ((ctx.parsed / total) * 100).toFixed(1); return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)'; } } }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if (count($trendData) >= 2): ?>
    var trendCtx = document.getElementById('subjectTrendChart');
    if (trendCtx) {
        var labels = [<?php foreach ($trendData as $d): ?>'<?= addslashes($d['semester_name']) ?>',<?php endforeach; ?>];
        var values = [<?php foreach ($trendData as $d): ?><?= number_format($d['avg_pct'], 1) ?>,<?php endforeach; ?>];
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'متوسط النسبة %',
                    data: values,
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#1a5632',
                    backgroundColor: 'rgba(26,86,50,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) { return ctx.parsed.y + '%'; } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
<?php endif; ?>
