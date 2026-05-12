<?php
/**
 * ============================================================
 * استيراد مستخدمين من ملف CSV
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'استيراد مستخدمين';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المستخدمين' => 'index.php',
    'استيراد مستخدمين' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// جلب الأدوار
$roles = $db->query("SELECT id, name, display_name_ar AS name_ar FROM roles WHERE is_active = 1 ORDER BY id")->fetchAll();
$roleMap = [];
foreach ($roles as $r) {
    $roleMap[$r['name']] = $r['id'];
}

$schools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

$preview = [];
$importErrors = [];
$importSuccess = false;
$totalImported = 0;
$totalErrors = 0;

// المرحلة 1: رفع الملف ومعاينة البيانات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('import.php');
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = 'يرجى اختيار ملف CSV صحيح';
    } else {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        $mimeType = mime_content_type($tmpName);

        // التحقق من نوع الملف
        if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])) {
            $importErrors[] = 'يجب أن يكون الملف بصيغة CSV';
        } else {
            // قراءة الملف
            $handle = fopen($tmpName, 'r');
            if ($handle) {
                // تجاوز صف العناوين
                $headers = fgetcsv($handle, 1000, ',');

                $rowIndex = 1;
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    $rowIndex++;
                    $row = [
                        'username' => trim($data[0] ?? ''),
                        'full_name_ar' => trim($data[1] ?? ''),
                        'email' => trim($data[2] ?? ''),
                        'phone' => trim($data[3] ?? ''),
                        'role' => trim($data[4] ?? ''),
                        'school_code' => trim($data[5] ?? ''),
                        'password' => trim($data[6] ?? ''),
                        'row_number' => $rowIndex,
                        'valid' => true,
                        'errors' => []
                    ];

                    // التحقق من الحقول المطلوبة
                    if (empty($row['username'])) $row['errors'][] = 'اسم المستخدم مطلوب';
                    if (empty($row['full_name_ar'])) $row['errors'][] = 'الاسم الكامل مطلوب';
                    if (empty($row['role'])) $row['errors'][] = 'الدور مطلوب';
                    if (empty($row['password'])) $row['errors'][] = 'كلمة المرور مطلوبة';

                    // التحقق من الدور
                    if (!empty($row['role']) && !isset($roleMap[$row['role']])) {
                        $row['errors'][] = 'الدور غير صحيح: ' . $row['role'];
                    }

                    // التحقق من طول كلمة المرور
                    if (!empty($row['password']) && mb_strlen($row['password']) < PASSWORD_MIN_LENGTH) {
                        $row['errors'][] = 'كلمة المرور قصيرة جداً';
                    }

                    // التحقق من البريد الإلكتروني
                    if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $row['errors'][] = 'البريد الإلكتروني غير صحيح';
                    }

                    if (!empty($row['errors'])) {
                        $row['valid'] = false;
                    }

                    $preview[] = $row;
                }
                fclose($handle);

                // التحقق من تفريد أسماء المستخدمين داخل الملف
                $usernames = array_column($preview, 'username');
                $duplicates = array_diff_assoc($usernames, array_unique($usernames));
                foreach ($preview as &$prow) {
                    if (in_array($prow['username'], $duplicates) && $prow['valid']) {
                        $prow['errors'][] = 'اسم المستخدم مكرر في الملف';
                        $prow['valid'] = false;
                    }
                }
                unset($prow);

                // حفظ البيانات في الجلسة للمرحلة التالية
                $_SESSION['import_preview'] = $preview;
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
    unset($_SESSION['import_preview']);

    if (empty($preview)) {
        setFlash('لا توجد بيانات للاستيراد. يرجى رفع الملف مرة أخرى.', 'warning');
        redirect('import.php');
    }

    try {
        $db->beginTransaction();

        foreach ($preview as $row) {
            if (!$row['valid']) {
                $totalErrors++;
                continue;
            }

            // التحقق من تفريد اسم المستخدم في قاعدة البيانات
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$row['username']]);
            if ($stmt->fetch()) {
                $totalErrors++;
                continue;
            }

            // تحديد school_id
            $schoolId = null;
            if (!empty($row['school_code'])) {
                $stmt = $db->prepare("SELECT id FROM schools WHERE code = ?");
                $stmt->execute([$row['school_code']]);
                $school = $stmt->fetch();
                if ($school) $schoolId = $school['id'];
            }

            // إدراج المستخدم
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name_ar, email, phone, role_id, school_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([
                $row['username'],
                password_hash($row['password'], PASSWORD_BCRYPT),
                $row['full_name_ar'],
                $row['email'] ?: null,
                $row['phone'] ?: null,
                $roleMap[$row['role']] ?? null,
                $schoolId
            ]);

            $totalImported++;
        }

        $db->commit();

        // تسجيل النشاط
        logActivity($db, $_SESSION['user']['id'], 'import_users', "استيراد {$totalImported} مستخدم", 'user', null);

        $importSuccess = true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Import Error: ' . $e->getMessage());
        $importErrors[] = 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage();
    }
}

// تحويل قائمة الأدوار للاستخدام في القالب
$roleNames = array_column($roles, 'name');
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">📥 استيراد مستخدمين من ملف CSV</h3>
        <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
    </div>
    <div class="card-body">
        <?php if ($importSuccess): ?>
            <div class="flash-message flash-success mb-4">
                <span>✅ تم استيراد <?= $totalImported ?> مستخدم بنجاح. <?php if ($totalErrors > 0): ?>وفشل استيراد <?= $totalErrors ?> سجل.<?php endif; ?></span>
            </div>
            <a href="index.php" class="btn btn-primary">العودة لقائمة المستخدمين</a>
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
                            <th>اسم المستخدم</th>
                            <th>الاسم الكامل</th>
                            <th>الدور</th>
                            <th>الحالة</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $row): ?>
                            <tr style="<?= $row['valid'] ? '' : 'background:rgba(220,53,69,0.05);' ?>">
                                <td><?= $row['row_number'] ?></td>
                                <td><?= e($row['username']) ?></td>
                                <td><?= e($row['full_name_ar']) ?></td>
                                <td><?= e($row['role']) ?></td>
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
                        username,full_name_ar,email,phone,role,school_code,password
                    </code>
                    <div class="mt-3 text-sm">
                        <p><strong>الأدوار المتاحة:</strong> <?= implode('، ', $roleNames) ?></p>
                        <p><strong>ملاحظة:</strong> الحقول المطلوبة: username, full_name_ar, role, password</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <?= csrfField() ?>

                <div class="form-group">
                    <label class="form-label">اختر ملف CSV <span class="required">*</span></label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    <div class="form-text">الحجم الأقصى: 5 ميجابايت | الصيغة: CSV</div>
                </div>

                <button type="submit" name="upload_csv" class="btn btn-primary">📤 رفع ومعاينة</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
