<?php
/**
 * ============================================================
 * EQAS - إعدادات النظام - لوحة التحكم
 * نظام التقييم الشامل - صفحة الإعدادات الرئيسية
 * ============================================================
 */

$pageTitle = 'إعدادات النظام';
$breadcrumb = ['إعدادات النظام' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// جلب فئات الإعدادات مع عدد الإعدادات في كل فئة
$stmt = $db->query("SELECT category, COUNT(*) as setting_count,
                    GROUP_CONCAT(DISTINCT name_ar SEPARATOR ' | ') as sample_names
                    FROM system_settings
                    WHERE is_active = 1 AND school_id IS NULL
                    GROUP BY category
                    ORDER BY MIN(sort_order), category");
$categories = $stmt->fetchAll();

// أسماء الفئات بالعربية
$categoryNames = [
    'general' => ['label' => 'عام', 'icon' => '🌐', 'color' => 'primary'],
    'academic' => ['label' => 'أكاديمي', 'icon' => '📚', 'color' => 'info'],
    'grading' => ['label' => 'الدرجات', 'icon' => '📊', 'color' => 'success'],
    'attendance' => ['label' => 'الحضور', 'icon' => '📅', 'color' => 'warning'],
    'behavior' => ['label' => 'السلوك', 'icon' => '📋', 'color' => 'danger'],
    'skills' => ['label' => 'المهارات', 'icon' => '🎯', 'color' => 'info'],
    'teaching' => ['label' => 'التدريس', 'icon' => '👨‍🏫', 'color' => 'primary'],
    'climate' => ['label' => 'المناخ المدرسي', 'icon' => '🌤️', 'color' => 'success'],
    'facility' => ['label' => 'المرافق', 'icon' => '🏫', 'color' => 'secondary'],
    'eqi' => ['label' => 'مؤشر الجودة', 'icon' => '📈', 'color' => 'success'],
    'promotion' => ['label' => 'الترقية', 'icon' => '🎓', 'color' => 'warning'],
    'alerts' => ['label' => 'التنبيهات', 'icon' => '🚨', 'color' => 'danger'],
    'notifications' => ['label' => 'الإشعارات', 'icon' => '📨', 'color' => 'info'],
    'backup' => ['label' => 'النسخ الاحتياطي', 'icon' => '💾', 'color' => 'secondary'],
    'export' => ['label' => 'التصدير', 'icon' => '📤', 'color' => 'success'],
    'appearance' => ['label' => 'المظهر', 'icon' => '🎨', 'color' => 'primary'],
    'security' => ['label' => 'الأمان', 'icon' => '🔒', 'color' => 'danger'],
    'parent_portal' => ['label' => 'بوابة ولي الأمر', 'icon' => '👨‍👩‍👧', 'color' => 'info'],
    'dashboard' => ['label' => 'لوحة المعلومات', 'icon' => '📊', 'color' => 'primary'],
    'system' => ['label' => 'النظام', 'icon' => '⚙️', 'color' => 'secondary'],
];

// بحث في الإعدادات
$searchQuery = $_GET['search'] ?? '';
$searchResults = [];
if ($searchQuery) {
    $stmt = $db->prepare("SELECT id, category, setting_key, name_ar, setting_value, value_type
                          FROM system_settings
                          WHERE is_active = 1 AND school_id IS NULL
                          AND (name_ar LIKE ? OR setting_key LIKE ? OR setting_value LIKE ?)
                          ORDER BY category, sort_order
                          LIMIT 20");
    $stmt->execute(["%$searchQuery%", "%$searchQuery%", "%$searchQuery%"]);
    $searchResults = $stmt->fetchAll();
}
?>

<!-- روابط سريعة للتنقل بين فئات الإعدادات -->
<style>
    .quick-links-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        direction: rtl;
    }
    .quick-link-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: var(--radius-md, 8px);
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        border: 1px solid var(--border-light, #e2e8f0);
        background: var(--bg-primary, #ffffff);
        color: var(--text-primary, #1e293b);
        transition: all 0.2s ease;
        cursor: pointer;
        white-space: nowrap;
    }
    .quick-link-chip:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: var(--primary, #3b82f6);
        color: var(--primary, #3b82f6);
    }
    .quick-link-chip .chip-icon {
        font-size: 15px;
        line-height: 1;
    }
    .quick-link-chip .chip-count {
        font-size: 11px;
        background: var(--border-light, #e2e8f0);
        border-radius: 10px;
        padding: 1px 7px;
        color: var(--text-secondary, #64748b);
        margin-right: -4px;
        margin-left: 2px;
    }
    .quick-link-chip.chip-primary  { border-color: #93c5fd; background: #eff6ff; }
    .quick-link-chip.chip-primary:hover  { background: #dbeafe; color: #2563eb; border-color: #3b82f6; }
    .quick-link-chip.chip-info     { border-color: #93c5fd; background: #eff6ff; }
    .quick-link-chip.chip-info:hover     { background: #dbeafe; color: #2563eb; border-color: #3b82f6; }
    .quick-link-chip.chip-success  { border-color: #86efac; background: #f0fdf4; }
    .quick-link-chip.chip-success:hover  { background: #dcfce7; color: #16a34a; border-color: #22c55e; }
    .quick-link-chip.chip-warning  { border-color: #fde68a; background: #fffbeb; }
    .quick-link-chip.chip-warning:hover  { background: #fef3c7; color: #d97706; border-color: #f59e0b; }
    .quick-link-chip.chip-danger   { border-color: #fca5a5; background: #fef2f2; }
    .quick-link-chip.chip-danger:hover   { background: #fee2e2; color: #dc2626; border-color: #ef4444; }
    .quick-link-chip.chip-secondary{ border-color: #d1d5db; background: #f9fafb; }
    .quick-link-chip.chip-secondary:hover{ background: #f3f4f6; color: #4b5563; border-color: #9ca3af; }
    html { scroll-behavior: smooth; }
</style>
<div class="card mb-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">⚡ الوصول السريع للفئات</h3>
        <span class="text-muted" style="font-size:12px;"><?php echo count($categories); ?> فئة</span>
    </div>
    <div class="card-body">
        <div class="quick-links-nav">
            <?php foreach ($categories as $qlCat): ?>
            <?php
            $qlInfo = $categoryNames[$qlCat['category']] ?? ['label' => $qlCat['category'], 'icon' => '📄', 'color' => 'secondary'];
            ?>
            <a href="#category-<?php echo e($qlCat['category']); ?>" class="quick-link-chip chip-<?php echo e($qlInfo['color']); ?>" title="<?php echo e($qlInfo['label']); ?>">
                <span class="chip-icon"><?php echo $qlInfo['icon']; ?></span>
                <span><?php echo e($qlInfo['label']); ?></span>
                <span class="chip-count"><?php echo $qlCat['setting_count']; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">🔍 بحث في الإعدادات</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control" value="<?php echo e($searchQuery); ?>"
                   placeholder="ابحث عن إعداد..." style="max-width:400px;">
            <button type="submit" class="btn btn-primary">🔍 بحث</button>
            <?php if ($searchQuery): ?>
            <a href="index.php" class="btn btn-light">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($searchQuery && !empty($searchResults)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">📋 نتائج البحث (<?php echo count($searchResults); ?>)</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>الفئة</th>
                        <th>الإعداد</th>
                        <th>القيمة الحالية</th>
                        <th>النوع</th>
                        <th>الإجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchResults as $sr): ?>
                    <tr>
                        <td>
                            <?php $catInfo = $categoryNames[$sr['category']] ?? ['label' => $sr['category'], 'icon' => '📄']; ?>
                            <span class="badge badge-<?php echo $catInfo['color'] ?? 'secondary'; ?>">
                                <?php echo $catInfo['icon']; ?> <?php echo e($catInfo['label']); ?>
                            </span>
                        </td>
                        <td><?php echo e($sr['name_ar']); ?></td>
                        <td><code><?php echo e(truncate($sr['setting_value'], 50)); ?></code></td>
                        <td><span class="badge badge-light"><?php echo e($sr['value_type']); ?></span></td>
                        <td>
                            <a href="category.php?cat=<?php echo e($sr['category']); ?>&highlight=<?php echo $sr['id']; ?>" class="btn btn-sm btn-outline-primary">
                                ✏️ تعديل
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">⚙️ فئات الإعدادات</h2>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($categories as $cat): ?>
            <?php
            $catInfo = $categoryNames[$cat['category']] ?? ['label' => $cat['category'], 'icon' => '📄', 'color' => 'secondary'];
            ?>
            <div class="col-md-3 col-sm-6 mb-3" id="category-<?php echo e($cat['category']); ?>">
                <a href="category.php?cat=<?php echo e($cat['category']); ?>" class="card border h-100" style="text-decoration:none;display:block;padding:16px;transition:all 0.2s;border-radius:8px;">
                    <div style="text-align:center;">
                        <div style="font-size:36px;margin-bottom:8px;"><?php echo $catInfo['icon']; ?></div>
                        <h5 style="margin:0;font-size:14px;color:var(--text-primary);"><?php echo e($catInfo['label']); ?></h5>
                        <div class="text-muted" style="font-size:12px;margin-top:4px;">
                            <?php echo $cat['setting_count']; ?> إعداد
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (hasPermission('group_admin')): ?>
<div class="card mt-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">🏫 إعدادات خاصة بالمدرسة</h2>
    </div>
    <div class="card-body">
        <p class="text-muted">يمكنك تجاوز إعدادات النظام الافتراضية لمدرسة محددة.</p>
        <a href="school_settings.php" class="btn btn-info">🏫 إدارة إعدادات المدارس</a>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
