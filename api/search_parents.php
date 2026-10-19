<?php
/**
 * ============================================================
 * API - بحث مستخدمي أولياء الأمور
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * يبحث عن مستخدمي أولياء الأمور (role_id = 4) للربط بالطلاب
 * ============================================================
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';

if (!isAjax()) {
    http_response_code(403);
    echo json_encode(['error' => 'طلب غير مسموح'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$query   = trim($_GET['q'] ?? '');
$schoolId = $_SESSION['user']['school_id'] ?? null;
$userRole = $_SESSION['user']['role'] ?? '';

if (empty($query) || mb_strlen($query) < 2) {
    echo json_encode(['parents' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "SELECT u.id, u.username, u.full_name_ar, u.phone, u.email, u.is_active
            FROM users u
            WHERE u.role_id = 4
              AND (u.full_name_ar LIKE ? OR u.username LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)";

    $params = ["%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];

    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND u.school_id = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY u.full_name_ar LIMIT 20";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $parents = $stmt->fetchAll();

    echo json_encode(['parents' => $parents], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Search Parents API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'حدث خطأ في البحث'], JSON_UNESCAPED_UNICODE);
}
