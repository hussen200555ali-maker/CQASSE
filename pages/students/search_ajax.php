<?php
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$userRole = $_SESSION['user']['role'] ?? '';
$schoolId = $_SESSION['user']['school_id'] ?? null;

try {
    $params = ['%' . $q . '%', '%' . $q . '%'];
    $sql = "SELECT s.id, s.full_name_ar AS name_ar, s.student_number AS student_id, sc.name_ar AS school_name
            FROM students s
            LEFT JOIN schools sc ON s.school_id = sc.id
            WHERE s.is_active = 1 AND (s.full_name_ar LIKE ? OR s.student_number LIKE ?)";

    if (in_array($userRole, ['school_admin', 'teacher']) && $schoolId) {
        $sql .= " AND s.school_id = " . (int)$schoolId;
    }

    $sql .= " LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results);
} catch (Exception $e) {
    echo json_encode([]);
}
