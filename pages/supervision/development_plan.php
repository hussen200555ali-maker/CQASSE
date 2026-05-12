<?php
/**
 * ============================================================
 * EQAS - خطة تطوير المعلم
 * نظام التقييم الشامل - Teacher Development Plan
 * ============================================================
 * تُفعّل عندما يكون combined_score للمعلم < 60%
 * نقاط الضعف، الأهداف، الاستراتيجيات
 * تواريخ المتابعة، تتبع التقدم
 * ============================================================
 */

$pageTitle = 'خطة تطوير المعلم';
$breadcrumb = ['التقييمات الإشرافية' => 'index.php', 'خطة تطوير المعلم' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();

// إنشاء الجدول إذا لم يكن موجوداً
$db->exec("CREATE TABLE IF NOT EXISTS teacher_development_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    weaknesses TEXT,
    goals TEXT,
    strategies TEXT,
    follow_up_date DATE,
    follow_up_notes TEXT,
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
            $teacherId = (int)$_POST['teacher_id'];
            $weaknesses = trim($_POST['weaknesses'] ?? '');
            $goals = trim($_POST['goals'] ?? '');
            $strategies = trim($_POST['strategies'] ?? '');
            $followUpDate = $_POST['follow_up_date'] ?? null;

            if (empty($teacherId) || empty($weaknesses) || empty($goals) || empty($strategies)) {
                setFlash('يرجى ملء جميع الحقول المطلوبة.', 'warning');
            } else {
                $stmt = $db->prepare("INSERT INTO teacher_development_plans (teacher_id, school_id, weaknesses, goals, strategies, follow_up_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$teacherId, $schoolId, $weaknesses, $goals, $strategies, $followUpDate ?: null, $_SESSION['user']['id']]);
                logActivity($db, $_SESSION['user']['id'], 'dev_plan_create', "إنشاء خطة تطوير للمعلم رقم {$teacherId}", 'teacher_development_plans', $db->lastInsertId());
                setFlash('تم إنشاء خطة التطوير بنجاح.', 'success');
            }

        } elseif ($action === 'update_progress') {
            $id = (int)$_POST['plan_id'];
            $progress = (int)$_POST['progress'];
            $followUpNotes = trim($_POST['follow_up_notes'] ?? '');
            $status = $progress >= 100 ? 'completed' : 'active';
            $stmt = $db->prepare("UPDATE teacher_development_plans SET progress = ?, follow_up_notes = ?, status = ? WHERE id = ?");
            $stmt->execute([$progress, $followUpNotes, $status, $id]);
            logActivity($db, $_SESSION['user']['id'], 'dev_plan_progress', "تحديث تقدم خطة تطوير رقم {$id} إلى {$progress}%", 'teacher_development_plans', $id);
            setFlash('تم تحديث التقدم.', 'success');

        } elseif ($action === 'complete_plan') {
            $id = (int)$_POST['plan_id'];
            $stmt = $db->prepare("UPDATE teacher_development_plans SET status = 'completed', progress = 100 WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم إكمال خطة التطوير! 🎉', 'success');

        } elseif ($action === 'cancel_plan') {
            $id = (int)$_POST['plan_id'];
            $stmt = $db->prepare("UPDATE teacher_development_plans SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('تم إلغاء الخطة.', 'warning');
        }

        redirect('development_plan.php');
    }
}

// جلب المعلمين
$teachers = $db->query("
    SELECT u.id, u.full_name_ar
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'teacher' AND u.is_active = 1 AND u.school_id = {$schoolId}
    ORDER BY u.full_name_ar
")->fetchAll();

// جلب الخطط
$stmt = $db->prepare("
    SELECT tdp.*, u.full_name_ar as teacher_name
    FROM teacher_development_plans tdp
    JOIN users u ON tdp.teacher_id = u.id
    WHERE tdp.school_id = ? AND tdp.is_active = 1
    ORDER BY tdp.status ASC, tdp.created_at DESC
");
$stmt->execute([$schoolId]);
$plans = $stmt->fetchAll();

// المعلمون الذين يحتاجون خطط تطوير
$teachersNeedingPlan = [];
foreach ($teachers as $teacher) {
    $hasActivePlan = false;
    foreach ($plans as $p) {
        if ($p['teacher_id'] == $teacher['id'] && $p['status'] === 'active') {
            $hasActivePlan = true;
            break;
        }
    }

    // جلب الدرجة المُجمّعة
    $combinedScore = null;
    try {
        $stmt = $db->prepare("
            SELECT AVG(ar.numeric_value) as avg_score
            FROM assessment_responses ar
            JOIN assessment_forms af ON ar.form_id = af.id
            WHERE af.form_key IN ('teaching_self','teaching_peer','teaching_admin')
            AND ar.teacher_id = ? AND ar.school_id = ? AND ar.is_active = 1
        ");
        $stmt->execute([$teacher['id'], $schoolId]);
        $row = $stmt->fetch();
        if ($row && $row['avg_score'] !== null) {
            $combinedScore = round($row['avg_score'], 1);
        }
    } catch (Exception $e) { }

    if ($combinedScore !== null && $combinedScore < 60 && !$hasActivePlan) {
        $teachersNeedingPlan[] = ['id' => $teacher['id'], 'name' => $teacher['full_name_ar'], 'score' => $combinedScore];
    }
}

$statusLabels = ['active' => 'نشطة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];
$statusColors = ['active' => 'var(--warning)', 'completed' => 'var(--success)', 'cancelled' => 'var(--text-muted)'];
?>

<div class="section">
    <div class="card mb-6">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 class="card-title">🎯 خطة تطوير المعلم</h2>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-form').style.display='block'">➕ إنشاء خطة جديدة</button>
        </div>
        <div class="card-body">
            <?php if (!empty($teachersNeedingPlan)): ?>
            <div class="alert alert-warning">
                <strong>⚠️ معلمون يحتاجون خطط تطوير:</strong>
                <ul style="padding-right:20px;margin:8px 0 0 0;">
                    <?php foreach ($teachersNeedingPlan as $t): ?>
                    <li><strong><?= e($t['name']) ?></strong> - الدرجة: <span style="color:var(--danger);"><?= $t['score'] ?>%</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- نموذج الإنشاء -->
            <div id="create-form" style="display:none;margin-bottom:20px;">
                <div class="card" style="border:2px solid var(--warning);">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_plan">
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">المعلم <span class="required">*</span></label>
                                <select name="teacher_id" class="form-control" required style="max-width:400px;">
                                    <option value="">اختر المعلم</option>
                                    <?php foreach ($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= e($t['full_name_ar']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">نقاط الضعف <span class="required">*</span></label>
                                <textarea name="weaknesses" class="form-control" rows="3" placeholder="وصف نقاط الضعف في أداء المعلم..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">الأهداف <span class="required">*</span></label>
                                <textarea name="goals" class="form-control" rows="3" placeholder="الأهداف المراد تحقيقها..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">الاستراتيجيات <span class="required">*</span></label>
                                <textarea name="strategies" class="form-control" rows="4" placeholder="الخطوات والاستراتيجيات المقترحة..." required></textarea>
                            </div>
                            <div class="form-group" style="max-width:250px;">
                                <label class="form-label">تاريخ المتابعة</label>
                                <input type="date" name="follow_up_date" class="form-control">
                            </div>
                        </div>
                        <div class="card-footer" style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-warning">💾 إنشاء الخطة</button>
                            <button type="button" class="btn btn-light" onclick="document.getElementById('create-form').style.display='none'">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- قائمة الخطط -->
            <?php if (empty($plans)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <div style="font-size:48px;margin-bottom:12px;">🎯</div>
                <p>لا توجد خطط تطوير حالياً.</p>
            </div>
            <?php else: ?>
            <?php foreach ($plans as $plan):
                $progressColor = $plan['progress'] >= 80 ? 'var(--success)' : ($plan['progress'] >= 40 ? 'var(--warning)' : 'var(--danger)');
            ?>
            <div class="card mb-4" style="border-right:4px solid <?= $statusColors[$plan['status']] ?>;">
                <div class="card-header" style="background:var(--background);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h4 style="margin:0;">
                            👨‍🏫 <?= e($plan['teacher_name']) ?>
                            <span style="padding:2px 8px;border-radius:var(--radius-full);font-size:11px;background:<?= $statusColors[$plan['status']] ?>;color:white;">
                                <?= $statusLabels[$plan['status']] ?>
                            </span>
                        </h4>
                        <span style="font-size:12px;color:var(--text-muted);">أنشئ: <?= formatDateAr($plan['created_at'], 'short') ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-3" style="gap:16px;font-size:14px;">
                        <div>
                            <strong style="color:var(--danger);">🔻 نقاط الضعف:</strong>
                            <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($plan['weaknesses'])) ?></p>
                        </div>
                        <div>
                            <strong style="color:var(--success);">🎯 الأهداف:</strong>
                            <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($plan['goals'])) ?></p>
                        </div>
                        <div>
                            <strong style="color:var(--info);">📋 الاستراتيجيات:</strong>
                            <p style="margin-top:4px;color:var(--text-secondary);font-size:13px;"><?= nl2br(e($plan['strategies'])) ?></p>
                        </div>
                    </div>

                    <?php if ($plan['follow_up_date']): ?>
                    <div style="margin-top:8px;font-size:13px;color:var(--text-muted);">
                        📅 موعد المتابعة: <strong><?= formatDateAr($plan['follow_up_date'], 'medium') ?></strong>
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
                    <form method="POST" style="margin-top:12px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_progress">
                        <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <div class="form-group" style="margin:0;max-width:80px;">
                                <input type="number" name="progress" class="form-control" value="<?= $plan['progress'] ?>" min="0" max="100" style="text-align:center;">
                            </div>
                            <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                                <input type="text" name="follow_up_notes" class="form-control" placeholder="ملاحظات المتابعة..." value="<?= e($plan['follow_up_notes'] ?? '') ?>">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">تحديث</button>
                            <button type="submit" name="action" value="complete_plan" class="btn btn-success btn-sm" onclick="this.form.elements['action'].value='complete_plan';return confirm('إكمال الخطة؟');">✅ إكمال</button>
                            <button type="submit" name="action" value="cancel_plan" class="btn btn-danger btn-sm" onclick="this.form.elements['action'].value='cancel_plan';return confirm('إلغاء الخطة؟');">❌ إلغاء</button>
                        </div>
                    </form>
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
