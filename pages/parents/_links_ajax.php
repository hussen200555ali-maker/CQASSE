<?php
/**
 * ============================================================
 * AJAX - جلب روابط أولياء الأمور لطالب محدد
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hasPermission('school_admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentId = intval($_GET['student_id'] ?? 0);
$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

if ($studentId <= 0) {
    echo json_encode(['links' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التحقق من نطاق المدرسة
    if ($schoolId && $userRole !== 'group_admin') {
        $stmt = $db->prepare("SELECT id FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$studentId, $schoolId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'الطالب ليس في مدرستك'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $stmt = $db->prepare("SELECT ps.id, ps.relationship, ps.is_active,
                                 u.full_name_ar AS parent_name, u.username, u.phone, u.email,
                                 s.full_name_ar AS student_name
                          FROM parent_students ps
                          JOIN users u ON ps.parent_id = u.id
                          JOIN students s ON ps.student_id = s.id
                          WHERE ps.student_id = ? AND ps.is_active = 1
                          ORDER BY ps.relationship");
    $stmt->execute([$studentId]);
    $links = $stmt->fetchAll();

    echo json_encode(['links' => $links], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Links AJAX Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'حدث خطأ'], JSON_UNESCAPED_UNICODE);
}
