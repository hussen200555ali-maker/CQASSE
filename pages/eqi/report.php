<?php
/**
 * ============================================================
 * EQAS - تقرير المؤشر
 * نظام التقييم الشامل - EQI Report
 * ============================================================
 */

$pageTitle = 'تقرير مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'تقرير' => null];

// معالجة التصدير قبل header.php (يحتاج header() قبل أي مخرجات HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/database.php';
    require_once __DIR__ . '/../../includes/helpers.php';
    require_once __DIR__ . '/../../includes/session.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/eqi.php';

    if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
        setFlash('ليس لديك صلاحية.', 'danger');
        redirect('index.php');
    }

    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
        redirect('report.php');
    }

    $schoolId = (int)($_POST['school_id'] ?? 0);
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    $semesterId = (int)($_POST['semester_id'] ?? 0);
    $format = $_POST['format'] ?? 'csv';

    $eqiCalc = new EQICalculator($db);
    $report = $eqiCalc->exportReport($schoolId, $yearId, $semesterId, $format);

    if ($report && $format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="eqi_report_' . date('Y-m-d') . '.csv"');
        echo $report;
        exit;
    }

    setFlash('لا توجد بيانات للتصدير.', 'warning');
    redirect('report.php');
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

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

$selectedYear = (int)($_GET['academic_year_id'] ?? 0);
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY id");
        $stmt->execute([$selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}
?>

<style>
.rpt-hero {
    background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%);
    border-radius: 16px; padding: 2rem 2.5rem; margin-bottom: 1.5rem;
    color: #fff; position: relative; overflow: hidden;
}
.rpt-hero::before {
    content: ''; position: absolute; top: -40%; right: -10%;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(200,169,81,0.12) 0%, transparent 70%);
    border-radius: 50%;
}
.rpt-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.rpt-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
.rpt-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden;
}
.rpt-card-hd {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; gap: 0.5rem;
}
.rpt-card-hd h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.rpt-card-bd { padding: 1.5rem; }
.rpt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.rpt-field { }
.rpt-field label { font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.35rem; display: block; }
.rpt-field label .req { color: #dc3545; }
.rpt-field select {
    border: 1px solid #dee2e6; border-radius: 8px; padding: 0.5rem 1rem;
    width: 100%; font-size: 0.9rem; color: #1a1a2e; background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.rpt-field select:focus { border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.12); outline: none; }
.radio-grp { display: flex; gap: 1.5rem; align-items: center; padding: 0.5rem 0; }
.radio-grp label { display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; cursor: pointer; }
.radio-grp input[type="radio"] { accent-color: #1a5632; width: 16px; height: 16px; }
.btn-export {
    display: inline-flex; align-items: center; gap: 0.4rem;
    background: #1a5632; color: #fff; padding: 0.6rem 1.5rem;
    border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-export:hover { background: #0f3a20; }
@media (max-width:768px) {
    .rpt-hero { padding: 1.25rem; }
    .rpt-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="rpt-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>تقرير مؤشر جودة التعليم</h1>
                <div class="hero-sub">صدّر تقرير EQI لمدرسة وفصل محدد بصيغة CSV</div>
            </div>
            <a href="index.php" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">← العودة</a>
        </div>
    </div>

    <!-- Form -->
    <div class="rpt-card">
        <div class="rpt-card-hd"><h3>📥 تصدير تقرير المؤشر</h3></div>
        <div class="rpt-card-bd">
            <form method="POST">
                <?= csrfField() ?>
                <div class="rpt-grid">
                    <div class="rpt-field">
                        <label>المدرسة <span class="req">*</span></label>
                        <select name="school_id" required>
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field">
                        <label>السنة الدراسية <span class="req">*</span></label>
                        <select name="academic_year_id" id="academicYearSelect" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field">
                        <label>الفصل الدراسي <span class="req">*</span></label>
                        <select name="semester_id" required>
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>"><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top:1rem;">
                    <div style="font-size:0.8rem;font-weight:600;color:#6c757d;margin-bottom:0.35rem;">صيغة التصدير</div>
                    <div class="radio-grp">
                        <label><input type="radio" name="format" value="csv" checked> CSV (Excel)</label>
                        <span style="font-size:0.78rem;color:#94a3b8;">PDF قيد التطوير</span>
                    </div>
                </div>
                <div style="margin-top:1.25rem;">
                    <button type="submit" name="export" value="1" class="btn-export">📥 تصدير التقرير</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Info -->
    <div class="rpt-card">
        <div class="rpt-card-hd"><h3>ℹ️ معلومات التقرير</h3></div>
        <div class="rpt-card-bd">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
                <div style="padding:1rem;background:#f8f9fa;border-radius:8px;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:700;color:#1a5632;">CSV</div>
                    <div style="font-size:0.8rem;color:#6c757d;margin-top:0.25rem;">صيغة الملف</div>
                </div>
                <div style="padding:1rem;background:#f8f9fa;border-radius:8px;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:700;color:#1a5632;"><?= count($schools) ?></div>
                    <div style="font-size:0.8rem;color:#6c757d;margin-top:0.25rem;">مدرسة متاحة</div>
                </div>
                <div style="padding:1rem;background:#f8f9fa;border-radius:8px;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:700;color:#1a5632;"><?= count($academicYears) ?></div>
                    <div style="font-size:0.8rem;color:#6c757d;margin-top:0.25rem;">عام دراسي</div>
                </div>
                <div style="padding:1rem;background:#f8f9fa;border-radius:8px;text-align:center;">
                    <div style="font-size:1.3rem;font-weight:700;color:#1a5632;">6</div>
                    <div style="font-size:0.8rem;color:#6c757d;margin-top:0.25rem;">أبعاد التقييم</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('academicYearSelect').addEventListener('change', function() {
    var yearId = this.value;
    if (yearId) {
        window.location.href = 'report.php?academic_year_id=' + yearId;
    }
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
