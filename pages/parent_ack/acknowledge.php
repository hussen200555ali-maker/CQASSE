<?php
/**
 * ============================================================
 * EQAS - إقرار أولياء الأمور - نموذج الإقرار
 * نظام التقييم الشامل - صفحة إقرار ولي الأمر
 * ============================================================
 */

$pageTitle = 'نموذج الإقرار';
$breadcrumb = ['إقرار أولياء الأمور' => 'index.php', 'الإقرار' => null];

require_once __DIR__ . '/../../includes/header.php';

$ackId = $_GET['id'] ?? 0;

// جلب بيانات الإقرار
$stmt = $db->prepare("SELECT pa.*, s.full_name_ar as student_name, s.student_number, s.school_id,
                      p.full_name_ar as parent_name, p.phone as parent_phone
                      FROM parent_acknowledgments pa
                      JOIN students s ON s.id = pa.student_id
                      JOIN users p ON p.id = pa.parent_id
                      WHERE pa.id = ? AND pa.is_active = 1");
$stmt->execute([$ackId]);
$acknowledgment = $stmt->fetch();

if (!$acknowledgment) {
    setFlash('الإقرار غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من الصلاحيات
$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id'];

if ($userRole === 'parent') {
    if ($userId != $acknowledgment['parent_id']) {
        setFlash('ليس لديك صلاحية للوصول إلى هذا الإقرار', 'danger');
        redirect('index.php');
    }
}

// أنواع الإقرارات
$ackTypeLabels = [
    'grades' => 'الدرجات',
    'promotion' => 'قرار الترفيع',
    'behavior' => 'التقييم السلوكي',
    'attendance' => 'سجل الحضور',
];

// جلب البيانات المرتبطة حسب نوع الإقرار
$relatedData = null;
switch ($acknowledgment['acknowledgment_type']) {
    case 'grades':
        if ($acknowledgment['entity_id']) {
            $stmt = $db->prepare("SELECT g.*, sub.name_ar as subject_name
                                  FROM grades g
                                  JOIN subjects sub ON sub.id = g.subject_id
                                  WHERE g.student_id = ? AND g.semester_id = ? AND g.is_active = 1
                                  ORDER BY sub.name_ar");
            $stmt->execute([$acknowledgment['student_id'], $acknowledgment['entity_id']]);
            $grades = $stmt->fetchAll();

            $total = 0;
            $count = 0;
            foreach ($grades as $g) {
                $total += $g['grade_value'];
                $count++;
            }
            $relatedData = [
                'type' => 'grades',
                'grades' => $grades,
                'average' => $count > 0 ? round($total / $count, 2) : 0,
                'total' => $total,
            ];
        }
        break;

    case 'behavior':
        $stmt = $db->prepare("SELECT be.*, u.full_name_ar as evaluator_name
                              FROM behavior_scores be
                              LEFT JOIN users u ON u.id = be.evaluator_id
                              WHERE be.student_id = ? AND be.is_active = 1
                              ORDER BY be.evaluation_date DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $behavior = $stmt->fetch();
        $relatedData = ['type' => 'behavior', 'evaluation' => $behavior];
        break;

    case 'attendance':
        $stmt = $db->prepare("SELECT * FROM attendance_summaries
                              WHERE student_id = ? AND is_active = 1
                              ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $attendance = $stmt->fetch();
        $relatedData = ['type' => 'attendance', 'summary' => $attendance];
        break;

    case 'promotion':
        $stmt = $db->prepare("SELECT * FROM promotion_decisions
                              WHERE student_id = ? AND is_active = 1
                              ORDER BY decided_at DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $promotion = $stmt->fetch();
        $relatedData = ['type' => 'promotion', 'decision' => $promotion];
        break;
}

// معالجة الإقرار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('acknowledge.php?id=' . $ackId);
    }

    // فقط ولي الأمر يمكنه الإقرار
    if ($userRole !== 'parent') {
        setFlash('فقط ولي الأمر يمكنه تقديم الإقرار', 'danger');
        redirect('acknowledge.php?id=' . $ackId);
    }

    if ($acknowledgment['is_acknowledged']) {
        setFlash('تم الإقرار مسبقاً', 'info');
        redirect('index.php');
    }

    $notes = trim($_POST['notes'] ?? '');

    $stmt = $db->prepare("UPDATE parent_acknowledgments SET is_acknowledged = 1, acknowledged_at = NOW(), notes = ? WHERE id = ?");
    $stmt->execute([$notes, $ackId]);

    logActivity($db, $userId, 'parent_acknowledgment', 'إقرار ولي الأمر: ' . $acknowledgment['acknowledgment_type'] . ' للطالب ' . $acknowledgment['student_name'], 'parent_acknowledgment', $ackId);

    setFlash('تم تسجيل إقرارك بنجاح. شكراً لتعاونكم.', 'success');
    redirect('index.php');
}

$decisionLabels = [
    'promoted' => ['ناجح - مرفّع', 'success'],
    'repeated' => ['راسب - معيد', 'danger'],
    'conditional' => ['ترفيع مشروط', 'warning'],
    'under_review' => ['قيد المراجعة', 'info'],
];
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">📄 نموذج الإقرار - <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? 'عام'); ?></h2>
    </div>
    <div class="card-body">
        <!-- معلومات الطالب -->
        <div class="card bg-light mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>👨‍🎓 الطالب:</strong> <?php echo e($acknowledgment['student_name']); ?></p>
                        <p><strong>🔢 رقم القيد:</strong> <?php echo e($acknowledgment['student_number']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>👤 ولي الأمر:</strong> <?php echo e($acknowledgment['parent_name']); ?></p>
                        <p><strong>📋 نوع الإقرار:</strong> <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? 'عام'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- البيانات المرتبطة -->
        <?php if ($relatedData): ?>
        <div class="card border mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0 text-white">📊 البيانات المتعلقة</h4>
            </div>
            <div class="card-body">
                <?php if ($relatedData['type'] === 'grades' && !empty($relatedData['grades'])): ?>
                <table class="table table-bordered table-sm">
                    <thead class="table-primary">
                        <tr>
                            <th>المادة</th>
                            <th>الدرجة</th>
                            <th>من</th>
                            <th>النوع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($relatedData['grades'] as $g): ?>
                        <tr>
                            <td><?php echo e($g['subject_name']); ?></td>
                            <td class="<?php echo $g['grade_value'] < 50 ? 'text-danger font-weight-bold' : ''; ?>">
                                <?php echo $g['grade_value']; ?>
                            </td>
                            <td><?php echo $g['max_value']; ?></td>
                            <td><?php echo e($g['grade_type']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <td><strong>المعدل العام</strong></td>
                            <td colspan="3"><strong class="<?php echo $relatedData['average'] >= 50 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $relatedData['average']; ?>%
                            </strong></td>
                        </tr>
                    </tfoot>
                </table>

                <?php elseif ($relatedData['type'] === 'behavior' && $relatedData['evaluation']): ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-card-label">الدرجة الكلية</div>
                            <div class="stat-card-value <?php echo $relatedData['evaluation']['overall_score'] >= 60 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $relatedData['evaluation']['overall_score']; ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <p><strong>تاريخ التقييم:</strong> <?php echo formatDateAr($relatedData['evaluation']['evaluation_date']); ?></p>
                        <?php if ($relatedData['evaluation']['notes']): ?>
                        <p><strong>ملاحظات:</strong> <?php echo nl2br(e($relatedData['evaluation']['notes'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($relatedData['type'] === 'attendance' && $relatedData['summary']): ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">إجمالي الأيام</div>
                            <div class="stat-card-value"><?php echo $relatedData['summary']['total_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">أيام الحضور</div>
                            <div class="stat-card-value text-success"><?php echo $relatedData['summary']['present_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">أيام الغياب</div>
                            <div class="stat-card-value text-danger"><?php echo $relatedData['summary']['absent_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">نسبة الحضور</div>
                            <div class="stat-card-value"><?php echo $relatedData['summary']['attendance_rate']; ?>%</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($relatedData['type'] === 'promotion' && $relatedData['decision']): ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">القرار</div>
                            <div class="stat-card-value" style="font-size:16px;">
                                <?php
                                $dec = $relatedData['decision']['decision'];
                                $decInfo = $decisionLabels[$dec] ?? [$dec, 'secondary'];
                                ?>
                                <span class="badge badge-<?php echo $decInfo[1]; ?>" style="font-size:14px;"><?php echo $decInfo[0]; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">الدرجة الأكاديمية</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['academic_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">درجة السلوك</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['behavior_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">نسبة الحضور</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['attendance_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                </div>
                <?php if ($relatedData['decision']['notes']): ?>
                <div class="alert alert-info mt-3">
                    <strong>ملاحظات:</strong> <?php echo e($relatedData['decision']['notes']); ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted">لا توجد بيانات متاحة حالياً</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- نموذج الإقرار -->
        <?php if ($acknowledgment['is_acknowledged']): ?>
        <div class="alert alert-success">
            <strong>✅ تم الإقرار</strong> في <?php echo formatDateAr($acknowledgment['acknowledged_at'], 'full'); ?>
            <?php if ($acknowledgment['notes']): ?>
            <br><strong>ملاحظات:</strong> <?php echo e($acknowledgment['notes']); ?>
            <?php endif; ?>
        </div>
        <?php elseif ($userRole === 'parent'): ?>
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0 text-white">✅ نموذج الإقرار</h4>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    أقر بأنني قد اطلعت على بيانات <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? ''); ?>
                    الخاصة بابني/ابنتي <strong><?php echo e($acknowledgment['student_name']); ?></strong>
                    وأتحمل مسؤولية العلم بها.
                </p>

                <form method="POST">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="أي ملاحظات تود إضافتها..."></textarea>
                    </div>

                    <button type="submit" name="acknowledge" value="1" class="btn btn-primary btn-lg">
                        ✅ إقرار بالاطلاع والموافقة
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            بانتظار إقرار ولي الأمر <strong><?php echo e($acknowledgment['parent_name']); ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
