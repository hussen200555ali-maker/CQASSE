<?php
/**
 * ============================================================
 * EQAS - اعتماد قرارات الترفيع
 * نظام التقييم الشامل - Approve Promotion Decisions
 * ============================================================
 */

$pageTitle = 'اعتماد قرارات الترفيع';
$breadcrumb = ['قرارات الترفيع' => 'index.php', 'اعتماد' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/promotion.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية الاعتماد.', 'danger');
    redirect('index.php');
}

$promoEngine = new PromotionEngine($db);
$decisionLabels = $promoEngine->getDecisionLabels();
$decisionColors = $promoEngine->getDecisionColors();

// الحصول على القرارات
$filters = ['per_page' => 50];
if (!empty($_GET['decision'])) $filters['decision'] = $_GET['decision'];
$decisions = $promoEngine->getDecisions($filters);

// معالجة الاعتماد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $selectedIds = $_POST['decision_ids'] ?? [];
        
        if (empty($selectedIds)) {
            setFlash('يرجى اختيار قرار واحد على الأقل.', 'warning');
        } else {
            $selectedIds = array_map('intval', $selectedIds);
            
            if ($_POST['action'] === 'approve_selected') {
                $count = $promoEngine->approveBulkDecisions($selectedIds, $_SESSION['user']['id']);
                setFlash(sprintf('تم اعتماد %d قرار بنجاح.', $count), 'success');
            } elseif ($_POST['action'] === 'approve_all') {
                $allIds = array_column($decisions, 'id');
                $count = $promoEngine->approveBulkDecisions($allIds, $_SESSION['user']['id']);
                setFlash(sprintf('تم اعتماد %d قرار بنجاح.', $count), 'success');
            }
        }
        redirect('approve.php');
    }
}
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">✅ اعتماد قرارات الترفيع</h3>
        </div>
        <div class="card-body">
            <div class="mb-4 p-3" style="background:rgba(40,167,69,0.1); border-radius:var(--radius-md); border-right:4px solid var(--success);">
                <p style="margin:0;">اختر القرارات التي تريد اعتمادها ثم اضغط "اعتماد المحدد". يمكنك أيضاً اعتماد جميع القرارات دفعة واحدة.</p>
            </div>
            <form method="GET" class="form-inline mb-4">
                <select name="decision" class="form-control" style="width:auto;">
                    <option value="">جميع القرارات</option>
                    <?php foreach ($decisionLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($_GET['decision'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">🔍 تصفية</button>
            </form>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <div class="card">
            <div class="card-header">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" id="select-all" onclick="toggleAll(this)">
                    <span class="form-check-label" style="font-weight:bold;">تحديد الكل</span>
                </label>
                <div class="flex gap-2">
                    <button type="submit" name="action" value="approve_selected" class="btn btn-success btn-sm" onclick="return confirmAction('اعتماد')">✅ اعتماد المحدد</button>
                    <button type="submit" name="action" value="approve_all" class="btn btn-warning btn-sm" onclick="return confirm('هل تريد اعتماد جميع القرارات؟')">✅ اعتماد الكل</button>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($decisions)): ?>
                <div class="table-empty"><div class="table-empty-icon">✅</div><div class="table-empty-text">لا توجد قرارات للاعتماد</div></div>
                <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr><th style="width:40px;"></th><th>الطالب</th><th>الأكاديمي</th><th>السلوك</th><th>الحضور</th><th>القرار</th><th>بواسطة</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decisions as $d): $dc = $decisionColors[$d['decision']] ?? $decisionColors['under_review']; ?>
                            <tr>
                                <td><input type="checkbox" name="decision_ids[]" value="<?= $d['id'] ?>" class="form-check-input dec-checkbox"></td>
                                <td><strong><?= e($d['student_name']) ?></strong></td>
                                <td><?= $d['academic_score'] !== null ? number_format($d['academic_score'], 1) . '%' : '-' ?></td>
                                <td><?= $d['behavior_score'] !== null ? number_format($d['behavior_score'], 1) . '%' : '-' ?></td>
                                <td><?= $d['attendance_score'] !== null ? number_format($d['attendance_score'], 1) . '%' : '-' ?></td>
                                <td><span style="padding:2px 8px; border-radius:var(--radius-full); font-size:11px; background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>;"><?= $decisionLabels[$d['decision']] ?></span></td>
                                <td style="font-size:12px;"><?= e($d['school_name'] ?? '-') ?></td>
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

<?php
$pageJs = "
function toggleAll(el) { document.querySelectorAll('.dec-checkbox').forEach(cb => cb.checked = el.checked); }
function confirmAction(a) { var c = document.querySelectorAll('.dec-checkbox:checked').length; if (!c) { alert('اختر قراراً أولاً.'); return false; } return confirm(a + ' ' + c + ' قرار؟'); }
";
require_once __DIR__ . '/../../includes/footer.php';
