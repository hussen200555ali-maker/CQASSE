<?php
/**
 * ============================================================
 * تسجيل الطلاب - تعديل التسجيل
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * تعديل حالة التسجيل (مسجل ← منقول / منسحب)
 * نقل الطالب إلى فصل آخر مع تسجيل السبب
 * تحديث تاريخ التسجيل
 * تسجيل جميع التغييرات في سجل النشاط
 * ============================================================
 */

$pageTitle = 'تعديل تسجيل طالب';
$breadcrumb = [
    'الإدارة' => null,
    'تسجيل الطلاب' => 'index.php',
    'تعديل التسجيل' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// متغيرات المستخدم والسياق
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// جلب معرف التسجيل
$enrollmentId = intval($_GET['id'] ?? 0);
if (!$enrollmentId) {
    setFlash('معرف التسجيل غير صحيح', 'danger');
    redirect('index.php');
}

// جلب بيانات التسجيل
$stmt = $db->prepare("SELECT se.*,
                              s.full_name_ar AS student_name,
                              s.student_number,
                              s.school_id AS student_school_id,
                              c.name_ar AS class_name,
                              c.capacity AS class_capacity,
                              c.school_id AS class_school_id,
                              ay.name AS academic_year_name,
                              gl.name_ar AS grade_name
                       FROM student_enrollments se
                       INNER JOIN students s ON s.id = se.student_id
                       INNER JOIN classes c ON c.id = se.class_id
                       INNER JOIN academic_years ay ON ay.id = se.academic_year_id
                       LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
                       WHERE se.id = ?");
$stmt->execute([$enrollmentId]);
$enrollment = $stmt->fetch();

if (!$enrollment) {
    setFlash('التسجيل غير موجود', 'danger');
    redirect('index.php');
}

// التحقق من نطاق المدرسة
if ($schoolId && $userRole !== 'group_admin') {
    if ($enrollment['class_school_id'] != $schoolId) {
        setFlash('ليس لديك صلاحية لتعديل هذا التسجيل', 'danger');
        redirect('index.php');
    }
}

// نوع الإجراء: تعديل حالة أو نقل
$action = $_GET['action'] ?? 'status';

// جلب الفصول المتاحة للنقل
$transferClasses = [];
if ($action === 'transfer') {
    try {
        $tcSql = "SELECT c.id, c.name_ar, c.capacity, gl.name_ar AS grade_name,
                         (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.status = 'enrolled' AND se.is_active = 1) AS current_count
                  FROM classes c
                  LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
                  WHERE c.is_active = 1
                    AND c.academic_year_id = ?
                    AND c.id != ?";
        $tcParams = [$enrollment['academic_year_id'], $enrollment['class_id']];
        if ($schoolId && $userRole !== 'group_admin') {
            $tcSql .= " AND c.school_id = ?";
            $tcParams[] = $schoolId;
        }
        $tcSql .= " ORDER BY gl.level_number, c.name_ar";
        $stmt = $db->prepare($tcSql);
        $stmt->execute($tcParams);
        $transferClasses = $stmt->fetchAll();
    } catch (Exception $e) {
        $transferClasses = [];
    }
}

// جلب سجل تغييرات هذا التسجيل
$changeLog = [];
try {
    $stmt = $db->prepare("SELECT al.*, u.full_name_ar AS user_name
                          FROM activity_logs al
                          LEFT JOIN users u ON u.id = al.user_id
                          WHERE al.entity_type = 'student_enrollment'
                            AND al.entity_id = ?
                          ORDER BY al.created_at DESC
                          LIMIT 10");
    $stmt->execute([$enrollmentId]);
    $changeLog = $stmt->fetchAll();
} catch (Exception $e) {
    $changeLog = [];
}

$errors = [];

// ============================================================
// معالجة إرسال النموذج
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.', 'danger');
        redirect("edit.php?id={$enrollmentId}");
    }

    // التحقق من أن السنة الأكاديمية غير مغلقة
    $stmt = $db->prepare("SELECT status FROM academic_years WHERE id = ?");
    $stmt->execute([$enrollment['academic_year_id']]);
    if ($stmt->fetchColumn() === 'closed') {
        setFlash('لا يمكن تعديل تسجيل في سنة أكاديمية مغلقة', 'danger');
        redirect("edit.php?id={$enrollmentId}");
    }

    $postedAction = $_POST['form_action'] ?? 'status';

    if ($postedAction === 'status') {
        // ============================================================
        // تعديل حالة التسجيل
        // ============================================================
        $newStatus      = $_POST['status'] ?? '';
        $reason         = trim($_POST['reason'] ?? '');
        $enrollmentDate = trim($_POST['enrollment_date'] ?? '');

        // التحقق من الحالة
        $validStatuses = ['enrolled', 'transferred', 'dropped'];
        if (!in_array($newStatus, $validStatuses)) {
            $errors['status'] = 'الحالة غير صحيحة';
        }

        // التحقق من أن الحالة مختلفة
        if ($newStatus === $enrollment['status']) {
            $errors['status'] = 'الحالة الجديدة مطابقة للحالة الحالية';
        }

        // السبب مطلوب عند النقل أو الانسحاب
        if (in_array($newStatus, ['transferred', 'dropped']) && empty($reason)) {
            $errors['reason'] = 'يجب ذكر سبب تغيير الحالة';
        }

        // التحقق من تاريخ التسجيل
        if (!empty($enrollmentDate) && !isValidDate($enrollmentDate)) {
            $errors['enrollment_date'] = 'تاريخ التسجيل غير صحيح';
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $oldStatus = $enrollment['status'];

                // تحديث حالة التسجيل والتاريخ
                $updateSql = "UPDATE student_enrollments SET status = ?";
                $updateParams = [$newStatus];

                if (!empty($enrollmentDate)) {
                    $updateSql .= ", enrollment_date = ?";
                    $updateParams[] = $enrollmentDate;
                }

                // عند النقل أو الانسحاب: تعيين is_active = 0
                if (in_array($newStatus, ['transferred', 'dropped'])) {
                    $updateSql .= ", is_active = 0";
                }

                // عند إعادة التسجيل: تعيين is_active = 1
                if ($newStatus === 'enrolled') {
                    $updateSql .= ", is_active = 1";
                }

                $updateSql .= " WHERE id = ?";
                $updateParams[] = $enrollmentId;

                $stmt = $db->prepare($updateSql);
                $stmt->execute($updateParams);

                // تسجيل النشاط
                $statusChangeDesc = "تغيير حالة التسجيل من '" . getStatusLabel($oldStatus) . "' إلى '" . getStatusLabel($newStatus) . "'";
                if (!empty($reason)) {
                    $statusChangeDesc .= " - السبب: {$reason}";
                }
                logActivity($db, $_SESSION['user']['id'], 'update_enrollment_status',
                    $statusChangeDesc, 'student_enrollment', $enrollmentId);

                $db->commit();

                setFlash('تم تحديث حالة التسجيل بنجاح', 'success');
                redirect('index.php');

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                error_log('Enrollment Status Update Error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ أثناء تحديث الحالة. يرجى المحاولة مرة أخرى.';
            }
        }

    } elseif ($postedAction === 'transfer') {
        // ============================================================
        // نقل الطالب إلى فصل آخر
        // ============================================================
        $newClassId = intval($_POST['new_class_id'] ?? 0);
        $reason     = trim($_POST['transfer_reason'] ?? '');

        if ($newClassId <= 0) {
            $errors['new_class_id'] = 'يجب اختيار الفصل الجديد';
        }

        if (empty($reason)) {
            $errors['transfer_reason'] = 'يجب ذكر سبب النقل';
        }

        // التحقق من أن الطالب مسجل حالياً
        if ($enrollment['status'] !== 'enrolled') {
            $errors['general'] = 'لا يمكن نقل طالب غير مسجل (الحالة الحالية: ' . getStatusLabel($enrollment['status']) . ')';
        }

        if (empty($errors) && $newClassId > 0) {
            try {
                // التحقق من الفصل الجديد
                $stmt = $db->prepare("SELECT id, name_ar, capacity, school_id FROM classes WHERE id = ? AND is_active = 1");
                $stmt->execute([$newClassId]);
                $newClass = $stmt->fetch();

                if (!$newClass) {
                    $errors['new_class_id'] = 'الفصل الجديد غير موجود';
                }

                // التحقق من نطاق المدرسة
                if (empty($errors) && $schoolId && $userRole !== 'group_admin') {
                    if ($newClass['school_id'] != $schoolId) {
                        $errors['new_class_id'] = 'الفصل غير تابع لمدرستك';
                    }
                }

                // التحقق من عدم وجود تسجيل سابق في الفصل الجديد
                if (empty($errors)) {
                    $stmt = $db->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND class_id = ? AND academic_year_id = ? AND status = 'enrolled' AND is_active = 1 AND id != ?");
                    $stmt->execute([$enrollment['student_id'], $newClassId, $enrollment['academic_year_id'], $enrollmentId]);
                    if ($stmt->fetch()) {
                        $errors['new_class_id'] = 'الطالب مسجل بالفعل في هذا الفصل';
                    }
                }

                // التحقق من سعة الفصل الجديد
                if (empty($errors)) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM student_enrollments WHERE class_id = ? AND status = 'enrolled' AND is_active = 1");
                    $stmt->execute([$newClassId]);
                    $currentCount = (int)$stmt->fetchColumn();
                    $classCapacity = (int)($newClass['capacity'] ?? 30);

                    if ($currentCount >= $classCapacity) {
                        $errors['new_class_id'] = "الفصل ممتلئ ({$currentCount}/{$classCapacity})";
                    }
                }

            } catch (Exception $e) {
                error_log('Transfer Validation Error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ في التحقق من بيانات الفصل الجديد';
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $oldClassName = $enrollment['class_name'];

                // تحديث تسجيل الطالب: تغيير الفصل
                $stmt = $db->prepare("UPDATE student_enrollments SET class_id = ? WHERE id = ?");
                $stmt->execute([$newClassId, $enrollmentId]);

                // تسجيل النشاط
                $transferDesc = "نقل الطالب من فصل '{$oldClassName}' إلى فصل '{$newClass['name_ar']}'";
                if (!empty($reason)) {
                    $transferDesc .= " - السبب: {$reason}";
                }
                logActivity($db, $_SESSION['user']['id'], 'transfer_enrollment',
                    $transferDesc, 'student_enrollment', $enrollmentId);

                $db->commit();

                setFlash('تم نقل الطالب بنجاح إلى فصل ' . e($newClass['name_ar']), 'success');
                redirect('index.php');

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                error_log('Enrollment Transfer Error: ' . $e->getMessage());
                $errors['general'] = 'حدث خطأ أثناء نقل الطالب. يرجى المحاولة مرة أخرى.';
            }
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تعديل تسجيل: <?= e($enrollment['student_name']) ?></h3>
        <div class="flex gap-2">
            <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
        </div>
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

        <!-- ============================================================
             بيانات التسجيل الحالية
             ============================================================ -->
        <div style="background:var(--bg-secondary, #f8f9fa); border-radius:8px; padding:16px; margin-bottom:24px;">
            <h4 style="margin:0 0 12px; font-size:14px; color:var(--text-secondary);">بيانات التسجيل الحالية</h4>
            <div class="grid grid-2">
                <div>
                    <span style="color:var(--text-secondary); font-size:13px;">الطالب:</span>
                    <strong><?= e($enrollment['student_name']) ?></strong>
                    <span style="color:var(--text-muted); font-size:12px;">(<?= e($enrollment['student_number']) ?>)</span>
                </div>
                <div>
                    <span style="color:var(--text-secondary); font-size:13px;">الفصل:</span>
                    <strong><?= e($enrollment['class_name']) ?></strong>
                    <?php if ($enrollment['grade_name']): ?>
                        <span style="color:var(--text-muted); font-size:12px;">- <?= e($enrollment['grade_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span style="color:var(--text-secondary); font-size:13px;">العام الدراسي:</span>
                    <strong><?= e($enrollment['academic_year_name']) ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-secondary); font-size:13px;">تاريخ التسجيل:</span>
                    <strong><?= formatDateAr($enrollment['enrollment_date'], 'medium') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-secondary); font-size:13px;">الحالة:</span>
                    <?php
                    $statusBadgeClass = [
                        'enrolled'    => 'badge-success',
                        'transferred' => 'badge-warning',
                        'dropped'     => 'badge-danger'
                    ];
                    ?>
                    <span class="badge <?= $statusBadgeClass[$enrollment['status']] ?? 'badge-secondary' ?>">
                        <?= e(getStatusLabel($enrollment['status'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
             تبويبات التعديل
             ============================================================ -->
        <div class="flex gap-2 mb-6">
            <a href="edit.php?id=<?= $enrollmentId ?>&action=status" class="btn <?= $action === 'status' ? 'btn-primary' : 'btn-light' ?>">📝 تعديل الحالة</a>
            <?php if ($enrollment['status'] === 'enrolled'): ?>
                <a href="edit.php?id=<?= $enrollmentId ?>&action=transfer" class="btn <?= $action === 'transfer' ? 'btn-primary' : 'btn-light' ?>">🔄 نقل إلى فصل آخر</a>
            <?php endif; ?>
        </div>

        <?php if ($action === 'status'): ?>
            <!-- ============================================================
                 نموذج تعديل الحالة
                 ============================================================ -->
            <form method="POST" action="" data-validate>
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="status">

                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">الحالة الجديدة <span class="required">*</span></label>
                        <select name="status" id="status-select" class="form-control <?= isset($errors['status']) ? 'is-invalid' : '' ?>" required>
                            <?php
                            $statusOptions = [
                                'enrolled'    => 'مسجل',
                                'transferred' => 'منقول',
                                'dropped'     => 'منسحب'
                            ];
                            foreach ($statusOptions as $val => $label):
                                $selected = ($enrollment['status'] === $val) ? 'selected' : '';
                            ?>
                                <option value="<?= $val ?>" <?= $selected ?>>
                                    <?= e($label) ?>
                                    <?php if ($enrollment['status'] === $val): ?> (الحالية)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <div class="invalid-feedback"><?= e($errors['status']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">تاريخ التسجيل</label>
                        <input type="date" name="enrollment_date" class="form-control <?= isset($errors['enrollment_date']) ? 'is-invalid' : '' ?>"
                               value="<?= e($enrollment['enrollment_date']) ?>">
                        <?php if (isset($errors['enrollment_date'])): ?>
                            <div class="invalid-feedback"><?= e($errors['enrollment_date']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">يمكنك تحديث تاريخ التسجيل إذا لزم الأمر</div>
                    </div>
                </div>

                <div class="form-group" id="reason-group">
                    <label class="form-label">سبب التغيير <span class="required" id="reason-required" style="display:none;">*</span></label>
                    <textarea name="reason" class="form-control <?= isset($errors['reason']) ? 'is-invalid' : '' ?>"
                              rows="3" placeholder="اذكر سبب تغيير حالة التسجيل..." id="reason-field"><?= e($_POST['reason'] ?? '') ?></textarea>
                    <?php if (isset($errors['reason'])): ?>
                        <div class="invalid-feedback"><?= e($errors['reason']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="divider"></div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                    <a href="index.php" class="btn btn-light">إلغاء</a>
                </div>
            </form>

        <?php elseif ($action === 'transfer' && $enrollment['status'] === 'enrolled'): ?>
            <!-- ============================================================
                 نموذج النقل إلى فصل آخر
                 ============================================================ -->
            <form method="POST" action="" data-validate>
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="transfer">

                <div class="form-group">
                    <label class="form-label">الفصل الجديد <span class="required">*</span></label>
                    <select name="new_class_id" class="form-control <?= isset($errors['new_class_id']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر الفصل الجديد</option>
                        <?php foreach ($transferClasses as $tc): ?>
                            <?php
                            $currentCount = (int)($tc['current_count'] ?? 0);
                            $capacity = (int)($tc['capacity'] ?? 30);
                            $label = e($tc['name_ar']);
                            if ($tc['grade_name']) {
                                $label .= ' - ' . e($tc['grade_name']);
                            }
                            $label .= " ({$currentCount}/{$capacity})";
                            $fullClass = $currentCount >= $capacity ? ' disabled' : '';
                            ?>
                            <option value="<?= $tc['id'] ?>"<?= $fullClass ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['new_class_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['new_class_id']) ?></div>
                    <?php endif; ?>
                    <?php if (empty($transferClasses)): ?>
                        <div class="form-text" style="color:var(--warning);">
                            ⚠️ لا توجد فصول متاحة للنقل في هذا العام الدراسي
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">سبب النقل <span class="required">*</span></label>
                    <textarea name="transfer_reason" class="form-control <?= isset($errors['transfer_reason']) ? 'is-invalid' : '' ?>"
                              rows="3" placeholder="اذكر سبب نقل الطالب إلى فصل آخر..." required><?= e($_POST['transfer_reason'] ?? '') ?></textarea>
                    <?php if (isset($errors['transfer_reason'])): ?>
                        <div class="invalid-feedback"><?= e($errors['transfer_reason']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="divider"></div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary">🔄 تنفيذ النقل</button>
                    <a href="edit.php?id=<?= $enrollmentId ?>&action=status" class="btn btn-light">إلغاء</a>
                </div>
            </form>
        <?php endif; ?>

        <!-- ============================================================
             سجل التغييرات
             ============================================================ -->
        <?php if (!empty($changeLog)): ?>
            <div class="divider" style="margin-top:32px;"></div>
            <h4 style="margin-bottom:12px; font-size:15px; color:var(--text-secondary);">
                📋 سجل التغييرات
            </h4>
            <div style="max-height:300px; overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>الإجراء</th>
                            <th>التفاصيل</th>
                            <th>بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeLog as $log): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:12px;">
                                    <?= formatDateAr($log['created_at'], 'medium') ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?= e($log['action']) ?></span>
                                </td>
                                <td style="font-size:13px;"><?= e($log['description']) ?></td>
                                <td style="font-size:13px;"><?= e($log['user_name'] ?? 'النظام') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$pageJs = <<<JS
    // ============================================================
    // إظهار/إخفاء حقل السبب بناءً على الحالة المختارة
    // ============================================================
    const statusSelect = document.getElementById('status-select');
    const reasonGroup = document.getElementById('reason-group');
    const reasonRequired = document.getElementById('reason-required');
    const reasonField = document.getElementById('reason-field');

    function toggleReasonField() {
        if (!statusSelect) return;
        const selected = statusSelect.value;
        if (selected === 'transferred' || selected === 'dropped') {
            if (reasonGroup) reasonGroup.style.display = 'block';
            if (reasonRequired) reasonRequired.style.display = 'inline';
            if (reasonField) reasonField.setAttribute('required', 'required');
        } else {
            if (reasonRequired) reasonRequired.style.display = 'none';
            if (reasonField) reasonField.removeAttribute('required');
        }
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleReasonField);
        toggleReasonField();
    }
JS;
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
