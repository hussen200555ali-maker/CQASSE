<?php
$pageTitle = 'تحليل أداء المعلمين';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'تحليل أداء المعلمين' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

require_once __DIR__ . '/../../includes/contextual_adjustment.php';

$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

// تحديد المدرسة
$isGroupAdmin = ($_SESSION['user']['role'] === 'group_admin');
if ($isGroupAdmin) {
    $selectedSchoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
} else {
    $selectedSchoolId = currentSchoolId();
}

if ($selectedAcademicYearId === 0) {
    $selectedAcademicYearId = (int)currentAcademicYearId();
}
if (!isset($_GET['semester_id']) && $selectedAcademicYearId > 0) {
    $selectedSemesterId = (int)currentSemesterId($selectedAcademicYearId);
}

// فلاتر
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

$schools = getAvailableSchools($db, $isGroupAdmin ? 'group_admin' : 'school_admin', $selectedSchoolId);

// اسم المدرسة
$schoolName = '';
$schoolCode = '';
try {
    $stmt = $db->prepare("SELECT name_ar, reference_code FROM schools WHERE id = ?");
    $stmt->execute([$selectedSchoolId]);
    $sRow = $stmt->fetch();
    if ($sRow) {
        $schoolName = $sRow['name_ar'];
        $schoolCode = $sRow['reference_code'];
    }
} catch (Exception $e) {}

// إحصائيات سريعة للمدرسة
$schoolStats = getSchoolQuickStats($db, $selectedSchoolId, $selectedAcademicYearId);

// متوسط التقييم العام للمدرسة
$schoolAvgScore = null;
try {
    $sql = "SELECT AVG(e.combined_score) FROM evaluations e
            JOIN users u ON e.teacher_id = u.id AND u.school_id = ?
            WHERE e.eval_type = 'teaching_summary' AND e.academic_year_id = ? AND e.semester_id = ? AND e.is_active = 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$selectedSchoolId, $selectedAcademicYearId, $selectedSemesterId]);
    $schoolAvgScore = $stmt->fetchColumn();
    if ($schoolAvgScore !== null) $schoolAvgScore = round((float)$schoolAvgScore, 1);
} catch (Exception $e) {}

// التعديل السياقي
$adjustedScore = $schoolAvgScore;
if ($schoolAvgScore !== null && $schoolAvgScore > 0) {
    try {
        $adj = new ContextualAdjustmentService($db);
        $adjustedScore = $adj->calculateAdjustedSQI($selectedSchoolId, $selectedAcademicYearId, $schoolAvgScore);
    } catch (Exception $e) {}
}

// متوسط المجموعة للمقارنة في Radar
$groupSelfAvg = $groupPeerAvg = $groupAdminAvg = $groupCombinedAvg = 0;
try {
    $stmt = $db->prepare("
        SELECT AVG(e.self_score) AS s_self, AVG(e.peer_score) AS s_peer,
               AVG(e.admin_score) AS s_admin, AVG(e.combined_score) AS s_combined
        FROM evaluations e
        JOIN users u ON e.teacher_id = u.id
        WHERE e.eval_type = 'teaching_summary' AND e.academic_year_id = ? AND e.semester_id = ? AND e.is_active = 1
    ");
    $stmt->execute([$selectedAcademicYearId, $selectedSemesterId]);
    $g = $stmt->fetch();
    $groupSelfAvg = round((float)($g['s_self'] ?? 0), 1);
    $groupPeerAvg = round((float)($g['s_peer'] ?? 0), 1);
    $groupAdminAvg = round((float)($g['s_admin'] ?? 0), 1);
    $groupCombinedAvg = round((float)($g['s_combined'] ?? 0), 1);
} catch (Exception $e) {}

// متوسط المدرسة للأبعاد (لـ Radar)
$schoolSelfAvg = $schoolPeerAvg = $schoolAdminAvg = 0;
try {
    $stmt = $db->prepare("
        SELECT AVG(e.self_score) AS s_self, AVG(e.peer_score) AS s_peer, AVG(e.admin_score) AS s_admin
        FROM evaluations e
        JOIN users u ON e.teacher_id = u.id AND u.school_id = ?
        WHERE e.eval_type = 'teaching_summary' AND e.academic_year_id = ? AND e.semester_id = ? AND e.is_active = 1
    ");
    $stmt->execute([$selectedSchoolId, $selectedAcademicYearId, $selectedSemesterId]);
    $r = $stmt->fetch();
    $schoolSelfAvg = round((float)($r['s_self'] ?? 0), 1);
    $schoolPeerAvg = round((float)($r['s_peer'] ?? 0), 1);
    $schoolAdminAvg = round((float)($r['s_admin'] ?? 0), 1);
} catch (Exception $e) {}

// جلب معلمي المدرسة مع الدرجات
$evalWeights = ['teaching_self' => 0.20, 'teaching_peer' => 0.30, 'teaching_admin' => 0.50];

$stmt = $db->prepare("
    SELECT u.id, u.full_name_ar
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'teacher' AND u.is_active = 1 AND u.school_id = ?
    ORDER BY u.full_name_ar
");
$stmt->execute([$selectedSchoolId]);
$teachers = $stmt->fetchAll();

$teacherScores = [];
foreach ($teachers as $teacher) {
    $scores = [];
    foreach ($evalWeights as $formKey => $weight) {
        try {
            $stmt = $db->prepare("
                SELECT AVG(ar.numeric_value) as avg_score
                FROM assessment_responses ar
                JOIN assessment_forms af ON ar.form_id = af.id
                WHERE af.form_key = ? AND ar.teacher_id = ? AND ar.school_id = ? AND ar.is_active = 1
            ");
            $stmt->execute([$formKey, $teacher['id'], $selectedSchoolId]);
            $row = $stmt->fetch();
            $scores[$formKey] = $row && $row['avg_score'] !== null ? round($row['avg_score'], 1) : null;
        } catch (Exception $e) {
            $scores[$formKey] = null;
        }
    }

    $combined = 0;
    $totalWeight = 0;
    foreach ($scores as $formKey => $score) {
        if ($score !== null) {
            $combined += $score * $evalWeights[$formKey];
            $totalWeight += $evalWeights[$formKey];
        }
    }
    $combinedScore = $totalWeight > 0 ? round($combined / $totalWeight, 1) : null;

    $teacherScores[$teacher['id']] = [
        'name' => $teacher['full_name_ar'],
        'self' => $scores['teaching_self'],
        'peer' => $scores['teaching_peer'],
        'admin' => $scores['teaching_admin'],
        'combined' => $combinedScore,
        'needs_plan' => $combinedScore !== null && $combinedScore < 60,
    ];
}

uasort($teacherScores, function($a, $b) {
    return ($b['combined'] ?? 0) <=> ($a['combined'] ?? 0);
});

// الاتجاه لكل معلم (مقارنة بالفصل السابق)
$teacherTrends = [];
try {
    if ($selectedSemesterId > 0) {
        $stmt = $db->prepare("SELECT name_ar, start_date FROM semesters WHERE id = ?");
        $stmt->execute([$selectedSemesterId]);
        $semInfo = $stmt->fetch();

        if ($semInfo) {
            $stmt = $db->prepare("
                SELECT e.teacher_id, AVG(e.combined_score) AS prev_avg
                FROM evaluations e
                JOIN users u ON e.teacher_id = u.id AND u.school_id = ?
                JOIN semesters s ON e.semester_id = s.id
                WHERE e.eval_type = 'teaching_summary' AND s.name_ar = ? AND s.start_date < ? AND s.is_active = 1 AND e.is_active = 1
                GROUP BY e.teacher_id
            ");
            $stmt->execute([$selectedSchoolId, $semInfo['name_ar'], $semInfo['start_date']]);
            while ($row = $stmt->fetch()) {
                $teacherTrends[(int)$row['teacher_id']] = (float)$row['prev_avg'];
            }
        }
    }
} catch (Exception $e) {}

// التحقق من وجود خطة تطوير نشطة لكل معلم
$activePlans = [];
try {
    $stmt = $db->prepare("SELECT teacher_id FROM improvement_plans WHERE plan_type = 'teacher' AND school_id = ? AND status = 'active' AND is_active = 1");
    $stmt->execute([$selectedSchoolId]);
    while ($row = $stmt->fetch()) {
        $activePlans[] = (int)$row['teacher_id'];
    }
} catch (Exception $e) {}

// بيانات الرسم البياني حسب المادة
$subjectLabels = [];
$subjectScores = [];
try {
    $stmt = $db->prepare("
        SELECT sub.name_ar, AVG(e.combined_score) AS avg_score
        FROM subject_teachers st
        JOIN subjects sub ON st.subject_id = sub.id
        JOIN evaluations e ON e.teacher_id = st.teacher_id AND e.is_active = 1
        WHERE st.school_id = ? AND e.eval_type = 'teaching_summary' AND e.academic_year_id = ? AND e.semester_id = ?
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_score DESC
    ");
    $stmt->execute([$selectedSchoolId, $selectedAcademicYearId, $selectedSemesterId]);
    while ($row = $stmt->fetch()) {
        $subjectLabels[] = $row['name_ar'];
        $subjectScores[] = round((float)$row['avg_score'], 1);
    }
} catch (Exception $e) {}

// توليد التوصيات التلقائية
$recommendations = [];
if ($schoolSelfAvg < $groupSelfAvg) {
    $recommendations[] = "بُعد 'التقييم الذاتي' في المدرسة ($schoolSelfAvg) أقل من متوسط المجموعة ($groupSelfAvg). يُنصح بتنظيم ورشة عمل حول أهمية التقييم الذاتي والتأمل المهني.";
}
if ($schoolPeerAvg < $groupPeerAvg && $schoolPeerAvg > 0) {
    $recommendations[] = "بُعد 'تقييم الأقران' في المدرسة ($schoolPeerAvg) أقل من متوسط المجموعة ($groupPeerAvg). يُنصح بتعزيز ثقافة التغذية الراجعة بين الأقران.";
}
if ($schoolAdminAvg < $groupAdminAvg && $schoolAdminAvg > 0) {
    $recommendations[] = "بُعد 'التقييم الإشرافي' في المدرسة ($schoolAdminAvg) أقل من متوسط المجموعة ($groupAdminAvg). يُنصح بمراجعة معايير التقييم الإشرافي.";
}

$lowTeachers = array_filter($teacherScores, function($t) { return $t['combined'] !== null && $t['combined'] < 60; });
if (count($lowTeachers) >= 3) {
    $recommendations[] = count($lowTeachers) . " معلمين تقييماتهم أقل من 60%. يُنصح بإنشاء خطط تطوير فردية ومتابعة مكثفة.";
}

if (empty($teacherScores) || empty(array_filter($teacherScores, function($t) { return $t['combined'] !== null; }))) {
    $recommendations[] = "لم يتم إكمال تقييمات المعلمين بعد. يُنصح بالبدء بعملية التقييم الذاتي وتقييم الأقران والتقييم الإشرافي.";
}

// متوسط سنوي للمدرسة (عند semester_id=0)
$schoolYearAvg = null;
if ($selectedSemesterId > 0) {
    $schoolYearAvg = $schoolAvgScore;
}

$needsDevelopment = $schoolAvgScore !== null && $schoolAvgScore < 60;
?>

<div class="section">
    <!-- شريط الفلاتر -->
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-inline" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <?php if ($isGroupAdmin): ?>
                <input type="hidden" name="id" value="<?= $selectedSchoolId ?>">
                <div class="form-group" style="min-width:200px;">
                    <label class="form-label" style="font-size:12px;">المدرسة</label>
                    <select name="id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSchoolId ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                <?php if ($isGroupAdmin): ?>
                <a href="teaching_dashboard.php?academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" class="btn btn-light btn-sm" style="margin-bottom:1px;">↩ عودة إلى المجموعة</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- رأس المدرسة -->
    <div class="card mb-6">
        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <h2 style="margin:0;">🏫 <?= e($schoolName) ?></h2>
                <?php if ($schoolCode): ?>
                <span style="font-size:13px;color:var(--text-muted);"><?= e($schoolCode) ?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);">عدد المعلمين</div>
                    <div style="font-size:24px;font-weight:bold;"><?= $schoolStats['teachers'] ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);">الطلاب</div>
                    <div style="font-size:24px;font-weight:bold;"><?= $schoolStats['students'] ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);;">الشعب</div>
                    <div style="font-size:24px;font-weight:bold;"><?= $schoolStats['classes'] ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);">متوسط التقييم</div>
                    <div style="font-size:24px;font-weight:bold;color:<?= $needsDevelopment ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $schoolAvgScore !== null ? $schoolAvgScore . '%' : '—' ?>
                    </div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:14px;color:var(--text-secondary);">المعدّل سياقياً</div>
                    <div style="font-size:24px;font-weight:bold;color:var(--info);">
                        <?= $adjustedScore !== null ? number_format($adjustedScore, 1) . '%' : '—' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($needsDevelopment): ?>
    <div class="alert alert-danger mb-6">
        <strong>⚠️ متوسط أداء المعلمين أقل من 60%!</strong>
        يُرجى التوجه إلى <a href="development_plan.php?school_id=<?= $selectedSchoolId ?>" style="font-weight:bold;">خطة تطوير المعلم</a> لاتخاذ الإجراءات اللازمة.
    </div>
    <?php endif; ?>

    <!-- المخططات البيانية -->
    <div class="grid grid-2 mb-6" style="gap:16px;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 رادار المقارنة</h3></div>
            <div class="card-body"><canvas id="teachingRadarChart" height="250"></canvas></div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 الأداء حسب المادة</h3></div>
            <div class="card-body">
                <?php if (empty($subjectLabels)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px;">لا توجد بيانات كافية حسب المادة.</div>
                <?php else: ?>
                <canvas id="teachingSubjectChart" height="250"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- جدول المعلمين -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">👨‍🏫 ترتيب المعلمين حسب الأداء</h3>
        </div>
        <div class="card-body">
            <?php if (empty($teacherScores) || empty(array_filter($teacherScores, function($t) { return $t['combined'] !== null; }))): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <p>لم يتم إكمال تقييمات المعلمين بعد.</p>
                <a href="../teaching/admin_index.php" class="btn btn-primary btn-sm">بدء التقييم</a>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المعلم</th>
                            <th>ذاتي (20%)</th>
                            <th>أقران (30%)</th>
                            <th>إشرافي (50%)</th>
                            <th>المُجمّع</th>
                            <th>الاتجاه</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($teacherScores as $tid => $ts): ?>
                        <?php if ($ts['combined'] === null) continue; ?>
                        <?php
                            $prevScore = $teacherTrends[$tid] ?? null;
                            $trendText = '—';
                            $trendClass = '';
                            if ($prevScore !== null && $prevScore > 0) {
                                $diff = $ts['combined'] - $prevScore;
                                $pct = ($diff / $prevScore) * 100;
                                if ($pct > 1) { $trendText = '▲'; $trendClass = 'trend-up'; }
                                elseif ($pct < -1) { $trendText = '▼'; $trendClass = 'trend-down'; }
                                else { $trendText = '→'; $trendClass = 'trend-neutral'; }
                            }

                            $hasActivePlan = in_array($tid, $activePlans);
                            $needsPlanBtn = $ts['needs_plan'] && !$hasActivePlan;
                        ?>
                        <tr style="<?= $ts['needs_plan'] ? 'background:rgba(220,53,69,0.03);' : '' ?>">
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><strong><a href="teaching_teacher.php?id=<?= $tid ?>&school_id=<?= $selectedSchoolId ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" style="text-decoration:none;color:inherit;"><?= e($ts['name']) ?></a></strong></td>
                            <td><?= $ts['self'] !== null ? $ts['self'] . '%' : '-' ?></td>
                            <td><?= $ts['peer'] !== null ? $ts['peer'] . '%' : '-' ?></td>
                            <td><?= $ts['admin'] !== null ? $ts['admin'] . '%' : '-' ?></td>
                            <td>
                                <strong style="color:<?= $ts['needs_plan'] ? 'var(--danger)' : 'var(--success)' ?>;">
                                    <?= $ts['combined'] ?>%
                                </strong>
                            </td>
                            <td><span class="<?= $trendClass ?>"><?= $trendText ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <?php if ($ts['needs_plan']): ?>
                                    <span style="background:var(--danger);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">⚠️ ضعيف</span>
                                    <?php else: ?>
                                    <span style="background:var(--success);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">✅ مقبول</span>
                                    <?php endif; ?>
                                    <?php if ($needsPlanBtn): ?>
                                    <a href="development_plan.php?school_id=<?= $selectedSchoolId ?>&teacher_id=<?= $tid ?>" class="btn btn-sm" style="background:var(--danger);color:white;font-size:11px;padding:2px 8px;text-decoration:none;border-radius:var(--radius-full);">🎯 خطة تطوير</a>
                                    <?php elseif ($ts['needs_plan']): ?>
                                    <span style="font-size:11px;color:var(--success);">📋 خطة موجودة</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- التوصيات التلقائية -->
    <?php if (!empty($recommendations)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">💡 التوصيات</h3>
        </div>
        <div class="card-body">
            <ul style="padding-right:20px;margin:0;">
                <?php foreach ($recommendations as $rec): ?>
                <li style="margin-bottom:8px;line-height:1.6;color:var(--text-secondary);font-size:14px;"><?= e($rec) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Radar Chart
    const radarCtx = document.getElementById('teachingRadarChart');
    if (radarCtx) {
        new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: ['ذاتي', 'أقران', 'إشرافي'],
                datasets: [
                    {
                        label: 'المدرسة',
                        data: [<?= $schoolSelfAvg ?>, <?= $schoolPeerAvg ?>, <?= $schoolAdminAvg ?>],
                        backgroundColor: 'rgba(26,86,50,0.2)',
                        borderColor: '#1a5632',
                        pointBackgroundColor: '#1a5632',
                        pointRadius: 5,
                    },
                    {
                        label: 'متوسط المجموعة',
                        data: [<?= $groupSelfAvg ?>, <?= $groupPeerAvg ?>, <?= $groupAdminAvg ?>],
                        backgroundColor: 'rgba(200,169,81,0.2)',
                        borderColor: '#c8a951',
                        borderDash: [5, 5],
                        pointBackgroundColor: '#c8a951',
                        pointRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Tajawal' }, usePointStyle: true } }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { font: { family: 'Tajawal' }, stepSize: 20 },
                        pointLabels: { font: { family: 'Tajawal', size: 13 } }
                    }
                }
            }
        });
    }

    // Subject Bar Chart
    const subCtx = document.getElementById('teachingSubjectChart');
    if (subCtx) {
        new Chart(subCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($subjectLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: 'متوسط التقييم',
                    data: <?= json_encode($subjectScores) ?>,
                    backgroundColor: 'rgba(26,86,50,0.7)',
                    borderColor: '#1a5632',
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { font: { family: 'Tajawal' } } },
                    y: { ticks: { font: { family: 'Tajawal' } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
