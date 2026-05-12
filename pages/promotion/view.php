<?php
/**
 * ============================================================
 * EQAS - عرض قرار الترفيع
 * نظام التقييم الشامل - View Promotion Decision
 * ============================================================
 */

$pageTitle = 'تفاصيل قرار الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'تفاصيل القرار' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();
$thresholds = $promoEngine->getSettings();

$decisionId = (int)($_GET['id'] ?? 0);
if (!$decisionId) {
    setFlash('معرف القرار غير صالح.', 'danger');
    redirect('index.php');
}

$decision = $promoEngine->getDecisionById($decisionId);
if (!$decision) {
    setFlash('القرار غير موجود.', 'danger');
    redirect('index.php');
}

$dc = $decisionColors[$decision['decision']] ?? $decisionColors['under_review'];
$history = $promoEngine->getStudentHistory($decision['student_id']);
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>;">
            <div class="flex items-center gap-4">
                <span style="font-size:36px; font-weight:bold;"><?= $decision['decision'] === 'promoted' ? '🎓' : ($decision['decision'] === 'conditional' ? '⚠️' : ($decision['decision'] === 'repeated' ? '🔁' : '❓')) ?></span>
                <div>
                    <h2 style="margin:0; color:<?= $dc['text'] ?>;"><?= $decisionLabels[$decision['decision']] ?></h2>
                    <span style="opacity:0.8;"><?= e($decision['student_name']) ?> - <?= e($decision['student_number']) ?></span>
                </div>
            </div>
            <a href="index.php" class="btn btn-light btn-sm">↩️ العودة</a>
        </div>
        <div class="card-body">
            <!-- المعايير الثلاثة -->
            <h4 class="mb-4" style="border-bottom:2px solid var(--primary); padding-bottom:8px;">📋 المعايير الثلاثة</h4>
            <div class="grid grid-3 gap-4 mb-6">
                <!-- الأكاديمي -->
                <div class="p-4" style="border:2px solid <?= $decision['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>; border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:24px; margin-bottom:8px;"><?= $decision['academic_pass'] ? '✅' : '❌' ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">الأداء الأكاديمي</div>
                    <div style="font-size:24px; font-weight:bold; color:<?= $decision['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $decision['academic_score'] !== null ? number_format($decision['academic_score'], 1) . '%' : 'غير متوفر' ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">الحد الأدنى: <?= $thresholds['academic_pass'] ?>%</div>
                </div>
                <!-- السلوك -->
                <div class="p-4" style="border:2px solid <?= $decision['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>; border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:24px; margin-bottom:8px;"><?= $decision['behavior_pass'] ? '✅' : '❌' ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">السلوك</div>
                    <div style="font-size:24px; font-weight:bold; color:<?= $decision['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $decision['behavior_score'] !== null ? number_format($decision['behavior_score'], 1) . '%' : 'غير متوفر' ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">الحد الأدنى: <?= $thresholds['behavior_pass'] ?>%</div>
                </div>
                <!-- الحضور -->
                <div class="p-4" style="border:2px solid <?= $decision['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>; border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:24px; margin-bottom:8px;"><?= $decision['attendance_pass'] ? '✅' : '❌' ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">الحضور</div>
                    <div style="font-size:24px; font-weight:bold; color:<?= $decision['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $decision['attendance_score'] !== null ? number_format($decision['attendance_score'], 1) . '%' : 'غير متوفر' ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">الحد الأدنى: <?= $thresholds['attendance_pass'] ?>%</div>
                </div>
            </div>

            <!-- تفاصيل إضافية -->
            <div class="grid grid-2 gap-6">
                <div>
                    <h5 class="mb-3">📋 معلومات الطالب</h5>
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">الاسم:</span>
                            <strong><?= e($decision['student_name']) ?></strong>
                        </div>
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">الرقم:</span>
                            <span><?= e($decision['student_number']) ?></span>
                        </div>
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">المدرسة:</span>
                            <span><?= e($decision['school_name'] ?? '-') ?></span>
                        </div>
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">الصف:</span>
                            <span><?= e($decision['grade_name'] ?? $decision['class_name'] ?? '-') ?></span>
                        </div>
                    </div>
                </div>
                <div>
                    <h5 class="mb-3">📝 تفاصيل القرار</h5>
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">القرار:</span>
                            <span style="padding:2px 10px; border-radius:var(--radius-full); background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>; font-weight:600;"><?= $decisionLabels[$decision['decision']] ?></span>
                        </div>
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">اتخذ القرار:</span>
                            <span><?= e($decision['decider_name'] ?? 'النظام') ?></span>
                        </div>
                        <div class="flex justify-between p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">تاريخ القرار:</span>
                            <span><?= formatDateAr($decision['decided_at'], 'medium') ?></span>
                        </div>
                        <?php if ($decision['notes']): ?>
                        <div class="p-2" style="background:var(--background); border-radius:var(--radius-sm);">
                            <span class="text-secondary">ملاحظات:</span>
                            <p style="margin:4px 0 0;"><?= e($decision['notes']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- التاريخ -->
    <?php if (!empty($history)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📜 تاريخ قرارات الترفيع</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr><th>السنة</th><th>الفصل</th><th>الأكاديمي</th><th>السلوك</th><th>الحضور</th><th>القرار</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): 
                            $hc = $decisionColors[$h['decision']] ?? $decisionColors['under_review'];
                        ?>
                        <tr>
                            <td><?= e($h['year_name']) ?></td>
                            <td><?= e($h['semester_name']) ?></td>
                            <td><?= $h['academic_score'] !== null ? number_format($h['academic_score'], 1) : '-' ?></td>
                            <td><?= $h['behavior_score'] !== null ? number_format($h['behavior_score'], 1) : '-' ?></td>
                            <td><?= $h['attendance_score'] !== null ? number_format($h['attendance_score'], 1) : '-' ?></td>
                            <td><span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $hc['bg'] ?>; color:<?= $hc['text'] ?>;"><?= $decisionLabels[$h['decision']] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
