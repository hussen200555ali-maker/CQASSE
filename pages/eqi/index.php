<?php
/**
 * ============================================================
 * EQAS - مؤشر جودة التعليم - لوحة التحكم
 * نظام التقييم الشامل - EQI Dashboard
 * ============================================================
 */

$pageTitle = 'مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
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

// الحصول على السنوات
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedSchool = (int)($_GET['school_id'] ?? ($schools[0]['id'] ?? 0));
$selectedYear = (int)($_GET['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$selectedSemester = (int)($_GET['semester_id'] ?? 0);

// الحصول على الفصول
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1");
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
        if (!$selectedSemester && !empty($semesters)) {
            $selectedSemester = $semesters[0]['id'];
        }
    } catch (Exception $e) {}
}

// الحصول على حساب EQI
$currentCalc = null;
$history = [];
if ($selectedSchool && $selectedYear && $selectedSemester) {
    $currentCalc = $eqiCalc->getEQIDimensions($selectedSchool, $selectedYear, $selectedSemester);
    $history = $eqiCalc->getEQIHistory($selectedSchool, 10);
}

$dimensions = $eqiCalc->getDimensions();
$rating = $currentCalc ? $eqiCalc->getEQIRating($currentCalc['overall_eqi'] ?? 0) : null;

// ترتيب المدارس (لمدير النظام)
$ranking = [];
if ($userRole === 'group_admin' && $selectedYear && $selectedSemester) {
    $ranking = $eqiCalc->getSchoolRanking($selectedYear, $selectedSemester, 10);
}
?>

<style>
.eqi-hero {
    background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%);
    border-radius: 16px;
    padding: 2rem 2.5rem;
    margin-bottom: 1.5rem;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.eqi-hero::before {
    content: '';
    position: absolute;
    top: -60%;
    left: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(200,169,81,0.12) 0%, transparent 70%);
    border-radius: 50%;
}
.eqi-hero::after {
    content: '';
    position: absolute;
    bottom: -40%;
    right: -5%;
    width: 350px;
    height: 350px;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    border-radius: 50%;
}
.eqi-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.eqi-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
.filter-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}
.filter-card label { font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.35rem; display: block; }
.filter-card select, .filter-card .form-control {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    min-width: 180px;
    font-size: 0.9rem;
    color: #1a1a2e;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.filter-card select:focus, .filter-card .form-control:focus {
    border-color: #1a5632;
    box-shadow: 0 0 0 3px rgba(26,86,50,0.12);
    outline: none;
}
.chart-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
    overflow: hidden;
    transition: box-shadow 0.2s;
}
.chart-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
.chart-card-header {
    padding: 1.1rem 1.5rem;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.chart-card-header h2, .chart-card-header h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.chart-card-body { padding: 1.5rem; }
.kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.kpi-item {
    border-radius: 12px;
    padding: 1.25rem;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.kpi-item:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
.kpi-item .kpi-ico {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin-bottom: 0.75rem;
}
.kpi-item .kpi-num { font-size: 1.8rem; font-weight: 800; line-height: 1.2; }
.kpi-item .kpi-lbl { font-size: 0.8rem; font-weight: 500; opacity: 0.85; margin-top: 0.2rem; }
.kpi-item .kpi-sub { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; }
.kpi-green { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); color: #1b5e20; }
.kpi-green .kpi-ico { background: rgba(26,86,50,0.15); }
.kpi-gold { background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%); color: #7c5a00; }
.kpi-gold .kpi-ico { background: rgba(200,169,81,0.2); }

.kpi-teal { background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%); color: #004d40; }
.kpi-teal .kpi-ico { background: rgba(0,150,136,0.15); }
.gauge-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2rem;
    flex-wrap: wrap;
}
.gauge-box {
    position: relative;
    width: 200px;
    height: 200px;
}
.gauge-center {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
}
.gauge-center .gauge-val {
    font-size: 2.2rem; font-weight: 800; line-height: 1;
}
.gauge-center .gauge-lbl {
    font-size: 0.85rem; font-weight: 600; margin-top: 0.2rem;
}
.dim-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.dim-table th {
    background: #f8f9fa;
    font-weight: 600; text-align: right; padding: 0.75rem 1rem;
    font-size: 0.8rem; color: #6c757d;
    text-transform: uppercase; letter-spacing: 0.03em;
    border-bottom: 2px solid #dee2e6;
}
.dim-table th:first-child { border-radius: 0 8px 0 0; }
.dim-table th:last-child { border-radius: 8px 0 0 0; }
.dim-table td { padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; font-size: 0.9rem; }
.dim-table tbody tr { transition: background 0.15s; }
.dim-table tbody tr:hover { background: #f1f3f5; }
.dim-table tbody tr:last-child td { border-bottom: none; }
.pct-track {
    display: inline-flex; align-items: center; gap: 0.5rem;
}
.pct-track .track {
    width: 80px; height: 7px; background: #e9ecef;
    border-radius: 4px; overflow: hidden;
}
.pct-track .fill {
    height: 100%; border-radius: 4px;
    transition: width 0.6s ease;
}
.badge-rating {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.2rem 0.7rem; border-radius: 999px;
    font-size: 0.78rem; font-weight: 600;
}
.badge-available { background: #e8f5e9; color: #1b5e20; }
.badge-missing { background: #fbe9e7; color: #b71c1c; }
.rank-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    font-size: 0.85rem; font-weight: 700;
}
.rank-gold { background: #fff8e1; color: #7c5a00; box-shadow: 0 0 0 2px #ffd54f; }
.rank-silver { background: #f5f5f5; color: #424242; box-shadow: 0 0 0 2px #bdbdbd; }
.rank-bronze { background: #fbe9e7; color: #6d4c41; box-shadow: 0 0 0 2px #ffab91; }
.rank-plain { background: #f1f3f5; color: #6c757d; }
.empty-box {
    text-align: center; padding: 3rem 1.5rem; color: #6c757d;
}
.empty-box .empty-ico { font-size: 3rem; margin-bottom: 0.75rem; }
.empty-box h3 { font-size: 1.1rem; margin-bottom: 0.5rem; color: #1a1a2e; }
@media (max-width:768px) {
    .eqi-hero { padding: 1.25rem; border-radius: 12px; }
    .kpi-row { grid-template-columns: repeat(2,1fr); }
}
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="eqi-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>مؤشر جودة التعليم</h1>
                <div class="hero-sub">
                    <?php if ($selectedSchool):
                        $schoolIdx = array_search($selectedSchool, array_column($schools, 'id'));
                        $schoolName = $schoolIdx !== false ? ($schools[$schoolIdx]['name_ar'] ?? '') : '';
                    ?>
                        مدرسة: <?= e($schoolName) ?>
                    <?php else: ?>
                        جميع المدارس
                    <?php endif; ?>
                    <span style="margin:0 0.5rem;">·</span>
                    <?php if ($selectedSemester && count($semesters)):
                        $semFilter = array_filter($semesters, fn($s) => (int)$s['id'] === $selectedSemester);
                        $semFirst = !empty($semFilter) ? reset($semFilter) : null;
                    ?>
                        <?= e($semFirst['name_ar'] ?? '') ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="calculate.php" class="btn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;font-size:0.85rem;padding:0.4rem 1rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">🔄 حساب المؤشر</a>
                <a href="compare.php" class="btn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;font-size:0.85rem;padding:0.4rem 1rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">📊 مقارنة</a>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-card">
        <form method="GET" style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;">
            <?php if ($userRole === 'group_admin'): ?>
            <div>
                <label>المدرسة</label>
                <select name="school_id" onchange="this.form.submit()">
                    <?php foreach ($schools as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selectedSchool == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label>السنة الدراسية</label>
                <select name="academic_year_id" onchange="this.form.submit()">
                    <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>الفصل الدراسي</label>
                <select name="semester_id" onchange="this.form.submit()">
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="font-size:0.8rem;color:#6c757d;padding-top:0.5rem;">↵ التحديث التلقائي</div>
        </form>
    </div>

    <?php if ($currentCalc): ?>

    <!-- KPI Row -->
    <div class="kpi-row">
        <div class="kpi-item kpi-green">
            <div class="kpi-ico">📊</div>
            <div class="kpi-num"><?= number_format($currentCalc['overall_eqi'], 1) ?></div>
            <div class="kpi-lbl">مؤشر جودة التعليم</div>
            <div class="kpi-sub"><?= $rating['text'] ?></div>
        </div>
        <div class="kpi-item kpi-gold">
            <div class="kpi-ico">🏆</div>
            <div class="kpi-num"><?= $rating['text'] ?></div>
            <div class="kpi-lbl">التصنيف</div>
            <div class="kpi-sub" style="direction:ltr;text-align:right;"><?= number_format($currentCalc['overall_eqi'], 1) ?> / 100</div>
        </div>
        <div class="kpi-item kpi-teal">
            <div class="kpi-ico">🏫</div>
            <div class="kpi-num"><?= count($schools) ?></div>
            <div class="kpi-lbl">عدد المدارس</div>
            <div class="kpi-sub"><?= $userRole === 'group_admin' ? 'إجمالي النظام' : 'مدرستك' ?></div>
        </div>
        <div class="kpi-item kpi-teal">
            <div class="kpi-ico">📅</div>
            <div class="kpi-num"><?= count($dimensions) ?></div>
            <div class="kpi-lbl">أبعاد التقييم</div>
            <div class="kpi-sub">6 أبعاد مرجحة</div>
        </div>
    </div>

    <!-- Gauge + Radar -->
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;margin-bottom:1.5rem;">
        <div class="chart-card">
            <div class="chart-card-header"><h3>📊 مؤشر جودة التعليم</h3></div>
            <div class="chart-card-body">
                <div class="gauge-wrap">
                    <div class="gauge-box">
                        <canvas id="eqi-gauge"></canvas>
                        <div class="gauge-center">
                            <div class="gauge-val" style="color:<?= $rating['color'] ?>;"><?= number_format($currentCalc['overall_eqi'], 1) ?></div>
                            <div class="gauge-lbl" style="color:<?= $rating['color'] ?>;"><?= $rating['text'] ?></div>
                        </div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:0.85rem;color:#6c757d;margin-bottom:0.5rem;">
                            <?php foreach (['academic','teaching','climate','behavior','skills','attendance'] as $k): ?>
                                <div style="display:flex;align-items:center;gap:0.35rem;margin:0.15rem 0;font-size:0.8rem;">
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= ($currentCalc[$k.'_score'] ?? 0) >= 80 ? '#28a745' : (($currentCalc[$k.'_score'] ?? 0) >= 50 ? '#ffc107' : '#dc3545') ?>;"></span>
                                    <span><?php $labels = ['academic'=>'أكاديمي','teaching'=>'تدريس','climate'=>'مناخ','behavior'=>'سلوك','skills'=>'مهارات','attendance'=>'حضور']; echo $labels[$k]; ?>: <?= number_format($currentCalc[$k.'_score'] ?? 0, 1) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:center;">
                            <a href="view.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-primary btn-sm">📊 التفاصيل</a>
                            <a href="calculate.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-outline-primary btn-sm" style="border:1px solid #1a5632;color:#1a5632;background:transparent;padding:0.25rem 0.75rem;border-radius:6px;font-size:0.8rem;text-decoration:none;">🔄 إعادة حساب</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-card-header">
            <h3>📊 توزيع الأبعاد</h3>
            <a href="dimensions.php" style="font-size:0.75rem;color:#6c757d;text-decoration:none;display:flex;align-items:center;gap:0.25rem;" title="تفاصيل الأبعاد">ℹ️ شرح الأبعاد</a>
        </div>
            <div class="chart-card-body">
                <div style="height:260px;">
                    <canvas id="eqi-radar"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Dimensions Table -->
    <div class="chart-card">
        <div class="chart-card-header"><h3>📋 تفاصيل الأبعاد</h3></div>
        <div class="chart-card-body" style="padding:0;">
            <table class="dim-table">
                <thead>
                    <tr>
                        <th>البعد</th>
                        <th>الدرجة</th>
                        <th>الوزن</th>
                        <th>الدرجة الموزونة</th>
                        <th>التصنيف</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $dimKeys = ['academic' => 'الأداء الأكاديمي', 'teaching' => 'جودة التدريس', 'climate' => 'المناخ المدرسي', 'behavior' => 'السلوك', 'skills' => 'المهارات', 'attendance' => 'الحضور'];
                    $dimIcons = ['academic' => '📚', 'teaching' => '👨‍🏫', 'climate' => '🏫', 'behavior' => '⭐', 'skills' => '🎯', 'attendance' => '📋'];
                    foreach ($dimKeys as $key => $name): 
                        $score = $currentCalc[$key . '_score'];
                        $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                        $barColor = $score >= 80 ? '#28a745' : ($score >= 50 ? '#ffc107' : ($score !== null ? '#dc3545' : '#dee2e6'));
                    ?>
                    <tr>
                        <td><span style="font-size:1.1rem;"><?= $dimIcons[$key] ?></span> <strong><?= $name ?></strong> <span style="color:#6c757d;font-size:0.78rem;">(<?= ($dimensions[$key]['weight'] * 100) ?>%)</span></td>
                        <td>
                            <?php if ($score !== null): ?>
                                <div class="pct-track">
                                    <span style="font-weight:700;color:<?= $dimRating['color'] ?>;min-width:40px;"><?= number_format($score, 1) ?>%</span>
                                    <div class="track"><div class="fill" style="width:<?= min($score, 100) ?>%;background:<?= $barColor ?>;"></div></div>
                                </div>
                            <?php else: ?>
                                <span style="color:#6c757d;">غير متوفر</span>
                            <?php endif; ?>
                        </td>
                        <td><?= ($dimensions[$key]['weight'] * 100) ?>%</td>
                        <td><?= $score !== null ? number_format($score * $dimensions[$key]['weight'], 2) : '-' ?></td>
                        <td>
                            <?php if ($dimRating): ?>
                                <span class="badge-rating" style="background:<?= $dimRating['color'] ?>20;color:<?= $dimRating['color'] ?>;"><?= $dimRating['text'] ?></span>
                            <?php else: ?>
                                <span class="badge-rating" style="background:#e9ecef;color:#6c757d;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($score !== null): ?>
                                <span class="badge-available badge-rating">✅ متوفر</span>
                            <?php else: ?>
                                <span class="badge-missing badge-rating">❌ مفقود</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8f9fa;">
                        <td><strong>المؤشر الكلي</strong></td>
                        <td colspan="2"></td>
                        <td><strong style="font-size:1rem;color:<?= $rating['color'] ?>;"><?= number_format($currentCalc['overall_eqi'], 2) ?></strong></td>
                        <td><span class="badge-rating" style="background:<?= $rating['color'] ?>;color:#fff;font-weight:700;"><?= $rating['text'] ?></span></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php if ($userRole === 'group_admin' && !empty($ranking)): ?>
    <!-- School Ranking -->
    <div class="chart-card">
        <div class="chart-card-header">
            <h3>🏆 ترتيب المدارس</h3>
            <a href="compare.php" class="btn btn-primary btn-sm" style="background:#1a5632;color:#fff;padding:0.3rem 0.75rem;border-radius:6px;font-size:0.8rem;text-decoration:none;">📊 مقارنة تفصيلية</a>
        </div>
        <div class="chart-card-body" style="padding:0;">
            <table class="dim-table">
                <thead>
                    <tr><th style="width:50px;">#</th><th>المدرسة</th><th>المؤشر</th><th>التصنيف</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $r): 
                        $rRating = $eqiCalc->getEQIRating($r['overall_eqi']);
                        $rk = (int)$r['rank'];
                        $rkClass = $rk === 1 ? 'rank-gold' : ($rk === 2 ? 'rank-silver' : ($rk === 3 ? 'rank-bronze' : 'rank-plain'));
                        $isCurrent = (int)$r['school_id'] === $selectedSchool;
                    ?>
                    <tr style="<?= $isCurrent ? 'background:rgba(26,86,50,0.06);' : '' ?>">
                        <td><span class="rank-num <?= $rkClass ?>"><?= $rk ?></span></td>
                        <td><strong><?= e($r['school_name']) ?></strong><?= $isCurrent ? ' <span style="color:#1a5632;font-size:0.75rem;">(المحددة)</span>' : '' ?></td>
                        <td>
                            <div class="pct-track">
                                <span style="font-weight:700;color:<?= $rRating['color'] ?>;"><?= number_format($r['overall_eqi'], 1) ?></span>
                                <div class="track"><div class="fill" style="width:<?= min($r['overall_eqi'], 100) ?>%;background:<?= $rRating['color'] ?>;"></div></div>
                            </div>
                        </td>
                        <td><span class="badge-rating" style="background:<?= $rRating['color'] ?>20;color:<?= $rRating['color'] ?>;"><?= $rRating['text'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($selectedSchool && $selectedYear && $selectedSemester): ?>
    <!-- Empty State -->
    <div class="chart-card">
        <div class="empty-box">
            <div class="empty-ico">📊</div>
            <h3>لا يوجد حساب لهذه الفترة</h3>
            <p style="color:#6c757d;font-size:0.9rem;margin-bottom:1rem;">لم يتم حساب مؤشر جودة التعليم لهذه المدرسة في الفترة المحددة.</p>
            <a href="calculate.php?school_id=<?= $selectedSchool ?>&year_id=<?= $selectedYear ?>&semester_id=<?= $selectedSemester ?>" class="btn btn-primary" style="background:#1a5632;color:#fff;padding:0.5rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600;">▶️ حساب المؤشر الآن</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if ($currentCalc):
$eqiRatingColor = $rating['color'] ?? '#6c757d';
$pageJs = "
new Chart(document.getElementById('eqi-radar'), {
    type: 'radar',
    data: {
        labels: ['الأكاديمي','التدريس','المناخ','السلوك','المهارات','الحضور'],
        datasets: [{
            label: 'مؤشر الجودة',
            data: [".($currentCalc['academic_score']??0).",".($currentCalc['teaching_score']??0).",".($currentCalc['climate_score']??0).",".($currentCalc['behavior_score']??0).",".($currentCalc['skills_score']??0).",".($currentCalc['attendance_score']??0)."],
            backgroundColor: 'rgba(26,86,50,0.15)',
            borderColor: '#1a5632',
            borderWidth: 2,
            pointBackgroundColor: '#1a5632',
            pointRadius: 4
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 20, font: { size: 10 } }, pointLabels: { font: { size: 11 } } } },
        plugins: { legend: { display: false } }
    }
});
new Chart(document.getElementById('eqi-gauge'), {
    type: 'doughnut',
    data: {
        datasets: [{
            data: [".($currentCalc['overall_eqi']??0).", ". (100 - ($currentCalc['overall_eqi']??0)) ."],
            backgroundColor: ['$eqiRatingColor', '#e9ecef'],
            borderWidth: 0,
            circumference: 270,
            rotation: 225,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        cutout: '78%',
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
