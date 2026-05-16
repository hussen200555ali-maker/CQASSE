<?php
/**
 * ============================================================
 * EQAS - لوحة متابعة خطط التحسين
 * نظام التقييم الشامل - Improvement Plans Dashboard
 * ============================================================
 * عرض موحد لجميع الخطط (مدرسة + معلم + طالب) لمدير المجموعة
 * ============================================================
 */

$pageTitle = 'لوحة متابعة خطط التحسين';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'لوحة المتابعة' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

// فلاتر
$selectedYear = intval($_GET['academic_year_id'] ?? 0);
$selectedSemester = intval($_GET['semester_id'] ?? 0);
$selectedSchool = intval($_GET['school_id'] ?? 0);

// جلب القوائم
$allSchools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
$allYears = $db->query("SELECT id, name FROM academic_years WHERE status != 'planning' ORDER BY start_date DESC")->fetchAll();
$allSemesters = $db->query("SELECT id, name_ar AS name FROM semesters WHERE is_active = 1 ORDER BY start_date ASC")->fetchAll();

// === 1. خطط المدارس ===
$schoolPlanWhere = 'sip.is_active = 1';
$schoolPlanParams = [];
if ($selectedSchool > 0) {
    $schoolPlanWhere .= ' AND sip.school_id = ?';
    $schoolPlanParams[] = $selectedSchool;
}

$schoolPlansStmt = $db->prepare("
    SELECT sip.*, s.name_ar AS school_name, u.full_name_ar AS responsible_name,
           cb.full_name_ar AS created_by_name
    FROM school_improvement_plans sip
    JOIN schools s ON sip.school_id = s.id
    LEFT JOIN users u ON sip.responsible_user_id = u.id
    LEFT JOIN users cb ON sip.created_by = cb.id
    WHERE $schoolPlanWhere
    ORDER BY sip.status ASC, sip.created_at DESC
");
$schoolPlansStmt->execute($schoolPlanParams);
$schoolPlans = $schoolPlansStmt->fetchAll();

$schoolPlanStats = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($schoolPlans as $sp) {
    $schoolPlanStats[$sp['status']]++;
}

// === 2. خطط المعلمين ===
$teacherPlanWhere = 'ip.is_active = 1';
$teacherPlanParams = [];
if ($selectedSchool > 0) {
    $teacherPlanWhere .= ' AND ip.school_id = ?';
    $teacherPlanParams[] = $selectedSchool;
}

$teacherPlansStmt = $db->prepare("
    SELECT ip.*, u.full_name_ar AS teacher_name, s.name_ar AS school_name,
           cb.full_name_ar AS created_by_name
    FROM improvement_plans ip
    JOIN users u ON ip.teacher_id = u.id
    JOIN schools s ON ip.school_id = s.id
    LEFT JOIN users cb ON ip.created_by = cb.id
    WHERE ip.plan_type = 'teacher' AND $teacherPlanWhere
    ORDER BY ip.status ASC, ip.created_at DESC
");
$teacherPlansStmt->execute($teacherPlanParams);
$teacherPlans = $teacherPlansStmt->fetchAll();

$teacherPlanStats = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($teacherPlans as $tp) {
    $teacherPlanStats[$tp['status']]++;
}

// === 3. خطط الطلاب ===
$studentPlanWhere = 'ip.is_active = 1';
$studentPlanParams = [];
if ($selectedSchool > 0) {
    $studentPlanWhere .= ' AND s.school_id = ?';
    $studentPlanParams[] = $selectedSchool;
}

$studentPlansStmt = $db->prepare("
    SELECT ip.*, s.full_name_ar AS student_name, sc.name_ar AS school_name,
           u.full_name_ar AS responsible_name,
           cb.full_name_ar AS created_by_name
    FROM improvement_plans ip
    JOIN students s ON ip.student_id = s.id
    JOIN schools sc ON s.school_id = sc.id
    LEFT JOIN users u ON ip.responsible_user_id = u.id
    LEFT JOIN users cb ON ip.created_by = cb.id
    WHERE $studentPlanWhere
    ORDER BY ip.status ASC, ip.created_at DESC
");
$studentPlansStmt->execute($studentPlanParams);
$studentPlans = $studentPlansStmt->fetchAll();

$studentPlanStats = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($studentPlans as $stp) {
    $studentPlanStats[$stp['status']]++;
}

// === خطط متأخرة (تجاوزت موعد المتابعة) ===
$overduePlans = [];

// خطط معلمين متأخرة
foreach ($teacherPlans as $tp) {
    if ($tp['status'] === 'active' && $tp['follow_up_date'] && strtotime($tp['follow_up_date']) < time()) {
        $daysOverdue = floor((time() - strtotime($tp['follow_up_date'])) / 86400);
        $overduePlans[] = [
            'type' => 'teacher',
            'type_label' => '👨‍🏫 معلم',
            'name' => $tp['teacher_name'],
            'school' => $tp['school_name'],
            'days_overdue' => $daysOverdue,
            'follow_up_date' => $tp['follow_up_date'],
        ];
    }
}

// خطط طلاب متأخرة
foreach ($studentPlans as $stp) {
    if ($stp['status'] === 'active' && $stp['follow_up_date'] && strtotime($stp['follow_up_date']) < time()) {
        $daysOverdue = floor((time() - strtotime($stp['follow_up_date'])) / 86400);
        $overduePlans[] = [
            'type' => 'student',
            'type_label' => '👨‍🎓 طالب',
            'name' => $stp['student_name'],
            'school' => $stp['school_name'],
            'days_overdue' => $daysOverdue,
            'follow_up_date' => $stp['follow_up_date'],
        ];
    }
}

usort($overduePlans, function($a, $b) { return $b['days_overdue'] - $a['days_overdue']; });

$statusLabels = ['active' => 'نشطة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];
$statusColors = ['active' => 'var(--warning)', 'completed' => 'var(--success)', 'cancelled' => 'var(--text-muted)'];
?>

<div class="section">
    <!-- فلاتر -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🔍 فلاتر العرض</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">المدرسة</label>
                    <select name="school_id" class="form-control">
                        <option value="0">جميع المدارس</option>
                        <?php foreach ($allSchools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSchool ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">العام الدراسي</label>
                    <select name="academic_year_id" class="form-control">
                        <option value="0">جميع السنوات</option>
                        <?php foreach ($allYears as $y): ?>
                        <option value="<?= $y['id'] ?>" <?= $y['id'] == $selectedYear ? 'selected' : '' ?>><?= e($y['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <option value="0">جميع الفصول</option>
                        <?php foreach ($allSemesters as $sm): ?>
                        <option value="<?= $sm['id'] ?>" <?= $sm['id'] == $selectedSemester ? 'selected' : '' ?>><?= e($sm['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">🔍 عرض</button>
                </div>
            </form>
        </div>
    </div>

    <!-- إحصائيات -->
    <div class="grid grid-3 mb-6" style="gap:16px;">
        <div class="card" style="border-top:4px solid var(--primary);">
            <div class="card-body text-center">
                <div style="font-size:32px;">🏫</div>
                <div style="font-size:28px;font-weight:bold;color:var(--primary);"><?= $schoolPlanStats['active'] ?></div>
                <div class="text-secondary">خطط مدارس نشطة</div>
                <div style="font-size:12px;color:var(--text-muted);">
                    مكتملة: <?= $schoolPlanStats['completed'] ?> | ملغاة: <?= $schoolPlanStats['cancelled'] ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid var(--info);">
            <div class="card-body text-center">
                <div style="font-size:32px;">👨‍🏫</div>
                <div style="font-size:28px;font-weight:bold;color:var(--info);"><?= $teacherPlanStats['active'] ?></div>
                <div class="text-secondary">خطط معلمين نشطة</div>
                <div style="font-size:12px;color:var(--text-muted);">
                    مكتملة: <?= $teacherPlanStats['completed'] ?> | ملغاة: <?= $teacherPlanStats['cancelled'] ?>
                </div>
            </div>
        </div>
        <div class="card" style="border-top:4px solid var(--secondary);">
            <div class="card-body text-center">
                <div style="font-size:32px;">👨‍🎓</div>
                <div style="font-size:28px;font-weight:bold;color:var(--secondary);"><?= $studentPlanStats['active'] ?></div>
                <div class="text-secondary">خطط طلاب نشطة</div>
                <div style="font-size:12px;color:var(--text-muted);">
                    مكتملة: <?= $studentPlanStats['completed'] ?> | ملغاة: <?= $studentPlanStats['cancelled'] ?>
                </div>
            </div>
        </div>
    </div>

    <!-- خطط متأخرة -->
    <?php if (!empty($overduePlans)): ?>
    <div class="card mb-6" style="border-right:4px solid var(--danger);">
        <div class="card-header" style="background:rgba(220,53,69,0.05);">
            <h3 class="card-title" style="color:var(--danger);">🚨 خطط متأخرة (<?= count($overduePlans) ?>)</h3>
        </div>
        <div class="card-body">
            <?php foreach ($overduePlans as $op): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light);">
                <div>
                    <strong><?= $op['type_label'] ?>: <?= e($op['name']) ?></strong>
                    <span class="text-secondary">— <?= e($op['school']) ?></span>
                </div>
                <span style="padding:4px 12px;border-radius:var(--radius-full);background:rgba(220,53,69,0.1);color:var(--danger);font-weight:600;font-size:12px;">
                    متأخرة بـ <?= $op['days_overdue'] ?> يوم
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- جدول الخطط النشطة -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">📋 جميع الخطط النشطة</h3>
        </div>
        <div class="card-body">
            <?php
            $allActivePlans = [];
            foreach ($schoolPlans as $sp) {
                if ($sp['status'] === 'active') {
                    $allActivePlans[] = [
                        'type' => 'school',
                        'type_icon' => '🏫',
                        'type_label' => 'مدرسة',
                        'name' => $sp['school_name'],
                        'detail' => $sp['trigger_type'] === 'climate' ? 'مناخ' : ($sp['trigger_type'] === 'facility' ? 'مرافق' : 'مناخ + مرافق'),
                        'progress' => $sp['progress'],
                        'status' => $sp['status'],
                        'created_at' => $sp['created_at'],
                        'responsible' => $sp['responsible_name'] ?? '—',
                        'url' => baseUrl('pages/supervision/improvement_plan.php?school_id=' . $sp['school_id']),
                    ];
                }
            }
            foreach ($teacherPlans as $tp) {
                if ($tp['status'] === 'active') {
                    $allActivePlans[] = [
                        'type' => 'teacher',
                        'type_icon' => '👨‍🏫',
                        'type_label' => 'معلم',
                        'name' => $tp['teacher_name'],
                        'detail' => $tp['school_name'],
                        'progress' => $tp['progress'],
                        'status' => $tp['status'],
                        'created_at' => $tp['created_at'],
                        'responsible' => $tp['created_by_name'] ?? '—',
                        'url' => baseUrl('pages/supervision/development_plan.php?school_id=' . $tp['school_id']),
                    ];
                }
            }
            foreach ($studentPlans as $stp) {
                if ($stp['status'] === 'active') {
                    $allActivePlans[] = [
                        'type' => 'student',
                        'type_icon' => '👨‍🎓',
                        'type_label' => 'طالب',
                        'name' => $stp['student_name'],
                        'detail' => $stp['school_name'],
                        'progress' => null,
                        'status' => $stp['status'],
                        'created_at' => $stp['created_at'],
                        'responsible' => $stp['responsible_name'] ?? '—',
                        'url' => '#',
                    ];
                }
            }

            usort($allActivePlans, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
            ?>

            <?php if (empty($allActivePlans)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">✅</div>
                <p>لا توجد خطط نشطة حالياً.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>النوع</th>
                            <th>الاسم</th>
                            <th>التفاصيل</th>
                            <th>التقدم</th>
                            <th>المسؤول</th>
                            <th>تاريخ الإنشاء</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($allActivePlans as $ap): ?>
                        <tr>
                            <td><?= $counter++ ?></td>
                            <td style="font-size:20px;"><?= $ap['type_icon'] ?></td>
                            <td><strong><?= e($ap['name']) ?></strong></td>
                            <td class="text-secondary"><?= e($ap['detail']) ?></td>
                            <td>
                                <?php if ($ap['progress'] !== null): ?>
                                <?php $pColor = $ap['progress'] >= 80 ? 'var(--success)' : ($ap['progress'] >= 40 ? 'var(--warning)' : 'var(--danger)'); ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                                        <div style="width:<?= $ap['progress'] ?>%;height:100%;background:<?= $pColor ?>;border-radius:var(--radius-full);"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:600;color:<?= $pColor ?>;"><?= $ap['progress'] ?>%</span>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= e($ap['responsible']) ?></td>
                            <td style="font-size:12px;"><?= formatDateAr($ap['created_at'], 'short') ?></td>
                            <td><a href="<?= $ap['url'] ?>" class="btn btn-sm btn-light">عرض</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
