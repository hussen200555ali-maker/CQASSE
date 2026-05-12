<?php
/**
 * ============================================================
 * EQAS - تقييم أداء المعلمين
 * نظام التقييم الشامل - Teaching Evaluation Summary
 * ============================================================
 * عرض مُجمّع لتقييمات المعلمين:
 * - ذاتي 20% + أقران 30% + إدارة 50%
 * - مقارنة بين الأنواع
 * - تفعيل خطة تطوير إذا كانت النتيجة < 60%
 * - ترتيب المعلمين داخل المدرسة
 * ============================================================
 */

$pageTitle = 'تقييم أداء المعلمين';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'تقييم المعلمين' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// أوزان التقييم
$evalWeights = ['teaching_self' => 0.20, 'teaching_peer' => 0.30, 'teaching_admin' => 0.50];
$evalLabels = [
    'teaching_self' => ['label' => 'التقييم الذاتي', 'weight' => '20%', 'icon' => '🪞', 'color' => 'var(--info)'],
    'teaching_peer' => ['label' => 'تقييم الأقران', 'weight' => '30%', 'icon' => '👥', 'color' => 'var(--warning)'],
    'teaching_admin' => ['label' => 'تقييم الإدارة', 'weight' => '50%', 'icon' => '👔', 'color' => 'var(--primary)'],
];

// جلب معلمي المدرسة
$teachers = $db->query("
    SELECT u.id, u.full_name_ar
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'teacher' AND u.is_active = 1 AND u.school_id = {$schoolId}
    ORDER BY u.full_name_ar
")->fetchAll();

// جلب درجات التقييم لكل معلم
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
            $stmt->execute([$formKey, $teacher['id'], $schoolId]);
            $row = $stmt->fetch();
            $scores[$formKey] = $row && $row['avg_score'] !== null ? round($row['avg_score'], 1) : null;
        } catch (Exception $e) {
            $scores[$formKey] = null;
        }
    }

    // حساب الدرجة المُجمّعة
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
        'needs_plan' => $combinedScore !== null && $combinedScore < 60
    ];
}

// ترتيب المعلمين حسب الدرجة المُجمّعة
uasort($teacherScores, function($a, $b) {
    return ($b['combined'] ?? 0) <=> ($a['combined'] ?? 0);
});

// المتوسط العام
$schoolAvg = null;
$validScores = array_filter(array_column($teacherScores, 'combined'), function($v) { return $v !== null; });
if (!empty($validScores)) {
    $schoolAvg = round(array_sum($validScores) / count($validScores), 1);
}

$needsDevelopment = $schoolAvg !== null && $schoolAvg < 60;
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h2 class="card-title">👨‍🏫 تقييم أداء المعلمين</h2>
        </div>
        <div class="card-body">
            <?php if ($needsDevelopment): ?>
            <div class="alert alert-danger">
                <strong>⚠️ متوسط أداء المعلمين أقل من 60%!</strong>
                يُرجى إنشاء <a href="development_plan.php" style="font-weight:bold;">خطة تطوير المعلم</a>.
            </div>
            <?php endif; ?>

            <!-- شرح الأوزان -->
            <div class="grid grid-3 mb-6" style="gap:16px;">
                <?php foreach ($evalLabels as $key => $info): ?>
                <div class="stat-card" style="border-top:3px solid <?= $info['color'] ?>;">
                    <div class="stat-icon"><?= $info['icon'] ?></div>
                    <div class="stat-value" style="font-size:20px;color:<?= $info['color'] ?>;"><?= $info['weight'] ?></div>
                    <div class="stat-label"><?= $info['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- المتوسط العام للمدرسة -->
            <?php if ($schoolAvg !== null): ?>
            <div class="card mb-6" style="border:2px solid <?= $schoolAvg < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                <div class="card-body" style="text-align:center;padding:24px;">
                    <div style="font-size:14px;color:var(--text-secondary);">المتوسط المُجمّع للمدرسة</div>
                    <div style="font-size:48px;font-weight:bold;color:<?= $schoolAvg < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $schoolAvg ?>%
                    </div>
                    <div style="font-size:14px;color:<?= $schoolAvg < 60 ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $schoolAvg >= 80 ? 'ممتاز' : ($schoolAvg >= 60 ? 'مقبول' : 'يحتاج تطوير') ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- جدول ترتيب المعلمين -->
            <h4 style="margin-bottom:12px;">🏆 ترتيب المعلمين حسب الأداء</h4>
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
                            <th>إدارة (50%)</th>
                            <th>المُجمّع</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($teacherScores as $tid => $ts): ?>
                        <?php if ($ts['combined'] === null) continue; ?>
                        <tr style="<?= $ts['needs_plan'] ? 'background:rgba(220,53,69,0.03);' : '' ?>">
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><strong><?= e($ts['name']) ?></strong></td>
                            <td><?= $ts['self'] !== null ? $ts['self'] . '%' : '-' ?></td>
                            <td><?= $ts['peer'] !== null ? $ts['peer'] . '%' : '-' ?></td>
                            <td><?= $ts['admin'] !== null ? $ts['admin'] . '%' : '-' ?></td>
                            <td>
                                <strong style="color:<?= $ts['needs_plan'] ? 'var(--danger)' : 'var(--success)' ?>;">
                                    <?= $ts['combined'] ?>%
                                </strong>
                            </td>
                            <td>
                                <?php if ($ts['needs_plan']): ?>
                                <span style="background:var(--danger);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">
                                    ⚠️ يحتاج تطوير
                                </span>
                                <?php else: ?>
                                <span style="background:var(--success);color:white;padding:2px 8px;border-radius:var(--radius-full);font-size:11px;">
                                    ✅ مقبول
                                </span>
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

    <!-- رسم بياني للمقارنة -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📊 مقارنة أنواع التقييم</h3>
        </div>
        <div class="card-body">
            <canvas id="teaching-chart" height="200"></canvas>
        </div>
    </div>
</div>

<?php
// بيانات الرسم البياني
$chartLabels = [];
$chartSelf = [];
$chartPeer = [];
$chartAdmin = [];
foreach ($teacherScores as $ts) {
    if ($ts['combined'] !== null) {
        $chartLabels[] = $ts['name'];
        $chartSelf[] = $ts['self'];
        $chartPeer[] = $ts['peer'];
        $chartAdmin[] = $ts['admin'];
    }
}

$pageJs = "
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('teaching-chart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: " . json_encode($chartLabels, JSON_UNESCAPED_UNICODE) . ",
            datasets: [
                { label: 'ذاتي (20%)', data: " . json_encode($chartSelf) . ", backgroundColor: 'rgba(23,162,184,0.7)' },
                { label: 'أقران (30%)', data: " . json_encode($chartPeer) . ", backgroundColor: 'rgba(255,193,7,0.7)' },
                { label: 'إدارة (50%)', data: " . json_encode($chartAdmin) . ", backgroundColor: 'rgba(26,86,50,0.7)' }
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, max: 100 } },
            plugins: { legend: { position: 'top' } }
        }
    });
});
";
require_once __DIR__ . '/../../includes/footer.php';
