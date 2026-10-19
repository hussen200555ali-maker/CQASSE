<?php
session_start();

echo "Session ID: " . session_id() . PHP_EOL;
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . PHP_EOL;
if (isset($_SESSION['user'])) {
    echo "User: " . print_r($_SESSION['user'], true) . PHP_EOL;
}
echo "Academic Year ID: " . ($_SESSION['academic_year_id'] ?? 'NOT SET') . PHP_EOL;
echo "Current Semester ID: " . ($_SESSION['current_semester_id'] ?? 'NOT SET') . PHP_EOL;
echo "School ID: " . ($_SESSION['school_id'] ?? 'NOT SET') . PHP_EOL;
?>