<?php
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'logged_out' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// تمديد الجلسة يدوياً
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['extend'])) {
        $_SESSION['last_activity'] = time();
        echo json_encode(['ok' => true, 'extended' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// تحديث last_activity مع كل ping للحفاظ على الجلسة نشطة
// (session.php يعيد تعيين المهلة مع last_activity)
$_SESSION['last_activity'] = time();

// الوقت المتبقي = كامل المهلة لأننا جدّدنا last_activity الآن
$timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;

echo json_encode([
    'ok' => true,
    'expires_in' => $timeout,
    'timeout' => $timeout
], JSON_UNESCAPED_UNICODE);
