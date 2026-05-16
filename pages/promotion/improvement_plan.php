<?php
/**
 * ============================================================
 * EQAS - خطة التحسين للترفيع المشروط
 * نظام التقييم الشامل - خطة تحسين للطلاب المشروط ترفيعهم
 * ============================================================
 */
require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول إلى هذه الصفحة.', 'danger');
    redirect(baseUrl('/index.php'));
}

$studentId = intval($_GET['student_id'] ?? 0);
$decisionId = intval($_GET['decision_id'] ?? 0);
$academicYearId = intval($_GET['academic_year_id'] ?? 0);
$semesterId = intval($_GET['semester_id'] ?? 0);

if (!$studentId || !$decisionId) {
    setFlash('بيانات غير مكتملة.', 'danger');
    redirect(baseUrl('/pages/promotion/index.php'));
}

// جلب بيانات الطالب
$student = $db->prepare("
    SELECT s.*, gl.name_ar as grade_name, c.name_ar as class_name
    FROM students s
    LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
    LEFT JOIN student_enrollments se ON s.id = se.student_id AND se.is_active = 1
    LEFT JOIN classes c ON se.class_id = c.id
    WHERE s.id = ? AND s.is_active = 1
");
$student->execute([$studentId]);
$student = $student->fetch();

if (!$student) {
    setFlash('الطالب غير موجود.', 'danger');
    redirect(baseUrl('/pages/promotion/index.php'));
}

// جلب قرار الترفيع
$decision = $db->prepare("
    SELECT pd.*, ay.name as year_name
    FROM promotions pd
    LEFT JOIN academic_years ay ON pd.academic_year_id = ay.id
    WHERE pd.id = ? AND pd.student_id = ? AND pd.is_active = 1
");
$decision->execute([$decisionId, $studentId]);
$decision = $decision->fetch();

if (!$decision || $decision['decision'] !== 'conditional') {
    setFlash('قرار الترفيع غير موجود أو ليس مشروطاً.', 'danger');
    redirect(baseUrl('/pages/promotion/index.php'));
}

// جلب خطة التحسين الحالية
$plan = $db->prepare("
    SELECT * FROM improvement_plans
    WHERE student_id = ? AND decision_id = ? AND is_active = 1
    ORDER BY created_at DESC LIMIT 1
");
$plan->execute([$studentId, $decisionId]);
$plan = $plan->fetch();

// معالجة حفظ خطة التحسين
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
        redirect(baseUrl("/pages/promotion/improvement_plan.php?student_id={$studentId}&decision_id={$decisionId}"));
    }

    $weaknesses = sanitize($_POST['weaknesses'] ?? '');
    $goals = sanitize($_POST['goals'] ?? '');
    $strategies = sanitize($_POST['strategies'] ?? '');
    $timeline = sanitize($_POST['timeline'] ?? '');
    $responsibleUser = intval($_POST['responsible_user_id'] ?? 0);
    $parentInvolvement = sanitize($_POST['parent_involvement'] ?? '');
    $followUpDate = sanitize($_POST['follow_up_date'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    $errors = [];
    if (empty($weakAreas)) $errors[] = 'يجب تحديد نقاط الضعف';
    if (empty($goals)) $errors[] = 'يجب تحديد الأهداف';
    if (empty($strategies)) $errors[] = 'يجب تحديد الاستراتيجيات';
    if (empty($timeline)) $errors[] = 'يجب تحديد الجدول الزمني';
    if (empty($followUpDate)) $errors[] = 'يجب تحديد تاريخ المتابعة';

    if (empty($errors)) {
        try {
            if ($plan) {
                $stmt = $db->prepare("
                    UPDATE improvement_plans SET
                        weaknesses = ?, goals = ?, strategies = ?,
                        timeline = ?, responsible_user_id = ?,
                        parent_involvement = ?, follow_up_date = ?,
                        notes = ?, updated_at = NOW(), updated_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $weaknesses, $goals, $strategies, $timeline,
                    $responsibleUser, $parentInvolvement, $followUpDate,
                    $notes, $_SESSION['user']['id'], $plan['id']
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO improvement_plans
                    (student_id, decision_id, weaknesses, goals, strategies,
                     timeline, responsible_user_id, parent_involvement,
                     follow_up_date, notes, status, created_by, created_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), 1)
                ");
                $stmt->execute([
                    $studentId, $decisionId, $weakAreas, $goals, $strategies,
                    $timeline, $responsibleTeacher, $parentInvolvement,
                    $followUpDate, $notes, $_SESSION['user']['id']
                ]);
            }

            logActivity($db, $_SESSION['user']['id'], 'save_improvement_plan', 'حفظ خطة تحسين', 'student', $studentId);
            setFlash('تم حفظ خطة التحسين بنجاح.', 'success');
            redirect(baseUrl("/pages/promotion/improvement_plan.php?student_id={$studentId}&decision_id={$decisionId}"));
        } catch (Exception $e) {
            setFlash('حدث خطأ أثناء الحفظ: ' . $e->getMessage(), 'danger');
        }
    } else {
        foreach ($errors as $error) {
            setFlash($error, 'danger');
        }
    }
}

// جلب المعلمين للمدرسة
$teachers = $db->query("
    SELECT u.id, u.full_name_ar
    FROM users u
    WHERE u.role_id = (SELECT id FROM roles WHERE name = 'teacher')
    AND u.is_active = 1
    ORDER BY u.full_name_ar
")->fetchAll();

// تحديد نقاط الضعف بناءً على المعايير
$weaknesses = [];
if (!$decision['academic_pass']) $weaknesses[] = 'الأداء الأكاديمي (أقل من 50%)';
if (!$decision['behavior_pass']) $weaknesses[] = 'السلوك (أقل من 60%)';
if (!$decision['attendance_pass']) $weaknesses[] = 'الحضور (أقل من 75%)';
?>

<div class="page-wrapper">
    <div class="page-header">
        <h1><i class="fas fa-tasks"></i> خطة التحسين - ترفيع مشروط</h1>
        <div class="breadcrumb">
            <a href="<?= baseUrl('/index.php') ?>">الرئيسية</a>
            <span>/</span>
            <a href="<?= baseUrl('/pages/promotion/index.php') ?>">قرارات الترفيع</a>
            <span>/</span>
            <span>خطة التحسين</span>
        </div>
    </div>

    <!-- بطاقة معلومات الطالب -->
    <div class="card mb-4">
        <div class="card-header">
            <h3><i class="fas fa-user-graduate"></i> معلومات الطالب</h3>
        </div>
        <div class="card-body">
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="info-item">
                    <label>اسم الطالب:</label>
                    <span><?= e($student['full_name_ar']) ?></span>
                </div>
                <div class="info-item">
                    <label>الصف:</label>
                    <span><?= e($student['grade_name'] ?? '') ?></span>
                </div>
                <div class="info-item">
                    <label>الفصل:</label>
                    <span><?= e($student['class_name'] ?? '') ?></span>
                </div>
                <div class="info-item">
                    <label>السنة الأكاديمية:</label>
                    <span><?= e($decision['year_name'] ?? '') ?></span>
                </div>
            </div>

            <!-- مؤشرات المعايير الثلاثة -->
            <div class="grid mt-3" style="grid-template-columns: repeat(3, 1fr);">
                <div class="criterion-card <?= $decision['academic_pass'] ? 'pass' : 'fail' ?>">
                    <div class="criterion-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="criterion-info">
                        <h4>الأداء الأكاديمي</h4>
                        <div class="criterion-value"><?= number_format($decision['academic_score'], 1) ?>%</div>
                        <div class="criterion-status">
                            <?= $decision['academic_pass']
                                ? '<span class="badge badge-success">مستوفي</span>'
                                : '<span class="badge badge-danger">غير مستوفي (الحد الأدنى 50%)</span>' ?>
                        </div>
                    </div>
                </div>
                <div class="criterion-card <?= $decision['behavior_pass'] ? 'pass' : 'fail' ?>">
                    <div class="criterion-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="criterion-info">
                        <h4>السلوك</h4>
                        <div class="criterion-value"><?= number_format($decision['behavior_score'], 1) ?>%</div>
                        <div class="criterion-status">
                            <?= $decision['behavior_pass']
                                ? '<span class="badge badge-success">مستوفي</span>'
                                : '<span class="badge badge-danger">غير مستوفي (الحد الأدنى 60%)</span>' ?>
                        </div>
                    </div>
                </div>
                <div class="criterion-card <?= $decision['attendance_pass'] ? 'pass' : 'fail' ?>">
                    <div class="criterion-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="criterion-info">
                        <h4>الحضور</h4>
                        <div class="criterion-value"><?= number_format($decision['attendance_score'], 1) ?>%</div>
                        <div class="criterion-status">
                            <?= $decision['attendance_pass']
                                ? '<span class="badge badge-success">مستوفي</span>'
                                : '<span class="badge badge-danger">غير مستوفي (الحد الأدنى 75%)</span>' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نموذج خطة التحسين -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clipboard-list"></i> خطة التحسين</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="improvementPlanForm">
                <?= csrfField() ?>

                <!-- نقاط الضعف -->
                <div class="form-group">
                    <label class="form-label" for="weaknesses">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                        نقاط الضعف المحددة *
                    </label>
                    <textarea name="weaknesses" id="weaknesses" class="form-control" rows="3" required><?= e($plan['weaknesses'] ?? implode("\n", $weaknesses)) ?></textarea>
                    <small class="form-text text-muted">تم تحديد نقاط الضعف تلقائياً بناءً على معايير الترفيع</small>
                </div>

                <!-- الأهداف -->
                <div class="form-group">
                    <label class="form-label" for="goals">
                        <i class="fas fa-bullseye text-primary"></i>
                        الأهداف المطلوب تحقيقها *
                    </label>
                    <textarea name="goals" id="goals" class="form-control" rows="3" required placeholder="مثال: رفع المعدل الأكاديمي إلى 50% على الأقل"><?= e($plan['goals'] ?? '') ?></textarea>
                </div>

                <!-- الاستراتيجيات -->
                <div class="form-group">
                    <label class="form-label" for="strategies">
                        <i class="fas fa-lightbulb text-warning"></i>
                        الاستراتيجيات والإجراءات *
                    </label>
                    <textarea name="strategies" id="strategies" class="form-control" rows="4" required placeholder="مثال: حصص تقوية أسبوعية، متابعة يومية للحضور"><?= e($plan['strategies'] ?? '') ?></textarea>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr;">
                    <!-- الجدول الزمني -->
                    <div class="form-group">
                        <label class="form-label" for="timeline">
                            <i class="fas fa-clock text-info"></i>
                            الجدول الزمني *
                        </label>
                        <textarea name="timeline" id="timeline" class="form-control" rows="3" required placeholder="مثال: الأسبوع 1-2: تقييم أولي، الأسبوع 3-6: حصص تقوية"><?= e($plan['timeline'] ?? '') ?></textarea>
                    </div>

                    <!-- المعلم المسؤول -->
                    <div class="form-group">
                        <label class="form-label" for="responsible_user_id">
                            <i class="fas fa-chalkboard-teacher text-success"></i>
                            المسؤول عن المتابعة
                        </label>
                        <select name="responsible_user_id" id="responsible_user_id" class="form-control">
                            <option value="">-- اختر المسؤول --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>" <?= ($plan['responsible_user_id'] ?? '') == $teacher['id'] ? 'selected' : '' ?>>
                                    <?= e($teacher['full_name_ar']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr;">
                    <!-- دور ولي الأمر -->
                    <div class="form-group">
                        <label class="form-label" for="parent_involvement">
                            <i class="fas fa-users text-secondary"></i>
                            دور ولي الأمر في خطة التحسين
                        </label>
                        <textarea name="parent_involvement" id="parent_involvement" class="form-control" rows="3" placeholder="مثال: متابعة الواجبات المنزلية، حضور اجتماعات أسبوعية"><?= e($plan['parent_involvement'] ?? '') ?></textarea>
                    </div>

                    <!-- تاريخ المتابعة -->
                    <div class="form-group">
                        <label class="form-label" for="follow_up_date">
                            <i class="fas fa-calendar-alt text-danger"></i>
                            تاريخ المتابعة القادمة *
                        </label>
                        <input type="date" name="follow_up_date" id="follow_up_date"
                               class="form-control" required
                               value="<?= e($plan['follow_up_date'] ?? date('Y-m-d', strtotime('+2 weeks'))) ?>">
                    </div>
                </div>

                <!-- ملاحظات -->
                <div class="form-group">
                    <label class="form-label" for="notes">
                        <i class="fas fa-sticky-note"></i>
                        ملاحظات إضافية
                    </label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="أي ملاحظات إضافية حول خطة التحسين"><?= e($plan['notes'] ?? '') ?></textarea>
                </div>

                <!-- أزرار الحفظ -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> حفظ خطة التحسين
                    </button>
                    <a href="<?= baseUrl('/pages/promotion/index.php') ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-right"></i> رجوع
                    </a>
                    <?php if ($plan): ?>
                        <button type="button" class="btn btn-success" onclick="printPlan()">
                            <i class="fas fa-print"></i> طباعة الخطة
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.criterion-card {
    padding: 1rem;
    border-radius: 0.5rem;
    text-align: center;
    border: 2px solid #dee2e6;
    transition: all 0.3s;
}
.criterion-card.pass {
    border-color: #28a745;
    background: #d4edda;
}
.criterion-card.fail {
    border-color: #dc3545;
    background: #f8d7da;
}
.criterion-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}
.criterion-value {
    font-size: 1.5rem;
    font-weight: bold;
}
.criterion-status {
    margin-top: 0.5rem;
}
.info-item label {
    color: #6c757d;
    font-size: 0.85rem;
}
.info-item span {
    font-weight: bold;
}
.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #dee2e6;
}
@media print {
    .form-actions, .page-header, .sidebar, .topbar { display: none !important; }
    .criterion-card { break-inside: avoid; }
}
</style>

<script>
function printPlan() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
