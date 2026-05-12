<?php
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';

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

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user']['id']]);
    $count = (int) $stmt->fetchColumn();

    echo json_encode([
        'count' => $count,
        'success' => true
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Notifications Check API Error: ' . $e->getMessage());
    echo json_encode([
        'count' => 0,
        'success' => false,
        'message' => 'حدث خطأ في جلب الإشعارات'
    ], JSON_UNESCAPED_UNICODE);
}
