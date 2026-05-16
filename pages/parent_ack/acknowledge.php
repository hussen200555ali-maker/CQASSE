<?php
/**
 * ============================================================
 * EQAS - ط¥ظ‚ط±ط§ط± ط£ظˆظ„ظٹط§ط، ط§ظ„ط£ظ…ظˆط± - ظ†ظ…ظˆط°ط¬ ط§ظ„ط¥ظ‚ط±ط§ط±
 * ظ†ط¸ط§ظ… ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط´ط§ظ…ظ„ - طµظپط­ط© ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط±
 * ============================================================
 */

$pageTitle = 'ظ†ظ…ظˆط°ط¬ ط§ظ„ط¥ظ‚ط±ط§ط±';
$breadcrumb = ['ط¥ظ‚ط±ط§ط± ط£ظˆظ„ظٹط§ط، ط§ظ„ط£ظ…ظˆط±' => 'index.php', 'ط§ظ„ط¥ظ‚ط±ط§ط±' => null];

require_once __DIR__ . '/../../includes/header.php';

$ackId = $_GET['id'] ?? 0;

// ط¬ظ„ط¨ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¥ظ‚ط±ط§ط±
$stmt = $db->prepare("SELECT pa.*, s.full_name_ar as student_name, s.student_number, s.school_id,
                      p.full_name_ar as parent_name, p.phone as parent_phone
                      FROM parent_acknowledgments pa
                      JOIN students s ON s.id = pa.student_id
                      JOIN users p ON p.id = pa.parent_id
                      WHERE pa.id = ? AND pa.is_active = 1");
$stmt->execute([$ackId]);
$acknowledgment = $stmt->fetch();

if (!$acknowledgment) {
    setFlash('ط§ظ„ط¥ظ‚ط±ط§ط± ط؛ظٹط± ظ…ظˆط¬ظˆط¯', 'danger');
    redirect('index.php');
}

// ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„طµظ„ط§ط­ظٹط§طھ
$userRole = $_SESSION['user']['role'] ?? '';
$userId = $_SESSION['user']['id'];

if ($userRole === 'parent') {
    if ($userId != $acknowledgment['parent_id']) {
        setFlash('ظ„ظٹط³ ظ„ط¯ظٹظƒ طµظ„ط§ط­ظٹط© ظ„ظ„ظˆطµظˆظ„ ط¥ظ„ظ‰ ظ‡ط°ط§ ط§ظ„ط¥ظ‚ط±ط§ط±', 'danger');
        redirect('index.php');
    }
}

// ط£ظ†ظˆط§ط¹ ط§ظ„ط¥ظ‚ط±ط§ط±ط§طھ
$ackTypeLabels = [
    'grades' => 'ط§ظ„ط¯ط±ط¬ط§طھ',
    'promotion' => 'ظ‚ط±ط§ط± ط§ظ„طھط±ظپظٹط¹',
    'behavior' => 'ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒظٹ',
    'attendance' => 'ط³ط¬ظ„ ط§ظ„ط­ط¶ظˆط±',
];

// ط¬ظ„ط¨ ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ…ط±طھط¨ط·ط© ط­ط³ط¨ ظ†ظˆط¹ ط§ظ„ط¥ظ‚ط±ط§ط±
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
        $stmt = $db->prepare("SELECT e.*, u.full_name_ar as evaluator_name
                              FROM evaluations e
                              LEFT JOIN users u ON u.id = e.student_id
                              WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.is_active = 1
                              ORDER BY e.id DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $behavior = $stmt->fetch();
        $relatedData = ['type' => 'behavior', 'evaluation' => $behavior];
        break;

    case 'attendance':
        $stmt = $db->prepare("SELECT * FROM v_attendance_summary
                              WHERE student_id = ?
                              ORDER BY student_id DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $attendance = $stmt->fetch();
        $relatedData = ['type' => 'attendance', 'summary' => $attendance];
        break;

    case 'promotion':
        $stmt = $db->prepare("SELECT * FROM promotions
                              WHERE student_id = ? AND is_active = 1
                              ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$acknowledgment['student_id']]);
        $promotion = $stmt->fetch();
        $relatedData = ['type' => 'promotion', 'decision' => $promotion];
        break;
}

// ظ…ط¹ط§ظ„ط¬ط© ط§ظ„ط¥ظ‚ط±ط§ط±
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge'])) {
    if (!verifyCsrf()) {
        setFlash('ط®ط·ط£ ظپظٹ ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط£ظ…ط§ظ†', 'danger');
        redirect('acknowledge.php?id=' . $ackId);
    }

    // ظپظ‚ط· ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ظٹظ…ظƒظ†ظ‡ ط§ظ„ط¥ظ‚ط±ط§ط±
    if ($userRole !== 'parent') {
        setFlash('ظپظ‚ط· ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ظٹظ…ظƒظ†ظ‡ طھظ‚ط¯ظٹظ… ط§ظ„ط¥ظ‚ط±ط§ط±', 'danger');
        redirect('acknowledge.php?id=' . $ackId);
    }

    if ($acknowledgment['is_acknowledged']) {
        setFlash('طھظ… ط§ظ„ط¥ظ‚ط±ط§ط± ظ…ط³ط¨ظ‚ط§ظ‹', 'info');
        redirect('index.php');
    }

    $notes = trim($_POST['notes'] ?? '');

    $stmt = $db->prepare("UPDATE parent_acknowledgments SET is_acknowledged = 1, acknowledged_at = NOW(), notes = ? WHERE id = ?");
    $stmt->execute([$notes, $ackId]);

    logActivity($db, $userId, 'parent_acknowledgment', 'ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط±: ' . $acknowledgment['acknowledgment_type'] . ' ظ„ظ„ط·ط§ظ„ط¨ ' . $acknowledgment['student_name'], 'parent_acknowledgment', $ackId);

    setFlash('طھظ… طھط³ط¬ظٹظ„ ط¥ظ‚ط±ط§ط±ظƒ ط¨ظ†ط¬ط§ط­. ط´ظƒط±ط§ظ‹ ظ„طھط¹ط§ظˆظ†ظƒظ….', 'success');
    redirect('index.php');
}

$decisionLabels = [
    'promoted' => ['ظ†ط§ط¬ط­ - ظ…ط±ظپظ‘ط¹', 'success'],
    'repeated' => ['ط±ط§ط³ط¨ - ظ…ط¹ظٹط¯', 'danger'],
    'conditional' => ['طھط±ظپظٹط¹ ظ…ط´ط±ظˆط·', 'warning'],
    'under_review' => ['ظ‚ظٹط¯ ط§ظ„ظ…ط±ط§ط¬ط¹ط©', 'info'],
];
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">ًں“„ ظ†ظ…ظˆط°ط¬ ط§ظ„ط¥ظ‚ط±ط§ط± - <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? 'ط¹ط§ظ…'); ?></h2>
    </div>
    <div class="card-body">
        <!-- ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ط·ط§ظ„ط¨ -->
        <div class="card bg-light mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>ًں‘¨â€چًںژ“ ط§ظ„ط·ط§ظ„ط¨:</strong> <?php echo e($acknowledgment['student_name']); ?></p>
                        <p><strong>ًں”¢ ط±ظ‚ظ… ط§ظ„ظ‚ظٹط¯:</strong> <?php echo e($acknowledgment['student_number']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>ًں‘¤ ظˆظ„ظٹ ط§ظ„ط£ظ…ط±:</strong> <?php echo e($acknowledgment['parent_name']); ?></p>
                        <p><strong>ًں“‹ ظ†ظˆط¹ ط§ظ„ط¥ظ‚ط±ط§ط±:</strong> <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? 'ط¹ط§ظ…'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ…ط±طھط¨ط·ط© -->
        <?php if ($relatedData): ?>
        <div class="card border mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0 text-white">ًں“ٹ ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ…طھط¹ظ„ظ‚ط©</h4>
            </div>
            <div class="card-body">
                <?php if ($relatedData['type'] === 'grades' && !empty($relatedData['grades'])): ?>
                <table class="table table-bordered table-sm">
                    <thead class="table-primary">
                        <tr>
                            <th>ط§ظ„ظ…ط§ط¯ط©</th>
                            <th>ط§ظ„ط¯ط±ط¬ط©</th>
                            <th>ظ…ظ†</th>
                            <th>ط§ظ„ظ†ظˆط¹</th>
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
                            <td><strong>ط§ظ„ظ…ط¹ط¯ظ„ ط§ظ„ط¹ط§ظ…</strong></td>
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
                            <div class="stat-card-label">ط§ظ„ط¯ط±ط¬ط© ط§ظ„ظƒظ„ظٹط©</div>
                            <div class="stat-card-value <?php echo $relatedData['evaluation']['overall_score'] >= 60 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $relatedData['evaluation']['overall_score']; ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <p><strong>طھط§ط±ظٹط® ط§ظ„طھظ‚ظٹظٹظ…:</strong> <?php echo formatDateAr($relatedData['evaluation']['evaluation_date']); ?></p>
                        <?php if ($relatedData['evaluation']['notes']): ?>
                        <p><strong>ظ…ظ„ط§ط­ط¸ط§طھ:</strong> <?php echo nl2br(e($relatedData['evaluation']['notes'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($relatedData['type'] === 'attendance' && $relatedData['summary']): ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط£ظٹط§ظ…</div>
                            <div class="stat-card-value"><?php echo $relatedData['summary']['total_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">ط£ظٹط§ظ… ط§ظ„ط­ط¶ظˆط±</div>
                            <div class="stat-card-value text-success"><?php echo $relatedData['summary']['present_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">ط£ظٹط§ظ… ط§ظ„ط؛ظٹط§ط¨</div>
                            <div class="stat-card-value text-danger"><?php echo $relatedData['summary']['absent_days']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card stat-card-sm">
                            <div class="stat-card-label">ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±</div>
                            <div class="stat-card-value"><?php echo $relatedData['summary']['attendance_rate']; ?>%</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($relatedData['type'] === 'promotion' && $relatedData['decision']): ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">ط§ظ„ظ‚ط±ط§ط±</div>
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
                            <div class="stat-card-label">ط§ظ„ط¯ط±ط¬ط© ط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹط©</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['academic_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">ط¯ط±ط¬ط© ط§ظ„ط³ظ„ظˆظƒ</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['behavior_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-card-label">ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±</div>
                            <div class="stat-card-value"><?php echo $relatedData['decision']['attendance_score'] ?? '-'; ?>%</div>
                        </div>
                    </div>
                </div>
                <?php if ($relatedData['decision']['notes']): ?>
                <div class="alert alert-info mt-3">
                    <strong>ظ…ظ„ط§ط­ط¸ط§طھ:</strong> <?php echo e($relatedData['decision']['notes']); ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted">ظ„ط§ طھظˆط¬ط¯ ط¨ظٹط§ظ†ط§طھ ظ…طھط§ط­ط© ط­ط§ظ„ظٹط§ظ‹</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ظ†ظ…ظˆط°ط¬ ط§ظ„ط¥ظ‚ط±ط§ط± -->
        <?php if ($acknowledgment['is_acknowledged']): ?>
        <div class="alert alert-success">
            <strong>âœ… طھظ… ط§ظ„ط¥ظ‚ط±ط§ط±</strong> ظپظٹ <?php echo formatDateAr($acknowledgment['acknowledged_at'], 'full'); ?>
            <?php if ($acknowledgment['notes']): ?>
            <br><strong>ظ…ظ„ط§ط­ط¸ط§طھ:</strong> <?php echo e($acknowledgment['notes']); ?>
            <?php endif; ?>
        </div>
        <?php elseif ($userRole === 'parent'): ?>
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0 text-white">âœ… ظ†ظ…ظˆط°ط¬ ط§ظ„ط¥ظ‚ط±ط§ط±</h4>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    ط£ظ‚ط± ط¨ط£ظ†ظ†ظٹ ظ‚ط¯ ط§ط·ظ„ط¹طھ ط¹ظ„ظ‰ ط¨ظٹط§ظ†ط§طھ <?php echo e($ackTypeLabels[$acknowledgment['acknowledgment_type']] ?? ''); ?>
                    ط§ظ„ط®ط§طµط© ط¨ط§ط¨ظ†ظٹ/ط§ط¨ظ†طھظٹ <strong><?php echo e($acknowledgment['student_name']); ?></strong>
                    ظˆط£طھط­ظ…ظ„ ظ…ط³ط¤ظˆظ„ظٹط© ط§ظ„ط¹ظ„ظ… ط¨ظ‡ط§.
                </p>

                <form method="POST">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label class="form-label">ظ…ظ„ط§ط­ط¸ط§طھ (ط§ط®طھظٹط§ط±ظٹ)</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="ط£ظٹ ظ…ظ„ط§ط­ط¸ط§طھ طھظˆط¯ ط¥ط¶ط§ظپطھظ‡ط§..."></textarea>
                    </div>

                    <button type="submit" name="acknowledge" value="1" class="btn btn-primary btn-lg">
                        âœ… ط¥ظ‚ط±ط§ط± ط¨ط§ظ„ط§ط·ظ„ط§ط¹ ظˆط§ظ„ظ…ظˆط§ظپظ‚ط©
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            ط¨ط§ظ†طھط¸ط§ط± ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط± <strong><?php echo e($acknowledgment['parent_name']); ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
