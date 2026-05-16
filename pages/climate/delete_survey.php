<?php
/**
 * ============================================================
 * EQAS - حذف استبيان المناخ
 * نظام التقييم الشامل - Delete Climate Survey
 * ============================================================
 */

require_once __DIR__ . '/../../includes/header.php';

if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('index.php');
}

$surveyId = (int)($_GET['id'] ?? 0);
if (!$surveyId) {
    setFlash('معرف الاستبيان غير صالح', 'danger');
    redirect('dashboard.php');
}

// جلب بيانات الاستبيان
$stmt = $db->prepare("SELECT s.*, sch.name_ar AS school_name FROM surveys s JOIN schools sch ON s.school_id = sch.id WHERE s.id = ? AND s.survey_type = 'climate'");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch();

if (!$survey) {
    setFlash('الاستبيان غير موجود', 'danger');
    redirect('dashboard.php');
}

// حذف العناصر المرتبطة
try {
    $db->beginTransaction();
    
    // حذف من climate_items
    $db->prepare("DELETE FROM climate_items WHERE survey_id = ?")->execute([$surveyId]);
    
    // حذف من survey_items
    $db->prepare("DELETE FROM survey_items WHERE survey_id = ?")->execute([$surveyId]);
    
    // حذف الاستبيان
    $db->prepare("DELETE FROM surveys WHERE id = ?")->execute([$surveyId]);
    
    $db->commit();
    
    logActivity($db, $_SESSION['user']['id'], 'climate_survey_delete', 
        'حذف استبيان مناخ - ' . $survey['school_name'] . ' - ' . $survey['survey_date'], 
        'climate_survey', $surveyId);
    
    setFlash('تم حذف الاستبيان بنجاح', 'success');
} catch (Exception $e) {
    $db->rollBack();
    setFlash('خطأ في حذف الاستبيان: ' . $e->getMessage(), 'danger');
}

redirect('dashboard.php');
