<?php
require_once __DIR__ . '/includes/database.php';

try {
    $stmt = $db->prepare('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.full_name_ar LIKE ?');
    $name = '%فاطمة أحمد الذماري%';
    $stmt->execute([$name]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($users)) {
        echo "No users found matching name: فاطمة أحمد الذماري" . PHP_EOL;
    } else {
        echo "Found " . count($users) . " matching users:" . PHP_EOL;
        foreach ($users as $user) {
            echo '  - ID: ' . $user['id'] . ', Username: ' . $user['username'] . ', Full Name: ' . $user['full_name_ar'] . ', Role: ' . $user['role_name'] . ', School ID: ' . ($user['school_id'] ?? 'NULL') . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>