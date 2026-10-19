<?php
$pageTitle = 'لوحة تقييمات المعلم';
$breadcrumb = ['التقييمات' => 'index.php', 'لوحة تقييمات المعلم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('teacher')) {
    setFlash('هذه الصفحة للمعلمين فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$userId = $_SESSION['user']['id'];
$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// فصول المعلم المسندة
$classIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
$hasClasses = !empty($classIds);
$classPh = $hasClasses ? implode(',', array_fill(0, count($classIds), '?')) : '0';

// ============================================================
// الإحصائيات الأساسية (4 KPI)
// ============================================================

// 1. عدد الطلاب في فصول المعلم
$studentCount = 0;
if ($hasClasses) {
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se WHERE se.class_id IN ($classPh) AND se.is_active = 1 AND se.status = 'enrolled'");
        $stmt->execute($classIds);
        $studentCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}

// 2. عدد الفصول
$classCount = count($classIds);

// 3. درجة تقييم الأداء (المجمّع)
$evalWeights = ['teaching_self' => 0.20, 'teaching_peer' => 0.30, 'teaching_admin' => 0.50];
$scores = ['self' => null, 'peer' => null, 'admin' => null, 'combined' => null];
try {
    foreach (['teaching_self' => 'self', 'teaching_peer' => 'peer', 'teaching_admin' => 'admin'] as $formKey => $key) {
        $stmt = $db->prepare("SELECT AVG(ar.numeric_value) FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = ? AND ar.teacher_id = ? AND ar.is_active = 1");
        $stmt->execute([$formKey, $userId]);
        $v = $stmt->fetchColumn();
        if ($v !== null && $v !== false) $scores[$key] = round((float)$v, 1);
    }
    $combined = 0; $totalW = 0;
    foreach (['self' => 'teaching_self', 'peer' => 'teaching_peer', 'admin' => 'teaching_admin'] as $key => $fk) {
        if ($scores[$key] !== null) { $combined += $scores[$key] * $evalWeights[$fk]; $totalW += $evalWeights[$fk]; }
    }
    $scores['combined'] = $totalW > 0 ? round($combined / $totalW, 1) : null;
} catch (Exception $e) {}

// 4. التقييمات المعلقة
$pendingCount = 0;
try {
    $stmt = $db->prepare("SELECT 1 FROM assessment_forms af WHERE af.form_key = 'teaching_self' AND af.is_active = 1 AND NOT EXISTS (SELECT 1 FROM assessment_responses ar WHERE ar.form_id = af.id AND ar.teacher_id = ? AND ar.is_active = 1) LIMIT 1");
    $stmt->execute([$userId]);
    $pendingCount += $stmt->fetchColumn() ? 1 : 0;
} catch (Exception $e) {}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'teaching_peer' AND ar.teacher_id = ? AND ar.is_active = 1");
    $stmt->execute([$userId]);
    $myPeerEvals = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1 AND u.id != ?");
    $stmt->execute([$schoolId, $userId]);
    $totalPeers = (int)$stmt->fetchColumn();
    if ($totalPeers > $myPeerEvals) $pendingCount += ($totalPeers - $myPeerEvals);
} catch (Exception $e) {}

// ============================================================
// إحصائيات إضافية للرسوم البيانية
// ============================================================

// توزيع التقييمات حسب النوع
$evalDistribution = ['self' => 0, 'peer' => 0, 'admin' => 0];
try {
    foreach (['teaching_self' => 'self', 'teaching_peer' => 'peer', 'teaching_admin' => 'admin'] as $formKey => $key) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = ? AND ar.teacher_id = ? AND ar.is_active = 1");
        $stmt->execute([$formKey, $userId]);
        $evalDistribution[$key] = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}

// إحصائيات السلوك لطلاب فصولي
$behaviorStats = ['total' => 0, 'avg' => null];
if ($hasClasses) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt, AVG(e.combined_score) AS avg_score FROM evaluations e WHERE e.eval_type = 'behavior_score' AND e.class_id IN ($classPh) AND e.is_active = 1");
        $stmt->execute($classIds);
        $row = $stmt->fetch();
        if ($row) {
            $behaviorStats['total'] = (int)$row['cnt'];
            $behaviorStats['avg'] = $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
        }
    } catch (Exception $e) {}
}

// إحصائيات المهارات لطلاب فصولي
$skillsStats = ['total' => 0, 'avg' => null];
if ($hasClasses) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt, AVG(e.combined_score) AS avg_score FROM evaluations e WHERE e.eval_type = 'skills_checklist' AND e.class_id IN ($classPh) AND e.is_active = 1");
        $stmt->execute($classIds);
        $row = $stmt->fetch();
        if ($row) {
            $skillsStats['total'] = (int)$row['cnt'];
            $skillsStats['avg'] = $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
        }
    } catch (Exception $e) {}
}

// الطلاب بحاجة متابعة (درجات منخفضة < 60%)
$needyStudents = [];
if ($hasClasses) {
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.full_name_ar, s.student_number, c.name_ar AS class_name,
                   ROUND(AVG(g.percentage), 1) AS avg_pct,
                   (SELECT COUNT(*) FROM attendance a2 WHERE a2.student_id = s.id AND a2.status = 'absent' AND a2.academic_year_id = ?) AS absences
            FROM students s
            JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1 AND se.status = 'enrolled'
            JOIN classes c ON se.class_id = c.id
            LEFT JOIN grades g ON g.student_id = s.id AND g.is_active = 1
            WHERE se.class_id IN ($classPh)
            GROUP BY s.id
            HAVING avg_pct < 60 OR avg_pct IS NULL
            ORDER BY avg_pct ASC
            LIMIT 10
        ");
        $stmt->execute(array_merge([$academicYearId], $classIds));
        $needyStudents = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// آخر النشاطات
$recentActivities = [];
try {
    $stmt = $db->prepare("
        SELECT al.action, al.description, al.created_at, al.entity_type
        FROM activity_log al
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) {}

// التقييمات التي قمت بها (behavior/skills)
$myEvaluationsCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM evaluations WHERE evaluated_by = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $myEvaluationsCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// متوسط حضور فصولي
$avgAttendance = null;
if ($hasClasses) {
    try {
        $stmt = $db->prepare("
            SELECT ROUND(AVG(ar.attendance_rate), 1)
            FROM v_attendance_summary ar
            WHERE ar.class_id IN ($classPh) AND ar.academic_year_id = ?
        ");
        $stmt->execute(array_merge($classIds, [$academicYearId]));
        $avgAttendance = $stmt->fetchColumn();
    } catch (Exception $e) {}
}
?>
<div class="section">
    <!-- بطاقات KPI -->
    <div class="grid grid-4" id="teacher-dashboard">
        <div class="card" style="border-top:4px solid var(--primary);">
            <div class="card-body" style="text-align:center;">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(26,86,50,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <i class="fas fa-user-graduate" style="font-size:22px;color:var(--primary);"></i>
                </div>
                <div style="font-size:13px;color:var(--text-secondary);">الطلاب</div>
                <div style="font-size:32px;font-weight:700;color:var(--primary);"><?= $studentCount ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #17a2b8;">
            <div class="card-body" style="text-align:center;">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(23,162,184,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <i class="fas fa-chart-line" style="font-size:22px;color:#17a2b8;"></i>
                </div>
                <div style="font-size:13px;color:var(--text-secondary);">درجة الأداء</div>
                <div style="font-size:32px;font-weight:700;color:#17a2b8;"><?= $scores['combined'] !== null ? number_format($scores['combined'], 1) . '%' : '—' ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #ffc107;">
            <div class="card-body" style="text-align:center;">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(255,193,7,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <i class="fas fa-clock" style="font-size:22px;color:#ffc107;"></i>
                </div>
                <div style="font-size:13px;color:var(--text-secondary);;">تقييمات معلقة</div>
                <div style="font-size:32px;font-weight:700;color:#ffc107;"><?= $pendingCount ?></div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid #28a745;">
            <div class="card-body" style="text-align:center;">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(40,167,69,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <i class="fas fa-school" style="font-size:22px;color:#28a745;"></i>
                </div>
                <div style="font-size:13px;color:var(--text-secondary);">الفصول</div>
                <div style="font-size:32px;font-weight:700;color:#28a745;"><?= $classCount ?></div>
            </div>
        </div>
    </div>
</div>

<!-- تفاصيل الأداء والتقييمات -->
<div class="section">
    <div class="grid grid-2">
        <!-- درجات تقييم التدريس -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 درجات تقييم التدريس</h3></div>
            <div class="card-body">
                <?php
                $dims = [
                    'self' => ['label' => 'تقييم ذاتي', 'color' => '#17a2b8'],
                    'peer' => ['label' => 'تقييم أقران', 'color' => '#6f42c1'],
                    'admin' => ['label' => 'تقييم إشرافي', 'color' => '#e83e8c'],
                ];
                foreach ($dims as $key => $info):
                    $val = $scores[$key];
                ?>
                <div class="mb-3">
                    <div class="flex justify-between items-center mb-1">
                        <span style="font-size:14px;font-weight:500;"><?= $info['label'] ?></span>
                        <span style="font-size:14px;font-weight:600;color:<?= $info['color'] ?>;"><?= $val !== null ? number_format($val, 1) . '%' : '—' ?></span>
                    </div>
                    <div style="height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;">
                        <div style="height:100%;width:<?= $val !== null ? min($val, 100) : 0 ?>%;background:<?= $info['color'] ?>;border-radius:4px;transition:width 0.5s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <hr style="margin:16px 0;border-color:#eee;">
                <div class="flex justify-between items-center">
                    <span style="font-size:16px;font-weight:600;">الدرجة المركّبة</span>
                    <span style="font-size:22px;font-weight:700;color:var(--primary);"><?= $scores['combined'] !== null ? number_format($scores['combined'], 1) . '%' : '—' ?></span>
                </div>
            </div>
        </div>

        <!-- توزيع التقييمات -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 توزيع التقييمات</h3></div>
            <div class="card-body">
                <div style="height:260px;"><canvas id="evalDistChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<!-- إحصائيات إضافية -->
<div class="section">
    <div class="grid grid-3">
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:13px;color:var(--text-secondary);">تقييمات السلوك (طلابي)</div>
            <div style="font-size:28px;font-weight:700;color:#dc3545;"><?= $behaviorStats['total'] ?></div>
            <?php if ($behaviorStats['avg'] !== null): ?>
            <div style="font-size:12px;color:var(--text-muted);">المتوسط: <?= number_format($behaviorStats['avg'], 1) ?>%</div>
            <?php endif; ?>
        </div>
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:13px;color:var(--text-secondary);">تقييمات المهارات (طلابي)</div>
            <div style="font-size:28px;font-weight:700;color:#17a2b8;"><?= $skillsStats['total'] ?></div>
            <?php if ($skillsStats['avg'] !== null): ?>
            <div style="font-size:12px;color:var(--text-muted);">المتوسط: <?= number_format($skillsStats['avg'], 1) ?>%</div>
            <?php endif; ?>
        </div>
        <div class="card" style="text-align:center;padding:16px;">
            <div style="font-size:13px;color:var(--text-secondary);">تقييمات قمت بها</div>
            <div style="font-size:28px;font-weight:700;color:#28a745;"><?= $myEvaluationsCount ?></div>
            <?php if ($avgAttendance !== null): ?>
            <div style="font-size:12px;color:var(--text-muted);">نسبة الحضور: <?= number_format($avgAttendance, 1) ?>%</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- الطلاب بحاجة متابعة + آخر النشاطات -->
<div class="section">
    <div class="grid grid-2">
        <!-- الطلاب بحاجة متابعة -->
        <div class="card">
            <div class="card-header" style="background:rgba(220,53,69,0.08);border-bottom:2px solid #dc3545;">
                <h3 class="card-title" style="color:#dc3545;">⚠️ طلاب بحاجة متابعة</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($needyStudents)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">✅</div>
                    <div class="table-empty-text">لا يوجد طلاب بحاجة متابعة</div>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>المتوسط</th>
                                <th>الغياب</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($needyStudents as $ns): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= e($ns['full_name_ar']) ?></div>
                                    <div style="font-size:11px;color:var(--text-muted);"><?= e($ns['student_number']) ?></div>
                                </td>
                                <td><?= e($ns['class_name']) ?></td>
                                <td>
                                    <span style="font-weight:600;color:<?= ($ns['avg_pct'] ?? 60) < 50 ? '#dc3545' : '#ffc107' ?>;">
                                        <?= $ns['avg_pct'] !== null ? number_format($ns['avg_pct'], 1) . '%' : '—' ?>
                                    </span>
                                </td>
                                <td><?= (int)($ns['absences'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- آخر النشاطات -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">🕐 آخر النشاطات</h3></div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentActivities)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">📋</div>
                    <div class="table-empty-text">لا توجد نشاطات حديثة</div>
                </div>
                <?php else: ?>
                <div style="padding:12px;">
                    <?php foreach ($recentActivities as $act): ?>
                    <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f0f0f0;">
                        <div style="width:32px;height:32px;border-radius:50%;background:rgba(26,86,50,0.08);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-circle" style="font-size:8px;color:var(--primary);"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:500;"><?= e($act['description'] ?? $act['action']) ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= formatDateAr($act['created_at'], 'short') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- إجراءات سريعة -->
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">⚡ إجراءات سريعة</h3></div>
        <div class="card-body">
            <div class="grid grid-auto-lg">
                <a href="<?= baseUrl('pages/behavior/evaluate.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">🎯</div>
                    <div style="font-size:13px;">تقييم سلوكي</div>
                </a>
                <a href="<?= baseUrl('pages/skills/evaluate.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">✅</div>
                    <div style="font-size:13px;">تقييم مهارات</div>
                </a>
                <a href="<?= baseUrl('pages/academic_assessment/grade_entry.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">✏️</div>
                    <div style="font-size:13px;">إدخال درجات</div>
                </a>
                <a href="<?= baseUrl('pages/attendance/daily.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">📋</div>
                    <div style="font-size:13px;">تسجيل حضور</div>
                </a>
                <a href="<?= baseUrl('pages/teaching/self_evaluate.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">👤</div>
                    <div style="font-size:13px;">تقييم ذاتي</div>
                </a>
                <a href="<?= baseUrl('pages/teaching/peer_evaluate.php') ?>" class="btn btn-outline-primary" style="padding:16px;text-align:center;border-radius:var(--radius-md);">
                    <div style="font-size:24px;margin-bottom:4px;">👥</div>
                    <div style="font-size:13px;">تقييم أقران</div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$chartJs = '';
if ($evalDistribution['self'] > 0 || $evalDistribution['peer'] > 0 || $evalDistribution['admin'] > 0):
$chartLabels = json_encode(['تقييم ذاتي', 'تقييم أقران', 'تقييم إشرافي'], JSON_UNESCAPED_UNICODE);
$chartData = json_encode([$evalDistribution['self'], $evalDistribution['peer'], $evalDistribution['admin']]);
$chartColors = json_encode(['#17a2b8', '#6f42c1', '#e83e8c']);
$chartJs = "
    eqasBarChart('evalDistChart', 'التقييمات', $chartLabels, [{
        label: 'عدد',
        data: $chartData,
        backgroundColor: $chartColors,
    }], {indexAxis: 'y', plugins: {legend: {display: false}}});
";
endif;
$pageJs = $chartJs;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
