<?php
/**
 * ============================================================
 * EQAS - كشوف الدرجات - لوحة التحكم
 * نظام التقييم الشامل - صفحة كشوف الدرجات الرئيسية
 * ============================================================
 */

$pageTitle = 'كشوف الدرجات';
$breadcrumb = ['كشوف الدرجات' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

// فلاتر
$filterSchool = $_GET['school_id'] ?? '';
$filterYear = $_GET['academic_year_id'] ?? getCurrentAcademicYearId($db);
$filterSemester = $_GET['semester_id'] ?? '';
$filterClass = $_GET['class_id'] ?? '';

// جلب المدارس
$schools = [];
if (hasPermission('group_admin')) {
    $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    $schools = $stmt->fetchAll();
}

// جلب السنوات الأكاديمية
$stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
$academicYears = $stmt->fetchAll();

// جلب الفصول الدراسية
$semesters = [];
if ($filterYear) {
    $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
    $stmt->execute([$filterYear]);
    $semesters = $stmt->fetchAll();
}

// جلب الفصول (الصفوف)
$classes = [];
if ($filterYear) {
    $schoolFilter = '';
    $params = [$filterYear];
    if ($filterSchool) {
        $schoolFilter = " AND c.school_id = ? ";
        $params[] = $filterSchool;
    } elseif (!hasPermission('group_admin')) {
        $schoolId = $_SESSION['user']['school_id'] ?? 0;
        $schoolFilter = " AND c.school_id = ? ";
        $params[] = $schoolId;
    }

    // للمعلم: فلترة إضافية حسب فصوله المسندة
    $currentRole = $_SESSION['user']['role'] ?? '';
    if ($currentRole === 'teacher') {
        $teacherId = $_SESSION['user']['id'] ?? 0;
        $allowedIds = getTeacherAssignedClassIds($db, $teacherId, $schoolId, $filterYear);
        if (!empty($allowedIds)) {
            $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
            $schoolFilter .= " AND c.id IN ($placeholders)";
            $params = array_merge($params, $allowedIds);
        } else {
            $schoolFilter .= " AND 1=0";
        }
    }

    $stmt = $db->prepare("SELECT c.id, c.name_ar, gl.name_ar as grade_name, s.name_ar as school_name
                          FROM classes c
                          JOIN grade_levels gl ON c.grade_level_id = gl.id
                          JOIN schools s ON c.school_id = s.id
                          WHERE c.academic_year_id = ? AND c.is_active = 1 {$schoolFilter}
                          ORDER BY s.name_ar, gl.level_number, c.name_ar");
    $stmt->execute($params);
    $classes = $stmt->fetchAll();
}

// جلب الكشوف المتاحة (مجموعات الدرجات)
$gradeSheets = [];
if ($filterYear && $filterSemester) {
    $sql = "SELECT c.id as class_id, c.name_ar as class_name, gl.name_ar as grade_name, s.name_ar as school_name,
            COUNT(DISTINCT g.student_id) as student_count,
            COUNT(DISTINCT t.subject_id) as subject_count,
            COUNT(g.id) as grade_count,
            ROUND(AVG(g.grade_value), 2) as avg_grade
            FROM classes c
            JOIN grade_levels gl ON c.grade_level_id = gl.id
            JOIN schools s ON c.school_id = s.id
            LEFT JOIN tests t ON t.class_id = c.id AND t.academic_year_id = ? AND t.semester_id = ?
            LEFT JOIN grades g ON g.test_id = t.id AND g.is_active = 1
            WHERE c.academic_year_id = ? AND c.is_active = 1";

    $params = [$filterYear, $filterSemester, $filterYear];

    if ($filterSchool) {
        $sql .= " AND c.school_id = ?";
        $params[] = $filterSchool;
    } elseif (!hasPermission('group_admin')) {
        $schoolId = $_SESSION['user']['school_id'] ?? 0;
        $sql .= " AND c.school_id = ?";
        $params[] = $schoolId;
    }

    // للمعلم: فلترة إضافية حسب فصوله المسندة
    $gsRole = $_SESSION['user']['role'] ?? '';
    if ($gsRole === 'teacher') {
        $gsTeacherId = $_SESSION['user']['id'] ?? 0;
        $gsAllowedIds = getTeacherAssignedClassIds($db, $gsTeacherId, $schoolId, $filterYear);
        if (!empty($gsAllowedIds)) {
            $placeholders = implode(',', array_fill(0, count($gsAllowedIds), '?'));
            $sql .= " AND c.id IN ($placeholders)";
            $params = array_merge($params, $gsAllowedIds);
        } else {
            $sql .= " AND 1=0";
        }
    }

    if ($filterClass) {
        $sql .= " AND c.id = ?";
        $params[] = $filterClass;
    }

    $sql .= " GROUP BY c.id ORDER BY s.name_ar, gl.level_number, c.name_ar";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $gradeSheets = $stmt->fetchAll();
}
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">🔍 فلترة كشوف الدرجات</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="form-grid">
            <?php if (hasPermission('group_admin')): ?>
            <div class="form-group">
                <label class="form-label">المدرسة</label>
                <select name="school_id" class="form-control">
                    <option value="">جميع المدارس</option>
                    <?php foreach ($schools as $school): ?>
                    <option value="<?php echo $school['id']; ?>" <?php echo $filterSchool == $school['id'] ? 'selected' : ''; ?>>
                        <?php echo e($school['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">السنة الأكاديمية</label>
                <select name="academic_year_id" class="form-control">
                    <option value="">اختر السنة</option>
                    <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo $year['id']; ?>" <?php echo $filterYear == $year['id'] ? 'selected' : ''; ?>>
                        <?php echo e($year['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">الفصل الدراسي</label>
                <select name="semester_id" class="form-control">
                    <option value="">اختر الفصل</option>
                    <?php foreach ($semesters as $sem): ?>
                    <option value="<?php echo $sem['id']; ?>" <?php echo $filterSemester == $sem['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sem['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">الفصل (الصف)</label>
                <select name="class_id" class="form-control">
                    <option value="">جميع الفصول</option>
                    <?php foreach ($classes as $cls): ?>
                    <option value="<?php echo $cls['id']; ?>" <?php echo $filterClass == $cls['id'] ? 'selected' : ''; ?>>
                        <?php echo e($cls['school_name'] . ' - ' . $cls['grade_name'] . ' - ' . $cls['name_ar']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary">
                    🔍 بحث
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">📊 كشوف الدرجات المتاحة</h2>
        <a href="generate.php?academic_year_id=<?php echo e($filterYear); ?>&semester_id=<?php echo e($filterSemester); ?>" class="btn btn-primary">
            ➕ إنشاء كشف جديد
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($gradeSheets)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <p class="empty-state-text">لا توجد كشوف درجات متاحة. يرجى تحديد الفلاتر والبحث.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المدرسة</th>
                            <th>الصف</th>
                            <th>الفصل</th>
                            <th>عدد الطلاب</th>
                            <th>عدد المواد</th>
                            <th>متوسط الدرجات</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gradeSheets as $i => $sheet): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo e($sheet['school_name']); ?></td>
                            <td><?php echo e($sheet['grade_name']); ?></td>
                            <td><?php echo e($sheet['class_name']); ?></td>
                            <td><span class="badge badge-info"><?php echo $sheet['student_count']; ?></span></td>
                            <td><span class="badge badge-secondary"><?php echo $sheet['subject_count']; ?></span></td>
                            <td>
                                <?php if ($sheet['avg_grade'] !== null): ?>
                                    <span class="badge <?php echo $sheet['avg_grade'] >= 50 ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $sheet['avg_grade']; ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">لا توجد درجات</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="view.php?class_id=<?php echo $sheet['class_id']; ?>&academic_year_id=<?php echo e($filterYear); ?>&semester_id=<?php echo e($filterSemester); ?>"
                                   class="btn btn-sm btn-info" title="عرض الكشف">
                                    👁️ عرض
                                </a>
                                <a href="generate.php?class_id=<?php echo $sheet['class_id']; ?>&academic_year_id=<?php echo e($filterYear); ?>&semester_id=<?php echo e($filterSemester); ?>"
                                   class="btn btn-sm btn-success" title="إنشاء/تحديث">
                                    🔄 إنشاء
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
