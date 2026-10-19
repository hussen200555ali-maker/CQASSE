<?php
require_once __DIR__ . '/../includes/database.php';
$db = Database::getInstance()->getConnection();

echo "=== behavior_items ===\n";
$cols = $db->query("DESCRIBE behavior_items")->fetchAll();
foreach ($cols as $c) {
    echo "  {$c['Field']} {$c['Type']} Null={$c['Null']}\n";
}

echo "\n=== Semester check ===\n";
$sem = $db->query("SELECT id, name_ar, academic_year_id FROM semesters WHERE name_ar = 'الفصل الدراسي الأول' AND is_active = 1 LIMIT 1")->fetch();
if ($sem) {
    echo "Found: id={$sem['id']} year_id={$sem['academic_year_id']}\n";
} else {
    echo "No semester found with name 'الفصل الدراسي الأول'\n";
}

echo "\n=== All semesters ===\n";
$sems = $db->query("SELECT id, name_ar, academic_year_id, is_active FROM semesters")->fetchAll();
foreach ($sems as $s) {
    echo "  id={$s['id']} '{$s['name_ar']}' year={$s['academic_year_id']} active={$s['is_active']}\n";
}
