<?php
/**
 * ============================================================
 * EQAS - درجات أبناء ولي الأمر
 * نظام التقييم الشامل - عرض الدرجات لجميع الأبناء
 * ============================================================
 * يعرض درجات جميع الأبناء المرتبطين بحساب ولي الأمر:
 * - الدرجات مفصلة حسب المادة والاختبار
 * - متوسط نسبة كل مادة
 * - تلوين حسب الأداء (أخضر ≥60%，أصفر 50-59%，أحمر <50%)
 * - زر العودة للرئيسية
 * ============================================================
 */

$pageTitle = 'درجات الأبناء';
$breadcrumb = ['لوحة ولي الأمر' => baseUrl('pages/dashboard/parent.php'), 'درجات الأبناء' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحية
if (!hasPermission('parent')) {
    setFlash('هذه الصفحة مخصصة لأولياء الأمور فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$parentId = $_SESSION['user']['id'];

// ═══════════════════════════════════════════
// جلب أبناء ولي الأمر
// ═══════════════════════════════════════════
$stmt = $db->prepare("
    SELECT s.id, s.full_name_ar, s.student_number, s.grade_level_id,
           gl.name_ar as grade_name, sc.name_ar as school_name,
           ps.relationship
    FROM parent_students ps
    JOIN students s ON ps.student_id = s.id
    JOIN grade_levels gl ON s.grade_level_id = gl.id
    JOIN schools sc ON s.school_id = sc.id
    WHERE ps.parent_id = ? AND ps.is_active = 1 AND s.is_active = 1
    ORDER BY gl.level_number, s.full_name_ar
");
$stmt->execute([$parentId]);
$children = $stmt->fetchAll();

// إذا لم يكن هناك أبناء
if (empty($children)):
?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center;padding:60px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">👨‍👩‍👧‍👦</div>
            <h3>لا يوجد طلاب مرتبطين بحسابك</h3>
            <p style="color:var(--text-secondary);margin-bottom:20px;">يرجى التواصل مع إدارة المدرسة.</p>
            <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-primary">
                <i class="fas fa-home"></i> العودة للرئيسية
            </a>
        </div>
    </div>
</div>
<?php
else:

$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// جلب الدرجات لكل طفل
// ═══════════════════════════════════════════
$childrenGrades = [];
foreach ($children as $child) {
    $sid = $child['id'];
    try {
        $stmt = $db->prepare("
            SELECT g.id, g.grade_value, g.max_value, g.percentage, g.letter_grade,
                   t.title_ar as test_title, t.test_date, t.max_grade as test_max_grade,
                   s.name_ar as subject_name, s.id as subject_id,
                   at.name_ar as assessment_type_name,
                   sem.name_ar as semester_name
            FROM grades g
            JOIN tests t ON g.test_id = t.id
            JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN assessment_types at ON t.assessment_type_id = at.id
            LEFT JOIN semesters sem ON t.semester_id = sem.id
            WHERE g.student_id = ? AND g.is_active = 1
            AND t.academic_year_id = ?
            ORDER BY s.name_ar, t.test_date DESC
        ");
        $stmt->execute([$sid, $academicYearId]);
        $grades = $stmt->fetchAll();
    } catch (Exception $e) {
        $grades = [];
    }

    // تنظيم الدرجات حسب المادة
    $gradesBySubject = [];
    foreach ($grades as $grade) {
        $subjId = $grade['subject_id'];
        if (!isset($gradesBySubject[$subjId])) {
            $gradesBySubject[$subjId] = [
                'subject_name' => $grade['subject_name'],
                'grades' => [],
                'total_percentage' => 0,
                'count' => 0
            ];
        }
        $gradesBySubject[$subjId]['grades'][] = $grade;
        $gradesBySubject[$subjId]['total_percentage'] += $grade['percentage'];
        $gradesBySubject[$subjId]['count']++;
    }

    // حساب المتوسط لكل مادة
    foreach ($gradesBySubject as $subjId => &$subjData) {
        $subjData['avg_percentage'] = $subjData['count'] > 0
            ? round($subjData['total_percentage'] / $subjData['count'], 1)
            : 0;
    }
    unset($subjData);

    $childrenGrades[$sid] = $gradesBySubject;
}

/**
 * دالة لتحديد لون الأداء حسب النسبة
 */
function getGradeColorClass($percentage) {
    if ($percentage >= 60) return 'success';
    if ($percentage >= 50) return 'warning';
    return 'danger';
}

function getGradeColorHex($percentage) {
    if ($percentage >= 60) return '#28a745';
    if ($percentage >= 50) return '#ffc107';
    return '#dc3545';
}

function getGradeLabel($percentage) {
    if ($percentage >= 90) return 'ممتاز';
    if ($percentage >= 80) return 'جيد جداً';
    if ($percentage >= 70) return 'جيد';
    if ($percentage >= 60) return 'مقبول';
    if ($percentage >= 50) return 'ضعيف';
    return 'راسب';
}
?>

<style>
.child-grades-card {
    margin-bottom: 24px;
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    border: 1px solid var(--border-light, #e5e7eb);
}
.child-grades-card .child-header {
    background: linear-gradient(135deg, #1a5632 0%, #1e6b3e 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.child-grades-card .child-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}
.child-grades-card .child-header .child-meta {
    font-size: 13px;
    opacity: 0.85;
}
.subject-block {
    margin: 16px 20px;
    padding: 16px;
    border: 1px solid var(--border-light, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    background: var(--surface, #fff);
}
.subject-block .subject-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 8px;
}
.subject-block .subject-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--eqas-green, #1a5632);
}
.subject-avg-badge {
    padding: 4px 14px;
    border-radius: var(--radius-full, 20px);
    font-size: 13px;
    font-weight: 700;
    color: #fff;
}
.grade-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.grade-table th {
    background: rgba(26,86,50,0.06);
    padding: 8px 10px;
    text-align: right;
    font-weight: 600;
    color: var(--text-secondary, #666);
    border-bottom: 2px solid var(--border-light, #e5e7eb);
    white-space: nowrap;
}
.grade-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-light, #e5e7eb);
}
.grade-table tr:hover td {
    background: rgba(26,86,50,0.02);
}
.grade-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}
.grade-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}
.no-grades-msg {
    text-align: center;
    padding: 30px;
    color: var(--text-muted, #999);
}
@media (max-width: 768px) {
    .child-grades-card .child-header {
        padding: 12px 14px;
    }
    .subject-block {
        margin: 10px 12px;
        padding: 10px;
    }
    .grade-table {
        font-size: 11px;
    }
    .grade-table th, .grade-table td {
        padding: 5px 6px;
    }
}
</style>

<div class="section">
    <!-- زر العودة -->
    <div style="margin-bottom:16px;">
        <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-outline-primary">
            <i class="fas fa-arrow-right"></i> العودة للرئيسية
        </a>
    </div>

    <?php foreach ($children as $child):
        $sid = $child['id'];
        $gradesBySubject = $childrenGrades[$sid] ?? [];
        $totalAvg = 0;
        $totalSubjects = 0;
        foreach ($gradesBySubject as $subjData) {
            $totalAvg += $subjData['avg_percentage'];
            $totalSubjects++;
        }
        $overallAvg = $totalSubjects > 0 ? round($totalAvg / $totalSubjects, 1) : 0;
    ?>
    <div class="child-grades-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-user-graduate" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div class="child-meta">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                    <?php if ($child['relationship']): ?>
                    | <span style="opacity:0.7;"><?php echo e($child['relationship'] === 'father' ? 'أب' : ($child['relationship'] === 'mother' ? 'أم' : ($child['relationship'] === 'guardian' ? 'وصي' : 'أخرى'))); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($overallAvg > 0): ?>
            <div style="text-align:center;">
                <div style="font-size:28px;font-weight:800;"><?php echo $overallAvg; ?>%</div>
                <div style="font-size:11px;opacity:0.8;">المعدل العام</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding:0;">
            <?php if (empty($gradesBySubject)): ?>
            <div class="no-grades-msg">
                <div style="font-size:40px;margin-bottom:8px;">📊</div>
                <p>لا توجد درجات مسجلة لهذا الطالب في العام الدراسي الحالي</p>
            </div>
            <?php else: ?>
                <?php foreach ($gradesBySubject as $subjId => $subjData):
                    $avgPct = $subjData['avg_percentage'];
                    $avgColorClass = getGradeColorClass($avgPct);
                    $avgColorHex = getGradeColorHex($avgPct);
                ?>
                <div class="subject-block">
                    <div class="subject-header">
                        <h4><i class="fas fa-book" style="margin-left:6px;opacity:0.6;"></i><?php echo e($subjData['subject_name']); ?></h4>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:12px;color:var(--text-muted);">متوسط المادة:</span>
                            <span class="subject-avg-badge" style="background:<?php echo $avgColorHex; ?>;">
                                <?php echo $avgPct; ?>% - <?php echo getGradeLabel($avgPct); ?>
                            </span>
                        </div>
                    </div>
                    <!-- شريط تقدم المتوسط -->
                    <div style="margin-bottom:12px;">
                        <div style="width:100%;height:8px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                            <div style="width:<?php echo min(100, $avgPct); ?>%;height:100%;background:<?php echo $avgColorHex; ?>;border-radius:var(--radius-full,20px);transition:width 0.6s;"></div>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="grade-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الاختبار</th>
                                    <th>نوع التقييم</th>
                                    <th>التاريخ</th>
                                    <th>الدرجة</th>
                                    <th>من</th>
                                    <th>النسبة</th>
                                    <th>التقدير</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjData['grades'] as $i => $grade):
                                    $pct = $grade['percentage'];
                                    $colorClass = getGradeColorClass($pct);
                                    $colorHex = getGradeColorHex($pct);
                                ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo e($grade['test_title']); ?></td>
                                    <td><span style="font-size:11px;color:var(--text-muted);"><?php echo e($grade['assessment_type_name'] ?? '-'); ?></span></td>
                                    <td><span style="font-size:11px;"><?php echo formatDateAr($grade['test_date'], 'short'); ?></span></td>
                                    <td>
                                        <span class="grade-cell" style="color:<?php echo $colorHex; ?>;">
                                            <span class="grade-dot" style="background:<?php echo $colorHex; ?>;"></span>
                                            <?php echo number_format($grade['grade_value'], 1); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($grade['max_value'], 1); ?></td>
                                    <td>
                                        <span style="font-weight:700;color:<?php echo $colorHex; ?>;">
                                            <?php echo number_format($pct, 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($grade['letter_grade']): ?>
                                        <span style="padding:2px 8px;border-radius:var(--radius-sm);font-size:11px;font-weight:700;background:<?php echo $colorHex; ?>22;color:<?php echo $colorHex; ?>;">
                                            <?php echo e($grade['letter_grade']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span style="font-size:12px;color:var(--text-muted);"><?php echo getGradeLabel($pct); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
