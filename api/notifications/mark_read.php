<?php
/**
 * ============================================================
 * EQAS - API: تحديد إشعار كمقروء
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
    echo json_encode(['success' => false, 'logged_out' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'رمز الأمان غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = $_SESSION['user']['id'];
$notifId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($notifId <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف الإشعار غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifId, $userId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'تم التحديد كمقروء'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => 'الإشعار غير موجود أو لا يخصك'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('Notifications MarkRead API Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في تحديث الإشعار'], JSON_UNESCAPED_UNICODE);
}
