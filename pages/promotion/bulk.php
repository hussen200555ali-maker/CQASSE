<?php
/**
 * ============================================================
 * EQAS - معالجة جماعية للترفيع
 * نظام التقييم الشامل - Bulk Promotion Processing
 * ============================================================
 */

$pageTitle = 'معالجة جماعية للترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'معالجة جماعية' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$selectedClass = $_POST['class_id'] ?? '';
$selectedYear = $_POST['academic_year_id'] ?? '';
$selectedSemester = $_POST['semester_id'] ?? '';
$evaluations = [];
$saved = false;

$academicYears = [];
try { $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC"); $academicYears = $stmt->fetchAll(); } catch (Exception $e) {}
if (!$selectedYear && !empty($academicYears)) $selectedYear = $academicYears[0]['id'];

$classes = [];
try { $sf = $userSchoolId ? " AND school_id = " . (int)$userSchoolId : ""; $stmt = $db->query("SELECT id, name_ar FROM classes WHERE is_active = 1 AND academic_year_id = " . (int)$selectedYear . " {$sf}"); $classes = $stmt->fetchAll(); } catch (Exception $e) {}

$semesters = [];
if ($selectedYear) { try { $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :yid AND is_active = 1"); $stmt->execute([':yid' => $selectedYear]); $semesters = $stmt->fetchAll(); } catch (Exception $e) {} }

$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('خطأ أمني.', 'danger'); }
    else {
        if (isset($_POST['evaluate']) && $selectedClass && $selectedYear && $selectedSemester) {
            $evaluations = $promoEngine->bulkEvaluate($selectedClass, $selectedYear, $selectedSemester);
        } elseif (isset($_POST['save_all'])) {
            $evalData = [];
            $sids = $_POST['student_ids'] ?? [];
            foreach ($sids as $sid) {
                $evalData[] = ['student_id' => $sid, 'academic_score' => $_POST['academic_'.$sid] ?? null, 'behavior_score' => $_POST['behavior_'.$sid] ?? null, 'attendance_rate' => $_POST['attendance_'.$sid] ?? null, 'academic_pass' => (bool)($_POST['ap_'.$sid] ?? false), 'behavior_pass' => (bool)($_POST['bp_'.$sid] ?? false), 'attendance_pass' => (bool)($_POST['atp_'.$sid] ?? false), 'decision' => $_POST['dec_'.$sid] ?? 'under_review'];
            }
            $result = $promoEngine->saveBulkDecisions($evalData, $selectedYear, $selectedSemester);
            setFlash(sprintf('تم حفظ %d قرار.', $result['saved']), 'success');
            $saved = true;
        }
    }
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header"><h3 class="card-title">📊 معالجة جماعية للترفيع</h3></div>
        <div class="card-body">
            <form method="POST" class="form-row">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label">السنة</label>
                    <select name="academic_year_id" class="form-control">
                        <?php foreach ($academicYears as $ay): ?><option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <?php foreach ($semesters as $sem): ?><option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الصف</label>
                    <select name="class_id" class="form-control">
                        <option value="">اختر</option>
                        <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $selectedClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name_ar']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">&nbsp;</label><button type="submit" name="evaluate" value="1" class="btn btn-primary">▶️ تقييم</button></div>
            </form>
        </div>
    </div>

    <?php if (!empty($evaluations)): ?>
    <form method="POST">
        <?= csrfField() ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">النتائج (<?= count($evaluations) ?> طالب)</h3>
                <div class="flex gap-2">
                    <button type="submit" name="save_all" value="1" class="btn btn-success btn-sm" onclick="return confirm('حفظ جميع القرارات؟')">💾 حفظ الكل</button>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>الطالب</th><th>الأكاديمي</th><th>السلوك</th><th>الحضور</th><th>القرار</th></tr></thead>
                        <tbody>
                        <?php foreach ($evaluations as $ev): $dc = $decisionColors[$ev['decision']] ?? $decisionColors['under_review']; ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="student_ids[]" value="<?= $ev['student_id'] ?>">
                                    <input type="hidden" name="academic_<?= $ev['student_id'] ?>" value="<?= $ev['academic_score'] ?>">
                                    <input type="hidden" name="behavior_<?= $ev['student_id'] ?>" value="<?= $ev['behavior_score'] ?>">
                                    <input type="hidden" name="attendance_<?= $ev['student_id'] ?>" value="<?= $ev['attendance_rate'] ?>">
                                    <input type="hidden" name="ap_<?= $ev['student_id'] ?>" value="<?= $ev['academic_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="bp_<?= $ev['student_id'] ?>" value="<?= $ev['behavior_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="atp_<?= $ev['student_id'] ?>" value="<?= $ev['attendance_pass'] ? 1 : 0 ?>">
                                    <input type="hidden" name="dec_<?= $ev['student_id'] ?>" value="<?= $ev['decision'] ?>">
                                    <strong><?= e($ev['student_name']) ?></strong>
                                </td>
                                <td style="color:<?= $ev['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>;"><?= $ev['academic_score'] !== null ? number_format($ev['academic_score'], 1) . '%' : '-' ?> <?= $ev['academic_pass'] ? '✅' : '❌' ?></td>
                                <td style="color:<?= $ev['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>;"><?= $ev['behavior_score'] !== null ? number_format($ev['behavior_score'], 1) . '%' : '-' ?> <?= $ev['behavior_pass'] ? '✅' : '❌' ?></td>
                                <td style="color:<?= $ev['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>;"><?= $ev['attendance_rate'] !== null ? number_format($ev['attendance_rate'], 1) . '%' : '-' ?> <?= $ev['attendance_pass'] ? '✅' : '❌' ?></td>
                                <td><span style="padding:4px 10px; border-radius:var(--radius-full); font-size:12px; background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>; font-weight:600;"><?= $ev['decision_label'] ?></span></td>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
