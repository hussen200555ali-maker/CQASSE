<?php
/**
 * ============================================================
 * EQAS - ملخص الدرجات
 * نظام التقييم الشامل - ملخص درجات الطلاب بالمادة
 * ============================================================
 * يتضمن:
 * - اختيار الفصل والمادة والفصل الدراسي
 * - جدول يعرض: درجة كل نوع تقييم، المجموع المرجح، التقدير،
 *   نقطة التقدير، الترتيب، حالة النجاح/الرسوب
 * - تلوين الناجح/الراسب
 * - تصدير إلى إكسيل
 * - عرض للطباعة
 * - رسم بياني لتوزيع الدرجات
 * - عرض توزيع الأوزان
 * ============================================================
 */

// استخدام output buffering للسماح بالتصدير بعد تحميل القالب
ob_start();

$pageTitle = 'ملخص الدرجات';
$breadcrumb = [
    'التقييم الأكاديمي' => 'index.php',
    'ملخص الدرجات' => null
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/academic_calculator.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$calculator = new AcademicCalculator($db);
$academicYearId = getCurrentAcademicYearId($db);
$schoolId = $_SESSION['user']['school_id'] ?? 0;

// جلب بيانات الاختيارات
$classes = [];
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT c.id, c.name_ar, gl.name_ar AS grade_name, gl.id AS grade_level_id
        FROM classes c
        INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
        WHERE c.school_id = ? AND c.academic_year_id = ? AND c.is_active = 1
        ORDER BY gl.level_number, c.name_ar
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classes = $stmt->fetchAll();
}

$semesters = [];
$stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY id");
$stmt->execute([$academicYearId]);
$semesters = $stmt->fetchAll();

$subjects = $db->query("SELECT id, name_ar, code FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// قيم الاختيار
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedSubjectId = (int)($_GET['subject_id'] ?? 0);
$selectedSemesterId = (int)($_GET['semester_id'] ?? 0);

// ============================================================
// جلب البيانات
// ============================================================
$studentsData = [];
$weightDistribution = [];
$assessmentTypesData = [];
$classGradeLevelId = 0;

if ($selectedClassId > 0 && $selectedSubjectId > 0 && $selectedSemesterId > 0) {
    // جلب معرف المرحلة الدراسية
    $stmt = $db->prepare("SELECT grade_level_id FROM classes WHERE id = ?");
    $stmt->execute([$selectedClassId]);
    $classInfo = $stmt->fetch();
    $classGradeLevelId = $classInfo ? (int)$classInfo['grade_level_id'] : 0;

    // جلب طلاب الفصل
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.full_name_ar
        FROM student_enrollments se
        INNER JOIN students s ON s.id = se.student_id
        WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status = 'enrolled'
            AND se.is_active = 1 AND s.is_active = 1
        ORDER BY s.full_name_ar
    ");
    $stmt->execute([$selectedClassId, $academicYearId]);
    $students = $stmt->fetchAll();

    // جلب أنواع التقييم النشطة
    $assessmentTypesData = $db->query("
        SELECT id, name_ar, code FROM assessment_types WHERE is_active = 1 ORDER BY sort_order
    ")->fetchAll();

    // جلب توزيع الأوزان
    if ($classGradeLevelId > 0) {
        $stmt = $db->prepare("
            SELECT swd.weight, at.name_ar, at.code, at.id AS type_id
            FROM subject_weight_distribution swd
            INNER JOIN assessment_types at ON at.id = swd.assessment_type_id
            INNER JOIN subject_grade_years sgy ON sgy.id = swd.subject_grade_year_id
            WHERE sgy.subject_id = ? AND sgy.grade_level_id = ?
                AND swd.academic_year_id = ? AND swd.is_active = 1
            ORDER BY at.sort_order
        ");
        $stmt->execute([$selectedSubjectId, $classGradeLevelId, $academicYearId]);
        $weightDistribution = $stmt->fetchAll();
    }

    // جلب جميع اختبارات المادة في الفصل للفصل الدراسي المحدد
    $stmt = $db->prepare("
        SELECT t.id, t.title_ar, t.max_grade, t.assessment_type_id, at.code AS type_code, at.name_ar AS type_name
        FROM tests t
        INNER JOIN assessment_types at ON at.id = t.assessment_type_id
        WHERE t.class_id = ? AND t.subject_id = ? AND t.academic_year_id = ?
            AND t.semester_id = ? AND t.is_active = 1
        ORDER BY at.sort_order, t.test_date
    ");
    $stmt->execute([$selectedClassId, $selectedSubjectId, $academicYearId, $selectedSemesterId]);
    $tests = $stmt->fetchAll();

    // تجميع الاختبارات حسب نوع التقييم
    $testsByType = [];
    foreach ($tests as $test) {
        $typeCode = $test['type_code'];
        if (!isset($testsByType[$typeCode])) {
            $testsByType[$typeCode] = ['name' => $test['type_name'], 'tests' => [], 'total_max' => 0];
        }
        $testsByType[$typeCode]['tests'][] = $test;
        $testsByType[$typeCode]['total_max'] += $test['max_grade'];
    }

    // جلب جميع الدرجات دفعة واحدة
    $testIds = array_column($tests, 'id');
    $allGrades = [];
    if (!empty($testIds)) {
        $placeholders = implode(',', array_fill(0, count($testIds), '?'));
        $stmt = $db->prepare("
            SELECT g.student_id, g.test_id, g.grade_value, g.percentage, g.letter_grade, g.max_value
            FROM grades g
            WHERE g.test_id IN ({$placeholders}) AND g.is_active = 1
        ");
        $stmt->execute($testIds);
        while ($row = $stmt->fetch()) {
            $allGrades[$row['student_id']][$row['test_id']] = $row;
        }
    }

    // جلب ملخصات المواد
    $studentIds = array_column($students, 'id');
    $summaries = [];
    if (!empty($studentIds)) {
        $ph = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $db->prepare("
            SELECT sss.*
            FROM student_subject_summary sss
            WHERE sss.student_id IN ({$ph}) AND sss.subject_id = ?
                AND sss.class_id = ? AND sss.academic_year_id = ?
                AND sss.semester_id = ? AND sss.is_active = 1
        ");
        $params = array_merge($studentIds, [$selectedSubjectId, $selectedClassId, $academicYearId, $selectedSemesterId]);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $summaries[$row['student_id']] = $row;
        }
    }

    // بناء بيانات كل طالب
    foreach ($students as $student) {
        $studentGrades = $allGrades[$student['id']] ?? [];
        $summary = $summaries[$student['id']] ?? null;

        $typeAverages = [];
        $totalWeighted = 0;
        $totalAppliedWeight = 0;

        foreach ($testsByType as $typeCode => $typeData) {
            $typeTotal = 0;
            $typeMaxTotal = 0;
            $typeCount = 0;

            foreach ($typeData['tests'] as $test) {
                if (isset($studentGrades[$test['id']])) {
                    $typeTotal += (float)$studentGrades[$test['id']]['grade_value'];
                    $typeMaxTotal += (float)$studentGrades[$test['id']]['max_value'];
                    $typeCount++;
                }
            }

            $avgPct = $typeMaxTotal > 0 ? round(($typeTotal / $typeMaxTotal) * 100, 2) : null;
            $typeAverages[$typeCode] = [
                'total' => $typeTotal,
                'max' => $typeMaxTotal,
                'avg_pct' => $avgPct,
                'count' => $typeCount,
                'name' => $typeData['name']
            ];

            // حساب المرجح
            $weight = 0;
            foreach ($weightDistribution as $wd) {
                if ($wd['code'] === $typeCode) {
                    $weight = (float)$wd['weight'];
                    break;
                }
            }

            if ($avgPct !== null && $weight > 0) {
                $totalWeighted += $avgPct * $weight;
                $totalAppliedWeight += $weight;
            }
        }

        $weightedTotal = $totalAppliedWeight > 0 ? round($totalWeighted / $totalAppliedWeight, 2) : 0;

        $studentsData[] = [
            'id' => $student['id'],
            'student_number' => $student['student_number'],
            'full_name' => $student['full_name_ar'],
            'type_averages' => $typeAverages,
            'weighted_total' => $summary ? (float)$summary['percentage'] : $weightedTotal,
            'letter_grade' => $summary ? $summary['letter_grade'] : ($weightedTotal > 0 ? $calculator->getLetterGrade($weightedTotal)['letter'] : '-'),
            'grade_point' => $summary ? $summary['grade_point'] : ($weightedTotal > 0 ? $calculator->getLetterGrade($weightedTotal)['point'] : 0),
            'rank' => $summary ? $summary['rank_in_class'] : null,
            'passing' => $weightedTotal >= 50
        ];
    }

    // حساب الترتيب
    usort($studentsData, function($a, $b) {
        return $b['weighted_total'] <=> $a['weighted_total'];
    });
    $rank = 1;
    foreach ($studentsData as $idx => &$sd) {
        $sd['calculated_rank'] = $rank++;
    }
    unset($sd);
}

// ============================================================
// معالجة التصدير
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($studentsData)) {
    ob_end_clean(); // مسح المخرجات المخزنة
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=grades_summary_' . date('Ymd') . '.csv');
    echo "\xEF\xBB\xBF"; // BOM for UTF-8

    // رؤوس الأعمدة
    $headers = ['الترتيب', 'رقم الطالب', 'اسم الطالب'];
    foreach ($testsByType ?? [] as $code => $td) {
        $headers[] = $td['name'] . ' (%)';
    }
    $headers[] = 'المجموع المرجح (%)';
    $headers[] = 'التقدير';
    $headers[] = 'نقطة التقدير';
    $headers[] = 'الحالة';

    echo implode(',', $headers) . "\n";

    foreach ($studentsData as $sd) {
        $row = [$sd['calculated_rank'], $sd['student_number'], $sd['full_name']];
        foreach ($testsByType ?? [] as $code => $td) {
            $row[] = $sd['type_averages'][$code]['avg_pct'] ?? '-';
        }
        $row[] = $sd['weighted_total'];
        $row[] = $sd['letter_grade'];
        $row[] = $sd['grade_point'];
        $row[] = $sd['passing'] ? 'ناجح' : 'راسب';
        echo implode(',', $row) . "\n";
    }
    exit;
}
?>

<!-- ============================================================
     نموذج الاختيار
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">📊 ملخص الدرجات</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="summary.php">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">-- اختر --</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= $selectedSemesterId == $sem['id'] ? 'selected' : '' ?>>
                                <?= e($sem['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الشعبة</label>
                    <select name="class_id" class="form-control" required>
                        <option value="">-- اختر --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $selectedClassId == $cls['id'] ? 'selected' : '' ?>>
                                <?= e($cls['grade_name'] . ' - ' . $cls['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">المادة</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">-- اختر --</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>" <?= $selectedSubjectId == $sub['id'] ? 'selected' : '' ?>>
                                <?= e($sub['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 عرض الملخص</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($studentsData)): ?>

<!-- ============================================================
     توزيع الأوزان
     ============================================================ -->
<?php if (!empty($weightDistribution)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">⚖️ توزيع أوزان التقييمات</h3>
    </div>
    <div class="card-body">
        <div class="flex flex-wrap gap-4 items-center">
            <?php
            $totalWeight = 0;
            foreach ($weightDistribution as $wd):
                $totalWeight += (float)$wd['weight'];
            ?>
                <div class="text-center p-3" style="background:rgba(26,86,50,0.06); border-radius:var(--radius-md); min-width:120px;">
                    <div class="text-lg font-bold" style="color:var(--primary);"><?= $wd['weight'] ?>%</div>
                    <div class="text-sm text-secondary"><?= e($wd['name_ar']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="text-center p-3" style="background:<?= $totalWeight == 100 ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)' ?>; border-radius:var(--radius-md); min-width:120px;">
                <div class="text-lg font-bold" style="color:<?= $totalWeight == 100 ? 'var(--success)' : 'var(--danger)' ?>;"><?= $totalWeight ?>%</div>
                <div class="text-sm text-secondary">المجموع</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     أزرار التصدير والطباعة
     ============================================================ -->
<div class="flex gap-3 mb-6 flex-wrap">
    <a href="summary.php?class_id=<?= $selectedClassId ?>&subject_id=<?= $selectedSubjectId ?>&semester_id=<?= $selectedSemesterId ?>&export=excel"
       class="btn btn-success">
        📥 تصدير إلى إكسيل
    </a>
    <button type="button" class="btn btn-info" onclick="window.print()">
        🖨️ طباعة
    </button>
</div>

<!-- ============================================================
     جدول ملخص الدرجات
     ============================================================ -->
<div class="card mb-6">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="data-table" id="summaryTable">
                <thead>
                    <tr>
                        <th>الترتيب</th>
                        <th>رقم الطالب</th>
                        <th>اسم الطالب</th>
                        <?php foreach ($testsByType as $code => $td): ?>
                            <th><?= e($td['name']) ?> (%)</th>
                        <?php endforeach; ?>
                        <th>المجموع المرجح</th>
                        <th>التقدير</th>
                        <th>نقطة التقدير</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studentsData as $sd): ?>
                        <tr style="<?= !$sd['passing'] ? 'background-color:rgba(220,53,69,0.05)' : '' ?>">
                            <td class="text-center">
                                <strong><?= $sd['calculated_rank'] ?></strong>
                            </td>
                            <td><?= e($sd['student_number']) ?></td>
                            <td><strong><?= e($sd['full_name']) ?></strong></td>
                            <?php foreach ($testsByType as $code => $td): ?>
                                <td class="text-center">
                                    <?php
                                    $typeAvg = $sd['type_averages'][$code]['avg_pct'] ?? null;
                                    if ($typeAvg !== null):
                                        $color = $typeAvg >= 70 ? 'var(--success)' : ($typeAvg >= 50 ? 'var(--warning-dark)' : 'var(--danger)');
                                    ?>
                                        <span style="color:<?= $color ?>; font-weight:600;"><?= $typeAvg ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <strong style="color:<?= $sd['passing'] ? 'var(--success)' : 'var(--danger)' ?>; font-size:var(--font-size-base);">
                                    <?= $sd['weighted_total'] ?>%
                                </strong>
                            </td>
                            <td class="text-center">
                                <?php
                                $lg = $sd['letter_grade'];
                                $badgeClass = in_array($lg, ['A+','A','B+','B']) ? 'success' : (in_array($lg, ['C+','C']) ? 'info' : (in_array($lg, ['D+','D','E']) ? 'warning' : 'danger'));
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>"><?= e($lg) ?></span>
                            </td>
                            <td class="text-center"><?= $sd['grade_point'] ?></td>
                            <td class="text-center">
                                <?php if ($sd['passing']): ?>
                                    <span class="badge badge-success">✅ ناجح</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">❌ راسب</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:left;"><strong>الإحصائيات</strong></td>
                        <?php foreach ($testsByType as $code => $td): ?>
                            <td class="text-center">
                                <?php
                                $typeAvgs = array_filter(array_map(function($s) use ($code) {
                                    return $s['type_averages'][$code]['avg_pct'] ?? null;
                                }, $studentsData));
                                echo count($typeAvgs) > 0 ? round(array_sum($typeAvgs) / count($typeAvgs), 1) . '%' : '-';
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <?php
                        $allTotals = array_column($studentsData, 'weighted_total');
                        $avgTotal = count($allTotals) > 0 ? round(array_sum($allTotals) / count($allTotals), 1) : 0;
                        $passCount = count(array_filter($studentsData, function($s) { return $s['passing']; }));
                        ?>
                        <td class="text-center"><strong><?= $avgTotal ?>%</strong></td>
                        <td colspan="2" class="text-center">
                            <span style="color:var(--success);"><?= $passCount ?> ناجح</span> /
                            <span style="color:var(--danger);"><?= count($studentsData) - $passCount ?> راسب</span>
                        </td>
                        <td class="text-center">
                            <?= round(($passCount / max(count($studentsData), 1)) * 100, 1) ?>% نسبة النجاح
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     رسم بياني لتوزيع الدرجات
     ============================================================ -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📊 توزيع الدرجات</h3>
    </div>
    <div class="card-body">
        <div style="max-width:700px; margin:0 auto;">
            <canvas id="gradeDistChart" height="300"></canvas>
        </div>
    </div>
</div>

<?php elseif ($selectedClassId > 0 && $selectedSubjectId > 0 && $selectedSemesterId > 0): ?>
<div class="card">
    <div class="card-body">
        <div class="table-empty">
            <div class="table-empty-icon">📭</div>
            <div class="table-empty-text">لا توجد بيانات درجات لهذا الاختيار</div>
            <div class="text-sm text-secondary mt-2">تأكد من إدخال الدرجات أولاً من صفحة إدخال الدرجات</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- أنماط الطباعة -->
<style>
    @media print {
        .sidebar, .header, .btn, .flash-message { display: none !important; }
        .main-content { margin: 0 !important; }
    }
</style>

<!-- رسم بياني -->
<?php if (!empty($studentsData)): ?>
<?php
// تحضير بيانات الرسم البياني
$distBuckets = [
    '0-19' => 0, '20-39' => 0, '40-49' => 0, '50-59' => 0,
    '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0
];
foreach ($studentsData as $sd) {
    $p = $sd['weighted_total'];
    if ($p < 20) $distBuckets['0-19']++;
    elseif ($p < 40) $distBuckets['20-39']++;
    elseif ($p < 50) $distBuckets['40-49']++;
    elseif ($p < 60) $distBuckets['50-59']++;
    elseif ($p < 70) $distBuckets['60-69']++;
    elseif ($p < 80) $distBuckets['70-79']++;
    elseif ($p < 90) $distBuckets['80-89']++;
    else $distBuckets['90-100']++;
}
$chartLabels = json_encode(array_keys($distBuckets), JSON_UNESCAPED_UNICODE);
$chartData = json_encode(array_values($distBuckets));
$chartColors = json_encode(['#dc3545', '#e4606d', '#ffc107', '#ffcd3a', '#48c468', '#28a745', '#2d7a4a', '#1a5632']);
$pageJs = "
    const ctx = document.getElementById('gradeDistChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: {$chartLabels},
                datasets: [{
                    label: 'عدد الطلاب',
                    data: {$chartData},
                    backgroundColor: {$chartColors},
                    borderRadius: 6,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'توزيع الدرجات', font: { size: 16 } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'عدد الطلاب' } },
                    x: { title: { display: true, text: 'نسبة الدرجات (%)' } }
                }
            }
        });
    }
";
?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
