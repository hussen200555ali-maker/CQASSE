<?php
/**
 * ============================================================
 * EQAS - لوحة التقييم الأكاديمي
 * نظام التقييم الشامل - الصفحة الرئيسية للتقييم الأكاديمي
 * ============================================================
 * يتضمن:
 * - إحصائيات سريعة (مواد، اختبارات، درجات)
 * - روابط سريعة لجميع الصفحات الفرعية
 * - آخر نشاط إدخال درجات
 * - تنبيهات الفصول التي تحتاج درجات
 * ============================================================
 */

$pageTitle = 'التقييم الأكاديمي';
$breadcrumb = [
    'التقييم الأكاديمي' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$academicYearId = getCurrentAcademicYearId($db);
$schoolId = $_SESSION['user']['school_id'] ?? 0;

// ============================================================
// جلب الإحصائيات
// ============================================================

// عدد المواد
$subjectsCount = $db->query("SELECT COUNT(*) FROM subjects WHERE is_active = 1")->fetchColumn();

// عدد الاختبارات
$testsCount = 0;
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tests t
        INNER JOIN classes c ON c.id = t.class_id
        WHERE c.school_id = ? AND t.academic_year_id = ? AND t.is_active = 1
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $testsCount = $stmt->fetchColumn();
}

// عدد الدرجات المسجلة
$gradesCount = 0;
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM grades g
        INNER JOIN tests t ON t.id = g.test_id
        INNER JOIN classes c ON c.id = t.class_id
        WHERE c.school_id = ? AND t.academic_year_id = ? AND g.is_active = 1
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $gradesCount = $stmt->fetchColumn();
}

// عدد الاختبارات حسب الحالة
$draftTests = 0;
$publishedTests = 0;
$lockedTests = 0;
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT t.status, COUNT(*) AS cnt FROM tests t
        INNER JOIN classes c ON c.id = t.class_id
        WHERE c.school_id = ? AND t.academic_year_id = ? AND t.is_active = 1
        GROUP BY t.status
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    while ($row = $stmt->fetch()) {
        switch ($row['status']) {
            case 'draft': $draftTests = $row['cnt']; break;
            case 'published': $publishedTests = $row['cnt']; break;
            case 'locked': $lockedTests = $row['cnt']; break;
        }
    }
}

// آخر نشاط إدخال درجات
$recentActivity = [];
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT g.updated_at, g.graded_by, t.title_ar AS test_title, s.full_name_ar AS student_name,
               u.full_name_ar AS grader_name
        FROM grades g
        INNER JOIN tests t ON t.id = g.test_id
        INNER JOIN students s ON s.id = g.student_id
        LEFT JOIN users u ON u.id = g.graded_by
        INNER JOIN classes c ON c.id = t.class_id
        WHERE c.school_id = ? AND t.academic_year_id = ? AND g.is_active = 1
        ORDER BY g.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $recentActivity = $stmt->fetchAll();
}

// فصول تحتاج درجات (اختبارات بدون درجات)
$classesNeedingGrades = [];
if ($schoolId) {
    $stmt = $db->prepare("
        SELECT c.id AS class_id, c.name_ar AS class_name, gl.name_ar AS grade_name,
               sub.name_ar AS subject_name, t.title_ar, t.id AS test_id
        FROM tests t
        INNER JOIN classes c ON c.id = t.class_id
        INNER JOIN grade_levels gl ON gl.id = c.grade_level_id
        INNER JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN grades g ON g.test_id = t.id AND g.is_active = 1
        WHERE c.school_id = ? AND t.academic_year_id = ? AND t.is_active = 1
            AND t.status != 'locked' AND g.id IS NULL
        GROUP BY t.id
        ORDER BY gl.level_number, c.name_ar
        LIMIT 10
    ");
    $stmt->execute([$schoolId, $academicYearId]);
    $classesNeedingGrades = $stmt->fetchAll();
}
?>

<!-- ============================================================
     إحصائيات سريعة
     ============================================================ -->
<div class="grid grid-4 mb-6">
    <div class="card" style="border-right:4px solid var(--primary);">
        <div class="card-body text-center">
            <div style="font-size:2rem; margin-bottom:var(--space-2);">📚</div>
            <div style="font-size:var(--font-size-2xl); font-weight:700; color:var(--primary);"><?= $subjectsCount ?></div>
            <div class="text-sm text-secondary">مادة دراسية</div>
        </div>
    </div>
    <div class="card" style="border-right:4px solid var(--info);">
        <div class="card-body text-center">
            <div style="font-size:2rem; margin-bottom:var(--space-2);">📝</div>
            <div style="font-size:var(--font-size-2xl); font-weight:700; color:var(--info);"><?= $testsCount ?></div>
            <div class="text-sm text-secondary">اختبار</div>
        </div>
    </div>
    <div class="card" style="border-right:4px solid var(--success);">
        <div class="card-body text-center">
            <div style="font-size:2rem; margin-bottom:var(--space-2);">✅</div>
            <div style="font-size:var(--font-size-2xl); font-weight:700; color:var(--success);"><?= $gradesCount ?></div>
            <div class="text-sm text-secondary">درجة مسجلة</div>
        </div>
    </div>
    <div class="card" style="border-right:4px solid var(--warning);">
        <div class="card-body text-center">
            <div style="font-size:2rem; margin-bottom:var(--space-2);">⏳</div>
            <div style="font-size:var(--font-size-2xl); font-weight:700; color:var(--warning-dark);"><?= $draftTests ?></div>
            <div class="text-sm text-secondary">اختبار مسودة</div>
        </div>
    </div>
</div>

<!-- ============================================================
     روابط سريعة
     ============================================================ -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">⚡ الوصول السريع</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-auto">
            <a href="grade_entry.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--primary-light); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">📝</div>
                    <div class="font-bold" style="color:var(--primary);">إدخال الدرجات</div>
                    <div class="text-sm text-secondary">إدخال درجات الطلاب</div>
                </div>
            </a>
            <a href="grade_edit.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--warning); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">✏️</div>
                    <div class="font-bold" style="color:var(--warning-dark);">تعديل الدرجات</div>
                    <div class="text-sm text-secondary">تعديل درجات مسجلة</div>
                </div>
            </a>
            <a href="summary.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--info); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">📊</div>
                    <div class="font-bold" style="color:var(--info);">ملخص الدرجات</div>
                    <div class="text-sm text-secondary">عرض وتحليل الدرجات</div>
                </div>
            </a>
            <a href="semester_calc.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--success); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">🎓</div>
                    <div class="font-bold" style="color:var(--success);">نتائج الفصل</div>
                    <div class="text-sm text-secondary">حساب المعدلات والترتيب</div>
                </div>
            </a>
            <a href="subjects.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--secondary); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">📚</div>
                    <div class="font-bold" style="color:var(--secondary-dark);">إدارة المواد</div>
                    <div class="text-sm text-secondary">المواد والتعيينات</div>
                </div>
            </a>
            <a href="weight_setup.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--accent); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">⚖️</div>
                    <div class="font-bold" style="color:var(--accent);">توزيع الأوزان</div>
                    <div class="text-sm text-secondary">أوزان التقييمات</div>
                </div>
            </a>
            <a href="tests.php" class="card" style="text-decoration:none; padding:var(--space-4); border:2px solid var(--border-dark); cursor:pointer; transition:all 0.2s;">
                <div class="text-center">
                    <div style="font-size:2rem; margin-bottom:var(--space-2);">📋</div>
                    <div class="font-bold" style="color:var(--text-primary);">إدارة الاختبارات</div>
                    <div class="text-sm text-secondary">إنشاء ونشر وقفل</div>
                </div>
            </a>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <!-- ============================================================
         تنبيهات الفصول التي تحتاج درجات
         ============================================================ -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚠️ فصول تحتاج درجات</h3>
        </div>
        <div class="card-body">
            <?php if (empty($classesNeedingGrades)): ?>
                <div class="text-center p-4">
                    <div style="font-size:3rem; opacity:0.3;">✅</div>
                    <div class="text-secondary mt-2">جميع الاختبارات لديها درجات</div>
                </div>
            <?php else: ?>
                <div style="max-height:300px; overflow-y:auto;">
                    <?php foreach ($classesNeedingGrades as $item): ?>
                        <div class="flex items-center justify-between p-3 mb-2" style="background:rgba(255,193,7,0.08); border-radius:var(--radius-md); border-right:3px solid var(--warning);">
                            <div>
                                <div class="font-bold text-sm"><?= e($item['grade_name'] . ' - ' . $item['class_name']) ?></div>
                                <div class="text-xs text-secondary"><?= e($item['subject_name']) ?> - <?= e($item['title_ar']) ?></div>
                            </div>
                            <a href="grade_entry.php?test_id=<?= $item['test_id'] ?>" class="btn btn-sm btn-warning">📝 إدخال</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         آخر نشاط إدخال الدرجات
         ============================================================ -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🕐 آخر نشاط</h3>
        </div>
        <div class="card-body">
            <?php if (empty($recentActivity)): ?>
                <div class="text-center p-4">
                    <div style="font-size:3rem; opacity:0.3;">📋</div>
                    <div class="text-secondary mt-2">لا يوجد نشاط حديث</div>
                </div>
            <?php else: ?>
                <div style="max-height:300px; overflow-y:auto;">
                    <?php foreach ($recentActivity as $act): ?>
                        <div class="flex items-center gap-3 p-3 mb-2" style="border-bottom:1px solid var(--border-light);">
                            <div style="width:36px; height:36px; background:rgba(26,86,50,0.1); border-radius:var(--radius-full); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                📝
                            </div>
                            <div class="flex-1">
                                <div class="text-sm font-bold"><?= e($act['grader_name'] ?? 'مستخدم') ?> → <?= e($act['student_name']) ?></div>
                                <div class="text-xs text-secondary"><?= e($act['test_title']) ?></div>
                            </div>
                            <div class="text-xs text-muted">
                                <?= formatDateAr($act['updated_at'], 'short') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     حالة الاختبارات
     ============================================================ -->
<div class="card mt-6">
    <div class="card-header">
        <h3 class="card-title">📈 حالة الاختبارات</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-3">
            <div class="text-center p-4" style="background:rgba(108,117,125,0.08); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--text-secondary);"><?= $draftTests ?></div>
                <div class="text-sm text-secondary">مسودة</div>
                <div class="text-xs text-muted">قابلة للتعديل</div>
            </div>
            <div class="text-center p-4" style="background:rgba(23,162,184,0.08); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--info);"><?= $publishedTests ?></div>
                <div class="text-sm text-secondary">منشورة</div>
                <div class="text-xs text-muted">يمكن إدخال الدرجات</div>
            </div>
            <div class="text-center p-4" style="background:rgba(220,53,69,0.08); border-radius:var(--radius-md);">
                <div class="text-2xl font-bold" style="color:var(--danger);"><?= $lockedTests ?></div>
                <div class="text-sm text-secondary">مقفلة</div>
                <div class="text-xs text-muted">لا يمكن التعديل</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
