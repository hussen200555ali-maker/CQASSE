<?php
/**
 * ============================================================
 * EQAS - إعداد الفصل الدراسي
 * نظام التقييم الشامل - Semester Setup
 * ============================================================
 */

$pageTitle = 'إعداد الفصل الدراسي';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'الفصل الدراسي' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$academicYearId = currentAcademicYearId();

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_semester') {
            $name = trim($_POST['name_ar'] ?? '');
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';

            if (empty($name) || empty($startDate) || empty($endDate)) {
                setFlash('يرجى ملء جميع الحقول.', 'warning');
            } elseif ($startDate >= $endDate) {
                setFlash('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.', 'warning');
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active) VALUES (?, ?, ?, ?, 0, 'none', 1)");
                    $stmt->execute([$academicYearId, $name, $startDate, $endDate]);
                    logActivity($db, $_SESSION['user']['id'], 'setup_semester_create', "إنشاء فصل دراسي: {$name}", 'semesters', $db->lastInsertId());
                    setFlash('تم إنشاء الفصل الدراسي بنجاح.', 'success');
                } catch (Exception $e) {
                    setFlash('خطأ: ' . $e->getMessage(), 'danger');
                }
            }

        } elseif ($action === 'update_semester') {
            $id = (int)$_POST['semester_id'];
            $name = trim($_POST['name_ar'] ?? '');
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $lockLevel = $_POST['lock_level'] ?? 'none';

            $stmt = $db->prepare("UPDATE semesters SET name_ar = ?, start_date = ?, end_date = ?, lock_level = ? WHERE id = ?");
            $stmt->execute([$name, $startDate, $endDate, $lockLevel, $id]);
            logActivity($db, $_SESSION['user']['id'], 'setup_semester_update', "تحديث فصل دراسي رقم {$id}", 'semesters', $id);
            setFlash('تم تحديث الفصل الدراسي.', 'success');

        } elseif ($action === 'set_current') {
            $id = (int)$_POST['semester_id'];
            // إلغاء الحالي
            $db->prepare("UPDATE semesters SET is_current = 0 WHERE academic_year_id = ?")->execute([$academicYearId]);
            // تعيين الجديد
            $stmt = $db->prepare("UPDATE semesters SET is_current = 1 WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم تعيين الفصل الدراسي الحالي.', 'success');

        } elseif ($action === 'lock_semester') {
            $id = (int)$_POST['semester_id'];
            $lockLevel = $_POST['lock_level'] ?? 'soft';
            $stmt = $db->prepare("UPDATE semesters SET lock_level = ?, locked_at = NOW(), locked_by = ? WHERE id = ?");
            $stmt->execute([$lockLevel, $_SESSION['user']['id'], $id]);
            logActivity($db, $_SESSION['user']['id'], 'setup_semester_lock', "قفل فصل دراسي رقم {$id} بمستوى {$lockLevel}", 'semesters', $id);
            setFlash('تم قفل الفصل الدراسي.', 'success');
        }

        redirect('semester_setup.php');
    }
}

// جلب الفصول الدراسية
$stmt = $db->prepare("
    SELECT s.*, ay.name as year_name
    FROM semesters s
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE s.is_active = 1 AND s.academic_year_id = ?
    ORDER BY s.start_date
");
$stmt->execute([$academicYearId]);
$semesters = $stmt->fetchAll();

$lockLabels = ['none' => 'مفتوح', 'soft' => 'قفل ناعم', 'hard' => 'قفل صلب', 'admin_handled' => 'معالجة المدير'];
$lockColors = ['none' => 'success', 'soft' => 'warning', 'hard' => 'danger', 'admin_handled' => 'info'];

// العام الدراسي
$stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
$stmt->execute([$academicYearId]);
$academicYear = $stmt->fetch();
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">📅 إعداد الفصل الدراسي</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-form').style.display='block'">➕ فصل دراسي جديد</button>
        </div>
        <div class="card-body">
            <?php if ($academicYear): ?>
            <div style="background:var(--background);padding:12px 16px;border-radius:var(--radius-md);margin-bottom:16px;">
                <strong>العام الدراسي:</strong> <?= e($academicYear['name']) ?>
                <span style="margin-right:16px;"><strong>من:</strong> <?= e($academicYear['start_date']) ?></span>
                <span style="margin-right:16px;"><strong>إلى:</strong> <?= e($academicYear['end_date']) ?></span>
            </div>
            <?php endif; ?>

            <!-- نموذج إنشاء -->
            <div id="create-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--primary);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_semester">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">اسم الفصل <span class="required">*</span></label>
                                    <input type="text" name="name_ar" class="form-control" placeholder="مثال: الفصل الدراسي الأول" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">تاريخ البداية <span class="required">*</span></label>
                                    <input type="date" name="start_date" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">تاريخ النهاية <span class="required">*</span></label>
                                    <input type="date" name="end_date" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary">💾 إنشاء</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('create-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- عرض الفصول الدراسية -->
            <?php if (empty($semesters)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📅</div>
                <p>لم يتم إنشاء فصول دراسية بعد.</p>
            </div>
            <?php else: ?>
            <?php foreach ($semesters as $sem):
                $lockLabel = $lockLabels[$sem['lock_level']] ?? $sem['lock_level'];
                $lockColor = $lockColors[$sem['lock_level']] ?? 'secondary';
                $daysRemaining = (strtotime($sem['end_date']) - time()) / 86400;
            ?>
            <div class="card mb-4" style="border-right:4px solid <?= $sem['is_current'] ? 'var(--success)' : 'var(--border)' ?>;">
                <div class="card-header" style="background:<?= $sem['is_current'] ? 'rgba(40,167,69,0.05)' : 'var(--background)' ?>;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h4 style="margin:0;">
                            📅 <?= e($sem['name_ar']) ?>
                            <?php if ($sem['is_current']): ?>
                            <span style="background:var(--success);color:white;padding:1px 8px;border-radius:var(--radius-full);font-size:10px;margin-right:8px;">الحالي</span>
                            <?php endif; ?>
                        </h4>
                        <span style="background:var(--<?= $lockColor ?>);color:<?= $lockColor === 'warning' ? 'var(--text-primary)' : 'white' ?>;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;">
                            🔒 <?= $lockLabel ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-3" style="gap:16px;font-size:14px;">
                        <div>
                            <strong>تاريخ البداية:</strong><br>
                            <?= formatDateAr($sem['start_date'], 'medium') ?>
                        </div>
                        <div>
                            <strong>تاريخ النهاية:</strong><br>
                            <?= formatDateAr($sem['end_date'], 'medium') ?>
                        </div>
                        <div>
                            <strong>الأيام المتبقية:</strong><br>
                            <span style="color:<?= $daysRemaining > 30 ? 'var(--success)' : ($daysRemaining > 0 ? 'var(--warning)' : 'var(--danger)') ?>;font-weight:bold;">
                                <?= $daysRemaining > 0 ? floor($daysRemaining) . ' يوم' : 'منتهي' ?>
                            </span>
                        </div>
                    </div>

                    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if (!$sem['is_current']): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="set_current">
                            <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm">📌 تعيين كحالي</button>
                        </form>
                        <?php endif; ?>

                        <?php if ($sem['lock_level'] === 'none'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('قفل هذا الفصل الدراسي؟');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="lock_semester">
                            <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                            <input type="hidden" name="lock_level" value="soft">
                            <button type="submit" class="btn btn-warning btn-sm">🔒 قفل ناعم</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('قفل صلب؟ لا يمكن التعديل!');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="lock_semester">
                            <input type="hidden" name="semester_id" value="<?= $sem['id'] ?>">
                            <input type="hidden" name="lock_level" value="hard">
                            <button type="submit" class="btn btn-danger btn-sm">🔒🔒 قفل صلب</button>
                        </form>
                        <?php endif; ?>

                        <?php if ($sem['locked_by']): ?>
                        <span style="font-size:11px;color:var(--text-muted);">
                            قفل بواسطة: ID#<?= $sem['locked_by'] ?> في <?= formatDateAr($sem['locked_at'], 'short') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
