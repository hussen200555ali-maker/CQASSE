<?php
/**
 * ============================================================
 * EQAS - حساب مؤشر جودة التعليم
 * نظام التقييم الشامل - Calculate EQI
 * ============================================================
 */

$pageTitle = 'حساب مؤشر جودة التعليم';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'حساب المؤشر' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// المدارس
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

// السنوات
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedSchool = (int)($_GET['school_id'] ?? $_POST['school_id'] ?? ($schools[0]['id'] ?? 0));
$selectedYear = (int)($_GET['academic_year_id'] ?? $_POST['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));
$selectedSemester = (int)($_GET['semester_id'] ?? $_POST['semester_id'] ?? 0);

// الفصول
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = :year_id AND is_active = 1");
        $stmt->execute([':year_id' => $selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$result = null;
$allResults = [];
$batchMode = false;

// معالجة الحساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);
        
        // منع school_admin من استخدام قيمة 0 (جميع المدارس) في الطلب المُزيف
        if ($schoolId === 0 && $userRole !== 'group_admin') {
            setFlash('طلب غير صالح.', 'danger');
            redirect('calculate.php');
        }
        if ($yearId === 0 && $userRole !== 'group_admin') {
            setFlash('طلب غير صالح.', 'danger');
            redirect('calculate.php');
        }
        if ($semesterId === 0 && $userRole !== 'group_admin') {
            setFlash('طلب غير صالح.', 'danger');
            redirect('calculate.php');
        }
        // تجميع كل التركيبات
        $schoolIds = $schoolId > 0 ? [$schoolId] : ($userRole === 'group_admin' ? array_column($schools, 'id') : [$_SESSION['user']['school_id'] ?? 0]);
        $yearIds = $yearId > 0 ? [$yearId] : array_column($academicYears, 'id');
        
        $combinations = [];
        foreach ($schoolIds as $sid) {
            foreach ($yearIds as $yid) {
                $semIds = $semesterId > 0 ? [$semesterId] : [];
                if ($semesterId === 0) {
                    try {
                        $st = $db->prepare("SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1");
                        $st->execute([$yid]);
                        $semIds = $st->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) { $semIds = []; }
                }
                foreach ($semIds as $sid2) {
                    $combinations[] = ['school_id' => $sid, 'year_id' => $yid, 'semester_id' => $sid2];
                }
            }
        }
        
        $maxCombinations = 200;
        if (count($combinations) > $maxCombinations) {
            setFlash("عدد التركيبات كبير جداً (" . count($combinations) . "). الحد الأقصى هو $maxCombinations. يرجى تضييق نطاق البحث.", 'danger');
        } elseif (count($combinations) > 1) {
            $batchMode = true;
            $successCount = 0;
            $failCount = 0;
            foreach ($combinations as $combo) {
                $res = $eqiCalc->calculate($combo['school_id'], $combo['year_id'], $combo['semester_id']);
                $res['_school_id'] = $combo['school_id'];
                $res['_year_id'] = $combo['year_id'];
                $res['_semester_id'] = $combo['semester_id'];
                $allResults[] = $res;
                if ($res['eqi'] !== null) { $successCount++; } else { $failCount++; }
            }
            setFlash("تم حساب $successCount مؤشر بنجاح" . ($failCount > 0 ? "، $failCount فشل لعدم كفاية البيانات." : "."), $failCount > 0 ? 'warning' : 'success');
        } elseif (count($combinations) === 1) {
            $combo = $combinations[0];
            $result = $eqiCalc->calculate($combo['school_id'], $combo['year_id'], $combo['semester_id']);
            $result['_school_id'] = $combo['school_id'];
            $result['_year_id'] = $combo['year_id'];
            $result['_semester_id'] = $combo['semester_id'];
            if ($result['eqi'] !== null) {
                setFlash(sprintf('تم حساب المؤشر بنجاح: %.2f (%s)', $result['eqi'], $result['rating']['text']), 'success');
            } else {
                setFlash($result['message'] ?? 'لا تتوفر بيانات كافية للحساب.', 'warning');
            }
        } else {
            setFlash('يرجى اختيار المدرسة والسنة والفصل الدراسي.', 'warning');
        }
    }
}

$dimensions = $eqiCalc->getDimensions();
?>

<style>
.calc-hero {
    background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%);
    border-radius: 16px; padding: 2rem 2.5rem; margin-bottom: 1.5rem;
    color: #fff; position: relative; overflow: hidden;
}
.calc-hero::before {
    content: ''; position: absolute; top: -50%; right: -15%;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(200,169,81,0.12) 0%, transparent 70%);
    border-radius: 50%;
}
.calc-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.calc-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
.form-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden;
}
.form-card-header {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; gap: 0.5rem;
}
.form-card-header h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.form-card-body { padding: 1.5rem; }
.info-box {
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
    border-radius: 10px; border-right: 4px solid #1a5632;
    margin-bottom: 1.25rem; font-size: 0.9rem;
}
.info-box strong { color: #1a5632; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.form-field { }
.form-field label { font-size: 0.8rem; font-weight: 600; color: #6c757d; margin-bottom: 0.35rem; display: block; }
.form-field label .req { color: #dc3545; }
.form-field select, .form-field .form-control {
    border: 1px solid #dee2e6; border-radius: 8px; padding: 0.55rem 1rem;
    width: 100%; font-size: 0.9rem; color: #1a1a2e; background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-field select:focus, .form-field .form-control:focus {
    border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.12); outline: none;
}
.btn-primary-eqi {
    display: inline-flex; align-items: center; gap: 0.4rem;
    background: #1a5632; color: #fff; padding: 0.6rem 1.5rem;
    border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-primary-eqi:hover { background: #0f3a20; }
.result-card {
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}
.result-header {
    padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 1rem;
}
.result-header h3 { margin: 0; font-size: 1.2rem; color: #1a1a2e; }
.result-body { padding: 1.5rem; background: #fff; }
.warn-box {
    padding: 0.9rem 1.1rem; border-radius: 8px; border-right: 4px solid #ffc107;
    background: #fffde7; font-size: 0.88rem; margin-bottom: 1.25rem;
}
.warn-box strong { color: #7c5a00; }
.calc-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.calc-table th {
    background: #f8f9fa; font-weight: 600; text-align: right;
    padding: 0.7rem 1rem; font-size: 0.78rem; color: #6c757d;
    border-bottom: 2px solid #dee2e6;
}
.calc-table th:first-child { border-radius: 0 8px 0 0; }
.calc-table th:last-child { border-radius: 8px 0 0 0; }
.calc-table td { padding: 0.7rem 1rem; border-bottom: 1px solid #e9ecef; font-size: 0.88rem; }
.calc-table tbody tr:hover { background: #f1f3f5; }
.calc-table tbody tr:last-child td { border-bottom: none; }
.pct-mini { display: inline-flex; align-items: center; gap: 0.4rem; }
.pct-mini .bar { width: 60px; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
.pct-mini .fill { height: 100%; border-radius: 3px; transition: width 0.6s ease; }
.status-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; }
.status-ok { background: #e8f5e9; color: #1b5e20; }
.status-partial { background: #fff8e1; color: #7c5a00; }
.status-missing { background: #fbe9e7; color: #b71c1c; }
.proxy-tag { font-size: 0.7rem; color: #6c757d; }
.tip-box {
    padding: 0.85rem 1rem; border-radius: 8px; border-right: 4px solid #ffc107;
    background: #fffde7; font-size: 0.85rem; color: #6c757d; margin-top: 1rem;
}
.tip-box strong { color: #7c5a00; }
@media (max-width:768px) {
    .calc-hero { padding: 1.25rem; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="calc-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>حساب مؤشر جودة التعليم</h1>
                <div class="hero-sub">احسب مؤشر EQI لمدرسة في فصل دراسي محدد باستخدام الطريقة الهجينة</div>
            </div>
            <a href="index.php" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">← العودة</a>
        </div>
    </div>

    <!-- Form -->
    <div class="form-card">
        <div class="form-card-header"><h3>▶️ حساب مؤشر جودة التعليم</h3></div>
        <div class="form-card-body">
            <div class="info-box"><strong>📐 طريقة الحساب الهجينة:</strong> يتم إعادة توزيع أوزان الأبعاد المفقودة بنسبة 50% تناسبية + 50% متساوية بين الأبعاد المتاحة.</div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-grid">
                    <div class="form-field">
                        <label>المدرسة <span class="req">*</span></label>
                        <select name="school_id" required>
                            <option value="">اختر المدرسة</option>
                            <?php if ($userRole === 'group_admin'): ?>
                            <option value="0" <?= $selectedSchool === 0 ? 'selected' : '' ?>>جميع المدارس</option>
                            <?php endif; ?>
                            <?php foreach ($schools as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $selectedSchool == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>السنة الدراسية <span class="req">*</span></label>
                        <select name="academic_year_id" required>
                            <option value="">اختر السنة</option>
                            <?php if ($userRole === 'group_admin'): ?>
                            <option value="0" <?= $selectedYear === 0 ? 'selected' : '' ?>>جميع السنوات</option>
                            <?php endif; ?>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>الفصل الدراسي <span class="req">*</span></label>
                        <select name="semester_id" required>
                            <option value="">اختر الفصل</option>
                            <?php if ($userRole === 'group_admin'): ?>
                            <option value="0" <?= $selectedSemester === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                            <?php endif; ?>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>" <?= $selectedSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top:1.25rem;">
                    <button type="submit" name="calculate" value="1" class="btn-primary-eqi" onclick="return confirm('هل تريد حساب مؤشر جودة التعليم؟ سيتم استبدال أي حساب سابق.')">▶️ حساب المؤشر</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Result -->
    <?php if ($result !== null && $result['eqi'] !== null):
        $rc = $result['rating']['color'] ?? '#6c757d';
    ?>
    <div class="result-card">
        <div class="result-header" style="background:<?= $rc ?>;color:#fff;">
            <span style="font-size:2rem;">📊</span>
            <div>
                <h3 style="color:#fff;"><?= number_format($result['eqi'] ?? 0, 2) ?> — <?= $result['rating']['text'] ?? 'غير محدد' ?></h3>
                <span style="opacity:0.9;font-size:0.85rem;">مؤشر جودة التعليم</span>
            </div>
        </div>
        <div class="result-body">
            <?php if (!empty($result['missing_dimensions']) && !empty($result['redistribution_info'])): ?>
            <div class="warn-box">
                <strong>⚠️ أبعاد مفقودة:</strong> <?= implode('، ', $result['redistribution_info']['missing_names'] ?? []) ?>
                <div style="margin-top:0.25rem;font-size:0.8rem;color:#6c757d;">تم إعادة توزيع الوزن (<?= number_format(($result['redistribution_info']['missing_weight'] ?? 0) * 100, 1) ?>%) بالطريقة الهجينة.</div>
            </div>
            <?php endif; ?>
            <div style="height:260px;max-width:380px;margin:0 auto 1.25rem;">
                <canvas id="result-radar"></canvas>
            </div>
            <div style="overflow-x:auto;">
                <table class="calc-table">
                    <thead>
                        <tr><th>البعد</th><th>الدرجة</th><th>الوزن الأصلي</th><th>الوزن الفعلي</th><th>الدرجة الموزونة</th><th>التصنيف</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dimensions as $key => $dim): 
                            $detail = $result['dimension_details'][$key] ?? null;
                            $score = $detail['score'] ?? null;
                            $origWeight = $dim['weight'] * 100;
                            $effWeight = isset($result['weights'][$key]) ? $result['weights'][$key] * 100 : 0;
                            $dimRating = $score !== null ? $eqiCalc->getEQIRating($score) : null;
                            $barColor = $score >= 80 ? '#28a745' : ($score >= 50 ? '#ffc107' : ($score !== null ? '#dc3545' : '#dee2e6'));
                        ?>
                        <tr>
                            <td><?= $dim['icon'] ?> <strong><?= $dim['name'] ?></strong></td>
                            <td>
                                <?php if ($score !== null): ?>
                                    <div class="pct-mini">
                                        <span style="font-weight:700;color:<?= $dimRating['color'] ?>;"><?= number_format($score, 1) ?>%</span>
                                        <div class="bar"><div class="fill" style="width:<?= min($score, 100) ?>%;background:<?= $barColor ?>;"></div></div>
                                    </div>
                                    <?php if (($detail['source_type'] ?? '') === 'proxy'): ?>
                                        <span class="proxy-tag">(تقديري)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#6c757d;font-style:italic;">غير متوفر ❌</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($origWeight, 1) ?>%</td>
                            <td>
                                <?= number_format($effWeight, 1) ?>%
                                <?php if ($effWeight != $origWeight): ?>
                                    <span style="color:#ffc107;font-size:0.75rem;">(↑)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $score !== null ? number_format($score * ($result['weights'][$key] ?? 0), 2) : '-' ?></td>
                            <td>
                                <?php if ($dimRating): ?>
                                    <span style="padding:2px 8px;border-radius:999px;font-size:0.75rem;background:<?= $dimRating['color'] ?>20;color:<?= $dimRating['color'] ?>;font-weight:600;"><?= $dimRating['text'] ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8f9fa;">
                            <td><strong>المجموع</strong></td>
                            <td></td><td>100%</td><td>100%</td>
                            <td><strong style="font-size:1rem;color:<?= $rc ?>;"><?= number_format((float)($result['eqi'] ?? 0), 2) ?></strong></td>
                            <td><span style="padding:3px 10px;border-radius:999px;background:<?= $rc ?>;color:#fff;font-weight:600;font-size:0.8rem;"><?= $result['rating']['text'] ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div style="margin-top:1.25rem;text-align:center;">
                <a href="view.php?school_id=<?= $result['_school_id'] ?? $selectedSchool ?>&year_id=<?= $result['_year_id'] ?? $selectedYear ?>&semester_id=<?= $result['_semester_id'] ?? $selectedSemester ?>" class="btn-primary-eqi" style="display:inline-flex;">📊 عرض التفاصيل الكاملة</a>
            </div>
        </div>
    </div>

    <?php elseif ($batchMode && !empty($allResults)):
    $schoolNames = [];
    foreach ($schools as $s) { $schoolNames[$s['id']] = $s['name_ar']; }
    $semNames = [];
    $st = $db->query("SELECT id, name_ar FROM semesters WHERE is_active = 1");
    while ($r = $st->fetch()) { $semNames[$r['id']] = $r['name_ar']; }
    $yearNames = [];
    $st = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1");
    while ($r = $st->fetch()) { $yearNames[$r['id']] = $r['name']; }
    $batchSuccess = array_filter($allResults, fn($r) => $r['eqi'] !== null);
    $batchFail = array_filter($allResults, fn($r) => $r['eqi'] === null);
    ?>
    <div class="result-card">
        <div class="result-header" style="background:<?= !empty($batchFail) ? '#ffc107' : '#1a5632' ?>;color:#fff;">
            <span style="font-size:1.5rem;">📦</span>
            <div>
                <h3 style="color:#fff;margin:0;">نتائج الحساب الجماعي</h3>
                <span style="opacity:0.9;font-size:0.85rem;"><?= count($batchSuccess) ?> نجاح، <?= count($batchFail) ?> فشل — إجمالي <?= count($allResults) ?></span>
            </div>
        </div>
        <div class="result-body">
            <div style="overflow-x:auto;">
                <table class="calc-table">
                    <thead>
                        <tr><th>المدرسة</th><th>السنة</th><th>الفصل</th><th>EQI</th><th>التصنيف</th><th>الإجراءات</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allResults as $res):
                            $rSchool = $schoolNames[$res['_school_id']] ?? '—';
                            $rYear = $yearNames[$res['_year_id']] ?? '—';
                            $rSem = $semNames[$res['_semester_id']] ?? '—';
                            $rRating = $res['eqi'] !== null ? $eqiCalc->getEQIRating($res['eqi']) : null;
                        ?>
                        <tr>
                            <td><strong><?= e($rSchool) ?></strong></td>
                            <td><?= e($rYear) ?></td>
                            <td><?= e($rSem) ?></td>
                            <td>
                                <?php if ($res['eqi'] !== null): ?>
                                <strong style="color:<?= $rRating['color'] ?>;"><?= number_format($res['eqi'], 2) ?></strong>
                                <?php else: ?>
                                <span style="color:#6c757d;font-style:italic;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rRating): ?>
                                <span style="padding:2px 8px;border-radius:999px;font-size:0.75rem;background:<?= $rRating['color'] ?>20;color:<?= $rRating['color'] ?>;font-weight:600;"><?= $rRating['text'] ?></span>
                                <?php else: ?>
                                <span style="color:#6c757d;">غير كاف</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="view.php?school_id=<?= (int)$res['_school_id'] ?>&year_id=<?= (int)$res['_year_id'] ?>&semester_id=<?= (int)$res['_semester_id'] ?>" class="action-btn action-btn-primary action-btn-sm" style="font-size:0.72rem;padding:0.2rem 0.5rem;display:inline-flex;align-items:center;gap:0.25rem;border-radius:6px;font-weight:600;text-decoration:none;background:#1a5632;color:#fff;border:none;cursor:pointer;">عرض</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($result !== null && $result['eqi'] === null):
        $availability = $eqiCalc->checkDataAvailability($selectedSchool, $selectedYear, $selectedSemester);
        $dimNames = ['academic' => 'الأداء الأكاديمي','teaching' => 'جودة التدريس','climate' => 'المناخ المدرسي','behavior' => 'السلوك','skills' => 'المهارات','attendance' => 'الحضور'];
        $dimIcons = ['academic' => '📚','teaching' => '👨‍🏫','climate' => '🏫','behavior' => '⭐','skills' => '🎯','attendance' => '📋'];
    ?>
    <div class="result-card" style="border:2px solid #ffc107;">
        <div class="result-header" style="background:#fffde7;">
            <span style="font-size:1.5rem;">⚠️</span>
            <h3 style="color:#7c5a00;">لا توجد بيانات كافية لحساب المؤشر</h3>
        </div>
        <div class="result-body">
            <p style="margin-bottom:1rem;font-size:0.9rem;color:#6c757d;">المدرسة المختارة لا تحتوي على بيانات كافية للفترة المحددة. يُرجى إدخال البيانات التالية قبل حساب المؤشر:</p>
            <div style="overflow-x:auto;">
                <table class="calc-table">
                    <thead>
                        <tr><th>البعد</th><th>الحالة</th><th>عدد السجلات</th><th>الإجراء</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($availability as $key => $check): ?>
                        <tr>
                            <td><?= $dimIcons[$key] ?> <?= $dimNames[$key] ?></td>
                            <td>
                                <?php if (!empty($check['partial'])): ?>
                                    <span class="status-badge status-partial">🟡 متوفر جزئياً</span>
                                <?php elseif ($check['available']): ?>
                                    <span class="status-badge status-ok">✅ متوفر</span>
                                <?php else: ?>
                                    <span class="status-badge status-missing">❌ غير متوفر</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($check['count']) ?></td>
                            <td>
                                <?php if (!empty($check['partial'])): ?>
                                    <span style="color:#6c757d;font-size:0.8rem;">تقديري من أداء الطلاب</span>
                                <?php elseif ($check['available']): ?>
                                    <span style="color:#6c757d;">—</span>
                                <?php else: ?>
                                    <a href="<?= baseUrl('pages/' . $check['page']) ?>" target="_blank" style="color:#1a5632;font-size:0.85rem;">← <?= $check['page_label'] ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="tip-box"><strong>💡 تلميح:</strong> ابدأ بإدخال <strong>الدرجات الأكاديمية</strong> أولاً (وزنها 35% من المؤشر)، ثم <strong>تقييم التدريس</strong> (25%)، ثم بقية الأبعاد.</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
if ($result && $result['eqi'] !== null):
$dimKeys = array_keys($dimensions);
$dimNames = array_map(function($d) { return $d['name']; }, $dimensions);
$pageJs = "
new Chart(document.getElementById('result-radar'), {
    type: 'radar',
    data: {
        labels: " . json_encode($dimNames, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
            label: 'مؤشر الجودة',
            data: [" . implode(',', array_map(function($key) use ($result) { return $result['scores'][$key] ?? 0; }, $dimKeys)) . "],
            backgroundColor: 'rgba(26,86,50,0.15)',
            borderColor: '#1a5632',
            borderWidth: 2,
            pointBackgroundColor: '#1a5632',
            pointRadius: 4
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { r: { beginAtZero: true, max: 100, ticks: { font: { size: 10 } }, pointLabels: { font: { size: 11 } } } },
        plugins: { legend: { display: false } }
    }
});
";
endif;
require_once __DIR__ . '/../../includes/footer.php';
