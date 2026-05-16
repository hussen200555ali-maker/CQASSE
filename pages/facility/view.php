<?php
/**
 * ============================================================
 * عرض فحص المرافق المدرسية - مع مؤشرات بصرية وتوصيات
 * Facility Checklist View with Visual Indicators & Recommendations
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'عرض فحص المرافق';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'فحص المرافق' => 'index.php', 'عرض الفحص' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$checklistId = (int)($_GET['id'] ?? 0);
if (!$checklistId) { setFlash('معرف الفحص غير صالح', 'danger'); redirect('index.php'); }

$checklist = null;
try {
    $stmt = $db->prepare("
        SELECT fc.*, s.name_ar as school_name, u.full_name_ar as evaluator_name,
               sem.name_ar as semester_name, ay.name as year_name
        FROM facility_checklists fc
        JOIN schools s ON fc.school_id = s.id
        JOIN users u ON fc.evaluator_id = u.id
        JOIN semesters sem ON fc.semester_id = sem.id
        JOIN academic_years ay ON fc.academic_year_id = ay.id
        WHERE fc.id = ? AND fc.is_active = 1
    ");
    $stmt->execute([$checklistId]);
    $checklist = $stmt->fetch();
} catch (Exception $e) {}

if (!$checklist) { setFlash('الفحص غير موجود', 'danger'); redirect('index.php'); }

$items = [];
$categoryScores = [];
$repairRecommendations = []; // توصيات الإصلاح
$complianceDetails = ['compliant' => 0, 'non_compliant' => 0, 'partial' => 0, 'total_yes_no' => 0];

$repairPriorities = [
    'critical' => ['label' => 'عاجل', 'color' => '#dc3545', 'icon' => '🔴'],
    'high'     => ['label' => 'مهم',  'color' => '#fd7e14', 'icon' => '🟠'],
    'medium'   => ['label' => 'متوسط', 'color' => '#ffc107', 'icon' => '🟡'],
    'low'      => ['label' => 'منخفض', 'color' => '#28a745', 'icon' => '🟢'],
];

try {
    $stmt = $db->prepare("
        SELECT fi.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value, ac.name_ar as category_name
        FROM facility_items fi JOIN assessment_structure ai ON fi.item_id = ai.id
        JOIN assessment_structure ac ON ai.parent_id = ac.id  // [إصلاح 2026-05-11] assessment_categories→assessment_criteria, category_id→criteria_id
        WHERE fi.checklist_id = ? AND fi.is_active = 1 ORDER BY ac.sort_order, ai.sort_order
    ");
    $stmt->execute([$checklistId]);
    $items = $stmt->fetchAll();
    
    foreach ($items as $item) {
        $catName = $item['category_name'];
        if (!isset($categoryScores[$catName])) {
            $categoryScores[$catName] = ['weighted_sum' => 0, 'total_weight' => 0, 'count' => 0, 'yes_count' => 0, 'total_yes_no' => 0, 'items' => []];
        }
        $categoryScores[$catName]['weighted_sum'] += $item['weight'] * ($item['score'] ?? 0);
        $categoryScores[$catName]['total_weight'] += $item['weight'];
        $categoryScores[$catName]['count']++;
        $categoryScores[$catName]['items'][] = $item;
        
        if ($item['tool_type'] === 'yes_no') {
            $categoryScores[$catName]['total_yes_no']++;
            $complianceDetails['total_yes_no']++;
            if ($item['score'] >= 100) {
                $categoryScores[$catName]['yes_count']++;
                $complianceDetails['compliant']++;
            } else {
                $complianceDetails['non_compliant']++;
                // إضافة توصية إصلاح عاجلة
                $repairRecommendations[] = [
                    'text' => $item['text_ar'],
                    'category' => $catName,
                    'priority' => 'critical',
                    'type' => 'missing'
                ];
            }
        } elseif ($item['tool_type'] === 'severity_4') {
            $complianceDetails['total_yes_no']++;
            // حساب الدرجة الخام من العنصر
            $rawScore = $item['score'] ?? 0;
            if ($rawScore >= 75) {
                $complianceDetails['compliant']++;
            } elseif ($rawScore >= 50) {
                $complianceDetails['partial']++;
                $repairRecommendations[] = [
                    'text' => $item['text_ar'],
                    'category' => $catName,
                    'priority' => 'medium',
                    'type' => 'severity_moderate'
                ];
            } else {
                $complianceDetails['non_compliant']++;
                $priority = $rawScore < 25 ? 'critical' : 'high';
                $repairRecommendations[] = [
                    'text' => $item['text_ar'],
                    'category' => $catName,
                    'priority' => $priority,
                    'type' => 'severity_high'
                ];
            }
        } elseif (($item['score'] ?? 0) < 50) {
            $priority = ($item['score'] ?? 0) < 25 ? 'high' : 'medium';
            $repairRecommendations[] = [
                'text' => $item['text_ar'],
                'category' => $catName,
                'priority' => $priority,
                'type' => 'low_score'
            ];
        }
    }
} catch (Exception $e) {}

// ترتيب التوصيات حسب الأولوية
usort($repairRecommendations, function($a, $b) {
    $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return ($order[$a['priority']] ?? 3) - ($order[$b['priority']] ?? 3);
});

$overallScore = $checklist['overall_score'];
$rating = $overallScore !== null ? $engine->getScoreRating($overallScore) : null;

// حساب درجة الامتثال
$complianceScore = $complianceDetails['total_yes_no'] > 0 
    ? round(($complianceDetails['compliant'] / $complianceDetails['total_yes_no']) * 100, 1) 
    : 0;

$categoryIcons = [
    'الفصول' => '🏫', 'المعامل' => '🔬', 'المكتبة' => '📚',
    'الملعب' => '⚽', 'دورات المياه' => '🚻', 'السلامة' => '🛡️',
    'السور' => '🧱', 'البوابة' => '🚪', 'الممرات' => '🚶'
];

// جلب فحوصات سابقة للمقارنة التاريخية
$historicalChecklists = [];
try {
    $stmt = $db->prepare("
        SELECT fc.id, fc.overall_score, fc.evaluation_date, sem.name_ar as semester_name
        FROM facility_checklists fc
        JOIN semesters sem ON fc.semester_id = sem.id
        WHERE fc.school_id = ? AND fc.is_active = 1
        ORDER BY fc.evaluation_date ASC
    ");
    $stmt->execute([$checklist['school_id']]);
    $historicalChecklists = $stmt->fetchAll();
} catch (Exception $e) {}

// حساب الفرق عن الفحص السابق
$scoreDiff = null;
$previousScore = null;
if (count($historicalChecklists) > 1) {
    // البحث عن الفحص السابق
    for ($i = 0; $i < count($historicalChecklists); $i++) {
        if ($historicalChecklists[$i]['id'] == $checklistId && $i > 0) {
            $previousScore = $historicalChecklists[$i - 1]['overall_score'];
            if ($previousScore !== null && $overallScore !== null) {
                $scoreDiff = round($overallScore - $previousScore, 1);
            }
            break;
        }
    }
}
?>

<!-- معلومات الفحص -->
<div class="section">
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span style="font-size:32px;">🏫</span>
                <div>
                    <h3 class="card-title">فحص المرافق - <?php echo e($checklist['school_name']); ?></h3>
                    <div style="font-size:13px; color:var(--text-secondary);">المقيّم: <?php echo e($checklist['evaluator_name']); ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="window.print()" class="btn btn-light btn-sm" title="تصدير التقرير">🖨️ تصدير</button>
                <a href="index.php" class="btn btn-light btn-sm">↩️ رجوع</a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-4">
                <div><span style="font-size:12px; color:var(--text-muted);">المدرسة</span><div style="font-weight:600;"><?php echo e($checklist['school_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">المقيّم</span><div style="font-weight:600;"><?php echo e($checklist['evaluator_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">الفصل الدراسي</span><div style="font-weight:600;"><?php echo e($checklist['semester_name']); ?></div></div>
                <div><span style="font-size:12px; color:var(--text-muted);">التاريخ</span><div style="font-weight:600;"><?php echo formatDateAr($checklist['evaluation_date'], 'medium'); ?></div></div>
            </div>
        </div>
    </div>
</div>

<!-- الدرجة الكلية ودرجة الامتثال -->
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-body" style="text-align:center; padding:24px;">
                <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">الدرجة الكلية لجودة المرافق</div>
                <div style="font-size:48px; font-weight:700; color:<?php echo $rating['color'] ?? 'var(--text-primary)'; ?>;">
                    <?php echo $overallScore !== null ? number_format($overallScore, 1) : '—'; ?>%
                </div>
                <?php if ($rating): ?>
                    <div style="font-size:20px; color:<?php echo $rating['color']; ?>; font-weight:600;"><?php echo $rating['label']; ?></div>
                <?php endif; ?>
                <div style="margin-top:16px; max-width:400px; margin-left:auto; margin-right:auto;">
                    <?php echo $engine->renderScoreBar($overallScore ?? 0); ?>
                </div>
                <?php if ($scoreDiff !== null): ?>
                <div style="margin-top:12px; font-size:14px; font-weight:600; color:<?php echo $scoreDiff >= 0 ? '#28a745' : '#dc3545'; ?>;">
                    <?php echo $scoreDiff >= 0 ? '↑' : '↓'; ?> 
                    <?php echo abs($scoreDiff); ?>% مقارنة بالفحص السابق
                    (<?php echo number_format($previousScore, 1); ?>%)
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="text-align:center; padding:24px;">
                <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">درجة الامتثال</div>
                <div style="font-size:48px; font-weight:700; color:<?php echo $complianceScore >= 75 ? '#28a745' : ($complianceScore >= 50 ? '#ffc107' : '#dc3545'); ?>;">
                    <?php echo number_format($complianceScore, 1); ?>%
                </div>
                <div style="font-size:14px; color:var(--text-secondary); margin-top:8px;">
                    <?php echo $complianceDetails['compliant']; ?> متوافق من أصل <?php echo $complianceDetails['total_yes_no']; ?>
                </div>
                <div style="margin-top:16px; max-width:400px; margin-left:auto; margin-right:auto;">
                    <div style="background:var(--border-light); border-radius:var(--radius-md); height:16px; overflow:hidden;">
                        <div style="display:flex; height:100%; border-radius:var(--radius-md); overflow:hidden;">
                            <div style="width:<?php echo $complianceDetails['total_yes_no'] > 0 ? ($complianceDetails['compliant']/$complianceDetails['total_yes_no'])*100 : 0; ?>%; background:#28a745;"></div>
                            <div style="width:<?php echo $complianceDetails['total_yes_no'] > 0 ? ($complianceDetails['partial']/$complianceDetails['total_yes_no'])*100 : 0; ?>%; background:#ffc107;"></div>
                            <div style="width:<?php echo $complianceDetails['total_yes_no'] > 0 ? ($complianceDetails['non_compliant']/$complianceDetails['total_yes_no'])*100 : 0; ?>%; background:#dc3545;"></div>
                        </div>
                    </div>
                </div>
                <div style="margin-top:8px; display:flex; justify-content:center; gap:12px; font-size:12px;">
                    <span style="color:#28a745;">● متوافق: <?php echo $complianceDetails['compliant']; ?></span>
                    <span style="color:#ffc107;">● جزئي: <?php echo $complianceDetails['partial']; ?></span>
                    <span style="color:#dc3545;">● غير متوافق: <?php echo $complianceDetails['non_compliant']; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- رسم بياني لحالة الفئات -->
<?php if (!empty($categoryScores)): ?>
<div class="section">
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 حالة المرافق حسب الفئة</h3></div>
            <div class="card-body">
                <div style="height:300px;">
                    <canvas id="categoryPieChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">📊 مقارنة الفئات</h3></div>
            <div class="card-body">
                <div style="height:300px;">
                    <canvas id="categoryBarChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- المقارنة التاريخية -->
<?php if (count($historicalChecklists) > 1): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📈 المقارنة التاريخية</h3></div>
        <div class="card-body">
            <div style="height:250px; margin-bottom:16px;">
                <canvas id="historicalChart"></canvas>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center;">
                <?php foreach ($historicalChecklists as $hc):
                    $isCurrent = $hc['id'] == $checklistId;
                    $hcRating = $hc['overall_score'] !== null ? $engine->getScoreRating($hc['overall_score']) : null;
                ?>
                <div style="padding:6px 12px; border-radius:var(--radius-md); font-size:12px;
                    <?php echo $isCurrent ? 'background:var(--primary); color:white; font-weight:700;' : 'background:var(--background); border:1px solid var(--border-light);'; ?>">
                    <?php echo e($hc['semester_name']); ?>: 
                    <?php echo $hc['overall_score'] !== null ? number_format($hc['overall_score'], 1) . '%' : '—'; ?>
                    <?php if ($isCurrent): ?> ◀<?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ملخص الفئات بمؤشرات بصرية -->
<div class="section">
    <h3 class="section-title">ملخص الفئات</h3>
    <div class="grid grid-auto-lg">
        <?php foreach ($categoryScores as $catName => $catData): 
            $catScore = $catData['total_weight'] > 0 ? round($catData['weighted_sum'] / $catData['total_weight'], 1) : 0;
            $catRating = $engine->getScoreRating($catScore);
            $catIcon = '🏫';
            foreach ($categoryIcons as $key => $icon) {
                if (mb_strpos($catName, $key) !== false) { $catIcon = $icon; break; }
            }
        ?>
        <div class="card" style="border-top:4px solid <?php echo $catRating['color']; ?>;">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:32px; margin-bottom:8px;"><?php echo $catIcon; ?></div>
                <div style="font-size:14px; font-weight:600; margin-bottom:8px;"><?php echo e($catName); ?></div>
                <div style="font-size:28px; font-weight:700; color:<?php echo $catRating['color']; ?>;"><?php echo $catScore; ?>%</div>
                <div style="font-size:13px; color:<?php echo $catRating['color']; ?>; margin-bottom:8px;"><?php echo $catRating['label']; ?></div>
                <?php echo $engine->renderScoreBar($catScore); ?>
                <?php if ($catData['total_yes_no'] > 0): ?>
                <div style="margin-top:8px; font-size:12px; color:var(--text-secondary);">
                    ✅ <?php echo $catData['yes_count']; ?> / <?php echo $catData['total_yes_no']; ?> متوفر
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- توصيات الإصلاح حسب الأولوية -->
<?php if (!empty($repairRecommendations)): ?>
<div class="section">
    <div class="card">
        <div class="card-header" style="background:#dc3545; color:white;">
            <div class="flex items-center justify-between" style="width:100%;">
                <h3 class="card-title" style="color:white;">🔧 توصيات الإصلاح</h3>
                <span style="font-size:14px; color:white; font-weight:600;"><?php echo count($repairRecommendations); ?> عنصر يحتاج إصلاح</span>
            </div>
        </div>
        <div class="card-body">
            <!-- ملخص الأولويات -->
            <div class="grid grid-4 mb-4">
                <?php 
                $priorityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
                foreach ($repairRecommendations as $rec) {
                    if (isset($priorityCounts[$rec['priority']])) $priorityCounts[$rec['priority']]++;
                }
                foreach ($repairPriorities as $key => $pri): 
                ?>
                <div style="text-align:center; padding:12px; border-radius:var(--radius-md); background:<?php echo $pri['color']; ?>10; border:2px solid <?php echo $pri['color']; ?>30;">
                    <div style="font-size:24px;"><?php echo $pri['icon']; ?></div>
                    <div style="font-size:24px; font-weight:700; color:<?php echo $pri['color']; ?>;"><?php echo $priorityCounts[$key]; ?></div>
                    <div style="font-size:13px; font-weight:600; color:<?php echo $pri['color']; ?>;"><?php echo $pri['label']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- قائمة التوصيات -->
            <div style="max-height:300px; overflow-y:auto;">
                <?php foreach ($repairRecommendations as $rec): 
                    $pri = $repairPriorities[$rec['priority']] ?? $repairPriorities['low'];
                    $catIcon = '🏫';
                    foreach ($categoryIcons as $key => $icon) {
                        if (mb_strpos($rec['category'], $key) !== false) { $catIcon = $icon; break; }
                    }
                ?>
                <div style="padding:10px 12px; margin-bottom:6px; background:<?php echo $pri['color']; ?>08; border-right:4px solid <?php echo $pri['color']; ?>; border-radius:var(--radius-md); display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;"><?php echo $pri['icon']; ?></span>
                    <span style="font-size:13px; flex:1;">
                        <span style="font-weight:600;"><?php echo e($rec['text']); ?></span>
                        <span style="color:var(--text-muted); font-size:11px;"> — <?php echo $catIcon . ' ' . e($rec['category']); ?></span>
                    </span>
                    <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:var(--radius-md); background:<?php echo $pri['color']; ?>20; color:<?php echo $pri['color']; ?>;">
                        <?php echo $pri['label']; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center; padding:24px;">
            <div style="font-size:40px; margin-bottom:8px;">✅</div>
            <div style="font-size:16px; font-weight:600; color:#28a745;">جميع المرافق في حالة جيدة</div>
            <div style="font-size:13px; color:var(--text-secondary);">لا توجد توصيات إصلاح في هذا الفحص</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- تفاصيل العناصر -->
<div class="section">
    <h3 class="section-title">تفاصيل الفحص</h3>
    <?php
    $currentCategory = '';
    foreach ($items as $item):
        if ($item['category_name'] !== $currentCategory):
            if ($currentCategory !== '') echo '</div></div>';
            $currentCategory = $item['category_name'];
            $catIcon = '🏫';
            foreach ($categoryIcons as $key => $icon) {
                if (mb_strpos($currentCategory, $key) !== false) { $catIcon = $icon; break; }
            }
    ?>
    <div class="card mb-4">
        <div class="card-header" style="background:#20c997; color:white;">
            <h4 style="margin:0; color:white; font-size:15px;"><?php echo $catIcon . ' ' . e($currentCategory); ?></h4>
        </div>
        <div class="card-body">
    <?php endif; ?>
            <?php
            // عرض بصري حسب نوع الأداة
            $score = $item['score'] ?? 0;
            $itemRating = $engine->getScoreRating($score);
            
            if ($item['tool_type'] === 'yes_no'):
                // عرض بصري لنعم/لا
                $isYes = $score >= 100;
            ?>
            <div style="padding:8px 12px; background:<?php echo $isYes ? 'rgba(40,167,69,0.1)' : 'rgba(220,53,69,0.1)'; ?>; border-radius:var(--radius-md); margin-bottom:8px; display:flex; justify-content:between; align-items:center;">
                <span style="font-size:20px; margin-left:8px;"><?php echo $isYes ? '✅' : '❌'; ?></span>
                <span style="flex:1; font-size:14px;"><?php echo e($item['text_ar']); ?></span>
                <span style="font-weight:600; color:<?php echo $isYes ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo $isYes ? 'متوفر' : 'غير متوفر'; ?>
                </span>
            </div>
            <?php elseif ($item['tool_type'] === 'severity_4'): 
                // عرض بصري لمقياس الشدة
                $severityLabels = [1 => 'شديد 🔴', 2 => 'متوسط 🟠', 3 => 'خفيف 🟡', 4 => 'لا يوجد 🟢'];
            ?>
            <div style="padding:8px 12px; background:var(--background); border-radius:var(--radius-md); margin-bottom:8px;">
                <div class="flex justify-between items-center">
                    <span style="font-size:14px;"><?php echo e($item['text_ar']); ?></span>
                    <span style="font-weight:600;"><?php echo e($severityLabels[(int)($item['raw_score'] ?? 4)] ?? '—'); ?></span>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-bottom:16px;">
                <div class="flex justify-between items-center mb-1">
                    <span style="font-size:14px; font-weight:500;"><?php echo e($item['text_ar']); ?></span>
                    <span style="font-size:14px; font-weight:600; color:<?php echo $itemRating['color']; ?>;"><?php echo number_format($score, 1); ?>%</span>
                </div>
                <?php echo $engine->renderScoreBar($score); ?>
                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                    الأداة: <?php echo e($engine->getToolLabel($item['tool_type'])); ?>
                </div>
            </div>
            <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($currentCategory !== ''): ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- مكان الصور -->
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📷 صور المرافق</h3></div>
        <div class="card-body">
            <div style="display:flex; gap:12px; flex-wrap:wrap; justify-content:center;">
                <?php foreach ($categoryScores as $catName => $catData):
                    $catIcon = '🏫';
                    foreach ($categoryIcons as $key => $icon) {
                        if (mb_strpos($catName, $key) !== false) { $catIcon = $icon; break; }
                    }
                ?>
                <div style="width:150px; text-align:center;">
                    <div style="width:150px; height:120px; border:2px dashed var(--border-light); border-radius:var(--radius-md); display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-muted); cursor:pointer; background:var(--background);" onclick="this.innerHTML='<div style=font-size:28px>📸</div><div style=font-size:10px>إضافة صورة</div>'">
                        <div style="font-size:28px;"><?php echo $catIcon; ?></div>
                        <div style="font-size:11px; margin-top:4px;">إضافة صورة</div>
                    </div>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?php echo e($catName); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($checklist['notes']): ?>
<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📝 ملاحظات</h3></div>
        <div class="card-body"><p><?php echo e($checklist['notes']); ?></p></div>
    </div>
</div>
<?php endif; ?>

<?php
// بيانات الرسوم البيانية
$catLabels = [];
$catData = [];
$catColors = [];
$colorPalette = ['#20c997', '#1a5632', '#17a2b8', '#fd7e14', '#6f42c1', '#dc3545', '#ffc107', '#6c757d'];
$ci = 0;
foreach ($categoryScores as $catName => $catDataItem) {
    $catLabels[] = $catName;
    $catScore = $catDataItem['total_weight'] > 0 ? round($catDataItem['weighted_sum'] / $catDataItem['total_weight'], 1) : 0;
    $catData[] = $catScore;
    $catColors[] = $colorPalette[$ci % count($colorPalette)];
    $ci++;
}

$histLabels = [];
$histData = [];
foreach ($historicalChecklists as $hc) {
    $histLabels[] = formatDateAr($hc['evaluation_date'], 'short');
    $histData[] = $hc['overall_score'] ?? 0;
}

$pageJs = "";

// رسم دائري لحالة الفئات
if (!empty($catLabels)) {
    $pageJs .= "
        eqasPieChart('categoryPieChart', 'حالة المرافق', " . json_encode($catLabels, JSON_UNESCAPED_UNICODE) . ", " . json_encode($catData) . ", " . json_encode($catColors) . ", true);
    ";
    
    // رسم أعمدة
    $pageJs .= "
        eqasBarChart('categoryBarChart', 'مقارنة الفئات', " . json_encode($catLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة',
            data: " . json_encode($catData) . ",
            backgroundColor: " . json_encode($catColors) . "
        }], {scales: {y: {beginAtZero: true, max: 100}}, plugins: {legend: {display: false}}});
    ";
}

// رسم المقارنة التاريخية
if (count($historicalChecklists) > 1) {
    $pageJs .= "
        eqasLineChart('historicalChart', 'التطور التاريخي', " . json_encode($histLabels, JSON_UNESCAPED_UNICODE) . ", [{
            label: 'الدرجة الكلية',
            data: " . json_encode($histData) . ",
            borderColor: '#20c997',
            fill: true
        }], {scales: {y: {beginAtZero: true, max: 100}}});
    ";
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
