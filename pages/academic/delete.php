<?php
$pageTitle = 'أرشفة سنة أكاديمية';
$breadcrumb = [
    'الإدارة' => null,
    'السنوات الأكاديمية' => 'index.php',
    'أرشفة سنة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$yearId = intval($_GET['id'] ?? 0);
$isRestore = isset($_GET['restore']);

if (!$yearId) {
    setFlash('معرف السنة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
$stmt->execute([$yearId]);
$year = $stmt->fetch();

if (!$year) {
    setFlash('السنة الأكاديمية غير موجودة', 'danger');
    redirect('index.php');
}

// جلب إحصائيات البيانات المرتبطة
$stats = [];

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $stats['classes'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['classes'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT se.student_id) FROM student_enrollments se JOIN classes c ON se.class_id = c.id WHERE c.academic_year_id = ? AND se.is_active = 1");
    $stmt->execute([$yearId]);
    $stats['students'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['students'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM assessment_responses WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $stats['assessments'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['assessments'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM eqi_results WHERE academic_year_id = ?");
    $stmt->execute([$yearId]);
    $stats['eqi'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['eqi'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM eqi_results WHERE academic_year_id = ?");
    $stmt->execute([$yearId]);
    $stats['equi'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['equi'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM subject_teachers WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $stats['subject_teachers'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['subject_teachers'] = 0; }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM semesters WHERE academic_year_id = ? AND is_active = 1");
    $stmt->execute([$yearId]);
    $stats['semesters'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { $stats['semesters'] = 0; }

// التحقق من العوائق
$blockers = [];

if ($year['is_current']) {
    $blockers[] = 'لا يمكن أرشفة السنة الحالية. قم بتعيين سنة أخرى كسنة حالية أولاً.';
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM semesters WHERE academic_year_id = ? AND is_current = 1 AND is_active = 1");
    $stmt->execute([$yearId]);
    if ((int)$stmt->fetchColumn() > 0) {
        $blockers[] = 'لا يمكن أرشفة السنة لأن أحد فصولها هو الفصل الحالي. قم بتعيين فصل آخر كفصل حالي أولاً.';
    }
} catch (Exception $e) {}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM semesters WHERE academic_year_id = ? AND lock_level = 'admin_handled' AND is_active = 1");
    $stmt->execute([$yearId]);
    if ((int)$stmt->fetchColumn() > 0) {
        $blockers[] = 'لا يمكن أرشفة السنة لأن أحد فصولها مقفل بقفل إداري. قم بفتحه أولاً.';
    }
} catch (Exception $e) {}

$hasBlockers = !empty($blockers);

// التنفيذ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasBlockers) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("delete.php?id={$yearId}" . ($isRestore ? '&restore=1' : ''));
    }

    try {
        $db->beginTransaction();

        if ($isRestore) {
            $stmt = $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ?");
            $stmt->execute([$yearId]);
            $stmt = $db->prepare("UPDATE semesters SET is_active = 1 WHERE academic_year_id = ?");
            $stmt->execute([$yearId]);
            logActivity($db, $_SESSION['user']['id'], 'restore_academic_year', "استعادة السنة الأكاديمية: {$year['name']}", 'academic_year', $yearId);
            $db->commit();
            setFlash("تم استعادة السنة الأكاديمية {$year['name']} بنجاح", 'success');
        } else {
            $stmt = $db->prepare("UPDATE academic_years SET is_active = 0 WHERE id = ?");
            $stmt->execute([$yearId]);
            $stmt = $db->prepare("UPDATE semesters SET is_active = 0 WHERE academic_year_id = ?");
            $stmt->execute([$yearId]);
            logActivity($db, $_SESSION['user']['id'], 'archive_academic_year', "أرشفة السنة الأكاديمية: {$year['name']}", 'academic_year', $yearId);
            $db->commit();
            setFlash("تم أرشفة السنة الأكاديمية {$year['name']} بنجاح", 'success');
        }

        redirect('index.php');
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Academic Year Archive Error: ' . $e->getMessage());
        setFlash('حدث خطأ أثناء تحديث حالة السنة', 'danger');
        redirect("delete.php?id={$yearId}" . ($isRestore ? '&restore=1' : ''));
    }
}

$actionText = $isRestore ? 'استعادة' : 'أرشفة';
$actionIcon = $isRestore ? '🔄' : '🗑️';
$actionColor = $isRestore ? 'success' : 'danger';
$totalRelated = $stats['students'] + $stats['classes'] + $stats['assessments'] + $stats['subject_teachers'];
?>

<div class="card mb-6">
    <div class="card-header" style="background:var(--gradient-primary);">
        <h3 class="card-title" style="color:var(--text-white);"><?= $actionIcon ?> <?= $actionText ?> السنة الأكاديمية: <?= e($year['name']) ?></h3>
        <a href="view.php?id=<?= $yearId ?>" class="btn btn-light btn-sm">↩ العودة</a>
    </div>
    <div class="card-body">
        <?php if ($hasBlockers): ?>
            <div class="alert alert-danger mb-5">
                <span class="alert-icon">⛔</span>
                <div class="flex-1">
                    <strong>لا يمكن <?= $actionText ?> هذه السنة</strong><br>
                    <span class="text-sm"><?= implode('<br>', array_map('e', $blockers)) ?></span>
                </div>
            </div>
            <a href="view.php?id=<?= $yearId ?>" class="btn btn-light btn-lg">↩ العودة إلى السنة</a>
        <?php else: ?>
            <?php if (!$isRestore && $totalRelated > 0): ?>
            <div class="alert alert-warning mb-5">
                <span class="alert-icon">⚠️</span>
                <div class="flex-1">
                    <strong>هذه السنة تحتوي على بيانات مرتبطة</strong><br>
                    <span class="text-sm">الأرشفة ستخفي السنة وجميع بياناتها من القوائم النشطة. يمكنك استعادتها لاحقاً.</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isRestore): ?>
            <div class="alert alert-info mb-5">
                <span class="alert-icon">💡</span>
                <div class="flex-1">
                    <strong>استعادة السنة الأكاديمية</strong><br>
                    <span class="text-sm">سيتم إعادة إظهار السنة وجميع فصولها في القوائم النشطة.</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- القسم 1: معلومات السنة -->
            <div class="card mb-6">
                <div class="card-header">
                    <h4 class="card-title" style="font-size:var(--font-size-base);">📋 معلومات السنة</h4>
                </div>
                <div class="card-body">
                    <div class="grid grid-3" style="gap:var(--space-4);">
                        <div class="form-group">
                            <label class="form-label">اسم السنة</label>
                            <input type="text" class="form-control" value="<?= e($year['name']) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">تاريخ البداية</label>
                            <input type="text" class="form-control" value="<?= e($year['start_date']) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">تاريخ النهاية</label>
                            <input type="text" class="form-control" value="<?= e($year['end_date']) ?>" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- القسم 2: البيانات المرتبطة -->
            <div class="card mb-6">
                <div class="card-header">
                    <h4 class="card-title" style="font-size:var(--font-size-base);">📊 البيانات المرتبطة</h4>
                </div>
                <div class="card-body">
                    <div class="grid grid-3" style="gap:var(--space-4);">
                        <div class="stat-card primary" style="margin:0;">
                            <div class="stat-card-label">🎓 الطلاب المسجلون</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['students']) ?></div>
                        </div>
                        <div class="stat-card info" style="margin:0;">
                            <div class="stat-card-label">🏫 الفصول الدراسية</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['classes']) ?></div>
                        </div>
                        <div class="stat-card warning" style="margin:0;">
                            <div class="stat-card-label">📊 الفصول (semesters)</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['semesters']) ?></div>
                        </div>
                        <div class="stat-card success" style="margin:0;">
                            <div class="stat-card-label">📋 التقييمات</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['assessments']) ?></div>
                        </div>
                        <div class="stat-card secondary" style="margin:0;">
                            <div class="stat-card-label">👨‍🏫 شعب المواد</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['subject_teachers']) ?></div>
                        </div>
                        <div class="stat-card" style="margin:0;background:var(--surface-2);">
                            <div class="stat-card-label">📈 حسابات EQI</div>
                            <div class="stat-card-value"><?= formatNumberAr($stats['eqi']) ?></div>
                        </div>
                    </div>

                    <?php if ($stats['equi'] > 0): ?>
                    <div class="alert alert-warning mt-4 mb-0">
                        <span class="alert-icon">⚠️</span>
                        <div class="flex-1">
                            <strong>نتائج EQI موجودة</strong><br>
                            <span class="text-sm">هذه السنة تحتوي على <strong><?= formatNumberAr($stats['equi']) ?></strong> حساب جودة في جدول نتائج EQI. الأرشفة ستخفي هذه النتائج من التقارير النشطة.</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- تأكيد -->
            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-<?= $actionColor ?> btn-lg"><?= $actionIcon ?> نعم، <?= $actionText ?></button>
                    <a href="view.php?id=<?= $yearId ?>" class="btn btn-light btn-lg">إلغاء</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
