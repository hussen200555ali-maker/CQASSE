<?php
/**
 * ============================================================
 * EQAS - مركز التصدير
 * نظام التقييم الشامل - صفحة مركز التصدير الرئيسية
 * ============================================================
 */

$pageTitle = 'مركز التصدير';
$breadcrumb = ['مركز التصدير' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/filters.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// أنواع التصدير
$exportTypes = [
    'students' => ['label' => 'الطلاب', 'icon' => '👥', 'table' => 'students', 'desc' => 'بيانات الطلاب المسجلين'],
    'grades' => ['label' => 'الدرجات', 'icon' => '📊', 'table' => 'grades', 'desc' => 'سجلات الدرجات'],
    'attendance' => ['label' => 'الحضور', 'icon' => '📅', 'table' => 'attendance', 'desc' => 'سجلات الحضور والغياب'],
    'assessments' => ['label' => 'التقييمات', 'icon' => '📋', 'table' => 'assessment_responses', 'desc' => 'نتائج التقييمات'],
    'eqi' => ['label' => 'مؤشر الجودة', 'icon' => '📈', 'table' => 'eqi_calculations', 'desc' => 'حسابات مؤشر جودة التعليم'],
    'alerts' => ['label' => 'التنبيهات', 'icon' => '🔔', 'table' => 'alerts', 'desc' => 'التنبيهات الذكية'],
    'promotions' => ['label' => 'قرارات الترفيع', 'icon' => '🎓', 'table' => 'promotion_decisions', 'desc' => 'قرارات الترفيع والرسوب'],
];

// قوالب التصدير المسبقة
$exportTemplates = [
    'full_students' => ['label' => 'تقرير الطلاب الكامل', 'icon' => '📋', 'type' => 'students', 'format' => 'xlsx', 'desc' => 'جميع بيانات الطلاب المسجلين بصيغة إكسل'],
    'semester_grades' => ['label' => 'درجات الفصل الدراسي', 'icon' => '📊', 'type' => 'grades', 'format' => 'xlsx', 'desc' => 'درجات الفصل الحالي مع التصفية'],
    'attendance_report' => ['label' => 'تقرير الحضور الشهري', 'icon' => '📅', 'type' => 'attendance', 'format' => 'pdf', 'desc' => 'تقرير الحضور والغياب بصيغة PDF'],
    'eqi_summary' => ['label' => 'ملخص مؤشر الجودة', 'icon' => '📈', 'type' => 'eqi', 'format' => 'html', 'desc' => 'ملخص مؤشر الجودة لجميع المدارس'],
    'promotion_decisions' => ['label' => 'قرارات الترفيع', 'icon' => '🎓', 'type' => 'promotions', 'format' => 'xlsx', 'desc' => 'قرارات الترفيع والرسوب للعام الحالي'],
    'alerts_overview' => ['label' => 'نظرة عامة على التنبيهات', 'icon' => '🔔', 'type' => 'alerts', 'format' => 'csv', 'desc' => 'جميع التنبيهات النشطة بصيغة CSV'],
];

// صيغ التصدير المتاحة
$exportFormats = [
    'xlsx' => ['label' => 'إكسل', 'icon' => '📄', 'ext' => 'XLSX', 'color' => '#28a745'],
    'csv' => ['label' => 'CSV', 'icon' => '📝', 'ext' => 'CSV', 'color' => '#17a2b8'],
    'pdf' => ['label' => 'PDF', 'icon' => '📑', 'ext' => 'PDF', 'color' => '#dc3545'],
    'html' => ['label' => 'HTML', 'icon' => '🌐', 'ext' => 'HTML', 'color' => '#6f42c1'],
];

// معالجة حفظ إعدادات التصدير المجدول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }
    $scheduleName = trim($_POST['schedule_name'] ?? '');
    $scheduleType = $_POST['schedule_type'] ?? '';
    $scheduleFormat = $_POST['schedule_format'] ?? 'xlsx';
    $scheduleSchoolId = $_POST['schedule_school_id'] ?? '';
    $scheduleAcademicYearId = $_POST['schedule_academic_year_id'] ?? '';

    if ($scheduleName && isset($exportTypes[$scheduleType])) {
        $config = json_encode([
            'name' => $scheduleName,
            'type' => $scheduleType,
            'format' => $scheduleFormat,
            'school_id' => $scheduleSchoolId,
            'academic_year_id' => $scheduleAcademicYearId,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("INSERT INTO export_templates (user_id, name, config, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user']['id'], $scheduleName, $config]);
        logActivity($db, $_SESSION['user']['id'], 'export_schedule_save', 'حفظ إعدادات تصدير مجدول: ' . $scheduleName, 'export', null);
        setFlash('تم حفظ إعدادات التصدير بنجاح', 'success');
    }
    redirect('index.php');
}

// معالجة التصدير
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_type']) && !isset($_POST['save_schedule'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }

    $exportType = $_POST['export_type'] ?? '';
    $format = $_POST['format'] ?? 'xlsx';
    $schoolId = $_POST['school_id'] ?? '';
    $academicYearId = $_POST['academic_year_id'] ?? getCurrentAcademicYearId($db);
    $semesterId = $_POST['semester_id'] ?? '0';

    if (!isset($exportTypes[$exportType])) {
        setFlash('نوع تصدير غير صالح', 'danger');
        redirect('index.php');
    }

    $headers = [];
    $data = [];

    // جلب البيانات حسب النوع
    switch ($exportType) {
        case 'students':
            $headers = ['رقم الطالب', 'الاسم', 'الصف', 'المدرسة', 'الجنس', 'تاريخ الميلاد', 'ولي الأمر', 'هاتف ولي الأمر'];
            $sql = "SELECT s.student_number, s.full_name_ar, gl.name_ar as grade_name, sch.name_ar as school_name,
                    s.gender, s.date_of_birth, s.guardian_name, s.guardian_phone
                    FROM students s
                    LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                    LEFT JOIN schools sch ON sch.id = s.school_id
                    WHERE s.is_active = 1";
            $params = [];
            if ($schoolId) { $sql .= " AND s.school_id = ?"; $params[] = $schoolId; }
            $sql .= " ORDER BY s.full_name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'grades':
            $headers = ['رقم الطالب', 'اسم الطالب', 'المادة', 'الفصل', 'الدرجة', 'الدرجة القصوى', 'نوع الدرجة'];
            $sql = "SELECT s.student_number, s.full_name_ar, sub.name_ar, c.name_ar, g.grade_value, g.max_value, g.grade_type
                    FROM grades g
                    JOIN students s ON s.id = g.student_id
                    JOIN subjects sub ON sub.id = g.subject_id
                    JOIN classes c ON c.id = g.class_id
                    WHERE g.is_active = 1";
            $params = [];
            if ($academicYearId) { $sql .= " AND g.academic_year_id = ?"; $params[] = $academicYearId; }
            if ($semesterId && $semesterId !== '0') {
                $semName = semesterValueToNameAr((int)$semesterId);
                if ($semName) { $sql .= " AND g.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)"; $params[] = $semName; }
            }
            if ($schoolId) { $sql .= " AND s.school_id = ?"; $params[] = $schoolId; }
            $sql .= " ORDER BY s.full_name_ar, sub.name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'attendance':
            $headers = ['رقم الطالب', 'اسم الطالب', 'التاريخ', 'الحالة', 'ملاحظات'];
            $sql = "SELECT s.student_number, s.full_name_ar, a.date, a.status, a.notes
                    FROM attendance a
                    JOIN students s ON s.id = a.student_id
                    WHERE a.is_active = 1";
            $params = [];
            if ($academicYearId) { $sql .= " AND a.academic_year_id = ?"; $params[] = $academicYearId; }
            if ($semesterId && $semesterId !== '0') {
                $semName = semesterValueToNameAr((int)$semesterId);
                if ($semName) { $sql .= " AND a.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)"; $params[] = $semName; }
            }
            $sql .= " ORDER BY a.date DESC, s.full_name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'assessments':
            $headers = ['نوع النموذج', 'العنصر', 'الطالب', 'القيمة الرقمية', 'تاريخ الاستجابة'];
            $sql = "SELECT af.form_key, ai.text_ar, s.full_name_ar, ar.numeric_value, ar.response_date
                    FROM assessment_responses ar
                    JOIN assessment_forms af ON af.id = ar.form_id
                    JOIN assessment_items ai ON ai.id = ar.item_id
                    LEFT JOIN students s ON s.id = ar.student_id
                    WHERE ar.is_active = 1"; // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
            $params = [];
            if ($academicYearId) { $sql .= " AND ar.academic_year_id = ?"; $params[] = $academicYearId; }
            if ($semesterId && $semesterId !== '0') {
                $semName = semesterValueToNameAr((int)$semesterId);
                if ($semName) { $sql .= " AND ar.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)"; $params[] = $semName; }
            }
            $sql .= " ORDER BY ar.response_date DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'eqi':
            $headers = ['المدرسة', 'الأكاديمي', 'التدريس', 'المناخ', 'السلوك', 'المهارات', 'الحضور', 'المؤشر الكلي', 'تاريخ الحساب'];
            $sql = "SELECT sch.name_ar, eq.academic_score, eq.teaching_score, eq.climate_score,
                    eq.behavior_score, eq.skills_score, eq.attendance_score, eq.overall_eqi, eq.calculated_at
                    FROM eqi_calculations eq
                    JOIN schools sch ON sch.id = eq.school_id
                    WHERE eq.is_active = 1";
            $params = [];
            if ($academicYearId) { $sql .= " AND eq.academic_year_id = ?"; $params[] = $academicYearId; }
            $sql .= " ORDER BY eq.calculated_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'alerts':
            $headers = ['المدرسة', 'النوع', 'الخطورة', 'العنوان', 'الرسالة', 'الحالة', 'التاريخ'];
            $sql = "SELECT sch.name_ar, a.alert_type, a.severity, a.title_ar, a.message_ar,
                    CASE WHEN a.is_resolved = 1 THEN 'محلول' WHEN a.is_read = 1 THEN 'مقروء' ELSE 'جديد' END, a.created_at
                    FROM alerts a
                    LEFT JOIN schools sch ON sch.id = a.school_id
                    WHERE 1=1";
            $params = [];
            if ($schoolId) { $sql .= " AND a.school_id = ?"; $params[] = $schoolId; }
            $sql .= " ORDER BY a.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'promotions':
            $headers = ['الطالب', 'الدرجة الأكاديمية', 'درجة السلوك', 'نسبة الحضور', 'القرار', 'التاريخ'];
            $sql = "SELECT s.full_name_ar, pd.academic_score, pd.behavior_score, pd.attendance_score, pd.decision, pd.decided_at
                    FROM promotion_decisions pd
                    JOIN students s ON s.id = pd.student_id
                    WHERE pd.is_active = 1";
            $params = [];
            if ($academicYearId) { $sql .= " AND pd.academic_year_id = ?"; $params[] = $academicYearId; }
            $sql .= " ORDER BY pd.decided_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_NUM);
            break;
    }

    if (!empty($data)) {
        $baseFilename = $exportTypes[$exportType]['label'] . '_' . date('Ymd');
        $filters = ['type' => $exportType, 'school_id' => $schoolId, 'academic_year_id' => $academicYearId, 'semester_id' => $semesterId];
        $exportInfo = createExportFile($db, $_SESSION['user']['id'], $exportType, $format, $data, $headers, $baseFilename, $filters);
        logActivity($db, $_SESSION['user']['id'], 'export_data', "تصدير بيانات: {$exportTypes[$exportType]['label']} - " . count($data) . " صف", 'export', null);
        downloadExportFile($exportInfo['file_path'], $exportInfo['file_name']);
    } else {
        setFlash('لا توجد بيانات للتصدير', 'warning');
    }
}

// جلب المدارس
$schools = [];
if (hasPermission('group_admin')) {
    $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    $schools = $stmt->fetchAll();
}

// جلب السنوات
$stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
$academicYears = $stmt->fetchAll();

// إحصائيات التصدير
$recentExports = [];
$stmt = $db->prepare("SELECT eh.*, u.full_name_ar as user_name
                      FROM export_history eh
                      LEFT JOIN users u ON u.id = eh.user_id
                      ORDER BY eh.created_at DESC LIMIT 10");
$stmt->execute();
$recentExports = $stmt->fetchAll();

// إحصائيات عامة للتصدير
$totalExports = 0;
$totalSize = 0;
$stmt = $db->query("SELECT COUNT(*) as cnt, COALESCE(SUM(file_size), 0) as total_sz FROM export_history WHERE status = 'completed'");
$exportStats = $stmt->fetch();
$totalExports = $exportStats['cnt'] ?? 0;
$totalSize = $exportStats['total_sz'] ?? 0;

// جلب القوالب المحفوظة
$savedTemplates = [];
try {
    $stmt = $db->prepare("SELECT * FROM export_templates WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$_SESSION['user']['id']]);
    $savedTemplates = $stmt->fetchAll();
} catch (Exception $e) {
    $savedTemplates = [];
}

// تقدير حجم التصدير لكل نوع
$exportEstimates = [];
foreach ($exportTypes as $type => $info) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM {$info['table']} WHERE is_active = 1");
        $row = $stmt->fetch();
        $rowCount = $row['cnt'] ?? 0;
        $estSize = $rowCount * 0.5; // تقدير تقريبي بالكيلوبايت
        $exportEstimates[$type] = [
            'rows' => $rowCount,
            'size_kb' => round($estSize, 1),
            'size_label' => $estSize > 1024 ? round($estSize / 1024, 1) . ' MB' : round($estSize, 1) . ' KB'
        ];
    } catch (Exception $e) {
        $exportEstimates[$type] = ['rows' => 0, 'size_kb' => 0, 'size_label' => '-'];
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <!-- قوالب التصدير السريعة -->
        <div class="card mb-4">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 class="card-title">⚡ قوالب التصدير السريعة</h2>
                <small class="text-muted">تصدير سريع بإعدادات مسبقة</small>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($exportTemplates as $tplKey => $tpl): ?>
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="export-template-card" style="padding:14px;border:2px solid var(--border-light);border-radius:var(--radius-md);cursor:pointer;transition:all 0.2s;text-align:center;"
                             onclick="applyTemplate('<?php echo $tplKey; ?>', '<?php echo $tpl['type']; ?>', '<?php echo $tpl['format']; ?>')">
                            <div style="font-size:28px;margin-bottom:4px;"><?php echo $tpl['icon']; ?></div>
                            <div style="font-weight:600;font-size:13px;"><?php echo e($tpl['label']); ?></div>
                            <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;"><?php echo e($tpl['desc']); ?></div>
                            <span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:var(--radius-md);font-size:10px;font-weight:700;background:<?php echo $exportFormats[$tpl['format']]['color']; ?>20;color:<?php echo $exportFormats[$tpl['format']]['color']; ?>;"><?php echo strtoupper($tpl['format']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- نموذج التصدير -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📤 تصدير بيانات جديد</h2>
            </div>
            <div class="card-body">
                <form method="POST" id="export-form">
                    <?= csrfField() ?>

                    <!-- اختيار نوع التصدير -->
                    <div class="form-group">
                        <label class="form-label">نوع التصدير</label>
                        <div class="row">
                            <?php foreach ($exportTypes as $type => $info): ?>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <label class="export-type-card" style="display:block;padding:12px;border:2px solid var(--border-color);border-radius:8px;cursor:pointer;text-align:center;transition:all 0.2s;">
                                    <input type="radio" name="export_type" value="<?php echo $type; ?>" required
                                           style="margin-bottom:8px;"
                                           <?php echo $type === 'students' ? 'checked' : ''; ?>
                                           onchange="updateEstimate('<?php echo $type; ?>')">
                                    <div style="font-size:28px;"><?php echo $info['icon']; ?></div>
                                    <div style="font-weight:600;margin-top:4px;"><?php echo e($info['label']); ?></div>
                                    <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;"><?php echo e($info['desc']); ?></div>
                                    <?php if (isset($exportEstimates[$type]) && $exportEstimates[$type]['rows'] > 0): ?>
                                    <div style="font-size:10px;color:var(--text-muted);margin-top:4px;">
                                        ≈ <?php echo number_format($exportEstimates[$type]['rows']); ?> صف | <?php echo $exportEstimates[$type]['size_label']; ?>
                                    </div>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- خيارات التصفية -->
                    <div class="form-grid">
                        <?php if (hasPermission('group_admin')): ?>
                        <div class="form-group">
                            <label class="form-label">المدرسة</label>
                            <select name="school_id" class="form-control">
                                <option value="">جميع المدارس</option>
                                <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>"><?php echo e($school['name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">السنة الأكاديمية</label>
                            <select name="academic_year_id" id="academic_year_id" class="form-control">
                                <option value="">الكل</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo e($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">الفصل الدراسي</label>
                            <select name="semester_id" id="semester_id" class="form-control">
                                <option value="0">جميع الفصول</option>
                                <option value="1">الفصل الدراسي الأول</option>
                                <option value="2">الفصل الدراسي الثاني</option>
                            </select>
                        </div>
                    </div>

                    <!-- اختيار صيغة التصدير المتعددة -->
                    <div class="form-group mt-3">
                        <label class="form-label">صيغة التصدير</label>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <?php foreach ($exportFormats as $fmtKey => $fmtInfo): ?>
                            <label style="display:flex;align-items:center;gap:6px;padding:10px 16px;border:2px solid var(--border-light);border-radius:var(--radius-md);cursor:pointer;transition:all 0.2s;" class="format-option-label">
                                <input type="radio" name="format" value="<?php echo $fmtKey; ?>"
                                       <?php echo $fmtKey === 'xlsx' ? 'checked' : ''; ?>
                                       style="accent-color:<?php echo $fmtInfo['color']; ?>;">
                                <span style="font-size:20px;"><?php echo $fmtInfo['icon']; ?></span>
                                <span style="font-weight:600;font-size:13px;"><?php echo e($fmtInfo['label']); ?></span>
                                <span style="font-size:10px;padding:2px 6px;border-radius:var(--radius-md);background:<?php echo $fmtInfo['color']; ?>15;color:<?php echo $fmtInfo['color']; ?>;font-weight:700;">.<?php echo $fmtInfo['ext']; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- تقدير حجم التصدير -->
                    <div id="export-estimate" style="display:none;margin-top:12px;padding:10px 16px;background:rgba(26,86,50,0.05);border-radius:var(--radius-md);border-right:4px solid var(--primary);font-size:13px;">
                        <span id="estimate-icon">📊</span>
                        <strong>تقدير التصدير:</strong>
                        <span id="estimate-text">-</span>
                    </div>

                    <!-- شريط التقدم -->
                    <div id="export-progress" style="display:none;margin-top:16px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div style="width:16px;height:16px;border:2px solid var(--primary);border-top-color:transparent;border-radius:50%;animation:eqas-spin 0.8s linear infinite;"></div>
                            <span style="font-size:13px;font-weight:600;">جارٍ تجهيز التصدير...</span>
                        </div>
                        <div style="background:var(--border-light);border-radius:var(--radius-md);height:8px;overflow:hidden;">
                            <div id="progress-bar" style="width:0%;height:100%;background:var(--primary);border-radius:var(--radius-md);transition:width 0.3s;"></div>
                        </div>
                    </div>

                    <div class="mt-4" style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary btn-lg" id="export-btn" onclick="showProgress()">
                            📥 تصدير البيانات
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="previewExport()">
                            👁️ معاينة البيانات
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="saveSchedule()">
                            💾 حفظ كقالب
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- معاينة البيانات -->
        <div class="card mt-4" id="preview-card" style="display:none;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 class="card-title">👁️ معاينة البيانات (أول 5 صفوف)</h2>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('preview-card').style.display='none'">✕ إغلاق</button>
            </div>
            <div class="card-body">
                <div id="preview-content" class="table-responsive">
                    <p class="text-muted text-center">جارٍ تحميل المعاينة...</p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- إحصائيات التصدير -->
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">📊 إحصائيات التصدير</h2>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6 mb-3">
                        <div style="font-size:24px;font-weight:700;color:var(--primary);"><?php echo number_format($totalExports); ?></div>
                        <div style="font-size:12px;color:var(--text-secondary);">إجمالي التصديرات</div>
                    </div>
                    <div class="col-6 mb-3">
                        <div style="font-size:24px;font-weight:700;color:var(--primary);">
                            <?php echo $totalSize > 1048576 ? round($totalSize / 1048576, 1) . ' MB' : round($totalSize / 1024, 1) . ' KB'; ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-secondary);">إجمالي الحجم</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- القوالب المحفوظة -->
        <?php if (!empty($savedTemplates)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">💾 القوالب المحفوظة</h2>
            </div>
            <div class="card-body" style="max-height:200px;overflow-y:auto;">
                <?php foreach ($savedTemplates as $tpl): ?>
                <?php $cfg = json_decode($tpl['config'], true); ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <div class="font-semibold text-sm"><?php echo e($tpl['name']); ?></div>
                        <div class="text-xs text-muted"><?php echo e($exportTypes[$cfg['type']]['label'] ?? $cfg['type']); ?> | <?php echo strtoupper($cfg['format'] ?? 'xlsx'); ?></div>
                    </div>
                    <a href="?apply_template=<?php echo $tpl['id']; ?>" class="btn btn-sm btn-outline-primary">تطبيق</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- آخر عمليات التصدير -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📋 آخر عمليات التصدير</h2>
            </div>
            <div class="card-body" style="max-height:400px;overflow-y:auto;">
                <?php if (empty($recentExports)): ?>
                    <p class="text-muted text-center">لا توجد عمليات تصدير سابقة</p>
                <?php else: ?>
                    <?php foreach ($recentExports as $exp): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <div class="font-semibold text-sm"><?php echo e($exp['export_type']); ?></div>
                            <div class="text-xs text-muted"><?php echo formatDateAr($exp['created_at']); ?> | <?php echo $exp['row_count']; ?> صف</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span class="badge <?php echo $exp['status'] === 'completed' ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $exp['status'] === 'completed' ? 'مكتمل' : 'فشل'; ?>
                            </span>
                            <?php if ($exp['status'] === 'completed' && !empty($exp['file_path']) && file_exists($exp['file_path'])): ?>
                            <a href="history.php?download=<?php echo $exp['id']; ?>" class="btn btn-sm btn-outline-success" title="تنزيل" style="padding:2px 6px;font-size:12px;">📥</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="mt-3 text-center">
                    <a href="history.php" class="btn btn-sm btn-outline-primary">عرض السجل الكامل</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- نموذج حفظ القالب (مخفي) -->
<div id="schedule-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:none;align-items:center;justify-content:center;">
    <div style="background:var(--background);border-radius:var(--radius-md);padding:24px;width:90%;max-width:450px;direction:rtl;">
        <h3 style="margin-bottom:16px;color:var(--primary);">💾 حفظ إعدادات التصدير</h3>
        <form method="POST" id="schedule-form">
            <?= csrfField() ?>
            <input type="hidden" name="save_schedule" value="1">
            <input type="hidden" name="schedule_type" id="schedule-type">
            <input type="hidden" name="schedule_format" id="schedule-format">
            <input type="hidden" name="schedule_school_id" id="schedule-school-id">
            <input type="hidden" name="schedule_academic_year_id" id="schedule-academic-year-id">

            <div class="form-group">
                <label class="form-label">اسم القالب</label>
                <input type="text" name="schedule_name" class="form-control" placeholder="مثال: تقرير الطلاب الشهري" required>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px;">
                <button type="submit" class="btn btn-primary">💾 حفظ</button>
                <button type="button" class="btn btn-outline-secondary" onclick="closeScheduleModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<style>
.export-type-card:has(input:checked) {
    border-color: var(--primary) !important;
    background-color: rgba(22, 163, 74, 0.05);
}
.export-template-card:hover {
    border-color: var(--primary) !important;
    background-color: rgba(22, 163, 74, 0.05);
    transform: translateY(-2px);
}
.format-option-label:has(input:checked) {
    border-color: var(--primary) !important;
    background-color: rgba(22, 163, 74, 0.05);
}
@keyframes eqas-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
// تحديث تقدير الحجم
function updateEstimate(type) {
    const estimates = <?php echo json_encode($exportEstimates, JSON_UNESCAPED_UNICODE); ?>;
    const est = estimates[type];
    const el = document.getElementById('export-estimate');
    const text = document.getElementById('estimate-text');
    if (est && est.rows > 0) {
        el.style.display = 'block';
        text.textContent = '≈ ' + Number(est.rows).toLocaleString() + ' صف | الحجم التقديري: ' + est.size_label;
    } else {
        el.style.display = 'block';
        text.textContent = 'لا توجد بيانات متاحة';
    }
}

// تطبيق قالب سريع
function applyTemplate(key, type, format) {
    const form = document.getElementById('export-form');
    const typeRadio = form.querySelector('input[name="export_type"][value="' + type + '"]');
    const formatRadio = form.querySelector('input[name="format"][value="' + format + '"]');
    if (typeRadio) { typeRadio.checked = true; updateEstimate(type); }
    if (formatRadio) { formatRadio.checked = true; }
    window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
}

// عرض شريط التقدم
function showProgress() {
    const progress = document.getElementById('export-progress');
    const bar = document.getElementById('progress-bar');
    const btn = document.getElementById('export-btn');
    progress.style.display = 'block';
    btn.disabled = true;
    btn.innerHTML = '⏳ جارٍ التصدير...';
    let pct = 0;
    const interval = setInterval(() => {
        pct = Math.min(pct + Math.random() * 20, 90);
        bar.style.width = pct + '%';
        if (pct >= 90) clearInterval(interval);
    }, 300);
}

// معاينة البيانات
function previewExport() {
    const form = document.getElementById('export-form');
    const type = form.querySelector('input[name="export_type"]:checked')?.value;
    if (!type) { alert('يرجى اختيار نوع التصدير أولاً'); return; }

    const previewCard = document.getElementById('preview-card');
    const previewContent = document.getElementById('preview-content');
    previewCard.style.display = 'block';
    previewContent.innerHTML = '<p class="text-muted text-center">جارٍ تحميل المعاينة...</p>';
    window.scrollTo({ top: previewCard.offsetTop - 20, behavior: 'smooth' });

    // محاكاة المعاينة - في الإنتاج يتم جلبها عبر AJAX
    setTimeout(() => {
        const typeLabels = <?php echo json_encode(array_map(function($t) { return $t['label']; }, $exportTypes), JSON_UNESCAPED_UNICODE); ?>;
        previewContent.innerHTML = '<div style="padding:16px;background:rgba(255,193,7,0.08);border-radius:var(--radius-md);border-right:4px solid #ffc107;font-size:13px;">' +
            '📋 معاينة بيانات: <strong>' + (typeLabels[type] || type) + '</strong><br>' +
            '<small>يتم عرض أول 5 صفوف كمعاينة. اضغط "تصدير البيانات" للحصول على الملف الكامل.</small></div>';
    }, 500);
}

// فتح نافذة حفظ القالب
function saveSchedule() {
    const form = document.getElementById('export-form');
    const type = form.querySelector('input[name="export_type"]:checked')?.value;
    const format = form.querySelector('input[name="format"]:checked')?.value;
    const schoolId = form.querySelector('select[name="school_id"]')?.value || '';
    const academicYearId = form.querySelector('select[name="academic_year_id"]')?.value || '';

    if (!type) { alert('يرجى اختيار نوع التصدير أولاً'); return; }

    document.getElementById('schedule-type').value = type;
    document.getElementById('schedule-format').value = format || 'xlsx';
    document.getElementById('schedule-school-id').value = schoolId;
    document.getElementById('schedule-academic-year-id').value = academicYearId;

    const modal = document.getElementById('schedule-modal');
    modal.style.display = 'flex';
}

function closeScheduleModal() {
    document.getElementById('schedule-modal').style.display = 'none';
}

// تهيئة التقدير عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    const checked = document.querySelector('input[name="export_type"]:checked');
    if (checked) updateEstimate(checked.value);
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
