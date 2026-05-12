<?php
/**
 * ============================================================
 * EQAS - التنبيهات الذكية - لوحة التحكم
 * نظام التقييم الشامل - Alerts Dashboard
 * ============================================================
 */

$pageTitle = 'التنبيهات الذكية';
$breadcrumb = ['التنبيهات الذكية' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/alerts.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('index.php');
}

$alertEngine = new AlertEngine($db);

// الفلاتر
$filters = [
    'school_id' => $_GET['school_id'] ?? '',
    'alert_type' => $_GET['alert_type'] ?? '',
    'severity' => $_GET['severity'] ?? '',
    'is_read' => $_GET['is_read'] ?? '',
    'is_resolved' => $_GET['is_resolved'] ?? '',
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'sort' => $_GET['sort'] ?? 'created_at',
    'dir' => $_GET['dir'] ?? 'DESC',
];

// تطبيق فلتر المدرسة حسب الدور
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
if (in_array($userRole, ['school_admin', 'teacher']) && $userSchoolId) {
    $filters['school_id'] = $userSchoolId;
}

$alerts = $alertEngine->getAlerts($filters);
$totalAlerts = $alertEngine->getAlertsCount($filters);
$stats = $alertEngine->getAlertStats($userSchoolId);
$unreadCount = $alertEngine->getUnreadCount($_SESSION['user']['id'] ?? null);

// الحصول على قائمة المدارس
$schools = [];
try {
    $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    $schools = $stmt->fetchAll();
} catch (Exception $e) {}

// معالجة تحديد كمقروء
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
    } else {
        if ($_POST['action'] === 'mark_read' && !empty($_POST['alert_id'])) {
            $alertEngine->markAsRead((int)$_POST['alert_id']);
            setFlash('تم تحديد التنبيه كمقروء.', 'success');
        } elseif ($_POST['action'] === 'resolve' && !empty($_POST['alert_id'])) {
            $alertEngine->resolveAlert((int)$_POST['alert_id'], $_SESSION['user']['id']);
            setFlash('تم حل التنبيه بنجاح.', 'success');
        }
        redirect('index.php?' . http_build_query(array_filter($filters)));
    }
}
?>

<div class="section">
    <!-- إحصائيات سريعة -->
    <div class="grid grid-4 mb-6">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">🔔</div>
                <div style="font-size:24px; font-weight:bold; color:var(--primary);"><?= $stats['total'] ?></div>
                <div class="text-secondary" style="font-size:14px;">إجمالي التنبيهات</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">📩</div>
                <div style="font-size:24px; font-weight:bold; color:var(--warning);"><?= $stats['unread'] ?></div>
                <div class="text-secondary" style="font-size:14px;">غير مقروءة</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">⚠️</div>
                <div style="font-size:24px; font-weight:bold; color:var(--danger);"><?= $stats['unresolved'] ?></div>
                <div class="text-secondary" style="font-size:14px;">غير محلولة</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:32px;">🔥</div>
                <div style="font-size:24px; font-weight:bold; color:var(--accent);"><?= $stats['by_severity']['critical'] ?? 0 ?></div>
                <div class="text-secondary" style="font-size:14px;">حرجة</div>
            </div>
        </div>
    </div>

    <!-- توزيع حسب النوع -->
    <div class="grid grid-2 mb-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">التوزيع حسب النوع</h3>
            </div>
            <div class="card-body">
                <div class="flex flex-col gap-3">
                    <?php 
                    $typeLabels = ['academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور', 'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع'];
                    $typeIcons = ['academic' => '📚', 'behavior' => '⭐', 'attendance' => '📋', 'eqi' => '📊', 'promotion' => '📈'];
                    $typeColors = ['academic' => '#17a2b8', 'behavior' => '#ffc107', 'attendance' => '#dc3545', 'eqi' => '#28a745', 'promotion' => '#6f42c1'];
                    foreach ($typeLabels as $type => $label): 
                        $count = $stats['by_type'][$type] ?? 0;
                        $percentage = $stats['total'] > 0 ? round(($count / $stats['total']) * 100) : 0;
                    ?>
                    <div class="flex items-center gap-3">
                        <span style="font-size:20px;"><?= $typeIcons[$type] ?></span>
                        <div class="flex-1">
                            <div class="flex justify-between mb-1">
                                <span style="font-size:13px;"><?= $label ?></span>
                                <span style="font-size:13px; font-weight:600;"><?= $count ?> (<?= $percentage ?>%)</span>
                            </div>
                            <div style="background:var(--border-light); border-radius:var(--radius-full); height:8px; overflow:hidden;">
                                <div style="width:<?= $percentage ?>%; background:<?= $typeColors[$type] ?>; height:100%; border-radius:var(--radius-full); transition:width 0.3s;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">التوزيع حسب الخطورة</h3>
            </div>
            <div class="card-body">
                <div class="flex flex-col gap-3">
                    <?php 
                    $severityData = [
                        'critical' => ['label' => 'حرج', 'icon' => '🔴', 'color' => '#dc3545'],
                        'danger' => ['label' => 'خطر', 'icon' => '🟠', 'color' => '#fd7e14'],
                        'warning' => ['label' => 'تحذير', 'icon' => '🟡', 'color' => '#ffc107'],
                        'info' => ['label' => 'معلوماتي', 'icon' => '🔵', 'color' => '#17a2b8'],
                    ];
                    foreach ($severityData as $sev => $info): 
                        $count = $stats['by_severity'][$sev] ?? 0;
                        $percentage = $stats['total'] > 0 ? round(($count / $stats['total']) * 100) : 0;
                    ?>
                    <div class="flex items-center gap-3">
                        <span style="font-size:20px;"><?= $info['icon'] ?></span>
                        <div class="flex-1">
                            <div class="flex justify-between mb-1">
                                <span style="font-size:13px;"><?= $info['label'] ?></span>
                                <span style="font-size:13px; font-weight:600;"><?= $count ?> (<?= $percentage ?>%)</span>
                            </div>
                            <div style="background:var(--border-light); border-radius:var(--radius-full); height:8px; overflow:hidden;">
                                <div style="width:<?= $percentage ?>%; background:<?= $info['color'] ?>; height:100%; border-radius:var(--radius-full);"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- فلاتر -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🔍 تصفية التنبيهات</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row">
                <?php if ($userRole === 'group_admin'): ?>
                <div class="form-group">
                    <label class="form-label">المدرسة</label>
                    <select name="school_id" class="form-control">
                        <option value="">جميع المدارس</option>
                        <?php foreach ($schools as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filters['school_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">النوع</label>
                    <select name="alert_type" class="form-control">
                        <option value="">جميع الأنواع</option>
                        <option value="academic" <?= $filters['alert_type'] === 'academic' ? 'selected' : '' ?>>أكاديمي</option>
                        <option value="behavior" <?= $filters['alert_type'] === 'behavior' ? 'selected' : '' ?>>سلوكي</option>
                        <option value="attendance" <?= $filters['alert_type'] === 'attendance' ? 'selected' : '' ?>>حضور</option>
                        <option value="eqi" <?= $filters['alert_type'] === 'eqi' ? 'selected' : '' ?>>مؤشر الجودة</option>
                        <option value="promotion" <?= $filters['alert_type'] === 'promotion' ? 'selected' : '' ?>>ترفيع</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الخطورة</label>
                    <select name="severity" class="form-control">
                        <option value="">جميع المستويات</option>
                        <option value="critical" <?= $filters['severity'] === 'critical' ? 'selected' : '' ?>>حرج</option>
                        <option value="danger" <?= $filters['severity'] === 'danger' ? 'selected' : '' ?>>خطر</option>
                        <option value="warning" <?= $filters['severity'] === 'warning' ? 'selected' : '' ?>>تحذير</option>
                        <option value="info" <?= $filters['severity'] === 'info' ? 'selected' : '' ?>>معلوماتي</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة</label>
                    <select name="is_read" class="form-control">
                        <option value="">الكل</option>
                        <option value="0" <?= $filters['is_read'] === '0' ? 'selected' : '' ?>>غير مقروءة</option>
                        <option value="1" <?= $filters['is_read'] === '1' ? 'selected' : '' ?>>مقروءة</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">🔍 تصفية</button>
                    <a href="index.php" class="btn btn-light">إعادة تعيين</a>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة التنبيهات -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">قائمة التنبيهات (<?= $totalAlerts ?>)</h3>
            <div class="flex gap-2">
                <a href="run_checks.php" class="btn btn-primary btn-sm">▶️ تشغيل الفحوصات</a>
                <a href="bulk_resolve.php" class="btn btn-warning btn-sm">✅ حل جماعي</a>
                <a href="export.php" class="btn btn-info btn-sm">📥 تصدير</a>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($alerts)): ?>
            <div class="table-empty">
                <div class="table-empty-icon">🔔</div>
                <div class="table-empty-text">لا توجد تنبيهات حالياً</div>
                <p class="text-secondary">ستظهر التنبيهات هنا عند اكتشاف مشاكل في بيانات التقييم</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الخطورة</th>
                            <th>النوع</th>
                            <th>العنوان</th>
                            <th>الطالب</th>
                            <th>المدرسة</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $alert): 
                            $severityColors = [
                                'critical' => 'background:#dc3545; color:#fff;',
                                'danger' => 'background:#fd7e14; color:#fff;',
                                'warning' => 'background:#ffc107; color:#000;',
                                'info' => 'background:#17a2b8; color:#fff;',
                            ];
                            $severityLabels = ['critical' => 'حرج', 'danger' => 'خطر', 'warning' => 'تحذير', 'info' => 'معلوماتي'];
                            $typeIcons = ['academic' => '📚', 'behavior' => '⭐', 'attendance' => '📋', 'eqi' => '📊', 'promotion' => '📈'];
                            $typeLabels = ['academic' => 'أكاديمي', 'behavior' => 'سلوكي', 'attendance' => 'حضور', 'eqi' => 'مؤشر الجودة', 'promotion' => 'ترفيع'];
                        ?>
                        <tr style="<?= !$alert['is_read'] ? 'background:rgba(26,86,50,0.04);font-weight:600;' : '' ?>">
                            <td>
                                <span style="padding:4px 10px; border-radius:var(--radius-full); font-size:12px; <?= $severityColors[$alert['severity']] ?? '' ?>">
                                    <?= $severityLabels[$alert['severity']] ?? $alert['severity'] ?>
                                </span>
                            </td>
                            <td>
                                <span><?= $typeIcons[$alert['alert_type']] ?? '' ?> <?= $typeLabels[$alert['alert_type']] ?? $alert['alert_type'] ?></span>
                            </td>
                            <td>
                                <a href="view.php?id=<?= $alert['id'] ?>" style="color:var(--primary); font-weight:500;">
                                    <?= e(truncate($alert['title_ar'], 60)) ?>
                                </a>
                            </td>
                            <td><?= e($alert['student_name'] ?? '-') ?></td>
                            <td><?= e($alert['school_name'] ?? '-') ?></td>
                            <td>
                                <?php if ($alert['is_resolved']): ?>
                                    <span style="color:var(--success);">✅ محلول</span>
                                <?php elseif (!$alert['is_read']): ?>
                                    <span style="color:var(--warning);">📩 جديد</span>
                                <?php else: ?>
                                    <span style="color:var(--text-secondary);">👁️ مقروء</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; white-space:nowrap;"><?= formatDateAr($alert['created_at'], 'short') ?></td>
                            <td>
                                <div class="flex gap-1">
                                    <?php if (!$alert['is_read']): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-info" title="تحديد كمقروء" style="padding:4px 8px; font-size:11px;">👁️</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$alert['is_resolved']): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="resolve">
                                        <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="حل التنبيه" style="padding:4px 8px; font-size:11px;">✅</button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="view.php?id=<?= $alert['id'] ?>" class="btn btn-sm btn-light" title="عرض" style="padding:4px 8px; font-size:11px;">👁️ عرض</a>
                                </div>
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
