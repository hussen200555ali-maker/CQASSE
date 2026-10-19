<?php
/**
 * ============================================================
 * EQAS - تقرير المرافق المدرسية
 * نظام التقييم الشامل - Facility Report
 * ============================================================
 * تقييم قائم على قائمة مراجعة مع:
 * - تسجيل حسب الفئات (فصول، مختبرات، مكتبة، ساحة، دورات مياه، أمن وسلامة)
 * - مؤشرات بصرية (ناجح/فاشل لكل فئة)
 * - تفعيل خطة التحسين إذا كان المجموع < 55%
 * ============================================================
 */

$pageTitle = 'تقرير المرافق المدرسية';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'المرافق' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// فئات المرافق
$categories = [
    'classrooms' => ['label' => 'الفصول الدراسية', 'icon' => '🏫', 'items' => [
        'إضاءة كافية', 'تهوية مناسبة', 'أثاث سليم', 'سبورة نظيفة', 'نظافة عامة'
    ]],
    'labs' => ['label' => 'المختبرات', 'icon' => '🔬', 'items' => [
        'معدات كافية', 'سلامة المختبر', 'مواد استهلاكية', 'تهوية خاصة'
    ]],
    'library' => ['label' => 'المكتبة', 'icon' => '📚', 'items' => [
        'كتب كافية', 'مساحة مناسبة', 'تهوية وإضاءة', 'نظام تصنيف'
    ]],
    'playground' => ['label' => 'الساحة', 'icon' => '⚽', 'items' => [
        'مساحة كافية', 'أرضية آمنة', 'معدات رياضية', 'تظليل'
    ]],
    'bathrooms' => ['label' => 'دورات المياه', 'icon' => '🚻', 'items' => [
        'نظافة', 'مياه متوفرة', 'عدد كافٍ', 'خصوصية'
    ]],
    'safety' => ['label' => 'الأمن والسلامة', 'icon' => '🛡️', 'items' => [
        'طفايات حريق', 'مخارج طوارئ', 'إسعافات أولية', 'سور المدرسة'
    ]]
];

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_report') {
            try {
                $db->beginTransaction();
                $scores = $_POST['scores'] ?? [];
                $totalScore = 0;
                $totalItems = 0;

                foreach ($scores as $category => $items) {
                    foreach ($items as $itemIdx => $value) {
                        $totalScore += (int)$value;
                        $totalItems++;
                    }
                }

                $combinedScore = $totalItems > 0 ? round(($totalScore / ($totalItems * 5)) * 100, 1) : 0;

                // حفظ في assessment_responses
                $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'facility_checklist' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1");
                $stmt->execute();
                $form = $stmt->fetch();

                if ($form) {
                    $semesterId = null;
                    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 LIMIT 1");
                    $stmt->execute();
                    $semRow = $stmt->fetch();
                    if ($semRow) $semesterId = $semRow['id'];

                    // حذف التقييمات السابقة
                    $db->prepare("DELETE FROM assessment_responses WHERE form_id = ? AND school_id = ? AND academic_year_id = ?")
                       ->execute([$form['id'], $schoolId, $academicYearId]);

                    $stmt = $db->prepare("INSERT INTO assessment_responses (form_id, item_id, school_id, academic_year_id, semester_id, numeric_value, responded_by, is_active) VALUES (?, 0, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$form['id'], $schoolId, $academicYearId, $semesterId, $combinedScore, $_SESSION['user']['id']]);
                }

                $db->commit();
                logActivity($db, $_SESSION['user']['id'], 'facility_report', "حفظ تقرير المرافق: الدرجة {$combinedScore}%", 'facility', null);

                if ($combinedScore < 55) {
                    setFlash("تم حفظ التقرير. الدرجة: {$combinedScore}% - 🚨 أقل من 55%! يُرجى إنشاء خطة تحسين.", 'warning');
                } else {
                    setFlash("تم حفظ التقرير بنجاح. الدرجة: {$combinedScore}%", 'success');
                }
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('خطأ في الحفظ: ' . $e->getMessage(), 'danger');
            }
            redirect('facility_report.php');
        }
    }
}

// جلب التقييم الحالي
$currentScores = [];
try {
    $stmt = $db->prepare("
        SELECT ar.numeric_value as overall_score
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1
        ORDER BY ar.response_date DESC LIMIT 1
    ");
    $stmt->execute([$schoolId]);
    $currentOverall = $stmt->fetch();
} catch (Exception $e) {
    $currentOverall = null;
}

// حساب درجات الفئات (محاكاة - من البيانات المحفوظة)
$categoryScores = [];
try {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND is_active = 1");
    $stmt->execute(['facility_scores_' . $schoolId]);
    $savedScores = $stmt->fetch();
    if ($savedScores) {
        $categoryScores = json_decode($savedScores['setting_value'], true) ?? [];
    }
} catch (Exception $e) { }
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h2 class="card-title">🏗️ تقرير المرافق المدرسية</h2>
        </div>
        <div class="card-body">
            <?php if (isset($currentOverall) && $currentOverall && $currentOverall['overall_score'] !== null):
                $overall = $currentOverall['overall_score'];
            ?>
            <div class="alert <?= $overall < 55 ? 'alert-danger' : 'alert-success' ?>" style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:32px;"><?= $overall < 55 ? '🚨' : '✅' ?></span>
                <div>
                    <strong>الدرجة الإجمالية: <?= $overall ?>%</strong>
                    <?php if ($overall < 55): ?>
                    <br>النتيجة أقل من 55%. يُرجى إنشاء <a href="improvement_plan.php" style="font-weight:bold;">خطة تحسين المدرسة</a>.
                    <?php else: ?>
                    <br>المرافق في وضع مقبول أو أفضل.
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_report">

                <?php foreach ($categories as $catKey => $cat):
                    $catScore = $categoryScores[$catKey] ?? null;
                ?>
                <div class="card mb-4" style="border-right:4px solid <?= $catScore && $catScore < 55 ? 'var(--danger)' : ($catScore && $catScore >= 55 ? 'var(--success)' : 'var(--border)') ?>;">
                    <div class="card-header" style="background:var(--background);">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <h4 style="margin:0;">
                                <?= $cat['icon'] ?> <?= $cat['label'] ?>
                            </h4>
                            <?php if ($catScore !== null): ?>
                            <span style="padding:2px 10px;border-radius:var(--radius-full);font-size:12px;font-weight:bold;
                                background:<?= $catScore < 55 ? 'var(--danger)' : 'var(--success)' ?>;
                                color:white;">
                                <?= $catScore ?>% <?= $catScore < 55 ? '❌' : '✅' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php foreach ($cat['items'] as $idx => $item): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light);">
                            <span style="font-size:14px;"><?= e($item) ?></span>
                            <div style="display:flex;gap:4px;">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label style="cursor:pointer;">
                                    <input type="radio" name="scores[<?= $catKey ?>][<?= $idx ?>]" value="<?= $i ?>"
                                           style="display:none;" class="facility-radio" data-cat="<?= $catKey ?>">
                                    <span style="display:inline-block;width:32px;height:32px;text-align:center;line-height:32px;
                                        border-radius:var(--radius-md);border:1px solid var(--border);font-size:13px;
                                        transition:all var(--transition-fast);"
                                          class="rating-btn"><?= $i ?></span>
                                </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary btn-lg">💾 حفظ التقرير</button>
                    <a href="index.php" class="btn btn-light btn-lg">↩ رجوع</a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.rating-btn:hover, .rating-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
input.facility-radio:checked + .rating-btn {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
</style>

<?php
require_once __DIR__ . '/../../includes/footer.php';
