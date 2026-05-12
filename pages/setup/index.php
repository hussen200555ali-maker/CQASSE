<?php
/**
 * ============================================================
 * EQAS - الإعدادات الهيكلية
 * نظام التقييم الشامل - Structural Settings Dashboard
 * ============================================================
 * لوحة الإعدادات الهيكلية التي تُنفذ مرة واحدة في بداية الفصل:
 * - المواد الدراسية
 * - أوزان التقييمات
 * - الفصول والشعب
 * - تعيين المعلمين
 * - إعداد الفصل الدراسي
 * - بيانات المدرسة
 * ============================================================
 */

$pageTitle = 'الإعدادات الهيكلية';
$breadcrumb = ['الإعدادات الهيكلية' => null];

require_once __DIR__ . '/../../includes/header.php';

// مدير المدرسة ومدير النظام فقط
if (!hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية الوصول إلى هذه الصفحة.', 'danger');
    redirect(baseUrl('index.php'));
}

$academicYearId = currentAcademicYearId();
$schoolId = currentSchoolId();

// ═══════════════════════════════════════════
// حساب حالة الإكمال لكل شاشة
// ═══════════════════════════════════════════

// 1. المواد الدراسية - هل تم تعيين مواد للصفوف؟
$subjectsComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM subject_grade_years WHERE is_active = 1");
    $stmt->execute();
    $subjectsComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $subjectsComplete = false; }

// 2. أوزان التقييمات - هل تم توزيع الأوزان؟
$weightsComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM subject_weight_distribution WHERE is_active = 1");
    $stmt->execute();
    $weightsComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $weightsComplete = false; }

// 3. الفصول والشعب - هل تم إنشاء فصول؟
$sectionsComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE is_active = 1 AND academic_year_id = ?");
    $stmt->execute([$academicYearId]);
    $sectionsComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $sectionsComplete = false; }

// 4. تعيين المعلمين - هل تم تعيين معلمين؟
$teachersComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE teacher_id IS NOT NULL AND is_active = 1 AND academic_year_id = ?");
    $stmt->execute([$academicYearId]);
    $teachersComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $teachersComplete = false; }

// 5. إعداد الفصل الدراسي - هل تم تحديد التواريخ؟
$semesterComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM semesters WHERE is_active = 1 AND start_date IS NOT NULL AND academic_year_id = ?");
    $stmt->execute([$academicYearId]);
    $semesterComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $semesterComplete = false; }

// 6. بيانات المدرسة - هل المدرسة بها بيانات كافية؟
$schoolComplete = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM schools WHERE is_active = 1 AND governorate IS NOT NULL AND governorate != ''");
    $stmt->execute();
    $schoolComplete = $stmt->fetchColumn() > 0;
} catch (Exception $e) { $schoolComplete = false; }

// بطاقات الإعدادات
$setupCards = [
    [
        'title' => 'إعداد المواد الدراسية',
        'desc' => 'تعيين المواد للصفوف الدراسية وتحديد الساعات ودرجة النجاح',
        'icon' => '📚',
        'url' => 'subjects.php',
        'complete' => $subjectsComplete,
        'color' => 'var(--primary)'
    ],
    [
        'title' => 'توزيع أوزان التقييمات',
        'desc' => 'ضبط أوزان أنواع التقييم لكل مادة (يجب أن يكون المجموع 100%)',
        'icon' => '⚖️',
        'url' => 'weight_setup.php',
        'complete' => $weightsComplete,
        'color' => 'var(--secondary)'
    ],
    [
        'title' => 'إعداد الفصول والشعب',
        'desc' => 'إنشاء الفصول للعام الدراسي وتحديد السعة وتوزيع الطلاب',
        'icon' => '🏫',
        'url' => 'sections.php',
        'complete' => $sectionsComplete,
        'color' => 'var(--info)'
    ],
    [
        'title' => 'تعيين المعلمين',
        'desc' => 'تعيين معلم لكل مادة وفصل ومراجعة الحمل التدريسي',
        'icon' => '👨‍🏫',
        'url' => 'teachers_assign.php',
        'complete' => $teachersComplete,
        'color' => 'var(--success)'
    ],
    [
        'title' => 'إعداد الفصل الدراسي',
        'desc' => 'تحديد تواريخ الفصول الدراسية وفترات التقييم والإعدادات',
        'icon' => '📅',
        'url' => 'semester_setup.php',
        'complete' => $semesterComplete,
        'color' => 'var(--warning)'
    ],
    [
        'title' => 'بيانات المدرسة',
        'desc' => 'إدخال بيانات المدرسة الأساسية: المحافظة، المديرية، النوع، التواصل',
        'icon' => '🏛️',
        'url' => 'school_info.php',
        'complete' => $schoolComplete,
        'color' => 'var(--accent)'
    ],
];

// نسبة الإكمال الكلية
$totalCards = count($setupCards);
$completedCards = count(array_filter($setupCards, function($c) { return $c['complete']; }));
$completionPercent = $totalCards > 0 ? round(($completedCards / $totalCards) * 100) : 0;
?>

<div class="section">
    <!-- ملاحظة توضيحية -->
    <div class="card mb-6">
        <div class="card-body">
            <div style="display:flex;align-items:flex-start;gap:16px;">
                <div style="font-size:40px;">⚙️</div>
                <div>
                    <h3 style="margin-bottom:4px;">الإعدادات الهيكلية</h3>
                    <p style="color:var(--text-secondary);margin:0;">
                        هذه الشاشات تُنفذ <strong>مرة واحدة</strong> في بداية الفصل الدراسي.
                        يُرجى إكمال جميع الخطوات بالترتيب لضمان عمل النظام بشكل صحيح.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- شريط الإكمال الكلي -->
    <div class="card mb-6">
        <div class="card-body" style="padding:16px 24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <strong>التقدم الكلي</strong>
                <span style="font-size:14px;color:var(--primary);font-weight:bold;"><?= $completionPercent ?>%</span>
            </div>
            <div style="width:100%;height:10px;background:var(--border-light);border-radius:var(--radius-full);">
                <div style="width:<?= $completionPercent ?>%;height:100%;background:<?= $completionPercent === 100 ? 'var(--success)' : 'var(--primary)' ?>;border-radius:var(--radius-full);transition:width 0.5s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--text-muted);">
                <span><?= $completedCards ?> من <?= $totalCards ?> مكتمل</span>
                <span><?= $totalCards - $completedCards ?> متبقي</span>
            </div>
        </div>
    </div>

    <!-- بطاقات الإعدادات -->
    <div class="grid grid-3" style="gap:20px;">
        <?php foreach ($setupCards as $index => $card): ?>
        <a href="<?= $card['url'] ?>" style="text-decoration:none;color:inherit;">
            <div class="card" style="height:100%;border-top:4px solid <?= $card['color'] ?>;transition:all var(--transition-base);">
                <div class="card-body" style="padding:20px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <div style="font-size:36px;"><?= $card['icon'] ?></div>
                        <?php if ($card['complete']): ?>
                        <span style="background:var(--success);color:white;padding:2px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;">
                            ✅ مكتمل
                        </span>
                        <?php else: ?>
                        <span style="background:var(--border-light);color:var(--text-secondary);padding:2px 10px;border-radius:var(--radius-full);font-size:11px;">
                            ⏳ غير مكتمل
                        </span>
                        <?php endif; ?>
                    </div>
                    <h4 style="font-size:16px;margin-bottom:6px;"><?= $card['title'] ?></h4>
                    <p style="font-size:13px;color:var(--text-secondary);margin:0;line-height:1.6;"><?= $card['desc'] ?></p>
                    <div style="margin-top:12px;font-size:12px;color:<?= $card['color'] ?>;font-weight:600;">
                        الخطوة <?= $index + 1 ?> ←
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
