<?php
/**
 * ============================================================
 * EQAS - الترفيع المشروط
 * نظام التقييم الشامل - Conditional Promotion
 * ============================================================
 */

$pageTitle = 'الترفيع المشروط';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'ترفيع مشروط' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$conditionalStudents = $promoEngine->getConditionalStudents(
    $userRole !== 'group_admin' ? $userSchoolId : null
);

$decisionLabels = $promoEngine->getDecisionLabels();
$thresholds = $promoEngine->getSettings();

// معالجة إنشاء خطة تحسين
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_plan'])) {
    if (!verifyCsrf()) { setFlash('خطأ أمني.', 'danger'); }
    else {
        $result = $promoEngine->createImprovementPlan(
            (int)$_POST['student_id'],
            (int)$_POST['academic_year_id'],
            (int)$_POST['semester_id'],
            [
                'objectives' => trim($_POST['objectives'] ?? ''),
                'actions' => trim($_POST['actions'] ?? ''),
                'responsible' => trim($_POST['responsible'] ?? ''),
                'deadline' => trim($_POST['deadline'] ?? ''),
            ]
        );
        if ($result) {
            setFlash('تم إنشاء خطة التحسين بنجاح.', 'success');
        } else {
            setFlash('حدث خطأ أثناء إنشاء الخطة.', 'danger');
        }
        redirect('conditional.php');
    }
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">⚠️ الترفيع المشروط</h3>
        </div>
        <div class="card-body">
            <div class="mb-4 p-3" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-md); border-right:4px solid var(--warning);">
                <p style="margin:0;"><strong>⚠️ الترفيع المشروط:</strong> الطلاب الذين أخفقوا في معيار واحد فقط من المعايير الثلاثة. يتطلب وضع خطة تحسين ومراقبة مستمرة.</p>
            </div>
        </div>
    </div>

    <?php if (empty($conditionalStudents)): ?>
    <div class="card">
        <div class="card-body text-center" style="padding:40px;">
            <div style="font-size:48px;">✅</div>
            <h3>لا يوجد طلاب مشروطون</h3>
            <p class="text-secondary">جميع الطلاب إما مرفعون أو معيدون أو قيد المراجعة.</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($conditionalStudents as $student): ?>
    <div class="card mb-4" style="border-right:4px solid var(--warning);">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:24px;">⚠️</span>
                <div>
                    <strong><?= e($student['student_name']) ?></strong>
                    <span class="text-secondary" style="font-size:12px;">(<?= e($student['student_number']) ?>)</span>
                </div>
            </div>
            <span style="padding:4px 12px; border-radius:var(--radius-full); background:#fff3cd; color:#856404; font-weight:600; font-size:12px;">ترفيع مشروط</span>
        </div>
        <div class="card-body">
            <div class="grid grid-3 gap-3 mb-4">
                <div class="p-3 text-center" style="background:<?= $student['academic_pass'] ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)' ?>; border-radius:var(--radius-md);">
                    <div style="font-size:12px;" class="text-secondary">الأكاديمي</div>
                    <div style="font-size:20px; font-weight:bold; color:<?= $student['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $student['academic_pass'] ? '✅' : '❌' ?>
                        <?= number_format($student['academic_score'], 1) ?>%
                    </div>
                    <div style="font-size:11px;" class="text-muted">الحد: <?= $thresholds['academic_pass'] ?>%</div>
                </div>
                <div class="p-3 text-center" style="background:<?= $student['behavior_pass'] ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)' ?>; border-radius:var(--radius-md);">
                    <div style="font-size:12px;" class="text-secondary">السلوك</div>
                    <div style="font-size:20px; font-weight:bold; color:<?= $student['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $student['behavior_pass'] ? '✅' : '❌' ?>
                        <?= number_format($student['behavior_score'], 1) ?>%
                    </div>
                    <div style="font-size:11px;" class="text-muted">الحد: <?= $thresholds['behavior_pass'] ?>%</div>
                </div>
                <div class="p-3 text-center" style="background:<?= $student['attendance_pass'] ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)' ?>; border-radius:var(--radius-md);">
                    <div style="font-size:12px;" class="text-secondary">الحضور</div>
                    <div style="font-size:20px; font-weight:bold; color:<?= $student['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $student['attendance_pass'] ? '✅' : '❌' ?>
                        <?= number_format($student['attendance_score'], 1) ?>%
                    </div>
                    <div style="font-size:11px;" class="text-muted">الحد: <?= $thresholds['attendance_pass'] ?>%</div>
                </div>
            </div>

            <!-- المعيار الفاشل -->
            <div class="mb-4 p-3" style="background:rgba(220,53,69,0.08); border-radius:var(--radius-md);">
                <strong style="color:var(--danger);">المعيار الفاشل:</strong>
                <?php foreach ($student['failed_criteria'] as $fc): ?>
                <span style="margin-right:8px; padding:2px 8px; border-radius:var(--radius-full); background:rgba(220,53,69,0.15); color:var(--danger); font-size:12px;">
                    <?= e($fc['label']) ?> (<?= number_format($fc['score'], 1) ?>% / <?= $fc['threshold'] ?>%)
                </span>
                <?php endforeach; ?>
            </div>

            <!-- خطة التحسين -->
            <?php if (empty($student['notes'])): ?>
            <details>
                <summary style="cursor:pointer; color:var(--primary); font-weight:600;">📝 إنشاء خطة تحسين</summary>
                <form method="POST" class="mt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                    <input type="hidden" name="academic_year_id" value="<?= $student['academic_year_id'] ?>">
                    <input type="hidden" name="semester_id" value="<?= $student['semester_id'] ?>">
                    <div class="form-group">
                        <label class="form-label">الأهداف</label>
                        <textarea name="objectives" class="form-control" rows="2" required placeholder="ما هي الأهداف المطلوبة لتحسين المعيار الفاشل؟"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الإجراءات المطلوبة</label>
                        <textarea name="actions" class="form-control" rows="2" required placeholder="ما هي الخطوات العملية؟"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">المسؤول</label>
                            <input type="text" name="responsible" class="form-control" required placeholder="اسم المسؤول عن المتابعة">
                        </div>
                        <div class="form-group">
                            <label class="form-label">الموعد النهائي</label>
                            <input type="date" name="deadline" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" name="create_plan" value="1" class="btn btn-primary">💾 حفظ خطة التحسين</button>
                </form>
            </details>
            <?php else: ?>
            <div class="p-3" style="background:rgba(40,167,69,0.08); border-radius:var(--radius-md);">
                <strong style="color:var(--success);">✅ توجد خطة تحسين</strong>
                <pre style="white-space:pre-wrap; margin:8px 0 0; font-size:13px; color:var(--text-secondary);"><?= e($student['notes']) ?></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
