<?php
/**
 * ============================================================
 * استيراد طلاب من ملف CSV
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'استيراد طلاب';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة الطلاب' => 'index.php',
    'استيراد طلاب' => null
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

// جلب المدارس
if ($isGroupAdmin) {
    $schools = $db->query("SELECT id, name_ar, code FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, name_ar, code FROM schools WHERE id = ? AND is_active = 1");
    $stmt->execute([$userSchoolId]);
    $schools = $stmt->fetchAll();
}

// جلب المراحل الدراسية
$gradeLevels = $db->query("SELECT id, name_ar, level_number FROM grade_levels WHERE is_active = 1 ORDER BY level_number")->fetchAll();
$gradeMap = [];
foreach ($gradeLevels as $gl) {
    $gradeMap[$gl['level_number']] = $gl['id'];
}

// بناء خريطة المدارس بالرمز
$schoolCodeMap = [];
foreach ($schools as $sc) {
    if (!empty($sc['code'])) {
        $schoolCodeMap[$sc['code']] = $sc['id'];
    }
}

$preview = [];
$importErrors = [];
$importSuccess = false;
$totalImported = 0;
$totalErrors = 0;
$totalSkipped = 0;

// تحديد المدرسة الافتراضية لغير مدير النظام
$defaultSchoolId = $isGroupAdmin ? null : $userSchoolId;

// المرحلة 1: رفع الملف ومعاينة البيانات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('import.php');
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = 'يرجى اختيار ملف CSV صحيح';
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $importErrors[] = 'حجم الملف يتجاوز الحد المسموح (5 ميجابايت)';
    } else {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        $mimeType = mime_content_type($tmpName);

        // التحقق من نوع الملف
        if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])) {
            $importErrors[] = 'يجب أن يكون الملف بصيغة CSV';
        } else {
            // تحديد المدرسة المستهدفة
            $targetSchoolId = $isGroupAdmin ? intval($_POST['school_id'] ?? 0) : $userSchoolId;
            if (empty($targetSchoolId)) {
                $importErrors[] = 'يرجى اختيار المدرسة المستهدفة';
            } else {
                // قراءة الملف
                $handle = fopen($tmpName, 'r');
                if ($handle) {
                    // تجاوز صف العناوين
                    $headers = fgetcsv($handle, 1000, ',');

                    // التحقق من وجود الأعمدة المطلوبة
                    $requiredColumns = ['student_number', 'full_name_ar', 'grade_level_id'];
                    $headerMap = array_map('trim', $headers);
                    $missingColumns = array_diff($requiredColumns, $headerMap);

                    if (!empty($missingColumns)) {
                        $importErrors[] = 'أعمدة مفقودة: ' . implode('، ', $missingColumns);
                    } else {
                        // بناء خريطة الأعمدة
                        $colIndex = array_flip($headerMap);

                        $rowIndex = 1;
                        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                            $rowIndex++;
                            $row = [
                                'student_number' => trim($data[$colIndex['student_number'] ?? 0] ?? ''),
                                'full_name_ar' => trim($data[$colIndex['full_name_ar'] ?? 1] ?? ''),
                                'full_name_en' => trim($data[$colIndex['full_name_en'] ?? 2] ?? ''),
                                'guardian_name' => trim($data[$colIndex['guardian_name'] ?? 3] ?? ''),
                                'guardian_phone' => trim($data[$colIndex['guardian_phone'] ?? 4] ?? ''),
                                'guardian_email' => trim($data[$colIndex['guardian_email'] ?? 5] ?? ''),
                                'grade_level_id' => trim($data[$colIndex['grade_level_id'] ?? 6] ?? ''),
                                'row_number' => $rowIndex,
                                'valid' => true,
                                'errors' => []
                            ];

                            // التحقق من الحقول المطلوبة
                            if (empty($row['student_number'])) $row['errors'][] = 'رقم الطالب مطلوب';
                            if (empty($row['full_name_ar'])) $row['errors'][] = 'الاسم الكامل بالعربية مطلوب';
                            if (empty($row['grade_level_id'])) $row['errors'][] = 'رقم المرحلة الدراسية مطلوب';

                            // التحقق من صحة المرحلة الدراسية
                            if (!empty($row['grade_level_id']) && !isset($gradeMap[$row['grade_level_id']])) {
                                $row['errors'][] = 'رقم المرحلة غير صحيح: ' . $row['grade_level_id'];
                            }

                            // التحقق من بريد ولي الأمر
                            if (!empty($row['guardian_email']) && !filter_var($row['guardian_email'], FILTER_VALIDATE_EMAIL)) {
                                $row['errors'][] = 'البريد الإلكتروني غير صحيح';
                            }

                            if (!empty($row['errors'])) {
                                $row['valid'] = false;
                            }

                            $preview[] = $row;
                        }
                        fclose($handle);

                        // التحقق من تفرد أرقام الطلاب داخل الملف
                        $numbers = array_column($preview, 'student_number');
                        $duplicates = array_diff_assoc($numbers, array_unique($numbers));
                        foreach ($preview as &$prow) {
                            if (in_array($prow['student_number'], $duplicates) && $prow['valid']) {
                                $prow['errors'][] = 'رقم الطالب مكرر في الملف';
                                $prow['valid'] = false;
                            }
                        }
                        unset($prow);

                        // حفظ البيانات في الجلسة للمرحلة التالية
                        $_SESSION['import_preview'] = $preview;
                        $_SESSION['import_school_id'] = $targetSchoolId;
                    }
                }
            }
        }
    }
}

// المرحلة 2: تنفيذ الاستيراد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('import.php');
    }

    $preview = $_SESSION['import_preview'] ?? [];
    $targetSchoolId = $_SESSION['import_school_id'] ?? null;
    unset($_SESSION['import_preview'], $_SESSION['import_school_id']);

    if (empty($preview)) {
        setFlash('لا توجد بيانات للاستيراد. يرجى رفع الملف مرة أخرى.', 'warning');
        redirect('import.php');
    }

    // التحقق من صلاحية المدرسة
    if (!$isGroupAdmin && $targetSchoolId != $userSchoolId) {
        setFlash('ليس لديك صلاحية لاستيراد طلاب لمدرسة أخرى', 'danger');
        redirect('import.php');
    }

    try {
        $db->beginTransaction();

        foreach ($preview as $row) {
            if (!$row['valid']) {
                $totalErrors++;
                continue;
            }

            // التحقق من تفرد رقم الطالب في قاعدة البيانات
            $stmt = $db->prepare("SELECT id FROM students WHERE student_number = ?");
            $stmt->execute([$row['student_number']]);
            if ($stmt->fetch()) {
                $totalSkipped++;
                continue;
            }

            // تحديد grade_level_id من الرقم
            $gradeLevelId = $gradeMap[$row['grade_level_id']] ?? null;
            if (!$gradeLevelId) {
                $totalErrors++;
                continue;
            }

            // إدراج الطالب
            $stmt = $db->prepare("INSERT INTO students (student_number, full_name_ar, full_name_en, guardian_name, guardian_phone, guardian_email, grade_level_id, school_id, is_active, enrollment_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, CURDATE(), NOW())");
            $stmt->execute([
                $row['student_number'],
                $row['full_name_ar'],
                $row['full_name_en'] ?: null,
                $row['guardian_name'] ?: null,
                $row['guardian_phone'] ?: null,
                $row['guardian_email'] ?: null,
                $gradeLevelId,
                $targetSchoolId
            ]);

            $totalImported++;
        }

        $db->commit();

        // تسجيل النشاط
        logActivity($db, $_SESSION['user']['id'], 'import_students', "استيراد {$totalImported} طالب", 'student', null);

        $importSuccess = true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Student Import Error: ' . $e->getMessage());
        $importErrors[] = 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage();
    }
}

// أرقام المراحل الدراسية المتاحة
$gradeNumbers = array_keys($gradeMap);
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">📥 استيراد طلاب من ملف CSV</h3>
        <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
    </div>
    <div class="card-body">
        <?php if ($importSuccess): ?>
            <div class="flash-message flash-success mb-4">
                <span>✅ تم استيراد <?= $totalImported ?> طالب بنجاح.
                <?php if ($totalSkipped > 0): ?>تم تجاوز <?= $totalSkipped ?> سجل (أرقام طلاب مكررة).<?php endif; ?>
                <?php if ($totalErrors > 0): ?>وفشل استيراد <?= $totalErrors ?> سجل.<?php endif; ?>
                </span>
            </div>
            <a href="index.php" class="btn btn-primary">العودة لقائمة الطلاب</a>
        <?php elseif (!empty($preview)): ?>
            <!-- معاينة البيانات -->
            <h4 class="mb-4">معاينة البيانات قبل الاستيراد</h4>

            <div class="flex gap-4 mb-4 text-sm">
                <span>إجمالي السجلات: <strong><?= count($preview) ?></strong></span>
                <span style="color:var(--success);">صالحة: <strong><?= count(array_filter($preview, fn($r) => $r['valid'])) ?></strong></span>
                <span style="color:var(--danger);">أخطاء: <strong><?= count(array_filter($preview, fn($r) => !$r['valid'])) ?></strong></span>
            </div>

            <div class="table-wrapper" style="max-height:400px;overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>رقم الطالب</th>
                            <th>الاسم الكامل</th>
                            <th>المرحلة</th>
                            <th>ولي الأمر</th>
                            <th>الحالة</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $row): ?>
                            <tr style="<?= $row['valid'] ? '' : 'background:rgba(220,53,69,0.05);' ?>">
                                <td><?= $row['row_number'] ?></td>
                                <td><?= e($row['student_number']) ?></td>
                                <td><?= e($row['full_name_ar']) ?></td>
                                <td><?= e($row['grade_level_id']) ?></td>
                                <td><?= e($row['guardian_name'] ?: '—') ?></td>
                                <td>
                                    <?php if ($row['valid']): ?>
                                        <span class="badge badge-success">✓ صالح</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">✗ خطأ</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['errors'])): ?>
                                        <span style="color:var(--danger);font-size:var(--font-size-xs);"><?= implode('، ', $row['errors']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="divider"></div>
            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="flex gap-3">
                    <button type="submit" name="confirm_import" class="btn btn-primary" <?= count(array_filter($preview, fn($r) => $r['valid'])) === 0 ? 'disabled' : '' ?>>
                        ✅ تأكيد الاستيراد (<?= count(array_filter($preview, fn($r) => $r['valid'])) ?> سجل)
                    </button>
                    <a href="import.php" class="btn btn-light">إلغاء</a>
                </div>
            </form>
        <?php else: ?>
            <!-- نموذج رفع الملف -->
            <?php if (!empty($importErrors)): ?>
                <div class="flash-message flash-danger mb-4">
                    <?php foreach ($importErrors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- تعليمات تنسيق الملف -->
            <div class="card mb-6" style="background:var(--background);">
                <div class="card-body">
                    <h5 class="mb-3">📋 تعليمات تنسيق ملف CSV</h5>
                    <p class="text-sm text-secondary mb-3">يجب أن يحتوي الملف على الصف التالي كعناوين في الصف الأول:</p>
                    <code style="display:block;padding:var(--space-3);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);direction:ltr;text-align:left;font-size:var(--font-size-sm);">
                        student_number,full_name_ar,full_name_en,guardian_name,guardian_phone,guardian_email,grade_level_id
                    </code>
                    <div class="mt-3 text-sm">
                        <p><strong>الأعمدة المطلوبة:</strong> student_number, full_name_ar, grade_level_id</p>
                        <p><strong>أعمدة اختيارية:</strong> full_name_en, guardian_name, guardian_phone, guardian_email</p>
                        <p><strong>أرقام المراحل المتاحة:</strong> <?= implode('، ', $gradeNumbers) ?> (حيث 1=الأول أساسي، 12=الثاني عشر ثانوي)</p>
                    </div>

                    <h6 class="mt-4 mb-2">مثال:</h6>
                    <code style="display:block;padding:var(--space-3);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);direction:ltr;text-align:left;font-size:var(--font-size-sm);">
                        student_number,full_name_ar,full_name_en,guardian_name,guardian_phone,guardian_email,grade_level_id<br>
                        STD001,أحمد محمد العلي,Ahmed Ali,محمد العلي,777123456,father@example.com,5<br>
                        STD002,فاطمة عبدالله,Fatima Abdullah,عبدالله أحمد,777654321,,7
                    </code>
                </div>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrfField() ?>

                <?php if ($isGroupAdmin): ?>
                <div class="form-group">
                    <label class="form-label">المدرسة المستهدفة <span class="required">*</span></label>
                    <select name="school_id" class="form-control" required>
                        <option value="">اختر المدرسة</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= $school['id'] ?>"><?= e($school['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">اختر ملف CSV <span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    <div class="form-text">الحجم الأقصى: 5 ميجابايت | الصيغة: CSV | الترميز: UTF-8</div>
                </div>

                <button type="submit" name="upload_csv" class="btn btn-primary">📤 رفع ومعاينة</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
