<?php
/**
 * ============================================================
 * EQAS - إعدادات أوزان المؤشر
 * نظام التقييم الشامل - EQI Weight Settings
 * ============================================================
 */

$pageTitle = 'إعدادات أوزان المؤشر';
$breadcrumb = ['مؤشر جودة التعليم' => 'index.php', 'الإعدادات' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/eqi.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$eqiCalc = new EQICalculator($db);
$dimensions = $eqiCalc->getDimensions();

// معالجة حفظ الإعدادات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $weights = [];
        foreach ($dimensions as $key => $dim) {
            $weights[$key] = (float)($_POST['weight_' . $key] ?? $dim['weight'] * 100);
        }
        
        $result = $eqiCalc->updateWeights($weights);
        if ($result['success']) {
            setFlash($result['message'], 'success');
            $dimensions = $eqiCalc->getDimensions(); // إعادة تحميل
        } else {
            setFlash($result['message'], 'danger');
        }
        redirect('settings.php');
    }
}
?>

<style>
.set-hero {
    background: linear-gradient(135deg, #0f3a20 0%, #1a5632 50%, #2d7a4a 100%);
    border-radius: 16px; padding: 2rem 2.5rem; margin-bottom: 1.5rem;
    color: #fff; position: relative; overflow: hidden;
}
.set-hero::before {
    content: ''; position: absolute; top: -50%; right: -15%;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(200,169,81,0.12) 0%, transparent 70%);
    border-radius: 50%;
}
.set-hero h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; position: relative; z-index: 1; }
.set-hero .hero-sub { font-size: 0.9rem; opacity: 0.85; position: relative; z-index: 1; }
.set-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem; border: 1px solid #dee2e6; overflow: hidden;
}
.set-card-hd {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; gap: 0.5rem;
}
.set-card-hd h3 { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; margin: 0; }
.set-card-bd { padding: 1.5rem; }
.note-box {
    padding: 0.9rem 1.1rem; border-radius: 8px; border-right: 4px solid #17a2b8;
    background: #e3f2fd; font-size: 0.88rem; margin-bottom: 1.25rem;
}
.note-box strong { color: #0d47a1; }
.set-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.set-table th {
    background: #f8f9fa; font-weight: 600; text-align: right;
    padding: 0.75rem 1rem; font-size: 0.8rem; color: #6c757d;
    border-bottom: 2px solid #dee2e6;
}
.set-table th:first-child { border-radius: 0 8px 0 0; }
.set-table th:last-child { border-radius: 8px 0 0 0; }
.set-table td { padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; font-size: 0.88rem; vertical-align: middle; }
.set-table tbody tr:hover { background: #f1f3f5; }
.set-table tbody tr:last-child td { border-bottom: none; }
.set-table .dim-icon { font-size: 1.4rem; margin-left: 0.4rem; }
.set-table .dim-desc { font-size: 0.8rem; color: #6c757d; }
.set-table input[type="number"] {
    border: 1px solid #dee2e6; border-radius: 6px; padding: 0.4rem;
    width: 80px; text-align: center; font-size: 0.9rem; font-weight: 600;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.set-table input[type="number"]:focus {
    border-color: #1a5632; box-shadow: 0 0 0 3px rgba(26,86,50,0.12); outline: none;
}
.total-display { font-size: 1.2rem; font-weight: 800; }
.total-ok { color: #28a745; }
.total-err { color: #dc3545; }
.btn-save {
    display: inline-flex; align-items: center; gap: 0.4rem;
    background: #1a5632; color: #fff; padding: 0.6rem 1.5rem;
    border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-save:hover { background: #0f3a20; }
.btn-cancel {
    display: inline-flex; align-items: center;
    padding: 0.6rem 1.25rem; border-radius: 8px;
    border: 1px solid #dee2e6; color: #6c757d; font-size: 0.9rem;
    text-decoration: none; transition: all 0.2s;
}
.btn-cancel:hover { background: #f8f9fa; }
@media (max-width:768px) {
    .set-hero { padding: 1.25rem; }
}
</style>

<div class="container mx-auto px-4 py-8">

    <!-- Hero -->
    <div class="set-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1>إعدادات أوزان المؤشر</h1>
                <div class="hero-sub">تخصيص أوزان أبعاد مؤشر جودة التعليم الستة</div>
            </div>
            <a href="index.php" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:8px;color:#fff;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">← العودة</a>
        </div>
    </div>

    <!-- Settings -->
    <div class="set-card">
        <div class="set-card-hd"><h3>⚙️ أوزان أبعاد المؤشر</h3></div>
        <div class="set-card-bd">
            <div class="note-box"><strong>ℹ️ ملاحظة:</strong> يجب أن يكون مجموع الأوزان 100%. تغيير الأوزان سيؤثر على حسابات المؤشر المستقبلية فقط.</div>
            <form method="POST">
                <?= csrfField() ?>
                <div style="overflow-x:auto;margin-bottom:1.25rem;">
                    <table class="set-table">
                        <thead>
                            <tr><th>البعد</th><th>الوصف</th><th style="text-align:center;">الوزن الحالي</th><th style="text-align:center;">الوزن الجديد</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $dimBgColors = ['academic' => '#e8f5e9','teaching' => '#e3f2fd','climate' => '#fff3e0','behavior' => '#fce4ec','skills' => '#f3e5f5','attendance' => '#e0f2f1'];
                            foreach ($dimensions as $key => $dim): 
                            ?>
                            <tr style="background:<?= $dimBgColors[$key] ?? '#fff' ?>;">
                                <td><span class="dim-icon"><?= $dim['icon'] ?></span> <strong><?= $dim['name'] ?></strong></td>
                                <td><span class="dim-desc"><?php 
                                    $descs = ['academic' => 'متوسط تحصيل الطلاب في جميع المواد','teaching' => 'جودة التدريس (ذاتي + أقران + إشرافي)','climate' => 'المناخ المدرسي العام من الاستبيانات','behavior' => 'مستوى السلوك من التقييمات السلوكية','skills' => 'المهارات الحياتية والأكاديمية','attendance' => 'معدل حضور الطلاب'];
                                    echo e($descs[$key] ?? '');
                                ?></span></td>
                                <td style="text-align:center;"><strong><?= ($dim['weight'] * 100) ?>%</strong></td>
                                <td style="text-align:center;">
                                    <input type="number" name="weight_<?= $key ?>" value="<?= ($dim['weight'] * 100) ?>" min="0" max="100" step="0.5" oninput="updateTotal()">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8f9fa;">
                                <td colspan="3"><strong>المجموع</strong></td>
                                <td style="text-align:center;">
                                    <span class="total-display total-ok" id="weight-total">100%</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <button type="submit" class="btn-save" onclick="return validateWeights()">💾 حفظ الأوزان</button>
                    <a href="settings.php" class="btn-cancel">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateTotal() {
    var inputs = document.querySelectorAll('input[name^=weight_]');
    var total = 0;
    inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
    var el = document.getElementById('weight-total');
    el.textContent = total.toFixed(1) + '%';
    el.className = 'total-display ' + (Math.abs(total - 100) < 0.1 ? 'total-ok' : 'total-err');
}
function validateWeights() {
    var inputs = document.querySelectorAll('input[name^=weight_]');
    var total = 0;
    inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
    if (Math.abs(total - 100) > 0.1) {
        alert('مجموع الأوزان يجب أن يكون 100%. المجموع الحالي: ' + total.toFixed(1) + '%');
        return false;
    }
    return confirm('هل تريد تحديث أوزان المؤشر؟');
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
