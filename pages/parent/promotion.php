<?php
/**
 * ============================================================
 * EQAS - قرارات ترفيع أبناء ولي الأمر
 * نظام التقييم الشامل - عرض قرارات الترفيع لجميع الأبناء
 * ============================================================
 * يعرض قرارات الترفيع لجميع الأبناء:
 * - آخر قرار لكل عام دراسي من promotion_decisions
 * - عرض: academic_score, behavior_score, attendance_rate, final_decision
 * - تلوين: promoted=أخضر, repeated=أحمر, conditional=أصفر
 * - خطة التحسين إذا كان القرار مشروط
 * ============================================================
 */

$pageTitle = 'قرارات الترفيع';
$breadcrumb = ['لوحة ولي الأمر' => baseUrl('pages/dashboard/parent.php'), 'قرارات الترفيع' => null];

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
$childrenPromotion = [];
foreach ($children as $child) {
    $sid = $child['id'];
    $data = [
        'decisions' => [],
        'latest' => null,
        'improvement_plan' => null
    ];

    // 1. قرارات الترفيع (الأحدث أولاً)
    try {
        $stmt = $db->prepare("
            SELECT pd.id, pd.academic_year_id, pd.semester_id,
                   pd.academic_score, pd.behavior_score, pd.attendance_score,
                   pd.academic_pass, pd.behavior_pass, pd.attendance_pass,
                   pd.decision, pd.status, pd.notes,
                   pd.approved_by, pd.approved_at, pd.decided_at,
                   ay.name as academic_year_name,
                   sem.name_ar as semester_name
            FROM promotion_decisions pd
            LEFT JOIN academic_years ay ON pd.academic_year_id = ay.id
            LEFT JOIN semesters sem ON pd.semester_id = sem.id
            WHERE pd.student_id = ? AND pd.is_active = 1
            ORDER BY pd.academic_year_id DESC, pd.semester_id DESC
        ");
        $stmt->execute([$sid]);
        $data['decisions'] = $stmt->fetchAll();
        $data['latest'] = !empty($data['decisions']) ? $data['decisions'][0] : null;
    } catch (Exception $e) {}

    // 2. خطة التحسين إن وجدت (للقرار المشروط)
    if ($data['latest'] && $data['latest']['decision'] === 'conditional') {
        try {
            $stmt = $db->prepare("
                SELECT ip.id, ip.weak_areas, ip.goals, ip.strategies,
                       ip.timeline, ip.parent_involvement, ip.follow_up_date,
                       ip.notes, ip.status as plan_status,
                       u.full_name_ar as teacher_name
                FROM improvement_plans ip
                LEFT JOIN users u ON ip.responsible_teacher_id = u.id
                WHERE ip.decision_id = ? AND ip.is_active = 1
                ORDER BY ip.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$data['latest']['id']]);
            $data['improvement_plan'] = $stmt->fetch();
        } catch (Exception $e) {}
    }

    $childrenPromotion[$sid] = $data;
}

// ترجمة القرار
$decisionLabels = [
    'promoted' => ['label' => 'مرفع', 'color' => '#28a745', 'bg' => 'rgba(40,167,69,0.08)', 'icon' => '🎓', 'border' => '#28a745'],
    'repeated' => ['label' => 'معيد', 'color' => '#dc3545', 'bg' => 'rgba(220,53,69,0.08)', 'icon' => '🔁', 'border' => '#dc3545'],
    'conditional' => ['label' => 'مشروط', 'color' => '#ffc107', 'bg' => 'rgba(255,193,7,0.08)', 'icon' => '⚠️', 'border' => '#ffc107'],
    'under_review' => ['label' => 'قيد المراجعة', 'color' => '#17a2b8', 'bg' => 'rgba(23,162,184,0.08)', 'icon' => '⏳', 'border' => '#17a2b8']
];

$statusLabels = [
    'proposed' => 'مقترح',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض'
];
$statusColors = [
    'proposed' => '#ffc107',
    'approved' => '#28a745',
    'rejected' => '#dc3545'
];
?>

<style>
.promo-card {
    margin-bottom: 24px;
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    border: 1px solid var(--border-light, #e5e7eb);
}
.promo-card .child-header {
    background: linear-gradient(135deg, #1a5632 0%, #1e6b3e 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.promo-card .child-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}
.decision-banner {
    padding: 20px;
    text-align: center;
    border-bottom: 3px solid;
}
.decision-banner .decision-icon {
    font-size: 48px;
    margin-bottom: 8px;
}
.decision-banner .decision-label {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 4px;
}
.decision-banner .decision-status {
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 12px;
    border-radius: var(--radius-full, 20px);
    color: #fff;
}
.score-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin: 16px 20px;
}
.score-box {
    padding: 16px 12px;
    border-radius: var(--radius-md, 6px);
    border: 1px solid var(--border-light, #e5e7eb);
    text-align: center;
}
.score-box .score-title {
    font-size: 12px;
    color: var(--text-muted, #999);
    margin-bottom: 6px;
}
.score-box .score-value {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 4px;
}
.score-box .score-status {
    font-size: 11px;
    font-weight: 600;
}
.score-box .score-bar {
    width: 100%;
    height: 6px;
    background: var(--border-light, #e5e7eb);
    border-radius: var(--radius-full, 20px);
    overflow: hidden;
    margin-top: 8px;
}
.score-box .score-bar-fill {
    height: 100%;
    border-radius: var(--radius-full, 20px);
    transition: width 0.6s;
}
.improvement-plan-box {
    margin: 16px 20px;
    padding: 20px;
    border: 2px dashed #ffc107;
    border-radius: var(--radius-md, 6px);
    background: rgba(255,193,7,0.04);
}
.improvement-plan-box h4 {
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 700;
    color: #856404;
}
.plan-item {
    margin-bottom: 10px;
    padding: 10px 12px;
    border-radius: var(--radius-sm, 4px);
    background: #fff;
    border: 1px solid var(--border-light, #e5e7eb);
}
.plan-item .plan-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted, #999);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.plan-item .plan-value {
    font-size: 14px;
    color: #333;
}
.history-item {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border-light, #e5e7eb);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.history-item:hover {
    background: rgba(26,86,50,0.02);
}
.history-item:last-child {
    border-bottom: none;
}
.decision-badge-sm {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: var(--radius-full, 20px);
    font-size: 12px;
    font-weight: 600;
    color: #fff;
}
.pass-badge {
    font-size: 11px;
    padding: 1px 8px;
    border-radius: var(--radius-full, 20px);
    font-weight: 600;
}
.section-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 16px 20px 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border-light, #e5e7eb);
}
.section-title-row h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--eqas-green, #1a5632);
}
.notes-box {
    margin: 12px 20px;
    padding: 12px 16px;
    border-radius: var(--radius-md, 6px);
    background: rgba(26,86,50,0.04);
    border-right: 4px solid var(--eqas-green, #1a5632);
    font-size: 14px;
    color: var(--text-secondary, #555);
}
@media (max-width: 768px) {
    .score-grid { grid-template-columns: 1fr 1fr; margin: 10px 12px; }
    .decision-banner .decision-label { font-size: 22px; }
    .score-box .score-value { font-size: 22px; }
    .improvement-plan-box { margin: 10px 12px; padding: 14px; }
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
        $pdata = $childrenPromotion[$sid];
        $latest = $pdata['latest'];
        $decisions = $pdata['decisions'];
        $plan = $pdata['improvement_plan'];

        if ($latest):
            $decInfo = $decisionLabels[$latest['decision']] ?? $decisionLabels['under_review'];
    ?>
    <div class="promo-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-user-graduate" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div style="font-size:13px;opacity:0.85;">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                </div>
            </div>
            <div style="text-align:left;">
                <div style="font-size:11px;opacity:0.8;"><?php echo e($latest['academic_year_name'] ?? ''); ?></div>
                <div style="font-size:11px;opacity:0.7;"><?php echo e($latest['semester_name'] ?? ''); ?></div>
            </div>
        </div>

        <!-- شعار القرار -->
        <div class="decision-banner" style="background:<?php echo $decInfo['bg']; ?>;border-bottom-color:<?php echo $decInfo['border']; ?>;">
            <div class="decision-icon"><?php echo $decInfo['icon']; ?></div>
            <div class="decision-label" style="color:<?php echo $decInfo['color']; ?>;">
                <?php echo $decInfo['label']; ?>
            </div>
            <div class="decision-status" style="background:<?php echo $statusColors[$latest['status']] ?? '#999'; ?>;">
                <?php echo $statusLabels[$latest['status']] ?? $latest['status']; ?>
                <?php if ($latest['status'] === 'approved' && $latest['approved_at']): ?>
                - <?php echo formatDateAr($latest['approved_at'], 'short'); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- بطاقات الدرجات الثلاث -->
        <div class="score-grid">
            <!-- الدرجة الأكاديمية -->
            <div class="score-box">
                <div class="score-title"><i class="fas fa-book" style="margin-left:4px;"></i>الأداء الأكاديمي</div>
                <div class="score-value" style="color:<?php echo $latest['academic_score'] !== null ? ($latest['academic_pass'] ? '#28a745' : '#dc3545') : '#999'; ?>;">
                    <?php echo $latest['academic_score'] !== null ? number_format($latest['academic_score'], 1) . '%' : '-'; ?>
                </div>
                <div class="score-status">
                    <?php if ($latest['academic_score'] !== null): ?>
                    <span class="pass-badge" style="background:<?php echo $latest['academic_pass'] ? 'rgba(40,167,69,0.15)' : 'rgba(220,53,69,0.15)'; ?>;color:<?php echo $latest['academic_pass'] ? '#28a745' : '#dc3545'; ?>;">
                        <?php echo $latest['academic_pass'] ? '✓ اجتاز' : '✗ لم يجتز'; ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#999;font-size:12px;">لم يحدد</span>
                    <?php endif; ?>
                </div>
                <?php if ($latest['academic_score'] !== null): ?>
                <div class="score-bar">
                    <div class="score-bar-fill" style="width:<?php echo min(100, $latest['academic_score']); ?>%;background:<?php echo $latest['academic_pass'] ? '#28a745' : '#dc3545'; ?>;"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- درجة السلوك -->
            <div class="score-box">
                <div class="score-title"><i class="fas fa-star" style="margin-left:4px;"></i>السلوك</div>
                <div class="score-value" style="color:<?php echo $latest['behavior_score'] !== null ? ($latest['behavior_pass'] ? '#28a745' : '#dc3545') : '#999'; ?>;">
                    <?php echo $latest['behavior_score'] !== null ? number_format($latest['behavior_score'], 1) . '%' : '-'; ?>
                </div>
                <div class="score-status">
                    <?php if ($latest['behavior_score'] !== null): ?>
                    <span class="pass-badge" style="background:<?php echo $latest['behavior_pass'] ? 'rgba(40,167,69,0.15)' : 'rgba(220,53,69,0.15)'; ?>;color:<?php echo $latest['behavior_pass'] ? '#28a745' : '#dc3545'; ?>;">
                        <?php echo $latest['behavior_pass'] ? '✓ اجتاز' : '✗ لم يجتز'; ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#999;font-size:12px;">لم يحدد</span>
                    <?php endif; ?>
                </div>
                <?php if ($latest['behavior_score'] !== null): ?>
                <div class="score-bar">
                    <div class="score-bar-fill" style="width:<?php echo min(100, $latest['behavior_score']); ?>%;background:<?php echo $latest['behavior_pass'] ? '#28a745' : '#dc3545'; ?>;"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- درجة الحضور -->
            <div class="score-box">
                <div class="score-title"><i class="fas fa-calendar-check" style="margin-left:4px;"></i>الحضور</div>
                <div class="score-value" style="color:<?php echo $latest['attendance_score'] !== null ? ($latest['attendance_pass'] ? '#28a745' : '#dc3545') : '#999'; ?>;">
                    <?php echo $latest['attendance_score'] !== null ? number_format($latest['attendance_score'], 1) . '%' : '-'; ?>
                </div>
                <div class="score-status">
                    <?php if ($latest['attendance_score'] !== null): ?>
                    <span class="pass-badge" style="background:<?php echo $latest['attendance_pass'] ? 'rgba(40,167,69,0.15)' : 'rgba(220,53,69,0.15)'; ?>;color:<?php echo $latest['attendance_pass'] ? '#28a745' : '#dc3545'; ?>;">
                        <?php echo $latest['attendance_pass'] ? '✓ اجتاز' : '✗ لم يجتز'; ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#999;font-size:12px;">لم يحدد</span>
                    <?php endif; ?>
                </div>
                <?php if ($latest['attendance_score'] !== null): ?>
                <div class="score-bar">
                    <div class="score-bar-fill" style="width:<?php echo min(100, $latest['attendance_score']); ?>%;background:<?php echo $latest['attendance_pass'] ? '#28a745' : '#dc3545'; ?>;"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ملاحظات -->
        <?php if ($latest['notes']): ?>
        <div class="notes-box">
            <strong><i class="fas fa-comment" style="margin-left:4px;"></i>ملاحظات:</strong><br>
            <?php echo e($latest['notes']); ?>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════
             خطة التحسين (للقرار المشروط)
             ═══════════════════════════════════════════ -->
        <?php if ($plan): ?>
        <div class="improvement-plan-box">
            <h4><i class="fas fa-clipboard-list" style="margin-left:6px;"></i>خطة التحسين</h4>

            <?php if ($plan['plan_status']): ?>
            <div style="margin-bottom:12px;">
                <span style="font-size:12px;padding:3px 10px;border-radius:var(--radius-full,20px);background:<?php echo $plan['plan_status'] === 'active' ? '#ffc107' : ($plan['plan_status'] === 'completed' ? '#28a745' : '#999'); ?>;color:#fff;">
                    <?php echo $plan['plan_status'] === 'active' ? '🔵 نشطة' : ($plan['plan_status'] === 'completed' ? '✅ مكتملة' : '⛔ ملغاة'); ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if ($plan['weak_areas']): ?>
            <div class="plan-item">
                <div class="plan-label"><i class="fas fa-exclamation-circle" style="margin-left:4px;"></i>نقاط الضعف</div>
                <div class="plan-value"><?php echo e($plan['weak_areas']); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($plan['goals']): ?>
            <div class="plan-item">
                <div class="plan-label"><i class="fas fa-bullseye" style="margin-left:4px;"></i>الأهداف</div>
                <div class="plan-value"><?php echo e($plan['goals']); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($plan['strategies']): ?>
            <div class="plan-item">
                <div class="plan-label"><i class="fas fa-lightbulb" style="margin-left:4px;"></i>الاستراتيجيات</div>
                <div class="plan-value"><?php echo e($plan['strategies']); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($plan['timeline']): ?>
            <div class="plan-item">
                <div class="plan-label"><i class="fas fa-clock" style="margin-left:4px;"></i>الجدول الزمني</div>
                <div class="plan-value"><?php echo e($plan['timeline']); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($plan['parent_involvement']): ?>
            <div class="plan-item" style="border-right:3px solid var(--eqas-green,#1a5632);">
                <div class="plan-label"><i class="fas fa-hands-helping" style="margin-left:4px;"></i>مشاركة ولي الأمر</div>
                <div class="plan-value" style="font-weight:600;color:var(--eqas-green,#1a5632);"><?php echo e($plan['parent_involvement']); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($plan['teacher_name']): ?>
            <div style="font-size:12px;color:var(--text-muted,#999);margin-top:8px;">
                <i class="fas fa-chalkboard-teacher" style="margin-left:4px;"></i>المعلم المسؤول: <strong><?php echo e($plan['teacher_name']); ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($plan['follow_up_date']): ?>
            <div style="font-size:12px;color:var(--text-muted,#999);margin-top:4px;">
                <i class="fas fa-calendar" style="margin-left:4px;"></i>تاريخ المتابعة: <strong><?php echo formatDateAr($plan['follow_up_date'], 'medium'); ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($plan['notes']): ?>
            <div style="font-size:12px;color:var(--text-secondary,#666);margin-top:8px;padding:8px;background:rgba(255,193,7,0.06);border-radius:var(--radius-sm,4px);">
                💬 <?php echo e($plan['notes']); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php elseif ($latest['decision'] === 'conditional'): ?>
        <div style="margin:16px 20px;padding:16px;border:1px dashed #ffc107;border-radius:var(--radius-md,6px);text-align:center;color:#856404;background:rgba(255,193,7,0.04);">
            <i class="fas fa-hourglass-half" style="font-size:24px;margin-bottom:8px;display:block;"></i>
            <strong>خطة التحسين قيد الإعداد</strong><br>
            <span style="font-size:13px;">سيتم إبلاغك بمجرد إعداد خطة التحسين من قبل إدارة المدرسة.</span>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════
             سجل القرارات السابقة
             ═══════════════════════════════════════════ -->
        <?php if (count($decisions) > 1): ?>
        <div class="section-title-row">
            <h4><i class="fas fa-history" style="margin-left:6px;"></i>سجل القرارات السابقة</h4>
        </div>
        <div style="max-height:300px;overflow-y:auto;">
            <?php for ($di = 1; $di < count($decisions); $di++):
                $dec = $decisions[$di];
                $dInfo = $decisionLabels[$dec['decision']] ?? $decisionLabels['under_review'];
            ?>
            <div class="history-item">
                <div>
                    <div style="font-size:13px;font-weight:600;"><?php echo e($dec['academic_year_name'] ?? ''); ?> - <?php echo e($dec['semester_name'] ?? ''); ?></div>
                    <div style="font-size:11px;color:var(--text-muted,#999);margin-top:2px;">
                        <?php echo formatDateAr($dec['decided_at'], 'short'); ?>
                        <?php if ($dec['notes']): ?> | <?php echo e(truncate($dec['notes'], 50)); ?><?php endif; ?>
                    </div>
                </div>
                <span class="decision-badge-sm" style="background:<?php echo $dInfo['color']; ?>;">
                    <?php echo $dInfo['icon']; ?> <?php echo $dInfo['label']; ?>
                </span>
            </div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php else: // لا يوجد قرار ?>
    <div class="promo-card">
        <div class="child-header">
            <div>
                <h3><i class="fas fa-user-graduate" style="margin-left:8px;"></i><?php echo e($child['full_name_ar']); ?></h3>
                <div style="font-size:13px;opacity:0.85;">
                    <?php echo e($child['grade_name']); ?> | <?php echo e($child['school_name']); ?>
                </div>
            </div>
        </div>
        <div class="card-body" style="text-align:center;padding:40px 20px;color:var(--text-muted,#999);">
            <div style="font-size:48px;margin-bottom:10px;">📋</div>
            <p>لم يتم إصدار قرار ترفيع بعد لهذا الطالب</p>
            <p style="font-size:12px;">سيتم إظهار قرار الترفيع بمجرد صدوره من إدارة المدرسة.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
