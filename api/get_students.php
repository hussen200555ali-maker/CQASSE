<?php
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

$classId = intval($_GET['class_id'] ?? 0);
if ($classId <= 0) {
    echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    // التحقق من أن الفصل ينتمي لمدرسة المستخدم (لمنع استكشاف فصول مدارس أخرى)
    if ($schoolId && $userRole !== 'group_admin') {
        $stmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
        $stmt->execute([$classId, $schoolId]);
        if (!$stmt->fetch()) {
            echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // تقييد المعلم: يرى فقط طلاب فصوله
    if ($userRole === 'teacher') {
        $allowedIds = getTeacherAssignedClassIds($db, $_SESSION['user']['id'], $schoolId);
        if (!in_array($classId, $allowedIds)) {
            echo json_encode(['students' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $sql = "SELECT s.id, s.student_number, s.full_name_ar
            FROM students s
            WHERE s.is_active = 1
              AND EXISTS (
                  SELECT 1 FROM student_enrollments se
                  WHERE se.student_id = s.id
                    AND se.class_id = ?
                    AND se.status = 'enrolled'
                    AND se.is_active = 1
              )";
    $params = [$classId];

    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY s.full_name_ar";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    echo json_encode(['students' => $students], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'حدث خطأ في تحميل الطلاب'], JSON_UNESCAPED_UNICODE);
}
