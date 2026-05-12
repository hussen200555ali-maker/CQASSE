<?php
/**
 * ============================================================
 * EQAS - تقييم الطلاب للترفيع
 * نظام التقييم الشامل - Evaluate Students for Promotion
 * ============================================================
 */

$pageTitle = 'تقييم الطلاب للترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'تقييم' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$selectedClass = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$selectedYear = (int)($_GET['academic_year_id'] ?? $_POST['academic_year_id'] ?? 0);
$selectedSemester = (int)($_GET['semester_id'] ?? $_POST['semester_id'] ?? 0);

// بيانات مساعدة
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
    if (!$selectedYear && !empty($academicYears)) $selectedYear = $academicYears[0]['id'];
} catch (Exception $e) {}

$classes = [];
try {
    $sf = $userSchoolId ? " AND school_id = " . (int)$userSchoolId : "";
    $stmt = $db->query("SELECT id, name_ar FROM classes WHERE is_active = 1 AND academic_year_id = " . (int)$selectedYear . " {$sf} ORDER BY name_ar");
    $classes = $stmt->fetchAll();
} catch (Exception $e) {}

$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :yid AND is_active = 1");
        $stmt->execute([':yid' => $selectedYear]);
        $semesters = $stmt->fetchAll();
        if (!$selectedSemester && !empty($semesters)) $selectedSemester = $semesters[0]['id'];
    } catch (Exception $e) {}
}

$evaluations = [];

// تقييم عند الطلب
if ($selectedClass && $selectedYear && $selectedSemester && isset($_GET['evaluate'])) {
    $evaluations = $promoEngine->bulkEvaluate($selectedClass, $selectedYear, $selectedSemester);
}

// حفظ القرارات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $evalData = [];
        $studentIds = $_POST['student_ids'] ?? [];
        foreach ($studentIds as $sid) {
            $evalData[] = [
                'student_id' => $sid,
                'academic_score' => $_POST['academic_' . $sid] ?? null,
                'behavior_score' => $_POST['behavior_' . $sid] ?? null,
                'attendance_rate' => $_POST['attendance_' . $sid] ?? null,
                'academic_pass' => (bool)($_POST['academic_pass_' . $sid] ?? false),
                'behavior_pass' => (bool)($_POST['behavior_pass_' . $sid] ?? false),
                'attendance_pass' => (bool)($_POST['attendance_pass_' . $sid] ?? false),
                'decision' => $_POST['decision_' . $sid] ?? 'under_review',
            ];
        }
        
        $result = $promoEngine->saveBulkDecisions($evalData, $selectedYear, $selectedSemester);
        setFlash(sprintf('تم حفظ %d قرار بنجاح.', $result['saved']), 'success');
        redirect('index.php');
    }
}

$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();
$thresholds = $promoEngine->getSettings();
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📝 تقييم الطلاب للترفيع</h3>
        </div>
        <div class="card-body">
            <div class="mb-4 p-3" style="background:rgba(26,86,50,0.05); border-radius:var(--radius-md); border-right:4px solid var(--primary);">
                <strong>📏 معايير الترفيع الإلزامية:</strong>
                <ul style="margin:4px 0 0 20px;">
                    <li>الدرجة الأكاديمية ≥ <?= $thresholds['academic_pass'] ?>%</li>
                    <li>درجة السلوك ≥ <?= $thresholds['behavior_pass'] ?>%</li>
                    <li>نسبة الحضور ≥ <?= $thresholds['attendance_pass'] ?>%</li>
                </ul>
            </div>
            
            <form method="GET" class="form-row">
                <div class="form-group">
                    <label class="form-label">السنة الدراسية</label>
                    <select name="academic_year_id" class="form-control">
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الصف</label>
                    <select name="class_id" class="form-control">
                        <option value="">اختر الصف</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selectedClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" name="evaluate" value="1" class="btn btn-primary">▶️ تقييم</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($evaluations)): ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="academic_year_id" value="<?= e($selectedYear) ?>">
        <input type="hidden" name="semester_id" value="<?= e($selectedSemester) ?>">
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 نتائج التقييم (<?= count($evaluations) ?> طالب)</h3>
                <button type="submit" name="save_all" value="1" class="btn btn-success btn-sm" onclick="return confirm('هل تريد حفظ جميع قرارات الترفيع؟')">
                    💾 حفظ جميع القرارات
                </button>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الطالب</th>
                                <th>الأكاديمي</th>
                                <th>السلوك</th>
                                <th>الحضور</th>
                                <th>المعايير الفاشلة</th>
                                <th>القرار</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $ev): 
                                $dc = $decisionColors[$ev['decision']] ?? $decisionColors['under_review'];
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="student_ids[]" value="<?= e($ev['student_id']) ?>">
                                    <input type="hidden" name="academic_<?= e($ev['student_id']) ?>" value="<?= e($ev['academic_score']) ?>">
                                    <input type="hidden" name="behavior_<?= e($ev['student_id']) ?>" value="<?= e($ev['behavior_score']) ?>">
                                    <input type="hidden" name="attendance_<?= e($ev['student_id']) ?>" value="<?= e($ev['attendance_rate']) ?>">
                                    <input type="hidden" name="academic_pass_<?= e($ev['student_id']) ?>" value="<?= $ev['academic_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="behavior_pass_<?= e($ev['student_id']) ?>" value="<?= $ev['behavior_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="attendance_pass_<?= e($ev['student_id']) ?>" value="<?= $ev['attendance_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="decision_<?= e($ev['student_id']) ?>" value="<?= e($ev['decision']) ?>">
                                    <strong><?= e($ev['student_name']) ?></strong><br>
                                    <span class="text-secondary" style="font-size:11px;"><?= e($ev['student_number']) ?></span>
                                </td>
                                <td>
                                    <span style="color:<?= $ev['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>; font-weight:bold;">
                                        <?= $ev['academic_pass'] !== null ? ($ev['academic_pass'] ? '✅' : '❌') : '➖' ?>
                                        <?= $ev['academic_score'] !== null ? number_format($ev['academic_score'], 1) . '%' : '-' ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color:<?= $ev['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>; font-weight:bold;">
                                        <?= $ev['behavior_pass'] !== null ? ($ev['behavior_pass'] ? '✅' : '❌') : '➖' ?>
                                        <?= $ev['behavior_score'] !== null ? number_format($ev['behavior_score'], 1) . '%' : '-' ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color:<?= $ev['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>; font-weight:bold;">
                                        <?= $ev['attendance_pass'] !== null ? ($ev['attendance_pass'] ? '✅' : '❌') : '➖' ?>
                                        <?= $ev['attendance_rate'] !== null ? number_format($ev['attendance_rate'], 1) . '%' : '-' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (empty($ev['failed_criteria'])): ?>
                                        <span style="color:var(--success);">لا يوجد</span>
                                    <?php else: ?>
                                        <span style="color:var(--danger);">
                                            <?= e(implode('، ', array_map(function($c) {
                                                $labels = ['academic' => 'أكاديمي', 'behavior' => 'سلوك', 'attendance' => 'حضور'];
                                                return $labels[$c] ?? $c;
                                            }, $ev['failed_criteria']))) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="padding:4px 12px; border-radius:var(--radius-full); font-size:12px; font-weight:600; background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>;">
                                        <?= e($ev['decision_label']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
