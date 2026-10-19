<?php
/**
 * ============================================================
 * API - ربط ولي أمر بالطالب
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 * ينشئ رابطاً جديداً في جدول parent_students
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

$parentId  = intval($_POST['parent_id'] ?? 0);
$studentId = intval($_POST['student_id'] ?? 0);
$relationship = $_POST['relationship'] ?? 'father';

$validRelationships = ['father', 'mother', 'guardian', 'other'];
if (!in_array($relationship, $validRelationships)) {
    $relationship = 'father';
}

if ($parentId <= 0 || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    // التحقق من وجود ولي الأمر
    $stmt = $db->prepare("SELECT id, full_name_ar FROM users WHERE id = ? AND role_id = 4 AND is_active = 1");
    $stmt->execute([$parentId]);
    $parent = $stmt->fetch();

    if (!$parent) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ولي الأمر غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من وجود الطالب ونطاق المدرسة
    $sql = "SELECT id, full_name_ar, school_id FROM students WHERE id = ? AND is_active = 1";
    $params = [$studentId];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $student = $stmt->fetch();

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'الطالب غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من نطاق المدرسة
    if ($schoolId && $userRole !== 'group_admin' && $student['school_id'] != $schoolId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'الطالب ليس في مدرستك'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من عدم وجود رابط مكرر
    $stmt = $db->prepare("SELECT id FROM parent_students WHERE parent_id = ? AND student_id = ?");
    $stmt->execute([$parentId, $studentId]);
    if ($stmt->fetch()) {
        // يوجد رابط بالفعل — تفعيله إذا كان غير نشط
        $stmt = $db->prepare("UPDATE parent_students SET is_active = 1, relationship = ? WHERE parent_id = ? AND student_id = ?");
        $stmt->execute([$relationship, $parentId, $studentId]);
        echo json_encode(['success' => true, 'message' => 'تم تحديث الرابط', 'parent_name' => $parent['full_name_ar'], 'student_name' => $student['full_name_ar']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // إنشاء الرابط
    $stmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship, is_active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$parentId, $studentId, $relationship]);

    // تسجيل النشاط
    logActivity($db, $_SESSION['user']['id'], 'link_parent', "ربط ولي الأمر \"{$parent['full_name_ar']}\" بالطالب \"{$student['full_name_ar']}\"", 'parent_student', $db->lastInsertId());

    // إشعار ولي الأمر
    $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read) VALUES (?, 'parent_link', ?, ?, 'student', ?, 0)");
    $notifStmt->execute([
        $parentId,
        'ربط بالطالب',
        "تم ربطك بالطالب: {$student['full_name_ar']}. يمكنك الآن متابعة درجاته وسلوكه وحضوره.",
        $studentId
    ]);

    // إشعار مدراء المدرسة
    $adminStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND role_id = 2 AND is_active = 1");
    $adminStmt->execute([$student['school_id']]);
    $adminIds = $adminStmt->fetchAll(\PDO::FETCH_COLUMN);
    $adminNotif = $db->prepare("INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read) VALUES (?, 'parent_link', ?, ?, 'student', ?, 0)");
    foreach ($adminIds as $adminId) {
        $adminNotif->execute([
            $adminId,
            'ربط ولي أمر',
            "تم ربط ولي الأمر \"{$parent['full_name_ar']}\" بالطالب \"{$student['full_name_ar']}\"",
            $studentId
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'تم ربط ولي الأمر بالطالب بنجاح',
        'parent_name' => $parent['full_name_ar'],
        'student_name' => $student['full_name_ar']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Link Parent API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في الربط'], JSON_UNESCAPED_UNICODE);
}
