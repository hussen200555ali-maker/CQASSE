<?php
/**
 * ============================================================
 * EQAS - الاعتماد الدفعي لقرارات الترفيع
 * نظام التقييم الشامل - Batch Approval of Promotion Decisions
 * ============================================================
 * صفحة الاعتماد الدفعي لقرارات الترفيع مع:
 * - تصفية حسب الصف والعام والفصل والحالة
 * - اختيار متعدد واعتماد/رفض دفعي
 * - اعتماد/رفض فردي مع ملاحظات
 * - إحصائيات القرارات المقترحة/المعتمدة/المرفوضة
 * - حماية CSRF وتسجيل النشاط
 * ============================================================
 */

$pageTitle = 'الاعتماد الدفعي لقرارات الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'اعتماد دفعي' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

// التحقق من الصلاحيات - مدير النظام أو مدير المدرسة
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الاعتماد الدفعي.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();

// جلب معلمات التصفية
$filterClass       = $_GET['class_id'] ?? '';
$filterAcademicYear = $_GET['academic_year_id'] ?? '';
$filterSemester    = $_GET['semester_id'] ?? '';
$filterDecision    = $_GET['decision'] ?? '';
$filterStatus      = $_GET['status'] ?? '';

// جلب القوائم المنسدلة
// الأعوام الدراسية
$academicYears = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY id DESC")->fetchAll();

// الفصول الدراسية
$semesterQuery = "SELECT id, name_ar FROM semesters WHERE is_active = 1";
$semesterParams = [];
if ($filterAcademicYear) {
    $semesterQuery .= " AND academic_year_id = ?";
    $semesterParams[] = $filterAcademicYear;
}
$semesterQuery .= " ORDER BY id DESC";
$stmt = $db->prepare($semesterQuery);
$stmt->execute($semesterParams);
$semesters = $stmt->fetchAll();

// الفصول (الشعب)
$classQuery = "SELECT c.id, c.name_ar, gl.name_ar as grade_name
               FROM classes c
               JOIN grade_levels gl ON c.grade_level_id = gl.id
               WHERE c.is_active = 1";
$classParams = [];
if ($filterAcademicYear) {
    $classQuery .= " AND c.academic_year_id = ?";
    $classParams[] = $filterAcademicYear;
}
$schoolId = currentSchoolId();
if ($schoolId && $_SESSION['user']['role'] !== 'group_admin') {
    $classQuery .= " AND c.school_id = ?";
    $classParams[] = $schoolId;
}
$classQuery .= " ORDER BY gl.level_number, c.name_ar";
$stmt = $db->prepare($classQuery);
$stmt->execute($classParams);
$classes = $stmt->fetchAll();

// ═══════════════════════════════════════════
// معالجة الاعتماد الدفعي
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
    } else {
        $action = $_POST['batch_action'];
        $selectedIds = $_POST['decision_ids'] ?? [];
        $selectedIds = array_map('intval', $selectedIds);

        if (empty($selectedIds)) {
            setFlash('يرجى اختيار قرار واحد على الأقل.', 'warning');
        } else {
            try {
                $db->beginTransaction();

                if ($action === 'approve_selected') {
                    // اعتماد القرارات المحددة
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $params = array_merge($selectedIds, [$_SESSION['user']['id']]);

                    $stmt = $db->prepare("
                        UPDATE promotion_decisions
                        SET status = 'approved',
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id IN ($placeholders)
                        AND status = 'proposed'
                    ");
                    $params = array_merge([$_SESSION['user']['id']], $selectedIds);
                    $stmt->execute($params);
                    $count = $stmt->rowCount();

                    // تسجيل النشاط
                    logActivity($db, $_SESSION['user']['id'], 'batch_approve', "اعتماد دفعي لـ {$count} قرار ترفيع", 'promotion', null);

                    $db->commit();
                    setFlash("تم اعتماد {$count} قرار بنجاح. لن يتم إرسال إشعارات حتى اعتماد جميع القرارات.", 'success');

                } elseif ($action === 'reject_selected') {
                    // رفض القرارات المحددة
                    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
                    if (empty($rejectionReason)) {
                        $db->rollBack();
                        setFlash('يرجى إدخال سبب الرفض.', 'warning');
                    } else {
                        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                        $stmt = $db->prepare("
                            UPDATE promotion_decisions
                            SET status = 'rejected',
                                rejection_reason = ?,
                                approved_by = ?,
                                approved_at = NOW(),
                                updated_at = NOW()
                            WHERE id IN ($placeholders)
                            AND status IN ('proposed', 'approved')
                        ");
                        $params = array_merge([$rejectionReason, $_SESSION['user']['id']], $selectedIds);
                        $stmt->execute($params);
                        $count = $stmt->rowCount();

                        // الرفض يعيد فتح إمكانية تعديل البيانات المتأثرة
                        logActivity($db, $_SESSION['user']['id'], 'batch_reject', "رفض دفعي لـ {$count} قرار ترفيع. السبب: {$rejectionReason}", 'promotion', null);

                        $db->commit();
                        setFlash("تم رفض {$count} قرار. تم إعادة فتح إمكانية تعديل البيانات المتأثرة.", 'warning');
                    }

                } elseif ($action === 'approve_all_proposed') {
                    // اعتماد جميع القرارات المقترحة
                    $whereParts = ["pd.status = 'proposed'"];
                    $whereParams = [];

                    if ($filterClass) {
                        $whereParts[] = "pd.class_id = ?";
                        $whereParams[] = $filterClass;
                    }
                    if ($filterAcademicYear) {
                        $whereParts[] = "pd.academic_year_id = ?";
                        $whereParams[] = $filterAcademicYear;
                    }
                    if ($filterSemester) {
                        $whereParts[] = "pd.semester_id = ?";
                        $whereParams[] = $filterSemester;
                    }

                    $whereClause = implode(' AND ', $whereParts);
                    $stmt = $db->prepare("
                        UPDATE promotion_decisions pd
                        SET pd.status = 'approved',
                            pd.approved_by = ?,
                            pd.approved_at = NOW(),
                            pd.updated_at = NOW()
                        WHERE $whereClause
                    ");
                    $params = array_merge([$_SESSION['user']['id']], $whereParams);
                    $stmt->execute($params);
                    $count = $stmt->rowCount();

                    logActivity($db, $_SESSION['user']['id'], 'batch_approve_all', "اعتماد جماعي لـ {$count} قرار ترفيع", 'promotion', null);

                    $db->commit();
                    setFlash("تم اعتماد {$count} قرار جماعياً بنجاح.", 'success');
                }

            } catch (Exception $e) {
                $db->rollBack();
                error_log('EQAS Batch Approve Error: ' . $e->getMessage());
                setFlash('حدث خطأ أثناء العملية. يرجى المحاولة مرة أخرى.', 'danger');
            }
        }
        redirect('batch_approve.php' . ($filterClass ? '?class_id=' . $filterClass : '') .
                 ($filterAcademicYear ? '&academic_year_id=' . $filterAcademicYear : '') .
                 ($filterSemester ? '&semester_id=' . $filterSemester : ''));
    }
}

// ═══════════════════════════════════════════
// معالجة الاعتماد/الرفض الفردي
// ═══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['individual_action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $decisionId = (int)$_POST['decision_id'];
        $action = $_POST['individual_action'];
        $notes = trim($_POST['notes'] ?? '');

        try {
            if ($action === 'approve_individual') {
                $stmt = $db->prepare("
                    UPDATE promotion_decisions
                    SET status = 'approved',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejection_reason = NULL,
                        updated_at = NOW()
                    WHERE id = ? AND status = 'proposed'
                ");
                $stmt->execute([$_SESSION['user']['id'], $decisionId]);
                $count = $stmt->rowCount();

                logActivity($db, $_SESSION['user']['id'], 'approve_promotion', "اعتماد قرار ترفيع رقم {$decisionId}" . ($notes ? ". ملاحظات: {$notes}" : ''), 'promotion', $decisionId);
                setFlash($count ? 'تم اعتماد القرار بنجاح.' : 'لم يتم اعتماد القرار (قد يكون معتمداً مسبقاً).', $count ? 'success' : 'warning');

            } elseif ($action === 'reject_individual') {
                if (empty($notes)) {
                    setFlash('يرجى إدخال سبب الرفض.', 'warning');
                } else {
                    $stmt = $db->prepare("
                        UPDATE promotion_decisions
                        SET status = 'rejected',
                            rejection_reason = ?,
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ? AND status IN ('proposed', 'approved')
                    ");
                    $stmt->execute([$notes, $_SESSION['user']['id'], $decisionId]);
                    $count = $stmt->rowCount();

                    logActivity($db, $_SESSION['user']['id'], 'reject_promotion', "رفض قرار ترفيع رقم {$decisionId}. السبب: {$notes}", 'promotion', $decisionId);
                    setFlash($count ? 'تم رفض القرار. تم إعادة فتح إمكانية تعديل البيانات.' : 'لم يتم رفض القرار.', $count ? 'warning' : 'info');
                }
            }
        } catch (Exception $e) {
            error_log('EQAS Individual Approve Error: ' . $e->getMessage());
            setFlash('حدث خطأ أثناء العملية.', 'danger');
        }
        redirect('batch_approve.php' . ($filterClass ? '?class_id=' . $filterClass : '') .
                 ($filterAcademicYear ? '&academic_year_id=' . $filterAcademicYear : '') .
                 ($filterSemester ? '&semester_id=' . $filterSemester : ''));
    }
}

// ═══════════════════════════════════════════
// جلب القرارات مع التصفية
// ═══════════════════════════════════════════
$whereParts = ["pd.is_active = 1"];
$whereParams = [];

if ($filterClass) {
    $whereParts[] = "pd.class_id = ?";
    $whereParams[] = $filterClass;
}
if ($filterAcademicYear) {
    $whereParts[] = "pd.academic_year_id = ?";
    $whereParams[] = $filterAcademicYear;
}
if ($filterSemester) {
    $whereParts[] = "pd.semester_id = ?";
    $whereParams[] = $filterSemester;
}
if ($filterDecision) {
    $whereParts[] = "pd.decision = ?";
    $whereParams[] = $filterDecision;
}
if ($filterStatus) {
    $whereParts[] = "pd.status = ?";
    $whereParams[] = $filterStatus;
}

$schoolFilter = '';
if ($schoolId && $_SESSION['user']['role'] !== 'group_admin') {
    $whereParts[] = "pd.school_id = ?";
    $whereParams[] = $schoolId;
}

$whereClause = implode(' AND ', $whereParts);

$sql = "SELECT pd.*,
               s.full_name_ar as student_name,
               s.student_number,
               gl.name_ar as grade_name,
               c.name_ar as class_name,
               sc.name_ar as school_name,
               u.full_name_ar as approver_name
        FROM promotion_decisions pd
        JOIN students s ON pd.student_id = s.id
        JOIN grade_levels gl ON pd.grade_level_id = gl.id
        LEFT JOIN classes c ON pd.class_id = c.id
        LEFT JOIN schools sc ON pd.school_id = sc.id
        LEFT JOIN users u ON pd.approved_by = u.id
        WHERE $whereClause
        ORDER BY pd.status ASC, s.full_name_ar ASC";

$stmt = $db->prepare($sql);
$stmt->execute($whereParams);
$decisions = $stmt->fetchAll();

// ═══════════════════════════════════════════
// الإحصائيات
// ═══════════════════════════════════════════
$statsWhere = implode(' AND ', array_filter($whereParts, function($w) { return strpos($w, 'pd.status') === false; }));
$statsParams = array_values(array_filter($whereParams, function($w, $k) use ($whereParts) {
    return strpos($whereParts[$k], 'pd.status') === false;
}, ARRAY_FILTER_USE_BOTH));

$stmtProposed = $db->prepare("SELECT COUNT(*) FROM promotion_decisions pd WHERE pd.status = 'proposed' AND " . ($statsWhere ?: '1=1'));
$stmtProposed->execute($statsParams);
$proposedCount = $stmtProposed->fetchColumn();

$stmtApproved = $db->prepare("SELECT COUNT(*) FROM promotion_decisions pd WHERE pd.status = 'approved' AND " . ($statsWhere ?: '1=1'));
$stmtApproved->execute($statsParams);
$approvedCount = $stmtApproved->fetchColumn();

$stmtRejected = $db->prepare("SELECT COUNT(*) FROM promotion_decisions pd WHERE pd.status = 'rejected' AND " . ($statsWhere ?: '1=1'));
$stmtRejected->execute($statsParams);
$rejectedCount = $stmtRejected->fetchColumn();
?>

<div class="section">
    <!-- ═══════════════════════════════════════════
         بطاقة الإحصائيات
         ═══════════════════════════════════════════ -->
    <div class="grid grid-4 mb-6">
        <div class="card" style="border-right:4px solid var(--info);">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:32px;color:var(--info);font-weight:bold;"><?= $proposedCount ?></div>
                <div style="color:var(--text-secondary);font-size:14px;">مقترح</div>
            </div>
        </div>
        <div class="card" style="border-right:4px solid var(--success);">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:32px;color:var(--success);font-weight:bold;"><?= $approvedCount ?></div>
                <div style="color:var(--text-secondary);font-size:14px;">معتمد</div>
            </div>
        </div>
        <div class="card" style="border-right:4px solid var(--danger);">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:32px;color:var(--danger);font-weight:bold;"><?= $rejectedCount ?></div>
                <div style="color:var(--text-secondary);font-size:14px;">مرفوض</div>
            </div>
        </div>
        <div class="card" style="border-right:4px solid var(--primary);">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:32px;color:var(--primary);font-weight:bold;"><?= $proposedCount + $approvedCount + $rejectedCount ?></div>
                <div style="color:var(--text-secondary);font-size:14px;">الإجمالي</div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         بطاقة التصفية
         ═══════════════════════════════════════════ -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🔍 تصفية قرارات الترفيع</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row">
                <div class="form-group">
                    <label class="form-label">العام الدراسي</label>
                    <select name="academic_year_id" class="form-control" id="filter-year">
                        <option value="">الكل</option>
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filterAcademicYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل الدراسي</label>
                    <select name="semester_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= $sem['id'] ?>" <?= $filterSemester == $sem['id'] ? 'selected' : '' ?>><?= e($sem['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل (الشعبة)</label>
                    <select name="class_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= $filterClass == $cl['id'] ? 'selected' : '' ?>><?= e($cl['grade_name'] . ' - ' . $cl['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">نوع القرار</label>
                    <select name="decision" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($decisionLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filterDecision === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">حالة الاعتماد</label>
                    <select name="status" class="form-control">
                        <option value="">الكل</option>
                        <option value="proposed" <?= $filterStatus === 'proposed' ? 'selected' : '' ?>>مقترح</option>
                        <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>معتمد</option>
                        <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 تصفية</button>
                    <a href="batch_approve.php" class="btn btn-light" style="margin-right:8px;">↻ إعادة تعيين</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         ملاحظة مهمة حول الإشعارات
         ═══════════════════════════════════════════ -->
    <div class="mb-4 p-3" style="background:rgba(23,162,184,0.1);border-radius:var(--radius-md);border-right:4px solid var(--info);">
        <p style="margin:0;"><strong>ℹ️ ملاحظة:</strong> لا يتم إرسال إشعارات أو إقرارات لأولياء الأمور حتى تكون حالة القرار <strong>"معتمد"</strong>. رفض القرار يعيد فتح إمكانية تعديل البيانات المتأثرة.</p>
    </div>

    <!-- ═══════════════════════════════════════════
         نموذج الاعتماد الدفعي
         ═══════════════════════════════════════════ -->
    <form method="POST" id="batch-form">
        <?= csrfField() ?>
        <input type="hidden" name="batch_action" id="batch-action-input" value="">

        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" id="select-all" onclick="toggleAll(this)">
                        <span class="form-check-label" style="font-weight:bold;">تحديد الكل</span>
                    </label>
                    <span id="selected-count" style="color:var(--text-secondary);font-size:13px;">لم يتم تحديد أي قرار</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-success btn-sm" onclick="submitBatch('approve_selected')">
                        ✅ اعتماد المحدد
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="showRejectModal()">
                        ❌ رفض المحدد
                    </button>
                    <button type="button" class="btn btn-warning btn-sm" onclick="submitBatch('approve_all_proposed')">
                        ✅ اعتماد الكل المقترح
                    </button>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($decisions)): ?>
                <div class="table-empty">
                    <div class="table-empty-icon">📋</div>
                    <div class="table-empty-text">لا توجد قرارات ترفيع مطابقة للتصفية</div>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">✓</th>
                                <th>الطالب</th>
                                <th>الصف</th>
                                <th>الأكاديمي</th>
                                <th>السلوك</th>
                                <th>الحضور</th>
                                <th>القرار</th>
                                <th>حالة الاعتماد</th>
                                <th>المعتمد بواسطة</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decisions as $d):
                                $dc = $decisionColors[$d['decision']] ?? $decisionColors['under_review'];
                                $statusColors = [
                                    'proposed' => ['bg' => '#e3f2fd', 'text' => '#1565c0', 'label' => 'مقترح'],
                                    'approved' => ['bg' => '#e8f5e9', 'text' => '#2e7d32', 'label' => 'معتمد'],
                                    'rejected' => ['bg' => '#ffebee', 'text' => '#c62828', 'label' => 'مرفوض']
                                ];
                                $sc = $statusColors[$d['status']] ?? $statusColors['proposed'];
                            ?>
                            <tr style="<?= $d['status'] === 'rejected' ? 'opacity:0.7;' : '' ?>">
                                <td>
                                    <?php if ($d['status'] === 'proposed'): ?>
                                    <input type="checkbox" name="decision_ids[]" value="<?= $d['id'] ?>" class="form-check-input dec-checkbox">
                                    <?php else: ?>
                                    <input type="checkbox" disabled style="opacity:0.3;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($d['student_name']) ?></strong><br>
                                    <span style="font-size:11px;color:var(--text-muted);"><?= e($d['student_number'] ?? '') ?></span>
                                </td>
                                <td><?= e($d['grade_name'] ?? '-') ?><?php if ($d['class_name']): ?> / <?= e($d['class_name']) ?><?php endif; ?></td>
                                <td><?= $d['academic_score'] !== null ? number_format($d['academic_score'], 1) . '%' : '-' ?></td>
                                <td><?= $d['behavior_score'] !== null ? number_format($d['behavior_score'], 1) . '%' : '-' ?></td>
                                <td><?= $d['attendance_score'] !== null ? number_format($d['attendance_score'], 1) . '%' : '-' ?></td>
                                <td>
                                    <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:<?= $dc['bg'] ?>;color:<?= $dc['text'] ?>;">
                                        <?= $decisionLabels[$d['decision']] ?? e($d['decision']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;">
                                        <?= $sc['label'] ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;">
                                    <?php if ($d['approver_name']): ?>
                                        <?= e($d['approver_name']) ?><br>
                                        <span style="color:var(--text-muted);"><?= $d['approved_at'] ? formatDateAr($d['approved_at'], 'short') : '' ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($d['status'] === 'proposed'): ?>
                                    <button type="button" class="btn btn-success" style="padding:2px 8px;font-size:11px;"
                                            onclick="individualAction(<?= $d['id'] ?>, 'approve_individual')">✅ اعتمد</button>
                                    <button type="button" class="btn btn-danger" style="padding:2px 8px;font-size:11px;"
                                            onclick="showIndividualReject(<?= $d['id'] ?>, '<?= e($d['student_name']) ?>')">❌ ارفض</button>
                                    <?php elseif ($d['status'] === 'rejected'): ?>
                                    <span style="font-size:11px;color:var(--danger);" title="<?= e($d['rejection_reason'] ?? '') ?>">
                                        💬 <?= truncate($d['rejection_reason'] ?? '', 30) ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="font-size:11px;color:var(--success);">✓ معتمد</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════
     نافذة رفض دفعي
     ═══════════════════════════════════════════ -->
<div id="reject-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;justify-content:center;align-items:center;">
    <div class="card" style="width:500px;max-width:90%;margin:auto;margin-top:15vh;">
        <div class="card-header" style="background:var(--danger);color:white;">
            <h3 class="card-title" style="color:white;">❌ رفض القرارات المحددة</h3>
        </div>
        <div class="card-body">
            <div class="alert alert-danger">
                <strong>⚠️ تحذير:</strong> رفض القرارات سيعيد فتح إمكانية تعديل البيانات المتأثرة.
            </div>
            <div class="form-group">
                <label class="form-label">سبب الرفض <span class="required">*</span></label>
                <textarea name="rejection_reason" id="batch-rejection-reason" class="form-control" rows="3"
                          placeholder="أدخل سبب الرفض (إلزامي)..." required></textarea>
            </div>
        </div>
        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-start;">
            <button type="button" class="btn btn-danger" onclick="submitBatch('reject_selected')">❌ تأكيد الرفض</button>
            <button type="button" class="btn btn-light" onclick="closeRejectModal()">إلغاء</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     نافذة رفض فردي
     ═══════════════════════════════════════════ -->
<div id="individual-reject-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;justify-content:center;align-items:center;">
    <div class="card" style="width:500px;max-width:90%;margin:auto;margin-top:15vh;">
        <div class="card-header" style="background:var(--danger);color:white;">
            <h3 class="card-title" style="color:white;">❌ رفض قرار ترفيع</h3>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="individual_action" value="reject_individual">
            <input type="hidden" name="decision_id" id="individual-decision-id" value="">
            <div class="card-body">
                <p>رفض قرار الطالب: <strong id="individual-student-name"></strong></p>
                <div class="form-group">
                    <label class="form-label">سبب الرفض <span class="required">*</span></label>
                    <textarea name="notes" class="form-control" rows="3"
                              placeholder="أدخل سبب الرفض (إلزامي)..." required></textarea>
                </div>
            </div>
            <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-start;">
                <button type="submit" class="btn btn-danger">❌ تأكيد الرفض</button>
                <button type="button" class="btn btn-light" onclick="closeIndividualReject()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     نموذج اعتماد فردي مخفي
     ═══════════════════════════════════════════ -->
<form method="POST" id="individual-approve-form" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="individual_action" value="approve_individual">
    <input type="hidden" name="decision_id" id="approve-decision-id" value="">
    <input type="hidden" name="notes" id="approve-notes" value="">
</form>

<?php
$pageJs = "
// تحديد/إلغاء تحديد الكل
function toggleAll(el) {
    document.querySelectorAll('.dec-checkbox').forEach(cb => cb.checked = el.checked);
    updateSelectedCount();
}

// تحديث عداد المحدد
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('dec-checkbox')) {
        updateSelectedCount();
    }
});

function updateSelectedCount() {
    var count = document.querySelectorAll('.dec-checkbox:checked').length;
    document.getElementById('selected-count').textContent = count > 0 ? count + ' قرار محدد' : 'لم يتم تحديد أي قرار';
}

// إرسال دفعي
function submitBatch(action) {
    if (action === 'approve_all_proposed') {
        if (!confirm('هل تريد اعتماد جميع القرارات المقترحة؟')) return;
    } else {
        var count = document.querySelectorAll('.dec-checkbox:checked').length;
        if (count === 0) {
            alert('يرجى اختيار قرار واحد على الأقل.');
            return;
        }
        if (action === 'approve_selected') {
            if (!confirm('اعتماد ' + count + ' قرار؟')) return;
        }
        if (action === 'reject_selected') {
            var reason = document.getElementById('batch-rejection-reason').value.trim();
            if (!reason) {
                alert('يرجى إدخال سبب الرفض.');
                return;
            }
        }
    }
    document.getElementById('batch-action-input').value = action;
    document.getElementById('batch-form').submit();
}

// نافذة الرفض الدفعي
function showRejectModal() {
    var count = document.querySelectorAll('.dec-checkbox:checked').length;
    if (count === 0) {
        alert('يرجى اختيار قرار واحد على الأقل.');
        return;
    }
    document.getElementById('reject-modal').style.display = 'block';
}
function closeRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}

// اعتماد فردي
function individualAction(id, action) {
    if (action === 'approve_individual') {
        if (!confirm('هل تريد اعتماد هذا القرار؟')) return;
        document.getElementById('approve-decision-id').value = id;
        document.getElementById('individual-approve-form').submit();
    }
}

// رفض فردي
function showIndividualReject(id, name) {
    document.getElementById('individual-decision-id').value = id;
    document.getElementById('individual-student-name').textContent = name;
    document.getElementById('individual-reject-modal').style.display = 'block';
}
function closeIndividualReject() {
    document.getElementById('individual-reject-modal').style.display = 'none';
}
";
require_once __DIR__ . '/../../includes/footer.php';
