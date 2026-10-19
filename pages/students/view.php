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
                <button type="button" class="btn btn-info btn-sm" onclick="openParentModal()">🔗 ربط ولي أمر</button>
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

<!-- نافذة ربط ولي الأمر -->
<div id="parentLinkModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div class="modal-content" style="background:#fff;border-radius:12px;width:90%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
            <h4 style="margin:0;">🔗 ربط ولي أمر بالطالب</h4>
            <button type="button" onclick="closeParentModal()" style="background:none;border:none;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px;">
            <div class="flash-message flash-info mb-4">
                <span id="modalStudentName"><?= e($student['full_name_ar']) ?> (<?= e($student['student_number']) ?>)</span>
            </div>

            <!-- الروابط الحالية -->
            <h5 style="margin:0 0 8px 0;">الروابط الحالية</h5>
            <div id="modalLinksList" style="margin-bottom:16px;font-size:13px;color:#64748b;">جاري التحميل...</div>

            <div class="divider"></div>

            <!-- بحث ولي أمر -->
            <h5 style="margin:12px 0 8px 0;">ربط ولي أمر جديد</h5>
            <div class="form-group" style="position:relative;">
                <label class="form-label">ابحث عن ولي أمر</label>
                <input type="text" id="modalParentSearch" class="form-control"
                       placeholder="اسم أو جوال أو بريد..." autocomplete="off"
                       oninput="modalSearchParents(this.value)">
                <div id="modalParentResults" style="position:absolute;top:100%;right:0;left:0;z-index:60;background:#fff;border:1px solid #cbd5e1;border-radius:6px;max-height:200px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);display:none;"></div>
            </div>

            <div class="grid grid-2 gap-3" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
                <div class="form-group">
                    <label class="form-label">العلاقة</label>
                    <select id="modalRelationship" class="form-control">
                        <option value="father">أب</option>
                        <option value="mother">أم</option>
                        <option value="guardian">وصي</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button class="btn btn-primary" id="modalLinkBtn" onclick="modalLinkParent()" disabled style="width:100%;">
                        🔗 ربط
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.parent-link-chip {
    display:inline-flex;align-items:center;gap:8px;padding:4px 12px;margin:2px 4px;
    background:#f1f5f9;border-radius:20px;font-size:13px;white-space:nowrap;
}
.parent-link-chip .unlink-btn {
    background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;padding:0;line-height:1;
}
.parent-link-chip .unlink-btn:hover { color:#dc2626; }
</style>

<script>
let modalSelectedParentId = null;
const modalStudentId = <?= $studentId ?>;

function openParentModal() {
    document.getElementById('parentLinkModal').style.display = 'flex';
    loadModalLinks();
}

function closeParentModal() {
    document.getElementById('parentLinkModal').style.display = 'none';
    modalSelectedParentId = null;
    document.getElementById('modalParentSearch').value = '';
    document.getElementById('modalParentResults').style.display = 'none';
    document.getElementById('modalLinkBtn').disabled = true;
}

// النقر خارج المحتوى لإغلاق النافذة
document.getElementById('parentLinkModal').addEventListener('click', function(e) {
    if (e.target === this) closeParentModal();
});

// بحث ولي الأمر
let modalParentTimer = null;
function modalSearchParents(q) {
    clearTimeout(modalParentTimer);
    if (q.length < 2) { document.getElementById('modalParentResults').style.display = 'none'; return; }
    modalParentTimer = setTimeout(() => {
        fetch('../../api/search_parents.php?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(r => r.json())
            .then(data => {
                const box = document.getElementById('modalParentResults');
                const parents = data.parents || [];
                if (parents.length === 0) { box.style.display = 'none'; return; }
                box.innerHTML = parents.map(p =>
                    '<div class="link-search-result" onclick="modalSelectParent(' + p.id + ',\'' + p.full_name_ar.replace(/'/g,"\\'") + '\',\'' + (p.phone || '') + '\',\'' + (p.email || '') + '\')" style="cursor:pointer;padding:8px 12px;border-bottom:1px solid #e2e8f0;transition:background .15s;">' +
                    '<strong>' + p.full_name_ar + '</strong>' +
                    (p.phone ? ' <span style="color:#64748b;font-size:12px;">' + p.phone + '</span>' : '') +
                    (p.email ? ' <span style="color:#94a3b8;font-size:11px;">' + p.email + '</span>' : '') +
                    ' <span style="color:#7c3aed;font-size:11px;">(' + p.username + ')</span>' +
                    '</div>'
                ).join('');
                box.style.display = 'block';
            });
    }, 300);
}

function modalSelectParent(id, name, phone, email) {
    modalSelectedParentId = id;
    const display = name + (phone ? ' - ' + phone : '') + (email ? ' - ' + email : '');
    document.getElementById('modalParentSearch').value = display;
    document.getElementById('modalParentResults').style.display = 'none';
    document.getElementById('modalLinkBtn').disabled = false;
}

// الربط
function modalLinkParent() {
    if (!modalSelectedParentId) return;
    const relationship = document.getElementById('modalRelationship').value;
    const btn = document.getElementById('modalLinkBtn');
    btn.disabled = true;
    btn.textContent = '⏳...';

    const formData = new FormData();
    formData.append('parent_id', modalSelectedParentId);
    formData.append('student_id', modalStudentId);
    formData.append('relationship', relationship);

    fetch('../../api/link_parent.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                modalSelectedParentId = null;
                document.getElementById('modalParentSearch').value = '';
                document.getElementById('modalLinkBtn').disabled = true;
                loadModalLinks();
                // إظهار رسالة نجاح
                const flash = document.createElement('div');
                flash.className = 'flash-message flash-success mb-3';
                flash.innerHTML = '<span>✅ ' + data.message + '</span>';
                document.querySelector('.modal-body').insertBefore(flash, document.querySelector('.modal-body').firstChild);
                setTimeout(() => flash.remove(), 4000);
            } else {
                alert('❌ ' + data.message);
            }
        })
        .catch(() => alert('❌ حدث خطأ في الاتصال'))
        .finally(() => { btn.disabled = false; btn.textContent = '🔗 ربط'; });
}

// تحميل الروابط الحالية
function loadModalLinks() {
    const list = document.getElementById('modalLinksList');
    list.textContent = 'جاري التحميل...';
    fetch('../../pages/parents/_links_ajax.php?student_id=' + modalStudentId)
        .then(r => r.json())
        .then(data => {
            if (!data.links || data.links.length === 0) {
                list.innerHTML = '<span style="color:#94a3b8;">لا توجد روابط حالية</span>';
                return;
            }
            const relLabels = { father: 'أب', mother: 'أم', guardian: 'وصي', other: 'أخرى' };
            list.innerHTML = data.links.map(l =>
                '<span class="parent-link-chip">' +
                '<strong>' + l.parent_name + '</strong>' +
                ' <span style="color:#64748b;font-size:11px;">(' + (relLabels[l.relationship] || l.relationship) + ')</span>' +
                '<button class="unlink-btn" onclick="modalUnlinkParent(' + l.id + ',\'' + l.parent_name.replace(/'/g,"\\'") + '\')" title="فصل">&times;</button>' +
                '</span>'
            ).join('');
        })
        .catch(() => { list.innerHTML = '<span style="color:#ef4444;">خطأ في تحميل الروابط</span>'; });
}

function modalUnlinkParent(linkId, parentName) {
    if (!confirm('هل أنت متأكد من فصل "' + parentName + '" عن هذا الطالب؟')) return;
    const formData = new FormData();
    formData.append('link_id', linkId);
    fetch('../../api/unlink_parent.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadModalLinks();
                const flash = document.createElement('div');
                flash.className = 'flash-message flash-success mb-3';
                flash.innerHTML = '<span>✅ ' + data.message + '</span>';
                document.querySelector('.modal-body').insertBefore(flash, document.querySelector('.modal-body').firstChild);
                setTimeout(() => flash.remove(), 4000);
            } else {
                alert('❌ ' + data.message);
            }
        })
        .catch(() => alert('❌ حدث خطأ في الاتصال'));
}

// إخفاء نتائج البحث عند النقر خارجها
document.addEventListener('click', function(e) {
    if (!e.target.closest('#modalParentResults') && !e.target.closest('#modalParentSearch')) {
        document.getElementById('modalParentResults').style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
