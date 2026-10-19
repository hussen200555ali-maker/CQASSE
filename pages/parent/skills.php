<?php
/**
 * ============================================================
 * EQAS - مهارات أبناء ولي الأمر
 * نظام التقييم الشامل - عرض تقييم المهارات لجميع الأبناء
 * ============================================================
 * يعرض تقييم المهارات لجميع الأبناء:
 * - من assessment_responses (skills_checklist)
 * - مجمّع حسب معايير التقييم (assessment_criteria)
 * - شريط تقدم لكل مهارة
 * - مقارنة مع متوسط الفصل إن توفر
 * ============================================================
 */

require_once __DIR__ . '/../../includes/header.php';

$pageTitle = 'مهارات الأبناء';
$breadcrumb = ['لوحة ولي الأمر' => baseUrl('pages/dashboard/parent.php'), 'مهارات الأبناء' => null];

// التحقق من الصلاحية
if (!hasPermission('parent')) {
    setFlash('هذه الصفحة مخصصة لأولياء الأمور فقط.', 'danger');
    redirect(baseUrl('index.php'));
}

$parentId = $_SESSION['user']['id'];

// ═══════════════════════════════════════════
// جلب أبناء ولي الأمر
// ═══════════════════════════════════════════
$stmt = $db->prepare("
    SELECT s.id, s.full_name_ar, s.student_number, s.grade_level_id,
           gl.name_ar as grade_name, sc.name_ar as school_name,
           ps.relationship
    FROM parent_students ps
    JOIN students s ON ps.student_id = s.id
    JOIN grade_levels gl ON s.grade_level_id = gl.id
    JOIN schools sc ON s.school_id = sc.id
    WHERE ps.parent_id = ? AND ps.is_active = 1 AND s.is_active = 1
    ORDER BY gl.level_number, s.full_name_ar
");
$stmt->execute([$parentId]);
$children = $stmt->fetchAll();

// إذا لم يكن هناك أبناء
if (empty($children)):
?>
<div class="section">
    <div class="card">
        <div class="card-body" style="text-align:center;padding:60px 20px;">
            <div style="font-size:64px;margin-bottom:16px;">👨‍👩‍👧‍👦</div>
            <h3>لا يوجد طلاب مرتبطين بحسابك</h3>
            <p style="color:var(--text-secondary);margin-bottom:20px;">يرجى التواصل مع إدارة المدرسة.</p>
            <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-primary">
                <i class="fas fa-home"></i> العودة للرئيسية
            </a>
        </div>
    </div>
</div>
<?php
else:

$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// جلب البيانات لكل طفل
// ═══════════════════════════════════════════
$childrenSkills = [];
foreach ($children as $child) {
    $sid = $child['id'];
    $data = [
        'criteria' => [],
        'overall_avg' => null
    ];

    // جلب تقييمات المهارات من assessment_responses (skills_checklist)
    try {
        $stmt = $db->prepare("
            SELECT ar.id, ar.numeric_value, ar.text_value, ar.response_date,
                   ar.criteria_id, ar.class_id, ar.semester_id,
                   acr.name_ar as criteria_name, acr.weight as criteria_weight, acr.sort_order as criteria_sort,
                   ai.text_ar as item_text, ai.tool_type, ai.weight as item_weight,
                   ai.max_value as item_max_value,
                   sem.name_ar as semester_name
            FROM assessment_responses ar
            JOIN assessment_structure ai ON ar.item_id = ai.id
            JOIN assessment_forms af ON ar.form_id = af.id
            LEFT JOIN assessment_structure acr ON ar.criteria_id = acr.id
            LEFT JOIN semesters sem ON ar.semester_id = sem.id
            WHERE ar.student_id = ? AND ar.is_active = 1
            AND af.form_key = 'skills_checklist'
            AND ar.academic_year_id = ?
            ORDER BY acr.sort_order ASC, ai.sort_order ASC
        ");
        $stmt->execute([$sid, $academicYearId]);
        $responses = $stmt->fetchAll();
    } catch (Exception $e) {
        $responses = [];
    }

    // تنظيم حسب المعيار
    $criteriaData = [];
    $allPercentages = [];
    foreach ($responses as $resp) {
        $cId = $resp['criteria_id'] ?? 0;
        $cName = $resp['criteria_name'] ?? 'مهارات عامة';

        if (!isset($criteriaData[$cId])) {
            $criteriaData[$cId] = [
                'name' => $cName,
                'weight' => (float)($resp['criteria_weight'] ?? 1),
                'sort_order' => (int)($resp['criteria_sort'] ?? 0),
                'items' => [],
                'avg_pct' => 0,
                'total_pct' => 0,
                'item_count' => 0
            ];
        }

        // حساب النسبة للعنصر
        $itemPct = 0;
        $itemVal = $resp['numeric_value'];
        if ($itemVal !== null) {
            if ($resp['tool_type'] === 'likert_5') {
                $itemPct = round(($itemVal / 5) * 100, 1);
            } elseif ($resp['tool_type'] === 'numeric_100') {
                $itemPct = round($itemVal, 1);
            } elseif ($resp['tool_type'] === 'yes_no') {
                $itemPct = $itemVal ? 100 : 0;
            } elseif ($resp['item_max_value'] && $resp['item_max_value'] > 0) {
                $itemPct = round(($itemVal / $resp['item_max_value']) * 100, 1);
            } elseif ($itemVal > 0) {
                $itemPct = min(100, round($itemVal * 20, 1));
            }
        }

        $criteriaData[$cId]['items'][] = [
            'text' => $resp['item_text'],
            'value' => $itemVal,
            'tool_type' => $resp['tool_type'],
            'percentage' => $itemPct,
            'text_value' => $resp['text_value'],
            'max_value' => $resp['item_max_value']
        ];

        if ($itemPct > 0) {
            $criteriaData[$cId]['total_pct'] += $itemPct;
            $criteriaData[$cId]['item_count']++;
            $allPercentages[] = $itemPct;
        }
    }

    // حساب متوسط كل معيار
    foreach ($criteriaData as $cId => &$cData) {
        $cData['avg_pct'] = $cData['item_count'] > 0
            ? round($cData['total_pct'] / $cData['item_count'], 1)
            : 0;
    }
    unset($cData);

    // ترتيب حسب sort_order
    uasort($criteriaData, function($a, $b) {
        return $a['sort_order'] - $b['sort_order'];
    });

    // المتوسط العام
    $data['overall_avg'] = count($allPercentages) > 0
        ? round(array_sum($allPercentages) / count($allPercentages), 1)
        : null;
    $data['criteria'] = $criteriaData;

    // ═══════════════════════════════════════════
    // جلب متوسط الفصل للمقارنة (إن وجد)
    // ═══════════════════════════════════════════
    if (!empty($responses)) {
        try {
            $classId = $responses[0]['class_id'] ?? null;
            if ($classId) {
                $stmt = $db->prepare("
                    SELECT
                        ar.criteria_id,
                        AVG(ar.numeric_value) as avg_value,
                        ai.tool_type, ai.max_value as item_max_value
                    FROM assessment_responses ar
                    JOIN assessment_structure ai ON ar.item_id = ai.id
                    JOIN assessment_forms af ON ar.form_id = af.id
                    JOIN student_enrollments se ON ar.student_id = se.student_id AND se.class_id = ? AND se.is_active = 1
                    WHERE ar.class_id = ? AND ar.is_active = 1
                    AND af.form_key = 'skills_checklist'
                    AND ar.academic_year_id = ?
                    GROUP BY ar.criteria_id
                ");
                $stmt->execute([$classId, $classId, $academicYearId]);
                $classAvgs = $stmt->fetchAll();

                $classAvgMap = [];
                foreach ($classAvgs as $ca) {
                    $caVal = (float)$ca['avg_value'];
                    $caPct = 0;
                    if ($ca['tool_type'] === 'likert_5') {
                        $caPct = round(($caVal / 5) * 100, 1);
                    } elseif ($ca['tool_type'] === 'numeric_100') {
                        $caPct = round($caVal, 1);
                    } elseif ($ca['item_max_value'] && $ca['item_max_value'] > 0) {
                        $caPct = round(($caVal / $ca['item_max_value']) * 100, 1);
                    } elseif ($caVal > 0) {
                        $caPct = min(100, round($caVal * 20, 1));
                    }
                    $classAvgMap[$ca['criteria_id']] = $caPct;
                }
                $data['class_avg'] = $classAvgMap;
            }
        } catch (Exception $e) {}
    }

    $childrenSkills[$sid] = $data;
}

/**
 * لون مستوى المهارة
 */
function getSkillColor($pct) {
    if ($pct >= 80) return '#28a745';
    if ($pct >= 60) return '#17a2b8';
    if ($pct >= 40) return '#ffc107';
    return '#dc3545';
}
function getSkillLabel($pct) {
    if ($pct >= 80) return 'متقن';
    if ($pct >= 60) return 'جيد';
    if ($pct >= 40) return 'مبتدئ';
    return 'يحتاج تطوير';
}
function getSkillIcon($pct) {
    if ($pct >= 80) return '🌟';
    if ($pct >= 60) return '✅';
    if ($pct >= 40) return '🔸';
    return '⚠️';
}
?>

<style>
.skills-card {
    margin-bottom: 24px;
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    border: 1px solid var(--border-light, #e5e7eb);
}
.skills-card .child-header {
    background: linear-gradient(135deg, #1a5632 0%, #1e6b3e 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.skills-card .child-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}
.criteria-block {
    margin: 14px 20px;
    padding: 16px;
    border: 1px solid var(--border-light, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    background: var(--surface, #fff);
}
.criteria-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 8px;
}
.criteria-header h5 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--eqas-green, #1a5632);
}
.criteria-avg-badge {
    padding: 3px 12px;
    border-radius: var(--radius-full, 20px);
    font-size: 12px;
    font-weight: 700;
    color: #fff;
}
.skill-item {
    margin-bottom: 10px;
}
.skill-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    flex-wrap: wrap;
    gap: 4px;
}
.skill-item-text {
    font-size: 13px;
    color: var(--text-secondary, #555);
}
.skill-item-value {
    font-size: 13px;
    font-weight: 700;
}
.skill-progress {
    width: 100%;
    height: 8px;
    background: var(--border-light, #e5e7eb);
    border-radius: var(--radius-full, 20px);
    overflow: hidden;
}
.skill-progress-fill {
    height: 100%;
    border-radius: var(--radius-full, 20px);
    transition: width 0.6s;
}
.class-comparison {
    margin-top: 4px;
    font-size: 10px;
    color: var(--text-muted, #999);
    display: flex;
    align-items: center;
    gap: 4px;
}
.class-comparison .class-bar {
    width: 60px;
    height: 4px;
    background: var(--border-light, #e5e7eb);
    border-radius: 2px;
    overflow: hidden;
    display: inline-block;
}
.class-comparison .class-bar-fill {
    height: 100%;
    background: #999;
    border-radius: 2px;
}
.overall-box {
    text-align: center;
    padding: 20px;
    margin: 14px 20px;
    border-radius: var(--radius-md, 6px);
    border: 2px solid var(--border-light, #e5e7eb);
}
.overall-ring {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 800;
    border: 6px solid;
    margin: 0 auto 8px;
}
.section-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border-light, #e5e7eb);
}
.section-title-row h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--eqas-green, #1a5632);
}
@media (max-width: 768px) {
    .criteria-block { margin: 10px 12px; padding: 12px; }
    .overall-ring { width: 80px; height: 80px; font-size: 20px; }
}
</style>

<div class="section">
    <!-- زر العودة -->
    <div style="margin-bottom:16px;">
        <a href="<?php echo baseUrl('pages/dashboard/parent.php'); ?>" class="btn btn-outline-primary">
            <i class="fas fa-arrow-right"></i> العودة للرئيسية
        </a>
    </div>

    <?php foreach ($children as $child):
        $sid = $child['id'];
        $sdata = $childrenSkills[$sid];
        $criteriaList = $sdata['criteria'];
        $overallAvg = $sdata['overall_avg'];
        $classAvgMap = $sdata['class_avg'] ?? [];
        $overallColor = $overallAvg !== null ? getSkillColor($overallAvg) : '#999';
    ?>
    <div class="skills-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-bullseye" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div style="font-size:13px;opacity:0.85;">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                </div>
            </div>
            <?php if ($overallAvg !== null): ?>
            <div style="text-align:center;">
                <div style="font-size:11px;opacity:0.8;">المستوى العام</div>
                <div style="font-size:24px;font-weight:800;"><?php echo $overallAvg; ?>%</div>
                <div style="font-size:12px;"><?php echo getSkillLabel($overallAvg); ?> <?php echo getSkillIcon($overallAvg); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding:0;">

            <?php if (empty($criteriaList)): ?>
            <div style="text-align:center;padding:40px 20px;color:var(--text-muted,#999);">
                <div style="font-size:48px;margin-bottom:10px;">🎯</div>
                <p>لم يتم تقييم المهارات بعد لهذا الطالب</p>
            </div>
            <?php else: ?>

            <!-- المؤشر العام -->
            <?php if ($overallAvg !== null): ?>
            <div class="overall-box" style="background:<?php echo $overallColor; ?>08;">
                <div class="overall-ring" style="border-color:<?php echo $overallColor; ?>;color:<?php echo $overallColor; ?>;">
                    <?php echo number_format($overallAvg, 0); ?>%
                </div>
                <div style="font-size:20px;font-weight:700;color:<?php echo $overallColor; ?>;">
                    <?php echo getSkillLabel($overallAvg); ?> <?php echo getSkillIcon($overallAvg); ?>
                </div>
                <div style="max-width:300px;margin:10px auto 0;">
                    <div style="width:100%;height:12px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                        <div style="width:<?php echo min(100, $overallAvg); ?>%;height:100%;background:<?php echo $overallColor; ?>;border-radius:var(--radius-full,20px);transition:width 0.8s;"></div>
                    </div>
                </div>
                <?php
                // متوسط الفصل العام
                if (!empty($classAvgMap)):
                    $classOverall = count($classAvgMap) > 0 ? round(array_sum($classAvgMap) / count($classAvgMap), 1) : null;
                    if ($classOverall !== null):
                        $diff = round($overallAvg - $classOverall, 1);
                ?>
                <div style="margin-top:10px;font-size:12px;color:var(--text-muted,#999);">
                    متوسط الفصل: <strong><?php echo $classOverall; ?>%</strong>
                    <?php if ($diff > 0): ?>
                    <span style="color:#28a745;"> (أعلى بـ <?php echo $diff; ?>%)</span>
                    <?php elseif ($diff < 0): ?>
                    <span style="color:#dc3545;"> (أقل بـ <?php echo abs($diff); ?>%)</span>
                    <?php else: ?>
                    <span style="color:#ffc107;"> (مطابق)</span>
                    <?php endif; ?>
                </div>
                <?php endif; endif; ?>
            </div>
            <?php endif; ?>

            <!-- تفصيل المعايير -->
            <?php foreach ($criteriaList as $cId => $cData):
                $cAvg = $cData['avg_pct'];
                $cColor = getSkillColor($cAvg);
                $cClassAvg = $classAvgMap[$cId] ?? null;
            ?>
            <div class="criteria-block">
                <div class="criteria-header">
                    <h5><i class="fas fa-layer-group" style="margin-left:6px;opacity:0.5;"></i><?php echo e($cData['name']); ?></h5>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if ($cClassAvg !== null): ?>
                        <span style="font-size:10px;color:var(--text-muted,#999);">الفصل: <?php echo $cClassAvg; ?>%</span>
                        <?php endif; ?>
                        <span class="criteria-avg-badge" style="background:<?php echo $cColor; ?>;">
                            <?php echo $cAvg; ?>% - <?php echo getSkillLabel($cAvg); ?>
                        </span>
                    </div>
                </div>

                <!-- شريط متوسط المعيار -->
                <div style="margin-bottom:14px;">
                    <div class="skill-progress" style="height:10px;">
                        <div class="skill-progress-fill" style="width:<?php echo min(100, $cAvg); ?>%;background:<?php echo $cColor; ?>;"></div>
                    </div>
                    <?php if ($cClassAvg !== null): ?>
                    <div style="position:relative;height:4px;margin-top:2px;">
                        <div style="position:absolute;right:<?php echo $cClassAvg; ?>%;top:0;width:3px;height:4px;background:#999;border-radius:1px;" title="متوسط الفصل: <?php echo $cClassAvg; ?>%"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- عناصر المهارة -->
                <?php foreach ($cData['items'] as $item):
                    $iColor = getSkillColor($item['percentage']);
                ?>
                <div class="skill-item">
                    <div class="skill-item-header">
                        <span class="skill-item-text"><?php echo e($item['text']); ?></span>
                        <span class="skill-item-value" style="color:<?php echo $iColor; ?>;">
                            <?php echo getSkillIcon($item['percentage']); ?>
                            <?php echo $item['percentage']; ?>%
                            <?php if ($item['tool_type'] === 'yes_no'): ?>
                                <?php echo $item['value'] ? 'نعم' : 'لا'; ?>
                            <?php elseif ($item['value'] !== null): ?>
                                (<?php echo number_format($item['value'], 1); ?><?= $item['max_value'] ? '/' . number_format($item['max_value'], 0) : '' ?>)
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="skill-progress">
                        <div class="skill-progress-fill" style="width:<?php echo min(100, $item['percentage']); ?>%;background:<?php echo $iColor; ?>;"></div>
                    </div>
                    <?php if ($item['text_value']): ?>
                    <div style="font-size:11px;color:var(--text-muted,#999);margin-top:2px;">
                        💬 <?php echo e($item['text_value']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
