<?php
require_once __DIR__ . '/includes/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

echo "الطلاب في المدرسة 3:\n";
echo "===================\n";

$stmt = $conn->query("SELECT s.id, s.full_name_ar, s.school_id FROM students s WHERE s.school_id = 3 LIMIT 10");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']} - {$row['full_name_ar']}\n";
}