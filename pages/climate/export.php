<?php
/**
 * ============================================================
 * EQAS - تصدير استبيان المناخ
 * نظام التقييم الشامل - Export Climate Survey
 * ============================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$surveyId = (int)($_GET['id'] ?? 0);
if (!$surveyId) {
    setFlash('معرف الاستبيان غير صالح', 'danger');
    redirect('dashboard.php');
}

// جلب بيانات الاستبيان
$stmt = $db->prepare("SELECT s.*, sch.name_ar AS school_name, sem.name_ar AS semester_name, ay.name AS year_name
    FROM surveys s 
    JOIN schools sch ON s.school_id = sch.id 
    JOIN semesters sem ON s.semester_id = sem.id 
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE s.id = ? AND s.survey_type = 'climate'");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch();

if (!$survey) {
    setFlash('الاستبيان غير موجود', 'danger');
    redirect('dashboard.php');
}

// جلب العناصر
$items = [];
try {
    $stmt = $db->prepare("
        SELECT ast.text_ar, ast.name_ar, ci.normalized_value
        FROM climate_items ci
        JOIN assessment_structure ast ON ci.item_id = ast.id
        WHERE ci.survey_id = ? AND ci.is_active = 1
        ORDER BY ast.sort_order
    ");
    $stmt->execute([$surveyId]);
    $items = $stmt->fetchAll();
} catch (Exception $e) {}

// تصدير CSV
ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="climate_survey_' . $survey['school_name'] . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// معلومات الاستبيان
fputcsv($output, ['استبيان المناخ المدرسي']);
fputcsv($output, ['المدرسة', $survey['school_name']]);
fputcsv($output, ['السنة الدراسية', $survey['year_name']]);
fputcsv($output, ['الفصل الدراسي', $survey['semester_name']]);
fputcsv($output, ['نوع المستجيب', $survey['respondent_type']]);
fputcsv($output, ['الدرجة الإجمالية', $survey['overall_score'] . '%']);
fputcsv($output, ['التاريخ', $survey['survey_date']]);
fputcsv($output, []);

// العناصر
fputcsv($output, ['#', 'البعد', 'العنصر', 'الدرجة (%)']);
$dimCount = 0;
foreach ($items as $idx => $item) {
    $dimName = $item['name_ar'] ?? '';
    if ($dimName && (!isset($lastDim) || $lastDim !== $dimName)) {
        $lastDim = $dimName;
        $dimCount++;
    }
    fputcsv($output, [
        $idx + 1,
        $dimName,
        $item['text_ar'],
        number_format($item['normalized_value'], 1) . '%'
    ]);
}

fclose($output);
exit;
