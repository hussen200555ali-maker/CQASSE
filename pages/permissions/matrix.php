<?php
$pageTitle = 'مصفوفة الصلاحيات';
$breadcrumb = [
    'النظام' => null,
    'الصلاحيات' => null,
    'مصفوفة الصلاحيات' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('../../index.php');
}

// ─── حفظ التغييرات ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();

            // تعطيل كل الصلاحيات أولاً
            $db->exec("UPDATE role_permissions SET is_active = 0");

            // تطبيق التحديدات الجديدة
            $checked = $_POST['perm'] ?? [];
            // $checked[role_id][] = permission_id
            $stmt = $db->prepare("UPDATE role_permissions SET is_active = 1 WHERE role_id = ? AND permission_id = ?");

            $insertStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id, is_active) VALUES (?, ?, 1)");

            foreach ($checked as $roleId => $permIds) {
                foreach ($permIds as $permId) {
                    $permId = (int)$permId;
                    $check = $db->prepare("SELECT id FROM role_permissions WHERE role_id = ? AND permission_id = ?");
                    $check->execute([(int)$roleId, $permId]);
                    if ($check->fetchColumn()) {
                        $stmt->execute([(int)$roleId, $permId]);
                    } else {
                        $insertStmt->execute([(int)$roleId, $permId]);
                    }
                }
            }

            $db->commit();
            setFlash('تم حفظ مصفوفة الصلاحيات بنجاح', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('حدث خطأ أثناء الحفظ: ' . $e->getMessage(), 'danger');
        }
    }
    redirect('matrix.php');
}

// ─── جلب البيانات ───
$roles = $db->query("SELECT id, name, display_name_ar FROM roles WHERE is_active = 1 ORDER BY rank DESC")->fetchAll();

$permissions = $db->query("SELECT id, name, name_ar, module FROM permissions WHERE is_active = 1 ORDER BY module, id")->fetchAll();

// تجميع الصلاحيات حسب الوحدة
$grouped = [];
foreach ($permissions as $p) {
    $grouped[$p['module']][] = $p;
}

// خريطة الدور ← permission_id ← is_active
$rpMap = [];
$rpStmt = $db->query("SELECT role_id, permission_id, is_active FROM role_permissions");
foreach ($rpStmt->fetchAll() as $rp) {
    $rpMap[$rp['role_id']][$rp['permission_id']] = (int)$rp['is_active'];
}

// ترجمة أسماء الوحدات
$moduleNames = [
    'users'       => 'المستخدمين',
    'schools'     => 'المدارس',
    'academic'    => 'العام الدراسي',
    'assessment'  => 'التقييمات',
    'grades'      => 'الدرجات',
    'attendance'  => 'الحضور',
    'behavior'    => 'السلوك',
    'reports'     => 'التقارير',
    'settings'    => 'الإعدادات',
    'backup'      => 'النسخ الاحتياطي',
    'promotion'   => 'الترقية',
];
?>
<div class="section">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">مصفوفة الصلاحيات</h3>
            <span class="badge badge-info">مدير النظام فقط</span>
        </div>
        <div class="card-body">
            <div style="background:var(--bg-warning-light);border:1px solid var(--border-warning);border-radius:8px;padding:16px;margin-bottom:20px;">
                <p style="margin:0;color:var(--text-warning);">
                    <strong>⚠️ ملاحظة:</strong> هذه المصفوفة أداة إدارة وتخطيط للصلاحيات فقط. الضبط الدقيق للصلاحيات على مستوى
                    الوحدة البرمجية سيُفعّل في إصدار لاحق. حالياً، يعتمد نظام التحكم في الوصول على التسلسل الهرمي للأدوار
                    (مدير النظام &gt; مدير المدرسة &gt; معلم &gt; ولي أمر).
                </p>
            </div>

            <form method="POST" action="" id="matrixForm">
                <?= csrfField() ?>
                <div style="overflow-x:auto;">
                    <table class="table table-bordered" id="permissionsMatrix" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th style="min-width:200px;">الصلاحية</th>
                                <?php foreach ($roles as $role): ?>
                                <th class="text-center" style="min-width:100px;">
                                    <?= e($role['display_name_ar']) ?>
                                    <br><small style="color:var(--text-muted);"><?= e($role['name']) ?></small>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $moduleIndex = 0; ?>
                            <?php foreach ($grouped as $module => $perms): ?>
                            <tr class="module-header" style="background:var(--bg-light);">
                                <td colspan="<?= count($roles) + 1 ?>" style="padding:10px 12px;font-weight:700;">
                                    <?= $moduleNames[$module] ?? $module ?>
                                </td>
                            </tr>
                            <?php foreach ($perms as $perm): ?>
                            <tr>
                                <td style="padding:6px 12px;">
                                    <?= e($perm['name_ar']) ?>
                                    <br><small style="color:var(--text-muted);"><?= e($perm['name']) ?></small>
                                </td>
                                <?php foreach ($roles as $role): ?>
                                <?php
                                    $isChecked = isset($rpMap[$role['id']][$perm['id']]) && $rpMap[$role['id']][$perm['id']] === 1;
                                    $isDisabled = ($role['name'] === 'group_admin') ? '' : '';
                                ?>
                                <td class="text-center" style="padding:6px;">
                                    <label class="checkbox-inline" style="cursor:pointer;display:block;padding:4px;">
                                        <input type="checkbox"
                                               name="perm[<?= $role['id'] ?>][]"
                                               value="<?= $perm['id'] ?>"
                                               <?= $isChecked ? 'checked' : '' ?>
                                               class="matrix-checkbox"
                                               data-role="<?= e($role['name']) ?>"
                                               data-perm="<?= e($perm['name']) ?>">
                                    </label>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php $moduleIndex++; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:1.5rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="save" value="1" class="btn btn-primary">
                        💾 حفظ التغييرات
                    </button>
                    <button type="button" class="btn btn-secondary" id="checkAllBtn">
                        ✅ تحديد الكل
                    </button>
                    <button type="button" class="btn btn-secondary" id="uncheckAllBtn">
                        ❌ إلغاء الكل
                    </button>
                    <span style="color:var(--text-muted);font-size:0.85rem;" id="statsSpan">
                        مجموع التحديدات: 0
                    </span>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
#permissionsMatrix th,
#permissionsMatrix td {
    vertical-align: middle !important;
    border: 1px solid var(--border-color, #dee2e6);
}
#permissionsMatrix .module-header td {
    background: #f0f4f8;
    font-size: 0.95rem;
}
#permissionsMatrix .checkbox-inline input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #1a5632;
}
#permissionsMatrix tr:hover td {
    background: rgba(26, 86, 50, 0.03);
}
#permissionsMatrix .module-header:hover td {
    background: #e8edf3;
}
</style>

<script>
(function() {
    var checkboxes = document.querySelectorAll('.matrix-checkbox');
    var statsSpan = document.getElementById('statsSpan');

    function updateStats() {
        var checked = document.querySelectorAll('.matrix-checkbox:checked').length;
        statsSpan.textContent = 'مجموع التحديدات: ' + checked;
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateStats);
    });

    document.getElementById('checkAllBtn').addEventListener('click', function() {
        checkboxes.forEach(function(cb) { cb.checked = true; });
        updateStats();
    });

    document.getElementById('uncheckAllBtn').addEventListener('click', function() {
        checkboxes.forEach(function(cb) { cb.checked = false; });
        updateStats();
    });

    updateStats();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
