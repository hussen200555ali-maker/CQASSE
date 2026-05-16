<?php
/**
 * ============================================================
 * EQAS - تشغيل فحوصات التنبيهات
 * نظام التقييم الشامل - Run Alert Checks
 * ============================================================
 */

$pageTitle = 'تشغيل فحوصات التنبيهات';
$breadcrumb = ['التنبيهات الذكية' => 'index.php', 'تشغيل الفحوصات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// الحصول على المدارس
$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    } else {
        $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $userSchoolId]);
    }
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

// الحصول على السنوات الدراسية
$academicYears = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name FROM academic_years WHERE status != 'planning' ORDER BY start_date DESC");
    } else {
        $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    }
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

// الحصول على الفصول الدراسية
$semesters = [];
$selectedYear = $_GET['academic_year_id'] ?? ($_POST['academic_year_id'] ?? '');
if ($selectedYear) {
    try {
        if ($userRole === 'group_admin') {
            $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id ORDER BY id");
        } else {
            $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1 ORDER BY id");
        }
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// نتائج الفحوصات
$results = null;

// معالجة تشغيل الفحوصات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_checks'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);
        
        if (!$schoolId || !$yearId || !$semesterId) {
            setFlash('يرجى اختيار المدرسة والسنة والفصل الدراسي.', 'warning');
        } else {
            $results = $alertEngine->runAllChecks($schoolId, $yearId, $semesterId);
            setFlash(sprintf('تم تشغيل الفحوصات بنجاح. تم إنشاء %d تنبيه جديد.', $results['total_new']), 'success');
        }
    }
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">▶️ تشغيل فحوصات التنبيهات</h3>
        </div>
        <div class="card-body">
            <div class="mb-6 p-4" style="background:rgba(23,162,184,0.1); border-radius:var(--radius-lg); border-right:4px solid var(--info);">
                <p style="margin:0; color:var(--info-dark);">
                    <strong>ℹ️ معلومات:</strong> تقوم هذه الأداة بتشغيل جميع فحوصات التنبيهات الثلاثة:
                </p>
                <ul style="margin:8px 0 0 20px; color:var(--text-secondary);">
                    <li>فحص الحدود العتبية (أكاديمي، سلوكي، حضور)</li>
                    <li>فحص الاتجاهات التنازلية (3+ فترات)</li>
                    <li>فحص الأنماط (غياب متتالي، انخفاض مفاجئ)</li>
                </ul>
            </div>
            
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المدرسة <span class="required">*</span></label>
                        <select name="school_id" class="form-control" required id="school-select">
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($_POST['school_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية <span class="required">*</span></label>
                        <select name="academic_year_id" class="form-control" required id="year-select">
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required id="semester-select">
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= ($_POST['semester_id'] ?? '') == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="run_checks" value="1" class="btn btn-primary btn-lg" onclick="return confirm('هل تريد تشغيل جميع فحوصات التنبيهات؟')">
                        ▶️ تشغيل جميع الفحوصات
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- نتائج الفحوصات -->
    <?php if ($results !== null): ?>
    <div class="card">
        <div class="card-header" style="background:var(--success); color:#fff;">
            <h3 class="card-title" style="color:#fff;">✅ نتائج الفحوصات</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-3 gap-4 mb-6">
                <div class="p-4" style="background:rgba(220,53,69,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--danger);"><?= $results['academic_thresholds'] ?></div>
                    <div class="text-secondary">تنبيهات أكاديمية</div>
                </div>
                <div class="p-4" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--warning);"><?= $results['behavior_thresholds'] ?></div>
                    <div class="text-secondary">تنبيهات سلوكية</div>
                </div>
                <div class="p-4" style="background:rgba(23,162,184,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--info);"><?= $results['attendance_thresholds'] ?></div>
                    <div class="text-secondary">تنبيهات حضور</div>
                </div>
            </div>
            <div class="grid grid-3 gap-4">
                <div class="p-4" style="background:rgba(108,117,125,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--text-secondary);"><?= $results['declining_trends'] ?></div>
                    <div class="text-secondary">اتجاهات تنازلية</div>
                </div>
                <div class="p-4" style="background:rgba(108,117,125,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--text-secondary);"><?= $results['absence_patterns'] ?></div>
                    <div class="text-secondary">أنماط غياب</div>
                </div>
                <div class="p-4" style="background:rgba(108,117,125,0.1); border-radius:var(--radius-lg); text-align:center;">
                    <div style="font-size:28px; font-weight:bold; color:var(--text-secondary);"><?= $results['sudden_drops'] ?></div>
                    <div class="text-secondary">انخفاضات مفاجئة</div>
                </div>
            </div>
            <div class="divider"></div>
            <div class="text-center">
                <span style="font-size:18px; font-weight:bold;">إجمالي التنبيهات الجديدة: <?= $results['total_new'] ?></span>
                <div class="mt-4">
                    <a href="index.php" class="btn btn-primary">🔔 عرض التنبيهات</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$pageJs = <<<'JS'
document.getElementById('year-select').addEventListener('change', function() {
    var yearId = this.value;
    var semSelect = document.getElementById('semester-select');
    semSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    fetch('run_checks.php?academic_year_id=' + yearId).then(r => r.text()).then(html => {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var opts = doc.getElementById('semester-select').innerHTML;
        semSelect.innerHTML = opts;
    });
});
JS;
require_once __DIR__ . '/../../includes/footer.php';
