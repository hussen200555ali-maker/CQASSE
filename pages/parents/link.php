<?php
/**
 * ============================================================
 * إدارة ربط أولياء الأمور بالطلاب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إدارة ربط أولياء الأمور';
$breadcrumb = [
    'الإدارة' => null,
    'أولياء الأمور' => null,
    'إدارة الربط' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'] ?? '';
$userSchoolId = $_SESSION['user']['school_id'] ?? null;
$isGroupAdmin = ($userRole === 'group_admin');

// جلب المدارس لمدراء المجموعة
if ($isGroupAdmin) {
    $schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
}
?>

<style>
.parent-link-row { transition: background .15s; }
.parent-link-row:hover { background: #f8fafc; }
.link-search-result {
    cursor: pointer; padding: 8px 12px; border-bottom: 1px solid #e2e8f0;
    transition: background .15s;
}
.link-search-result:hover { background: #eef2ff; }
.link-search-result:last-child { border-bottom: none; }
.search-results-box {
    position: absolute; top: 100%; right: 0; left: 0; z-index: 50;
    background: #fff; border: 1px solid #cbd5e1; border-radius: 6px;
    max-height: 220px; overflow-y: auto; box-shadow: 0 4px 12px rgba(0,0,0,.1);
    display: none;
}
</style>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🔗 إدارة ربط أولياء الأمور بالطلاب</h3>
    </div>
    <div class="card-body">

        <?php if ($isGroupAdmin): ?>
        <div class="form-group">
            <label class="form-label">المدرسة</label>
            <select id="schoolFilter" class="form-control" onchange="loadStudents()">
                <option value="">اختر المدرسة</option>
                <?php foreach ($schools as $s): ?>
                <option value="<?= $s['id'] ?>"><?= e($s['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="divider"></div>
        <?php endif; ?>

        <div class="grid grid-2 gap-4" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <!-- الجانب الأيمن: اختيار الطالب -->
            <div>
                <div class="card" style="height:100%;">
                    <div class="card-header">
                        <h4 class="card-title">👤 اختيار الطالب</h4>
                    </div>
                    <div class="card-body">
                        <div class="form-group" style="position:relative;">
                            <label class="form-label">ابحث عن طالب</label>
                            <input type="text" id="studentSearch" class="form-control"
                                   placeholder="اسم الطالب أو رقمه..." autocomplete="off"
                                   oninput="searchStudents(this.value)">
                            <div id="studentResults" class="search-results-box"></div>
                        </div>
                        <div id="selectedStudentInfo" class="mt-3" style="display:none;">
                            <div class="flash-message flash-info">
                                <span id="selectedStudentName"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- الجانب الأيسر: اختيار ولي الأمر -->
            <div>
                <div class="card" style="height:100%;">
                    <div class="card-header">
                        <h4 class="card-title">👤 اختيار ولي الأمر</h4>
                    </div>
                    <div class="card-body">
                        <div class="form-group" style="position:relative;">
                            <label class="form-label">ابحث عن ولي أمر</label>
                            <input type="text" id="parentSearch" class="form-control"
                                   placeholder="اسم أو جوال أو بريد..." autocomplete="off"
                                   oninput="searchParents(this.value)">
                            <div id="parentResults" class="search-results-box"></div>
                        </div>
                        <div id="selectedParentInfo" class="mt-3" style="display:none;">
                            <div class="flash-message flash-info">
                                <span id="selectedParentName"></span>
                            </div>
                        </div>

                        <div class="divider mt-4">أو</div>

                        <div class="form-group">
                            <label class="form-label">العلاقة</label>
                            <select id="relationshipSelect" class="form-control">
                                <option value="father">أب</option>
                                <option value="mother">أم</option>
                                <option value="guardian">وصي</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>

                        <button class="btn btn-primary" id="linkBtn" onclick="linkParent()" disabled>
                            🔗 ربط ولي الأمر
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- جدول الروابط الحالية -->
        <h4 class="mb-3">الروابط الحالية</h4>
        <div class="table-wrapper">
            <table class="data-table" id="linksTable">
                <thead>
                    <tr>
                        <th>الطالب</th>
                        <th>ولي الأمر</th>
                        <th>اسم المستخدم</th>
                        <th>العلاقة</th>
                        <th>الهاتف</th>
                        <th>البريد</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="linksBody">
                    <tr><td colspan="7" class="text-center" style="color:#94a3b8;">اختر طالباً لعرض الروابط</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ========== متغيرات الحالة ==========
let selectedStudentId = null;
let selectedParentId = null;
let selectedSchoolId = <?= $isGroupAdmin ? 'null' : ($userSchoolId ?: 'null') ?>;

// ========== دوال البحث ==========
let studentSearchTimer = null;
function searchStudents(q) {
    clearTimeout(studentSearchTimer);
    if (q.length < 2) { document.getElementById('studentResults').style.display = 'none'; return; }
    studentSearchTimer = setTimeout(() => {
        const schoolParam = selectedSchoolId ? '&school_id=' + selectedSchoolId : (<?= $isGroupAdmin ? 'getSchoolFilter()' : '""' ?>);
        fetch('../../api/search_students.php?mode=general&q=' + encodeURIComponent(q) + schoolParam, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json())
            .then(data => {
                const box = document.getElementById('studentResults');
                const students = data.students || [];
                if (students.length === 0) { box.style.display = 'none'; return; }
                box.innerHTML = students.map(s =>
                    '<div class="link-search-result" onclick="selectStudent(' + s.id + ',\'' + s.full_name_ar.replace(/'/g,"\\'") + '\',\'' + (s.student_number || '') + '\')">' +
                    '<strong>' + s.full_name_ar + '</strong>' +
                    (s.student_number ? ' <span style="color:#64748b;font-size:12px;">(' + s.student_number + ')</span>' : '') +
                    (s.grade_name ? ' <span style="color:#94a3b8;font-size:11px;">' + s.grade_name + '</span>' : '') +
                    '</div>'
                ).join('');
                box.style.display = 'block';
            });
    }, 300);
}

let parentSearchTimer = null;
function searchParents(q) {
    clearTimeout(parentSearchTimer);
    if (q.length < 2) { document.getElementById('parentResults').style.display = 'none'; return; }
    parentSearchTimer = setTimeout(() => {
        fetch('../../api/search_parents.php?q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json())
            .then(data => {
                const box = document.getElementById('parentResults');
                const parents = data.parents || [];
                if (parents.length === 0) { box.style.display = 'none'; return; }
                box.innerHTML = parents.map(p =>
                    '<div class="link-search-result" onclick="selectParent(' + p.id + ',\'' + p.full_name_ar.replace(/'/g,"\\'") + '\',\'' + (p.phone || '') + '\',\'' + (p.email || '') + '\')">' +
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

// ========== اختيار العناصر ==========
function selectStudent(id, name, number) {
    selectedStudentId = id;
    document.getElementById('studentSearch').value = name + (number ? ' (' + number + ')' : '');
    document.getElementById('studentResults').style.display = 'none';
    document.getElementById('selectedStudentInfo').style.display = 'block';
    document.getElementById('selectedStudentName').textContent = '✅ تم اختيار: ' + name;
    checkLinkBtn();
    loadLinks(id);
}

function selectParent(id, name, phone, email) {
    selectedParentId = id;
    const display = name + (phone ? ' - ' + phone : '') + (email ? ' - ' + email : '');
    document.getElementById('parentSearch').value = display;
    document.getElementById('parentResults').style.display = 'none';
    document.getElementById('selectedParentInfo').style.display = 'block';
    document.getElementById('selectedParentName').textContent = '✅ تم اختيار: ' + name;
    checkLinkBtn();
}

function checkLinkBtn() {
    document.getElementById('linkBtn').disabled = !(selectedStudentId && selectedParentId);
}

// ========== الربط ==========
function linkParent() {
    if (!selectedStudentId || !selectedParentId) return;
    const relationship = document.getElementById('relationshipSelect').value;
    const btn = document.getElementById('linkBtn');
    btn.disabled = true;
    btn.textContent = '⏳ جاري الربط...';

    const formData = new FormData();
    formData.append('parent_id', selectedParentId);
    formData.append('student_id', selectedStudentId);
    formData.append('relationship', relationship);

    fetch('../../api/link_parent.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash('✅ ' + data.message, 'success');
                loadLinks(selectedStudentId);
                selectedParentId = null;
                document.getElementById('parentSearch').value = '';
                document.getElementById('selectedParentInfo').style.display = 'none';
                checkLinkBtn();
            } else {
                showFlash('❌ ' + data.message, 'danger');
            }
        })
        .catch(() => showFlash('❌ حدث خطأ في الاتصال', 'danger'))
        .finally(() => { btn.disabled = false; btn.textContent = '🔗 ربط ولي الأمر'; });
}

function unlinkParent(linkId, parentName, studentName) {
    if (!confirm('هل أنت متأكد من فصل "' + parentName + '" عن "' + studentName + '"؟')) return;
    const formData = new FormData();
    formData.append('link_id', linkId);
    fetch('../../api/unlink_parent.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash('✅ ' + data.message, 'success');
                loadLinks(selectedStudentId);
            } else {
                showFlash('❌ ' + data.message, 'danger');
            }
        })
        .catch(() => showFlash('❌ حدث خطأ في الاتصال', 'danger'));
}

// ========== تحميل الروابط ==========
function loadLinks(studentId) {
    if (!studentId) {
        document.getElementById('linksBody').innerHTML = '<tr><td colspan="7" class="text-center" style="color:#94a3b8;">اختر طالباً لعرض الروابط</td></tr>';
        return;
    }
    fetch('../../api/search_parents.php?q=&student_id=' + studentId)
        .catch(() => '{}')
        .then(r => r.json())
        .then(data => {
            // مؤقتاً نستخدم query بسيط — نعرض الروابط من خلال استعلام مباشر عبر واجهة AJAX
            // بدلاً من ذلك، نستخدم استعلام SQL في PHP مباشرة
            loadLinksDirect(studentId);
        });
}

function loadLinksDirect(studentId) {
    // استعلام مباشر عبر صفحة PHP لعرض الروابط الحالية
    const schoolParam = selectedSchoolId ? '&school_id=' + selectedSchoolId : '';
    fetch('../../pages/parents/_links_ajax.php?student_id=' + studentId + schoolParam)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('linksBody');
            if (!data.links || data.links.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="color:#94a3b8;">لا توجد روابط حالية لهذا الطالب</td></tr>';
                return;
            }
            const relLabels = { father: 'أب', mother: 'أم', guardian: 'وصي', other: 'أخرى' };
            tbody.innerHTML = data.links.map(l =>
                '<tr class="parent-link-row">' +
                '<td>' + l.student_name + '</td>' +
                '<td><strong>' + l.parent_name + '</strong></td>' +
                '<td style="direction:ltr;text-align:left;font-family:monospace;font-size:12px;">' + l.username + '</td>' +
                '<td><span class="badge badge-info">' + (relLabels[l.relationship] || l.relationship) + '</span></td>' +
                '<td>' + (l.phone || '—') + '</td>' +
                '<td>' + (l.email || '—') + '</td>' +
                '<td><button class="btn btn-sm btn-danger" onclick="unlinkParent(' + l.id + ',\'' + l.parent_name.replace(/'/g,"\\'") + '\',\'' + l.student_name.replace(/'/g,"\\'") + '\')">فصل</button></td>' +
                '</tr>'
            ).join('');
        });
}

// ========== الفلترة ==========
function getSchoolFilter() {
    const v = document.getElementById('schoolFilter')?.value;
    return v ? '&school_id=' + v : '';
}

function loadStudents() {
    if (<?= $isGroupAdmin ? 'true' : 'false' ?>) {
        selectedSchoolId = document.getElementById('schoolFilter')?.value || null;
    }
    selectedStudentId = null;
    document.getElementById('studentSearch').value = '';
    document.getElementById('selectedStudentInfo').style.display = 'none';
    document.getElementById('linksBody').innerHTML = '<tr><td colspan="7" class="text-center" style="color:#94a3b8;">اختر طالباً لعرض الروابط</td></tr>';
    checkLinkBtn();
}

// ========== الفلاش ==========
function showFlash(msg, type) {
    const existing = document.querySelector('.custom-flash');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.className = 'custom-flash flash-message flash-' + type + ' mb-4';
    div.innerHTML = '<span>' + msg + '</span>';
    document.querySelector('.card-body').insertBefore(div, document.querySelector('.card-body').firstChild);
    setTimeout(() => div.remove(), 5000);
}

// إخفاء نتائج البحث عند النقر خارجها
document.addEventListener('click', function(e) {
    if (!e.target.closest('#studentResults') && !e.target.closest('#studentSearch')) {
        document.getElementById('studentResults').style.display = 'none';
    }
    if (!e.target.closest('#parentResults') && !e.target.closest('#parentSearch')) {
        document.getElementById('parentResults').style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
