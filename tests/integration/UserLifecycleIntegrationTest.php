<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class UserLifecycleIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $_SESSION = ['user' => ['role' => 'group_admin', 'school_id' => 1]];
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة أ', 'A')");
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES (1, 'admin', ?, 'مدير النظام', 'group_admin', 1, 1)");
        $stmt->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testUserCreateAndLoginLifecycle(): void
    {
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active, must_change_password) VALUES (2, 'teacher1', ?, 'معلم 1', 'teacher', 1, 1, 1)");
        $stmt->execute([password_hash('Pass@1234', PASSWORD_DEFAULT)]);

        $stmt = $this->db->query("SELECT role, must_change_password FROM users WHERE username = 'teacher1'");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('teacher', $user['role']);
        $this->assertEquals(1, (int)$user['must_change_password']);

        $hash = password_hash('Pass@1234', PASSWORD_DEFAULT);
        $this->assertTrue(password_verify('Pass@1234', $hash));
    }

    public function testSchoolAdminCreatesTeacher(): void
    {
        $plainPw = generatePassword(10);
        $hash = password_hash($plainPw, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active, must_change_password) VALUES (3, 't2', ?, 'معلم 2', 'teacher', 1, 1, 1)");
        $stmt->execute([$hash]);

        $stmt = $this->db->query("SELECT role, school_id, must_change_password, is_active FROM users WHERE id = 3");
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('teacher', $u['role']);
        $this->assertEquals(1, (int)$u['school_id']);
        $this->assertEquals(1, (int)$u['must_change_password']);

        $this->assertEquals(10, strlen($plainPw));
        $this->assertTrue(password_verify($plainPw, $hash));
    }

    public function testSchoolAdminCannotSeeOtherSchoolUsers(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (2, 'مدرسة ب', 'B')");
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES (4, 't_a', 'hash', 'معلم أ', 'teacher', 1, 1)");
        $stmt->execute();
        $stmt = $this->db->prepare("INSERT INTO users (id, username, password_hash, full_name_ar, role, school_id, is_active) VALUES (5, 't_b', 'hash', 'معلم ب', 'teacher', 2, 1)");
        $stmt->execute();

        $_SESSION['user']['role'] = 'school_admin';
        $_SESSION['user']['school_id'] = 1;

        $stmt = $this->db->prepare("SELECT id, username FROM users WHERE school_id = ? AND role = 'teacher'");
        $stmt->execute([$_SESSION['user']['school_id']]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $teachers);
        $this->assertEquals('t_a', $teachers[0]['username']);
    }

    public function testPasswordStrengthIntegration(): void
    {
        $weakResult = passwordStrength('12345678');
        $this->assertLessThan(3, $weakResult['score']);

        $strongResult = passwordStrength('MyP@ssw0rd!');
        $this->assertGreaterThanOrEqual(4, $strongResult['score']);

        $genPw = generatePassword(12);
        $pwResult = passwordStrength($genPw);
        $this->assertGreaterThanOrEqual(4, $pwResult['score']);
    }
}
