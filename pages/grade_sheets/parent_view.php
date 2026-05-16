<?php
/**
 * ============================================================
 * EQAS - ظƒط´ظˆظپ ط§ظ„ط¯ط±ط¬ط§طھ - ط¹ط±ط¶ ظˆظ„ظٹ ط§ظ„ط£ظ…ط±
 * ظ†ط¸ط§ظ… ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط´ط§ظ…ظ„ - ط¹ط±ط¶ ظ…ط¨ط³ط· ظ„ط£ظˆظ„ظٹط§ط، ط§ظ„ط£ظ…ظˆط±
 * ============================================================
 */

$pageTitle = 'ط¯ط±ط¬ط§طھ ط§ظ„ط·ط§ظ„ط¨';
$breadcrumb = ['ط¯ط±ط¬ط§طھ ط§ظ„ط·ط§ظ„ط¨' => null];

require_once __DIR__ . '/../../includes/header.php';

// ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„طµظ„ط§ط­ظٹط§طھ - ظˆظ„ظٹ ط§ظ„ط£ظ…ط± ظپظ‚ط·
$userRole = $_SESSION['user']['role'] ?? '';
if ($userRole !== 'parent' && !hasPermission('school_admin')) {
    setFlash('ظ„ظٹط³ ظ„ط¯ظٹظƒ طµظ„ط§ط­ظٹط© ظ„ظ„ظˆطµظˆظ„ ط¥ظ„ظ‰ ظ‡ط°ظ‡ ط§ظ„طµظپط­ط©', 'danger');
    redirect('index.php');
}

// ط¬ظ„ط¨ ط·ظ„ط§ط¨ ظˆظ„ظٹ ط§ظ„ط£ظ…ط±
$userId = $_SESSION['user']['id'];
$studentId = $_GET['student_id'] ?? '';
$academicYearId = $_GET['academic_year_id'] ?? getCurrentAcademicYearId($db);
$semesterId = $_GET['semester_id'] ?? '';

// ط¬ظ„ط¨ ط£ط¨ظ†ط§ط، ظˆظ„ظٹ ط§ظ„ط£ظ…ط±
$myStudents = [];
if ($userRole === 'parent') {
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar, s.grade_level_id, gl.name_ar as grade_name
                          FROM students s
                          JOIN parent_students ps ON ps.student_id = s.id
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          WHERE ps.parent_id = ? AND s.is_active = 1 AND ps.is_active = 1
                          ORDER BY s.full_name_ar");
    $stmt->execute([$userId]);
    $myStudents = $stmt->fetchAll();
} elseif (hasPermission('school_admin')) {
    // ظ…ط¯ظٹط± ط§ظ„ظ…ط¯ط±ط³ط© ظٹظ…ظƒظ†ظ‡ ط¹ط±ط¶ ط£ظٹ ط·ط§ظ„ط¨
    $schoolId = $_SESSION['user']['school_id'] ?? 0;
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.full_name_ar, s.grade_level_id, gl.name_ar as grade_name
                          FROM students s
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          WHERE s.school_id = ? AND s.is_active = 1
                          ORDER BY s.full_name_ar
                          LIMIT 50");
    $stmt->execute([$schoolId]);
    $myStudents = $stmt->fetchAll();
}

// ط§ط®طھظٹط§ط± ط£ظˆظ„ ط·ط§ظ„ط¨ ط¥ط°ط§ ظ„ظ… ظٹط­ط¯ط¯
if (!$studentId && !empty($myStudents)) {
    $studentId = $myStudents[0]['id'];
}

// ط¬ظ„ط¨ ط§ظ„ط³ظ†ظˆط§طھ ظˆط§ظ„ظپطµظˆظ„
$stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
$academicYears = $stmt->fetchAll();

$semesters = [];
if ($academicYearId) {
    $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
    $stmt->execute([$academicYearId]);
    $semesters = $stmt->fetchAll();
    if (!$semesterId && !empty($semesters)) {
        $semesterId = $semesters[0]['id'];
    }
}

// ط¬ظ„ط¨ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط·ط§ظ„ط¨
$studentData = null;
$gradeDetails = [];
$behaviorScore = null;
$attendanceSummary = null;
$parentAcknowledgment = null;

if ($studentId && $academicYearId && $semesterId) {
    // ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ط·ط§ظ„ط¨
    $stmt = $db->prepare("SELECT s.*, gl.name_ar as grade_name, sch.name_ar as school_name
                          FROM students s
                          JOIN grade_levels gl ON gl.id = s.grade_level_id
                          JOIN schools sch ON sch.id = s.school_id
                          WHERE s.id = ? AND s.is_active = 1");
    $stmt->execute([$studentId]);
    $studentData = $stmt->fetch();

    if ($studentData) {
        // ط§ظ„ط¯ط±ط¬ط§طھ
        $stmt = $db->prepare("SELECT g.*, sub.name_ar as subject_name
                              FROM grades g
                              JOIN subjects sub ON sub.id = g.subject_id
                              WHERE g.student_id = ? AND g.academic_year_id = ? AND g.semester_id = ? AND g.is_active = 1
                              ORDER BY sub.name_ar");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $gradeDetails = $stmt->fetchAll();

        // طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒ
        $stmt = $db->prepare("SELECT e.*, u.full_name_ar as evaluator_name
                              FROM evaluations e
                              LEFT JOIN users u ON u.id = e.student_id
                              WHERE e.eval_type = 'behavior_score' AND e.student_id = ? AND e.academic_year_id = ? AND e.semester_id = ? AND e.is_active = 1
                              ORDER BY e.id DESC LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $behaviorScore = $stmt->fetch();

        // ظ…ظ„ط®طµ ط§ظ„ط­ط¶ظˆط±
        $stmt = $db->prepare("SELECT * FROM v_attendance_summary
                              WHERE student_id = ? AND academic_year_id = ? AND semester_id = ?
                              LIMIT 1");
        $stmt->execute([$studentId, $academicYearId, $semesterId]);
        $attendanceSummary = $stmt->fetch();

        // ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط±
        $stmt = $db->prepare("SELECT pa.*, p.full_name_ar as parent_name
                              FROM parent_acknowledgments pa
                              JOIN users p ON p.id = pa.parent_id
                              WHERE pa.student_id = ? AND pa.acknowledgment_type = 'grades'
                              AND pa.entity_id = ? AND pa.is_active = 1
                              ORDER BY pa.created_at DESC LIMIT 1");
        $stmt->execute([$studentId, $semesterId]);
        $parentAcknowledgment = $stmt->fetch();
    }
}

// ظ…ط¹ط§ظ„ط¬ط© ط§ظ„ط¥ظ‚ط±ط§ط±
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge'])) {
    if (!verifyCsrf()) {
        setFlash('ط®ط·ط£ ظپظٹ ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط£ظ…ط§ظ†', 'danger');
    } else {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $db->prepare("INSERT INTO parent_acknowledgments (parent_id, student_id, acknowledgment_type, entity_id, is_acknowledged, acknowledged_at, notes, is_active, created_at)
                              VALUES (?, ?, 'grades', ?, 1, NOW(), ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE is_acknowledged = 1, acknowledged_at = NOW(), notes = ?");
        $stmt->execute([$userId, $studentId, $semesterId, $notes, $notes]);
        setFlash('طھظ… طھط³ط¬ظٹظ„ ط¥ظ‚ط±ط§ط±ظƒ ط¨ظ†ط¬ط§ط­', 'success');
        redirect('parent_view.php?student_id=' . $studentId . '&academic_year_id=' . $academicYearId . '&semester_id=' . $semesterId);
    }
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">ًںژ“ ط¹ط±ط¶ ط¯ط±ط¬ط§طھ ط§ظ„ط·ط§ظ„ط¨</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="form-grid">
            <div class="form-group">
                <label class="form-label">ط§ظ„ط·ط§ظ„ط¨</label>
                <select name="student_id" class="form-control">
                    <?php foreach ($myStudents as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $studentId == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo e($s['full_name_ar'] . ' - ' . $s['grade_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ط§ظ„ط³ظ†ط© ط§ظ„ط£ظƒط§ط¯ظٹظ…ظٹط©</label>
                <select name="academic_year_id" class="form-control">
                    <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo $year['id']; ?>" <?php echo $academicYearId == $year['id'] ? 'selected' : ''; ?>>
                        <?php echo e($year['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ط§ظ„ظپطµظ„ ط§ظ„ط¯ط±ط§ط³ظٹ</label>
                <select name="semester_id" class="form-control">
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?php echo $sem['id']; ?>" <?php echo $semesterId == $sem['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sem['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary">ًں”چ ط¹ط±ط¶</button>
            </div>
        </form>
    </div>
</div>

<?php if ($studentData): ?>
<div class="card mt-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="card-title">ًں“‹ ظƒط´ظپ ط¯ط±ط¬ط§طھ - <?php echo e($studentData['full_name_ar']); ?></h2>
            <p class="text-sm text-muted"><?php echo e($studentData['school_name'] . ' | ' . $studentData['grade_name']); ?></p>
        </div>
        <button onclick="window.print()" class="btn btn-secondary">ًں–¨ï¸ڈ ط·ط¨ط§ط¹ط©</button>
    </div>
    <div class="card-body">
        <!-- ظ…ظ„ط®طµ ط§ظ„ط¯ط±ط¬ط§طھ -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">ط§ظ„ظ…ط¹ط¯ظ„ ط§ظ„ط¹ط§ظ…</div>
                    <?php
                    $totalGrade = 0;
                    $gradeCount = 0;
                    foreach ($gradeDetails as $gd) {
                        $totalGrade += $gd['grade_value'];
                        $gradeCount++;
                    }
                    $avgGrade = $gradeCount > 0 ? round($totalGrade / $gradeCount, 2) : 0;
                    ?>
                    <div class="stat-card-value <?php echo $avgGrade >= 50 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $avgGrade; ?>%
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">ط§ظ„طھظ‚ظٹظٹظ… ط§ظ„ط³ظ„ظˆظƒظٹ</div>
                    <div class="stat-card-value">
                        <?php echo $behaviorScore ? $behaviorScore['overall_score'] . '%' : 'ط؛ظٹط± ظ…طھظˆظپط±'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-card-label">ظ†ط³ط¨ط© ط§ظ„ط­ط¶ظˆط±</div>
                    <div class="stat-card-value">
                        <?php echo $attendanceSummary ? $attendanceSummary['attendance_rate'] . '%' : 'ط؛ظٹط± ظ…طھظˆظپط±'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- طھظپط§طµظٹظ„ ط§ظ„ط¯ط±ط¬ط§طھ -->
        <?php if (!empty($gradeDetails)): ?>
        <h4 class="mb-3">ًں“ٹ طھظپط§طµظٹظ„ ط§ظ„ط¯ط±ط¬ط§طھ</h4>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>ط§ظ„ظ…ط§ط¯ط©</th>
                    <th>ط§ظ„ط¯ط±ط¬ط©</th>
                    <th>ط§ظ„ظ†ط³ط¨ط©</th>
                    <th>ط§ظ„ط­ط§ظ„ط©</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gradeDetails as $gd): ?>
                <tr>
                    <td><?php echo e($gd['subject_name']); ?></td>
                    <td class="<?php echo $gd['grade_value'] < 50 ? 'text-danger font-weight-bold' : ''; ?>">
                        <?php echo $gd['grade_value']; ?>
                    </td>
                    <td>
                        <div class="progress" style="height:20px;">
                            <div class="progress-bar <?php echo $gd['grade_value'] >= 50 ? 'bg-success' : 'bg-danger'; ?>"
                                 style="width:<?php echo min($gd['grade_value'], 100); ?>%">
                                <?php echo $gd['grade_value']; ?>%
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $gd['grade_value'] >= 50 ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $gd['grade_value'] >= 50 ? 'ظ†ط§ط¬ط­' : 'ط±ط§ط³ط¨'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="alert alert-info">ظ„ط§ طھظˆط¬ط¯ ط¯ط±ط¬ط§طھ ظ…ط³ط¬ظ„ط© ظ„ظ‡ط°ط§ ط§ظ„ظپطµظ„ ط§ظ„ط¯ط±ط§ط³ظٹ.</div>
        <?php endif; ?>

        <!-- ظ…ظ„ط®طµ ط§ظ„ط­ط¶ظˆط± -->
        <?php if ($attendanceSummary): ?>
        <h4 class="mb-3 mt-4">ًں“… ظ…ظ„ط®طµ ط§ظ„ط­ط¶ظˆط±</h4>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط£ظٹط§ظ…</div>
                    <div class="stat-card-value"><?php echo $attendanceSummary['total_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">ط£ظٹط§ظ… ط§ظ„ط­ط¶ظˆط±</div>
                    <div class="stat-card-value text-success"><?php echo $attendanceSummary['present_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">ط£ظٹط§ظ… ط§ظ„ط؛ظٹط§ط¨</div>
                    <div class="stat-card-value text-danger"><?php echo $attendanceSummary['absent_days']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-sm">
                    <div class="stat-card-label">ط£ظٹط§ظ… ط§ظ„طھط£ط®ظٹط±</div>
                    <div class="stat-card-value text-warning"><?php echo $attendanceSummary['late_days']; ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ظ…ظ„ط§ط­ط¸ط§طھ ط§ظ„ط³ظ„ظˆظƒ -->
        <?php if ($behaviorScore && $behaviorScore['notes']): ?>
        <h4 class="mb-3 mt-4">ًں’¬ ظ…ظ„ط§ط­ط¸ط§طھ ط§ظ„ط³ظ„ظˆظƒ</h4>
        <div class="alert alert-light border">
            <?php echo nl2br(e($behaviorScore['notes'])); ?>
        </div>
        <?php endif; ?>

        <!-- ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط± -->
        <?php if ($userRole === 'parent'): ?>
        <div class="card mt-4" style="border:2px solid var(--primary);">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title text-white">âœ… ط¥ظ‚ط±ط§ط± ظˆظ„ظٹ ط§ظ„ط£ظ…ط±</h3>
            </div>
            <div class="card-body">
                <?php if ($parentAcknowledgment && $parentAcknowledgment['is_acknowledged']): ?>
                    <div class="alert alert-success">
                        <strong>âœ… طھظ… ط§ظ„ط¥ظ‚ط±ط§ط±</strong> ظپظٹ <?php echo formatDateAr($parentAcknowledgment['acknowledged_at'], 'full'); ?>
                        <?php if ($parentAcknowledgment['notes']): ?>
                            <br>ظ…ظ„ط§ط­ط¸ط§طھ: <?php echo e($parentAcknowledgment['notes']); ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p>ط£ظ‚ط± ط¨ط£ظ†ظ†ظٹ ظ‚ط¯ ط§ط·ظ„ط¹طھ ط¹ظ„ظ‰ ط¯ط±ط¬ط§طھ ظˆط³ظ„ظˆظƒ ظˆط­ط¶ظˆط± ط§ط¨ظ†ظٹ/ط§ط¨ظ†طھظٹ ط§ظ„ظ…ط°ظƒظˆط±ط© ط£ط¹ظ„ط§ظ‡.</p>
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label class="form-label">ظ…ظ„ط§ط­ط¸ط§طھ (ط§ط®طھظٹط§ط±ظٹ)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="ط£ظٹ ظ…ظ„ط§ط­ط¸ط§طھ طھظˆط¯ ط¥ط¶ط§ظپطھظ‡ط§..."></textarea>
                        </div>
                        <button type="submit" name="acknowledge" value="1" class="btn btn-primary btn-lg">
                            âœ… ط¥ظ‚ط±ط§ط± ط¨ط§ظ„ط§ط·ظ„ط§ط¹
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
