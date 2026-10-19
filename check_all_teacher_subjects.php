<?php
require_once __DIR__ . '/includes/database.php';

try {
    // Check all subject_teachers for teacher 2, regardless of is_active
    $stmt = $db->prepare('SELECT st.*, s.name_ar as subject_name, c.name_ar as class_name FROM subject_teachers st JOIN subjects s ON st.subject_id = s.id JOIN classes c ON st.classroom_id = c.id WHERE st.teacher_id = 2');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No subject_teachers records found for teacher ID 2 (any status)" . PHP_EOL;
    } else {
        echo "Found " . count($rows) . " total subject_teachers records for teacher ID 2:" . PHP_EOL;
        foreach ($rows as $row) {
            echo '  - Subject ID ' . $row['subject_id'] . ' (' . $row['subject_name'] . '), Class ID ' . $row['classroom_id'] . ' (' . $row['class_name'] . '), Academic Year: ' . $row['academic_year_id'] . ', Semester: ' . $row['semester_id'] . ', is_active: ' . ($row['is_active'] ? 'YES' : 'NO') . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>