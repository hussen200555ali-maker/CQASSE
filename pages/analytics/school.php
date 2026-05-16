<?php
/**
 * ============================================================
 * تحليل أداء المدرسة
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'تحليل أداء المدرسة';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => 'index.php',
    'تحليل أداء المدرسة' => null
];
$pageCSS = 'analytics.css';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/contextual_adjustment.php';
require_once __DIR__ . '/../../includes/filters.php';

// التحقق من الصلاحيات - فقط مدير المجموعة ومدير المدرسة
if (!hasPermission('group_admin') && !hasPermission('school_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}

// school_admin يرى بيانات مدرسته تلقائياً
// group_admin يحدد المدرسة من الرابط
if ($_SESSION['user']['role'] === 'school_admin') {
    $schoolId = (int)($_SESSION['user']['school_id'] ?? 0);
} elseif ($_SESSION['user']['role'] === 'group_admin') {
    $schoolId = intval($_GET['school_id'] ?? 0);
} else {
    $schoolId = 0;
}

// جلب قائمة المدارس (نحتاجها في كلتا الحالتين)
$allSchools = $db->query("SELECT id, name_ar, reference_code, governorate, school_type FROM schools WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// SC-06: مدرسة واحدة فقط — عرض مباشر بدون منتقي
if (hasPermission('group_admin') && !$schoolId && count($allSchools) === 1) {
    $schoolId = (int)$allSchools[0]['id'];
}

// school_admin يتجاوز منتقي المدرسة مباشرة
if ($_SESSION['user']['role'] === 'school_admin' && $schoolId > 0) {
    // تخطي منتقي المدرسة - الانتقال مباشرة للبيانات
} elseif (!$schoolId) {
    // عرض منتقي المدرسة
    $pageTitle = 'اختر مدرسة للتحليل';
    
    // تجميع المدارس حسب المحافظة
    $schoolsByGovernorate = [];
    $govCounts = [];
    $typeCounts = ['basic' => 0, 'secondary' => 0, 'combined' => 0];
    foreach ($allSchools as $s) {
        $gov = $s['governorate'] ?: 'غير محدد';
        $schoolsByGovernorate[$gov][] = $s;
        $govCounts[$gov] = ($govCounts[$gov] ?? 0) + 1;
        if (isset($typeCounts[$s['school_type']])) $typeCounts[$s['school_type']]++;
    }
    ksort($schoolsByGovernorate);
    $schoolTypeLabels = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
    $schoolTypeIcons = ['basic' => '🏫', 'secondary' => '🏛️', 'combined' => '🏬'];
    ?>
    <div class="container mx-auto px-4 py-8">
        <div class="page-hero" style="margin-bottom:2rem;">
            <h1>اختر مدرسة لتحليل أدائها</h1>
            <div class="picker-hero-sub">
                <span>اختر مدرسة من القائمة أدناه لعرض تحليل شامل</span>
                <span class="hero-stat">📚 <?= count($allSchools) ?> مدرسة</span>
                <span class="hero-stat">📍 <?= count($schoolsByGovernorate) ?> محافظة</span>
            </div>
        </div>
        <div class="school-picker" id="schoolPickerApp">
            <!-- Search & Filters -->
            <div class="picker-toolbar">
                <div class="picker-search" id="pickerSearch">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="schoolSearchInput" placeholder="ابحث باسم المدرسة أو الرمز..." autocomplete="off">
                    <button class="search-clear" id="searchClearBtn" onclick="clearPickerSearch()">✕</button>
                </div>
                <div class="picker-filters">
                    <span class="filter-label">النوع:</span>
                    <span class="pill-btn active" data-filter="all" onclick="setPickerFilter(this, 'all')">الكل <span class="pill-count"><?= count($allSchools) ?></span></span>
                    <span class="pill-btn" data-filter="basic" onclick="setPickerFilter(this, 'basic')">🏫 أساسية <span class="pill-count"><?= $typeCounts['basic'] ?></span></span>
                    <span class="pill-btn" data-filter="secondary" onclick="setPickerFilter(this, 'secondary')">🏛️ ثانوية <span class="pill-count"><?= $typeCounts['secondary'] ?></span></span>
                    <span class="pill-btn" data-filter="combined" onclick="setPickerFilter(this, 'combined')">🏬 مختلطة <span class="pill-count"><?= $typeCounts['combined'] ?></span></span>
                </div>
                <div class="picker-stats-bar" id="pickerStatsBar">
                    <span class="stat-item">عرض <strong id="visibleCount"><?= count($allSchools) ?></strong> مدرسة</span>
                    <span class="stat-dot"></span>
                    <span class="stat-item"><strong id="visibleGovCount"><?= count($schoolsByGovernorate) ?></strong> محافظة</span>
                </div>
            </div>

            <!-- Schools Grid -->
            <div class="grid-cards" id="schoolGrid">
                <?php $cardIndex = 0; ?>
                <?php foreach ($schoolsByGovernorate as $governorate => $govSchools): ?>
                <div class="gov-header" data-gov="<?= e($governorate) ?>">
                    <span class="gov-icon">📍</span>
                    <span class="gov-name"><?= e($governorate) ?></span>
                    <span class="gov-count"><?= count($govSchools) ?></span>
                </div>
                <?php foreach ($govSchools as $s): 
                    $iconClass = 'card-icon-' . $s['school_type'];
                    $badgeClass = 'school-badge-' . $s['school_type'];
                    $typeLabel = $schoolTypeLabels[$s['school_type']] ?? $s['school_type'];
                    $typeIcon = $schoolTypeIcons[$s['school_type']] ?? '🏫';
                ?>
                <a href="?school_id=<?= $s['id'] ?>" class="pick-card anim-in" 
                   data-type="<?= e($s['school_type']) ?>" 
                   data-gov="<?= e($governorate) ?>"
                   data-name="<?= e($s['name_ar']) ?>"
                   data-code="<?= e($s['reference_code']) ?>">
                    <div class="card-icon-wrap">
                        <span class="card-icon <?= $iconClass ?>"><?= $typeIcon ?></span>
                        <div style="flex:1;min-width:0;">
                            <h3><?= e($s['name_ar']) ?></h3>
                            <div class="meta">
                                <span>🆔 <?= e($s['reference_code']) ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="school-badge <?= $badgeClass ?>"><?= $typeLabel ?></span>
                </a>
                <?php $cardIndex++; endforeach; ?>
                <?php endforeach; ?>
                <!-- Empty state (hidden by default) -->
                <div class="picker-empty-state" id="pickerEmptyState" style="display:none;">
                    <span class="empty-icon">🔍</span>
                    <div style="font-size:1rem;font-weight:600;color:var(--text-secondary);">لا توجد نتائج</div>
                    <div style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;">حاول تعديل البحث أو تغيير فلتر النوع</div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var searchInput = document.getElementById('schoolSearchInput');
        var searchWrap = document.getElementById('pickerSearch');
        var clearBtn = document.getElementById('searchClearBtn');
        var emptyState = document.getElementById('pickerEmptyState');
        var visibleCount = document.getElementById('visibleCount');
        var visibleGovCount = document.getElementById('visibleGovCount');
        var activeFilter = 'all';
        var searchQuery = '';

        function updatePicker() {
            var cards = document.querySelectorAll('#schoolGrid .pick-card');
            var govHeaders = document.querySelectorAll('#schoolGrid .gov-header');
            var visible = 0;
            var visibleGovs = new Set();

            cards.forEach(function(c) {
                var name = (c.getAttribute('data-name') || '').toLowerCase();
                var code = (c.getAttribute('data-code') || '').toLowerCase();
                var type = c.getAttribute('data-type') || '';
                var gov = c.getAttribute('data-gov') || '';

                var matchSearch = !searchQuery || name.indexOf(searchQuery) !== -1 || code.indexOf(searchQuery) !== -1;
                var matchFilter = activeFilter === 'all' || type === activeFilter;

                if (matchSearch && matchFilter) {
                    c.classList.remove('hidden-card');
                    visible++;
                    visibleGovs.add(gov);
                } else {
                    c.classList.add('hidden-card');
                }
            });

            govHeaders.forEach(function(h) {
                var gov = h.getAttribute('data-gov') || '';
                h.classList.toggle('hidden-header', !visibleGovs.has(gov));
            });

            emptyState.style.display = visible === 0 ? '' : 'none';
            visibleCount.textContent = visible;
            visibleGovCount.textContent = visibleGovs.size;

            // Update active count on pills
            document.querySelectorAll('.pill-btn').forEach(function(p) {
                var filter = p.getAttribute('data-filter');
                var count = 0;
                if (filter === 'all') {
                    cards.forEach(function(c) {
                        var name = (c.getAttribute('data-name') || '').toLowerCase();
                        var code = (c.getAttribute('data-code') || '').toLowerCase();
                        if (!searchQuery || name.indexOf(searchQuery) !== -1 || code.indexOf(searchQuery) !== -1) count++;
                    });
                } else {
                    cards.forEach(function(c) {
                        if (c.getAttribute('data-type') !== filter) return;
                        var name = (c.getAttribute('data-name') || '').toLowerCase();
                        var code = (c.getAttribute('data-code') || '').toLowerCase();
                        if (!searchQuery || name.indexOf(searchQuery) !== -1 || code.indexOf(searchQuery) !== -1) count++;
                    });
                }
                var badge = p.querySelector('.pill-count');
                if (badge) badge.textContent = count;
            });
        }

        searchInput.addEventListener('input', function() {
            searchQuery = this.value.trim().toLowerCase();
            searchWrap.classList.toggle('active', searchQuery.length > 0);
            updatePicker();
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { clearPickerSearch(); }
        });

        window.clearPickerSearch = function() {
            searchInput.value = '';
            searchQuery = '';
            searchWrap.classList.remove('active');
            updatePicker();
            searchInput.focus();
        };

        window.setPickerFilter = function(el, filter) {
            document.querySelectorAll('.pill-btn').forEach(function(b) { b.classList.remove('active'); });
            el.classList.add('active');
            activeFilter = filter;
            updatePicker();
        };
    })();
    </script>
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    <?php exit; ?>
<?php } ?>
<?php
// جلب بيانات المدرسة
$stmt = $db->prepare("SELECT s.* FROM schools s WHERE s.id = ? AND s.is_active = 1");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('المدرسة غير موجودة أو غير نشطة', 'danger');
    redirect('../group_admin/index.php');
}

// ===== معالجة الفلاتر =====
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$selectedSemesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
if (!in_array($selectedSemesterId, [0, 1, 2])) {
    $selectedSemesterId = 0;
}
// Fetch academic years
$allAcademicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 AND status != 'planning' ORDER BY start_date DESC");
    $allAcademicYears = $stmt->fetchAll();
} catch (Exception $e) {}
$validAyIds = array_column($allAcademicYears, 'id');
if ($selectedAcademicYearId && !in_array($selectedAcademicYearId, $validAyIds)) {
    $selectedAcademicYearId = (int)($allAcademicYears[0]['id'] ?? 0);
}
$selectedSemesterName = $selectedSemesterId > 0 ? semesterValueToNameAr($selectedSemesterId) : '';

// ===== استعلامات البيانات =====

// 1. معلومات المدرسة العامة
$studentCountStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
$studentCountStmt->execute([$schoolId]);
$studentCount = $studentCountStmt->fetchColumn();

$teacherCountStmt = $db->prepare("
    SELECT COUNT(*) FROM users 
    WHERE school_id = ? AND role_id = (SELECT id FROM roles WHERE name = 'teacher') AND is_active = 1
");
$teacherCountStmt->execute([$schoolId]);
$teacherCount = $teacherCountStmt->fetchColumn();

// 2. EQI للمدرسة في الفصل المحدد (أو جميع الفصول/السنوات)
$eqiYearFilter = buildYearFilter($selectedAcademicYearId, 'eqi');
$eqiSchoolFilter = buildSchoolFilter($schoolId, 'eqi');
if ($selectedSemesterId > 0) {
    $eqiSemFilter = buildSemesterSubquery($selectedSemesterId, 'eqi');
    $avgEQIStmt = $db->prepare("
        SELECT AVG(eqi.overall_eqi) AS overall_eqi FROM eqi_results eqi
        WHERE eqi.is_active = 1{$eqiSchoolFilter['sql']}{$eqiYearFilter['sql']}{$eqiSemFilter['sql']}
    ");
    $avgEQIStmt->execute(array_merge($eqiSchoolFilter['params'], $eqiYearFilter['params'], $eqiSemFilter['params']));
} elseif ($selectedAcademicYearId > 0) {
    $avgEQIStmt = $db->prepare("
        SELECT AVG(eqi.overall_eqi) AS overall_eqi FROM eqi_results eqi
        WHERE eqi.is_active = 1{$eqiSchoolFilter['sql']}{$eqiYearFilter['sql']}
    ");
    $avgEQIStmt->execute(array_merge($eqiSchoolFilter['params'], $eqiYearFilter['params']));
} else {
    $avgEQIStmt = $db->prepare("
        SELECT AVG(eqi.overall_eqi) AS overall_eqi FROM eqi_results eqi
        WHERE eqi.is_active = 1{$eqiSchoolFilter['sql']}
    ");
    $avgEQIStmt->execute($eqiSchoolFilter['params']);
}
$avgEQIResult = $avgEQIStmt->fetch();
$avgEQI = $avgEQIResult['overall_eqi'] ?? 0;

// 2b. التعديل السياقي
$adjuster = new ContextualAdjustmentService($db);
$adjResult = $adjuster->calculateAdjustedSQIWithDetails($schoolId, $selectedAcademicYearId, $avgEQI);
$adjustedEQI = $adjResult['adjusted_sqi'];
$challengeIndex = $adjResult['challenge_index'];
$hasContext = $adjResult['has_context'];

// 2c. الاتجاه السنوي (مقارنة بنفس الفصل من العام السابق)
$annualTrend = ['direction' => '→', 'class' => 'trend-neutral', 'text' => 'لا توجد بيانات سابقة', 'diff' => 0];
if ($selectedSemesterId > 0 && $selectedAcademicYearId > 0) {
    // الحصول على اسم الفصل الحالي
    $semName = semesterValueToNameAr($selectedSemesterId);
    
    // البحث عن نفس اسم الفصل في العام السابق
    $prevYearStmt = $db->prepare("SELECT id FROM academic_years WHERE end_date < (SELECT start_date FROM academic_years WHERE id = ?) ORDER BY end_date DESC LIMIT 1");
    $prevYearStmt->execute([$selectedAcademicYearId]);
    $prevAcademicYearId = $prevYearStmt->fetchColumn();
    
    if ($prevAcademicYearId) {
        $prevSemStmt = $db->prepare("SELECT id FROM semesters WHERE academic_year_id = ? AND name_ar = ? AND is_active = 1 LIMIT 1");
        $prevSemStmt->execute([$prevAcademicYearId, $semName]);
        $prevSemesterId = $prevSemStmt->fetchColumn();
        
        if ($prevSemesterId) {
            $prevEQIStmt = $db->prepare("SELECT overall_eqi FROM eqi_results WHERE school_id = ? AND semester_id = ? AND is_active = 1");
            $prevEQIStmt->execute([$schoolId, $prevSemesterId]);
            $prevEQI = $prevEQIStmt->fetchColumn();
            
            if ($prevEQI !== false && $prevEQI !== null) {
                $diff = $avgEQI - $prevEQI;
                if ($diff > 1) {
                    $annualTrend = ['direction' => '▲', 'class' => 'trend-up', 'text' => '+' . number_format($diff, 1), 'diff' => $diff];
                } elseif ($diff < -1) {
                    $annualTrend = ['direction' => '▼', 'class' => 'trend-down', 'text' => number_format($diff, 1), 'diff' => $diff];
                } else {
                    $annualTrend = ['direction' => '→', 'class' => 'trend-neutral', 'text' => 'مستقر', 'diff' => $diff];
                }
            }
        }
    }
}

// 3. اتجاه EQI عبر الفصول (آخر 6 فصول متاحة)
$trendExtraSql = ''; $trendExtraParams = [];
if ($selectedAcademicYearId > 0) {
    $trendExtraSql .= " AND eqi.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)";
    $trendExtraParams[] = $selectedAcademicYearId;
}
if ($selectedSemesterId > 0) {
    $semTrendName = semesterValueToNameAr($selectedSemesterId);
    $trendExtraSql .= " AND eqi.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
    $trendExtraParams[] = $semTrendName;
}
$eqiTrendStmt = $db->prepare("
    SELECT s.name_ar AS semester_name, eqi.overall_eqi, s.start_date
    FROM eqi_results eqi
    JOIN semesters s ON eqi.semester_id = s.id
    WHERE eqi.school_id = ? AND eqi.is_active = 1 AND eqi.overall_eqi IS NOT NULL{$trendExtraSql}
    ORDER BY s.start_date DESC
    LIMIT 6
");
$eqiTrendStmt->execute(array_merge([$schoolId], $trendExtraParams));
$eqiTrendData = $eqiTrendStmt->fetchAll();

// 4. توزيع الطلاب حسب الصف
$gradeDistributionStmt = $db->prepare("
    SELECT gl.name_ar as grade_name, COUNT(*) as student_count
    FROM students st
    JOIN grade_levels gl ON st.grade_level_id = gl.id
    WHERE st.school_id = ? AND st.is_active = 1
    GROUP BY gl.id, gl.name_ar
    ORDER BY gl.level_number
");
$gradeDistributionStmt->execute([$schoolId]);
$gradeDistribution = $gradeDistributionStmt->fetchAll();

// 5. أداء الشعب في الفصل المحدد (أو جميع الفصول/السنوات)
$classExtraSql = ''; $classExtraParams = [];
if ($selectedAcademicYearId > 0) {
    $classExtraSql .= " AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)";
    $classExtraParams[] = $selectedAcademicYearId;
}
if ($selectedSemesterId > 0) {
    $semCpName = semesterValueToNameAr($selectedSemesterId);
    $classExtraSql .= " AND sss.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
    $classExtraParams[] = $semCpName;
}
$classPerformanceStmt = $db->prepare("
    SELECT c.id, c.name_ar as class_name, gl.name_ar as grade_name, 
           AVG(sss.percentage) as avg_percentage, COUNT(DISTINCT sss.student_id) as student_count
    FROM student_subject_summary sss
    JOIN classes c ON sss.class_id = c.id
    JOIN grade_levels gl ON c.grade_level_id = gl.id
    WHERE c.school_id = ? AND sss.is_active = 1{$classExtraSql}
    GROUP BY c.id, c.name_ar, gl.name_ar
    ORDER BY gl.level_number, c.name_ar
");
$classPerformanceStmt->execute(array_merge([$schoolId], $classExtraParams));
$classPerformance = $classPerformanceStmt->fetchAll();

// 6. أداء المواد في الفصل المحدد (أو جميع الفصول/السنوات)
$subjExtraSql = ''; $subjExtraParams = [];
if ($selectedAcademicYearId > 0) {
    $subjExtraSql .= " AND sss.semester_id IN (SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1)";
    $subjExtraParams[] = $selectedAcademicYearId;
}
if ($selectedSemesterId > 0) {
    $semSubjName = semesterValueToNameAr($selectedSemesterId);
    $subjExtraSql .= " AND sss.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)";
    $subjExtraParams[] = $semSubjName;
}
$subjectPerformanceStmt = $db->prepare("
    SELECT sub.id, sub.name_ar as subject_name, 
           AVG(sss.percentage) as avg_percentage, COUNT(DISTINCT sss.student_id) as student_count
    FROM student_subject_summary sss
    JOIN subjects sub ON sss.subject_id = sub.id
    JOIN classes c ON sss.class_id = c.id
    WHERE c.school_id = ? AND sss.is_active = 1{$subjExtraSql}
    GROUP BY sub.id, sub.name_ar
    ORDER BY avg_percentage DESC
");
$subjectPerformanceStmt->execute(array_merge([$schoolId], $subjExtraParams));
$subjectPerformance = $subjectPerformanceStmt->fetchAll();

// 7. قائمة الشعب للمدرسة
$classesStmt = $db->prepare("
    SELECT c.id, c.name_ar as class_name, gl.name_ar as grade_name, 
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.class_id = c.id AND se.is_active = 1) as enrolled_count
    FROM classes c
    JOIN grade_levels gl ON c.grade_level_id = gl.id
    WHERE c.school_id = ? AND c.is_active = 1
    ORDER BY gl.level_number, c.name_ar
");
$classesStmt->execute([$schoolId]);
$classes = $classesStmt->fetchAll();

// 8. قائمة المواد للمدرسة (مرشحة حسب العام الدراسي)
$subjectsStmt = $db->prepare("
    SELECT DISTINCT sub.id, sub.name_ar as subject_name
    FROM subjects sub
    JOIN student_subject_summary sss ON sub.id = sss.subject_id
    JOIN classes c ON sss.class_id = c.id
    WHERE c.school_id = ? AND sss.is_active = 1
    ORDER BY sub.name_ar
");
$subjectsStmt->execute([$schoolId]);
$subjects = $subjectsStmt->fetchAll();

// فحص بسيط لعدم وجود بيانات — رسالة في أعلى الصفحة فقط
$hasAnyData = ($avgEQI > 0) || !empty($classPerformance) || !empty($subjectPerformance) || !empty($gradeDistribution);
?>
        <!-- Main Content -->
        <div class="container mx-auto px-4 py-8">

            <!-- Hero Header -->
            <div class="page-hero">
                <div class="hero-flex">
                    <div class="hero-flex-left">
                        <h1><?= e($school['name_ar']) ?></h1>
                        <div class="hero-sub">
                            <span>رمز المدرسة: <?= e($school['reference_code']) ?></span>
                            <span class="hero-meta-sep">|</span>
                            <span><?= e($school['governorate'] ?? '—') ?></span>
                            <span class="hero-meta-sep">|</span>
                            <span>
                                <?php
                                $type = $school['school_type'];
                                $types = ['basic' => 'أساسية', 'secondary' => 'ثانوية', 'combined' => 'مختلطة'];
                                echo e($types[$type] ?? $type);
                                ?>
                            </span>
                        </div>
                    </div>
                    <a href="../group_admin/index.php" class="back-btn">← العودة إلى لوحة القيادة</a>
                </div>
            </div>

        <?php if (!$hasAnyData): ?>
        <div class="card" style="border-right:4px solid var(--warning);margin-bottom:1.5rem;">
            <div class="card-body notice-flex">
                <span class="notice-icon">📭</span>
                <div>
                    <strong>لا توجد بيانات أداء لهذه المدرسة في الفترة المحددة</strong>
                    <div style="font-size:0.85rem;color:var(--text-secondary);margin-top:0.25rem;">جرّب اختيار سنة أو فصل مختلف، أو تأكد من رصد الدرجات وتقييم EQI.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Bar -->
            <div class="filter-card">
                <form id="filterForm" method="GET" class="filter-row">
                    <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                    <div>
                        <label>العام الدراسي</label>
                        <select name="academic_year_id" onchange="document.getElementById('filterForm').submit();">
                            <option value="0" <?= $selectedAcademicYearId === 0 ? 'selected' : '' ?>>جميع السنوات</option>
                            <?php foreach ($allAcademicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>" <?= (int)$ay['id'] === $selectedAcademicYearId ? 'selected' : '' ?>>
                                    <?= e($ay['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>الفصل الدراسي</label>
                        <select name="semester_id" onchange="document.getElementById('filterForm').submit();">
                            <option value="0" <?= $selectedSemesterId === 0 ? 'selected' : '' ?>>جميع الفصول</option>
                            <option value="1" <?= $selectedSemesterId === 1 ? 'selected' : '' ?>>الفصل الدراسي الأول</option>
                            <option value="2" <?= $selectedSemesterId === 2 ? 'selected' : '' ?>>الفصل الدراسي الثاني</option>
                        </select>
                    </div>
                    <div class="auto-hint">↵ التحديث التلقائي عند تغيير الفلتر</div>
                </form>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-stats-grid">
                <div class="stat-card stat-card-primary">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value"><?= number_format($avgEQI, 1) ?></div>
                    <div class="stat-label">EQI الخام</div>
                </div>
                <?php if ($hasContext): ?>
                <div class="stat-card stat-card-success">
                    <div class="stat-icon">🎯</div>
                    <div class="stat-value"><?= number_format($adjustedEQI, 1) ?></div>
                    <div class="stat-label">EQI المعدّل سياقياً</div>
                    <div class="stat-card-change <?= $challengeIndex > 50 ? 'negative' : 'positive' ?>">التحدي: <?= number_format($challengeIndex) ?>%</div>
                </div>
                <?php endif; ?>
                <div class="stat-card <?= $annualTrend['diff'] > 1 ? 'stat-card-success' : ($annualTrend['diff'] < -1 ? 'stat-card-danger' : 'stat-card-info') ?>">
                    <div class="stat-icon">📈</div>
                    <div class="stat-value <?= $annualTrend['class'] ?>"><?= $annualTrend['direction'] ?> <?= $annualTrend['text'] ?></div>
                    <div class="stat-label">الاتجاه السنوي</div>
                </div>
                <div class="stat-card stat-card-info">
                    <div class="stat-icon">👨‍🎓</div>
                    <div class="stat-value"><?= number_format($studentCount) ?></div>
                    <div class="stat-label">عدد الطلاب</div>
                </div>
                <div class="stat-card stat-card-warning">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-value"><?= number_format($teacherCount) ?></div>
                    <div class="stat-label">عدد المعلمين</div>
                </div>
            </div>

            <!-- EQI Trend Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📈 اتجاه EQI عبر الفصول</h3>
                    <?php if (!empty($eqiTrendData)): ?>
                    <span style="font-size:0.8rem;color:var(--text-secondary);">آخر <?= count($eqiTrendData) ?> فصول</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($eqiTrendData)): ?>
                        <div class="empty-state">لا توجد بيانات EQI متاحة لهذا الاتجاه.</div>
                    <?php else: 
                        $chartLabels = array_reverse(array_map(function($d) { return $d['semester_name']; }, $eqiTrendData));
                        $chartValues = array_reverse(array_map(function($d) { return (float)$d['overall_eqi']; }, $eqiTrendData));
                    ?>
                    <div class="chart-height-lg">
                        <canvas id="eqiTrendChart"></canvas>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الفصل</th>
                                    <th>تاريخ البداية</th>
                                    <th>EQI</th>
                                    <th>الاتجاه</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $prevEQI = null;
                                $counter = 1;
                                $eqiTrendReversed = array_reverse($eqiTrendData);
                                foreach ($eqiTrendReversed as $data): 
                                    $diff = null;
                                    $trendClass = 'trend-badge-neutral';
                                    $trendIcon = '→';
                                    $trendText = 'الأول';
                                    if ($prevEQI !== null) {
                                        $diff = $data['overall_eqi'] - $prevEQI;
                                        if ($diff > 0) {
                                            $trendClass = 'trend-badge-up';
                                            $trendIcon = '▲';
                                            $trendText = '+' . number_format($diff, 1);
                                        } elseif ($diff < 0) {
                                            $trendClass = 'trend-badge-down';
                                            $trendIcon = '▼';
                                            $trendText = number_format($diff, 1);
                                        } else {
                                            $trendClass = 'trend-badge-neutral';
                                            $trendIcon = '→';
                                            $trendText = 'مستقر';
                                        }
                                    }
                                    $prevEQI = $data['overall_eqi'];
                                ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><?= e($data['semester_name']) ?></td>
                                        <td><?= e($data['start_date']) ?></td>
                                        <td><span class="eqi-score"><span class="score-dot" style="background:<?= $data['overall_eqi'] >= 70 ? 'var(--success)' : ($data['overall_eqi'] >= 50 ? 'var(--warning)' : 'var(--danger)') ?>"></span><?= number_format($data['overall_eqi'], 1) ?></span></td>
                                        <td><span class="trend-badge <?= $trendClass ?>"><?= $trendIcon ?> <?= $trendText ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grade Distribution -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">👥 توزيع الطلاب حسب الصف</h3>
                    <?php if (!empty($gradeDistribution)): ?>
                    <span style="font-size:0.8rem;color:var(--text-secondary);"><?= array_sum(array_column($gradeDistribution, 'student_count')) ?> طالب</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($gradeDistribution)): ?>
                        <div class="empty-state">لا توجد بيانات طلاب متاحة.</div>
                    <?php else: 
                        $maxStudents = max(array_column($gradeDistribution, 'student_count'));
                        $colors = ['var(--primary)', 'var(--success)', 'var(--warning)', 'var(--danger)', 'var(--purple)', 'var(--info)', 'var(--pink)'];
                        $ci = 0;
                    ?>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>الصف</th>
                                    <th>عدد الطلاب</th>
                                    <th>التوزيع النسبي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gradeDistribution as $grade): 
                                    $pct = $maxStudents > 0 ? ($grade['student_count'] / $maxStudents * 100) : 0;
                                    $color = $colors[$ci % count($colors)]; $ci++;
                                ?>
                                    <tr>
                                        <td><strong><?= e($grade['grade_name']) ?></strong></td>
                                        <td><?= number_format($grade['student_count']) ?></td>
                                        <td>
                                            <div class="pct-bar">
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                                </div>
                                                <span class="bar-label"><?= number_format($pct, 0) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Class Performance -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🏫 متوسط أداء الشعب</h3>
                    <?php if (!empty($classPerformance)): ?>
                    <a href="class.php?school_id=<?= $schoolId ?>&academic_year_id=<?= $selectedAcademicYearId ?>&semester_id=<?= $selectedSemesterId ?>" style="font-size:0.85rem;color:var(--primary);">عرض الكل ←</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($classPerformance)): ?>
                        <div class="empty-state">لا توجد بيانات أداء للشعب في هذا الفصل.</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الصف</th>
                                    <th>الشعبة</th>
                                    <th>متوسط النسبة</th>
                                    <th>عدد الطلاب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($classPerformance as $class): 
                                    $avg = (float)$class['avg_percentage'];
                                    $barColor = $avg >= 80 ? 'var(--success)' : ($avg >= 60 ? 'var(--warning)' : 'var(--danger)');
                                ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><?= e($class['grade_name']) ?></td>
                                        <td><a href="class.php?school_id=<?= $schoolId ?>&class_id=<?= $class['id'] ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>" style="color:var(--primary);font-weight:500;"><?= e($class['class_name']) ?></a></td>
                                        <td>
                                            <div class="pct-bar-sm">
                                                <span class="bar-label" style="min-width:auto;font-weight:700;color:<?= $barColor ?>;"><?= number_format($avg, 1) ?>%</span>
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width:<?= min($avg, 100) ?>%;background:<?= $barColor ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= number_format($class['student_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subject Performance -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📚 متوسط أداء المواد</h3>
                    <?php if (!empty($subjectPerformance)): ?>
                    <span style="font-size:0.8rem;color:var(--text-secondary);">مرتبة تنازلياً</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($subjectPerformance)): ?>
                        <div class="empty-state">لا توجد بيانات أداء للمواد في هذا الفصل.</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المادة</th>
                                    <th>متوسط النسبة</th>
                                    <th>عدد الطلاب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($subjectPerformance as $subject): 
                                    $avg = (float)$subject['avg_percentage'];
                                    $barColor = $avg >= 80 ? 'var(--success)' : ($avg >= 60 ? 'var(--warning)' : 'var(--danger)');
                                ?>
                                    <tr>
                                        <td><?= $counter++ ?></td>
                                        <td><?= e($subject['subject_name']) ?></td>
                                        <td>
                                            <div class="pct-bar-sm">
                                                <span class="bar-label" style="min-width:auto;font-weight:700;color:<?= $barColor ?>;"><?= number_format($avg, 1) ?>%</span>
                                                <div class="bar-track" style="width:100px;">
                                                    <div class="bar-fill" style="width:<?= min($avg, 100) ?>%;background:<?= $barColor ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= number_format($subject['student_count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Deep Dive -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🔍 استكشاف أعمق</h3>
                    <?php if (!empty($classes) || !empty($subjects)): ?>
                    <span style="font-size:0.8rem;color:var(--text-secondary);">اختر شعبة أو مادة للتحليل</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($classes)): 
                        $groupedClasses = [];
                        foreach ($classes as $cl) {
                            $grade = $cl['grade_name'];
                            if (!isset($groupedClasses[$grade])) $groupedClasses[$grade] = [];
                            $groupedClasses[$grade][] = $cl;
                        }
                    ?>
                    <div style="margin-bottom:1.5rem;">
                        <div class="section-divider">📚 الشعب الدراسية</div>
                        <?php foreach ($groupedClasses as $grade => $gradeClasses): ?>
                        <div style="margin-bottom:0.75rem;">
                            <div class="grade-label"><?= e($grade) ?></div>
                            <div class="tag-list">
                                <?php foreach ($gradeClasses as $cl): ?>
                                <a href="class.php?school_id=<?= $schoolId ?>&class_id=<?= $cl['id'] ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>">
                                    <span style="font-weight:600;"><?= e($cl['class_name']) ?></span>
                                    <span class="tag-count"><?= number_format($cl['enrolled_count']) ?> طالب</span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($subjects)): 
                        $groupedSubjects = [];
                        foreach ($subjects as $subject) {
                            $first = mb_substr($subject['subject_name'], 0, 1);
                            if (!isset($groupedSubjects[$first])) $groupedSubjects[$first] = [];
                            $groupedSubjects[$first][] = $subject;
                        }
                        ksort($groupedSubjects);
                    ?>
                    <div>
                        <div class="section-divider">📖 المواد الدراسية</div>
                        <div class="tag-list">
                            <?php foreach ($subjects as $subject): ?>
                            <a href="subject.php?school_id=<?= $schoolId ?>&subject_id=<?= $subject['id'] ?>&semester_id=<?= $selectedSemesterId ?>&academic_year_id=<?= $selectedAcademicYearId ?>">
                                <span style="font-weight:600;"><?= e($subject['subject_name']) ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (empty($classes) && empty($subjects)): ?>
                    <div class="empty-state">لا توجد شعب أو مواد متاحة للاستكشاف.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>

        <script>
            <?php if (!empty($eqiTrendData)): ?>
            (function() {
                var labels = <?= json_encode($chartLabels) ?>;
                var values = <?= json_encode($chartValues) ?>;
                if (typeof eqasLineChart === 'function' && document.getElementById('eqiTrendChart')) {
                    eqasLineChart('eqiTrendChart', 'اتجاه EQI', labels, [
                        { label: 'EQI', data: values, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#1a5632', fill: true, fillColor: 'rgba(26,86,50,0.1)', pointRadius: 5 }
                    ], {
                        scales: {
                            y: { min: 0, max: 100, ticks: { stepSize: 20 } }
                        }
                    });
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof eqasLineChart === 'function') {
                            eqasLineChart('eqiTrendChart', 'اتجاه EQI', labels, [
                                { label: 'EQI', data: values, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#1a5632', fill: true, fillColor: 'rgba(26,86,50,0.1)', pointRadius: 5 }
                            ], {
                                scales: {
                                    y: { min: 0, max: 100, ticks: { stepSize: 20 } }
                                }
                            });
                        }
                    });
                }
            })();
            <?php endif; ?>
        </script>