<?php
/**
 * ============================================================
 * EQAS - خطة تحسين المدرسة
 * نظام التقييم الشامل - School Improvement Plan
 * ============================================================
 * تُفعّل عندما يكون المناخ المدرسي أو المرافق < 55%
 * نموذج: نقاط الضعف، الأهداف، الاستراتيجيات، الجدول الزمني
 * تتبع الحالة (نشطة، مكتملة، ملغاة)
 * ============================================================
 */

$pageTitle = 'خطة تحسين المدرسة';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'خطة التحسين' => null];

require_once __DIR__ . '/../../includes/header.php';

// الصلاحيات: school_admin + group_admin
$userRole = $_SESSION['user']['role'] ?? '';
if (!hasPermission('school_admin') && !hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

// ═══════════════════════════════════════════
// تحديد المدرسة حسب الدور
// ═══════════════════════════════════════════
if ($userRole === 'group_admin') {
    $schoolId = intval($_GET['school_id'] ?? 0);

    // ───────────────────────────────────────
    // عرض صفحة اختيار المدارس لـ group_admin
    // ───────────────────────────────────────
    if ($schoolId === 0) {
        // جلب كل المدارس مع إحصائيات الخطط والدرجات
        $schools = $db->query("
            SELECT s.id, s.name_ar,
                   (SELECT COUNT(*) FROM school_improvement_plans WHERE school_id = s.id AND status = 'active' AND is_active = 1) as active_plans,
                   (SELECT COUNT(*) FROM school_improvement_plans WHERE school_id = s.id AND is_active = 1) as total_plans
            FROM schools s WHERE s.is_active = 1 ORDER BY s.name_ar
        ")->fetchAll();

        // جلب درجات المناخ والمرافق لكل مدرسة
        $schoolScores = [];
        foreach ($schools as $sch) {
            $cs = null; $fs = null;
            try {
                $s1 = $db->prepare("SELECT AVG(cs.overall_score) as avg FROM surveys cs WHERE cs.survey_type = 'climate' AND cs.school_id = ? AND cs.is_active = 1");
                $s1->execute([$sch['id']]);
                $r1 = $s1->fetch();
                if ($r1 && $r1['avg'] !== null) $cs = round($r1['avg'], 1);

                $s2 = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1");
                $s2->execute([$sch['id']]);
                $r2 = $s2->fetch();
                if ($r2 && $r2['avg'] !== null) $fs = round($r2['avg'], 1);
            } catch (Exception $e) {}
            $schoolScores[$sch['id']] = ['climate' => $cs, 'facility' => $fs];
        }

        $pageTitle = 'اختر مدرسة — خطط التحسين';
        $breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'خطط التحسين المدرسية' => null];
        ?>

        <div class="section">
            <div class="card mb-6">
                <div class="card-body">
                    <div style="display:flex;align-items:flex-start;gap:16px;">
                        <div style="font-size:40px;">📈</div>
                        <div>
                            <h3 style="margin-bottom:4px;">خطط التحسين المدرسية</h3>
                            <p style="color:var(--text-secondary);margin:0;">
                                اختر مدرسة لعرض أو إنشاء خطط التحسين. تُفعّل الخطط تلقائياً عندما يكون المناخ أو المرافق أقل من 55%.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-auto" style="gap:16px;">
                <?php foreach ($schools as $sch):
                    $cs = $schoolScores[$sch['id']]['climate'];
                    $fs = $schoolScores[$sch['id']]['facility'];
                    $needsPlan = ($cs !== null && $cs < 55) || ($fs !== null && $fs < 55);
                    $hasActive = $sch['active_plans'] > 0;

                    if ($hasActive) {
                        $cardColor = 'var(--warning)';
                        $cardBg = '#fffbeb';
                        $badge = '<span style="background:var(--warning);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">📋 ' . $sch['active_plans'] . ' نشطة</span>';
                        $btnText = 'عرض الخطط';
                        $btnColor = 'var(--warning)';
                    } elseif ($needsPlan) {
                        $cardColor = 'var(--danger)';
                        $cardBg = '#fff5f5';
                        $reasons = [];
                        if ($cs !== null && $cs < 55) $reasons[] = 'مناخ ' . $cs . '%';
                        if ($fs !== null && $fs < 55) $reasons[] = 'مرافق ' . $fs . '%';
                        $badge = '<span style="background:var(--danger);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">🚨 مطلوب (' . implode('، ', $reasons) . ')</span>';
                        $btnText = 'إنشاء خطة';
                        $btnColor = 'var(--danger)';
                    } else {
                        $cardColor = 'var(--success)';
                        $cardBg = '#f0fff4';
                        $badge = '<span style="background:var(--success);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">✅ لا تحتاج</span>';
                        $btnText = 'عرض';
                        $btnColor = 'var(--success)';
                    }
                ?>
                <div style="border:2px solid <?= $cardColor ?>;border-radius:var(--radius-lg);background:<?= $cardBg ?>;padding:20px;transition:all var(--transition-base);">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <h4 style="font-size:16px;margin:0;">🏫 <?= e($sch['name_ar']) ?></h4>
                        <?= $badge ?>
                    </div>

                    <div style="display:flex;gap:12px;font-size:13px;margin-bottom:16px;">
                        <?php if ($cs !== null): ?>
                        <span style="color:<?= $cs >= 55 ? 'var(--success)' : 'var(--danger)' ?>;">🌡️ <?= $cs ?>%</span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">🌡️ —</span>
                        <?php endif; ?>
                        <?php if ($fs !== null): ?>
                        <span style="color:<?= $fs >= 55 ? 'var(--success)' : 'var(--danger)' ?>;">🏗️ <?= $fs ?>%</span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">🏗️ —</span>
                        <?php endif; ?>
                        <?php if ($sch['total_plans'] > 0): ?>
                        <span style="color:var(--text-muted);">📊 <?= $sch['total_plans'] ?> خطة</span>
                        <?php endif; ?>
                    </div>

                    <a href="improvement_plan.php?school_id=<?= $sch['id'] ?>" class="btn btn-sm" style="background:<?= $btnColor ?>;color:white;width:100%;text-align:center;text-decoration:none;"><?= $btnText ?></a>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($schools)): ?>
            <div class="card">
                <div class="card-body" style="text-align:center;padding:40px;">
                    <div style="font-size:48px;margin-bottom:12px;">🏫</div>
                    <h4>لا توجد مدارس نشطة</h4>
                    <p style="color:var(--text-muted);">يرجى إضافة مدرسة أولاً من صفحة إدارة المدارس.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php
        require_once __DIR__ . '/../../includes/footer.php';
        exit;
    }
} else {
    $schoolId = currentSchoolId();
}

// جلب بيانات المدرسة
$schoolStmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = ? AND is_active = 1");
$schoolStmt->execute([$schoolId]);
$currentSchool = $schoolStmt->fetch();
if (!$currentSchool) {
    setFlash('المدرسة غير موجودة أو غير نشطة.', 'danger');
    redirect(baseUrl('pages/supervision/index.php'));
}

// جلب قائمة المدارس (لمدير المجموعة)
$allSchools = [];
if ($userRole === 'group_admin') {
    $allSchools = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
}

// إنشاء الجدول إذا لم يكن موجوداً (للتوافق مع قواعد البيانات القديمة)
$db->exec("CREATE TABLE IF NOT EXISTS school_improvement_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    trigger_type ENUM('climate','facility','both') DEFAULT 'both',
    weaknesses TEXT,
    goals TEXT,
    strategies TEXT,
    timeline VARCHAR(200),
    responsible VARCHAR(200),
    status ENUM('active','completed','cancelled') DEFAULT 'active',
    progress INT DEFAULT 0,
    created_by INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق.', 'danger');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_plan') {
            $triggerType = $_POST['trigger_type'] ?? 'both';
            $weaknesses = trim($_POST['weaknesses'] ?? '');
            $goals = trim($_POST['goals'] ?? '');
            $strategies = trim($_POST['strategies'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $responsibleUserId = intval($_POST['responsible_user_id'] ?? 0);
            if ($responsibleUserId === 0) $responsibleUserId = null;

            if (empty($weaknesses) || empty($goals) || empty($strategies)) {
                setFlash('يرجى ملء الحقول المطلوبة (نقاط الضعف، الأهداف، الاستراتيجيات).', 'warning');
            } else {
                $stmt = $db->prepare("INSERT INTO school_improvement_plans (school_id, trigger_type, weaknesses, goals, strategies, timeline, responsible_user_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$schoolId, $triggerType, $weaknesses, $goals, $strategies, $timeline, $responsibleUserId, $_SESSION['user']['id']]);
                logActivity($db, $_SESSION['user']['id'], 'improvement_plan_create', "إنشاء خطة تحسين المدرسة", 'school_improvement_plans', $db->lastInsertId());
                setFlash('تم إنشاء خطة التحسين بنجاح.', 'success');
            }

        } elseif ($action === 'update_progress') {
            $id = (int)$_POST['plan_id'];
            $progress = (int)$_POST['progress'];
            $status = $progress >= 100 ? 'completed' : 'active';
            $stmt = $db->prepare("UPDATE school_improvement_plans SET progress = ?, status = ? WHERE id = ?");
            $stmt->execute([$progress, $status, $id]);
            logActivity($db, $_SESSION['user']['id'], 'improvement_plan_progress', "تحديث تقدم خطة رقم {$id} إلى {$progress}%", 'school_improvement_plans', $id);
            setFlash('تم تحديث التقدم.', 'success');

        } elseif ($action === 'cancel_plan') {
            $id = (int)$_POST['plan_id'];
            $stmt = $db->prepare("UPDATE school_improvement_plans SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم إلغاء الخطة.', 'warning');

        } elseif ($action === 'complete_plan') {
            $id = (int)$_POST['plan_id'];
            $stmt = $db->prepare("UPDATE school_improvement_plans SET status = 'completed', progress = 100 WHERE id = ?");
            $stmt->execute([$id]);
            logActivity($db, $_SESSION['user']['id'], 'improvement_plan_complete', "إكمال خطة تحسين رقم {$id}", 'school_improvement_plans', $id);
            setFlash('تم إكمال الخطة بنجاح! 🎉', 'success');
        }

        $redirectUrl = 'improvement_plan.php';
        if ($userRole === 'group_admin') {
            $redirectUrl .= '?school_id=' . $schoolId;
        }
        redirect($redirectUrl);
    }
}

// جلب الخطط
$stmt = $db->prepare("
    SELECT sip.*, u.full_name_ar AS responsible_name
    FROM school_improvement_plans sip
    LEFT JOIN users u ON sip.responsible_user_id = u.id
    WHERE sip.school_id = ? AND sip.is_active = 1
    ORDER BY sip.status ASC, sip.created_at DESC
");
$stmt->execute([$schoolId]);
$plans = $stmt->fetchAll();

// جلب المستخدمين المسؤولين
$usersStmt = $db->query("SELECT id, full_name_ar, role_name FROM users WHERE is_active = 1 ORDER BY full_name_ar");
$availableUsers = $usersStmt->fetchAll();

// هل تحتاج خطة؟
$climateScore = null;
$facilityScore = null;
try {
    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg'] !== null) $climateScore = round($r['avg'], 1);

    $stmt = $db->prepare("SELECT AVG(ar.numeric_value) as avg FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]);
    $r = $stmt->fetch();
    if ($r && $r['avg'] !== null) $facilityScore = round($r['avg'], 1);
} catch (Exception $e) { }

$needsPlan = ($climateScore !== null && $climateScore < 55) || ($facilityScore !== null && $facilityScore < 55);
$hasActivePlan = count(array_filter($plans, function($p) { return $p['status'] === 'active'; })) > 0;

$statusLabels = ['active' => 'نشطة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];
$statusColors = ['active' => 'var(--warning)', 'completed' => 'var(--success)', 'cancelled' => 'var(--text-muted)'];
$triggerLabels = ['climate' => 'مناخ مدرسي', 'facility' => 'مرافق', 'both' => 'مناخ + مرافق'];
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h2 class="card-title">📈 خطة تحسين المدرسة — <?= e($currentSchool['name_ar']) ?></h2>
            <div style="display:flex;gap:8px;align-items:center;">
                <?php if ($userRole === 'group_admin'): ?>
                <form method="GET" style="display:flex;gap:4px;align-items:center;">
                    <select name="school_id" class="form-control" style="max-width:200px;font-size:13px;" onchange="this.form.submit();">
                        <?php foreach ($allSchools as $as): ?>
                        <option value="<?= $as['id'] ?>" <?= $as['id'] == $schoolId ? 'selected' : '' ?>><?= e($as['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <?php if ($needsPlan && !$hasActivePlan): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('create-form').style.display='block'">➕ إنشاء خطة تحسين</button>
                <?php endif; ?>
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()">🖨️ طباعة</button>
            </div>
        </div>
        <div class="card-body">
            <?php if ($needsPlan && !$hasActivePlan): ?>
            <div class="alert alert-danger">
                <strong>🚨 مطلوب خطة تحسين!</strong>
                <?php if ($climateScore !== null && $climateScore < 55): ?>
                درجة المناخ المدرسي: <strong><?= $climateScore ?>%</strong> (أقل من 55%).
                <?php endif; ?>
                <?php if ($facilityScore !== null && $facilityScore < 55): ?>
                درجة المرافق: <strong><?= $facilityScore ?>%</strong> (أقل من 55%).
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- نموذج الإنشاء -->
            <div id="create-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--danger);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_plan">
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">سبب التفعيل</label>
                                <select name="trigger_type" class="form-control" style="max-width:200px;">
                                    <?php foreach ($triggerLabels as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">نقاط الضعف <span class="required">*</span></label>
                                <textarea name="weaknesses" class="form-control" rows="3" placeholder="صف نقاط الضعف المكتشفة..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">الأهداف <span class="required">*</span></label>
                                <textarea name="goals" class="form-control" rows="3" placeholder="ما هي الأهداف المراد تحقيقها؟" required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">الاستراتيجيات <span class="required">*</span></label>
                                <textarea name="strategies" class="form-control" rows="4" placeholder="ما الخطوات والاستراتيجيات المتبعة؟" required></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">الجدول الزمني</label>
                                    <input type="text" name="timeline" class="form-control" placeholder="مثال: خلال 3 أشهر">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">المسؤول عن التنفيذ</label>
                                    <select name="responsible_user_id" class="form-control">
                                        <option value="">-- اختر المسؤول --</option>
                                        <?php foreach ($availableUsers as $au): ?>
                                        <option value="<?= $au['id'] ?>"><?= e($au['full_name_ar']) ?> (<?= $au['role_name'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-danger">💾 إنشاء الخطة</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('create-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- قائمة الخطط -->
            <?php if (empty($plans)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">📈</div>
                <p>لا توجد خطط تحسين حالياً.</p>
            </div>
            <?php else: ?>
            <?php foreach ($plans as $plan):
                $progressColor = $plan['progress'] >= 80 ? 'var(--success)' : ($plan['progress'] >= 40 ? 'var(--warning)' : 'var(--danger)');
            ?>
            <div class="card mb-4" style="border-right:4px solid <?= $statusColors[$plan['status']] ?>;">
                <div class="card-header" style="background:var(--background);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h4 style="margin:0;">
                            📈 خطة تحسين - <?= $triggerLabels[$plan['trigger_type']] ?? $plan['trigger_type'] ?>
                            <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:<?= $statusColors[$plan['status']] ?>;color:white;">
                                <?= $statusLabels[$plan['status']] ?>
                            </span>
                        </h4>
                        <span style="font-size:12px;color:var(--text-muted);"><?= formatDateAr($plan['created_at'], 'short') ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-2" style="gap:16px;font-size:14px;">
                        <div>
                            <strong style="color:var(--danger);">🔻 نقاط الضعف:</strong>
                            <p style="margin-top:4px;color:var(--text-secondary);"><?= nl2br(e($plan['weaknesses'])) ?></p>
                        </div>
                        <div>
                            <strong style="color:var(--success);">🎯 الأهداف:</strong>
                            <p style="margin-top:4px;color:var(--text-secondary);"><?= nl2br(e($plan['goals'])) ?></p>
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <strong style="color:var(--info);">📋 الاستراتيجيات:</strong>
                        <p style="margin-top:4px;color:var(--text-secondary);"><?= nl2br(e($plan['strategies'])) ?></p>
                    </div>

                    <?php if ($plan['timeline'] || $plan['responsible_name']): ?>
                    <div style="margin-top:8px;font-size:13px;color:var(--text-muted);">
                        <?php if ($plan['timeline']): ?>⏰ <?= e($plan['timeline']) ?><?php endif; ?>
                        <?php if ($plan['responsible_name']): ?> | 👤 <?= e($plan['responsible_name']) ?><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- شريط التقدم -->
                    <div style="margin-top:16px;">
                        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                            <span>التقدم</span>
                            <strong style="color:<?= $progressColor ?>;"><?= $plan['progress'] ?>%</strong>
                        </div>
                        <div style="width:100%;height:8px;background:var(--border-light);border-radius:var(--radius-full);">
                            <div style="width:<?= $plan['progress'] ?>%;height:100%;background:<?= $progressColor ?>;border-radius:var(--radius-full);transition:width 0.5s;"></div>
                        </div>
                    </div>

                    <?php if ($plan['status'] === 'active'): ?>
                    <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
                        <form method="POST" style="display:flex;gap:4px;align-items:center;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_progress">
                            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                            <input type="number" name="progress" class="form-control" value="<?= $plan['progress'] ?>" min="0" max="100" style="width:70px;text-align:center;">
                            <button type="submit" class="btn btn-primary btn-sm">تحديث</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('إكمال الخطة؟');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="complete_plan">
                            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm">✅ إكمال</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('إلغاء الخطة؟');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="cancel_plan">
                            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">❌ إلغاء</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>

<style>
@media print {
    .sidebar, .topbar, .btn, form, .card-footer, #create-form { display: none !important; }
    .section { margin: 0; padding: 0; }
    .card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
    .card-header { background: #f5f5f5 !important; }
}
</style>
