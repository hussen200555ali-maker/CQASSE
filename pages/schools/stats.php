<?php
/**
 * ============================================================
 * إحصائيات المدرسة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إحصائيات المدرسة';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => 'index.php',
    'إحصائيات المدرسة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$schoolId = intval($_GET['id'] ?? 0);
if (!$schoolId) {
    setFlash('معرف المدرسة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('المدرسة غير موجودة', 'danger');
    redirect('index.php');
}

// 1. توزيع الطلاب حسب الجنس
$genderDist = $db->prepare("SELECT gender, COUNT(*) AS cnt FROM students WHERE school_id = ? AND is_active = 1 GROUP BY gender");
$genderDist->execute([$schoolId]);
$genderData = $genderDist->fetchAll();
$genderLabels = [];
$genderValues = [];
$genderMap = ['male' => 'ذكور', 'female' => 'إناث'];
foreach ($genderData as $gd) {
    $genderLabels[] = $genderMap[$gd['gender']] ?? $gd['gender'];
    $genderValues[] = intval($gd['cnt']);
}

// 2. توزيع المعلمين حسب المادة
$teacherDist = $db->prepare("SELECT sub.name_ar AS subject, COUNT(DISTINCT st.teacher_id) AS cnt
                             FROM subject_teachers st
                             JOIN subjects sub ON st.subject_id = sub.id
                             JOIN users u ON st.teacher_id = u.id
                             WHERE st.school_id = ? AND st.is_active = 1
                             GROUP BY sub.name_ar ORDER BY cnt DESC LIMIT 10");
$teacherDist->execute([$schoolId]);
$teacherData = $teacherDist->fetchAll();

// 3. توزيع الطلاب حسب المرحلة الدراسية
$gradeDist = $db->prepare("SELECT gl.name_ar, COUNT(s.id) AS cnt
                           FROM students s
                           JOIN grade_levels gl ON s.grade_level_id = gl.id
                           WHERE s.school_id = ? AND s.is_active = 1
                           GROUP BY gl.id ORDER BY gl.level_number");
$gradeDist->execute([$schoolId]);
$gradeData = $gradeDist->fetchAll();

// 4. مؤشر جودة التعليم عبر الفصول الدراسية
$eqiTrend = [];
try {
    $stmt = $db->prepare("SELECT sem.name_ar AS semester, er.overall_score
                          FROM eqi_results er
                          JOIN semesters sem ON er.semester_id = sem.id
                          WHERE er.school_id = ?
                          ORDER BY sem.start_date");
    $stmt->execute([$schoolId]);
    $eqiTrend = $stmt->fetchAll();
} catch (Exception $e) {
    $eqiTrend = [];
}

// 5. معدل الحضور
$attendanceRate = null;
try {
    $stmt = $db->prepare("SELECT AVG(attendance_rate) AS avg_rate FROM attendance_summaries WHERE school_id = ?");
    $stmt->execute([$schoolId]);
    $attRow = $stmt->fetch();
    $attendanceRate = $attRow ? $attRow['avg_rate'] : null;
} catch (Exception $e) {
    $attendanceRate = null;
}

$totalStudents = array_sum($genderValues);
?>

<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">📊 إحصائيات: <?= e($school['name_ar']) ?></h3>
            <a href="view.php?id=<?= $schoolId ?>" class="btn btn-light btn-sm">↩ العودة للتفاصيل</a>
        </div>
    </div>
</div>

<!-- بطاقات ملخصة -->
<div class="grid grid-4 mb-6" style="gap:var(--space-4);">
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">🎓</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--primary);"><?= formatNumberAr($totalStudents) ?></div>
            <div class="text-sm text-secondary">إجمالي الطلاب</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">👨‍🏫</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--success);">
                <?php
                $stmt = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN roles r ON u.role_id = r.id WHERE u.school_id = ? AND r.name = 'teacher' AND u.is_active = 1");
                $stmt->execute([$schoolId]);
                echo formatNumberAr($stmt->fetchColumn());
                ?>
            </div>
            <div class="text-sm text-secondary">المعلمون</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">🏫</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--info);">
                <?php
                $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND is_active = 1");
                $stmt->execute([$schoolId]);
                echo formatNumberAr($stmt->fetchColumn());
                ?>
            </div>
            <div class="text-sm text-secondary">الفصول</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:28px;">📋</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--secondary);">
                <?= $attendanceRate !== null ? number_format($attendanceRate, 1) . '%' : '—' ?>
            </div>
            <div class="text-sm text-secondary">معدل الحضور</div>
        </div>
    </div>
</div>

<!-- الرسوم البيانية -->
<div class="grid grid-2" style="gap:var(--space-6);">
    <!-- توزيع الطلاب حسب الجنس -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">توزيع الطلاب حسب الجنس</h3>
        </div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="genderChart"></canvas>
            </div>
        </div>
    </div>

    <!-- توزيع الطلاب حسب المرحلة -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">توزيع الطلاب حسب المرحلة الدراسية</h3>
        </div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="gradeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- توزيع المعلمين -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">توزيع المعلمين حسب المادة</h3>
        </div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="teacherChart"></canvas>
            </div>
        </div>
    </div>

    <!-- مؤشر جودة التعليم -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">مؤشر جودة التعليم عبر الفصول</h3>
        </div>
        <div class="card-body">
            <div style="height:300px;">
                <canvas id="eqiChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php
$pageJs = <<<JS
    // رسم بياني: توزيع الطلاب حسب الجنس
    initChart('genderChart', 'doughnut', {
        labels: <?= json_encode($genderLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode($genderValues) ?>,
            backgroundColor: ['#1a5632', '#c8a951'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    });

    // رسم بياني: توزيع الطلاب حسب المرحلة
    initChart('gradeChart', 'bar', {
        labels: <?= json_encode(array_column($gradeData, 'name_ar'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'عدد الطلاب',
            data: <?= json_encode(array_map('intval', array_column($gradeData, 'cnt'))) ?>,
            backgroundColor: 'rgba(26, 86, 50, 0.7)',
            borderColor: '#1a5632',
            borderWidth: 1
        }]
    });

    // رسم بياني: توزيع المعلمين
    initChart('teacherChart', 'bar', {
        labels: <?= json_encode(array_column($teacherData, 'subject'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'عدد المعلمين',
            data: <?= json_encode(array_map('intval', array_column($teacherData, 'cnt'))) ?>,
            backgroundColor: 'rgba(200, 169, 81, 0.7)',
            borderColor: '#c8a951',
            borderWidth: 1
        }]
    }, { indexAxis: 'y' });

    // رسم بياني: مؤشر جودة التعليم
    const eqiLabels = <?= json_encode(array_column($eqiTrend, 'semester'), JSON_UNESCAPED_UNICODE) ?>;
    const eqiValues = <?= json_encode(array_map('floatval', array_column($eqiTrend, 'overall_score'))) ?>;
    initChart('eqiChart', 'line', {
        labels: eqiLabels.length > 0 ? eqiLabels : ['لا توجد بيانات'],
        datasets: [{
            label: 'مؤشر جودة التعليم',
            data: eqiValues.length > 0 ? eqiValues : [0],
            borderColor: '#1a5632',
            backgroundColor: 'rgba(26, 86, 50, 0.1)',
            fill: true,
            tension: 0.3
        }]
    });
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
