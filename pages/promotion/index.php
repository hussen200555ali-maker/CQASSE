<?php
/**
 * ============================================================
 * EQAS - قرارات الترفيع - لوحة التحكم
 * نظام التقييم الشامل - Promotion Decisions Dashboard
 * ============================================================
 */

$pageTitle = 'قرارات الترفيع';
$breadcrumb = ['قرارات الترفيع' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

// فلاتر
$filters = [
    'academic_year_id' => $_GET['academic_year_id'] ?? '',
    'semester_id' => $_GET['semester_id'] ?? '',
    'decision' => $_GET['decision'] ?? '',
    'class_id' => $_GET['class_id'] ?? '',
    'page' => max(1, (int)($_GET['page'] ?? 1)),
];

if (in_array($userRole, ['school_admin']) && $userSchoolId) {
    $filters['school_id'] = $userSchoolId;
}

$decisions = $promoEngine->getDecisions($filters);
$totalDecisions = $promoEngine->getDecisionsCount($filters);
$stats = $promoEngine->getStatistics($userSchoolId, $filters['academic_year_id'] ?: null, $filters['semester_id'] ?: null);

// البيانات المساعدة
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$classes = [];
try {
    $schoolFilter = $userSchoolId ? " AND school_id = " . (int)$userSchoolId : "";
    $stmt = $db->query("SELECT id, name_ar FROM classes WHERE is_active = 1 {$schoolFilter} ORDER BY name_ar");
    $classes = $stmt->fetchAll();
} catch (Exception $e) {}

$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();
?>

<div class="section">
    <!-- إحصائيات -->
    <div class="grid grid-4 mb-6">
        <?php foreach ($decisionLabels as $key => $label): 
            $count = $stats['by_decision'][$key] ?? 0;
            $color = $decisionColors[$key];
        ?>
        <div class="card">
            <div class="card-body text-center" style="border-top:4px solid <?= $color['badge'] === 'success' ? '#28a745' : ($color['badge'] === 'danger' ? '#dc3545' : ($color['badge'] === 'warning' ? '#ffc107' : '#17a2b8')) ?>;">
                <div style="font-size:28px; font-weight:bold; color:<?= $color['badge'] === 'success' ? '#28a745' : ($color['badge'] === 'danger' ? '#dc3545' : ($color['badge'] === 'warning' ? '#856404' : '#17a2b8')) ?>;">
                    <?= $count ?>
                </div>
                <div class="text-secondary"><?= $label ?></div>
                <?php if ($stats['total'] > 0): ?>
                <div style="font-size:12px; color:var(--text-muted);"><?= $stats['percentages'][$key] ?? 0 ?>%</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- فلاتر -->
    <div class="card mb-6">
        <div class="card-body">
            <form method="GET" class="form-row">
                <div class="form-group">
                    <label class="form-label">السنة الدراسية</label>
                    <select name="academic_year_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filters['academic_year_id'] == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الفصل</label>
                    <select name="class_id" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filters['class_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">القرار</label>
                    <select name="decision" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($decisionLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filters['decision'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">🔍 تصفية</button>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول القرارات -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">قرارات الترفيع (<?= $totalDecisions ?>)</h3>
            <div class="flex gap-2">
                <a href="evaluate.php" class="btn btn-primary btn-sm">📝 تقييم جديد</a>
                <a href="bulk.php" class="btn btn-info btn-sm">📊 تقييم جماعي</a>
                <a href="approve.php" class="btn btn-warning btn-sm">✅ اعتماد</a>
                <a href="statistics.php" class="btn btn-light btn-sm">📈 إحصائيات</a>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($decisions)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">📈</div>
                <div class="table-empty-text">لا توجد قرارات ترفيع</div>
                <p class="text-secondary">ابدأ بتقييم الطلاب لاتخاذ قرارات الترفيع</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الطالب</th>
                            <th>الرقم</th>
                            <th>الأكاديمي</th>
                            <th>السلوك</th>
                            <th>الحضور</th>
                            <th>القرار</th>
                            <th>التاريخ</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($decisions as $d): 
                            $dc = $decisionColors[$d['decision']] ?? $decisionColors['under_review'];
                        ?>
                        <tr>
                            <td><strong><?= e($d['student_name']) ?></strong></td>
                            <td><?= e($d['student_number']) ?></td>
                            <td>
                                <span style="color:<?= $d['academic_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $d['academic_pass'] ? '✅' : '❌' ?>
                                    <?= $d['academic_score'] !== null ? number_format($d['academic_score'], 1) . '%' : '-' ?>
                                </span>
                            </td>
                            <td>
                                <span style="color:<?= $d['behavior_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $d['behavior_pass'] ? '✅' : '❌' ?>
                                    <?= $d['behavior_score'] !== null ? number_format($d['behavior_score'], 1) . '%' : '-' ?>
                                </span>
                            </td>
                            <td>
                                <span style="color:<?= $d['attendance_pass'] ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $d['attendance_pass'] ? '✅' : '❌' ?>
                                    <?= $d['attendance_score'] !== null ? number_format($d['attendance_score'], 1) . '%' : '-' ?>
                                </span>
                            </td>
                            <td>
                                <span style="padding:4px 12px; border-radius:var(--radius-full); font-size:12px; font-weight:600; background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>;">
                                    <?= $decisionLabels[$d['decision']] ?? $d['decision'] ?>
                                </span>
                            </td>
                            <td style="font-size:12px;"><?= formatDateAr($d['decided_at'], 'short') ?></td>
                            <td>
                                <a href="view.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-light">👁️</a>
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

<?php
require_once __DIR__ . '/../../includes/footer.php';
