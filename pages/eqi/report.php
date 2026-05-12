<?php
/**
 * ============================================================
 * EQAS - تقرير المؤشر
 * نظام التقييم الشامل - EQI Report
 * ============================================================
 */

$pageTitle = 'تقرير مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'تقرير' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);
        $format = $_POST['format'] ?? 'csv';
        
        $report = $eqiCalc->exportReport($schoolId, $yearId, $semesterId, $format);
        
        if ($report && $format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="eqi_report_' . date('Y-m-d') . '.csv"');
            echo $report;
            exit;
        }
        
        setFlash('لا توجد بيانات للتصدير.', 'warning');
    }
}

$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    } else {
        $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = :id");
        $stmt->execute([':id' => $userSchoolId]);
    }
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$semesters = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1 ORDER BY id");
    $semesters = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📥 تقرير مؤشر جودة التعليم</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المدرسة <span class="required">*</span></label>
                        <select name="school_id" class="form-control" required>
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية <span class="required">*</span></label>
                        <select name="academic_year_id" class="form-control" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>"><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>"><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">صيغة التصدير</label>
                    <label class="form-check">
                        <input type="radio" name="format" value="csv" checked class="form-check-input">
                        <span class="form-check-label">CSV (Excel)</span>
                    </label>
                </div>
                <button type="submit" name="export" value="1" class="btn btn-primary btn-lg">📥 تصدير التقرير</button>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
