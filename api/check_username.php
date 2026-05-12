<?php
/**
 * ============================================================
 * API - التحقق من تفرد اسم المستخدم
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
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
if (!isset($_SESSION['user']['id'])) { // @session-migration-v2.0.3
    http_response_code(401);
    echo json_encode(['error' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = trim($_GET['username'] ?? '');

if (empty($username) || mb_strlen($username) < 3) {
    echo json_encode(['available' => false, 'message' => 'اسم المستخدم قصير جداً'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تفرد اسم المستخدم
$excludeId = intval($_GET['exclude'] ?? 0);

if ($excludeId > 0) {
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $excludeId]);
} else {
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
}

$exists = $stmt->fetch();

echo json_encode([
    'available' => !$exists,
    'message' => $exists ? 'اسم المستخدم مستخدم بالفعل' : 'اسم المستخدم متاح'
], JSON_UNESCAPED_UNICODE);
