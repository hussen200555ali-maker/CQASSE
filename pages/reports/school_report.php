<?php
/**
 * ============================================================
 * EQAS - School Report Generator
 * تقرير مدرسة محددة (PDF)
 * ============================================================
 * صلاحية: group_admin + school_admin
 * - group_admin: يختار أي مدرسة
 * - school_admin: مقيد بمدرسته تلقائياً (لا يمكنه تغييرها)
 */

// ===================== التحميل الموحد =====================
require_once __DIR__ . '/../../includes/error_handler.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'تقرير المدرسة';
$breadcrumb = ['التقارير' => null, 'تقرير المدرسة' => null];

// ============================================================
// POST: توليد PDF
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/report_template.php';
    require_once __DIR__ . '/../../includes/eqi.php';

    $userRole = $_SESSION['user']['role'] ?? '';

    // فحص الصلاحية: group_admin أو school_admin فقط
    if ($userRole !== 'group_admin' && $userRole !== 'school_admin') {
        http_response_code(403);
        die('غير مصرح');
    }

    if (!verifyCsrf()) {
        setFlash('رمز الأمان غير صالح، يرجى المحاولة مرة أخرى', 'danger');
        redirect('school_report.php');
    }

    global $db;

    // تحديد المدرسة: school_admin مقيد بمدرسته تلقائياً
    if ($userRole === 'school_admin') {
        $schoolId = (int)currentSchoolId();
    } else {
        $schoolId = (int)($_POST['school_id'] ?? 0);
    }

    $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
    $semesterId = (int)($_POST['semester_id'] ?? 0);
    $preparedBy = $_SESSION['user']['full_name'] ?? 'مدير النظام';

    if (!$schoolId || !$academicYearId || !$semesterId) {
        http_response_code(400);
        die('بيانات غير مكتملة');
    }

    // تحقق إضافي: school_admin لا يمكنه التلاعب بـ school_id
    if ($userRole === 'school_admin' && (int)currentSchoolId() !== $schoolId) {
        http_response_code(403);
        die('غير مصرح');
    }

    // بيانات المدرسة
    $schoolStmt = $db->prepare("SELECT name_ar, name_en FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    if (!$school) {
        http_response_code(404);
        die('المدرسة غير موجودة');
    }
    $schoolName = $school['name_ar'];

    // العام الدراسي والفصل
    $yearStmt = $db->prepare("SELECT name FROM academic_years WHERE id = ?");
    $yearStmt->execute([$academicYearId]);
    $yearName = $yearStmt->fetchColumn() ?: '';

    $semStmt = $db->prepare("SELECT name_ar FROM semesters WHERE id = ?");
    $semStmt->execute([$semesterId]);
    $semName = $semStmt->fetchColumn() ?: '';

    // ================================================================
    // جمع البيانات
    // ================================================================

    // 1. إحصائيات عامة
    $stats = [];
    $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$schoolId]);
    $stats['students'] = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1");
    $stmt->execute([$schoolId]);
    $stats['teachers'] = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$schoolId]);
    $stats['classes'] = $stmt->fetchColumn();

    // 2. EQI
    $eqiStmt = $db->prepare("SELECT overall_eqi FROM eqi_results WHERE school_id = ? AND academic_year_id = ? AND semester_id = ?");
    $eqiStmt->execute([$schoolId, $academicYearId, $semesterId]);
    $eqiRow = $eqiStmt->fetch();
    $eqiScore = $eqiRow ? (float)$eqiRow['overall_eqi'] : 0;
    $eqiRating = $eqiScore > 0 ? getEqiRating($eqiScore) : ['—', 'معلومات'];

    // EQI السابق (للاتجاه)
    $prevYearStmt = $db->prepare("SELECT id FROM academic_years WHERE id < ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1");
    $prevYearStmt->execute([$academicYearId]);
    $prevYearId = $prevYearStmt->fetchColumn();
    $prevEqi = null;
    if ($prevYearId) {
        $prevSemStmt = $db->prepare("SELECT s2.id FROM semesters s2 JOIN semesters s1 ON s1.id = ? WHERE s2.academic_year_id = ? AND s2.name_ar = s1.name_ar AND s2.is_active = 1 LIMIT 1");
        $prevSemStmt->execute([$semesterId, $prevYearId]);
        $prevSemId = $prevSemStmt->fetchColumn();
        if ($prevSemId) {
            $prevEqiStmt = $db->prepare("SELECT overall_eqi FROM eqi_results WHERE school_id = ? AND academic_year_id = ? AND semester_id = ?");
            $prevEqiStmt->execute([$schoolId, $prevYearId, $prevSemId]);
            $prevEqiRow = $prevEqiStmt->fetch();
            $prevEqi = $prevEqiRow ? (float)$prevEqiRow['overall_eqi'] : null;
        }
    }

    $eqiTrend = null;
    if ($prevEqi !== null && $eqiScore > 0 && $prevEqi > 0) {
        $diff = $eqiScore - $prevEqi;
        $pctChange = round(($diff / $prevEqi) * 100, 1);
        $eqiTrend = $pctChange > 1 ? "▲ +{$pctChange}%" : ($pctChange < -1 ? "▼ " . abs($pctChange) . "%" : "→ مستقر");
    }

    // 3. الأبعاد الفرعية لـ EQI (من أعمدة eqi_results)
    $eqiDimensions = [];
    if ($eqiScore > 0) {
        $dimStmt = $db->prepare("SELECT academic_score, teaching_score, climate_score, behavior_score, skills_score, attendance_score FROM eqi_results WHERE school_id = ? AND academic_year_id = ? AND semester_id = ?");
        $dimStmt->execute([$schoolId, $academicYearId, $semesterId]);
        $dimRow = $dimStmt->fetch();
        if ($dimRow) {
            $dimMap = [
                'climate' => 'climate_score',
                'behavior' => 'behavior_score',
                'skills' => 'skills_score',
                'teaching' => 'teaching_score',
                'attendance' => 'attendance_score',
            ];
            foreach ($dimMap as $key => $col) {
                $val = (float)$dimRow[$col];
                if ($val > 0) $eqiDimensions[$key] = $val;
            }
        }
    }

    // 4. الحضور
    $attStmt = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count
        FROM attendance_daily
        WHERE class_id IN (SELECT id FROM classes WHERE school_id = ?)
          AND date >= (SELECT start_date FROM semesters WHERE id = ?)
          AND date <= (SELECT end_date FROM semesters WHERE id = ?)
    ");
    $attStmt->execute([$schoolId, $semesterId, $semesterId]);
    $attRow = $attStmt->fetch();
    $attendancePct = ($attRow && $attRow['total'] > 0) ? round(($attRow['present_count'] / $attRow['total']) * 100, 1) : 0;

    // 5. السلوك
    $behPosStmt = $db->prepare("
        SELECT COUNT(*) FROM behavior_incidents b
        JOIN classes c ON b.class_id = c.id
        JOIN behavior_categories cat ON b.category_id = cat.id
        WHERE c.school_id = ? AND cat.type = 'positive'
          AND b.incident_date >= (SELECT start_date FROM semesters WHERE id = ?)
          AND b.incident_date <= (SELECT end_date FROM semesters WHERE id = ?)
    ");
    $behPosStmt->execute([$schoolId, $semesterId, $semesterId]);
    $positiveBehaviors = $behPosStmt->fetchColumn();

    $behNegStmt = $db->prepare("
        SELECT COUNT(*) FROM behavior_incidents b
        JOIN classes c ON b.class_id = c.id
        JOIN behavior_categories cat ON b.category_id = cat.id
        WHERE c.school_id = ? AND cat.type = 'negative'
          AND b.incident_date >= (SELECT start_date FROM semesters WHERE id = ?)
          AND b.incident_date <= (SELECT end_date FROM semesters WHERE id = ?)
    ");
    $behNegStmt->execute([$schoolId, $semesterId, $semesterId]);
    $negativeBehaviors = $behNegStmt->fetchColumn();

    // متوسط نقاط السلوك (current_percentage من behavior_scores)
    $avgBehStmt = $db->prepare("
        SELECT AVG(b.current_percentage) FROM behavior_scores b
        JOIN classes c ON b.class_id = c.id
        WHERE c.school_id = ? AND b.semester_id = ?
    ");
    $avgBehStmt->execute([$schoolId, $semesterId]);
    $avgBehaviorScore = $avgBehStmt->fetchColumn() ?: 0;

    // 6. التقييمات الإشرافية
    $evalStmt = $db->prepare("
        SELECT COUNT(*) as total, AVG(combined_score) as avg_score
        FROM evaluations e
        JOIN classes c ON e.class_id = c.id
        WHERE c.school_id = ? AND e.academic_year_id = ? AND e.semester_id = ?
    ");
    $evalStmt->execute([$schoolId, $academicYearId, $semesterId]);
    $evalRow = $evalStmt->fetch();
    $evalCount = $evalRow['total'] ?? 0;
    $evalAvg = $evalRow['avg_score'] ? round((float)$evalRow['avg_score'], 1) : 0;

    // 7. المناخ المدرسي
    $climateStmt = $db->prepare("
        SELECT COUNT(*) as total, AVG(overall_score) as avg_score
        FROM surveys
        WHERE school_id = ? AND semester_id = ? AND respondent_type IN ('teacher', 'student')
    ");
    $climateStmt->execute([$schoolId, $semesterId]);
    $climateRow = $climateStmt->fetch();
    $climateSurveyAvg = $climateRow['avg_score'] ? round((float)$climateRow['avg_score'], 1) : 0;
    $climateRespondents = $climateRow['total'] ?? 0;

    // 8. التنبيهات المفتوحة
    $alertStmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE school_id = ? AND is_resolved = 0");
    $alertStmt->execute([$schoolId]);
    $openAlerts = $alertStmt->fetchColumn();

    // 9. الأداء الأكاديمي (متوسط النسب المئوية حسب المادة)
    $subjectStmt = $db->prepare("
        SELECT sub.name_ar, AVG(ss.percentage) as avg_score,
               COUNT(ss.percentage) as count
        FROM student_subject_summary ss
        JOIN subjects sub ON ss.subject_id = sub.id
        JOIN classes c ON ss.class_id = c.id
        WHERE c.school_id = ? AND ss.academic_year_id = ? AND ss.semester_id = ?
          AND ss.percentage IS NOT NULL AND ss.percentage > 0
        GROUP BY sub.id, sub.name_ar
        ORDER BY avg_score DESC
    ");
    $subjectStmt->execute([$schoolId, $academicYearId, $semesterId]);
    $subjectPerformance = $subjectStmt->fetchAll();

    // ================================================================
    // بناء التقرير PDF
    // ================================================================

    $title = "تقرير {$schoolName} - {$yearName}";
    $report = new ReportTemplate($title, 'P', 'A4');

    $logoPath = __DIR__ . '/../../assets/img/logo-login.svg';
    if (!file_exists($logoPath)) $logoPath = null;
    $report->addCoverPage($yearName . ' — ' . $schoolName, $preparedBy, $logoPath);

    $report->startContentPages();

    // ── الملخص التنفيذي ──
    $report->addSectionTitle('الملخص التنفيذي');

    $execSummary = "يُقدم هذا التقرير تحليلاً شاملاً لأداء {$schoolName} للعام الدراسي {$yearName}، الفصل {$semName}. يغطي التقرير {$stats['students']} طالباً و {$stats['teachers']} معلماً موزعين على {$stats['classes']} شعبة.";
    if ($eqiScore > 0) {
        $execSummary .= " بلغ مؤشر جودة التعليم {$eqiScore}% بتقييم {$eqiRating[0]}.";
    }
    $report->addParagraph($execSummary);

    $report->addKPICards([
        ['label' => 'عدد الطلاب', 'value' => number_format($stats['students']), 'color' => [26, 86, 50]],
        ['label' => 'عدد المعلمين', 'value' => number_format($stats['teachers']), 'color' => [200, 169, 81]],
        ['label' => 'عدد الشعب', 'value' => number_format($stats['classes']), 'color' => [23, 162, 184]],
        ['label' => 'مؤشر الجودة', 'value' => $eqiScore > 0 ? number_format($eqiScore, 1) . '%' : '—', 'color' => $eqiScore >= 85 ? [40, 167, 69] : ($eqiScore >= 70 ? [23, 162, 184] : ($eqiScore >= 50 ? [255, 193, 7] : ($eqiScore > 0 ? [220, 53, 69] : [150, 150, 150])))],
    ]);

    if ($eqiTrend) {
        $report->addParagraph("الاتجاه السنوي: {$eqiTrend} مقارنة بنفس الفصل من العام السابق.");
    }

    // ── مؤشر جودة التعليم ──
    $report->addSectionTitle('مؤشر جودة التعليم (EQI)');

    if ($eqiScore > 0) {
        $report->addSubTitle('الدرجة الكلية');
        $barColor = $eqiRating[2] === 'success' ? [40, 167, 69] : ($eqiRating[2] === 'warning' ? [255, 193, 7] : [220, 53, 69]);
        $report->addProgressBar('مؤشر الجودة', $eqiScore, 100, $barColor);
        $report->addParagraph("التقييم: {$eqiRating[0]} — {$eqiRating[1]}");
    } else {
        $report->addParagraph("لم يتم حساب مؤشر جودة التعليم لهذه المدرسة لهذا الفصل بعد.");
    }

    if (!empty($eqiDimensions)) {
        $report->addSubTitle('الأبعاد الفرعية');
        $dimLabels = [
            'climate' => 'المناخ المدرسي',
            'behavior' => 'السلوك والانضباط',
            'skills' => 'المهارات',
            'teaching' => 'جودة التدريس',
            'attendance' => 'الحضور',
        ];
        $dimRows = [];
        foreach ($eqiDimensions as $dim => $score) {
            if ($score > 0) {
                $dimLabel = $dimLabels[$dim] ?? $dim;
                $dimRating = getEqiRating($score);
                $dimRows[] = [
                    ['text' => $dimLabel, 'align' => 'R'],
                    ['text' => number_format($score, 1) . '%', 'align' => 'C'],
                    ['text' => $dimRating[0], 'align' => 'C', 'color' => $dimRating[2] === 'success' ? [40, 167, 69] : ($dimRating[2] === 'warning' ? [255, 193, 7] : [220, 53, 69])],
                ];
            }
        }
        if (!empty($dimRows)) {
            $report->addTable(
                [
                    ['text' => 'البُعد', 'width' => 40],
                    ['text' => 'الدرجة', 'width' => 30],
                    ['text' => 'التقييم', 'width' => 30],
                ],
                $dimRows
            );
        }
    }

    // ── الحضور والانضباط ──
    $report->addSectionTitle('الحضور والانضباط');

    $report->addSubTitle('نسبة الحضور');
    $attBarColor = $attendancePct >= 90 ? [40, 167, 69] : ($attendancePct >= 75 ? [255, 193, 7] : [220, 53, 69]);
    $report->addProgressBar('الحضور', $attendancePct, 100, $attBarColor);
    $report->addParagraph("نسبة الحضور الكلية: {$attendancePct}%");

    $report->addSubTitle('السلوك');
    $behaviorSummary = "تم تسجيل {$positiveBehaviors} سلوك إيجابي و {$negativeBehaviors} سلوك سلبي.";
    if ($avgBehaviorScore > 0) {
        $behaviorSummary .= " متوسط نقاط السلوك: " . number_format($avgBehaviorScore, 1);
    }
    $report->addParagraph($behaviorSummary);

    // ── التقييمات الإشرافية ──
    $report->addSectionTitle('التقييمات الإشرافية');

    if ($evalCount > 0) {
        $report->addParagraph("تم إجراء {$evalCount} تقييم إشرافي بمتوسط درجة {$evalAvg}%.");
        $evalBarColor = $evalAvg >= 85 ? [40, 167, 69] : ($evalAvg >= 70 ? [23, 162, 184] : ($evalAvg >= 50 ? [255, 193, 7] : [220, 53, 69]));
        $report->addProgressBar('متوسط التقييمات', $evalAvg, 100, $evalBarColor);
    } else {
        $report->addParagraph("لم يتم إجراء أي تقييمات إشرافية لهذا الفصل.");
    }

    // ── المناخ المدرسي ──
    $report->addSectionTitle('المناخ المدرسي');

    if ($climateSurveyAvg > 0) {
        $report->addParagraph("شارك {$climateRespondents} مستجيب في استبيانات المناخ المدرسي بمتوسط درجة {$climateSurveyAvg}%.");
        $clBarColor = $climateSurveyAvg >= 70 ? [40, 167, 69] : ($climateSurveyAvg >= 50 ? [255, 193, 7] : [220, 53, 69]);
        $report->addProgressBar('رضا المناخ', $climateSurveyAvg, 100, $clBarColor);
    } else {
        $report->addParagraph("لا تتوفر نتائج استبيانات المناخ المدرسي لهذا الفصل.");
    }

    // ── الأداء الأكاديمي ──
    $report->addSectionTitle('الأداء الأكاديمي');

    if (!empty($subjectPerformance)) {
        $report->addParagraph("يوضح الجدول التالي متوسط درجات الطلاب في كل مادة لهذا الفصل:");

        $subjRows = [];
        foreach ($subjectPerformance as $sub) {
            $avg = round((float)$sub['avg_score'], 1);
            $rating = getEqiRating($avg);
            $subjRows[] = [
                ['text' => $sub['name_ar'], 'align' => 'R'],
                ['text' => number_format($avg, 1) . '%', 'align' => 'C'],
                ['text' => (string)$sub['count'], 'align' => 'C'],
                ['text' => $rating[0], 'align' => 'C', 'color' => $rating[2] === 'success' ? [40, 167, 69] : ($rating[2] === 'warning' ? [255, 193, 7] : [220, 53, 69])],
            ];
        }

        $report->addTable(
            [
                ['text' => 'المادة', 'width' => 40],
                ['text' => 'المتوسط', 'width' => 20],
                ['text' => 'العدد', 'width' => 20],
                ['text' => 'التقييم', 'width' => 20],
            ],
            $subjRows
        );

        if (count($subjectPerformance) >= 2) {
            $best = $subjectPerformance[0];
            $worst = $subjectPerformance[count($subjectPerformance) - 1];
            $report->addParagraph("أفضل مادة: {$best['name_ar']} (" . number_format((float)$best['avg_score'], 1) . "%) — أضعف مادة: {$worst['name_ar']} (" . number_format((float)$worst['avg_score'], 1) . "%)");
        }
    } else {
        $report->addParagraph("لا تتوفر بيانات أكاديمية مسجلة لهذا الفصل.");
    }

    // ── التنبيهات المفتوحة ──
    $report->addSectionTitle('التنبيهات المفتوحة');

    if ($openAlerts > 0) {
        $report->addParagraph("يوجد {$openAlerts} تنبيه مفتوح يحتاج متابعة. يُرجى مراجعة لوحة التنبيهات لمعالجتها.");

        $alertListStmt = $db->prepare("
            SELECT message_ar AS message, severity, created_at
            FROM alerts
            WHERE school_id = ? AND is_resolved = 0
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $alertListStmt->execute([$schoolId]);
        $alertItems = [];
        $severityLabels = ['critical' => 'حرج', 'danger' => 'عاجل', 'warning' => 'تحذير', 'info' => 'معلومة'];
        while ($a = $alertListStmt->fetch()) {
            $sevLabel = $severityLabels[$a['severity']] ?? $a['severity'];
            $dateStr = date('Y/m/d', strtotime($a['created_at']));
            $alertItems[] = "[$dateStr] [$sevLabel] {$a['message']}";
        }
        if (!empty($alertItems)) {
            $report->addSubTitle('آخر التنبيهات');
            $report->addRecommendations($alertItems);
        }
    } else {
        $report->addParagraph("لا توجد تنبيهات مفتوحة. الأداء ضمن المستويات المقبولة.");
    }

    // ── التوصيات ──
    $report->addSectionTitle('التوصيات');
    $report->addParagraph("بناءً على تحليل البيانات الواردة في هذا التقرير، نوصي باتخاذ الإجراءات التالية:");

    $recommendations = [];

    if ($eqiScore > 0 && $eqiScore < 50) {
        $recommendations[] = "مؤشر جودة التعليم منخفض (أقل من 50%) — يوصى بوضع خطة تحسين شاملة وعاجلة.";
    }
    if ($eqiScore > 0 && $eqiScore >= 50 && $eqiScore < 70) {
        $recommendations[] = "مؤشر جودة التعليم في المستوى المقبول — يوصى بتعزيز البرامج التربوية للوصول لمستوى جيد جداً.";
    }
    if ($eqiTrend && strpos($eqiTrend, '▼') !== false) {
        $recommendations[] = "سجلت المدرسة تراجعاً في مؤشر الجودة مقارنة بالعام السابق — يوصى بتحليل أسباب التراجع وتصميم خطط علاجية.";
    }
    if ($attendancePct > 0 && $attendancePct < 75) {
        $recommendations[] = "نسبة الحضور منخفضة — يوصى بتفعيل نظام متابعة الغياب والتواصل مع أولياء الأمور.";
    }
    if ($climateSurveyAvg > 0 && $climateSurveyAvg < 60) {
        $recommendations[] = "نسبة رضا المناخ المدرسي منخفضة — يوصى بإجراء استبيانات تفصيلية ومعالجة التحديات.";
    }
    if ($evalCount > 0 && $evalAvg < 60) {
        $recommendations[] = "متوسط التقييمات الإشرافية منخفض — يوصى بتكثيف الزيارات الإشرافية وبرامج التطوير المهني.";
    }
    if (!empty($subjectPerformance)) {
        $worst = $subjectPerformance[count($subjectPerformance) - 1];
        if ((float)$worst['avg_score'] < 50) {
            $recommendations[] = "المادة {$worst['name_ar']} تحتاج تدخلاً عاجلاً — متوسط الدرجات أقل من 50%.";
        }
    }

    if (empty($recommendations)) {
        $recommendations[] = "الأداء العام جيد — يُوصى بالاستمرار في البرامج الحالية مع التركيز على التطوير المستمر.";
        $recommendations[] = "تفعيل برامج التطوير المهني المستمر للمعلمين.";
        $recommendations[] = "تعزيز مشاركة أولياء الأمور في العملية التعليمية.";
    }

    $report->addRecommendations($recommendations);

    $filename = "EQAS_School_Report_{$schoolName}_{$yearName}_{$semName}.pdf";
    $filename = str_replace(['/', '\\', ' ', '،', ','], '_', $filename);
    $report->output($filename, 'D');
    exit;
}

// ============================================================
// GET: عرض صفحة الاختيار
// ============================================================
require_once __DIR__ . '/../../includes/header.php';

$userRole = $_SESSION['user']['role'] ?? '';

// فحص الصلاحية: group_admin أو school_admin فقط
if ($userRole !== 'group_admin' && $userRole !== 'school_admin') {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'danger');
    redirect('../../index.php');
}

$isGroupAdmin = ($userRole === 'group_admin');

// تحديد المدرسة
if ($isGroupAdmin) {
    $schoolId = (int)($_GET['school_id'] ?? 0);
    $stmt = $db->query("SELECT id, name_ar FROM schools WHERE is_active = 1 ORDER BY name_ar");
    $schools = $stmt->fetchAll();
} else {
    // school_admin: مقيد بمدرسته فقط
    $schoolId = (int)currentSchoolId();
    $stmt = $db->prepare("SELECT id, name_ar FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $schools = $stmt->fetchAll();
}

// السنوات الدراسية
$academicYears = [];
try {
    $stmt = $db->query("SELECT id, name FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC");
    $academicYears = $stmt->fetchAll();
} catch (Exception $e) {}

$selectedYear = (int)($_GET['academic_year_id'] ?? ($academicYears[0]['id'] ?? 0));

// الفصول الدراسية
$semesters = [];
if ($selectedYear) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar FROM semesters WHERE academic_year_id = ? AND is_active = 1");
        $stmt->execute([$selectedYear]);
        $semesters = $stmt->fetchAll();
    } catch (Exception $e) {}
}
?>
<div class="section">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📄 تقرير المدرسة</h3>
        </div>
        <div class="card-body">
            <div style="background:var(--bg-info-light);border:1px solid var(--border-info);border-radius:8px;padding:16px;margin-bottom:20px;">
                <p style="margin:0;color:var(--text-info);">
                    يُنشئ هذا التقرير وثيقة PDF احترافية تتضمن: ملخص تنفيذي، مؤشر جودة التعليم، الحضور والانضباط،
                    التقييمات الإشرافية، المناخ المدرسي، الأداء الأكاديمي، التنبيهات المفتوحة، والتوصيات.
                </p>
            </div>

            <form method="POST" action="" id="reportForm">
                <?= csrfField() ?>
                <div class="form-row">

                    <?php if ($isGroupAdmin): ?>
                    <div class="form-group">
                        <label class="form-label">المدرسة</label>
                        <select name="school_id" class="form-control" required>
                            <option value="">اختر المدرسة</option>
                            <?php foreach ($schools as $sch): ?>
                            <option value="<?= $sch['id'] ?>" <?= $schoolId == $sch['id'] ? 'selected' : '' ?>><?= e($sch['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="school_id" value="<?= $schoolId ?>">
                    <div class="form-group">
                        <label class="form-label">المدرسة</label>
                        <input type="text" class="form-control" value="<?= e($schools[0]['name_ar'] ?? '') ?>" readonly style="background:var(--bg-muted);">
                        <small class="form-text">مقيد بمدرستك تلقائياً</small>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">السنة الدراسية</label>
                        <select name="academic_year_id" id="academicYearSelect" class="form-control" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selectedYear == $ay['id'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">الفصل الدراسي</label>
                        <select name="semester_id" id="semesterSelect" class="form-control" required>
                            <option value="">اختر الفصل</option>
                            <?php foreach ($semesters as $sem): ?>
                            <option value="<?= $sem['id'] ?>"><?= e($sem['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div class="form-group" style="margin-top:1.5rem;">
                    <button type="submit" class="btn btn-primary">
                        📄 إنشاء تقرير المدرسة (PDF)
                    </button>
                    <div id="loadingIndicator" style="display:none;margin-top:10px;color:var(--text-muted);">
                        جاري إنشاء التقرير... قد يستغرق ذلك بضع لحظات
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('academicYearSelect').addEventListener('change', function() {
    var yearId = this.value;
    var semSelect = document.getElementById('semesterSelect');
    semSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    fetch('school_report.php?academic_year_id=' + yearId)
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newSelect = doc.getElementById('semesterSelect');
            if (newSelect) {
                semSelect.innerHTML = newSelect.innerHTML;
            }
        });
});

document.getElementById('reportForm').addEventListener('submit', function() {
    document.getElementById('loadingIndicator').style.display = 'block';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
