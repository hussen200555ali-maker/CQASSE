<?php
$pageTitle = 'تفاصيل تقييم المعلم';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'تفاصيل تقييم المعلم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$teacherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : (int)currentAcademicYearId();
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

$isGroupAdmin = ($_SESSION['user']['role'] === 'group_admin');
if ($isGroupAdmin) {
    $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
} else {
    $schoolId = currentSchoolId();
}

if ($teacherId === 0) {
    setFlash('معلم غير محدد.', 'danger');
    redirect(baseUrl('pages/supervision/teaching_school.php' . ($schoolId > 0 ? "?id=$schoolId" : '')));
}

if ($selectedSemesterId === 0 && !isset($_GET['semester_id'])) {
    $selectedSemesterId = (int)currentSemesterId($selectedAcademicYearId);
}

// معلومات المعلم
$teacher = null;
try {
    $stmt = $db->prepare("SELECT id, full_name_ar, email FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();
} catch (Exception $e) {}

if (!$teacher) {
    setFlash('المعلم غير موجود.', 'danger');
    redirect(baseUrl('pages/supervision/teaching_school.php' . ($schoolId > 0 ? "?id=$schoolId" : '')));
}

// الفلاتر
$academicYears = [];
try {
    $academicYears = $db->query("SELECT id, name AS name_ar FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {}

$allSemesters = [];
if ($selectedAcademicYearId > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$selectedAcademicYearId]);
        $allSemesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// تقييمات المعلم
$evalWeights = ['teaching_self' => 0.20, 'teaching_peer' => 0.30, 'teaching_admin' => 0.50];
$evalLabels = [
    'teaching_self' => ['label' => 'ذاتي', 'weight' => '20%', 'color' => 'var(--info)'],
    'teaching_peer' => ['label' => 'أقران', 'weight' => '30%', 'color' => 'var(--warning)'],
    'teaching_admin' => ['label' => 'إشرافي', 'weight' => '50%', 'color' => 'var(--primary)'],
];

$currentScores = [];
$currentCombined = null;
foreach ($evalWeights as $formKey => $weight) {
    try {
        $stmt = $db->prepare("
            SELECT AVG(ar.numeric_value) AS avg_score
            FROM assessment_responses ar
            JOIN assessment_forms af ON ar.form_id = af.id
            WHERE af.form_key = ? AND ar.teacher_id = ? AND ar.is_active = 1
        ");
        $stmt->execute([$formKey, $teacherId]);
        $row = $stmt->fetch();
        $currentScores[$formKey] = $row && $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
    } catch (Exception $e) {
        $currentScores[$formKey] = null;
    }
}

$combined = 0;
$totalWeight = 0;
foreach ($currentScores as $formKey => $score) {
    if ($score !== null) {
        $combined += $score * $evalWeights[$formKey];
        $totalWeight += $evalWeights[$formKey];
    }
}
$currentCombined = $totalWeight > 0 ? round($combined / $totalWeight, 1) : null;

// السجل التاريخي للتقييمات
$history = [];
try {
    $stmt = $db->prepare("
        SELECT e.academic_year_id, e.semester_id, ay.name AS year_name, s.name_ar AS sem_name,
               e.self_score, e.peer_score, e.admin_score, e.combined_score
        FROM evaluations e
        JOIN academic_years ay ON e.academic_year_id = ay.id
        JOIN semesters s ON e.semester_id = s.id
        WHERE e.eval_type = 'teaching_summary' AND e.teacher_id = ? AND e.is_active = 1
        ORDER BY ay.start_date DESC, s.start_date DESC
    ");
    $stmt->execute([$teacherId]);
    $history = $stmt->fetchAll();
} catch (Exception $e) {}

// الاتجاه عبر الفصول
$trendHistory = [];
$trendLabels = [];
try {
    $stmt = $db->prepare("
        SELECT e.academic_year_id, e.semester_id, e.combined_score, s.name_ar AS sem_name
        FROM evaluations e
        JOIN semesters s ON e.semester_id = s.id
        WHERE e.eval_type = 'teaching_summary' AND e.teacher_id = ? AND e.is_active = 1
        ORDER BY s.start_date ASC
        LIMIT 12
    ");
    $stmt->execute([$teacherId]);
    $trendHistory = $stmt->fetchAll();
} catch (Exception $e) {}

foreach ($trendHistory as $th) {
    $trendLabels[] = $th['sem_name'];
}

// خطة التطوير النشطة
$activePlan = null;
try {
    $stmt = $db->prepare("
        SELECT * FROM improvement_plans
        WHERE plan_type = 'teacher' AND teacher_id = ? AND status = 'active' AND is_active = 1
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$teacherId]);
    $activePlan = $stmt->fetch();
} catch (Exception $e) {}

$needsPlan = $currentCombined !== null && $currentCombined < 60 && !$activePlan;
?>

<div class="section">
    <!-- شريط الفلاتر -->
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="id" value="<?= $teacherId ?>">
                <?php if ($schoolId > 0): ?>
                <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                <?php endif; ?>
                <div class="form-group" style="min-width:180px;">
                    <label class="form-label" style="font-size:12px;">السنة الدراسية</label>
                    <select name="academic_year_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $ay['id'] == $selectedAcademicYearId ? 'selected' : '' ?>><?= e($ay['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:160px;">
                    <label class="form-label" style="font-size:12px;">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($allSemesters as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSemesterId ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;">تطبيق</button>
                <a href="teaching_school.php<?= $schoolId > 0 ? "?id=$schoolId" : '' ?>" class="btn btn-light btn-sm" style="margin-bottom:1px;">↩ عودة للمدرسة</a>
                <?php if ($isGroupAdmin): ?>
                <a href="teaching_dashboard.php?academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="btn btn-light btn-sm" style="margin-bottom:1px;">📊 مركز القيادة</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- رأس المعلم -->
    <div class="card mb-6">
        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <h2 style="margin:0;">👨‍🏫 <?= e($teacher['full_name_ar']) ?></h2>
                <?php if ($teacher['email']): ?>
                <span style="font-size:13px;color:var(--text-muted);"><?= e($teacher['email']) ?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);;">التقييم الحالي</div>
                    <div style="font-size:32px;font-weight:bold;color:<?= $currentCombined !== null && $currentCombined < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $currentCombined !== null ? $currentCombined . '%' : '—' ?>
                    </div>
                </div>
                <?php if ($needsPlan): ?>
                <div style="text-align:center;">
                    <a href="development_plan.php?school_id=<?= $schoolId ?>&teacher_id=<?= $teacherId ?>" class="btn btn-danger">🎯 إنشاء خطة تطوير</a>
                </div>
                <?php elseif ($activePlan): ?>
                <div style="text-align:center;">
                    <span style="background:var(--success);color:white;padding:4px 12px;border-radius:var(--radius-full);font-size:13px;">📋 خطة تطوير نشطة (<?= $activePlan['progress'] ?>%)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($needsPlan): ?>
    <div class="alert alert-danger mb-6">
        <strong>⚠️ تقييم المعلم أقل من 60% ولا توجد خطة تطوير نشطة!</strong>
        يُرجى <a href="development_plan.php?school_id=<?= $schoolId ?>&teacher_id=<?= $teacherId ?>" style="font-weight:bold;">إنشاء خطة تطوير</a> فوراً.
    </div>
    <?php endif; ?>

    <!-- درجات التقييم الحالية -->
    <div class="grid grid-4 mb-6" style="gap:16px;">
        <?php foreach ($evalLabels as $key => $info):
            $score = $currentScores[$key] ?? null;
        ?>
        <div class="stat-card" style="border-top:3px solid <?= $info['color'] ?>;">
            <div class="stat-icon" style="color:<?= $info['color'] ?>;">📊</div>
            <div class="stat-value" style="font-size:28px;color:<?= $info['color'] ?>;"><?= $score !== null ? $score . '%' : '—' ?></div>
            <div class="stat-label"><?= $info['label'] ?> (<?= $info['weight'] ?>)</div>
        </div>
        <?php endforeach; ?>
        <div class="stat-card" style="border-top:3px solid <?= $currentCombined !== null && $currentCombined < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
            <div class="stat-icon" style="color:<?= $currentCombined !== null && $currentCombined < 60 ? 'var(--danger)' : 'var(--success)' ?>;">🎯</div>
            <div class="stat-value" style="font-size:28px;color:<?= $currentCombined !== null && $currentCombined < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                <?= $currentCombined !== null ? $currentCombined . '%' : '—' ?>
            </div>
            <div class="stat-label">المجموع المرجح</div>
        </div>
    </div>

    <!-- رسم بياني للمقارنة -->
    <div class="grid grid-2 mb-6" style="gap:16px;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 مقارنة أنواع التقييم</h3></div>
            <div class="card-body"><canvas id="teacherDetailChart" height="200"></canvas></div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📈 اتجاه التقييم عبر الفصول</h3></div>
            <div class="card-body">
                <?php if (empty($trendHistory)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px;">لا توجد بيانات تاريخية كافية.</div>
                <?php else: ?>
                <canvas id="teacherTrendChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- السجل التاريخي -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📋 السجل التاريخي للتقييمات</h3>
        </div>
        <div class="card-body">
            <?php if (empty($history)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <p>لا توجد بيانات تاريخية لهذا المعلم.</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>العام</th>
                            <th>الفصل</th>
                            <th>ذاتي</th>
                            <th>أقران</th>
                            <th>إشرافي</th>
                            <th>المجموع</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h):
                            $combined = (float)$h['combined_score'];
                        ?>
                        <tr>
                            <td><?= e($h['year_name']) ?></td>
                            <td><?= e($h['sem_name']) ?></td>
                            <td><?= $h['self_score'] !== null ? number_format((float)$h['self_score'], 1) . '%' : '-' ?></td>
                            <td><?= $h['peer_score'] !== null ? number_format((float)$h['peer_score'], 1) . '%' : '-' ?></td>
                            <td><?= $h['admin_score'] !== null ? number_format((float)$h['admin_score'], 1) . '%' : '-' ?></td>
                            <td><strong style="color:<?= $combined < 60 ? 'var(--danger)' : 'var(--success)' ?>;"><?= number_format($combined, 1) ?>%</strong></td>
                            <td>
                                <?php if ($combined >= 80): ?>
                                <span style="background:var(--success);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">ممتاز</span>
                                <?php elseif ($combined >= 60): ?>
                                <span style="background:var(--warning);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">مقبول</span>
                                <?php else: ?>
                                <span style="background:var(--danger);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">يحتاج تطوير</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- خطة التطوير النشطة -->
    <?php if ($activePlan): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🎯 خطة التطوير النشطة</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-3" style="gap:16px;font-size:14px;">
                <div>
                    <strong style="color:var(--danger);">🔻 نقاط الضعف:</strong>
                    <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($activePlan['weaknesses'])) ?></p>
                </div>
                <div>
                    <strong style="color:var(--success);">🎯 الأهداف:</strong>
                    <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($activePlan['goals'])) ?></p>
                </div>
                <div>
                    <strong style="color:var(--info);">📋 الاستراتيجيات:</strong>
                    <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($activePlan['strategies'])) ?></p>
                </div>
            </div>
            <div style="margin-top:16px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                    <span>التقدم</span>
                    <strong><?= $activePlan['progress'] ?>%</strong>
                </div>
                <div style="width:100%;height:8px;background:var(--border-light);border-radius:var(--radius-full);">
                    <div style="width:<?= $activePlan['progress'] ?>%;height:100%;background:<?= $activePlan['progress'] >= 80 ? 'var(--success)' : ($activePlan['progress'] >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;border-radius:var(--radius-full);"></div>
                </div>
            </div>
            <div style="margin-top:12px;">
                <a href="development_plan.php?school_id=<?= $schoolId ?>" class="btn btn-primary btn-sm">متابعة الخطة</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Bar Chart (مقارنة أنواع التقييم)
    const barCtx = document.getElementById('teacherDetailChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['ذاتي (20%)', 'أقران (30%)', 'إشرافي (50%)', 'المجموع'],
                datasets: [{
                    label: 'الدرجة',
                    data: [
                        <?= $currentScores['teaching_self'] ?? 0 ?>,
                        <?= $currentScores['teaching_peer'] ?? 0 ?>,
                        <?= $currentScores['teaching_admin'] ?? 0 ?>,
                        <?= $currentCombined ?? 0 ?>
                    ],
                    backgroundColor: [
                        'rgba(23,162,184,0.7)',
                        'rgba(255,193,7,0.7)',
                        'rgba(26,86,50,0.7)',
                        'rgba(220,53,69,0.7)'
                    ],
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { font: { family: 'Tajawal' } } },
                    x: { ticks: { font: { family: 'Tajawal' } } }
                }
            }
        });
    }

    // Line Chart (اتجاه)
    const lineCtx = document.getElementById('teacherTrendChart');
    if (lineCtx) {
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: 'التقييم',
                    data: <?= json_encode(array_column($trendHistory, 'combined_score')) ?>,
                    borderColor: '#1a5632',
                    backgroundColor: 'rgba(26,86,50,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointBackgroundColor: '#1a5632',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { font: { family: 'Tajawal' } } },
                    x: { ticks: { font: { family: 'Tajawal' } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
