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

<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚙️ إعدادات أوزان أبعاد المؤشر</h3>
        </div>
        <div class="card-body">
            <div class="mb-6 p-4" style="background:rgba(23,162,184,0.1); border-radius:var(--radius-lg); border-right:4px solid var(--info);">
                <p style="margin:0;"><strong>ℹ️ ملاحظة:</strong> يجب أن يكون مجموع الأوزان 100%. تغيير الأوزان سيؤثر على حسابات المؤشر المستقبلية فقط.</p>
            </div>
            
            <form method="POST">
                <?= csrfField() ?>
                
                <div class="table-wrapper mb-6">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>البعد</th>
                                <th>الوصف</th>
                                <th>الوزن الحالي (%)</th>
                                <th>الوزن الجديد (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dimensions as $key => $dim): ?>
                            <tr>
                                <td>
                                    <span style="font-size:20px;"><?= $dim['icon'] ?></span>
                                    <strong><?= $dim['name'] ?></strong>
                                </td>
                                <td>
                                    <span class="text-secondary" style="font-size:13px;">
                                        <?php 
                                        $descs = [
                                            'academic' => 'متوسط تحصيل الطلاب في جميع المواد',
                                            'teaching' => 'جودة التدريس (ذاتي + أقران + إشرافي)',
                                            'climate' => 'المناخ المدرسي العام من الاستبيانات',
                                            'behavior' => 'مستوى السلوك من التقييمات السلوكية',
                                            'skills' => 'المهارات الحياتية والأكاديمية',
                                            'attendance' => 'معدل حضور الطلاب',
                                        ];
                                        echo e($descs[$key] ?? '');
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= ($dim['weight'] * 100) ?>%</strong>
                                </td>
                                <td>
                                    <input type="number" name="weight_<?= $key ?>" class="form-control" 
                                           value="<?= ($dim['weight'] * 100) ?>" min="0" max="100" step="0.5"
                                           style="width:100px; text-align:center;"
                                           oninput="updateTotal()">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3"><strong>المجموع</strong></td>
                                <td>
                                    <strong id="weight-total" style="font-size:18px; color:var(--primary);">100%</strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" onclick="return validateWeights()">💾 حفظ الأوزان</button>
                    <a href="settings.php" class="btn btn-light">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageJs = "
function updateTotal() {
    var inputs = document.querySelectorAll('input[name^=weight_]');
    var total = 0;
    inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
    var el = document.getElementById('weight-total');
    el.textContent = total.toFixed(1) + '%';
    el.style.color = Math.abs(total - 100) < 0.1 ? 'var(--success)' : 'var(--danger)';
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
";
require_once __DIR__ . '/../../includes/footer.php';
