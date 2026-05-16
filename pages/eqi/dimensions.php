<?php
/**
 * ============================================================
 * EQAS - طھظپط§طµظٹظ„ ط§ظ„ط£ط¨ط¹ط§ط¯
 * ظ†ط¸ط§ظ… ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط´ط§ظ…ظ„ - EQI Dimensions Detail
 * ============================================================
 */

$pageTitle = 'طھظپط§طµظٹظ„ ط£ط¨ط¹ط§ط¯ ط§ظ„ظ…ط¤ط´ط±';
$breadcrumb = ['ظ…ط¤ط´ط± ط¬ظˆط¯ط© ط§ظ„طھط¹ظ„ظٹظ…' => 'index.php', 'طھظپط§طµظٹظ„ ط§ظ„ط£ط¨ط¹ط§ط¯' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('group_admin') && !hasPermission('school_admin') && !hasPermission('teacher')) {
    setFlash('ظ„ظٹط³ ظ„ط¯ظٹظƒ طµظ„ط§ط­ظٹط©.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$dimensions = $eqiCalc->getDimensions();

$selectedDim = $_GET['dimension'] ?? 'academic';
if (!isset($dimensions[$selectedDim])) $selectedDim = 'academic';

$dimInfo = $dimensions[$selectedDim];

// طھظپط§طµظٹظ„ ظƒظ„ ط¨ط¹ط¯
$dimensionDetails = [
    'academic' => [
        'description' => 'ظٹظ‚ظٹط³ ظ…ط³طھظˆظ‰ ط§ظ„طھط­طµظٹظ„ ط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹ ظ„ط·ظ„ط§ط¨ ط§ظ„ظ…ط¯ط±ط³ط© ط¨ظ†ط§ط،ظ‹ ط¹ظ„ظ‰ ظ…طھظˆط³ط· ط§ظ„ط¯ط±ط¬ط§طھ ظپظٹ ط¬ظ…ظٹط¹ ط§ظ„ظ…ظˆط§ط¯ ط§ظ„ط¯ط±ط§ط³ظٹط©.',
        'factors' => ['ظ…طھظˆط³ط· ط¯ط±ط¬ط§طھ ط§ظ„ط·ظ„ط§ط¨ ظپظٹ ط§ظ„ظ…ظˆط§ط¯', 'ظ†ط³ط¨ط© ط§ظ„ط·ظ„ط§ط¨ ط§ظ„ظ†ط§ط¬ط­ظٹظ†', 'طھظˆط²ظٹط¹ ط§ظ„ط¯ط±ط¬ط§طھ', 'ظ…ظ‚ط§ط±ظ†ط© ط§ظ„ط£ط¯ط§ط، ط¨ظٹظ† ط§ظ„ظ…ظˆط§ط¯'],
        'data_source' => 'ط¬ط¯ظˆظ„ ط§ظ„ط¯ط±ط¬ط§طھ (grades)',
        'formula' => 'ظ…طھظˆط³ط· (ط¯ط±ط¬ط© ط§ظ„ط·ط§ظ„ط¨ / ط§ظ„ط¯ط±ط¬ط© ط§ظ„ظ‚طµظˆظ‰ أ— 100) ظ„ط¬ظ…ظٹط¹ ط§ظ„ط·ظ„ط§ط¨',
    ],
    'teaching' => [
        'description' => 'ظٹظ‚ظٹط³ ط¬ظˆط¯ط© ط§ظ„طھط¯ط±ظٹط³ ط¨ظ†ط§ط،ظ‹ ط¹ظ„ظ‰ ط«ظ„ط§ط«ط© ط£ظ†ظˆط§ط¹ ظ…ظ† ط§ظ„طھظ‚ظٹظٹظ…: ط°ط§طھظٹ (20%)طŒ ط£ظ‚ط±ط§ظ† (30%)طŒ ط¥ط´ط±ط§ظپظٹ (50%).',
        'factors' => ['ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط°ط§طھظٹ ظ„ظ„ظ…ط¹ظ„ظ…', 'طھظ‚ظٹظٹظ… ط§ظ„ط£ظ‚ط±ط§ظ†', 'ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط¥ط´ط±ط§ظپظٹ', 'ط§ظ„طھط·ظˆظٹط± ط§ظ„ظ…ظ‡ظ†ظٹ'],
        'data_source' => 'ط¬ط¯ط§ظˆظ„ طھظ‚ظٹظٹظ… ط§ظ„طھط¯ط±ظٹط³ (teaching_*_evaluations)',
        'formula' => '(ط°ط§طھظٹأ—20% + ط£ظ‚ط±ط§ظ†أ—30% + ط¥ط´ط±ط§ظپظٹأ—50%) / ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط£ظˆط²ط§ظ† ط§ظ„ظ…طھط§ط­ط©',
    ],
    'climate' => [
        'description' => 'ظٹظ‚ظٹط³ ط§ظ„ظ…ظ†ط§ط® ط§ظ„ظ…ط¯ط±ط³ظٹ ط§ظ„ط¹ط§ظ… ط¨ظ†ط§ط،ظ‹ ط¹ظ„ظ‰ ظ†طھط§ط¦ط¬ ط§ط³طھط¨ظٹط§ظ† ط§ظ„ظ…ظ†ط§ط® ط§ظ„ظ…ط¯ط±ط³ظٹ.',
        'factors' => ['ط§ظ„ط¨ظٹط¦ط© ط§ظ„ظ…ط§ط¯ظٹط©', 'ط§ظ„ط£ظ…ظ† ظˆط§ظ„ط³ظ„ط§ظ…ط©', 'ط§ظ„ط¹ظ„ط§ظ‚ط§طھ ط§ظ„ظ…ط¯ط±ط³ظٹط©', 'ط«ظ‚ط§ظپط© ط§ظ„ظ…ط´ط§ط±ظƒط©'],
        'data_source' => 'ط¬ط¯ظˆظ„ ط§ط³طھط¨ظٹط§ظ† ط§ظ„ظ…ظ†ط§ط® (surveys)',
        'formula' => 'ظ…طھظˆط³ط· ط¯ط±ط¬ط§طھ ط§ط³طھط¨ظٹط§ظ† ط§ظ„ظ…ظ†ط§ط®',
    ],
    'behavior' => [
        'description' => 'ظٹظ‚ظٹط³ ظ…ط³طھظˆظ‰ ط§ظ„ط³ظ„ظˆظƒ ظ„ط¯ظ‰ ط§ظ„ط·ظ„ط§ط¨ ط¨ظ†ط§ط،ظ‹ ط¹ظ„ظ‰ ط§ظ„طھظ‚ظٹظٹظ…ط§طھ ط§ظ„ط³ظ„ظˆظƒظٹط©.',
        'factors' => ['ط§ظ„ط§ظ„طھط²ط§ظ… ط¨ط§ظ„ظ‚ظˆط§ط¹ط¯', 'ط§ظ„طھط¹ط§ظˆظ†', 'ط§ظ„ظ…ط³ط¤ظˆظ„ظٹط©', 'ط§ظ„ط§ط­طھط±ط§ظ…'],
        'data_source' => 'ط¬ط¯ظˆظ„ ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒظٹ (evaluations)',
        'formula' => 'ظ…طھظˆط³ط· ط¯ط±ط¬ط§طھ ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒظٹ ظ„ط¬ظ…ظٹط¹ ط§ظ„ط·ظ„ط§ط¨',
    ],
    'skills' => [
        'description' => 'ظٹظ‚ظٹط³ ظ…ط³طھظˆظ‰ ط§ظ„ظ…ظ‡ط§ط±ط§طھ ط§ظ„ط­ظٹط§طھظٹط© ظˆط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹط© ظ„ط¯ظ‰ ط§ظ„ط·ظ„ط§ط¨.',
        'factors' => ['ظ…ظ‡ط§ط±ط§طھ ط§ظ„طھظپظƒظٹط±', 'ظ…ظ‡ط§ط±ط§طھ ط§ظ„طھظˆط§طµظ„', 'ظ…ظ‡ط§ط±ط§طھ ط­ظ„ ط§ظ„ظ…ط´ظƒظ„ط§طھ', 'ط§ظ„ظ…ظ‡ط§ط±ط§طھ ط§ظ„ط±ظ‚ظ…ظٹط©'],
        'data_source' => 'ط¬ط¯ظˆظ„ طھظ‚ظٹظٹظ… ط§ظ„ظ…ظ‡ط§ط±ط§طھ (skills_evaluations)',
        'formula' => 'ظ…طھظˆط³ط· ط¯ط±ط¬ط§طھ ظ‚ط§ط¦ظ…ط© ط§ظ„ظ…ظ‡ط§ط±ط§طھ ظ„ط¬ظ…ظٹط¹ ط§ظ„ط·ظ„ط§ط¨',
    ],
    'attendance' => [
        'description' => 'ظٹظ‚ظٹط³ ظ…ط¹ط¯ظ„ ط­ط¶ظˆط± ط§ظ„ط·ظ„ط§ط¨ ظƒظ…ط¤ط´ط± ط¹ظ„ظ‰ ط§ظ„ط§ظ„طھط²ط§ظ… ظˆط§ظ„ظ…ط´ط§ط±ظƒط©.',
        'factors' => ['ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط± ط§ظ„ط¥ط¬ظ…ط§ظ„ظٹط©', 'ظ†ط³ط¨ط© ط§ظ„طھط£ط®ظٹط±', 'ط£ظ†ظ…ط§ط· ط§ظ„ط؛ظٹط§ط¨', 'ط§ظ„ط؛ظٹط§ط¨ ط¨ط¹ط°ط± ظˆط¨ط¯ظˆظ† ط¹ط°ط±'],
        'data_source' => 'ط¬ط¯ظˆظ„ ظ…ظ„ط®طµ ط§ظ„ط­ط¶ظˆط± (v_attendance_summary)',
        'formula' => 'ظ…طھظˆط³ط· ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط± ظ„ط¬ظ…ظٹط¹ ط§ظ„ط·ظ„ط§ط¨',
    ],
];
?>

<div class="section">
    <!-- ط§ط®طھظٹط§ط± ط§ظ„ط¨ط¹ط¯ -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">ًں”چ طھظپط§طµظٹظ„ ط£ط¨ط¹ط§ط¯ ط§ظ„ظ…ط¤ط´ط±</h3>
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

    <!-- طھظپط§طµظٹظ„ ط§ظ„ط¨ط¹ط¯ ط§ظ„ظ…ط®طھط§ط± -->
    <div class="grid grid-2 gap-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $dimInfo['icon'] ?> <?= $dimInfo['name'] ?></h3>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <h5 style="color:var(--primary);">ًں“‌ ط§ظ„ظˆطµظپ</h5>
                    <p><?= e($dimensionDetails[$selectedDim]['description']) ?></p>
                </div>
                
                <div class="mb-4">
                    <h5 style="color:var(--primary);">âڑ–ï¸ڈ ط§ظ„ظˆط²ظ† ط§ظ„ط§ظپطھط±ط§ط¶ظٹ</h5>
                    <div class="flex items-center gap-3">
                        <div style="flex:1; background:var(--border-light); border-radius:var(--radius-full); height:20px; overflow:hidden;">
                            <div style="width:<?= $dimInfo['weight'] * 100 ?>%; background:var(--primary); height:100%; border-radius:var(--radius-full);"></div>
                        </div>
                        <strong style="font-size:18px;"><?= ($dimInfo['weight'] * 100) ?>%</strong>
                    </div>
                </div>
                
                <div class="mb-4">
                    <h5 style="color:var(--primary);">ًں“گ ظ…ط¹ط§ط¯ظ„ط© ط§ظ„ط­ط³ط§ط¨</h5>
                    <div class="p-3" style="background:var(--background); border-radius:var(--radius-md); font-family:monospace; direction:ltr; text-align:left;">
                        <?= e($dimensionDetails[$selectedDim]['formula']) ?>
                    </div>
                </div>
                
                <div>
                    <h5 style="color:var(--primary);">ًں’¾ ظ…طµط¯ط± ط§ظ„ط¨ظٹط§ظ†ط§طھ</h5>
                    <p class="text-secondary"><?= e($dimensionDetails[$selectedDim]['data_source']) ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ًں“ٹ ط§ظ„ط¹ظˆط§ظ…ظ„ ط§ظ„ظ…ط¤ط«ط±ط©</h3>
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
                
                <h5 style="color:var(--primary);">ًں’، طھظˆطµظٹط§طھ ط§ظ„طھط­ط³ظٹظ†</h5>
                <div class="flex flex-col gap-2">
                    <?php 
                    $recs = $eqiCalc->getRecommendations([$selectedDim => 40]);
                    if (isset($recs[$selectedDim])):
                    ?>
                    <div class="p-3" style="background:rgba(255,193,7,0.1); border-radius:var(--radius-md); border-right:3px solid var(--warning);">
                        <strong>ط¥ط°ط§ ظƒط§ظ† ط§ظ„ط¨ط¹ط¯ ظ…ظ‚ط¨ظˆظ„ط§ظ‹:</strong><br>
                        <?= e($recs[$selectedDim]['recommendation']) ?>
                    </div>
                    <?php endif; ?>
                    <?php 
                    $recs = $eqiCalc->getRecommendations([$selectedDim => 30]);
                    if (isset($recs[$selectedDim])):
                    ?>
                    <div class="p-3" style="background:rgba(220,53,69,0.1); border-radius:var(--radius-md); border-right:3px solid var(--danger);">
                        <strong>ط¥ط°ط§ ظƒط§ظ† ط§ظ„ط¨ط¹ط¯ ط¶ط¹ظٹظپط§ظ‹:</strong><br>
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
