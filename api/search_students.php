<?php
/**
 * ============================================================
 * API - بحث الطلاب للتسجيل
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * يبحث عن الطلاب المتاحين للتسجيل في فصل محدد
 * يستبعد الطلاب المسجلين بالفعل في نفس الفصل والسنة
 * ============================================================
 */

// تضمين الملفات الأساسية
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';

// التحقق من أن الطلب AJAX
if (!isAjax()) {
    http_response_code(403);
    echo json_encode(['error' => 'طلب غير مسموح'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
if (!hasPermission('school_admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

// متغيرات الإدخال
$query          = trim($_GET['q'] ?? '');
$classId        = intval($_GET['class_id'] ?? 0);
$academicYearId = intval($_GET['academic_year_id'] ?? 0);

if (empty($query) || mb_strlen($query) < 2) {
    echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($classId <= 0 || $academicYearId <= 0) {
    echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// متغيرات المستخدم والسياق
$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    // البحث عن الطلاب غير المسجلين في الفصل المحدد
    $sql = "SELECT s.id, s.student_number, s.full_name_ar, gl.name_ar AS grade_name
            FROM students s
            LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
            WHERE s.is_active = 1
              AND (s.full_name_ar LIKE ? OR s.student_number LIKE ?)
              AND NOT EXISTS (
                  SELECT 1 FROM student_enrollments se
                  WHERE se.student_id = s.id
                    AND se.class_id = ?
                    AND se.academic_year_id = ?
                    AND se.status = 'enrolled'
                    AND se.is_active = 1
              )";

    $params = ["%{$query}%", "%{$query}%", $classId, $academicYearId];

    // نطاق المدرسة
    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY s.full_name_ar LIMIT 20";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    echo json_encode(['students' => $students], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Search Students API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'حدث خطأ في البحث'], JSON_UNESCAPED_UNICODE);
}
