<?php
/**
 * ============================================================
 * EQAS - استبيان المناخ المدرسي
 * نظام التقييم الشامل - Climate Survey Management
 * ============================================================
 * إدارة استبيانات المناخ المدرسي:
 * - إنشاء وجدولة الاستبيانات
 * - اختيار نوع المستجيبين
 * - عرض النتائج مع الرسوم البيانية
 * - تفعيل خطة التحسين إذا كانت النتيجة < 55%
 * ============================================================
 */

$pageTitle = 'استبيان المناخ المدرسي';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'استبيان المناخ' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// ملاحظة: جدول climate_surveys تم دمجه في surveys عبر الترحيل v3.3.0
// هذه الصفحة للعرض فقط - استخدم pages/climate/survey.php لإنشاء استبيانات جديدة

// معالجة الحفظ - تحويل إلى صفحة الاستبيان الجديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlash('يرجى استخدام صفحة استبيان المناخ الجديدة: pages/climate/survey.php', 'info');
    redirect(baseUrl('pages/climate/survey.php'));
}

// جلب الاستبيانات — مجمعة حسب نوع المستجيب لأن جدول surveys الجديد يخزن استجابة فردية لكل صف
$stmt = $db->prepare("
    SELECT 
        respondent_type,
        COUNT(*) AS total_respondents,
        ROUND(AVG(overall_score), 1) AS avg_score,
        CASE 
            WHEN COUNT(CASE WHEN status = 'active' THEN 1 END) > 0 THEN 'active'
            WHEN COUNT(CASE WHEN status = 'draft' THEN 1 END) > 0 THEN 'draft'
            ELSE 'closed'
        END AS status,
        MIN(survey_date) AS survey_date,
        MAX(created_at) AS last_date
    FROM surveys 
    WHERE survey_type = 'climate' AND school_id = ? AND is_active = 1 AND respondent_type IS NOT NULL
    GROUP BY respondent_type
    ORDER BY MAX(created_at) DESC
");
$stmt->execute([$schoolId]);
$surveys = $stmt->fetchAll();

// النتائج حسب نوع المستجيب
$resultsByType = ['student' => null, 'teacher' => null, 'parent' => null, 'staff' => null];
$stmt = $db->prepare("
    SELECT cs.respondent_type, AVG(cs.overall_score) as type_avg, COUNT(cs.id) as total
    FROM surveys cs
    WHERE cs.survey_type = 'climate' AND cs.school_id = ? AND cs.is_active = 1 AND cs.status = 'closed' AND cs.overall_score IS NOT NULL
    GROUP BY cs.respondent_type
");
$stmt->execute([$schoolId]);
while ($row = $stmt->fetch()) {
    $resultsByType[$row['respondent_type']] = $row;
}

// المتوسط العام
$overallScore = null;
try {
    $stmt = $db->prepare("
        SELECT AVG(ar.numeric_value) as avg_score
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1
    ");
    $stmt->execute([$schoolId]);
    $row = $stmt->fetch();
    if ($row && $row['avg_score'] !== null) {
        $overallScore = round($row['avg_score'], 1);
    }
} catch (Exception $e) { }

$respondentLabels = ['student' => 'طلاب', 'teacher' => 'معلمين', 'parent' => 'أولياء أمور', 'staff' => 'إداريين'];
$statusLabels = ['draft' => 'مسودة', 'active' => 'نشط', 'closed' => 'مغلق'];
$statusColors = ['draft' => 'secondary', 'active' => 'success', 'closed' => 'info'];
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">🌡️ استبيان المناخ المدرسي</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-form').style.display='block'">➕ استبيان جديد</button>
        </div>
        <div class="card-body">
            <?php if ($overallScore !== null && $overallScore < 55): ?>
            <div class="alert alert-danger">
                <strong>🚨 درجة المناخ المدرسي حرجة (<?= $overallScore ?>%)!</strong>
                النتيجة أقل من 55%. يُرجى إنشاء <a href="improvement_plan.php" style="font-weight:bold;">خطة تحسين المدرسة</a>.
            </div>
            <?php endif; ?>

            <!-- نموذج إنشاء -->
            <div id="create-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--info);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_survey">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">عنوان الاستبيان <span class="required">*</span></label>
                                    <input type="text" name="title" class="form-control" placeholder="مثال: استبيان المناخ - الفصل الأول 2025" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">نوع المستجيب</label>
                                    <select name="respondent_type" class="form-control">
                                        <?php foreach ($respondentLabels as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">تاريخ الجدولة</label>
                                    <input type="date" name="scheduled_date" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary">💾 إنشاء</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('create-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- النتائج حسب نوع المستجيب -->
            <div class="grid grid-4 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
                <?php foreach ($respondentLabels as $type => $label):
                    $result = $resultsByType[$type];
                    $score = $result ? round($result['type_avg'], 1) : null;
                ?>
                <div class="stat-card" style="border-top:3px solid <?= $score && $score < 55 ? 'var(--danger)' : 'var(--info)' ?>;">
                    <div class="stat-label"><?= $label ?></div>
                    <div class="stat-value" style="font-size:24px;color:<?= $score && $score < 55 ? 'var(--danger)' : 'var(--info)' ?>;">
                        <?= $score !== null ? $score . '%' : '-' ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);">
                        <?= $result ? $result['total'] . ' مستجيب' : 'لا بيانات' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- المتوسط العام -->
            <?php if ($overallScore !== null): ?>
            <div class="card mb-6" style="border:2px solid <?= $overallScore < 55 ? 'var(--danger)' : 'var(--success)' ?>;">
                <div class="card-body" style="text-align:center;padding:24px;">
                    <div style="font-size:14px;color:var(--text-secondary);margin-bottom:4px;">المتوسط العام للمناخ المدرسي</div>
                    <div style="font-size:48px;font-weight:bold;color:<?= $overallScore < 55 ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $overallScore ?>%
                    </div>
                    <div style="font-size:14px;color:<?= $overallScore < 55 ? 'var(--danger)' : 'var(--success)' ?>;">
                        <?= $overallScore >= 70 ? 'جيد' : ($overallScore >= 55 ? 'مقبول' : 'ضعيف - يحتاج خطة تحسين') ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- قائمة الاستبيانات -->
            <h4 style="margin-bottom:12px;">📋 الاستبيانات</h4>
            <?php if (empty($surveys)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <p>لم يتم إنشاء أي استبيانات بعد.</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>النوع</th>
                            <th>الحالة</th>
                            <th>المستجيبون</th>
                            <th>المتوسط</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($surveys as $survey): ?>
                        <tr>
                            <td><strong><?= $respondentLabels[$survey['respondent_type']] ?? $survey['respondent_type'] ?></strong></td>
                            <td><?= $respondentLabels[$survey['respondent_type']] ?? $survey['respondent_type'] ?></td>
                            <td>
                                <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:var(--<?= $statusColors[$survey['status']] ?? 'secondary' ?>);color:white;">
                                    <?= $statusLabels[$survey['status']] ?? $survey['status'] ?>
                                </span>
                            </td>
                            <td><?= $survey['total_respondents'] ?></td>
                            <td><?= $survey['avg_score'] !== null ? round($survey['avg_score'], 1) . '%' : '-' ?></td>
                            <td>
                                <span style="color:var(--text-muted);font-size:11px;">—</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// رسم بياني للنتائج
$pageJs = "
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('climate-chart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['الطلاب', 'المعلمين', 'أولياء الأمور', 'الإداريين'],
            datasets: [{
                label: 'درجة المناخ %',
                data: [
                    " . ($resultsByType['student']['type_avg'] ?? 'null') . ",
                    " . ($resultsByType['teacher']['type_avg'] ?? 'null') . ",
                    " . ($resultsByType['parent']['type_avg'] ?? 'null') . ",
                    " . ($resultsByType['staff']['type_avg'] ?? 'null') . "
                ],
                backgroundColor: ['rgba(23,162,184,0.7)', 'rgba(40,167,69,0.7)', 'rgba(255,193,7,0.7)', 'rgba(108,117,125,0.7)'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, max: 100 } },
            plugins: { legend: { display: false } }
        }
    });
});
";
require_once __DIR__ . '/../../includes/footer.php';
