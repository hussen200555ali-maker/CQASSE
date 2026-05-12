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

// إنشاء جدول الاستبيانات إذا لم يكن موجوداً
$db->exec("CREATE TABLE IF NOT EXISTS climate_surveys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    academic_year_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    respondent_type ENUM('student','teacher','parent','staff') NOT NULL DEFAULT 'teacher',
    status ENUM('draft','active','closed') DEFAULT 'draft',
    total_respondents INT DEFAULT 0,
    avg_score DECIMAL(5,2) DEFAULT NULL,
    scheduled_date DATE DEFAULT NULL,
    created_by INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_survey') {
            $title = trim($_POST['title'] ?? '');
            $respondentType = $_POST['respondent_type'] ?? 'teacher';
            $scheduledDate = $_POST['scheduled_date'] ?? null;

            if (empty($title)) {
                setFlash('يرجى إدخال عنوان الاستبيان.', 'warning');
            } else {
                $stmt = $db->prepare("INSERT INTO climate_surveys (school_id, academic_year_id, title, respondent_type, scheduled_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$schoolId, $academicYearId, $title, $respondentType, $scheduledDate ?: null, $_SESSION['user']['id']]);
                logActivity($db, $_SESSION['user']['id'], 'climate_survey_create', "إنشاء استبيان مناخ: {$title}", 'climate_surveys', $db->lastInsertId());
                setFlash('تم إنشاء الاستبيان بنجاح.', 'success');
            }

        } elseif ($action === 'activate_survey') {
            $id = (int)$_POST['survey_id'];
            $stmt = $db->prepare("UPDATE climate_surveys SET status = 'active' WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $schoolId]);
            setFlash('تم تفعيل الاستبيان.', 'success');

        } elseif ($action === 'close_survey') {
            $id = (int)$_POST['survey_id'];
            $stmt = $db->prepare("UPDATE climate_surveys SET status = 'closed' WHERE id = ? AND school_id = ?");
            $stmt->execute([$id, $schoolId]);
            setFlash('تم إغلاق الاستبيان.', 'success');
        }

        redirect('climate_survey.php');
    }
}

// جلب الاستبيانات
$stmt = $db->prepare("SELECT * FROM climate_surveys WHERE school_id = ? AND is_active = 1 ORDER BY created_at DESC");
$stmt->execute([$schoolId]);
$surveys = $stmt->fetchAll();

// النتائج حسب نوع المستجيب
$resultsByType = ['student' => null, 'teacher' => null, 'parent' => null, 'staff' => null];
$stmt = $db->prepare("
    SELECT cs.respondent_type, AVG(cs.avg_score) as type_avg, SUM(cs.total_respondents) as total
    FROM climate_surveys cs
    WHERE cs.school_id = ? AND cs.is_active = 1 AND cs.status = 'closed' AND cs.avg_score IS NOT NULL
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
                            <td><strong><?= e($survey['title']) ?></strong></td>
                            <td><?= $respondentLabels[$survey['respondent_type']] ?? $survey['respondent_type'] ?></td>
                            <td>
                                <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:var(--<?= $statusColors[$survey['status']] ?? 'secondary' ?>);color:white;">
                                    <?= $statusLabels[$survey['status']] ?? $survey['status'] ?>
                                </span>
                            </td>
                            <td><?= $survey['total_respondents'] ?></td>
                            <td><?= $survey['avg_score'] !== null ? round($survey['avg_score'], 1) . '%' : '-' ?></td>
                            <td>
                                <?php if ($survey['status'] === 'draft'): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="activate_survey">
                                    <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                                    <button type="submit" class="btn btn-success" style="padding:2px 8px;font-size:11px;">▶️ تفعيل</button>
                                </form>
                                <?php elseif ($survey['status'] === 'active'): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="close_survey">
                                    <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                                    <button type="submit" class="btn btn-info" style="padding:2px 8px;font-size:11px;">⏹️ إغلاق</button>
                                </form>
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
