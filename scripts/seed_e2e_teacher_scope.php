<?php
/**
 * ============================================================
 * ����� ������ ������ E2E � Teacher Scope
 * ����: ����� ���� �����ѡ ���� ���ȡ �������
 * ============================================================
 * �������: php scripts/seed_e2e_teacher_scope.php
 */

try {
    $db = new PDO("mysql:host=localhost;dbname=eqas_db;charset=utf8mb4", "root", "");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $password = password_hash("test1234", PASSWORD_DEFAULT);
    $schoolId = 1;
    $ayId = 2; // 2025-2026 academic year
    $semId = 1;
    $glId = 1;

    // 1. Clean old test data
    $db->exec("DELETE FROM evaluations WHERE class_id IN (900,901,902)");
    $db->exec("DELETE FROM student_enrollments WHERE class_id IN (900,901,902)");
    $db->exec("DELETE FROM students WHERE id >= 900");
    $db->exec("DELETE FROM users WHERE username = 'e2e_tch_scope'");
    $db->exec("DELETE FROM subject_teachers WHERE teacher_id = 999");
    $db->exec("DELETE FROM classes WHERE id IN (900,901,902)");

    // 2. Create teacher user
    $stmt = $db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role_id, school_id, must_change_password, is_active) VALUES (999, 'e2e_tch_scope', ?, '���� ���� E2E', 3, ?, 0, 1)");
    $stmt->execute([$password, $schoolId]);

    // 3. Create classes
    $db->exec("INSERT INTO classes (id, name_ar, school_id, academic_year_id, grade_level_id, teacher_id, is_active) VALUES 
        (900, '��� E2E-�', $schoolId, $ayId, $glId, 999, 1),
        (901, '��� E2E-�', $schoolId, $ayId, $glId, NULL, 1),
        (902, '��� E2E-�', $schoolId, $ayId, $glId, NULL, 1)");

    // 4. Assign teacher to classes 900 and 901
    $db->exec("INSERT INTO subject_teachers (teacher_id, subject_id, classroom_id, school_id, academic_year_id, is_active) VALUES 
        (999, 1, 900, $schoolId, $ayId, 1),
        (999, 2, 901, $schoolId, $ayId, 1)");

    // 5. Create students
    $stmt = $db->prepare("INSERT INTO students (id, school_id, full_name_ar, student_number, grade_level_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->execute([900, $schoolId, '���� E2E-�', 'SN-E2E-900', $glId]);
    $stmt->execute([901, $schoolId, '���� E2E-�', 'SN-E2E-901', $glId]);
    $stmt->execute([902, $schoolId, '���� E2E-�', 'SN-E2E-902', $glId]);

    // 6. Enrollments
    $db->exec("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, enrollment_date, status, is_active) VALUES 
        (900, 900, $ayId, CURDATE(), 'enrolled', 1),
        (901, 901, $ayId, CURDATE(), 'enrolled', 1),
        (902, 902, $ayId, CURDATE(), 'enrolled', 1)");

    // 7. Behavior evaluations (class 900 = assigned, class 902 = unassigned)
    $db->exec("INSERT INTO evaluations (eval_type, student_id, class_id, school_id, academic_year_id, semester_id, evaluated_by, combined_score, is_active) VALUES 
        ('behavior_score', 900, 900, $schoolId, $ayId, $semId, 999, 85, 1),
        ('behavior_score', 902, 902, $schoolId, $ayId, $semId, 999, 65, 1)");

    // 8. Skills evaluations (class 901 = assigned, class 902 = unassigned)
    $db->exec("INSERT INTO evaluations (eval_type, student_id, class_id, school_id, academic_year_id, semester_id, evaluated_by, combined_score, is_active) VALUES 
        ('skills_checklist', 901, 901, $schoolId, $ayId, $semId, 999, 90, 1),
        ('skills_checklist', 902, 902, $schoolId, $ayId, $semId, 999, 70, 1)");

    // 9. Unlock admin
    $db->exec("UPDATE users SET login_attempts = 0, lockout_until = NULL WHERE username = 'admin'");

    // 10. Output eval IDs for E2E tests
    $evalFile = __DIR__ . '/../docs/testing/eval_ids.json';
    $evalDir = dirname($evalFile);
    if (!is_dir($evalDir)) { mkdir($evalDir, 0755, true); }
    $stmt = $db->query("SELECT id, eval_type, class_id FROM evaluations WHERE class_id IN (900,901,902) AND is_active=1");
    $evals = [];
    foreach ($stmt as $row) { $evals[] = ['id' => (int)$row['id'], 'eval_type' => $row['eval_type'], 'class_id' => (int)$row['class_id']]; }
    file_put_contents($evalFile, json_encode($evals, JSON_UNESCAPED_UNICODE));

    echo "E2E test environment seeded successfully.\n";
    echo "Teacher: e2e_tch_scope / test1234\n";
    echo "Admin: admin / 123456\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
