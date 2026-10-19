<?php
/**
 * ============================================================
 * EQAS - استعادة من نسخة احتياطية (4 مستويات أمان)
 * نظام التقييم الشامل - System Restore with 4-Level Safety
 * ============================================================
 * نظام استعادة آمن بأربعة مستويات حماية:
 * المستوى 1: فقط مدير النظام (requireRole + إعادة مصادقة)
 * المستوى 2: تأكيد مزدوج - كتابة كلمة "استعادة"
 * المستوى 3: إنشاء نسخة احتياطية تلقائياً قبل التنفيذ
 * المستوى 4: تسجيل كامل في سجل النشاط (من، متى، أي نسخة)
 * ============================================================
 */

$pageTitle = 'استعادة من نسخة احتياطية';
$breadcrumb = ['النظام' => '#', 'استعادة' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/backup.php';

// ═══════════════════════════════════════════
// المستوى 1: التحقق من الصلاحيات
// ═══════════════════════════════════════════
requireRole('group_admin');

// متغيرات الحالة
$reAuthenticated = false;
$step = (int)($_GET['step'] ?? 1);
$selectedBackupId = $_GET['backup_id'] ?? $_POST['backup_id'] ?? '';
$restoreResult = null;
$preRestoreBackupId = null;

$backupManager = new BackupManager($db);

// ═══════════════════════════════════════════
// المستوى 1: إعادة المصادقة
// ═══════════════════════════════════════════
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reauthenticate'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
        redirect('restore.php?step=1');
    }

    $password = $_POST['admin_password'] ?? '';

    // التحقق من كلمة مرور المدير
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['restore_authenticated'] = true;
        $_SESSION['restore_auth_time'] = time();
        logActivity($db, $_SESSION['user']['id'], 'restore_auth', 'مصادقة ناجحة للاستعادة', 'system', null);
        redirect('restore.php?step=2');
    } else {
        setFlash('كلمة المرور غير صحيحة.', 'danger');
        logActivity($db, $_SESSION['user']['id'], 'restore_auth_failed', 'محاولة مصادقة فاشلة للاستعادة', 'system', null);
        redirect('restore.php?step=1');
    }
}

// التحقق من المصادقة السابقة (صالحة لمدة 10 دقائق)
$isAuthValid = isset($_SESSION['restore_authenticated']) && $_SESSION['restore_authenticated']
    && isset($_SESSION['restore_auth_time']) && (time() - $_SESSION['restore_auth_time']) < 600;

if ($step > 1 && !$isAuthValid) {
    setFlash('يرجى إعادة المصادقة أولاً.', 'warning');
    redirect('restore.php?step=1');
}

// ═══════════════════════════════════════════
// المستوى 2: التأكيد المزدوج - كتابة "استعادة"
// ═══════════════════════════════════════════
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان.', 'danger');
        redirect('restore.php?step=2');
    }

    $confirmText = trim($_POST['confirm_text'] ?? '');
    $backupId = (int)($_POST['backup_id'] ?? 0);

    // التحقق من نص التأكيد
    if ($confirmText !== 'استعادة') {
        setFlash('يرجى كتابة كلمة "استعادة" بالضبط للتأكيد.', 'warning');
        redirect('restore.php?step=3&backup_id=' . $backupId);
    }

    // جلب معلومات النسخة
    $backup = $backupManager->getBackupById($backupId);

    if (!$backup) {
        setFlash('النسخة الاحتياطية غير موجودة أو غير مكتملة.', 'danger');
        redirect('restore.php?step=2');
    }

    // ═══════════════════════════════════════════
    // المستوى 3: إنشاء نسخة احتياطية قبل الاستعادة
    // ═══════════════════════════════════════════
    $preRestoreResult = $backupManager->createPreOperationBackup('restore', $_SESSION['user']['id']);

    if (!$preRestoreResult['success']) {
        setFlash('فشل إنشاء نسخة احتياطية قبل الاستعادة: ' . $preRestoreResult['message'] . ' - تم إلغاء العملية.', 'danger');
        redirect('restore.php?step=2');
    }

    $preRestoreBackupId = $preRestoreResult['backup_id'];

    // ═══════════════════════════════════════════
    // تنفيذ الاستعادة
    // ═══════════════════════════════════════════
    $restoreResult = $backupManager->restoreBackup($backup['file_path'], $_SESSION['user']['id']);

    // ═══════════════════════════════════════════
    // المستوى 4: تسجيل كامل في سجل النشاط
    // ═══════════════════════════════════════════
    logActivity($db, $_SESSION['user']['id'], 'backup_restore',
        sprintf('استعادة من نسخة احتياطية: ID=%d, ملف=%s, نسخة_قبل=%d, نجح=%s, استعلامات=%d, أخطاء=%d',
            $backupId,
            basename($backup['file_path']),
            $preRestoreBackupId,
            $restoreResult['success'] ? 'نعم' : 'لا',
            $restoreResult['queries_executed'] ?? 0,
            count($restoreResult['errors'] ?? [])
        ),
        'backup',
        $backupId
    );

    // مسح بيانات المصادقة
    unset($_SESSION['restore_authenticated'], $_SESSION['restore_auth_time']);

    // الانتقال لخطوة النتيجة
    $step = 4;
}

// جلب النسخ الاحتياطية المتاحة
$backups = $backupManager->listBackups(['status' => 'completed']);

// معلومات نسخة محددة
$selectedBackup = null;
$backupInfo = null;
if ($selectedBackupId) {
    $selectedBackup = $backupManager->getBackupById($selectedBackupId);
    if ($selectedBackup) {
        $backupInfo = $backupManager->getBackupInfo($selectedBackup['file_path']);
    }
}

// نوع النسخة بالعربية
$typeLabels = ['full' => 'كاملة', 'pre_operation' => 'قبل عملية', 'manual' => 'يدوية'];
?>

<style>
.restore-step {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
}
.restore-step-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: var(--radius-full);
    font-size: 13px;
    font-weight: 600;
    background: var(--background);
    color: var(--text-secondary);
    border: 2px solid var(--border);
    transition: all var(--transition-base);
}
.restore-step-item.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
.restore-step-item.completed {
    background: var(--success);
    color: white;
    border-color: var(--success);
}
.restore-step-connector {
    width: 40px;
    height: 2px;
    background: var(--border);
    margin: 0 4px;
}
.restore-step-connector.completed {
    background: var(--success);
}
.safety-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 10px;
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 600;
}
</style>

<div class="section">
    <!-- ═══════════════════════════════════════════
         شريط الخطوات
         ═══════════════════════════════════════════ -->
    <div class="restore-step">
        <div class="restore-step-item <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
            🔐 المصادقة
        </div>
        <div class="restore-step-connector <?= $step > 1 ? 'completed' : '' ?>"></div>
        <div class="restore-step-item <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
            📋 اختيار النسخة
        </div>
        <div class="restore-step-connector <?= $step > 2 ? 'completed' : '' ?>"></div>
        <div class="restore-step-item <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">
            ⚠️ التأكيد
        </div>
        <div class="restore-step-connector <?= $step > 3 ? 'completed' : '' ?>"></div>
        <div class="restore-step-item <?= $step >= 4 ? 'active' : '' ?>">
            📊 النتيجة
        </div>
    </div>

    <?php if ($step === 1): ?>
    <!-- ═══════════════════════════════════════════
         الخطوة 1: إعادة المصادقة
         ═══════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header" style="background:var(--danger);color:white;">
            <h2 class="card-title" style="color:white;">🔐 المستوى 1: إعادة المصادقة</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-danger">
                <strong>🚨 عملية خطرة:</strong>
                الاستعادة من نسخة احتياطية عملية خطيرة قد تؤدي لفقدان بيانات.
                يرجى إعادة إدخال كلمة المرور للتأكيد على هويتك.
            </div>

            <div style="background:rgba(23,162,184,0.1);padding:12px 16px;border-radius:var(--radius-md);margin-bottom:20px;border-right:4px solid var(--info);">
                <p style="margin:0;font-size:13px;">
                    <strong>مستويات الحماية المفعّلة:</strong><br>
                    <span class="safety-badge" style="background:var(--danger);color:white;">المستوى 1</span> إعادة مصادقة المدير ✅<br>
                    <span class="safety-badge" style="background:var(--warning);color:var(--text-primary);">المستوى 2</span> تأكيد مزدوج بالكتابة<br>
                    <span class="safety-badge" style="background:var(--info);color:white;">المستوى 3</span> نسخة احتياطية تلقائية قبل التنفيذ<br>
                    <span class="safety-badge" style="background:var(--primary);color:white;">المستوى 4</span> تسجيل كامل في سجل النشاط
                </p>
            </div>

            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label">كلمة مرور المدير <span class="required">*</span></label>
                    <input type="password" name="admin_password" class="form-control" required
                           placeholder="أدخل كلمة مرورك الحالية" autocomplete="current-password"
                           style="max-width:400px;">
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" name="reauthenticate" class="btn btn-primary btn-lg">
                        🔐 تأكيد الهوية والمتابعة
                    </button>
                    <a href="../backup_reports/index.php" class="btn btn-light btn-lg">إلغاء</a>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($step === 2): ?>
    <!-- ═══════════════════════════════════════════
         الخطوة 2: اختيار النسخة الاحتياطية
         ═══════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">📋 المستوى 2: اختيار النسخة الاحتياطية</h2>
            <span class="safety-badge" style="background:var(--success);color:white;">✅ تمت المصادقة</span>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>⚠️ تنبيه:</strong> تأكد من اختيار النسخة الصحيحة. سيتم إنشاء نسخة احتياطية تلقائياً قبل الاستعادة (المستوى 3).
            </div>

            <?php if (empty($backups)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📦</div>
                <p>لا توجد نسخ احتياطية مكتملة متاحة للاستعادة</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>الحجم</th>
                            <th>الجداول</th>
                            <th>الصفوف</th>
                            <th>أنشئ بواسطة</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup):
                            $fileSizeMB = ($backup['file_size'] ?? 0) / (1024 * 1024);
                            $isLarge = $fileSizeMB > 50;
                        ?>
                        <tr style="<?= $isLarge ? 'background:rgba(255,193,7,0.05);' : '' ?>">
                            <td><?= formatDateAr($backup['created_at'], 'full') ?></td>
                            <td>
                                <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;
                                    background:<?= $backup['backup_type'] === 'full' ? 'var(--primary)' : ($backup['backup_type'] === 'manual' ? 'var(--info)' : 'var(--warning)') ?>;
                                    color:white;">
                                    <?= $typeLabels[$backup['backup_type']] ?? $backup['backup_type'] ?>
                                </span>
                            </td>
                            <td>
                                <?= $backup['file_size'] ? ($fileSizeMB > 1 ? round($fileSizeMB, 2) . ' MB' : round($backup['file_size'] / 1024, 1) . ' KB') : '-' ?>
                                <?php if ($isLarge): ?>
                                <span style="color:var(--warning);font-size:11px;">⚠️ كبير</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $backup['tables_count'] ?? '-' ?></td>
                            <td><?= number_format($backup['rows_count'] ?? 0) ?></td>
                            <td><?= e($backup['creator_name'] ?? '-') ?></td>
                            <td>
                                <a href="restore.php?step=3&backup_id=<?= $backup['id'] ?>" class="btn btn-danger btn-sm"
                                   onclick="return confirm('هل تريد الاستعادة من هذه النسخة؟');">
                                    🔄 استعادة
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div style="margin-top:16px;">
                <a href="restore.php?step=1" class="btn btn-light">↩ رجوع</a>
            </div>
        </div>
    </div>

    <?php elseif ($step === 3): ?>
    <!-- ═══════════════════════════════════════════
         الخطوة 3: التأكيد المزدوج
         ═══════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header" style="background:var(--warning);color:var(--text-primary);">
            <h2 class="card-title">⚠️ المستوى 2: التأكيد المزدوج</h2>
        </div>
        <div class="card-body">
            <?php if ($selectedBackup): ?>
            <div class="alert alert-danger" style="font-size:15px;">
                <strong>🚨 تحذير نهائي:</strong>
                أنت على وشك استعادة البيانات من نسخة احتياطية. هذا سيستبدل البيانات الحالية بالكامل!
                <br><strong>هذه العملية لا يمكن التراجع عنها بسهولة!</strong>
            </div>

            <!-- تفاصيل النسخة المحددة -->
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <h4 style="margin-bottom:12px;">📋 تفاصيل النسخة المحددة</h4>
                    <div class="grid grid-2" style="gap:8px;font-size:14px;">
                        <div><strong>النوع:</strong> <?= $typeLabels[$selectedBackup['backup_type']] ?? $selectedBackup['backup_type'] ?></div>
                        <div><strong>التاريخ:</strong> <?= formatDateAr($selectedBackup['created_at'], 'full') ?></div>
                        <div><strong>الحجم:</strong>
                            <?php
                            $sz = $selectedBackup['file_size'] ?? 0;
                            echo $sz > 1048576 ? round($sz / 1048576, 2) . ' MB' : round($sz / 1024, 1) . ' KB';
                            ?>
                        </div>
                        <div><strong>أنشئ بواسطة:</strong> <?= e($selectedBackup['creator_name'] ?? 'غير معروف') ?></div>
                        <div><strong>عدد الجداول:</strong> <?= $selectedBackup['tables_count'] ?? '-' ?></div>
                        <div><strong>عدد الصفوف:</strong> <?= number_format($selectedBackup['rows_count'] ?? 0) ?></div>
                    </div>

                    <?php
                    $fileSizeMB = ($selectedBackup['file_size'] ?? 0) / (1024 * 1024);
                    if ($fileSizeMB > 50):
                    ?>
                    <div class="alert alert-warning" style="margin-top:12px;">
                        <strong>⚠️ تحذير:</strong> حجم النسخة كبير (<?= round($fileSizeMB, 2) ?> MB). قد تستغرق الاستعادة وقتاً طويلاً.
                    </div>
                    <?php endif; ?>

                    <?php if ($backupInfo && $backupInfo['success'] && !empty($backupInfo['info']['tables'])): ?>
                    <div style="margin-top:8px;">
                        <strong>الجداول المشمولة:</strong>
                        <div style="max-height:100px;overflow-y:auto;font-size:11px;margin-top:4px;padding:8px;background:white;border-radius:var(--radius-md);">
                            <?= implode(', ', $backupInfo['info']['tables']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- تأكيدات الحماية -->
            <div style="background:rgba(40,167,69,0.05);padding:16px;border-radius:var(--radius-md);margin-bottom:20px;border:1px solid rgba(40,167,69,0.2);">
                <h4 style="margin-bottom:8px;">🛡️ إجراءات الحماية التي ستُنفذ تلقائياً:</h4>
                <ul style="padding-right:20px;margin:0;font-size:13px;line-height:2;">
                    <li>✅ <strong>المستوى 1:</strong> تمت مصادقة هويتك بنجاح</li>
                    <li>🔄 <strong>المستوى 3:</strong> سيتم إنشاء نسخة احتياطية تلقائياً قبل التنفيذ</li>
                    <li>📝 <strong>المستوى 4:</strong> سيتم تسجيل كل تفاصيل العملية في سجل النشاط</li>
                </ul>
            </div>

            <!-- نموذج التأكيد -->
            <form method="POST" id="confirm-restore-form">
                <?= csrfField() ?>
                <input type="hidden" name="backup_id" value="<?= $selectedBackup['id'] ?>">

                <div class="form-group">
                    <label class="form-label" style="font-size:16px;font-weight:bold;">
                        اكتب <span style="color:var(--danger);font-size:20px;">"استعادة"</span> لتأكيد العملية <span class="required">*</span>
                    </label>
                    <input type="text" name="confirm_text" class="form-control" required
                           placeholder='اكتب "استعادة" هنا للتأكيد'
                           pattern="^استعادة$"
                           title='يجب كتابة كلمة "استعادة" بالضبط'
                           style="max-width:400px;font-size:18px;text-align:center;font-weight:bold;"
                           id="confirm-text-input">
                    <div class="form-text" style="color:var(--danger);">
                        يجب كتابة الكلمة بالضبط كما هي بالعربية
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:20px;">
                    <button type="submit" name="confirm_restore" value="1" class="btn btn-danger btn-lg"
                            id="confirm-btn" disabled>
                        🔄 تنفيذ الاستعادة
                    </button>
                    <a href="restore.php?step=2" class="btn btn-light btn-lg">↩ رجوع واختيار نسخة أخرى</a>
                </div>
            </form>

            <?php else: ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">
                <p>لم يتم اختيار نسخة احتياطية.</p>
                <a href="restore.php?step=2" class="btn btn-primary">اختيار نسخة</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($step === 4): ?>
    <!-- ═══════════════════════════════════════════
         الخطوة 4: عرض النتيجة
         ═══════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header" style="background:<?= $restoreResult && $restoreResult['success'] ? 'var(--success)' : 'var(--danger)' ?>;color:white;">
            <h2 class="card-title" style="color:white;">
                <?= $restoreResult && $restoreResult['success'] ? '✅ تمت الاستعادة بنجاح' : '❌ فشل الاستعادة' ?>
            </h2>
        </div>
        <div class="card-body">
            <?php if ($restoreResult): ?>
            <!-- تقرير النتيجة -->
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <h4 style="margin-bottom:12px;">📊 تقرير الاستعادة</h4>
                    <div class="grid grid-2" style="gap:12px;font-size:14px;">
                        <div>
                            <strong>حالة العملية:</strong>
                            <span style="color:<?= $restoreResult['success'] ? 'var(--success)' : 'var(--danger)' ?>;font-weight:bold;">
                                <?= $restoreResult['success'] ? '✅ ناجحة' : '❌ فاشلة' ?>
                            </span>
                        </div>
                        <div><strong>الرسالة:</strong> <?= e($restoreResult['message'] ?? '') ?></div>
                        <div><strong>عدد الاستعلامات المنفذة:</strong> <?= $restoreResult['queries_executed'] ?? 0 ?></div>
                        <div><strong>عدد الأخطاء:</strong> <?= count($restoreResult['errors'] ?? []) ?></div>
                    </div>

                    <?php if ($preRestoreBackupId): ?>
                    <div style="margin-top:12px;padding:8px 12px;background:rgba(40,167,69,0.1);border-radius:var(--radius-md);border-right:3px solid var(--success);">
                        <strong>🛡️ نسخة ما قبل الاستعادة:</strong>
                        تم إنشاء نسخة احتياطية تلقائياً برقم <strong>#<?= $preRestoreBackupId ?></strong> قبل التنفيذ.
                        يمكنك استخدامها للتراجع إذا لزم الأمر.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($restoreResult['errors'])): ?>
                    <div style="margin-top:12px;">
                        <strong style="color:var(--danger);">⚠️ الأخطاء:</strong>
                        <div style="max-height:200px;overflow-y:auto;font-size:12px;background:white;padding:8px;border-radius:var(--radius-md);margin-top:4px;">
                            <?php foreach ($restoreResult['errors'] as $err): ?>
                            <div style="margin-bottom:4px;padding:4px 0;border-bottom:1px solid var(--border-light);">
                                <code><?= e(mb_substr($err['query'] ?? '', 0, 150)) ?></code><br>
                                <span style="color:var(--danger);"><?= e($err['error'] ?? '') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- تأكيد المستويات الأربعة -->
            <div style="padding:16px;background:rgba(26,86,50,0.05);border-radius:var(--radius-md);border:1px solid rgba(26,86,50,0.1);">
                <h4 style="margin-bottom:8px;">🛡️ ملخص مستويات الحماية المنفذة:</h4>
                <ul style="padding-right:20px;margin:0;font-size:13px;line-height:2;">
                    <li>✅ <strong>المستوى 1:</strong> مصادقة المدير - تم التحقق</li>
                    <li>✅ <strong>المستوى 2:</strong> تأكيد مزدوج بالكتابة - تم التحقق</li>
                    <li>✅ <strong>المستوى 3:</strong> نسخة احتياطية تلقائية قبل التنفيذ - رقم #<?= $preRestoreBackupId ?? '-' ?></li>
                    <li>✅ <strong>المستوى 4:</strong> تسجيل في سجل النشاط - تم التسجيل</li>
                </ul>
            </div>
            <?php endif; ?>

            <div style="margin-top:20px;display:flex;gap:8px;">
                <a href="../../index.php" class="btn btn-primary btn-lg">🏠 العودة للرئيسية</a>
                <a href="../backup_reports/history.php" class="btn btn-light btn-lg">📋 سجل النسخ الاحتياطي</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// تفعيل زر التأكيد فقط عند كتابة الكلمة الصحيحة
document.addEventListener('DOMContentLoaded', function() {
    var confirmInput = document.getElementById('confirm-text-input');
    var confirmBtn = document.getElementById('confirm-btn');
    if (confirmInput && confirmBtn) {
        confirmInput.addEventListener('input', function() {
            confirmBtn.disabled = this.value.trim() !== 'استعادة';
            if (!confirmBtn.disabled) {
                confirmBtn.style.animation = 'pulse 0.5s';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
