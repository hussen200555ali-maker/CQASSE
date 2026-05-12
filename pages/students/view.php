<?php
/**
 * ============================================================
 * عرض بيانات طالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'عرض بيانات الطالب';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => 'index.php',
    'عرض بيانات الطالب' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// تحديد نطاق المدرسة
$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
$isGroupAdmin = ($userRole === 'group_admin');

$studentId = intval($_GET['id'] ?? 0);
if (!$studentId) {
    setFlash('معرف الطالب غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات الطالب مع المرحلة والمدرسة
$stmt = $db->prepare("SELECT s.*, gl.name_ar AS grade_name, gl.level_number, gl.stage, sc.name_ar AS school_name
                      FROM students s
                      LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
                      LEFT JOIN schools sc ON s.school_id = sc.id
                      WHERE s.id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('الطالب غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من أن مدير المدرسة يرى طلاب مدرسته فقط
if (!$isGroupAdmin && $student['school_id'] != $userSchoolId) {
    setFlash('ليس لديك صلاحية لعرض طلاب مدرسة أخرى', 'danger');
    redirect('index.php');
}

// جلب حسابات أولياء الأمور المرتبطين
$stmt = $db->prepare("SELECT ps.*, u.full_name_ar AS parent_name, u.username, u.phone, u.email
                      FROM parent_students ps
                      LEFT JOIN users u ON ps.parent_id = u.id
                      WHERE ps.student_id = ? AND ps.is_active = 1
                      ORDER BY ps.relationship");
$stmt->execute([$studentId]);
$parents = $stmt->fetchAll();

// جلب تسجيلات الطالب في الفصول
$enrollments = [];
try {
    $stmt = $db->prepare("SELECT se.*, c.name_ar AS class_name, ay.name AS year_name, s.name AS semester_name
                          FROM student_enrollments se
                          LEFT JOIN classes c ON se.class_id = c.id
                          LEFT JOIN academic_years ay ON se.academic_year_id = ay.id
                          LEFT JOIN semesters s ON se.academic_year_id = s.academic_year_id
                          WHERE se.student_id = ?
                          ORDER BY se.enrollment_date DESC
                          LIMIT 20");
    $stmt->execute([$studentId]);
    $enrollments = $stmt->fetchAll();
} catch (Exception $e) {
    $enrollments = [];
}

// ترجمة صلة القرابة
$relLabels = [
    'father' => 'أب',
    'mother' => 'أم',
    'guardian' => 'وصي',
    'other' => 'أخرى'
];

// ترجمة حالة التسجيل
$enrollStatusLabels = [
    'enrolled' => 'مسجل',
    'transferred' => 'منقول',
    'dropped' => 'منسحب'
];
?>

<!-- بطاقة معلومات الطالب -->
<div class="card mb-6">
    <div class="card-header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h3 class="card-title">🎓 بيانات الطالب</h3>
            <div class="flex gap-2">
                <a href="edit.php?id=<?= $studentId ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                <?php if ($student['is_active']): ?>
                    <a href="toggle.php?id=<?= $studentId ?>" class="btn btn-danger btn-sm" onclick="return confirm('هل أنت متأكد من تعطيل هذا الطالب؟')">🚫 تعطيل</a>
                <?php else: ?>
                    <a href="toggle.php?id=<?= $studentId ?>" class="btn btn-success btn-sm" onclick="return confirm('هل أنت متأكد من تفعيل هذا الطالب؟')">✅ تفعيل</a>
                <?php endif; ?>
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
                            <td style="font-weight:600;width:160px;">رقم الطالب</td>
                            <td><code style="font-size:14px;"><?= e($student['student_number']) ?></code></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">الاسم بالعربية</td>
                            <td><?= e($student['full_name_ar']) ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">الاسم بالإنجليزية</td>
                            <td dir="ltr"><?= e($student['full_name_en'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">المرحلة الدراسية</td>
                            <td>
                                <span class="badge badge-info"><?= e($student['grade_name'] ?? '—') ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr>
                            <td style="font-weight:600;width:160px;">المدرسة</td>
                            <td><?= e($student['school_name'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">تاريخ التسجيل</td>
                            <td><?= $student['enrollment_date'] ? formatDateAr($student['enrollment_date'], 'medium') : '—' ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">الحالة</td>
                            <td>
                                <?php if ($student['is_active']): ?>
                                    <span class="badge badge-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">غير نشط</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">تاريخ الإنشاء</td>
                            <td><?= formatDateAr($student['created_at'], 'medium') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">آخر تحديث</td>
                            <td><?= formatDateAr($student['updated_at'], 'medium') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- معلومات ولي الأمر -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">👨‍👩‍👧 معلومات ولي الأمر</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr>
                            <td style="font-weight:600;width:160px;">اسم ولي الأمر</td>
                            <td><?= e($student['guardian_name'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">هاتف ولي الأمر</td>
                            <td>
                                <?php if (!empty($student['guardian_phone'])): ?>
                                    <a href="tel:<?= e($student['guardian_phone']) ?>" dir="ltr"><?= e($student['guardian_phone']) ?></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div>
                <table class="data-table" style="box-shadow:none;">
                    <tbody>
                        <tr>
                            <td style="font-weight:600;width:160px;">بريد ولي الأمر</td>
                            <td>
                                <?php if (!empty($student['guardian_email'])): ?>
                                    <a href="mailto:<?= e($student['guardian_email']) ?>" dir="ltr"><?= e($student['guardian_email']) ?></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- حسابات أولياء الأمور المرتبطين -->
<?php if (!empty($parents)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">🔗 حسابات أولياء الأمور المرتبطين</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم ولي الأمر</th>
                        <th>اسم المستخدم</th>
                        <th>صلة القرابة</th>
                        <th>الهاتف</th>
                        <th>البريد الإلكتروني</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($parents as $parent): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= e($parent['parent_name'] ?? '—') ?></td>
                            <td><?= e($parent['username'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-warning">
                                    <?= e($relLabels[$parent['relationship']] ?? $parent['relationship']) ?>
                                </span>
                            </td>
                            <td dir="ltr"><?= e($parent['phone'] ?? '—') ?></td>
                            <td dir="ltr"><?= e($parent['email'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- تسجيلات الطالب في الفصول -->
<?php if (!empty($enrollments)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">📚 التسجيلات الدراسية</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الفصل</th>
                        <th>العام الدراسي</th>
                        <th>تاريخ التسجيل</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($enrollments as $enrollment): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= e($enrollment['class_name'] ?? '—') ?></td>
                            <td><?= e($enrollment['year_name'] ?? '—') ?></td>
                            <td><?= formatDateAr($enrollment['enrollment_date'], 'medium') ?></td>
                            <td>
                                <?php
                                $status = $enrollment['status'] ?? 'enrolled';
                                $statusLabel = $enrollStatusLabels[$status] ?? $status;
                                $statusColor = $status === 'enrolled' ? 'success' : ($status === 'transferred' ? 'warning' : 'danger');
                                ?>
                                <span class="badge badge-<?= $statusColor ?>"><?= e($statusLabel) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
