<?php
/**
 * ============================================================
 * تقييم سلوكي جديد - New Behavior Evaluation
 * Behavior Evaluation Form
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

$pageTitle = 'تقييم سلوكي جديد';
$breadcrumb = ['التقييمات' => '../assessment/index.php', 'التقييم السلوكي' => 'index.php', 'تقييم جديد' => null];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/filters.php';

if (!hasPermission('teacher')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

require_once __DIR__ . '/../../includes/assessment_engine.php';
$engine = new AssessmentEngine($db);

$userId = $_SESSION['user']['id'];
$userRole = $_SESSION['user']['role'];
$schoolId = $_SESSION['user']['school_id'] ?? null;

// جلب الفصول — للمعلم: فقط فصوله المسندة إليه
$classes = [];
try {
    if ($userRole === 'teacher' && $schoolId) {
        $academicYearId = $_SESSION['academic_year_id'] ?? getCurrentAcademicYearId($db);
        $sql = "SELECT DISTINCT c.id, c.name_ar, gl.name_ar as grade_name
                FROM classes c
                JOIN grade_levels gl ON c.grade_level_id = gl.id
                JOIN subject_teachers st ON st.classroom_id = c.id AND st.teacher_id = ? AND st.is_active = 1
                WHERE c.is_active = 1 AND c.school_id = ?
                UNION
                SELECT DISTINCT c.id, c.name_ar, gl.name_ar as grade_name
                FROM classes c
                JOIN grade_levels gl ON c.grade_level_id = gl.id
                WHERE c.teacher_id = ? AND c.is_active = 1 AND c.school_id = ? AND c.academic_year_id = ?
                ORDER BY grade_name, name_ar";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $schoolId, $userId, $schoolId, $academicYearId]);
    } else {
        $sql = "SELECT c.id, c.name_ar, gl.name_ar as grade_name FROM classes c JOIN grade_levels gl ON c.grade_level_id = gl.id WHERE c.is_active = 1";
        $params = [];
        if ($schoolId && $userRole !== 'group_admin') {
            $sql .= " AND c.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY gl.level_number, c.name_ar";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    $classes = $stmt->fetchAll();
} catch (Exception $e) {
    $classes = [];
}

// جلب السنوات الدراسية
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

// جلب نموذج التقييم السلوكي
$behaviorForm = null;
$behaviorItems = [];
try {
    $stmt = $db->prepare("SELECT id FROM assessment_forms WHERE form_key = 'behavior_eval' AND is_active = 1 ORDER BY is_default DESC, created_at DESC LIMIT 1"); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
    $stmt->execute();
    $formRow = $stmt->fetch();
    if ($formRow) {
        $behaviorForm = $engine->getFormWithItems($formRow['id']);
        if ($behaviorForm) {
            foreach ($behaviorForm['categories'] as $cat) {
                foreach ($cat['items'] as $item) {
                    $behaviorItems[] = $item;
                }
            }
        }
    }
} catch (Exception $e) {}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('خطأ في التحقق من الأمان', 'danger');
    } else {
        try {
            $db->beginTransaction();
            
            $studentId = (int)($_POST['student_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $semesterVal = (int)($_POST['semester_id'] ?? 0);
            $semesterId = 0;
            if ($semesterVal && $academicYearId) {
                $semName = semesterValueToNameAr($semesterVal);
                if ($semName) {
                    $sStmt = $db->prepare("SELECT id FROM semesters WHERE name_ar = ? AND academic_year_id = ? AND is_active = 1 LIMIT 1");
                    $sStmt->execute([$semName, $academicYearId]);
                    $semesterId = (int)$sStmt->fetchColumn();
                }
            }
            // التحقق الأمني: المعلم يملك صلاحية لهذا الفصل
            if ($userRole === 'teacher') {
                $allowedIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
                if (!in_array($classId, $allowedIds)) {
                    throw new Exception('ليس لديك صلاحية لتقييم سلوك في هذا الفصل');
                }
            }
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$studentId || !$classId || !$academicYearId || !$semesterId) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
            // حساب الدرجة الكلية
            $responses = [];
            $itemScores = [];
            foreach ($behaviorItems as $item) {
                $fieldName = 'item_' . $item['id'];
                $rawValue = $_POST[$fieldName] ?? null;
                
                if ($rawValue !== null && $rawValue !== '') {
                    $validation = $engine->validateTool($item['tool_type'], $rawValue, $item['max_value']);
                    if ($validation['valid']) {
                        $normalized = $engine->normalizeTool($item['tool_type'], $validation['value'], $item['max_value'], $item['is_inverse']);
                        $responses[] = [
                            'weight' => $item['weight'],
                            'normalized' => $normalized
                        ];
                        $itemScores[$item['id']] = [
                            'raw' => $validation['value'],
                            'normalized' => $normalized
                        ];
                    }
                }
            }
            
            $overallScore = $engine->calculateScore($responses);
            
            // حفظ التقييم الرئيسي
            $stmt = $db->prepare("
                INSERT INTO evaluations 
                (eval_type, student_id, class_id, school_id, academic_year_id, semester_id, 
                 evaluated_by, combined_score, current_percentage, is_active, created_at, updated_at)
                VALUES ('behavior_score', ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $studentId, $classId, $schoolId, $academicYearId, $semesterId,
                $userId, $overallScore, $overallScore
            ]);
            $evaluationId = $db->lastInsertId();
            
            // حفظ عناصر التقييم في assessment_responses
            $formId = $formRow['id'];
            $stmt = $db->prepare("
                INSERT INTO assessment_responses 
                (form_id, item_id, student_id, school_id, class_id, academic_year_id, semester_id, numeric_value, responded_by, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            foreach ($itemScores as $itemId => $scores) {
                $stmt->execute([
                    $formId,
                    $itemId,
                    $studentId,
                    $schoolId,
                    $classId,
                    $academicYearId,
                    $semesterId,
                    $scores['normalized'],
                    $userId
                ]);
            }
            
            $db->commit();
            setFlash('تم حفظ التقييم السلوكي بنجاح - الدرجة الكلية: ' . number_format($overallScore, 1) . '%', 'success');
            redirect('view.php?id=' . $evaluationId);
            
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('خطأ: ' . $e->getMessage(), 'danger');
        }
    }
}
?>

<!-- نموذج التقييم السلوكي -->
<div class="section">
    <form method="POST" data-validate>
        <?= csrfField() ?>
        
        <!-- معلومات أساسية -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">🎯 معلومات التقييم السلوكي</h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">الطالب <span class="required">*</span></label>
                        <select name="student_id" class="form-control" required id="student-select">
                            <option value="">-- اختر الطالب --</option>
                        </select>
                        <span class="form-text">اختر الفصل أولاً لعرض الطلاب</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل <span class="required">*</span></label>
                        <select name="class_id" class="form-control" required id="class-select">
                            <option value="">-- اختر الفصل --</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>"><?php echo e($cls['grade_name'] . ' - ' . $cls['name_ar']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">السنة الدراسية <span class="required">*</span></label>
                        <select name="academic_year_id" class="form-control" required>
                            <option value="">-- اختر السنة --</option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>"><?php echo e($ay['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي <span class="required">*</span></label>
                        <select name="semester_id" class="form-control" required>
                            <option value="">-- اختر الفصل الدراسي --</option>
                            <option value="1">الفصل الدراسي الأول</option>
                            <option value="2">الفصل الدراسي الثاني</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- عناصر التقييم -->
        <?php if (!empty($behaviorForm) && !empty($behaviorItems)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📋 عناصر التقييم السلوكي</h3>
                <div id="overall-score-display" style="font-size:18px; font-weight:700; color:var(--primary);">
                    الدرجة الكلية: <span id="live-score">0</span>%
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($behaviorForm['categories'] as $cat): ?>
                <div class="mb-4">
                    <h4 style="color:var(--primary); border-bottom:2px solid var(--primary); padding-bottom:4px; margin-bottom:12px;">
                        <?php echo e($cat['name_ar']); ?>
                    </h4>
                    <?php foreach ($cat['items'] as $item): ?>
                        <?php echo $engine->renderTool($item, null, 'item_'); ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body" style="text-align:center; padding:40px;">
                <div style="font-size:48px; margin-bottom:12px;">⚠️</div>
                <h4>لا يوجد نموذج تقييم سلوكي</h4>
                <p style="color:var(--text-secondary);">يرجى إنشاء نموذج تقييم سلوكي من صفحة إدارة النماذج أولاً</p>
                <a href="../assessment/add_form.php?form_type=behavior_eval" class="btn btn-primary mt-3">إنشاء نموذج</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ملاحظات -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">📝 ملاحظات</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="notes" class="form-control" rows="3" placeholder="أضف ملاحظاتك هنا..."></textarea>
                </div>
            </div>
        </div>

        <!-- أزرار الحفظ -->
        <div class="flex justify-end gap-3">
            <a href="index.php" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary">💾 حفظ التقييم</button>
        </div>
    </form>
</div>

<script>
// تحميل الطلاب بناءً على الفصل المختار
document.getElementById('class-select').addEventListener('change', function() {
    const classId = this.value;
    const studentSelect = document.getElementById('student-select');
    studentSelect.innerHTML = '<option value="">-- جاري التحميل... --</option>';
    
    if (!classId) {
        studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
        return;
    }
    
    // طلب AJAX لجلب الطلاب (باستخدام نقطة بيانات مبسطة)
    fetch(`../../api/get_students.php?class_id=${classId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
        if (data.students) {
            data.students.forEach(s => {
                studentSelect.innerHTML += `<option value="${s.id}">${s.full_name_ar} (${s.student_number})</option>`;
            });
        }
    })
    .catch(() => {
        studentSelect.innerHTML = '<option value="">-- اختر الطالب --</option>';
    });
});

// حساب الدرجة الكلية تلقائياً
document.addEventListener('change', function(e) {
    if (e.target.closest('.assessment-item')) {
        calculateLiveScore();
    }
});

function calculateLiveScore() {
    // حساب مبسط - يحسب متوسط القيم المختارة
    const items = document.querySelectorAll('.assessment-item');
    let totalWeight = 0;
    let weightedSum = 0;
    
    items.forEach(item => {
        const input = item.querySelector('input[type="radio"]:checked, input[type="number"]');
        const toolType = item.dataset.tool;
        
        if (input) {
            const value = input.type === 'radio' ? parseFloat(input.value) : parseFloat(input.value || 0);
            const weight = parseFloat(input.dataset.weight || 1);
            let normalized = 0;
            
            switch (toolType) {
                case 'likert_5': case 'frequency_5': case 'satisfaction_5':
                    normalized = (value / 5) * 100; break;
                case 'yes_no':
                    normalized = value * 100; break;
                case 'severity_4':
                    normalized = ((value - 1) / 3) * 100; break;
                case 'rubric_4':
                    normalized = (value / 4) * 100; break;
                case 'numeric_100':
                    normalized = value; break;
                default:
                    normalized = (value / 5) * 100;
            }
            
            weightedSum += weight * normalized;
            totalWeight += weight;
        }
    });
    
    const score = totalWeight > 0 ? Math.round((weightedSum / totalWeight) * 10) / 10 : 0;
    document.getElementById('live-score').textContent = score;
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
