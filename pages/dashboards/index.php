<?php
/**
 * ============================================================
 * EQAS - تخصيص لوحة التحكم
 * نظام التقييم الشامل - صفحة تخصيص لوحة المعلومات
 * ============================================================
 */

$pageTitle = 'تخصيص لوحة التحكم';
$breadcrumb = ['تخصيص لوحة التحكم' => null];

require_once __DIR__ . '/../../includes/header.php';

requireLogin();

// جميع الأدوار يمكنهم تخصيص لوحتهم
$userId = $_SESSION['user']['id']; // @session-migration-v2.0.3
$userRole = $_SESSION['user']['role'] ?? '';

// العناصر المتاحة حسب الدور
$availableWidgets = [
    'eqi_overview' => ['label' => 'نظرة عامة على مؤشر الجودة', 'icon' => '📈', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'alerts' => ['label' => 'التنبيهات الذكية', 'icon' => '🚨', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'attendance_chart' => ['label' => 'رسم بياني للحضور', 'icon' => '📅', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'recent_grades' => ['label' => 'آخر الدرجات', 'icon' => '📊', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'student_stats' => ['label' => 'إحصائيات الطلاب', 'icon' => '👥', 'roles' => ['group_admin', 'school_admin']],
    'behavior_summary' => ['label' => 'ملخص السلوك', 'icon' => '📋', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'school_comparison' => ['label' => 'مقارنة المدارس', 'icon' => '🏫', 'roles' => ['group_admin']],
    'promotion_status' => ['label' => 'حالة الترفيع', 'icon' => '🎓', 'roles' => ['group_admin', 'school_admin']],
    'quick_actions' => ['label' => 'إجراءات سريعة', 'icon' => '⚡', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'calendar' => ['label' => 'التقويم', 'icon' => '🗓️', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'notes' => ['label' => 'الملاحظات', 'icon' => '📝', 'roles' => ['group_admin', 'school_admin', 'teacher']],
    'notifications' => ['label' => 'آخر الإشعارات', 'icon' => '📨', 'roles' => ['group_admin', 'school_admin', 'teacher', 'parent']],
    'child_grades' => ['label' => 'درجات الأبناء', 'icon' => '🎓', 'roles' => ['parent']],
    'child_attendance' => ['label' => 'حضور الأبناء', 'icon' => '📅', 'roles' => ['parent']],
    'child_behavior' => ['label' => 'سلوك الأبناء', 'icon' => '📋', 'roles' => ['parent']],
    'parent_ack' => ['label' => 'الإقرارات المعلقة', 'icon' => '✅', 'roles' => ['parent']],
];

// تصفية العناصر حسب الدور
$userWidgets = array_filter($availableWidgets, function($w) use ($userRole) {
    return in_array($userRole, $w['roles']);
});

// جلب العناصر الحالية للمستخدم
$stmt = $db->prepare("SELECT * FROM dashboard_widgets WHERE user_id = ? AND is_active = 1 ORDER BY position");
$stmt->execute([$userId]);
$currentWidgets = $stmt->fetchAll();

// بناء قائمة العناصر الحالية
$currentWidgetTypes = array_column($currentWidgets, 'widget_type');

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('index.php');
    }

    if (isset($_POST['save_layout'])) {
        $widgetOrder = $_POST['widget_order'] ?? [];
        $widgetVisibility = $_POST['widget_visibility'] ?? [];

        // حذف العناصر الحالية
        $stmt = $db->prepare("DELETE FROM dashboard_widgets WHERE user_id = ?");
        $stmt->execute([$userId]);

        // إضافة العناصر الجديدة
        $position = 0;
        foreach ($widgetOrder as $widgetType) {
            if (!isset($userWidgets[$widgetType])) continue;

            $isVisible = isset($widgetVisibility[$widgetType]) ? 1 : 0;
            $title = $userWidgets[$widgetType]['label'];

            $stmt = $db->prepare("INSERT INTO dashboard_widgets (user_id, widget_type, title_ar, config, position, is_visible, is_active, created_at, updated_at)
                                  VALUES (?, ?, ?, NULL, ?, ?, 1, NOW(), NOW())");
            $stmt->execute([$userId, $widgetType, $title, $position, $isVisible]);
            $position++;
        }

        logActivity($db, $userId, 'dashboard_customize', 'تخصيص لوحة التحكم', 'dashboard', null);
        setFlash('تم حفظ تخصيص لوحة التحكم بنجاح', 'success');
        redirect('index.php');
    }

    if (isset($_POST['reset_layout'])) {
        $stmt = $db->prepare("DELETE FROM dashboard_widgets WHERE user_id = ?");
        $stmt->execute([$userId]);

        // إضافة العناصر الافتراضية
        $defaultWidgets = ['eqi_overview', 'alerts', 'attendance_chart', 'recent_grades'];
        $position = 0;
        foreach ($defaultWidgets as $widgetType) {
            if (!isset($userWidgets[$widgetType])) continue;

            $title = $userWidgets[$widgetType]['label'];
            $stmt = $db->prepare("INSERT INTO dashboard_widgets (user_id, widget_type, title_ar, config, position, is_visible, is_active, created_at, updated_at)
                                  VALUES (?, ?, ?, NULL, ?, 1, 1, NOW(), NOW())");
            $stmt->execute([$userId, $widgetType, $title, $position]);
            $position++;
        }

        setFlash('تم إعادة تعيين لوحة التحكم إلى الافتراضي', 'success');
        redirect('index.php');
    }
}

// بناء قائمة العناصر المطلوبة للعرض
$displayWidgets = [];
// أولاً العناصر الحالية بالترتيب
foreach ($currentWidgets as $cw) {
    if (isset($userWidgets[$cw['widget_type']])) {
        $displayWidgets[] = [
            'type' => $cw['widget_type'],
            'label' => $cw['title_ar'] ?: $userWidgets[$cw['widget_type']]['label'],
            'icon' => $userWidgets[$cw['widget_type']]['icon'],
            'is_visible' => $cw['is_visible'],
            'position' => $cw['position'],
            'active' => true
        ];
    }
}
// ثم العناصر المتاحة غير المضافة
foreach ($userWidgets as $type => $info) {
    if (!in_array($type, array_column($displayWidgets, 'type'))) {
        $displayWidgets[] = [
            'type' => $type,
            'label' => $info['label'],
            'icon' => $info['icon'],
            'is_visible' => 1,
            'position' => count($displayWidgets),
            'active' => false
        ];
    }
}
?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">🎨 تخصيص لوحة التحكم</h2>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="togglePreview()">
                👁️ معاينة
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <strong>ℹ️ تخصيص لوحة التحكم:</strong>
            قم بترتيب العناصر وإظهار/إخفاء ما تريده. اسحب العناصر لإعادة ترتيبها.
            التغييرات تظهر على الرئيسية.
        </div>

        <form method="POST" id="dashboard-form">
            <?= csrfField() ?>

            <!-- العناصر المتاحة -->
            <h4 class="mb-3">📦 العناصر المتاحة</h4>
            <p class="text-muted text-sm mb-3">حدد العناصر التي تريد عرضها في لوحة التحكم ورتبها بالسحب.</p>

            <div id="widget-list" class="mb-4">
                <?php foreach ($displayWidgets as $widget): ?>
                <div class="card border mb-2 widget-item" data-widget="<?php echo e($widget['type']); ?>"
                     style="cursor:move;transition:all 0.2s;">
                    <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span class="drag-handle" style="cursor:grab;font-size:18px;color:#999;">☰</span>
                            <span style="font-size:20px;"><?php echo $widget['icon']; ?></span>
                            <div>
                                <strong style="font-size:14px;"><?php echo e($widget['label']); ?></strong>
                                <br><code style="font-size:10px;"><?php echo e($widget['type']); ?></code>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <label class="switch" style="margin:0;">
                                <input type="checkbox" name="widget_visibility[<?php echo e($widget['type']); ?>]" value="1"
                                       <?php echo $widget['is_visible'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <input type="hidden" name="widget_order[]" value="<?php echo e($widget['type']); ?>">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4" style="display:flex;gap:8px;">
                <button type="submit" name="save_layout" value="1" class="btn btn-primary btn-lg">
                    💾 حفظ التخطيط
                </button>
                <button type="submit" name="reset_layout" value="1" class="btn btn-outline-secondary btn-lg"
                        onclick="return confirm('هل تريد إعادة تعيين لوحة التحكم إلى الافتراضي؟');">
                    🔄 إعادة تعيين
                </button>
                <a href="../index.php" class="btn btn-light btn-lg">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<!-- معاينة -->
<div id="preview-panel" style="display:none;" class="card mt-4">
    <div class="card-header bg-primary text-white">
        <h3 class="mb-0 text-white">👁️ معاينة لوحة التحكم</h3>
    </div>
    <div class="card-body">
        <div class="row" id="preview-widgets">
            <!-- يتم تعبئتها بالجافاسكريبت -->
        </div>
    </div>
</div>

<style>
.switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 22px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 22px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    right: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: var(--primary, #16a34a);
}
input:checked + .slider:before {
    transform: translateX(-22px);
}
.widget-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.widget-item.dragging {
    opacity: 0.5;
}
</style>

<script>
// تبديل المعاينة
function togglePreview() {
    const panel = document.getElementById('preview-panel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    if (panel.style.display === 'block') updatePreview();
}

// تحديث المعاينة
function updatePreview() {
    const container = document.getElementById('preview-widgets');
    const items = document.querySelectorAll('.widget-item');
    let html = '';

    items.forEach(item => {
        const type = item.dataset.widget;
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            const icon = item.querySelector('[style*="font-size:20px"]').textContent;
            const label = item.querySelector('strong').textContent;
            html += '<div class="col-md-6 col-lg-4 mb-3">';
            html += '<div class="card border" style="min-height:120px;">';
            html += '<div class="card-body text-center">';
            html += '<div style="font-size:32px;">' + icon + '</div>';
            html += '<div style="font-weight:600;margin-top:8px;">' + label + '</div>';
            html += '<div style="color:#999;font-size:12px;">محتوى العنصر</div>';
            html += '</div></div></div>';
        }
    });

    container.innerHTML = html || '<p class="text-muted text-center">لا توجد عناصر مرئية</p>';
}

// سحب وإفلات بسيط
const widgetList = document.getElementById('widget-list');
if (widgetList) {
    let draggedItem = null;

    widgetList.addEventListener('dragstart', function(e) {
        if (e.target.classList.contains('widget-item')) {
            draggedItem = e.target;
            e.target.classList.add('dragging');
        }
    });

    widgetList.addEventListener('dragend', function(e) {
        if (draggedItem) {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
        }
    });

    widgetList.addEventListener('dragover', function(e) {
        e.preventDefault();
        const afterElement = getDragAfterElement(widgetList, e.clientY);
        if (draggedItem) {
            if (afterElement == null) {
                widgetList.appendChild(draggedItem);
            } else {
                widgetList.insertBefore(draggedItem, afterElement);
            }
        }
    });

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.widget-item:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    // جعل العناصر قابلة للسحب
    document.querySelectorAll('.widget-item').forEach(item => {
        item.draggable = true;
    });
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
