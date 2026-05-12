<?php
/**
 * ============================================================
 * تعديل بيانات مدرسة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تعديل بيانات مدرسة';
$breadcrumb = [
    'الإدارة' => null,
    'إدارة المدارس' => 'index.php',
    'تعديل مدرسة' => null
];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

$schoolId = intval($_GET['id'] ?? 0);
if (!$schoolId) {
    setFlash('معرف المدرسة غير صحيح', 'danger');
    redirect('index.php');
}

$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('المدرسة غير موجودة', 'danger');
    redirect('index.php');
}

$yemenGovernorates = [
    'أمانة العاصمة', 'عدن', 'لحج', 'أبين', 'شبوة', 'حضرموت', 'المهرة',
    'حجة', 'عمران', 'صعدة', 'الجوف', 'مأرب', 'البيضاء', 'إب', 'تعز',
    'الحديدة', 'ريمة', 'ذمار', 'الضالع', 'صنعاء', 'سقطرى'
];
$districts = $db->query("SELECT id, name_ar FROM districts WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
        redirect("edit.php?id={$schoolId}");
    }

    $name_ar = trim($_POST['name_ar'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $governorate = $_POST['governorate'] ?? '';
    $district_id = $_POST['district_id'] ?? '';
    $district = trim($_POST['district'] ?? '');
    $school_type = $_POST['school_type'] ?? '';
    $principal_name = trim($_POST['principal_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($name_ar)) $errors['name_ar'] = 'اسم المدرسة بالعربية مطلوب';
    if (empty($school_type)) $errors['school_type'] = 'نوع المدرسة مطلوب';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'البريد الإلكتروني غير صحيح';

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE schools SET name_ar=?, name_en=?, address=?, governorate=?, district_id=?, district=?, school_type=?, principal_name=?, phone=?, email=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([
                $name_ar, $name_en ?: null, $address ?: null,
                $governorate ?: null, $district_id ?: null, $district ?: null,
                $school_type, $principal_name ?: null, $phone ?: null,
                $email ?: null, $schoolId
            ]);

            logActivity($db, $_SESSION['user']['id'], 'edit_school', "تعديل بيانات المدرسة: {$name_ar}", 'school', $schoolId);
            setFlash('تم تحديث بيانات المدرسة بنجاح', 'success');
            redirect("view.php?id={$schoolId}");
        } catch (Exception $e) {
            error_log('School Edit Error: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء التحديث';
        }
    }

    // تحديث بيانات النموذج
    $school['name_ar'] = $name_ar;
    $school['name_en'] = $name_en;
    $school['address'] = $address;
    $school['governorate'] = $governorate;
    $school['district_id'] = $district_id;
    $school['district'] = $district;
    $school['school_type'] = $school_type;
    $school['principal_name'] = $principal_name;
    $school['phone'] = $phone;
    $school['email'] = $email;
}
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">تعديل بيانات المدرسة: <?= e($school['name_ar']) ?></h3>
        <div class="flex gap-2">
            <a href="view.php?id=<?= $schoolId ?>" class="btn btn-info btn-sm">👁️ عرض</a>
            <a href="index.php" class="btn btn-light btn-sm">↩ القائمة</a>
        </div>
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
                           value="<?= e($school['name_ar']) ?>" required>
                    <?php if (isset($errors['name_ar'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name_ar']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">اسم المدرسة بالإنجليزية</label>
                    <input type="text" name="name_en" class="form-control"
                           value="<?= e($school['name_en'] ?? '') ?>" style="direction:ltr;text-align:left;">
                </div>
            </div>

            <!-- رمز المدرسة (للقراءة فقط) -->
            <div class="form-group">
                <label class="form-label">رمز المدرسة</label>
                <input type="text" class="form-control" value="<?= e($school['code']) ?>" disabled>
                <div class="form-text">لا يمكن تغيير رمز المدرسة بعد الإنشاء</div>
            </div>

            <div class="form-group">
                <label class="form-label">العنوان</label>
                <textarea name="address" class="form-control" rows="2"><?= e($school['address'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">المحافظة</label>
                    <select name="governorate" class="form-control">
                        <option value="">اختر المحافظة</option>
                        <?php foreach ($yemenGovernorates as $gov): ?>
                            <option value="<?= e($gov) ?>" <?= ($school['governorate'] ?? '') === $gov ? 'selected' : '' ?>><?= e($gov) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">المديرية</label>
                    <select name="district_id" class="form-control">
                        <option value="">اختر المديرية</option>
                        <?php foreach ($districts as $dist): ?>
                            <option value="<?= $dist['id'] ?>" <?= ($school['district_id'] ?? '') == $dist['id'] ? 'selected' : '' ?>><?= e($dist['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">نوع المدرسة <span class="required">*</span></label>
                    <select name="school_type" class="form-control <?= isset($errors['school_type']) ? 'is-invalid' : '' ?>" required>
                        <option value="">اختر النوع</option>
                        <option value="basic" <?= $school['school_type'] === 'basic' ? 'selected' : '' ?>>أساسية</option>
                        <option value="secondary" <?= $school['school_type'] === 'secondary' ? 'selected' : '' ?>>ثانوية</option>
                        <option value="combined" <?= $school['school_type'] === 'combined' ? 'selected' : '' ?>>مختلطة</option>
                    </select>
                    <?php if (isset($errors['school_type'])): ?>
                        <div class="invalid-feedback"><?= e($errors['school_type']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">اسم مدير المدرسة</label>
                    <input type="text" name="principal_name" class="form-control"
                           value="<?= e($school['principal_name'] ?? '') ?>">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($school['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($school['email'] ?? '') ?>">
                    <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="divider"></div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="view.php?id=<?= $schoolId ?>" class="btn btn-light">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
