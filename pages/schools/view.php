<?php
/**
 * ============================================================
 * عرض تفاصيل مدرسة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تفاصيل المدرسة';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => 'index.php',
    'تفاصيل المدرسة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$schoolId = intval($_GET['id'] ?? 0);
if (!$schoolId) {
    setFlash('معرف المدرسة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('المدرسة غير موجودة', 'danger');
    redirect('index.php');
}

// إحصائيات المدرسة
$studentCount = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
$studentCount->execute([$schoolId]);
$studentCountVal = $studentCount->fetchColumn();

$teacherCount = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM users u JOIN roles r ON u.role_id = r.id WHERE u.school_id = ? AND r.name = 'teacher' AND u.is_active = 1");
$teacherCount->execute([$schoolId]);
$teacherCountVal = $teacherCount->fetchColumn();

$classCount = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND is_active = 1");
$classCount->execute([$schoolId]);
$classCountVal = $classCount->fetchColumn();

// درجة مؤشر جودة التعليم إن وجدت
$eqiScore = null;
try {
    $stmt = $db->prepare("SELECT AVG(overall_score) AS avg_score FROM eqi_results WHERE school_id = ? ORDER BY calculated_at DESC LIMIT 1");
    $stmt->execute([$schoolId]);
    $eqiRow = $stmt->fetch();
    $eqiScore = $eqiRow ? $eqiRow['avg_score'] : null;
} catch (Exception $e) {
    $eqiScore = null;
}

// قائمة الفصول
$classes = $db->prepare("SELECT c.*, gl.name_ar AS grade_name, ay.name AS year_name,
                         (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1) AS enrollment_count
                         FROM classes c
                         LEFT JOIN grade_levels gl ON c.grade_level_id = gl.id
                         LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
                         WHERE c.school_id = ? AND c.is_active = 1
                         ORDER BY ay.name DESC, gl.level_number");
$classes->execute([$schoolId]);
$classesList = $classes->fetchAll();

// أنواع المدارس
$schoolTypeAr = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
?>

<!-- بطاقة معلومات المدرسة -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">🏫 <?= e($school['name_ar']) ?></h3>
            <div class="flex gap-2">
                <a href="edit.php?id=<?= $schoolId ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                <a href="stats.php?id=<?= $schoolId ?>" class="btn btn-info btn-sm">📊 الإحصائيات</a>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr><td style="font-weight:600;width:140px;">الاسم بالعربية</td><td><?= e($school['name_ar']) ?></td></tr>
                        <tr><td style="font-weight:600;">الاسم بالإنجليزية</td><td><?= e($school['name_en'] ?? '—') ?></td></tr>
                        <tr><td style="font-weight:600;">الرمز</td><td><code><?= e($school['code']) ?></code></td></tr>
                        <tr><td style="font-weight:600;">النوع</td><td><span class="badge badge-info"><?= $schoolTypeAr[$school['school_type']] ?? $school['school_type'] ?></span></td></tr>
                    </tbody>
                </table>
            </div>
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr><td style="font-weight:600;width:140px;">المحافظة</td><td><?= e($school['governorate'] ?? '—') ?></td></tr>
                        <tr><td style="font-weight:600;">المديرية</td><td><?= e($school['district'] ?? '—') ?></td></tr>
                        <tr><td style="font-weight:600;">مدير المدرسة</td><td><?= e($school['principal_name'] ?? '—') ?></td></tr>
                        <tr><td style="font-weight:600;">الهاتف</td><td><?= e($school['phone'] ?? '—') ?></td></tr>
                        <tr><td style="font-weight:600;">البريد الإلكتروني</td><td><?= e($school['email'] ?? '—') ?></td></tr>
                        <tr>
                            <td style="font-weight:600;">الحالة</td>
                            <td><?= $school['is_active'] ? '<span class="badge badge-success">نشطة</span>' : '<span class="badge badge-secondary">غير نشطة</span>' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($school['address']): ?>
            <div class="mt-4"><strong>العنوان:</strong> <?= e($school['address']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- بطاقة الإحصائيات -->
<div class="grid grid-4 mb-6" style="gap:var(--space-4);">
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:32px;margin-bottom:var(--space-2);">🎓</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--primary);"><?= formatNumberAr($studentCountVal) ?></div>
            <div class="text-sm text-secondary">طالب</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:32px;margin-bottom:var(--space-2);">👨‍🏫</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--success);"><?= formatNumberAr($teacherCountVal) ?></div>
            <div class="text-sm text-secondary">معلم</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:32px;margin-bottom:var(--space-2);">🏫</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--info);"><?= formatNumberAr($classCountVal) ?></div>
            <div class="text-sm text-secondary">فصل</div>
        </div>
    </div>
    <div class="card" style="text-align:center;">
        <div class="card-body">
            <div style="font-size:32px;margin-bottom:var(--space-2);">📊</div>
            <div style="font-size:var(--font-size-2xl);font-weight:700;color:var(--secondary);">
                <?= $eqiScore !== null ? number_format($eqiScore, 1) : '—' ?>
            </div>
            <div class="text-sm text-secondary">مؤشر الجودة</div>
        </div>
    </div>
</div>

<!-- قائمة الفصول -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📚 الفصول الدراسية</h3>
    </div>
    <div class="card-body">
        <?php if (empty($classesList)): ?>
            <p class="text-secondary text-center">لا توجد فصول مسجلة</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الفصل</th>
                            <th>الصف الدراسي</th>
                            <th>السنة الدراسية</th>
                            <th>عدد الطلاب</th>
                            <th>السعة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; ?>
                        <?php foreach ($classesList as $cls): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= e($cls['name_ar']) ?></td>
                                <td><?= e($cls['grade_name'] ?? '—') ?></td>
                                <td><?= e($cls['year_name'] ?? '—') ?></td>
                                <td><?= formatNumberAr($cls['enrollment_count']) ?></td>
                                <td><?= formatNumberAr($cls['capacity']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
