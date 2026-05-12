<?php
/**
 * ============================================================
 * عرض بيانات مستخدم
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'عرض بيانات المستخدم';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'عرض بيانات المستخدم' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userId = intval($_GET['id'] ?? 0);
if (!$userId) {
    setFlash('معرف المستخدم غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات المستخدم مع الدور والمدرسة
$stmt = $db->prepare("SELECT u.*, r.name AS role_name, r.display_name_ar AS role_name_ar, s.name_ar AS school_name
                      FROM users u
                      LEFT JOIN roles r ON u.role_id = r.id
                      LEFT JOIN schools s ON u.school_id = s.id
                      WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('المستخدم غير موجود', 'danger');
    redirect('index.php');
}

// جلب سجل النشاطات (آخر 20 عملية)
$stmt = $db->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$userId]);
$activities = $stmt->fetchAll();

// جلب الطلاب المرتبطين (لولي الأمر)
$students = [];
if ($user['role_name'] === 'parent') {
    $stmt = $db->prepare("SELECT s.*, ps.relationship
                          FROM parent_students ps
                          JOIN students s ON ps.student_id = s.id
                          WHERE ps.parent_id = ? AND ps.is_active = 1 AND s.is_active = 1
                          ORDER BY s.full_name_ar");
    $stmt->execute([$userId]);
    $students = $stmt->fetchAll();
}

// جلب الفصول التي يدرسها (للمعلم)
$classes = [];
if ($user['role_name'] === 'teacher') {
    $stmt = $db->prepare("SELECT c.name_ar AS class_name, sub.name_ar AS subject_name, ay.name AS year_name
                          FROM subject_teachers st
                          JOIN classes c ON st.class_id = c.id
                          JOIN subjects sub ON st.subject_id = sub.id
                          JOIN academic_years ay ON st.academic_year_id = ay.id
                          WHERE st.teacher_id = ? AND st.is_active = 1
                          ORDER BY ay.name DESC, c.name_ar");
    $stmt->execute([$userId]);
    $classes = $stmt->fetchAll();
}

// ألوان شارات الدور
$roleBadgeClass = [
    'group_admin' => 'badge-danger',
    'school_admin' => 'badge-primary',
    'teacher' => 'badge-success',
    'parent' => 'badge-warning'
];
$badgeClass = $roleBadgeClass[$user['role_name']] ?? 'badge-secondary';
?>

<!-- بطاقة معلومات المستخدم -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">بيانات المستخدم</h3>
            <div class="flex gap-2">
                <a href="edit.php?id=<?= $userId ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                <a href="reset_password.php?id=<?= $userId ?>" class="btn btn-secondary btn-sm">🔑 إعادة تعيين كلمة المرور</a>
                <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr>
                            <td style="font-weight:600;width:160px;">اسم المستخدم</td>
                            <td><?= e($user['username']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">الاسم الكامل</td>
                            <td><?= e($user['full_name_ar']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">البريد الإلكتروني</td>
                            <td><?= e($user['email'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">رقم الهاتف</td>
                            <td><?= e($user['phone'] ?? '—') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr>
                            <td style="font-weight:600;width:160px;">الدور</td>
                            <td><span class="badge <?= $badgeClass ?>"><?= e($user['role_name_ar'] ?? getRoleLabel($user['role_name'])) ?></span></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">المدرسة</td>
                            <td><?= e($user['school_name'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">الحالة</td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">غير نشط</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">آخر تسجيل دخول</td>
                            <td><?= $user['last_login_at'] ? formatDateAr($user['last_login_at'], 'medium') : 'لم يسجل بعد' ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">تاريخ الإنشاء</td>
                            <td><?= formatDateAr($user['created_at'], 'medium') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- الطلاب المرتبطين (لولي الأمر) -->
<?php if ($user['role_name'] === 'parent' && !empty($students)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">🎓 الطلاب المرتبطين</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم الطالب</th>
                        <th>صلة القرابة</th>
                        <th>رقم الطالب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= e($student['full_name_ar']) ?></td>
                            <td>
                                <?php
                                $relLabels = ['father' => 'أب', 'mother' => 'أم', 'guardian' => 'وصي', 'other' => 'أخرى'];
                                echo e($relLabels[$student['relationship']] ?? $student['relationship']);
                                ?>
                            </td>
                            <td><?= e($student['student_number']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- الفصول التي يدرسها (للمعلم) -->
<?php if ($user['role_name'] === 'teacher' && !empty($classes)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">📚 الفصول والمواد التي يدرسها</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الفصل</th>
                        <th>المادة</th>
                        <th>السنة الدراسية</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($classes as $cls): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= e($cls['class_name']) ?></td>
                            <td><?= e($cls['subject_name']) ?></td>
                            <td><?= e($cls['year_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- سجل النشاطات -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📋 سجل النشاطات الأخيرة</h3>
    </div>
    <div class="card-body">
        <?php if (empty($activities)): ?>
            <p class="text-secondary text-center">لا توجد نشاطات مسجلة</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الإجراء</th>
                            <th>الوصف</th>
                            <th>التاريخ</th>
                            <th>عنوان IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; ?>
                        <?php foreach ($activities as $act): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><span class="badge badge-info"><?= e($act['action']) ?></span></td>
                                <td><?= e($act['description']) ?></td>
                                <td><?= formatDateAr($act['created_at'], 'medium') ?></td>
                                <td><?= e($act['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
