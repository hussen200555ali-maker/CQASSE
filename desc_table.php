<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=eqas_db;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query('DESCRIBE improvement_plans');
    echo "<pre>";
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo implode("\t", $row) . "\n";
    }
    echo "</pre>";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}