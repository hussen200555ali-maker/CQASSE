<?php
/**
 * ============================================================
 * EQAS - Annual Report Generator
 * التقرير السنوي لمؤشرات جودة التعليم (PDF)
 * ============================================================
 */

// ===================== التحميل الموحد =====================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'التقرير السنوي لمؤشرات الجودة';
$breadcrumb = ['التقارير' => null, 'التقرير السنوي' => null];

// ===================== معالج POST =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'group_admin') {
        die('Unauthorized');
    }
    global $db;

    require_once __DIR__ . '/../../includes/report_template.php';
    require_once __DIR__ . '/../../includes/eqi.php';
    require_once __DIR__ . '/../../includes/contextual_adjustment.php';

    $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
    $semesterId = (int)($_POST['semester_id'] ?? 0);
    $preparedBy = $_SESSION['user']['full_name'] ?? 'مدير النظام';

    // الحصول على اسم العام الدراسي والفصل
    $yearStmt = $db->prepare("SELECT name FROM academic_years WHERE id = ?");
    $yearStmt->execute([$academicYearId]);
    $yearName = $yearStmt->fetchColumn() ?: '';

    $semStmt = $db->prepare("SELECT name_ar FROM semesters WHERE id = ?");
    $semStmt->execute([$semesterId]);
    $semName = $semStmt->fetchColumn() ?: '';

    // بيانات إحصائية
    $stats = [];
    $stats['schools'] = $db->query("SELECT COUNT(*) FROM schools WHERE is_active = 1")->fetchColumn();
    $stats['students'] = $db->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    $stats['teachers'] = $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.is_active = 1")->fetchColumn();
    $stats['classes'] = $db->query("SELECT COUNT(*) FROM classes WHERE is_active = 1")->fetchColumn();

    // بيانات EQI
    $eqiCalc = new EQICalculator($db);
    $allSchoolsEQI = $eqiCalc->getAllSchoolsEQI($academicYearId, $semesterId);

    // EQI trend (نفس الفصل من العام السابق)
    $trendData = [];
    $prevYearStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
    $prevYearStmt->execute([$academicYearId]);
    $prevYearId = $prevYearStmt->fetchColumn();

    if ($prevYearId) {
        $prevSemStmt = $db->prepare("SELECT s2.id FROM semesters s2 JOIN semesters s1 ON s1.id = ? WHERE s2.academic_year_id = ? AND s2.name_ar = s1.name_ar AND s2.is_active = 1 LIMIT 1");
        $prevSemStmt->execute([$semesterId, $prevYearId]);
        $prevSemId = $prevSemStmt->fetchColumn();
        if ($prevSemId) {
            $prevEQI = $eqiCalc->getAllSchoolsEQI($prevYearId, $prevSemId);
            foreach ($prevEQI as $p) {
                $trendData[$p['school_id']] = $p['overall_eqi'];
            }
        }
    }

    // التعديل السياقي
    $adjustService = new ContextualAdjustmentService($db);
    $schoolIds = array_map(function($s) { return $s['school_id']; }, $allSchoolsEQI);
    $rawMap = [];
    foreach ($allSchoolsEQI as $s) {
        $rawMap[$s['school_id']] = $s['overall_eqi'];
    }
    $adjustedMap = $adjustService->batchCalculateAdjustedSQI($schoolIds, $rawMap);

    // ================================================================
    // بناء التقرير
    // ================================================================

    $title = "التقرير السنوي لمؤشرات جودة التعليم - $yearName";
    $report = new ReportTemplate($title, 'P', 'A4');

    // ─── الغلاف ───
    $logoPath = __DIR__ . '/../../assets/img/logo-login.svg';
    if (!file_exists($logoPath)) $logoPath = null;
    $report->addCoverPage($yearName, $preparedBy, $logoPath);

    // ─── المحتوى ───
    $report->startContentPages();

    // ================================================================
    // القسم 1: ملخص تنفيذي
    // ================================================================
    $report->addSectionTitle('الملخص التنفيذي');

    // KPI cards
    $avgEqi = 0;
    $eqiCount = 0;
    foreach ($allSchoolsEQI as $s) {
        if ($s['overall_eqi'] > 0) { $avgEqi += $s['overall_eqi']; $eqiCount++; }
    }
    $avgEqi = $eqiCount > 0 ? round($avgEqi / $eqiCount, 1) : 0;

    $report->addParagraph("يقدم هذا التقرير تحليلاً شاملاً لمؤشرات جودة التعليم للعام الدراسي {$yearName}، الفصل {$semName}. يغطي التقرير {$stats['schools']} مدارس، و {$stats['students']} طالباً وطالبة، و {$stats['teachers']} معلماً ومعلمة، موزعين على {$stats['classes']} شعبة صفية. يهدف التقرير إلى توفير رؤية استراتيجية متكاملة لمتخذي القرار لدعم جهود تحسين جودة التعليم.");
    $report->addParagraph("بلغ متوسط مؤشر جودة التعليم العام $avgEqi%، مما يعكس مستوى " . ($avgEqi >= 85 ? 'ممتازاً' : ($avgEqi >= 70 ? 'جيداً جداً' : ($avgEqi >= 50 ? 'مقبولاً' : 'ضعيفاً'))) . " على مستوى المجموعة التعليمية.");

    $report->addKPICards([
        ['label' => 'عدد المدارس', 'value' => number_format($stats['schools']), 'color' => [26, 86, 50]],
        ['label' => 'عدد الطلاب', 'value' => number_format($stats['students']), 'color' => [200, 169, 81]],
        ['label' => 'عدد المعلمين', 'value' => number_format($stats['teachers']), 'color' => [23, 162, 184]],
        ['label' => 'متوسط المؤشر', 'value' => number_format($avgEqi, 1) . '%', 'color' => [40, 167, 69]],
    ]);

    // ================================================================
    // القسم 2: إحصائيات عامة
    // ================================================================
    $report->addSectionTitle('إحصائيات عامة');
    $report->addParagraph("يوضح الجدول التالي توزيع المدارس حسب تصنيف مؤشر جودة التعليم. يشكل هذا التوزيع مؤشراً مهماً على مستوى الأداء العام للمجموعة التعليمية ويساعد في تحديد الأولويات والتدخلات المطلوبة.");

    $ratingBuckets = ['ممتاز' => 0, 'جيد جداً' => 0, 'مقبول' => 0, 'ضعيف' => 0];
    foreach ($allSchoolsEQI as $s) {
        $eqi = $s['overall_eqi'];
        if ($eqi >= 85) $ratingBuckets['ممتاز']++;
        elseif ($eqi >= 70) $ratingBuckets['جيد جداً']++;
        elseif ($eqi >= 50) $ratingBuckets['مقبول']++;
        else $ratingBuckets['ضعيف']++;
    }

    $distRows = [];
    $ratingColors = [
        'ممتاز' => [40, 167, 69],
        'جيد جداً' => [23, 162, 184],
        'مقبول' => [255, 193, 7],
        'ضعيف' => [220, 53, 69],
    ];
    foreach ($ratingBuckets as $label => $count) {
        $pct = $stats['schools'] > 0 ? round(($count / $stats['schools']) * 100, 1) : 0;
        $distRows[] = [
            ['text' => $label, 'align' => 'R'],
            ['text' => (string)$count, 'align' => 'C'],
            ['text' => number_format($pct, 1) . '%', 'align' => 'C', 'color' => $ratingColors[$label]],
        ];
    }

    $report->addTable(
        [
            ['text' => 'التصنيف', 'width' => 40],
            ['text' => 'عدد المدارس', 'width' => 30],
            ['text' => 'النسبة', 'width' => 30],
        ],
        $distRows
    );

    // ================================================================
    // القسم 3: اتجاهات مؤشر الجودة
    // ================================================================
    $report->addSectionTitle('اتجاهات مؤشر جودة التعليم');
    $report->addParagraph("يعرض هذا القسم مقارنة بين أداء المدارس في الفصل الحالي ونفس الفصل من العام الدراسي الماضي. تمكن هذه المقارنة متخذي القرار من تقييم أثر التدخلات التربوية ومدى التقدم المتحقق.");

    if (!empty($trendData)) {
        $trendRows = [];
        $trendImprove = 0; $trendDecline = 0; $trendStable = 0;
        foreach ($allSchoolsEQI as $s) {
            $sid = $s['school_id'];
            $curr = $s['overall_eqi'];
            $prev = $trendData[$sid] ?? null;
            if ($prev !== null && $curr > 0 && $prev > 0) {
                $diff = $curr - $prev;
                $pctChange = round(($diff / $prev) * 100, 1);
                $trendText = $pctChange > 1 ? "▲ +{$pctChange}%" : ($pctChange < -1 ? "▼ " . abs($pctChange) . "%" : '→ مستقر');
                if ($pctChange > 1) $trendImprove++;
                elseif ($pctChange < -1) $trendDecline++;
                else $trendStable++;
                $trendRows[] = [
                    $s['school_name'],
                    number_format($curr, 1) . '%',
                    number_format($prev, 1) . '%',
                    $trendText,
                ];
            }
        }

        if (!empty($trendRows)) {
            $report->addSubTitle('تحليل الاتجاهات');
            $report->addParagraph("من بين " . count($trendRows) . " مدارس تتوفر لها بيانات مقارنة، سجلت $trendImprove مدارس تحسناً في مؤشر الجودة، بينما سجلت $trendDecline مدارس تراجعاً، واستقر أداء $trendStable مدارس. هذا يعكس " . ($trendImprove > $trendDecline ? 'اتجاه عام نحو التحسن' : 'حاجة إلى مزيد من التدخلات') . ".");
            $report->addTable(
                [
                    ['text' => 'المدرسة', 'width' => 40],
                    ['text' => 'الحالي', 'width' => 20],
                    ['text' => 'السابق', 'width' => 20],
                    ['text' => 'الاتجاه', 'width' => 20],
                ],
                $trendRows
            );
        }
    } else {
        $report->addParagraph("لا تتوفر بيانات للعام الدراسي السابق للمقارنة. هذا هو العام الأساسي لقياس الاتجاهات.");
    }

    // ================================================================
    // القسم 4: تصنيف المدارس
    // ================================================================
    $report->addSectionTitle('تصنيف المدارس حسب مؤشر الجودة');
    $report->addParagraph("الجدول التالي يعرض ترتيب المدارس تنازلياً حسب مؤشر جودة التعليم، مع التقييم النوعي والرتبة. يساعد هذا التصنيف في التعرف على المدارس المتميزة والمدارس التي تحتاج دعماً إضافياً.");

    // ترتيب المدارس
    usort($allSchoolsEQI, function($a, $b) {
        return ($b['overall_eqi'] ?? 0) <=> ($a['overall_eqi'] ?? 0);
    });

    $rankRows = [];
    $rankNum = 1;
    foreach ($allSchoolsEQI as $s) {
        $eqi = $s['overall_eqi'];
        if ($eqi <= 0) continue;
        $rating = getEqiRating($eqi);
        $rankRows[] = [
            ['text' => (string)$rankNum++, 'align' => 'C'],
            $s['school_name'],
            ['text' => number_format($eqi, 1) . '%', 'align' => 'C'],
            ['text' => $rating[0], 'align' => 'C', 'color' => $rating[2] === 'success' ? [40,167,69] : ($rating[2] === 'warning' ? [255,193,7] : [220,53,69])],
        ];
    }

    $report->addTable(
        [
            ['text' => '#', 'width' => 10],
            ['text' => 'المدرسة', 'width' => 40],
            ['text' => 'مؤشر الجودة', 'width' => 25],
            ['text' => 'التقييم', 'width' => 25],
        ],
        $rankRows
    );

    // ================================================================
    // القسم 5: التعديل السياقي
    // ================================================================
    $report->addSectionTitle('التعديل السياقي');
    $report->addParagraph("يهدف التعديل السياقي إلى تقديم صورة أكثر عدلاً عن أداء المدارس من خلال مراعاة الظروف والتحديات التي تواجهها كل مدرسة. يتم حساب مؤشر التحدي السياقي بناءً على عوامل مثل: نوع المنطقة، المستوى الاقتصادي الاجتماعي، نسبة الطلاب إلى المعلمين، جودة البنية التحتية، ومستوى تعليم أولياء الأمور.");

    $adjRows = [];
    foreach ($allSchoolsEQI as $s) {
        $sid = $s['school_id'];
        $adj = $adjustedMap[$sid] ?? null;
        if ($adj && $adj['adjusted_sqi'] > 0) {
            $adjRows[] = [
                $s['school_name'],
                ['text' => number_format($adj['raw_sqi'], 1) . '%', 'align' => 'C'],
                ['text' => number_format($adj['adjusted_sqi'], 1) . '%', 'align' => 'C'],
                ['text' => $adj['has_context'] ? number_format($adj['challenge_index'], 0) . '/100' : '—', 'align' => 'C'],
            ];
        }
    }

    if (!empty($adjRows)) {
        $report->addTable(
            [
                ['text' => 'المدرسة', 'width' => 40],
                ['text' => 'الخام', 'width' => 20],
                ['text' => 'المعدّل', 'width' => 20],
                ['text' => 'مؤشر التحدي', 'width' => 20],
            ],
            $adjRows
        );
    } else {
        $report->addParagraph("لم يتم تسجيل بيانات سياقية لأي مدرسة لهذا العام. يمكن إضافة البيانات السياقية من خلال صفحة إدارة المدارس للحصول على تعديل سياقي دقيق.");
    }

    // ================================================================
    // القسم 6: التوصيات
    // ================================================================
    $report->addSectionTitle('التوصيات');
    $report->addParagraph("بناءً على تحليل البيانات الواردة في هذا التقرير، نوصي باتخاذ الإجراءات التالية لتحسين جودة التعليم على مستوى المجموعة:");
    $report->Ln(2);

    $recommendations = [];

    // توصيات بناءً على المدارس الضعيفة
    $weakSchools = array_filter($allSchoolsEQI, function($s) { return $s['overall_eqi'] > 0 && $s['overall_eqi'] < 50; });
    if (count($weakSchools) > 0) {
        $names = array_map(function($s) { return $s['school_name']; }, array_slice($weakSchools, 0, 3));
        $recommendations[] = "مدارس ذات أداء ضعيف: " . implode('، ', $names) . " — يوصى بتدخل عاجل يتضمن خطة تحسين شاملة، زيارات إشرافية مكثفة، وبرامج تدريب مكثفة للكوادر التعليمية.";
    }

    // توصيات بناءً على الاتجاهات
    if (!empty($trendData) && isset($trendDecline) && $trendDecline > 0) {
        $recommendations[] = "سجلت $trendDecline مدارس تراجعاً في مؤشر الجودة مقارنة بالعام السابق — يوصى بتحليل أسباب التراجع لكل مدرسة على حدة وتصميم خطط علاجية مخصصة.";
    }

    $recommendations[] = "تفعيل برامج التطوير المهني المستمر للمعلمين، مع التركيز على استراتيجيات التدريس الحديثة والتقويم التربوي.";
    $recommendations[] = "تعزيز مشاركة أولياء الأمور في العملية التعليمية، خاصة في المدارس ذات المستوى الاقتصادي الاجتماعي المنخفض.";
    $recommendations[] = "تطوير نظام متابعة دورية لمؤشرات الجودة لرصد التحسن أو التراجع بشكل فوري.";
    $recommendations[] = "الاستفادة من المدارس المتميزة كمراكز خبرة لنقل الممارسات الجيدة إلى بقية المدارس.";
    $recommendations[] = "اعتماد التعديل السياقي في تقييم أداء المدارس لضمان العدالة في المقارنات بين المدارس ذات الظروف المختلفة.";

    $report->addRecommendations($recommendations);

    // ─── إخراج PDF ───
    $filename = "EQAS_Annual_Report_{$yearName}_{$semName}.pdf";
    $filename = str_replace(['/', '\\', ' ', '،', ','], '_', $filename);
    $report->output($filename, 'D');
    exit;
}

// ─── عرض صفحة الاختيار (GET) ───
require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('../../index.php');
}

$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedYear = (int)($_GET['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1");
        $stmt->execute([$selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}
?>
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📄 التقرير السنوي لمؤشرات جودة التعليم</h3>
        </div>
        <div class="card-body">
            <div style="background:var(--bg-info-light);border:1px solid var(--border-info);border-radius:8px;padding:16px;margin-bottom:20px;">
                <p style="margin:0;color:var(--text-info);">
                    يُنشئ هذا التقرير وثيقة PDF احترافية تتضمن: غلاف، ملخص تنفيذي، إحصائيات عامة، اتجاهات مؤشر الجودة،
                    تصنيف المدارس، التعديل السياقي، وتوصيات استراتيجية.
                </p>
            </div>

            <form method="POST" action="" id="reportForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية</label>
                        <select name="academic_year_id" id="academicYearSelect" class="form-control" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي</label>
                        <select name="semester_id" id="semesterSelect" class="form-control" required>
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>"><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-top:1.5rem;">
                    <button type="submit" class="btn btn-primary">
                        📄 إنشاء التقرير السنوي (PDF)
                    </button>
                    <div id="loadingIndicator" style="display:none;margin-top:10px;color:var(--text-muted);">
                        جاري إنشاء التقرير... قد يستغرق ذلك بضع لحظات
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('academicYearSelect').addEventListener('change', function() {
    var yearId = this.value;
    var semSelect = document.getElementById('semesterSelect');
    semSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    fetch('annual_report.php?academic_year_id=' + yearId)
        .then(r => r.text())
        .then(html => {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newSelect = doc.getElementById('semesterSelect');
            if (newSelect) {
                semSelect.innerHTML = newSelect.innerHTML;
            }
        });
});

document.getElementById('reportForm').addEventListener('submit', function() {
    document.getElementById('loadingIndicator').style.display = 'block';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
