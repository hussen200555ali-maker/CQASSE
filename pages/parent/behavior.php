<?php
/**
 * ============================================================
 * EQAS - سلوك أبناء ولي الأمر
 * نظام التقييم الشامل - عرض بيانات السلوك لجميع الأبناء
 * ============================================================
 * يعرض بيانات السلوك لجميع الأبناء:
 * - آخر درجة سلوك من behavior_scores
 * - آخر 10 حوادث سلوكية من behavior_incidents
 * - نتائج تقييم السلوك من assessment_responses
 * - اتجاهات السلوك عبر الفصول
 * - تلوين: ≥80% ممتاز، ≥60% جيد، <60% يحتاج اهتمام
 * ============================================================
 */

$pageTitle = 'سلوك الأبناء';
$breadcrumb = ['لوحة ولي الأمر' => baseUrl('pages/dashboard/parent.php'), 'سلوك الأبناء' => null];

require_once __DIR__ . '/../../includes/header.php';

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
$childrenBehavior = [];
foreach ($children as $child) {
    $sid = $child['id'];
    $data = [
        'score' => null,
        'scores_history' => [],
        'incidents' => [],
        'evaluations' => []
    ];

    // 1. درجة السلوك الحالية من evaluations
    try {
        $stmt = $db->prepare("
            SELECT e.id, e.starting_points, e.earned_points, e.deducted_points,
                   e.current_percentage, e.is_passing, e.semester_id,
                   sem.name_ar as semester_name
            FROM evaluations e
            LEFT JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.is_active = 1
            AND e.academic_year_id = ?
            ORDER BY e.semester_id DESC
            LIMIT 1
        ");
        $stmt->execute([$sid, $academicYearId]);
        $data['score'] = $stmt->fetch();
    } catch (Exception $e) {}

    // 2. تاريخ درجات السلوك عبر الفصول (للاتجاهات)
    try {
        $stmt = $db->prepare("
            SELECT e.current_percentage, e.semester_id, sem.name_ar as semester_name
            FROM evaluations e
            LEFT JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.is_active = 1
            ORDER BY e.semester_id ASC
        ");
        $stmt->execute([$sid]);
        $data['scores_history'] = $stmt->fetchAll();
    } catch (Exception $e) {}

    // 3. آخر 10 حوادث سلوكية
    try {
        $stmt = $db->prepare("
            SELECT bi.id, bi.incident_date, bi.description, bi.points,
                   bc.name_ar as category_name, bc.type as category_type,
                   sem.name_ar as semester_name
            FROM behavior_incidents bi
            LEFT JOIN behavior_categories bc ON bi.category_id = bc.id
            LEFT JOIN semesters sem ON bi.semester_id = sem.id
            WHERE bi.student_id = ? AND bi.is_active = 1
            ORDER BY bi.incident_date DESC
            LIMIT 10
        ");
        $stmt->execute([$sid]);
        $data['incidents'] = $stmt->fetchAll();
    } catch (Exception $e) {}

    // 4. نتائج تقييم السلوك من assessment_responses (behavior_eval)
    try {
        $stmt = $db->prepare("
            SELECT ar.criteria_id, acr.name_ar as criteria_name,
                   ar.numeric_value, ar.response_date,
                   sem.name_ar as semester_name,
                   ai.text_ar as item_text, ai.tool_type,
                   af.name_ar as form_name
            FROM assessment_responses ar
            JOIN assessment_structure ai ON ar.item_id = ai.id
            JOIN assessment_forms af ON ar.form_id = af.id
            LEFT JOIN assessment_structure acr ON ar.criteria_id = acr.id
            LEFT JOIN semesters sem ON ar.semester_id = sem.id
            WHERE ar.student_id = ? AND ar.is_active = 1
            AND af.form_key = 'behavior_eval'
            AND ar.academic_year_id = ?
            ORDER BY ar.response_date DESC, acr.sort_order ASC
        ");
        $stmt->execute([$sid, $academicYearId]);
        $data['evaluations'] = $stmt->fetchAll();
    } catch (Exception $e) {}

    $childrenBehavior[$sid] = $data;
}

/**
 * دالة تحديد مستوى السلوك
 */
function getBehaviorLevel($pct) {
    if ($pct >= 80) return ['label' => 'ممتاز', 'class' => 'success', 'hex' => '#28a745', 'icon' => '😊'];
    if ($pct >= 60) return ['label' => 'جيد', 'class' => 'warning', 'hex' => '#ffc107', 'icon' => '😐'];
    return ['label' => 'يحتاج اهتمام', 'class' => 'danger', 'hex' => '#dc3545', 'icon' => '😟'];
}
?>

<style>
.behavior-card {
    margin-bottom: 24px;
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    border: 1px solid var(--border-light, #e5e7eb);
}
.behavior-card .child-header {
    background: linear-gradient(135deg, #1a5632 0%, #1e6b3e 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.behavior-card .child-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}
.behavior-ring {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 800;
    border: 5px solid;
    margin: 0 auto 8px;
}
.behavior-level-label {
    font-size: 18px;
    font-weight: 700;
    text-align: center;
}
.incident-row {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-light, #e5e7eb);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
}
.incident-row:hover {
    background: rgba(26,86,50,0.02);
}
.incident-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: var(--radius-full, 20px);
    font-size: 11px;
    font-weight: 600;
}
.eval-criteria-block {
    margin-bottom: 12px;
    padding: 10px 14px;
    border: 1px solid var(--border-light, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    background: rgba(26,86,50,0.02);
}
.trend-bar {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    height: 100px;
    padding: 8px 0;
}
.trend-bar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    max-width: 80px;
}
.trend-bar-value {
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 4px;
}
.trend-bar-fill {
    width: 100%;
    border-radius: 4px 4px 0 0;
    transition: height 0.6s;
    min-height: 4px;
}
.trend-bar-label {
    font-size: 9px;
    color: var(--text-muted, #999);
    text-align: center;
    margin-top: 4px;
    word-wrap: break-word;
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
    .behavior-card .child-header {
        padding: 12px 14px;
    }
    .behavior-ring {
        width: 70px;
        height: 70px;
        font-size: 18px;
    }
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
        $bdata = $childrenBehavior[$sid];
        $score = $bdata['score'];
        $currentPct = $score ? (float)$score['current_percentage'] : null;
        $level = $currentPct !== null ? getBehaviorLevel($currentPct) : null;
    ?>
    <div class="behavior-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-user-shield" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div style="font-size:13px;opacity:0.85;">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                </div>
            </div>
            <?php if ($score): ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;opacity:0.8;">الفصل:</span>
                <span style="font-size:13px;font-weight:600;"><?php echo e($score['semester_name'] ?? '-'); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-body" style="padding:20px;">
            <!-- ═══════════════════════════════════════════
                 مؤشر السلوك الحالي
                 ═══════════════════════════════════════════ -->
            <?php if ($currentPct !== null && $level): ?>
            <div style="text-align:center;margin-bottom:24px;padding:20px;background:<?php echo $level['hex']; ?>08;border-radius:var(--radius-md,6px);">
                <div class="behavior-ring" style="border-color:<?php echo $level['hex']; ?>;color:<?php echo $level['hex']; ?>;">
                    <?php echo number_format($currentPct, 0); ?>%
                </div>
                <div style="font-size:28px;margin-bottom:4px;"><?php echo $level['icon']; ?></div>
                <div class="behavior-level-label" style="color:<?php echo $level['hex']; ?>;">
                    <?php echo $level['label']; ?>
                </div>
                <!-- شريط التقدم -->
                <div style="max-width:300px;margin:12px auto 0;">
                    <div style="width:100%;height:10px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                        <div style="width:<?php echo min(100, $currentPct); ?>%;height:100%;background:<?php echo $level['hex']; ?>;border-radius:var(--radius-full,20px);transition:width 0.8s;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted,#999);margin-top:4px;">
                        <span>ضعيف</span><span>جيد</span><span>ممتاز</span>
                    </div>
                </div>
                <!-- تفاصيل النقاط -->
                <div style="display:flex;justify-content:center;gap:20px;margin-top:12px;font-size:13px;">
                    <span>نقاط البداية: <strong><?php echo number_format($score['starting_points'], 0); ?></strong></span>
                    <span style="color:#28a745;">مكتسبة: <strong>+<?php echo number_format($score['earned_points'], 1); ?></strong></span>
                    <span style="color:#dc3545;">مخصومة: <strong>-<?php echo number_format($score['deducted_points'], 1); ?></strong></span>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted,#999);margin-bottom:16px;">
                <div style="font-size:40px;margin-bottom:8px;">📋</div>
                <p>لم يتم تقييم السلوك بعد لهذا الطالب</p>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 اتجاه السلوك عبر الفصول
                 ═══════════════════════════════════════════ -->
            <?php if (count($bdata['scores_history']) > 1): ?>
            <div style="margin-bottom:24px;">
                <div class="section-title-row">
                    <h4><i class="fas fa-chart-line" style="margin-left:6px;"></i>اتجاه السلوك عبر الفصول</h4>
                </div>
                <div class="trend-bar">
                    <?php foreach ($bdata['scores_history'] as $hist):
                        $histPct = (float)$hist['current_percentage'];
                        $histLevel = getBehaviorLevel($histPct);
                        $barHeight = max(10, $histPct);
                    ?>
                    <div class="trend-bar-item">
                        <div class="trend-bar-value" style="color:<?php echo $histLevel['hex']; ?>;"><?php echo number_format($histPct, 0); ?>%</div>
                        <div class="trend-bar-fill" style="height:<?php echo $barHeight; ?>px;background:<?php echo $histLevel['hex']; ?>;"></div>
                        <div class="trend-bar-label"><?php echo e($hist['semester_name'] ?? 'فصل'); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 نتائج تقييم السلوك
                 ═══════════════════════════════════════════ -->
            <?php if (!empty($bdata['evaluations'])): ?>
            <div style="margin-bottom:24px;">
                <div class="section-title-row">
                    <h4><i class="fas fa-clipboard-check" style="margin-left:6px;"></i>نتائج تقييم السلوك</h4>
                </div>
                <?php
                // تنظيم حسب المعيار
                $evalByCriteria = [];
                foreach ($bdata['evaluations'] as $eval) {
                    $cId = $eval['criteria_id'] ?? 0;
                    $cName = $eval['criteria_name'] ?? 'عناصر التقييم';
                    if (!isset($evalByCriteria[$cId])) {
                        $evalByCriteria[$cId] = ['name' => $cName, 'items' => []];
                    }
                    $evalByCriteria[$cId]['items'][] = $eval;
                }
                ?>
                <?php foreach ($evalByCriteria as $cId => $criteriaGroup): ?>
                <div class="eval-criteria-block">
                    <div style="font-weight:600;margin-bottom:8px;color:var(--eqas-green,#1a5632);"><?php echo e($criteriaGroup['name']); ?></div>
                    <?php foreach ($criteriaGroup['items'] as $item):
                        $itemVal = $item['numeric_value'];
                        $itemPct = 0;
                        // حساب النسبة حسب نوع الأداة
                        if ($item['tool_type'] === 'likert_5' && $itemVal !== null) {
                            $itemPct = round(($itemVal / 5) * 100, 1);
                        } elseif ($item['tool_type'] === 'numeric_100' && $itemVal !== null) {
                            $itemPct = round($itemVal, 1);
                        } elseif ($itemVal !== null && $itemVal > 0) {
                            $itemPct = min(100, round($itemVal * 20, 1));
                        }
                        $itemLevel = getBehaviorLevel($itemPct);
                    ?>
                    <div style="margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;flex-wrap:wrap;gap:4px;">
                            <span style="font-size:13px;"><?php echo e($item['item_text']); ?></span>
                            <span style="font-size:12px;font-weight:700;color:<?php echo $itemLevel['hex']; ?>;">
                                <?php echo $itemVal !== null ? number_format($itemVal, 1) : '-'; ?>
                                <?php if ($itemPct > 0): ?>(<?php echo $itemPct; ?>%)<?php endif; ?>
                            </span>
                        </div>
                        <?php if ($itemPct > 0): ?>
                        <div style="width:100%;height:6px;background:var(--border-light,#e5e7eb);border-radius:var(--radius-full,20px);overflow:hidden;">
                            <div style="width:<?php echo min(100, $itemPct); ?>%;height:100%;background:<?php echo $itemLevel['hex']; ?>;border-radius:var(--radius-full,20px);transition:width 0.6s;"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
                 الحوادث السلوكية الأخيرة
                 ═══════════════════════════════════════════ -->
            <div>
                <div class="section-title-row">
                    <h4><i class="fas fa-exclamation-triangle" style="margin-left:6px;"></i>آخر الحوادث السلوكية</h4>
                </div>
                <?php if (empty($bdata['incidents'])): ?>
                <div style="text-align:center;padding:16px;color:var(--text-muted,#999);">
                    <div style="font-size:30px;margin-bottom:4px;">✅</div>
                    <p>لا توجد حوادث سلوكية مسجلة</p>
                </div>
                <?php else: ?>
                <div style="max-height:400px;overflow-y:auto;border:1px solid var(--border-light,#e5e7eb);border-radius:var(--radius-md,6px);">
                    <?php foreach ($bdata['incidents'] as $inc):
                        $isPositive = ($inc['category_type'] ?? 'negative') === 'positive';
                        $incColor = $isPositive ? '#28a745' : '#dc3545';
                        $incBg = $isPositive ? 'rgba(40,167,69,0.06)' : 'rgba(220,53,69,0.06)';
                    ?>
                    <div class="incident-row" style="background:<?php echo $incBg; ?>;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                                <span class="incident-type-badge" style="background:<?php echo $incColor; ?>18;color:<?php echo $incColor; ?>;">
                                    <?php if ($isPositive): ?>👍<?php else: ?>⚠️<?php endif; ?>
                                    <?php echo e($inc['category_name'] ?? 'سلوك'); ?>
                                </span>
                                <span style="font-size:11px;color:var(--text-muted,#999);">
                                    <?php echo formatDateAr($inc['incident_date'], 'short'); ?>
                                </span>
                            </div>
                            <?php if ($inc['description']): ?>
                            <div style="font-size:13px;color:var(--text-secondary,#666);margin-top:4px;">
                                <?php echo e($inc['description']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:center;flex-shrink:0;">
                            <div style="font-size:16px;font-weight:700;color:<?php echo $incColor; ?>;">
                                <?php echo $isPositive ? '+' : ''; ?><?php echo number_format($inc['points'], 1); ?>
                            </div>
                            <div style="font-size:10px;color:var(--text-muted,#999);">نقطة</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
