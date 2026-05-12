<?php
/**
 * ============================================================
 * EQAS - بيانات المدرسة
 * نظام التقييم الشامل - School Information Setup
 * ============================================================
 */

$pageTitle = 'بيانات المدرسة';
$breadcrumb = ['الإعدادات الهيكلية' => 'index.php', 'بيانات المدرسة' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();

// جلب بيانات المدرسة
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $nameAr = trim($_POST['name_ar'] ?? '');
        $governorate = trim($_POST['governorate'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $schoolType = $_POST['school_type'] ?? 'combined';
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $principalName = trim($_POST['principal_name'] ?? '');

        if (empty($nameAr)) {
            setFlash('اسم المدرسة مطلوب.', 'warning');
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE schools SET name_ar = ?, governorate = ?, district = ?,
                    school_type = ?, address = ?, phone = ?, email = ?, principal_name = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nameAr, $governorate, $district, $schoolType, $address, $phone, $email, $principalName, $schoolId]);
                logActivity($db, $_SESSION['user']['id'], 'setup_school_info', "تحديث بيانات المدرسة رقم {$schoolId}", 'schools', $schoolId);
                setFlash('تم تحديث بيانات المدرسة بنجاح.', 'success');
            } catch (Exception $e) {
                setFlash('خطأ في الحفظ: ' . $e->getMessage(), 'danger');
            }
        }
        redirect('school_info.php');
    }
}

// إعادة جلب بعد الحفظ
if ($schoolId) {
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
}

$schoolTypes = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
$governorates = ['أمانة العاصمة', 'عدن', 'تعز', 'الحديدة', 'إب', 'حضرموت', 'المهرة', 'شبوة', 'أبين', 'لحج', 'الضالع', 'البيضاء', 'مأرب', 'صنعاء', 'ذمار', 'حجة', 'صعدة', 'عمران', 'الجوف', 'ريمة'];
?>

<div class="section">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">🏛️ بيانات المدرسة</h2>
        </div>
        <div class="card-body">
            <?php if (!$school): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <p>لم يتم تحديد مدرسة مرتبطة بحسابك.</p>
            </div>
            <?php else: ?>

            <!-- عرض الرمز المرجعي -->
            <div style="background:var(--primary);color:white;padding:16px 24px;border-radius:var(--radius-lg);margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:12px;opacity:0.8;">الرمز المرجعي</div>
                    <div style="font-size:28px;font-weight:bold;letter-spacing:3px;"><?= e($school['reference_code']) ?></div>
                </div>
                <div style="font-size:48px;">🏛️</div>
            </div>

            <form method="POST">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">اسم المدرسة <span class="required">*</span></label>
                        <input type="text" name="name_ar" class="form-control" value="<?= e($school['name_ar']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نوع المدرسة</label>
                        <select name="school_type" class="form-control">
                            <?php foreach ($schoolTypes as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $school['school_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">المحافظة</label>
                        <select name="governorate" class="form-control">
                            <option value="">اختر المحافظة</option>
                            <?php foreach ($governorates as $gov): ?>
                            <option value="<?= e($gov) ?>" <?= $school['governorate'] === $gov ? 'selected' : '' ?>><?= e($gov) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">المديرية</label>
                        <input type="text" name="district" class="form-control" value="<?= e($school['district'] ?? '') ?>" placeholder="اسم المديرية">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">العنوان</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="العنوان التفصيلي"><?= e($school['address'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">اسم المدير</label>
                        <input type="text" name="principal_name" class="form-control" value="<?= e($school['principal_name'] ?? '') ?>" placeholder="اسم مدير المدرسة">
                    </div>
                    <div class="form-group">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="tel" name="phone" class="form-control" value="<?= e($school['phone'] ?? '') ?>" placeholder="رقم هاتف المدرسة">
                    </div>
                    <div class="form-group">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" value="<?= e($school['email'] ?? '') ?>" placeholder="بريد إلكتروني">
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary btn-lg">💾 حفظ البيانات</button>
                    <a href="index.php" class="btn btn-light btn-lg">↩ رجوع</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
