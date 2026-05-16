<?php
/**
 * ============================================================
 * مقارنة المدارس
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'مقارنة المدارس';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => 'index.php',
    'مقارنة المدارس' => null
];
$pageCSS = 'analytics.css';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

// التحقق من الصلاحيات - فقط مدير المجموعة
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// ===== معالجة الفلاتر (GET) =====
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$selectedSchoolIds = isset($_GET['school_ids']) && is_array($_GET['school_ids']) ? array_map('intval', $_GET['school_ids']) : [];
$selectedSubjectIds = isset($_GET['subject_ids']) && is_array($_GET['subject_ids']) ? array_map('intval', $_GET['subject_ids']) : [];

if (count($selectedSchoolIds) > 6) {
    $selectedSchoolIds = array_slice($selectedSchoolIds, 0, 6);
}

// قائمة المدارس للقائمة المنسدلة
$allSchools = $db->query("SELECT id, name_ar, reference_code FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// قائمة المواد للقائمة المنسدلة
$allSubjects = $db->query("SELECT id, name_ar FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

require_once __DIR__ . '/../../includes/filter_helpers.php';
list($selectedAcademicYearId, $selectedSemesterId, $allAcademicYears, $allSemesters, $selectedSemesterName) = processAcademicFilters(
    $db, $selectedAcademicYearId, $selectedSemesterId
);

// ===== استعلام البيانات للمدارس المختارة =====
$comparisonData = [];
$subjectComparisonData = [];
if (!empty($selectedSchoolIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedSchoolIds), '?'));
    $params = array_merge([$selectedSemesterId, $selectedSemesterId], $selectedSchoolIds);
    
    // الاستعلام الرئيسي
    $stmt = $db->prepare("
        SELECT 
            s.id AS school_id,
            s.name_ar AS school_name,
            s.reference_code,
            COALESCE(AVG(sss.percentage), 0) AS avg_percentage,
            COALESCE(eqi.overall_eqi, 0) AS eqi_score,
            COUNT(DISTINCT st.id) AS student_count,
            COUNT(DISTINCT u.id) AS teacher_count
        FROM schools s
        LEFT JOIN students st ON s.id = st.school_id AND st.is_active = 1
        LEFT JOIN users u ON s.id = u.school_id AND u.role_id = (SELECT id FROM roles WHERE name = 'teacher') AND u.is_active = 1
        LEFT JOIN student_subject_summary sss ON st.id = sss.student_id AND sss.semester_id = ?
        LEFT JOIN equi_results eqi ON s.id = eqi.school_id AND eqi.semester_id = ? AND eqi.is_active = 1
        WHERE s.id IN ($placeholders) AND s.is_active = 1
        GROUP BY s.id, s.name_ar, s.reference_code
    ");
    $stmt->execute($params);
    $comparisonData = $stmt->fetchAll();
    
    // التعديل السياقي لكل مدرسة
    $adjuster = new ContextualAdjustmentService($db);
    foreach ($comparisonData as &$school) {
        $raw = $school['eqi_score'] ?: 0;
        $adj = $adjuster->calculateAdjustedSQIWithDetails($school['school_id'], $selectedAcademicYearId, $raw);
        $school['adjusted_eqi'] = $adj['adjusted_sqi'];
        $school['challenge_index'] = $adj['challenge_index'];
        $school['has_context'] = $adj['has_context'];
    }
    unset($school);
    
    // مؤشرات حسب المادة (إذا اختيرت مواد)
    if (!empty($selectedSubjectIds)) {
        $subjPlaceholders = implode(',', array_fill(0, count($selectedSubjectIds), '?'));
        $subjStmt = $db->prepare("
            SELECT 
                s.id AS school_id, s.name_ar AS school_name,
                sub.id AS subject_id, sub.name_ar AS subject_name,
                AVG(sss.percentage) AS avg_pct,
                COUNT(DISTINCT sss.student_id) AS student_count
            FROM schools s
            JOIN student_subject_summary sss ON sss.semester_id = ?
            JOIN classes c ON sss.class_id = c.id AND c.school_id = s.id
            JOIN subjects sub ON sss.subject_id = sub.id
            WHERE s.id IN ($placeholders)
              AND sss.subject_id IN ($subjPlaceholders)
              AND sss.is_active = 1
              AND s.is_active = 1
            GROUP BY s.id, sub.id
            ORDER BY s.name_ar, sub.name_ar
        ");
        $subjParams = array_merge([$selectedSemesterId], $selectedSchoolIds, $selectedSubjectIds);
        $subjStmt->execute($subjParams);
        $subjectComparisonData = $subjStmt->fetchAll();
    }
}

// المتوسطات العامة
$overallAvgPercentage = $comparisonData ? array_reduce($comparisonData, function($c, $i) { return $c + $i['avg_percentage']; }, 0) / count($comparisonData) : 0;
$overallAvgEQI = $comparisonData ? array_reduce($comparisonData, function($c, $i) { return $c + $i['eqi_score']; }, 0) / count($comparisonData) : 0;
?>
<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="page-hero" style="margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <div class="hero-sub">مقارنة أداء المدارس عبر مؤشرات رئيسية ومؤشرات حسب المادة</div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="filter-card">
        <form method="GET" action="">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;align-items:end;">
                <div>
                    <label>العام الدراسي:</label>
                    <select name="academic_year_id" onchange="this.form.submit()">
                        <?php foreach ($allAcademicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= (int)$ay['id'] === $selectedAcademicYearId ? 'selected' : '' ?>>
                                <?= e($ay['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>الفصل الدراسي:</label>
                    <select name="semester_id" onchange="this.form.submit()">
                        <?php foreach ($allSemesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= (int)$sem['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                                <?= e($sem['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>المدارس (اختر حتى 6):</label>
                    <select name="school_ids[]" multiple style="height:150px;width:100%;border:1px solid var(--border);border-radius:var(--radius-md);padding:0.5rem;font-size:0.9rem;">
                        <?php foreach ($allSchools as $school): ?>
                            <option value="<?= $school['id'] ?>" <?= in_array($school['id'], $selectedSchoolIds) ? 'selected' : '' ?>>
                                <?= e($school['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>المواد (اختياري):</label>
                    <select name="subject_ids[]" multiple style="height:150px;width:100%;border:1px solid var(--border);border-radius:var(--radius-md);padding:0.5rem;font-size:0.9rem;">
                        <?php foreach ($allSubjects as $subj): ?>
                            <option value="<?= $subj['id'] ?>" <?= in_array($subj['id'], $selectedSubjectIds) ? 'selected' : '' ?>>
                                <?= e($subj['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;text-align:left;">
                <button type="submit" class="btn btn-primary">عرض المقارنة</button>
            </div>
        </form>
    </div>

    <!-- Results -->
    <?php if (!empty($selectedSchoolIds) && !empty($comparisonData)): ?>
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h3 class="card-title">🔍 نتائج المقارنة للفصل: <?= e($selectedSemesterName) ?></h3>
            </div>
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المدرسة</th>
                                <th>الرمز</th>
                                <th>المتوسط %</th>
                                <th>EQI</th>
                                <th>المعدّل سياقياً</th>
                                <th>التحدي</th>
                                <th>الطلاب</th>
                                <th>المعلمون</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($comparisonData as $school): ?>
                                <tr>
                                    <td><?= $counter++ ?></td>
                                    <td><a href="school.php?school_id=<?= $school['school_id'] ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>" style="color:var(--primary);font-weight:500;"><?= e($school['school_name']) ?></a></td>
                                    <td style="font-family:monospace;"><?= e($school['reference_code']) ?></td>
                                    <td><?= number_format($school['avg_percentage'], 1) ?>%</td>
                                    <td><?= number_format($school['eqi_score'], 1) ?></td>
                                    <td><?= $school['has_context'] ? number_format($school['adjusted_eqi'], 1) : '—' ?></td>
                                    <td><?= $school['has_context'] ? number_format($school['challenge_index']) . '%' : '—' ?></td>
                                    <td><?= number_format($school['student_count']) ?></td>
                                    <td><?= number_format($school['teacher_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Subject breakdown if subjects selected -->
                <?php if (!empty($selectedSubjectIds) && !empty($subjectComparisonData)): ?>
                    <div style="margin-top:1.5rem;">
                        <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.75rem;padding-bottom:0.5rem;border-bottom:2px solid var(--border);">مؤشرات حسب المادة</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>المدرسة</th>
                                        <?php 
                                        $shownSubjects = [];
                                        foreach ($subjectComparisonData as $sd) {
                                            if (!in_array($sd['subject_name'], $shownSubjects)) {
                                                $shownSubjects[] = $sd['subject_name'];
                                            }
                                        }
                                        ?>
                                        <?php foreach ($shownSubjects as $sname): ?>
                                            <th><?= e($sname) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedSchoolIds as $sid): 
                                        $schoolRow = array_filter($subjectComparisonData, function($d) use ($sid) { return (int)$d['school_id'] === $sid; });
                                        $schoolName = '';
                                        foreach ($allSchools as $as) { if ((int)$as['id'] === $sid) { $schoolName = $as['name_ar']; break; } }
                                    ?>
                                        <tr>
                                            <td><?= e($schoolName) ?></td>
                                            <?php foreach ($shownSubjects as $sname): 
                                                $match = null;
                                                foreach ($schoolRow as $r) { if ($r['subject_name'] === $sname) { $match = $r; break; } }
                                            ?>
                                                <td><?= $match ? number_format($match['avg_pct'], 1) . '%' : '—' ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 ملخص المجموعة</h3>
            </div>
            <div class="card-body">
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-value"><?= number_format($overallAvgPercentage, 1) ?>%</div>
                        <div class="summary-label">متوسط النسبة</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?= number_format($overallAvgEQI, 1) ?></div>
                        <div class="summary-label">متوسط EQI</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?= array_sum(array_column($comparisonData, 'student_count')) ?></div>
                        <div class="summary-label">إجمالي الطلاب</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?= array_sum(array_column($comparisonData, 'teacher_count')) ?></div>
                        <div class="summary-label">إجمالي المعلمين</div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif (!empty($selectedSchoolIds)): ?>
        <div class="card" style="border-right:4px solid var(--danger);">
            <div class="card-body">
                <p style="color:var(--danger);font-weight:500;">لا توجد بيانات متاحة للمدارس المختارة في الفصل المحدد.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Footer -->
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
    const schoolSelect = document.querySelector('select[name="school_ids[]"]');
    if (schoolSelect) {
        schoolSelect.addEventListener('change', function() {
            const selectedOptions = Array.from(this.selectedOptions);
            if (selectedOptions.length > 6) {
                const excess = selectedOptions.length - 6;
                for (let i = 0; i < excess; i++) {
                    const idx = this.options.length - 1 - i;
                    this.options[idx].selected = false;
                }
                alert('يمكنك اختيار ما يصل إلى 6 مدارس فقط.');
            }
        });
    }
</script>