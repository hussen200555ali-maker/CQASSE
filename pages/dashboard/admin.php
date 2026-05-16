<?php
/**
 * ============================================================
 * EQAS - لوحة تحكم مدير النظام
 * نظام التقييم الشامل - Group Admin Dashboard
 * ============================================================
 * لوحة تحكم مدير النظام:
 * - إحصائيات على مستوى النظام
 * - مقارنة مؤشر جودة التعليم بين المدارس
 * - وصول سريع لإدارة المستخدمين
 * - تنبيهات النظام والأخطاء
 * - حالة النسخ الاحتياطي
 * - سجل النشاطات
 * ============================================================
 */

$pageTitle = 'لوحة تحكم مدير النظام';
$breadcrumb = ['الرئيسية' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الدور
requireRole('group_admin');

// ═══════════════════════════════════════════
// جلب الإحصائيات
// ═══════════════════════════════════════════
$stats = [
    'schools' => 0, 'students' => 0, 'teachers' => 0,
    'classes' => 0, 'users' => 0, 'active_alerts' => 0
];

try {
    $stats['schools'] = $db->query("SELECT COUNT(*) FROM schools WHERE is_active = 1")->fetchColumn();
    $stats['students'] = $db->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    $stats['teachers'] = $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.is_active = 1")->fetchColumn();
    $stats['classes'] = $db->query("SELECT COUNT(*) FROM classes WHERE is_active = 1")->fetchColumn();
    $stats['users'] = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $stats['active_alerts'] = $db->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1 AND status = 'open'")->fetchColumn();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// مقارنة مؤشر جودة التعليم بين المدارس
// ═══════════════════════════════════════════
require_once __DIR__ . '/../../includes/contextual_adjustment.php';

$currentYearId = currentAcademicYearId();
$currentSemId = currentSemesterId();

$schoolEqi = [];
$yearlyTrends = [];
try {
    // جلب مؤشرات الفصل الحالي
    $stmt = $db->prepare("
        SELECT s.id, s.name_ar,
               COALESCE(eq.overall_eqi, 0) as eqi_score,
               eq.overall_eqi as raw_eqi
        FROM schools s
        LEFT JOIN equi_results eq ON s.id = eq.school_id
            AND eq.semester_id = ?
            AND eq.academic_year_id = ?
            AND eq.is_active = 1
        WHERE s.is_active = 1
        ORDER BY eqi_score DESC
        LIMIT 20
    ");
    $stmt->execute([$currentSemId, $currentYearId]);
    $schoolEqi = $stmt->fetchAll();

    // جلب مؤشرات نفس الفصل من العام السابق للمقارنة السنوية
    if (!empty($schoolEqi)) {
        $prevYearId = null;
        $prevStmt = $db->prepare("
            SELECT id FROM academic_years
            WHERE id < ? AND is_active = 1
            ORDER BY start_date DESC LIMIT 1
        ");
        $prevStmt->execute([$currentYearId]);
        $prevYearId = $prevStmt->fetchColumn();

        if ($prevYearId !== false) {
            $prevSemStmt = $db->prepare("
                SELECT s2.id FROM semesters s2
                JOIN semesters s1 ON s1.id = ?
                WHERE s2.academic_year_id = ?
                    AND s2.name_ar = s1.name_ar
                    AND s2.is_active = 1
                LIMIT 1
            ");
            $prevSemStmt->execute([$currentSemId, $prevYearId]);
            $prevSemId = $prevSemStmt->fetchColumn();

            if ($prevSemId !== false) {
                $trendStmt = $db->prepare("
                    SELECT school_id, overall_eqi
                    FROM equi_results
                    WHERE semester_id = ? AND academic_year_id = ? AND is_active = 1
                ");
                $trendStmt->execute([$prevSemId, $prevYearId]);
                while ($row = $trendStmt->fetch()) {
                    $yearlyTrends[$row['school_id']] = $row['overall_eqi'];
                }
            }
        }
    }
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// حالة النسخ الاحتياطي
// ═══════════════════════════════════════════
$backupStats = ['last_full' => null, 'total_backups' => 0, 'total_size' => 0];
try {
    $stmt = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(file_size),0) as total_size FROM backup_logs WHERE is_active = 1 AND status = 'completed'");
    $row = $stmt->fetch();
    $backupStats['total_backups'] = $row['total'];
    $backupStats['total_size'] = $row['total_size'];

    $stmt = $db->query("SELECT created_at FROM backup_logs WHERE backup_type = 'full' AND is_active = 1 AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
    $row = $stmt->fetch();
    $backupStats['last_full'] = $row ? $row['created_at'] : null;
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// سجل النشاطات الأخيرة
// ═══════════════════════════════════════════
$recentActivities = [];
try {
    $stmt = $db->query("
        SELECT al.*, u.full_name_ar as user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $recentActivities = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// إحصائيات المستخدمين حسب الدور
// ═══════════════════════════════════════════
$usersByRole = [];
try {
    $stmt = $db->query("
        SELECT r.display_name_ar, r.name as role_name, COUNT(u.id) as count
        FROM roles r
        LEFT JOIN users u ON u.role_id = r.id AND u.is_active = 1
        GROUP BY r.id
        ORDER BY r.rank DESC
    ");
    $usersByRole = $stmt->fetchAll();
} catch (Exception $e) { }

// ═══════════════════════════════════════════
// تنبيهات النظام
// ═══════════════════════════════════════════
$systemAlerts = [];
if ($backupStats['last_full'] === null) {
    $systemAlerts[] = ['type' => 'danger', 'icon' => '🚨', 'text' => 'لم يتم إنشاء أي نسخة احتياطية كاملة بعد!'];
} else {
    $daysSinceBackup = (time() - strtotime($backupStats['last_full'])) / 86400;
    if ($daysSinceBackup > 3) {
        $systemAlerts[] = ['type' => 'warning', 'icon' => '⚠️', 'text' => 'آخر نسخة احتياطية كاملة منذ ' . floor($daysSinceBackup) . ' يوم'];
    }
}
if ($stats['active_alerts'] > 0) {
    $systemAlerts[] = ['type' => 'warning', 'icon' => '🔔', 'text' => $stats['active_alerts'] . ' تنبيه نشط في النظام'];
}
if (empty($systemAlerts)) {
    $systemAlerts[] = ['type' => 'success', 'icon' => '✅', 'text' => 'النظام يعمل بشكل طبيعي'];
}
?>

<style>
.dashboard-stat {
    text-align: center;
    padding: 20px 16px;
    border-radius: var(--radius-lg);
    background: var(--surface);
    border: 1px solid var(--border-light);
    transition: all var(--transition-base);
}
.dashboard-stat:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.dashboard-stat .stat-icon { font-size: 28px; margin-bottom: 8px; }
.dashboard-stat .stat-value { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
.dashboard-stat .stat-label { font-size: 13px; color: var(--text-secondary); }
</style>

<div class="section">
    <!-- إحصائيات عامة -->
    <div class="grid grid-6 mb-6" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));">
        <div class="dashboard-stat" style="border-top:3px solid var(--primary);">
            <div class="stat-icon">🏫</div>
            <div class="stat-value" style="color:var(--primary);"><?= number_format($stats['schools']) ?></div>
            <div class="stat-label">المدارس</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--info);">
            <div class="stat-icon">👥</div>
            <div class="stat-value" style="color:var(--info);"><?= number_format($stats['students']) ?></div>
            <div class="stat-label">الطلاب</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--success);">
            <div class="stat-icon">👨‍🏫</div>
            <div class="stat-value" style="color:var(--success);"><?= number_format($stats['teachers']) ?></div>
            <div class="stat-label">المعلمون</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--secondary);">
            <div class="stat-icon">📚</div>
            <div class="stat-value" style="color:var(--secondary);"><?= number_format($stats['classes']) ?></div>
            <div class="stat-label">الفصول</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--accent);">
            <div class="stat-icon">👤</div>
            <div class="stat-value" style="color:var(--accent);"><?= number_format($stats['users']) ?></div>
            <div class="stat-label">المستخدمون</div>
        </div>
        <div class="dashboard-stat" style="border-top:3px solid var(--danger);">
            <div class="stat-icon">🚨</div>
            <div class="stat-value" style="color:var(--danger);"><?= number_format($stats['active_alerts']) ?></div>
            <div class="stat-label">تنبيهات مفتوحة</div>
        </div>
    </div>

    <!-- 🔍 بحث سريع عن طالب -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">🔍 بحث عن طالب</h3>
        </div>
        <div class="card-body">
            <div style="position:relative;">
                <input type="text" id="studentSearchInput" class="form-control" placeholder="ابحث باسم الطالب أو رقم الهوية..." autocomplete="off" style="padding:12px 16px;font-size:15px;">
                <div id="studentSearchResults" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border-light);border-radius:var(--radius-md);max-height:320px;overflow-y:auto;display:none;z-index:1000;box-shadow:var(--shadow-lg);"></div>
            </div>
        </div>
    </div>

    <style>
    #studentSearchResults .search-result-item {
        padding:10px 14px;
        cursor:pointer;
        border-bottom:1px solid var(--border-light);
        display:flex;
        align-items:center;
        gap:10px;
        transition:background 0.15s;
    }
    #studentSearchResults .search-result-item:hover,
    #studentSearchResults .search-result-item.highlighted {
        background:var(--primary-light);
    }
    #studentSearchResults .search-result-item:last-child {
        border-bottom:none;
    }
    #studentSearchResults .result-name {
        font-weight:600;
        font-size:14px;
    }
    #studentSearchResults .result-meta {
        font-size:12px;
        color:var(--text-secondary);
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('studentSearchInput');
        const results = document.getElementById('studentSearchResults');
        let debounceTimer = null;
        let selectedIndex = -1;
        let currentData = [];

        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 2) { results.style.display = 'none'; return; }
            debounceTimer = setTimeout(function() {
                fetch('<?php echo baseUrl('pages/students/search_ajax.php'); ?>?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        currentData = data;
                        selectedIndex = -1;
                        if (!data || data.length === 0) {
                            results.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">لا توجد نتائج</div>';
                            results.style.display = 'block';
                            return;
                        }
                        var html = '';
                        for (var i = 0; i < data.length; i++) {
                            html += '<div class="search-result-item" data-id="' + data[i].id + '" data-index="' + i + '">' +
                                '<div style="width:36px;height:36px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;">' +
                                (data[i].name_ar ? data[i].name_ar.charAt(0) : '?') + '</div>' +
                                '<div><div class="result-name">' + escapeHtml(data[i].name_ar) + '</div>' +
                                '<div class="result-meta">' + (data[i].school_name ? escapeHtml(data[i].school_name) : '') + (data[i].student_id ? ' · ' + escapeHtml(data[i].student_id) : '') + '</div></div></div>';
                        }
                        results.innerHTML = html;
                        results.style.display = 'block';
                        attachResultClickHandlers();
                    })
                    .catch(function() { results.style.display = 'none'; });
            }, 300);
        });

        input.addEventListener('keydown', function(e) {
            var items = results.querySelectorAll('.search-result-item');
            if (items.length === 0) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                var id = items[selectedIndex].getAttribute('data-id');
                if (id) window.location.href = '<?php echo baseUrl('pages/students/view.php'); ?>?id=' + id;
            } else if (e.key === 'Escape') {
                results.style.display = 'none';
                selectedIndex = -1;
            }
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                results.style.display = 'none';
            }
        });

        function updateHighlight(items) {
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('highlighted', i === selectedIndex);
            }
            if (selectedIndex >= 0 && items[selectedIndex]) {
                items[selectedIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function attachResultClickHandlers() {
            results.querySelectorAll('.search-result-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    if (id) window.location.href = '<?php echo baseUrl('pages/students/view.php'); ?>?id=' + id;
                });
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    });
    </script>

    <div class="grid grid-2" style="grid-template-columns:repeat(auto-fill,minmax(400px,1fr));">
        <!-- تنبيهات النظام -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🔔 تنبيهات النظام</h3>
            </div>
            <div class="card-body">
                <?php foreach ($systemAlerts as $alert): ?>
                <div class="alert alert-<?= $alert['type'] ?>" style="padding:10px 16px;">
                    <?= $alert['icon'] ?> <?= $alert['text'] ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- حالة النسخ الاحتياطي -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">💾 حالة النسخ الاحتياطي</h3>
                <a href="../backup_reports/index.php" class="btn btn-sm btn-light">التفاصيل</a>
            </div>
            <div class="card-body">
                <div class="grid grid-3" style="gap:12px;font-size:14px;">
                    <div style="text-align:center;">
                        <div style="font-size:24px;font-weight:bold;color:var(--primary);"><?= $backupStats['total_backups'] ?></div>
                        <div style="color:var(--text-secondary);font-size:12px;">إجمالي النسخ</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:24px;font-weight:bold;color:var(--info);">
                            <?= $backupStats['total_size'] > 1048576 ? round($backupStats['total_size']/1048576, 1) . ' MB' : round($backupStats['total_size']/1024, 0) . ' KB' ?>
                        </div>
                        <div style="color:var(--text-secondary);font-size:12px;">الحجم الكلي</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:14px;font-weight:bold;color:<?= $backupStats['last_full'] ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= $backupStats['last_full'] ? formatDateAr($backupStats['last_full'], 'short') : 'لا توجد' ?>
                        </div>
                        <div style="color:var(--text-secondary);font-size:12px;">آخر نسخة كاملة</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- المستخدمون حسب الدور -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h3 class="card-title">👤 المستخدمون حسب الدور</h3>
                <a href="../users/index.php" class="btn btn-sm btn-light">إدارة</a>
            </div>
            <div class="card-body">
                <?php foreach ($usersByRole as $ur): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light);">
                    <span><?= e($ur['display_name_ar']) ?></span>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-weight:bold;"><?= $ur['count'] ?></span>
                        <div style="width:60px;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                            <div style="width:<?= $stats['users'] > 0 ? min(100, ($ur['count']/$stats['users'])*100) : 0 ?>%;height:100%;background:var(--primary);border-radius:var(--radius-full);"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- سجل النشاطات -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 آخر النشاطات</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div style="max-height:300px;overflow-y:auto;">
                    <?php foreach ($recentActivities as $act): ?>
                    <div style="padding:8px 16px;border-bottom:1px solid var(--border-light);font-size:13px;">
                        <div style="display:flex;justify-content:space-between;">
                            <strong><?= e($act['user_name'] ?? 'نظام') ?></strong>
                            <span style="font-size:11px;color:var(--text-muted);"><?= formatDateAr($act['created_at'], 'short') ?></span>
                        </div>
                        <div style="color:var(--text-secondary);font-size:12px;"><?= e($act['description'] ?? $act['action']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- مقارنة مؤشر جودة التعليم -->
    <div class="card mt-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card-title">📊 مقارنة مؤشر جودة التعليم بين المدارس</h3>
            <a href="../eqi/compare.php" class="btn btn-sm btn-light">مقارنة مفصلة</a>
        </div>
        <div class="card-body">
            <?php if (empty($schoolEqi) || empty(array_filter($schoolEqi, function($s) { return $s['eqi_score'] > 0; }))): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <p>لم يتم حساب مؤشر جودة التعليم بعد. <a href="../eqi/calculate.php">بدء الحساب</a></p>
            </div>
            <?php else: ?>
            <div style="max-height:400px;overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>مؤشر الجودة</th>
                            <th>التقييم</th>
                            <th>الاتجاه السنوي</th>
                            <th>المعدّل سياقياً</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        $adjustService = new ContextualAdjustmentService($db);
                        foreach ($schoolEqi as $s):
                            if ($s['eqi_score'] <= 0) continue;
                            $rating = getEqiRating($s['eqi_score']);

                            // الاتجاه السنوي
                            $trendText = '—';
                            $trendClass = 'trend-neutral';
                            $prevScore = $yearlyTrends[$s['id']] ?? null;
                            if ($prevScore !== null && $prevScore > 0) {
                                $diff = $s['eqi_score'] - $prevScore;
                                $pct = ($diff / $prevScore) * 100;
                                if ($pct > 1) {
                                    $trendText = '▲ +' . number_format($pct, 1) . '%';
                                    $trendClass = 'trend-up';
                                } elseif ($pct < -1) {
                                    $trendText = '▼ ' . number_format(abs($pct), 1) . '%';
                                    $trendClass = 'trend-down';
                                } else {
                                    $trendText = '→ مستقر';
                                    $trendClass = 'trend-neutral';
                                }
                            } elseif ($prevScore === null) {
                                $trendText = 'لا يوجد';
                                $trendClass = 'trend-neutral';
                            }

                            // التعديل السياقي
                            $adj = $adjustService->calculateAdjustedSQIWithDetails($s['id'], $currentYearId, $s['raw_eqi']);
                            $adjValue = $adj['adjusted_sqi'] ?? null;
                        ?>
                        <tr>
                            <td><strong><?= $rank++ ?></strong></td>
                            <td><strong><?= e($s['name_ar']) ?></strong></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="width:100px;height:8px;background:var(--border-light);border-radius:var(--radius-full);">
                                        <div style="width:<?= $s['eqi_score'] ?>%;height:100%;background:<?= $rating[2] === 'success' ? 'var(--success)' : ($rating[2] === 'warning' ? 'var(--warning)' : 'var(--danger)') ?>;border-radius:var(--radius-full);"></div>
                                    </div>
                                    <strong><?= number_format($s['eqi_score'], 1) ?>%</strong>
                                </div>
                            </td>
                            <td><span class="badge badge-<?= $rating[1] ?>"><?= $rating[0] ?></span></td>
                            <td style="text-align:center;">
                                <span style="font-weight:bold;font-size:13px;" class="<?= $trendClass ?>"><?= $trendText ?></span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($adjValue !== null): ?>
                                <div><strong style="font-size:14px;color:#333;"><?= number_format($adjValue, 1) ?></strong></div>
                                <div style="font-size:10px;color:var(--text-muted);">
                                    <?php if ($adj['has_context']): ?>
                                    تحد: <?= number_format($adj['challenge_index'], 0) ?>
                                    <?php else: ?>
                                    بدون سياق
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

     <!-- رسوم بيانية -->
     <div class="grid grid-2 mt-6" style="grid-template-columns:repeat(auto-fill,minmax(400px,1fr));">
         <div class="card">
             <div class="card-header">
                 <h3 class="card-title">📊 توزيع المدارس حسب تقييم المؤشر</h3>
             </div>
             <div class="card-body" style="height:300px;">
                 <canvas id="eqiDistributionChart"></canvas>
             </div>
         </div>
         <div class="card">
             <div class="card-header">
                 <h3 class="card-title">📈 المستخدمون حسب الدور</h3>
             </div>
             <div class="card-body" style="height:300px;">
                 <canvas id="usersRoleChart"></canvas>
             </div>
         </div>
     </div>

     <script>
     document.addEventListener('DOMContentLoaded', function() {
         // توزيع المدارس حسب تقييم المؤشر
         <?php
         $eqiBuckets = ['ممتاز' => 0, 'جيد جداً' => 0, 'مقبول' => 0, 'ضعيف' => 0];
         foreach ($schoolEqi as $s) {
             if ($s['eqi_score'] <= 0) continue;
             $r = getEqiRating($s['eqi_score']);
             if (isset($eqiBuckets[$r[0]])) $eqiBuckets[$r[0]]++;
         }
         ?>
         eqasPieChart('eqiDistributionChart', '',
             <?= json_encode(array_keys($eqiBuckets), JSON_UNESCAPED_UNICODE) ?>,
             <?= json_encode(array_values($eqiBuckets)) ?>,
             ['#28a745', '#1a5632', '#ffc107', '#dc3545']
         );

         // المستخدمون حسب الدور
         <?php
         $roleLabels = []; $roleCounts = []; $roleColors = ['#1a5632','#c8a951','#17a2b8','#fd7e14'];
         foreach ($usersByRole as $ur) { $roleLabels[] = $ur['display_name_ar']; $roleCounts[] = (int)$ur['count']; }
         ?>
         eqasBarChart('usersRoleChart', '',
             <?= json_encode($roleLabels, JSON_UNESCAPED_UNICODE) ?>,
             [{label: 'عدد المستخدمين', data: <?= json_encode($roleCounts) ?>, backgroundColor: <?= json_encode(array_slice($roleColors, 0, count($roleLabels))) ?>}]
         );
     });
     </script>

    <!-- إجراءات سريعة -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">⚡ إجراءات سريعة</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-auto" style="gap:12px;">
                <a href="../users/add.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">👤</div>
                    <div style="font-size:13px;">إضافة مستخدم</div>
                </a>
                <a href="../schools/add.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🏫</div>
                    <div style="font-size:13px;">إضافة مدرسة</div>
                </a>
                <a href="../backup_reports/create.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">💾</div>
                    <div style="font-size:13px;">إنشاء نسخة احتياطية</div>
                </a>
                <a href="../eqi/calculate.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">📊</div>
                    <div style="font-size:13px;">حساب مؤشر الجودة</div>
                </a>
                <a href="../alerts/run_checks.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🚨</div>
                    <div style="font-size:13px;">فحص التنبيهات</div>
                </a>
                <a href="../system/restore.php" class="btn btn-light" style="text-align:center;padding:16px;">
                    <div style="font-size:24px;">🔄</div>
                    <div style="font-size:13px;">استعادة نسخة</div>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
