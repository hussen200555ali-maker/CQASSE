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

if (!hasPermission('school_admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$classId = intval($_GET['class_id'] ?? 0);
$academicYearId = intval($_GET['academic_year_id'] ?? 0);

if ($classId <= 0 || $academicYearId <= 0) {
    echo json_encode(['students' => [], 'html' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    $sql = "SELECT s.id, s.student_number, s.full_name_ar, gl.name_ar AS grade_name
            FROM students s
            LEFT JOIN grade_levels gl ON gl.id = s.grade_level_id
            WHERE s.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM student_enrollments se
                  WHERE se.student_id = s.id
                    AND se.class_id = ?
                    AND se.academic_year_id = ?
                    AND se.status = 'enrolled'
                    AND se.is_active = 1
              )";
    $params = [$classId, $academicYearId];

    if ($schoolId && $userRole !== 'group_admin') {
        $sql .= " AND s.school_id = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY s.full_name_ar";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $html = '';
    if (empty($students)) {
        $html = '<div style="text-align:center; padding:30px; color:var(--text-muted);">
                    <div style="font-size:32px; margin-bottom:8px;">📭</div>
                    لا يوجد طلاب متاحون للتسجيل في هذا الفصل
                 </div>';
    } else {
        foreach ($students as $st) {
            $name = e($st['full_name_ar']);
            $num = e($st['student_number']);
            $grade = e($st['grade_name'] ?? '—');
            $html .= '<label style="display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; border-radius:6px; transition:background 0.15s;" onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'transparent\'">
                        <input type="checkbox" name="student_ids[]" value="' . (int)$st['id'] . '" class="student-checkbox" style="width:18px; height:18px; cursor:pointer;">
                        <div>
                            <div style="font-weight:600; font-size:14px;">' . $name . '</div>
                            <div style="font-size:12px; color:var(--text-muted);">' . $num . ' • ' . $grade . '</div>
                        </div>
                      </label>';
        }
    }

    echo json_encode(['students' => $students, 'html' => $html, 'count' => count($students)], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Enrollment Students API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'حدث خطأ في تحميل الطلاب'], JSON_UNESCAPED_UNICODE);
}
