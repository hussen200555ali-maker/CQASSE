<?php
/**
 * ============================================================
 * EQAS - API: معاينة الإشعارات (آخر 5 غير مقروءة)
 * ============================================================
 */

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';

if (!isAjax()) {
    http_response_code(403);
    echo json_encode(['error' => 'طلب غير مسموح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['id'])) {
    echo json_encode(['items' => [], 'count' => 0, 'logged_out' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = $_SESSION['user']['id'];

try {
    // العدد الكلي
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $countStmt->execute([$userId]);
    $total = (int)$countStmt->fetchColumn();

    // آخر 5 إشعارات غير مقروءة
    $stmt = $db->prepare("SELECT id, type, title_ar, message_ar, created_at
                           FROM notifications
                           WHERE user_id = ? AND is_read = 0
                           ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    echo json_encode([
        'items' => $items,
        'count' => $total,
        'success' => true
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Notifications Preview API Error: ' . $e->getMessage());
    echo json_encode([
        'items' => [],
        'count' => 0,
        'success' => false,
        'message' => 'حدث خطأ في جلب الإشعارات'
    ], JSON_UNESCAPED_UNICODE);
}
