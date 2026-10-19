<?php
/**
 * ============================================================
 * تسجيل سلوك
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * تسجيل حادثة سلوكية (إيجابية أو سلبية) لطالب معين
 * باستخدام فئات السلوك ونظام النقاط (BehaviorCalculator)
 */

$pageTitle = 'تسجيل سلوك';
$breadcrumb = ['السلوك' => 'index.php', 'تسجيل سلوك' => null];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$schoolId = $_SESSION['user']['school_id'] ?? null;
$academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);

// جلب الفصل الدراسي الحالي
$semesterId = null;
try {
    $stmt = $db->prepare("SELECT id FROM semesters WHERE is_current = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $sem = $stmt->fetch();
    $semesterId = $sem ? $sem['id'] : null;
} catch (Exception $e) {}

// تحميل محسب السلوك ودوال الفلترة
require_once __DIR__ . '/../../includes/behavior_calculator.php';
require_once __DIR__ . '/../../includes/filters.php';
$behaviorCalc = new BehaviorCalculator($db);

// ============================================================
// معالجة تسجيل السلوك
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_incident'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
    } else {
        $studentId  = (int)($_POST['student_id'] ?? 0);
        $classId    = (int)($_POST['class_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $incidentDate = $_POST['incident_date'] ?? date('Y-m-d');

        // فحص أمان: المعلم فقط لفصوله المسندة
        if ($userRole === 'teacher') {
            $assignedClasses = getTeacherAssignedClassIds($db, $userId, $schoolId);
            if (!in_array($classId, $assignedClasses)) {
                setFlash('ليس لديك صلاحية لتسجيل سلوك في هذا الفصل', 'danger');
                redirect('incident.php');
            }
            // التحقق من أن الطالب مسجل في هذا الفصل
            $checkStmt = $db->prepare("SELECT 1 FROM student_enrollments WHERE student_id = ? AND class_id = ? AND is_active = 1 AND status = 'enrolled'");
            $checkStmt->execute([$studentId, $classId]);
            if (!$checkStmt->fetch()) {
                setFlash('الطالب غير مسجل في هذا الفصل', 'danger');
                redirect('incident.php');
            }
        }

        if (empty($studentId) || empty($classId) || empty($categoryId)) {
            setFlash('يرجى ملء جميع الحقول المطلوبة', 'warning');
        } else {
            try {
                // جلب نوع الفئة (إيجابية/سلبية)
                $stmt = $db->prepare("SELECT type, name_ar, points FROM behavior_categories WHERE id = ? AND is_active = 1");
                $stmt->execute([$categoryId]);
                $category = $stmt->fetch();

                if (!$category) {
                    setFlash('فئة السلوك غير موجودة', 'danger');
                } else {
                    $categoryType = $category['type'];

                    // التأكد من تهيئة سجل السلوك للطالب
                    $behaviorCalc->initializeBehaviorScore($studentId, $classId, $academicYearId, $semesterId);

                    // تسجيل السلوك حسب النوع
                    if ($categoryType === 'positive') {
                        $result = $behaviorCalc->addPositiveBehavior(
                            $studentId, $categoryId, $classId,
                            $academicYearId, $semesterId, $description, $userId
                        );
                    } else {
                        $result = $behaviorCalc->addNegativeBehavior(
                            $studentId, $categoryId, $classId,
                            $academicYearId, $semesterId, $description, $userId
                        );
                    }

                    if ($result['success']) {
                        $msg = "تم تسجيل السلوك بنجاح. النسبة السلوكية الحالية: " . number_format($result['current_percentage'], 1) . "%";
                        if (isset($result['needs_alert']) && $result['needs_alert']) {
                            $msg .= " ⚠️ تنبيه: النسبة السلوكية أقل من 50%!";
                            setFlash($msg, 'warning');
                        } else {
                            setFlash($msg, 'success');
                        }

                        // تسجيل النشاط
                        logActivity($db, $userId, 'behavior_incident',
                            "تسجيل سلوك {$categoryType} للطالب رقم {$studentId}: {$category['name_ar']}",
                            'behavior_incidents', null);
                    } else {
                        setFlash($result['message'], 'danger');
                    }
                }
            } catch (Exception $e) {
                setFlash('حدث خطأ أثناء تسجيل السلوك: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// ============================================================
// جلب بيانات العرض
// ============================================================

// جلب الفصول — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($allowedIds)) {
            $ph = implode(',', array_fill(0, count($allowedIds), '?'));
            $sql = "SELECT c.id, c.name_ar FROM classes c WHERE c.id IN ($ph) AND c.is_active = 1 ORDER BY c.name_ar";
            $stmt = $db->prepare($sql);
            $stmt->execute($allowedIds);
            $classes = $stmt->fetchAll();
        }
    } else {
        $sql = "SELECT id, name_ar FROM classes WHERE is_active = 1";
        $params = [];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY name_ar";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// جلب الطلاب (حسب الفصل المحدد أو الكل)
$filterClass = $_GET['class_id'] ?? '';
$students = [];
if ($filterClass) {
    try {
        $roleFilter = buildRoleFilter($db, $userRole, $userId, $schoolId, 'se.class_id');
        $stmt = $db->prepare("
            SELECT s.id, s.student_number, s.full_name_ar
            FROM students s
            INNER JOIN student_enrollments se ON se.student_id = s.id AND se.class_id = ? AND se.is_active = 1 AND se.status = 'enrolled'
            WHERE s.is_active = 1
            {$roleFilter['sql']}
            ORDER BY s.full_name_ar
        ");
        $stmt->execute(array_merge([$filterClass], $roleFilter['params']));
        $students = $stmt->fetchAll();
    } catch (Exception $e) {}
} else {
    try {
        if ($userRole === 'teacher' && $schoolId) {
            $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
            $sql = "SELECT DISTINCT s.id, s.student_number, s.full_name_ar
                    FROM students s
                    JOIN student_enrollments se ON se.student_id = s.id AND se.is_active = 1 AND se.status = 'enrolled'
                    JOIN subject_teachers st ON st.classroom_id = se.class_id AND st.teacher_id = ? AND st.is_active = 1
                    WHERE s.is_active = 1 AND s.school_id = ?
                    UNION
                    SELECT DISTINCT s.id, s.student_number, s.full_name_ar
                    FROM students s
                    JOIN student_enrollments se ON se.student_id = s.id AND se.is_active = 1 AND se.status = 'enrolled'
                    JOIN classes c ON c.id = se.class_id AND c.teacher_id = ? AND c.academic_year_id = ?
                    WHERE s.is_active = 1 AND s.school_id = ?
                    ORDER BY full_name_ar LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $schoolId, $userId, $academicYearId, $schoolId]);
        } else {
            $sql = "SELECT id, student_number, full_name_ar FROM students WHERE is_active = 1";
            $params = [];
            if ($schoolId && $userRole !== 'group_admin') {
                $sql .= " AND school_id = ?";
                $params[] = $schoolId;
            }
            $sql .= " ORDER BY full_name_ar LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }
        $students = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// جلب فئات السلوك
$positiveCategories = [];
$negativeCategories = [];
try {
    $sql = "SELECT id, name_ar, type, points FROM behavior_categories WHERE is_active = 1";
    $params = [];
    if ($schoolId) {
        $sql .= " AND (school_id IS NULL OR school_id = ?)";
        $params[] = $schoolId;
    }
    $sql .= " ORDER BY type, name_ar";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allCategories = $stmt->fetchAll();

    foreach ($allCategories as $cat) {
        if ($cat['type'] === 'positive') {
            $positiveCategories[] = $cat;
        } else {
            $negativeCategories[] = $cat;
        }
    }
} catch (Exception $e) {}

// جلب آخر الحوادث المسجلة
$recentIncidents = [];
try {
    $sql = "SELECT bi.*, s.full_name_ar AS student_name, s.student_number,
                   bc.name_ar AS category_name, bc.type AS category_type,
                   u.full_name_ar AS reporter_name
            FROM behavior_incidents bi
            INNER JOIN students s ON s.id = bi.student_id
            INNER JOIN behavior_categories bc ON bc.id = bi.category_id
            LEFT JOIN users u ON u.id = bi.reported_by
            WHERE bi.is_active = 1
              AND bi.academic_year_id = ?
              AND bi.semester_id = ?";
    $params = [$academicYearId, $semesterId];
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }
    if ($userRole === 'teacher') {
        $sql .= " AND bi.reported_by = ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY bi.created_at DESC LIMIT 15";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recentIncidents = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب نسبة السلوك الحالية لطالب محدد
$selectedStudent = $_GET['student_id'] ?? '';
$currentBehavior = null;
if ($selectedStudent && $filterClass && $academicYearId && $semesterId) {
    $currentBehavior = $behaviorCalc->calculateCurrentPercentage($selectedStudent, $filterClass, $academicYearId, $semesterId);
}
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- نموذج تسجيل السلوك -->
    <div class="lg:col-span-2 section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📝 تسجيل سلوك جديد</h3>
            </div>
            <div class="card-body">
                <form method="POST" id="incident-form">
                    <?= csrfField() ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- الفصل -->
                        <div class="form-group">
                            <label class="form-label">الفصل: <span style="color:var(--danger);">*</span></label>
                            <select name="class_id" id="class-select" class="form-control" required>
                                <option value="">-- اختر الفصل --</option>
                                <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>" <?= $filterClass == $cls['id'] ? 'selected' : '' ?>>
                                    <?= e($cls['name_ar']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- الطالب -->
                        <div class="form-group">
                            <label class="form-label">الطالب: <span style="color:var(--danger);">*</span></label>
                            <select name="student_id" id="student-select" class="form-control" required>
                                <option value="">-- اختر الطالب --</option>
                                <?php foreach ($students as $st): ?>
                                <option value="<?= $st['id'] ?>" <?= $selectedStudent == $st['id'] ? 'selected' : '' ?>
                                        data-class="<?= $filterClass ?>">
                                    <?= e($st['full_name_ar']) ?> (<?= e($st['student_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- نوع السلوك -->
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">نوع السلوك: <span style="color:var(--danger);">*</span></label>
                        <div style="display:flex; gap:12px; margin-top:8px;">
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid var(--success); border-radius:var(--radius-md); transition:all 0.2s;"
                                   id="type-positive-label">
                                <input type="radio" name="incident_type" value="positive" id="type-positive" checked
                                       style="width:18px; height:18px;">
                                <span style="font-weight:600; color:var(--success);">✅ سلوك إيجابي</span>
                            </label>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid var(--danger); border-radius:var(--radius-md); transition:all 0.2s; opacity:0.5;"
                                   id="type-negative-label">
                                <input type="radio" name="incident_type" value="negative" id="type-negative"
                                       style="width:18px; height:18px;">
                                <span style="font-weight:600; color:var(--danger);">❌ سلوك سلبي</span>
                            </label>
                        </div>
                    </div>

                    <!-- فئة السلوك -->
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">فئة السلوك: <span style="color:var(--danger);">*</span></label>
                        <select name="category_id" id="category-select" class="form-control" required>
                            <option value="">-- اختر الفئة --</option>
                            <optgroup label="✅ سلوك إيجابي" id="positive-options">
                                <?php foreach ($positiveCategories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" data-type="positive" data-points="<?= $cat['points'] ?>">
                                    <?= e($cat['name_ar']) ?> (+<?= $cat['points'] ?> نقطة)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="❌ سلوك سلبي" id="negative-options">
                                <?php foreach ($negativeCategories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" data-type="negative" data-points="<?= $cat['points'] ?>">
                                    <?= e($cat['name_ar']) ?> (-<?= abs($cat['points']) ?> نقطة)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <!-- النقاط التلقائية -->
                    <div id="points-display" style="margin-top:12px; padding:10px 14px; background:var(--bg-secondary); border-radius:var(--radius-md); display:none;">
                        <span style="font-weight:600;">النقاط: </span>
                        <span id="points-value" style="font-size:18px; font-weight:700;"></span>
                    </div>

                    <!-- وصف الحادثة -->
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">وصف الحادثة:</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="أدخل وصفاً تفصيلياً للحادثة السلوكية..."></textarea>
                    </div>

                    <!-- تاريخ الحادثة -->
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">تاريخ الحادثة:</label>
                        <input type="date" name="incident_date" class="form-control" style="max-width:200px;"
                               value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>

                    <!-- النسبة السلوكية الحالية -->
                    <?php if ($currentBehavior): ?>
                    <div style="margin-top:16px; padding:12px; background:var(--bg-secondary); border-radius:var(--radius-md);">
                        <div style="font-weight:600; margin-bottom:4px;">📊 النسبة السلوكية الحالية للطالب:</div>
                        <div style="font-size:28px; font-weight:700; color:var(--<?= $currentBehavior['is_passing'] ? 'success' : 'danger' ?>);">
                            <?= number_format($currentBehavior['current_percentage'], 1) ?>%
                        </div>
                        <div style="font-size:12px; color:var(--text-muted);">
                            النقاط: <?= $currentBehavior['current_points'] ?> / <?= $currentBehavior['starting_points'] ?>
                            • <?= $currentBehavior['is_passing'] ? '✅ مستوٍ للمعيار' : '❌ دون المعيار (60%)' ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top:20px;">
                        <button type="submit" name="record_incident" class="btn btn-primary btn-lg">
                            💾 تسجيل السلوك
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- آخر الحوادث المسجلة -->
    <div class="section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📋 آخر السلوكيات المسجلة</h3>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($recentIncidents)): ?>
                <div style="text-align:center; padding:30px; color:var(--text-muted);">
                    <div style="font-size:32px; margin-bottom:8px;">📝</div>
                    لا توجد سلوكيات مسجلة بعد
                </div>
                <?php else: ?>
                <div style="max-height:500px; overflow-y:auto;">
                    <?php foreach ($recentIncidents as $inc):
                        $isPositive = $inc['category_type'] === 'positive';
                    ?>
                    <div style="padding:10px 16px; border-bottom:1px solid var(--border-color);">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <div style="font-weight:600; font-size:14px;">
                                    <?= e($inc['student_name']) ?>
                                </div>
                                <div style="font-size:12px; color:var(--text-muted);">
                                    <?= e($inc['category_name']) ?>
                                </div>
                            </div>
                            <span style="font-weight:700; color:var(--<?= $isPositive ? 'success' : 'danger' ?>); font-size:14px;">
                                <?= $isPositive ? '+' : '-' ?><?= $inc['points'] ?>
                            </span>
                        </div>
                        <?php if ($inc['description']): ?>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">
                            <?= e(truncate($inc['description'], 60)) ?>
                        </div>
                        <?php endif; ?>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                            <?= formatDateAr($inc['incident_date'], 'short') ?> • 
                            <?= e($inc['reporter_name'] ?? 'غير معروف') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- روابط سريعة -->
        <div class="card" style="margin-top:16px;">
            <div class="card-body" style="padding:12px;">
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <a href="scores.php" class="btn btn-light" style="text-align:right;">📊 نقاط السلوك</a>
                    <a href="history.php" class="btn btn-light" style="text-align:right;">📜 سجل السلوك</a>
                    <?php if (hasPermission('group_admin')): ?>
                    <a href="override.php" class="btn btn-light" style="text-align:right;">🔄 تجاوز معيار السلوك</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     جافاسكربت
     ============================================================ -->
<?php
$pageJs = <<<'JS'
// تبديل نوع السلوك (إيجابي/سلبي)
const typePositive = document.getElementById('type-positive');
const typeNegative = document.getElementById('type-negative');
const positiveLabel = document.getElementById('type-positive-label');
const negativeLabel = document.getElementById('type-negative-label');
const positiveOptions = document.getElementById('positive-options');
const negativeOptions = document.getElementById('negative-options');
const categorySelect = document.getElementById('category-select');
const pointsDisplay = document.getElementById('points-display');
const pointsValue = document.getElementById('points-value');

function updateTypeUI() {
    const isPositive = typePositive.checked;
    positiveLabel.style.opacity = isPositive ? '1' : '0.5';
    negativeLabel.style.opacity = isPositive ? '0.5' : '1';
    
    // إظهار/إخفاء المجموعات
    if (positiveOptions) positiveOptions.style.display = isPositive ? '' : 'none';
    if (negativeOptions) negativeOptions.style.display = isPositive ? 'none' : '';
    
    // إعادة تعيين الاختيار
    categorySelect.value = '';
    pointsDisplay.style.display = 'none';
}

if (typePositive) typePositive.addEventListener('change', updateTypeUI);
if (typeNegative) typeNegative.addEventListener('change', updateTypeUI);

// عرض النقاط عند اختيار الفئة
if (categorySelect) {
    categorySelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.dataset.points) {
            const points = parseFloat(option.dataset.points);
            const type = option.dataset.type;
            pointsDisplay.style.display = 'block';
            pointsValue.textContent = (type === 'positive' ? '+' : '-') + Math.abs(points) + ' نقطة';
            pointsValue.style.color = type === 'positive' ? 'var(--success)' : 'var(--danger)';
        } else {
            pointsDisplay.style.display = 'none';
        }
    });
}

// تحديث قائمة الطلاب عند تغيير الفصل
const classSelect = document.getElementById('class-select');
if (classSelect) {
    classSelect.addEventListener('change', function() {
        if (this.value) {
            window.location.href = 'incident.php?class_id=' + this.value;
        }
    });
}

// تهيئة الواجهة
updateTypeUI();
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
