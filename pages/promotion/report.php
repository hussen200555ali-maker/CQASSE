<?php
/**
 * ============================================================
 * EQAS - تقرير الترفيع
 * نظام التقييم الشامل - Promotion Report
 * ============================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/promotion.php';

// معالجة التصدير - يجب أن يكون قبل أي مخرجات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    $filters = [];
    if (!empty($_POST['school_id'])) $filters['school_id'] = $_POST['school_id'];
    if (!empty($_POST['academic_year_id'])) $filters['academic_year_id'] = $_POST['academic_year_id'];
    if (!empty($_POST['decision'])) $filters['decision'] = $_POST['decision'];
    
    $promoEngine = new PromotionEngine($db);
    $csv = $promoEngine->exportReport($filters, 'csv');
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="promotion_report_' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
    }
}

$pageTitle = 'تقرير الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'تقرير' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;

$schools = [];
try {
    if ($userRole === 'group_admin') {
        $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
        $schools = $stmt->fetchAll();
    }
} catch (Exception $e) {}

$academicYears = [];
try { $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY name DESC"); $academicYears = $stmt->fetchAll(); } catch (Exception $e) {}

$decisionLabels = $promoEngine->getDecisionLabels();
?>

<div class="section">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📥 تقرير قرارات الترفيع</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <?php if ($userRole === 'group_admin'): ?>
                    <div class="form-group">
                        <label class="form-label">المدرسة</label>
                        <select name="school_id" class="form-control">
                            <option value="">الكل</option>
                            <?php foreach ($schools as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">السنة</label>
                        <select name="academic_year_id" class="form-control">
                            <option value="">الكل</option>
                            <?php foreach ($academicYears as $ay): ?><option value="<?= $ay['id'] ?>"><?= e($ay['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نوع القرار</label>
                        <select name="decision" class="form-control">
                            <option value="">الكل</option>
                            <?php foreach ($decisionLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="export" value="1" class="btn btn-primary btn-lg">📥 تصدير التقرير</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
