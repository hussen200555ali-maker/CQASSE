<?php
/**
 * ============================================================
 * تجاوز معيار السلوك
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * عرض الطلاب الذين لم يستوفوا معيار السلوك (60%)
 * وإنشاء تجاوز معتمد من مدير النظام
 */

$pageTitle = 'تجاوز معيار السلوك';
$breadcrumb = ['السلوك' => 'index.php', 'تجاوز معيار السلوك' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المدرسة ومدير النظام
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة. فقط مدير المدرسة أو مدير النظام.', 'danger');
    redirect('index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);

// جلب الفصل الدراسي الحالي
$semesterId = null;
try {
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $sem = $stmt->fetch();
    $semesterId = $sem ? $sem['id'] : null;
} catch (Exception $e) {}

// تحميل محسب السلوك
require_once __DIR__ . '/../../includes/behavior_calculator.php';
$behaviorCalc = new BehaviorCalculator($db);

// ============================================================
// معالجة إنشاء التجاوز
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_override'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $overrideStudent = (int)($_POST['student_id'] ?? 0);
        $overrideReason  = trim($_POST['override_reason'] ?? '');
        $overrideCriterion = $_POST['criterion'] ?? 'behavior';

        if (empty($overrideStudent) || empty($overrideReason)) {
            setFlash('يرجى ملء جميع الحقول المطلوبة (الطالب وسبب التجاوز)', 'warning');
        } elseif (!in_array($overrideCriterion, ['behavior', 'academic', 'attendance'])) {
            setFlash('معيار التجاوز غير صالح', 'danger');
        } else {
            try {
                // التحقق من ملكية المدرسة قبل إنشاء التجاوز
                $stmt = $db->prepare("SELECT s.id, s.school_id FROM students s WHERE s.id = ?");
                $stmt->execute([$overrideStudent]);
                $studentCheck = $stmt->fetch();
                if (!$studentCheck) {
                    setFlash('الطالب غير موجود', 'danger');
                } elseif ($schoolId && $userRole !== 'group_admin' && $studentCheck['school_id'] != $schoolId) {
                    setFlash('ليس لديك صلاحية لإنشاء تجاوز لهذا الطالب', 'danger');
                } else {
                    $result = $behaviorCalc->createPromotionOverride(
                        $overrideStudent,
                        $academicYearId,
                        $semesterId,
                        $overrideCriterion,
                        $overrideReason,
                        $userId
                    );

                    if ($result['success']) {
                        logActivity($db, $userId, 'behavior_override',
                            "إنشاء تجاوز سلوك للطالب رقم {$overrideStudent}: {$overrideReason}",
                            'promotion_overrides', $result['override_id'] ?? null);
                        setFlash($result['message'], 'success');
                    } else {
                        setFlash($result['message'], 'danger');
                    }
                }
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء إنشاء التجاوز: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// ============================================================
// جلب الطلاب تحت معيار السلوك (60%)
// ============================================================
$failingStudents = [];
if ($academicYearId && $semesterId) {
    try {
        $sql = "SELECT bs.student_id, bs.current_percentage, bs.is_passing,
                       e.starting_points, e.earned_points, e.deducted_points,
                       s.full_name_ar AS student_name, s.student_number,
                       c.name_ar AS class_name, c.id AS class_id,
                       po.id AS override_id, po.override_reason, po.approved_at,
                       u.full_name_ar AS approver_name
                FROM evaluations e
                INNER JOIN students s ON s.id = e.student_id
                INNER JOIN classes c ON c.id = e.class_id
                LEFT JOIN promotion_overrides po ON po.student_id = e.student_id 
                    AND po.academic_year_id = e.academic_year_id 
                    AND po.semester_id = e.semester_id 
                    AND po.criterion = 'behavior' AND po.is_active = 1
                LEFT JOIN users u ON u.id = po.approved_by
                WHERE e.eval_type = 'behavior_score' AND e.is_passing = 0 AND e.is_active = 1 AND s.is_active = 1
                    AND e.academic_year_id = ? AND e.semester_id = ?";
        $params = [$academicYearId, $semesterId];

        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }

        $sql .= " ORDER BY e.current_percentage ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $failingStudents = $stmt->fetchAll();
    } catch (Exception $e) {
        $failingStudents = [];
    }
}

// جلب التجاوزات الموجودة
$existingOverrides = [];
try {
    $sql = "SELECT po.*, s.full_name_ar AS student_name, s.student_number,
                   u.full_name_ar AS approver_name
            FROM promotion_overrides po
            INNER JOIN students s ON s.id = po.student_id
            LEFT JOIN users u ON u.id = po.approved_by
            WHERE po.criterion = 'behavior' AND po.is_active = 1
                AND po.academic_year_id = ? AND po.semester_id = ?";
    $params = [$academicYearId, $semesterId];

    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY po.approved_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $existingOverrides = $stmt->fetchAll();
} catch (Exception $e) {
    $existingOverrides = [];
}
?>

<!-- تنبيه صلاحية -->
<?php if ($userRole !== 'group_admin'): ?>
<div class="section">
    <div style="background:#fff3cd; padding:10px 14px; border-radius:var(--radius-md); font-size:13px; color:#856404;">
        ⚠️ تنبيه: فقط مدير النظام (group_admin) يمكنه اعتماد التجاوز. يمكنك تقديم طلب التجاوز لكنه سيحتاج موافقة مدير النظام.
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- قائمة الطلاب تحت المعيار -->
    <div class="lg:col-span-2 section">
        <div class="card">
            <div class="card-header">
                <div class="flex items-center justify-between">
                    <h3 class="card-title">⚠️ الطلاب تحت معيار السلوك (أقل من 60%)</h3>
                    <span class="badge badge-danger"><?= count($failingStudents) ?> طالب</span>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($failingStudents)): ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">
                    <div style="font-size:48px; margin-bottom:12px;">✅</div>
                    <div style="font-size:16px; font-weight:600;">جميع الطلاب مستوون لمعيار السلوك</div>
                    <p>لا يوجد طلاب بنسبة سلوكية أقل من 60%</p>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الطالب</th>
                                <th>الفصل</th>
                                <th>النسبة</th>
                                <th>حالة التجاوز</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failingStudents as $i => $fs):
                                $pct = (float)$fs['current_percentage'];
                                $hasOverride = !empty($fs['override_id']);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div style="font-weight:600;"><?= e($fs['student_name']) ?></div>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= e($fs['student_number']) ?></div>
                                </td>
                                <td><?= e($fs['class_name']) ?></td>
                                <td>
                                    <span style="font-weight:700; color:var(--danger); font-size:16px;">
                                        <?= number_format($pct, 1) ?>%
                                    </span>
                                    <div style="font-size:11px; color:var(--text-muted);">
                                        نقاط: <?= $fs['starting_points'] + $fs['earned_points'] - $fs['deducted_points'] ?>/<?= $fs['starting_points'] ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($hasOverride): ?>
                                    <span class="badge badge-success">✅ تم التجاوز</span>
                                    <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                                        بواسطة: <?= e($fs['approver_name'] ?? 'غير معروف') ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="badge badge-danger">❌ بدون تجاوز</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$hasOverride): ?>
                                    <button type="button" class="btn btn-sm btn-warning"
                                            onclick="openOverrideModal(<?= $fs['student_id'] ?>, <?= json_encode($fs['student_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode(number_format($pct, 1) . '%', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
                                        🔄 تجاوز
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-info"
                                            onclick="viewOverrideReason(<?= json_encode($fs['override_reason'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)">
                                        👁️ عرض السبب
                                    </button>
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
    </div>

    <!-- التجاوزات المعتمدة -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 التجاوزات المعتمدة</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($existingOverrides)): ?>
                <div style="text-align:center; padding:30px; color:var(--text-muted);">
                    <div style="font-size:32px; margin-bottom:8px;">📋</div>
                    لا توجد تجاوزات معتمدة
                </div>
                <?php else: ?>
                <div style="max-height:400px; overflow-y:auto;">
                    <?php foreach ($existingOverrides as $ov): ?>
                    <div style="padding:10px 16px; border-bottom:1px solid var(--border-color);">
                        <div style="font-weight:600;"><?= e($ov['student_name']) ?></div>
                        <div style="font-size:12px; color:var(--text-muted);"><?= e($ov['student_number']) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">
                            <?= e(truncate($ov['override_reason'], 80)) ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                            المعتمد: <?= e($ov['approver_name'] ?? 'غير معروف') ?> • 
                            <?= formatDateAr($ov['approved_at'], 'short') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- نافذة التجاوز -->
<div id="override-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:var(--radius-lg); padding:24px; max-width:500px; width:90%; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">🔄 تجاوز معيار السلوك</h3>
            <button type="button" onclick="closeOverrideModal()" style="background:none; border:none; font-size:20px; cursor:pointer;">✕</button>
        </div>

        <div style="background:var(--bg-secondary); padding:12px; border-radius:var(--radius-md); margin-bottom:16px;">
            <div style="font-weight:600;" id="modal-student-name"></div>
            <div style="font-size:13px; color:var(--text-muted);">النسبة السلوكية: <span id="modal-student-pct" style="color:var(--danger); font-weight:700;"></span></div>
        </div>

        <?php if ($userRole !== 'group_admin'): ?>
        <div style="background:#fff3cd; padding:10px; border-radius:var(--radius-md); margin-bottom:16px; font-size:13px; color:#856404;">
            ⚠️ هذا الطلب سيحتاج موافقة مدير النظام (group_admin) للاعتماد النهائي.
        </div>
        <?php endif; ?>

        <form method="POST" id="override-form">
            <?= csrfField() ?>
            <input type="hidden" name="student_id" id="modal-student-id">
            <input type="hidden" name="criterion" value="behavior">

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">سبب التجاوز: <span style="color:var(--danger);">*</span></label>
                <textarea name="override_reason" class="form-control" rows="4" required
                          placeholder="أدخل سبباً تفصيلياً للتجاوز..."></textarea>
            </div>

            <div style="display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" class="btn btn-light" onclick="closeOverrideModal()">إلغاء</button>
                <button type="submit" name="create_override" class="btn btn-warning">
                    🔄 تقديم التجاوز
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     جافاسكربت
     ============================================================ -->
<?php
$pageJs = <<<'JS'
function openOverrideModal(studentId, studentName, pct) {
    document.getElementById('modal-student-id').value = studentId;
    document.getElementById('modal-student-name').textContent = studentName;
    document.getElementById('modal-student-pct').textContent = pct;
    const modal = document.getElementById('override-modal');
    modal.style.display = 'flex';
}

function closeOverrideModal() {
    document.getElementById('override-modal').style.display = 'none';
}

function viewOverrideReason(reason) {
    alert('سبب التجاوز:\n' + reason);
}

// إغلاق عند النقر خارج النافذة
document.getElementById('override-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeOverrideModal();
});
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
