<?php
/**
 * ============================================================
 * EQAS - تفاصيل الأبعاد
 * نظام التقييم الشامل - EQI Dimensions Detail
 * ============================================================
 */

$pageTitle = 'تفاصيل أبعاد المؤشر';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'تفاصيل الأبعاد' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$dimensions = $eqiCalc->getDimensions();

$selectedDim = $_GET['dimension'] ?? 'academic';
if (!isset($dimensions[$selectedDim])) $selectedDim = 'academic';

$dimInfo = $dimensions[$selectedDim];

// تفاصيل كل بعد
$dimensionDetails = [
    'academic' => [
        'description' => 'يقيس مستوى التحصيل الأكاديمي لطلاب المدرسة بناءً على متوسط الدرجات في جميع المواد الدراسية.',
        'factors' => ['متوسط درجات الطلاب في المواد', 'نسبة الطلاب الناجحين', 'توزيع الدرجات', 'مقارنة الأداء بين المواد'],
        'data_source' => 'جدول الدرجات (grades)',
        'formula' => 'متوسط (درجة الطالب / الدرجة القصوى × 100) لجميع الطلاب',
    ],
    'teaching' => [
        'description' => 'يقيس جودة التدريس بناءً على ثلاثة أنواع من التقييم: ذاتي (20%)، أقران (30%)، إشرافي (50%).',
        'factors' => ['التقييم الذاتي للمعلم', 'تقييم الأقران', 'التقييم الإشرافي', 'التطوير المهني'],
        'data_source' => 'جداول تقييم التدريس (teaching_*_evaluations)',
        'formula' => '(ذاتي×20% + أقران×30% + إشرافي×50%) / إجمالي الأوزان المتاحة',
    ],
    'climate' => [
        'description' => 'يقيس المناخ المدرسي العام بناءً على نتائج استبيان المناخ المدرسي.',
        'factors' => ['البيئة المادية', 'الأمن والسلامة', 'العلاقات المدرسية', 'ثقافة المشاركة'],
        'data_source' => 'جدول استبيان المناخ (climate_surveys)',
        'formula' => 'متوسط درجات استبيان المناخ',
    ],
    'behavior' => [
        'description' => 'يقيس مستوى السلوك لدى الطلاب بناءً على التقييمات السلوكية.',
        'factors' => ['الالتزام بالقواعد', 'التعاون', 'المسؤولية', 'الاحترام'],
        'data_source' => 'جدول التقييم السلوكي (behavior_scores)',
        'formula' => 'متوسط درجات التقييم السلوكي لجميع الطلاب',
    ],
    'skills' => [
        'description' => 'يقيس مستوى المهارات الحياتية والأكاديمية لدى الطلاب.',
        'factors' => ['مهارات التفكير', 'مهارات التواصل', 'مهارات حل المشكلات', 'المهارات الرقمية'],
        'data_source' => 'جدول تقييم المهارات (skills_evaluations)',
        'formula' => 'متوسط درجات قائمة المهارات لجميع الطلاب',
    ],
    'attendance' => [
        'description' => 'يقيس معدل حضور الطلاب كمؤشر على الالتزام والمشاركة.',
        'factors' => ['نسبة الحضور الإجمالية', 'نسبة التأخير', 'أنماط الغياب', 'الغياب بعذر وبدون عذر'],
        'data_source' => 'جدول ملخص الحضور (attendance_summaries)',
        'formula' => 'متوسط نسبة الحضور لجميع الطلاب',
    ],
];
?>

<div class="section">
    <!-- اختيار البعد -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🔍 تفاصيل أبعاد المؤشر</h3>
        </div>
        <div class="card-body">
            <div class="flex flex-wrap gap-2">
                <?php foreach ($dimensions as $key => $dim): ?>
                <a href="?dimension=<?= $key ?>" class="btn <?= $key === $selectedDim ? 'btn-primary' : 'btn-light' ?>">
                    <?= $dim['icon'] ?> <?= $dim['name'] ?> (<?= ($dim['weight'] * 100) ?>%)
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- تفاصيل البعد المختار -->
    <div class="grid grid-2 gap-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $dimInfo['icon'] ?> <?= $dimInfo['name'] ?></h3>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <h5 style="color:var(--primary);">📝 الوصف</h5>
                    <p><?= e($dimensionDetails[$selectedDim]['description']) ?></p>
                </div>
                
                <div class="mb-4">
                    <h5 style="color:var(--primary);">⚖️ الوزن الافتراضي</h5>
                    <div class="flex items-center gap-3">
                        <div style="flex:1; background:var(--border-light); border-radius:var(--radius-full); height:20px; overflow:hidden;">
                            <div style="width:<?= $dimInfo['weight'] * 100 ?>%; background:var(--primary); height:100%; border-radius:var(--radius-full);"></div>
                        </div>
                        <strong style="font-size:18px;"><?= ($dimInfo['weight'] * 100) ?>%</strong>
                    </div>
                </div>
                
                <div class="mb-4">
                    <h5 style="color:var(--primary);">📐 معادلة الحساب</h5>
                    <div class="p-3" style="background:var(--background); border-radius:var(--radius-md); font-family:monospace; direction:ltr; text-align:left;">
                        <?= e($dimensionDetails[$selectedDim]['formula']) ?>
                    </div>
                </div>
                
                <div>
                    <h5 style="color:var(--primary);">💾 مصدر البيانات</h5>
                    <p class="text-secondary"><?= e($dimensionDetails[$selectedDim]['data_source']) ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 العوامل المؤثرة</h3>
            </div>
            <div class="card-body">
                <div class="flex flex-col gap-3">
                    <?php foreach ($dimensionDetails[$selectedDim]['factors'] as $i => $factor): ?>
                    <div class="flex items-center gap-3 p-3" style="background:var(--background); border-radius:var(--radius-md);">
                        <span style="width:28px; height:28px; background:var(--primary); color:#fff; border-radius:var(--radius-full); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:bold; flex-shrink:0;"><?= $i + 1 ?></span>
                        <span><?= e($factor) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="divider"></div>
                
                <h5 style="color:var(--primary);">💡 توصيات التحسين</h5>
                <div class="flex flex-col gap-2">
                    <?php 
                    $recs = $eqiCalc->getRecommendations([$selectedDim => 40]);
                    if (isset($recs[$selectedDim])):
                    ?>
                    <div class="p-3" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-md); border-right:3px solid var(--warning);">
                        <strong>إذا كان البعد مقبولاً:</strong><br>
                        <?= e($recs[$selectedDim]['recommendation']) ?>
                    </div>
                    <?php endif; ?>
                    <?php 
                    $recs = $eqiCalc->getRecommendations([$selectedDim => 30]);
                    if (isset($recs[$selectedDim])):
                    ?>
                    <div class="p-3" style="background:rgba(220,53,69,0.1); border-radius:var(--radius-md); border-right:3px solid var(--danger);">
                        <strong>إذا كان البعد ضعيفاً:</strong><br>
                        <?= e($recs[$selectedDim]['recommendation']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
