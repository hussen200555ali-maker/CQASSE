<?php
/**
 * ============================================================
 * تسجيل الطلاب - إضافة تسجيل جديد
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * نموذج تسجيل طالب في فصل دراسي مع دعم التسجيل الجماعي
 * يتضمن التحقق من عدم تكرار التسجيل وسعة الفصل
 * ============================================================
 */

$pageTitle = 'تسجيل طالب جديد';
$breadcrumb = [
    'الإدارة' => null,
    'تسجيل الطلاب' => 'index.php',
    'تسجيل جديد' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// متغيرات المستخدم والسياق
$userRole       = $_SESSION['user']['role'];
$schoolId       = $_SESSION['user']['school_id'] ?? null;
$academicYearId = currentAcademicYearId();

// وضع التسجيل: فردي أو جماعي
$enrollmentMode = $_GET['mode'] ?? 'single';

$errors = [];
$old = [];
$successCount = 0;

// جلب السنوات الدراسية
$academicYears = [];
try {
    $academicYears = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC")->fetchAll();
} catch (Exception $e) {
    $academicYears = [];
}

// جلب الفصول حسب المدرسة والعام الدراسي
$classes = [];
$selectedAcademicYear = intval($_POST['academic_year_id'] ?? $_GET['academic_year_id'] ?? $academicYearId ?? 0);
if ($selectedAcademicYear > 0) {
    try {
        $clSql = "SELECT c.id, c.name_ar, c.capacity, gl.name_ar AS grade_name,
                         (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.status = 'enrolled' AND se.is_active = 1) AS current_count
                  FROM classes c
                  LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
                  WHERE c.is_active = 1 AND c.academic_year_id = ?";
        $clParams = [$selectedAcademicYear];
        if ($schoolId && $userRole !== 'group_admin') {
            $clSql .= " AND c.school_id = ?";
            $clParams[] = $schoolId;
        }
        $clSql .= " ORDER BY gl.level_number, c.name_ar";
        $stmt = $db->prepare($clSql);
        $stmt->execute($clParams);
        $classes = $stmt->fetchAll();
    } catch (Exception $e) {
        $classes = [];
    }
}

// جلب الطلاب المتاحين للتسجيل (غير مسجلين في نفس الفصل والسنة)
$availableStudents = [];
$selectedClass = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClass > 0 && $selectedAcademicYear > 0) {
    try {
        $stSql = "SELECT s.id, s.student_number, s.full_name_ar, gl.name_ar AS grade_name
                  FROM students s
                  LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
                  WHERE s.is_active = 1
                    AND NOT EXISTS (
                        SELECT 1 FROM student_enrollments se
                        WHERE se.student_id = s.id
                          AND se.class_id = ?
                          AND se.academic_year_id = ?
                          AND se.status = 'enrolled'
                          AND se.is_active = 1
                    )";
        $stParams = [$selectedClass, $selectedAcademicYear];
        if ($schoolId && $userRole !== 'group_admin') {
            $stSql .= " AND s.school_id = ?";
            $stParams[] = $schoolId;
        }
        $stSql .= " ORDER BY s.full_name_ar";
        $stmt = $db->prepare($stSql);
        $stmt->execute($stParams);
        $availableStudents = $stmt->fetchAll();
    } catch (Exception $e) {
        $availableStudents = [];
    }
}

// ============================================================
// معالجة إرسال النموذج
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect('add.php');
    }

    $postedMode          = $_POST['enrollment_mode'] ?? 'single';
    $classId             = intval($_POST['class_id'] ?? 0);
    $postedAcademicYear  = intval($_POST['academic_year_id'] ?? 0);
    $enrollmentDate      = trim($_POST['enrollment_date'] ?? date('Y-m-d'));

    // التحقق من أن السنة الأكاديمية غير مغلقة
    if ($postedAcademicYear > 0) {
        $stmt = $db->prepare("SELECT status FROM academic_years WHERE id = ?");
        $stmt->execute([$postedAcademicYear]);
        $yearStatus = $stmt->fetchColumn();
        if ($yearStatus === 'closed') {
            $errors['academic_year_id'] = 'لا يمكن التسجيل في سنة أكاديمية مغلقة';
        }
    }

    // التحقق من صحة التاريخ
    if (!isValidDate($enrollmentDate)) {
        $errors['enrollment_date'] = 'تاريخ التسجيل غير صحيح';
    }

    // التحقق من العام الدراسي
    if ($postedAcademicYear <= 0) {
        $errors['academic_year_id'] = 'العام الدراسي مطلوب';
    }

    // التحقق من الفصل
    if ($classId <= 0) {
        $errors['class_id'] = 'الفصل مطلوب';
    }

    if (empty($errors)) {
        try {
            // التحقق من أن الفصل تابع لمدرسة المستخدم (لمدير المدرسة)
            if ($schoolId && $userRole !== 'group_admin') {
                $stmt = $db->prepare("SELECT id, school_id, capacity FROM classes WHERE id = ? AND is_active = 1");
                $stmt->execute([$classId]);
                $classInfo = $stmt->fetch();
                if (!$classInfo || $classInfo['school_id'] != $schoolId) {
                    $errors['class_id'] = 'الفصل المحدد غير تابع لمدرستك';
                }
            } else {
                $stmt = $db->prepare("SELECT id, capacity FROM classes WHERE id = ? AND is_active = 1");
                $stmt->execute([$classId]);
                $classInfo = $stmt->fetch();
                if (!$classInfo) {
                    $errors['class_id'] = 'الفصل غير موجود';
                }
            }
        } catch (Exception $e) {
            $errors['general'] = 'حدث خطأ في التحقق من بيانات الفصل';
        }
    }

    if ($postedMode === 'single') {
        // ============================================================
        // التسجيل الفردي
        // ============================================================
        $studentId = intval($_POST['student_id'] ?? 0);

        if ($studentId <= 0) {
            $errors['student_id'] = 'الطالب مطلوب';
        }

        if (empty($errors)) {
            try {
                // التحقق من عدم وجود تسجيل سابق للطالب في نفس الفصل والسنة
                $stmt = $db->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND class_id = ? AND academic_year_id = ? AND is_active = 1");
                $stmt->execute([$studentId, $classId, $postedAcademicYear]);
                if ($stmt->fetch()) {
                    $errors['student_id'] = 'الطالب مسجل بالفعل في هذا الفصل لهذا العام الدراسي';
                }
            } catch (Exception $e) {
                $errors['general'] = 'حدث خطأ في التحقق من التسجيل';
            }
        }

        if (empty($errors)) {
            try {
                // التحقق من سعة الفصل
                $stmt = $db->prepare("SELECT COUNT(*) FROM student_enrollments WHERE class_id = ? AND status = 'enrolled' AND is_active = 1");
                $stmt->execute([$classId]);
                $currentCount = (int)$stmt->fetchColumn();
                $classCapacity = (int)($classInfo['capacity'] ?? 30);

                if ($currentCount >= $classCapacity) {
                    $errors['class_id'] = "الفصل ممتلئ ({$currentCount}/{$classCapacity})";
                }
            } catch (Exception $e) {
                $errors['general'] = 'حدث خطأ في التحقق من سعة الفصل';
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // إدراج التسجيل
                $stmt = $db->prepare("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, enrollment_date, status, is_active)
                                      VALUES (?, ?, ?, ?, 'enrolled', 1)");
                $stmt->execute([$studentId, $classId, $postedAcademicYear, $enrollmentDate]);
                $enrollmentId = $db->lastInsertId();

                // تسجيل النشاط
                logActivity($db, $_SESSION['user']['id'], 'create_enrollment',
                    "تسجيل طالب رقم {$studentId} في الفصل رقم {$classId} للعام {$postedAcademicYear}",
                    'student_enrollment', $enrollmentId);

                $db->commit();

                setFlash('تم تسجيل الطالب بنجاح', 'success');
                redirect('index.php');

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                error_log('Enrollment Add Error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ أثناء تسجيل الطالب. يرجى المحاولة مرة أخرى.';
            }
        }

        // تحديث القيم القديمة في حالة وجود أخطاء
        $old = [
            'student_id'       => $studentId ?? 0,
            'class_id'         => $classId,
            'academic_year_id' => $postedAcademicYear,
            'enrollment_date'  => $enrollmentDate,
            'enrollment_mode'  => 'single'
        ];

    } else {
        // ============================================================
        // التسجيل الجماعي
        // ============================================================
        $selectedStudents = $_POST['student_ids'] ?? [];

        if (empty($selectedStudents) || !is_array($selectedStudents)) {
            $errors['student_ids'] = 'يجب اختيار طالب واحد على الأقل';
        }

        if (empty($errors) && !empty($selectedStudents)) {
            try {
                // التحقق من سعة الفصل
                $stmt = $db->prepare("SELECT COUNT(*) FROM student_enrollments WHERE class_id = ? AND status = 'enrolled' AND is_active = 1");
                $stmt->execute([$classId]);
                $currentCount = (int)$stmt->fetchColumn();
                $classCapacity = (int)($classInfo['capacity'] ?? 30);
                $newStudentsCount = count($selectedStudents);

                if (($currentCount + $newStudentsCount) > $classCapacity) {
                    $available = $classCapacity - $currentCount;
                    $errors['student_ids'] = "الفصل لا يتسع لـ {$newStudentsCount} طالب. المتاح: {$available} مقعد فقط من أصل {$classCapacity}";
                }
            } catch (Exception $e) {
                $errors['general'] = 'حدث خطأ في التحقق من سعة الفصل';
            }
        }

        if (empty($errors) && !empty($selectedStudents)) {
            try {
                $db->beginTransaction();

                $successCount = 0;
                $skippedCount = 0;
                $skippedNames = [];

                foreach ($selectedStudents as $sid) {
                    $sid = intval($sid);
                    if ($sid <= 0) continue;

                    // التحقق من عدم وجود تسجيل سابق
                    $stmt = $db->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND class_id = ? AND academic_year_id = ? AND is_active = 1");
                    $stmt->execute([$sid, $classId, $postedAcademicYear]);
                    if ($stmt->fetch()) {
                        $skippedCount++;
                        // جلب اسم الطالب المتخطى
                        $stmt2 = $db->prepare("SELECT full_name_ar FROM students WHERE id = ?");
                        $stmt2->execute([$sid]);
                        $sn = $stmt2->fetchColumn();
                        $skippedNames[] = $sn ?: "طالب رقم {$sid}";
                        continue;
                    }

                    // إدراج التسجيل
                    $stmt = $db->prepare("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, enrollment_date, status, is_active)
                                          VALUES (?, ?, ?, ?, 'enrolled', 1)");
                    $stmt->execute([$sid, $classId, $postedAcademicYear, $enrollmentDate]);
                    $enrollmentId = $db->lastInsertId();

                    // تسجيل النشاط
                    logActivity($db, $_SESSION['user']['id'], 'create_enrollment',
                        "تسجيل جماعي - طالب رقم {$sid} في الفصل رقم {$classId} للعام {$postedAcademicYear}",
                        'student_enrollment', $enrollmentId);

                    $successCount++;
                }

                $db->commit();

                // رسالة النتيجة
                $message = "تم تسجيل {$successCount} طالب بنجاح";
                if ($skippedCount > 0) {
                    $message .= " (تم تخطي {$skippedCount} طالب مسجل مسبقاً: " . implode('، ', $skippedNames) . ")";
                }
                setFlash($message, $successCount > 0 ? 'success' : 'warning');
                redirect('index.php');

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                error_log('Bulk Enrollment Add Error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ أثناء التسجيل الجماعي. يرجى المحاولة مرة أخرى.';
            }
        }

        // تحديث القيم القديمة
        $old = [
            'student_ids'      => $selectedStudents ?? [],
            'class_id'         => $classId,
            'academic_year_id' => $postedAcademicYear,
            'enrollment_date'  => $enrollmentDate,
            'enrollment_mode'  => 'bulk'
        ];
    }
}

// تعيين القيم الافتراضية
$old['academic_year_id'] = $old['academic_year_id'] ?? ($academicYearId ?? '');
$old['class_id']         = $old['class_id'] ?? '';
$old['enrollment_date']  = $old['enrollment_date'] ?? date('Y-m-d');
$old['enrollment_mode']  = $old['enrollment_mode'] ?? $enrollmentMode;
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تسجيل طالب جديد</h3>
        <a href="index.php" class="btn btn-light">↩ العودة للقائمة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4">
                <div class="flash-content">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($errors['general']) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- تبديل وضع التسجيل -->
        <div class="flex gap-2 mb-6">
            <a href="add.php?mode=single" class="btn <?= ($old['enrollment_mode'] ?? 'single') === 'single' ? 'btn-primary' : 'btn-light' ?>">👤 تسجيل فردي</a>
            <a href="add.php?mode=bulk" class="btn <?= ($old['enrollment_mode'] ?? 'single') === 'bulk' ? 'btn-primary' : 'btn-light' ?>">👥 تسجيل جماعي</a>
        </div>

        <form method="POST" action="" id="enrollment-form" data-validate>
            <?= csrfField() ?>
            <input type="hidden" name="enrollment_mode" value="<?= e($old['enrollment_mode'] ?? 'single') ?>">

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">العام الدراسي <span class="required">*</span></label>
                    <select name="academic_year_id" id="academic-year-select" class="form-control <?= isset($errors['academic_year_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر العام الدراسي</option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= ($old['academic_year_id'] ?? '') == $ay['id'] ? 'selected' : '' ?>>
                                <?= e($ay['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['academic_year_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['academic_year_id']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">تاريخ التسجيل <span class="required">*</span></label>
                    <input type="date" name="enrollment_date" class="form-control <?= isset($errors['enrollment_date']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['enrollment_date'] ?? date('Y-m-d')) ?>" required>
                    <?php if (isset($errors['enrollment_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['enrollment_date']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">الفصل <span class="required">*</span></label>
                <select name="class_id" id="class-select" class="form-control <?= isset($errors['class_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">اختر الفصل</option>
                    <?php foreach ($classes as $cls): ?>
                        <?php
                        $currentCount = (int)($cls['current_count'] ?? 0);
                        $capacity = (int)($cls['capacity'] ?? 30);
                        $label = e($cls['name_ar']);
                        if ($cls['grade_name']) {
                            $label .= ' - ' . e($cls['grade_name']);
                        }
                        $label .= " ({$currentCount}/{$capacity})";
                        $fullClass = $currentCount >= $capacity ? ' disabled' : '';
                        ?>
                        <option value="<?= $cls['id'] ?>" <?= ($old['class_id'] ?? '') == $cls['id'] ? 'selected' : '' ?><?= $fullClass ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['class_id'])): ?>
                    <div class="invalid-feedback"><?= e($errors['class_id']) ?></div>
                <?php endif; ?>
                <div id="class-info" class="form-text mt-2"></div>
            </div>

            <?php if (($old['enrollment_mode'] ?? 'single') === 'single'): ?>
                <!-- ============================================================
                     وضع التسجيل الفردي
                     ============================================================ -->
                <div class="form-group">
                    <label class="form-label">الطالب <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="text" id="student-search" class="form-control" placeholder="ابحث باسم الطالب أو رقمه..." autocomplete="off">
                        <input type="hidden" name="student_id" id="student-id-input" value="<?= e($old['student_id'] ?? '') ?>">
                        <div id="student-search-results" style="position:absolute; top:100%; right:0; left:0; z-index:1050; display:none; background:#fff; border:1px solid #ddd; border-radius:8px; max-height:250px; overflow-y:auto; box-shadow:0 4px 16px rgba(0,0,0,0.12);"></div>
                    </div>
                    <div id="selected-student-info" class="form-text mt-2" style="display:none;">
                        <span id="selected-student-name" style="font-weight:600;"></span>
                        <button type="button" id="clear-student" class="btn btn-sm btn-light" style="margin-right:8px;">✕ إزالة</button>
                    </div>
                    <?php if (isset($errors['student_id'])): ?>
                        <div class="invalid-feedback" style="display:block;"><?= e($errors['student_id']) ?></div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ============================================================
                     وضع التسجيل الجماعي
                     ============================================================ -->
                <div class="form-group">
                    <label class="form-label">اختر الطلاب <span class="required">*</span></label>
                    <div class="flex gap-2 mb-2">
                        <button type="button" id="select-all-students" class="btn btn-sm btn-light">✅ تحديد الكل</button>
                        <button type="button" id="deselect-all-students" class="btn btn-sm btn-light">❌ إلغاء التحديد</button>
                        <span id="selected-count" class="text-sm" style="line-height:30px; color:var(--text-secondary);">0 طالب محدد</span>
                    </div>
                    <?php if (isset($errors['student_ids'])): ?>
                        <div class="invalid-feedback" style="display:block; margin-bottom:8px;"><?= e($errors['student_ids']) ?></div>
                    <?php endif; ?>
                    <div id="students-checkbox-list" style="max-height:350px; overflow-y:auto; border:1px solid var(--border-color); border-radius:8px; padding:8px;">
                        <?php if (empty($availableStudents)): ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted);">
                                <div style="font-size:32px; margin-bottom:8px;">📭</div>
                                اختر الفصل أولاً لعرض الطلاب المتاحين
                            </div>
                        <?php else: ?>
                            <?php foreach ($availableStudents as $st): ?>
                                <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; border-radius:6px; transition:background 0.15s;" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='transparent'">
                                    <input type="checkbox" name="student_ids[]" value="<?= $st['id'] ?>" class="student-checkbox"
                                        <?= in_array($st['id'], $old['student_ids'] ?? []) ? 'checked' : '' ?>
                                        style="width:18px; height:18px; cursor:pointer;">
                                    <div>
                                        <div style="font-weight:600; font-size:14px;"><?= e($st['full_name_ar']) ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);">
                                            <?= e($st['student_number']) ?> • <?= e($st['grade_name'] ?? '—') ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">
                    💾 <?= ($old['enrollment_mode'] ?? 'single') === 'bulk' ? 'تسجيل الطلاب المحددين' : 'تسجيل الطالب' ?>
                </button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php
$pageJs = <<<JS
    // ============================================================
    // تحميل الفصول عند تغيير العام الدراسي
    // ============================================================
    const academicYearSelect = document.getElementById('academic-year-select');
    const classSelect = document.getElementById('class-select');
    const classInfo = document.getElementById('class-info');

    if (academicYearSelect) {
        academicYearSelect.addEventListener('change', function() {
            const yearId = this.value;
            if (!yearId) {
                classSelect.innerHTML = '<option value="">اختر العام الدراسي أولاً</option>';
                classInfo.textContent = '';
                return;
            }

            // إعادة تحميل الصفحة مع العام المحدد
            const mode = document.querySelector('input[name="enrollment_mode"]').value;
            const currentClassId = classSelect.value;
            window.location.href = 'add.php?mode=' + mode + '&academic_year_id=' + yearId + (currentClassId ? '&class_id=' + currentClassId : '');
        });
    }

    // ============================================================
    // عرض معلومات الفصل عند الاختيار
    // ============================================================
    if (classSelect) {
        classSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            if (selected && selected.value) {
                classInfo.innerHTML = '<i class="fas fa-info-circle"></i> ' + selected.textContent;
            } else {
                classInfo.textContent = '';
            }

            // في الوضع الجماعي: تحميل قائمة الطلاب
            const mode = document.querySelector('input[name="enrollment_mode"]')?.value;
            if (mode === 'bulk' && this.value) {
                const yearId = academicYearSelect ? academicYearSelect.value : '';
                window.location.href = 'add.php?mode=bulk&academic_year_id=' + yearId + '&class_id=' + this.value;
            }
        });
    }

    // ============================================================
    // بحث الطالب (الوضع الفردي)
    // ============================================================
    const studentSearch = document.getElementById('student-search');
    const studentResults = document.getElementById('student-search-results');
    const studentIdInput = document.getElementById('student-id-input');
    const selectedStudentInfo = document.getElementById('selected-student-info');
    const selectedStudentName = document.getElementById('selected-student-name');
    const clearStudentBtn = document.getElementById('clear-student');
    let searchTimer = null;

    if (studentSearch) {
        studentSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            const query = this.value.trim();
            if (query.length < 2) {
                studentResults.style.display = 'none';
                return;
            }

            searchTimer = setTimeout(function() {
                // البحث محلياً في قائمة الطلاب المتاحين
                const classId = classSelect ? classSelect.value : '';
                const yearId = academicYearSelect ? academicYearSelect.value : '';
                if (!classId || !yearId) {
                    studentResults.innerHTML = '<div style="padding:12px; text-align:center; color:var(--text-muted);">اختر الفصل والعام أولاً</div>';
                    studentResults.style.display = 'block';
                    return;
                }

                // استخدام AJAX للبحث
                fetch('../../api/search_students.php?q=' + encodeURIComponent(query) + '&class_id=' + classId + '&academic_year_id=' + yearId, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.students && data.students.length > 0) {
                        let html = '';
                        data.students.forEach(s => {
                            html += '<div class="student-option" data-id="' + s.id + '" data-name="' + s.full_name_ar + '" style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #f0f0f0; transition:background 0.15s;" onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'transparent\'">';
                            html += '<div style="font-weight:600; font-size:14px;">' + s.full_name_ar + '</div>';
                            html += '<div style="font-size:12px; color:var(--text-muted);">' + (s.student_number || '') + (s.grade_name ? ' • ' + s.grade_name : '') + '</div>';
                            html += '</div>';
                        });
                        studentResults.innerHTML = html;
                        studentResults.style.display = 'block';

                        // ربط أحداث النقر
                        document.querySelectorAll('.student-option').forEach(el => {
                            el.addEventListener('click', function() {
                                studentIdInput.value = this.dataset.id;
                                studentSearch.value = this.dataset.name;
                                selectedStudentName.textContent = this.dataset.name;
                                selectedStudentInfo.style.display = 'block';
                                studentResults.style.display = 'none';
                            });
                        });
                    } else {
                        studentResults.innerHTML = '<div style="padding:12px; text-align:center; color:var(--text-muted);">لا توجد نتائج</div>';
                        studentResults.style.display = 'block';
                    }
                })
                .catch(() => {
                    studentResults.style.display = 'none';
                });
            }, 400);
        });

        // إخفاء النتائج عند النقر خارجها
        document.addEventListener('click', function(e) {
            if (studentResults && !studentSearch.contains(e.target) && !studentResults.contains(e.target)) {
                studentResults.style.display = 'none';
            }
        });
    }

    // زر إزالة الطالب المحدد
    if (clearStudentBtn) {
        clearStudentBtn.addEventListener('click', function() {
            if (studentIdInput) studentIdInput.value = '';
            if (studentSearch) studentSearch.value = '';
            if (selectedStudentInfo) selectedStudentInfo.style.display = 'none';
        });
    }

    // ============================================================
    // تحديد/إلغاء تحديد الكل (الوضع الجماعي)
    // ============================================================
    const selectAllBtn = document.getElementById('select-all-students');
    const deselectAllBtn = document.getElementById('deselect-all-students');
    const selectedCountSpan = document.getElementById('selected-count');

    function updateSelectedCount() {
        const checkboxes = document.querySelectorAll('.student-checkbox');
        const checked = document.querySelectorAll('.student-checkbox:checked');
        if (selectedCountSpan) {
            selectedCountSpan.textContent = checked.length + ' طالب محدد من ' + checkboxes.length;
        }
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = true);
            updateSelectedCount();
        });
    }

    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
            updateSelectedCount();
        });
    }

    // تحديث العداد عند تغيير أي checkbox
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    // تحديث العداد عند التحميل
    updateSelectedCount();
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
