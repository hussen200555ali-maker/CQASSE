<?php
/**
 * ============================================================
 * API - فصل ولي أمر عن الطالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * يعطّل الرابط في parent_students (soft delete: is_active = 0)
 * ============================================================
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طلب غير مسموح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hasPermission('school_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'رمز الأمان غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$linkId = intval($_POST['link_id'] ?? 0);

if ($linkId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    // التحقق من وجود الرابط ونطاق المدرسة
    if ($schoolId && $userRole !== 'group_admin') {
        $stmt = $db->prepare("SELECT ps.id, ps.parent_id, ps.student_id, u.full_name_ar AS parent_name, s.full_name_ar AS student_name
                              FROM parent_students ps
                              JOIN students s ON ps.student_id = s.id
                              JOIN users u ON ps.parent_id = u.id
                              WHERE ps.id = ? AND s.school_id = ?");
        $stmt->execute([$linkId, $schoolId]);
    } else {
        $stmt = $db->prepare("SELECT ps.id, ps.parent_id, ps.student_id, u.full_name_ar AS parent_name, s.full_name_ar AS student_name
                              FROM parent_students ps
                              JOIN students s ON ps.student_id = s.id
                              JOIN users u ON ps.parent_id = u.id
                              WHERE ps.id = ?");
        $stmt->execute([$linkId]);
    }

    $link = $stmt->fetch();

    if (!$link) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'الرابط غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تعطيل الرابط
    $stmt = $db->prepare("UPDATE parent_students SET is_active = 0 WHERE id = ?");
    $stmt->execute([$linkId]);

    logActivity($db, $_SESSION['user']['id'], 'unlink_parent', "فصل ولي الأمر \"{$link['parent_name']}\" عن الطالب \"{$link['student_name']}\"", 'parent_student', $linkId);

    // إشعار ولي الأمر
    $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read) VALUES (?, 'parent_link', ?, ?, 'student', ?, 0)");
    $notifStmt->execute([
        $link['parent_id'],
        'إلغاء الربط',
        "تم فصل ولي الأمر عن الطالب: {$link['student_name']}",
        $link['student_id']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'تم فصل ولي الأمر عن الطالب بنجاح'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Unlink Parent API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في الفصل'], JSON_UNESCAPED_UNICODE);
}
