<?php
/**
 * ============================================================
 * EQAS - التقييمات الإشرافية
 * نظام التقييم الشامل - Supervisory Evaluation Dashboard
 * ============================================================
 * لوحة التقييمات الإشرافية التي تُنفذ كل فصل دراسي:
 * - استبيان المناخ المدرسي
 * - تقرير المرافق
 * - تقييم أداء المعلمين
 * - خطة تحسين المدرسة
 * - خطة تطوير المعلم
 * ============================================================
 */

$pageTitle = 'التقييمات الإشرافية';
$breadcrumb = ['التقييمات الإشرافية' => null];

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول.', 'danger');
    redirect(baseUrl('index.php'));
}

$schoolId = currentSchoolId();
$academicYearId = currentAcademicYearId();

// ═══════════════════════════════════════════
// حساب حالة الإكمال لكل تقييم
// ═══════════════════════════════════════════

// 1. استبيان المناخ المدرسي
$climateStatus = ['completed' => false, 'score' => null, 'count' => 0];
try {
    $stmt = $db->prepare("
        SELECT ar.school_id, AVG(ar.numeric_value) as avg_score
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1
        GROUP BY ar.school_id
    ");
    $stmt->execute([$schoolId]);
    $climateRow = $stmt->fetch();
    if ($climateRow && $climateRow['avg_score'] !== null) {
        $climateStatus['completed'] = true;
        $climateStatus['score'] = round($climateRow['avg_score'], 1);
    }
    $stmt = $db->prepare("SELECT COUNT(DISTINCT responded_by) FROM assessment_responses ar JOIN assessment_forms af ON ar.form_id = af.id WHERE af.form_key = 'climate_survey' AND ar.school_id = ? AND ar.is_active = 1");
    $stmt->execute([$schoolId]);
    $climateStatus['count'] = $stmt->fetchColumn();
} catch (Exception $e) { }

// 2. تقرير المرافق
$facilityStatus = ['completed' => false, 'score' => null];
try {
    $stmt = $db->prepare("
        SELECT AVG(ar.numeric_value) as avg_score
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE af.form_key = 'facility_checklist' AND ar.school_id = ? AND ar.is_active = 1
    ");
    $stmt->execute([$schoolId]);
    $facilityRow = $stmt->fetch();
    if ($facilityRow && $facilityRow['avg_score'] !== null) {
        $facilityStatus['completed'] = true;
        $facilityStatus['score'] = round($facilityRow['avg_score'], 1);
    }
} catch (Exception $e) { }

// 3. تقييم أداء المعلمين
$teachingStatus = ['completed' => false, 'score' => null];
try {
    $stmt = $db->prepare("
        SELECT AVG(ar.numeric_value) as avg_score
        FROM assessment_responses ar
        JOIN assessment_forms af ON ar.form_id = af.id
        WHERE af.form_key IN ('teaching_self', 'teaching_peer', 'teaching_admin')
        AND ar.school_id = ? AND ar.is_active = 1
    ");
    $stmt->execute([$schoolId]);
    $teachingRow = $stmt->fetch();
    if ($teachingRow && $teachingRow['avg_score'] !== null) {
        $teachingStatus['completed'] = true;
        $teachingStatus['score'] = round($teachingRow['avg_score'], 1);
    }
} catch (Exception $e) { }

// 4. خطة تحسين المدرسة - هل توجد خطة نشطة؟
$improvementActive = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS school_improvement_plans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_id INT UNSIGNED NOT NULL,
        weaknesses TEXT, goals TEXT, strategies TEXT, timeline TEXT,
        status ENUM('active','completed','cancelled') DEFAULT 'active',
        created_by INT UNSIGNED, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $db->prepare("SELECT COUNT(*) FROM school_improvement_plans WHERE school_id = ? AND status = 'active' AND is_active = 1");
    $stmt->execute([$schoolId]);
    $improvementActive = $stmt->fetchColumn() > 0;
} catch (Exception $e) { }

// 5. خطة تطوير المعلم - هل توجد خطة؟
$developmentActive = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS teacher_development_plans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        school_id INT UNSIGNED NOT NULL,
        weaknesses TEXT, goals TEXT, strategies TEXT,
        follow_up_date DATE, status ENUM('active','completed','cancelled') DEFAULT 'active',
        created_by INT UNSIGNED, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $db->prepare("SELECT COUNT(*) FROM teacher_development_plans WHERE school_id = ? AND status = 'active' AND is_active = 1");
    $stmt->execute([$schoolId]);
    $developmentActive = $stmt->fetchColumn() > 0;
} catch (Exception $e) { }

// هل يحتاج لخطة تحسين؟
$needsImprovement = ($climateStatus['score'] !== null && $climateStatus['score'] < 55) ||
                    ($facilityStatus['score'] !== null && $facilityStatus['score'] < 55);
$needsDevelopment = $teachingStatus['score'] !== null && $teachingStatus['score'] < 60;

// بطاقات التقييم
$evalCards = [
    [
        'title' => 'استبيان المناخ المدرسي',
        'desc' => 'قياس رضا الطلاب والمعلمين وأولياء الأمور عن البيئة المدرسية',
        'icon' => '🌡️',
        'url' => 'climate_survey.php',
        'completed' => $climateStatus['completed'],
        'score' => $climateStatus['score'],
        'color' => 'var(--info)',
        'trigger' => $climateStatus['score'] !== null && $climateStatus['score'] < 55
    ],
    [
        'title' => 'تقرير المرافق المدرسية',
        'desc' => 'تقييم شامل لحالة المرافق: فصول، مختبرات، مكتبة، ساحة، دورات مياه',
        'icon' => '🏗️',
        'url' => 'facility_report.php',
        'completed' => $facilityStatus['completed'],
        'score' => $facilityStatus['score'],
        'color' => 'var(--success)',
        'trigger' => $facilityStatus['score'] !== null && $facilityStatus['score'] < 55
    ],
    [
        'title' => 'تقييم أداء المعلمين',
        'desc' => 'تقييم شامل: ذاتي 20% + أقران 30% + إدارة 50%',
        'icon' => '👨‍🏫',
        'url' => 'teaching_school.php',
        'completed' => $teachingStatus['completed'],
        'score' => $teachingStatus['score'],
        'color' => 'var(--secondary)',
        'trigger' => $teachingStatus['score'] !== null && $teachingStatus['score'] < 60
    ],
    [
        'title' => 'خطة تحسين المدرسة',
        'desc' => 'تُفعّل عندما يكون المناخ أو المرافق أقل من 55%',
        'icon' => '📈',
        'url' => 'improvement_plan.php',
        'completed' => $improvementActive,
        'score' => null,
        'color' => 'var(--warning)',
        'trigger' => false,
        'needed' => $needsImprovement
    ],
    [
        'title' => 'خطة تطوير المعلم',
        'desc' => 'تُفعّل عندما يكون تقييم المعلم أقل من 60%',
        'icon' => '🎯',
        'url' => 'development_plan.php',
        'completed' => $developmentActive,
        'score' => null,
        'color' => 'var(--accent)',
        'trigger' => false,
        'needed' => $needsDevelopment
    ],
];
?>

<div class="section">
    <!-- وصف -->
    <div class="card mb-6">
        <div class="card-body">
            <div style="display:flex;align-items:flex-start;gap:16px;">
                <div style="font-size:40px;">📋</div>
                <div>
                    <h3 style="margin-bottom:4px;">التقييمات الإشرافية</h3>
                    <p style="color:var(--text-secondary);margin:0;">
                        التقييمات التي تُنفذ <strong>كل فصل دراسي</strong>. يُرجى إكمالها بالترتيب لضمان حساب مؤشر جودة التعليم بدقة.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- تنبيهات -->
    <?php if ($needsImprovement): ?>
    <div class="alert alert-danger" style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:24px;">🚨</span>
        <div>
            <strong>يحتاج خطة تحسين!</strong>
            درجة المناخ المدرسي أو المرافق أقل من 55%. يُرجى إنشاء <a href="improvement_plan.php" style="font-weight:bold;">خطة تحسين المدرسة</a>.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($needsDevelopment): ?>
    <div class="alert alert-warning" style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:24px;">⚠️</span>
        <div>
            <strong>يحتاج خطة تطوير!</strong>
            تقييم المعلمين أقل من 60%. يُرجى إنشاء <a href="development_plan.php" style="font-weight:bold;">خطة تطوير المعلم</a>.
        </div>
    </div>
    <?php endif; ?>

    <!-- بطاقات التقييم -->
    <div class="grid grid-auto" style="gap:20px;">
        <?php foreach ($evalCards as $card): ?>
        <a href="<?= $card['url'] ?>" style="text-decoration:none;color:inherit;">
            <div class="card" style="height:100%;border-top:4px solid <?= $card['color'] ?>;transition:all var(--transition-base);">
                <div class="card-body" style="padding:20px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <div style="font-size:36px;"><?= $card['icon'] ?></div>
                        <?php if ($card['completed']): ?>
                        <span style="background:var(--success);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">
                            ✅ مكتمل
                        </span>
                        <?php elseif (isset($card['needed']) && $card['needed']): ?>
                        <span style="background:var(--danger);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">
                            🚨 مطلوب
                        </span>
                        <?php else: ?>
                        <span style="background:var(--border-light);color:var(--text-secondary);padding:2px 10px;border-radius:var(--radius-full);font-size:11px;">
                            ⏳ بانتظار
                        </span>
                        <?php endif; ?>
                    </div>
                    <h4 style="font-size:16px;margin-bottom:6px;"><?= $card['title'] ?></h4>
                    <p style="font-size:13px;color:var(--text-secondary);margin:0;line-height:1.6;"><?= $card['desc'] ?></p>

                    <?php if ($card['score'] !== null): ?>
                    <div style="margin-top:12px;">
                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                            <span>الدرجة</span>
                            <strong style="color:<?= $card['trigger'] ? 'var(--danger)' : 'var(--success)' ?>;"><?= $card['score'] ?>%</strong>
                        </div>
                        <div style="width:100%;height:6px;background:var(--border-light);border-radius:var(--radius-full);">
                            <div style="width:<?= min(100, $card['score']) ?>%;height:100%;background:<?= $card['trigger'] ? 'var(--danger)' : 'var(--success)' ?>;border-radius:var(--radius-full);"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($card['needed']) && $card['needed'] && !$card['completed']): ?>
                    <div style="margin-top:8px;font-size:12px;color:var(--danger);font-weight:600;">
                        ⚠️ مطلوب إنشاء خطة ←
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- نظرة عامة على مؤشر جودة التعليم -->
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">📊 نظرة عامة على مؤشر جودة التعليم (EQI)</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-3" style="gap:16px;">
                <div class="stat-card" style="border-top:3px solid var(--info);">
                    <div class="stat-icon">🌡️</div>
                    <div class="stat-value" style="color:var(--info);">
                        <?= $climateStatus['score'] !== null ? $climateStatus['score'] . '%' : '-' ?>
                    </div>
                    <div class="stat-label">المناخ المدرسي</div>
                </div>
                <div class="stat-card" style="border-top:3px solid var(--success);">
                    <div class="stat-icon">🏗️</div>
                    <div class="stat-value" style="color:var(--success);">
                        <?= $facilityStatus['score'] !== null ? $facilityStatus['score'] . '%' : '-' ?>
                    </div>
                    <div class="stat-label">المرافق</div>
                </div>
                <div class="stat-card" style="border-top:3px solid var(--secondary);">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-value" style="color:var(--secondary);">
                        <?= $teachingStatus['score'] !== null ? $teachingStatus['score'] . '%' : '-' ?>
                    </div>
                    <div class="stat-label">أداء المعلمين</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
