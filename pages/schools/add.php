<?php
/**
 * ============================================================
 * إضافة مدرسة جديدة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'إضافة مدرسة جديدة';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => 'index.php',
    'إضافة مدرسة' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$errors = [];
$old = [];

// المحافظات ة
$yemenGovernorates = [
    'أمانة العاصمة', 'عدن', 'لحج', 'أبين', 'شبوة', 'حضرموت', 'المهرة',
    'حجة', 'عمران', 'صعدة', 'الجوف', 'مأرب', 'البيضاء', 'إب', 'تعز',
    'الحديدة', 'ريمة', 'ذمار', 'الضالع', 'صنعاء', 'سقطرى'
];

// جلب المديريات
$districts = $db->query("SELECT id, governorate_id, name_ar FROM districts WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// توليد رمز المدرسة تلقائياً
function generateSchoolCode($db) {
    $stmt = $db->query("SELECT COUNT(*) FROM schools");
    $count = $stmt->fetchColumn();
    $number = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    // التأكد من عدم تكرار الرمز
    $code = 'SCH-' . $number;
    $stmt = $db->prepare("SELECT id FROM schools WHERE code = ?");
    $stmt->execute([$code]);
    while ($stmt->fetch()) {
        $count++;
        $number = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $code = 'SCH-' . $number;
        $stmt = $db->prepare("SELECT id FROM schools WHERE code = ?");
        $stmt->execute([$code]);
    }
    return $code;
}

// معالجة إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect('add.php');
    }

    $old = [
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'name_en' => trim($_POST['name_en'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'governorate' => $_POST['governorate'] ?? '',
        'district_id' => $_POST['district_id'] ?? '',
        'district' => trim($_POST['district'] ?? ''),
        'school_type' => $_POST['school_type'] ?? '',
        'principal_name' => trim($_POST['principal_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? '')
    ];

    // التحقق من الحقول المطلوبة
    if (empty($old['name_ar'])) $errors['name_ar'] = 'اسم المدرسة بالعربية مطلوب';
    if (empty($old['school_type'])) $errors['school_type'] = 'نوع المدرسة مطلوب';

    // التحقق من البريد الإلكتروني
    if (!empty($old['email']) && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'البريد الإلكتروني غير صحيح';
    }

    if (empty($errors)) {
        try {
            $code = generateSchoolCode($db);

            $stmt = $db->prepare("INSERT INTO schools (name_ar, name_en, code, address, governorate, district_id, district, school_type, principal_name, phone, email, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([
                $old['name_ar'],
                $old['name_en'] ?: null,
                $code,
                $old['address'] ?: null,
                $old['governorate'] ?: null,
                $old['district_id'] ?: null,
                $old['district'] ?: null,
                $old['school_type'],
                $old['principal_name'] ?: null,
                $old['phone'] ?: null,
                $old['email'] ?: null
            ]);

            $schoolId = $db->lastInsertId();
            logActivity($db, $_SESSION['user']['id'], 'create_school', "إضافة مدرسة جديدة: {$old['name_ar']} ({$code})", 'school', $schoolId);

            setFlash('تم إضافة المدرسة بنجاح برمز: ' . $code, 'success');
            redirect('index.php');
        } catch (Exception $e) {
            error_log('School Add Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء إضافة المدرسة';
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">إضافة مدرسة جديدة</h3>
        <a href="index.php" class="btn btn-light">↩ العودة للقائمة</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-danger mb-4"><span><?= e($errors['general']) ?></span></div>
        <?php endif; ?>

        <form method="POST" action="" data-validate>
            <?= csrfField() ?>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">اسم المدرسة بالعربية <span class="required">*</span></label>
                    <input type="text" name="name_ar" class="form-control <?= isset($errors['name_ar']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['name_ar'] ?? '') ?>" required placeholder="أدخل اسم المدرسة بالعربية">
                    <?php if (isset($errors['name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name_ar']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">اسم المدرسة بالإنجليزية</label>
                    <input type="text" name="name_en" class="form-control"
                           value="<?= e($old['name_en'] ?? '') ?>" placeholder="School Name" style="direction:ltr;text-align:left;">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">العنوان</label>
                <textarea name="address" class="form-control" rows="2" placeholder="عنوان المدرسة"><?= e($old['address'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">المحافظة</label>
                    <select name="governorate" id="governorate-select" class="form-control">
                        <option value="">اختر المحافظة</option>
                        <?php foreach ($yemenGovernorates as $gov): ?>
                            <option value="<?= e($gov) ?>" <?= ($old['governorate'] ?? '') === $gov ? 'selected' : '' ?>><?= e($gov) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">المديرية</label>
                    <select name="district_id" id="district-select" class="form-control">
                        <option value="">اختر المديرية</option>
                        <?php foreach ($districts as $dist): ?>
                            <option value="<?= $dist['id'] ?>" <?= ($old['district_id'] ?? '') == $dist['id'] ? 'selected' : '' ?>><?= e($dist['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">نوع المدرسة <span class="required">*</span></label>
                    <select name="school_type" class="form-control <?= isset($errors['school_type']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر النوع</option>
                        <option value="basic" <?= ($old['school_type'] ?? '') === 'basic' ? 'selected' : '' ?>>أساسية</option>
                        <option value="secondary" <?= ($old['school_type'] ?? '') === 'secondary' ? 'selected' : '' ?>>ثانوية</option>
                        <option value="combined" <?= ($old['school_type'] ?? '') === 'combined' ? 'selected' : '' ?>>مختلطة</option>
                    </select>
                    <?php if (isset($errors['school_type'])): ?>
                        <div class="invalid-feedback"><?= e($errors['school_type']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">اسم مدير المدرسة</label>
                    <input type="text" name="principal_name" class="form-control"
                           value="<?= e($old['principal_name'] ?? '') ?>" placeholder="اسم المدير">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= e($old['phone'] ?? '') ?>" placeholder="777123456">
                </div>

                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old['email'] ?? '') ?>" placeholder="school@example.com">
                    <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <div class="form-text mb-4">💡 سيتم توليد رمز المدرسة تلقائياً بصيغة SCH-XXXX</div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ المدرسة</button>
                <a href="index.php" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
