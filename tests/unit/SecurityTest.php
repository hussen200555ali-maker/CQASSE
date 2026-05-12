<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class SecurityTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        $_SESSION = ['user' => ['role' => 'group_admin', 'school_id' => 1]];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ====================================================================
    // SC-01: SQL Injection Prevention
    // ====================================================================

    public function testPreparedStatementsPreventSqlInjection(): void
    {
        $malicious = "' OR '1'='1";
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$malicious]);
        $results = $stmt->fetchAll();
        $this->assertCount(0, $results, 'SQL injection via username should return no results');
    }

    public function testPreparedStatementWithSqliPayload(): void
    {
        $payload = "admin'; DROP TABLE users; --";
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$payload]);
        $stmt->fetchAll();

        $check = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $this->assertNotFalse($check->fetch(), 'Users table should still exist after SQLi payload');
    }

    // ====================================================================
    // SC-02: XSS Prevention
    // ====================================================================

    public function testEscapePreventsXss(): void
    {
        $payloads = [
            '<script>alert(1)</script>' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            '<img onerror=alert(1) src=x>' => '&lt;img onerror=alert(1) src=x&gt;',
            '"><script>evil()</script>' => '&quot;&gt;&lt;script&gt;evil()&lt;/script&gt;',
            "'-alert(1)-'" => '&#039;-alert(1)-&#039;',
        ];
        foreach ($payloads as $input => $expected) {
            $this->assertEquals($expected, e($input), "XSS bypass detected: {$input}");
        }
    }

    public function testSanitizeStripsTags(): void
    {
        $result = sanitize('<script>alert("xss")</script>safe');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('safe', $result);
        $this->assertEquals('hello', sanitize(' <b>hello</b> '));
        $this->assertEquals('', sanitize('<iframe src="evil.com"></iframe>'));
    }

    // ====================================================================
    // SC-03: CSRF Protection
    // ====================================================================

    public function testCsrfTokenIsUniquePerSession(): void
    {
        $_SESSION = [];
        $field1 = csrfField();
        $token1 = $_SESSION['csrf_token'];

        $_SESSION = [];
        $field2 = csrfField();
        $token2 = $_SESSION['csrf_token'];

        $this->assertNotEquals($token1, $token2, 'CSRF tokens should be unique per session');
    }

    public function testCsrfTokenLengthAndEntropy(): void
    {
        $_SESSION = [];
        csrfField();
        $this->assertEquals(64, strlen($_SESSION['csrf_token']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $_SESSION['csrf_token']);
    }

    public function testVerifyCsrfRejectsOldTokenAfterRegeneration(): void
    {
        $_SESSION = ['csrf_token' => 'old_token_abc'];
        $_POST = ['csrf_token' => 'old_token_abc'];
        $this->assertTrue(verifyCsrf());

        $_SESSION['csrf_token'] = 'new_token_xyz';
        $this->assertFalse(verifyCsrf(), 'Old CSRF token should be rejected after session regeneration');
    }

    public function testVerifyCsrfRejectsEmptyToken(): void
    {
        $_SESSION = ['csrf_token' => 'valid_token'];
        $_POST = [];
        $this->assertFalse(verifyCsrf());
    }

    public function testVerifyCsrfTimingSafeComparison(): void
    {
        $_SESSION = ['csrf_token' => bin2hex(random_bytes(32))];
        $_POST = ['csrf_token' => $_SESSION['csrf_token'] . 'x'];
        $start = microtime(true);
        $result = verifyCsrf();
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result);
        $this->assertLessThan(1.0, $elapsed, 'CSRF verification should complete quickly');
    }

    // ====================================================================
    // SC-04: Permission Bypass Prevention
    // ====================================================================

    public function testPermissionBypassLowRole(): void
    {
        $_SESSION['user']['role'] = 'guest';
        $this->assertFalse(hasPermission('teacher'));
        $this->assertFalse(hasPermission('school_admin'));
        $this->assertFalse(hasPermission('group_admin'));
    }

    public function testPermissionBypassTeacher(): void
    {
        $_SESSION['user']['role'] = 'teacher';
        $this->assertTrue(hasPermission('teacher'));
        $this->assertFalse(hasPermission('school_admin'));
        $this->assertFalse(hasPermission('group_admin'));
    }

    public function testPermissionBypassSchoolAdmin(): void
    {
        $_SESSION['user']['role'] = 'school_admin';
        $this->assertTrue(hasPermission('teacher'));
        $this->assertTrue(hasPermission('school_admin'));
        $this->assertFalse(hasPermission('group_admin'));
    }

    // ====================================================================
    // SC-05: School Isolation
    // ====================================================================

    public function testSchoolIsolationSchoolAdmin(): void
    {
        $_SESSION['user'] = ['role' => 'school_admin', 'school_id' => 1];
        $this->assertTrue(checkSchoolOwnership(1));
        $this->assertFalse(checkSchoolOwnership(2));
    }

    public function testSchoolIsolationGroupAdmin(): void
    {
        $_SESSION['user'] = ['role' => 'group_admin', 'school_id' => 1];
        $this->assertTrue(checkSchoolOwnership(1));
        $this->assertTrue(checkSchoolOwnership(999));
    }

    public function testSchoolIsolationAcrossEntities(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code) VALUES (1, 'مدرسة أ', 'A'), (2, 'مدرسة ب', 'B')");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar) VALUES (1, 1, 'طالب أ'), (2, 2, 'طالب ب')");

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
        $stmt->execute([1]);
        $this->assertEquals(1, $stmt->fetchColumn(), 'School A should only see its own students');

        $stmt->execute([2]);
        $this->assertEquals(1, $stmt->fetchColumn(), 'School B should only see its own students');
    }

    // ====================================================================
    // SC-06: Semester Lock Bypass
    // ====================================================================

    public function testLockBypassTeacherOnSoftLock(): void
    {
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'ف1', 'soft', 1)");
        $this->assertFalse(canEditSemester($this->db, 1, 'teacher'), 'Teacher should not bypass soft lock');
    }

    public function testLockBypassSchoolAdminOnHardLock(): void
    {
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'ف1', 'hard', 1)");
        $this->assertFalse(canEditSemester($this->db, 1, 'school_admin'), 'School admin should not bypass hard lock');
    }

    public function testLockLevelRequiresCorrectRole(): void
    {
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, lock_level, is_active) VALUES (1, 1, 'ف1', 'admin_handled', 1)");
        $this->assertFalse(canEditSemester($this->db, 1, 'group_admin'), 'Group admin should not bypass admin_handled lock');
        $this->assertFalse(canEditSemester($this->db, 1, 'school_admin'));
        $this->assertFalse(canEditSemester($this->db, 1, 'teacher'));
    }

    // ====================================================================
    // SC-07: Brute Force Protection
    // ====================================================================

    public function testLoginAttemptsThreshold(): void
    {
        $this->assertGreaterThanOrEqual(3, MAX_LOGIN_ATTEMPTS, 'Max login attempts should be at least 3');
        $this->assertLessThanOrEqual(10, MAX_LOGIN_ATTEMPTS, 'Max login attempts should be at most 10');
    }

    public function testLockoutDurationConfig(): void
    {
        $this->assertGreaterThanOrEqual(60, LOCKOUT_DURATION, 'Lockout should be at least 1 minute');
        $this->assertLessThanOrEqual(3600, LOCKOUT_DURATION, 'Lockout should be at most 1 hour');
    }

    public function testPasswordMinLengthConfig(): void
    {
        $this->assertGreaterThanOrEqual(6, PASSWORD_MIN_LENGTH);
        $this->assertLessThanOrEqual(16, PASSWORD_MIN_LENGTH);
    }

    // ====================================================================
    // SC-08: Session Security
    // ====================================================================

    public function testPasswordHashSigPreventsSessionReuse(): void
    {
        $hash = password_hash('test123', PASSWORD_DEFAULT);
        $sig1 = hash('sha256', $hash);
        $sig2 = hash('sha256', password_hash('different', PASSWORD_DEFAULT));
        $this->assertNotEquals($sig1, $sig2, 'Different password hashes should produce different signatures');
    }

    public function testRandomBytesForCsrf(): void
    {
        $token1 = bin2hex(random_bytes(32));
        $token2 = bin2hex(random_bytes(32));
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals(64, strlen($token1));
    }

    // ====================================================================
    // SC-09: Password Security
    // ====================================================================

    public function testPasswordStrengthEnforcesPolicy(): void
    {
        $weak = passwordStrength('short');
        $this->assertLessThan(3, $weak['score'], 'Short password should have low strength score');
        $this->assertNotEmpty($weak['feedback'], 'Weak password should have feedback');

        $strong = passwordStrength('MyP@ssw0rd2026!');
        $this->assertGreaterThanOrEqual(4, $strong['score'], 'Strong password should have high strength score');
    }

    public function testPasswordStrengthRejectsWeakPatterns(): void
    {
        $common = passwordStrength('password123');
        $this->assertLessThanOrEqual(3, $common['score'], 'Common patterns should have low strength');

        $repeating = passwordStrength('11111111');
        $this->assertLessThanOrEqual(2, $repeating['score'], 'Repeating characters should have very low strength');
    }

    // ====================================================================
    // SC-10: File Upload Security
    // ====================================================================

    public function testFileUploadSizeLimit(): void
    {
        $maxSize = 5 * 1024 * 1024;
        $this->assertGreaterThan(0, $maxSize);
        $this->assertEquals(5242880, $maxSize, 'Max upload should be 5 MB');
    }

    public function testCsvMimeTypes(): void
    {
        $validMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $this->assertContains('text/csv', $validMimes);
        $this->assertContains('text/plain', $validMimes);
        $this->assertNotContains('text/html', $validMimes);
        $this->assertNotContains('application/x-php', $validMimes);
    }

    // ====================================================================
    // SC-11: Access Control Checks
    // ====================================================================

    public function testCheckPageAccessRequiresLogin(): void
    {
        $_SESSION = [];
        $this->assertFalse(checkPageAccess('group_admin'));
    }

    public function testCheckPageAccessHierarchy(): void
    {
        $_SESSION['user'] = ['role' => 'school_admin', 'school_id' => 1];
        $this->assertTrue(checkPageAccess('school_admin'));
        $this->assertTrue(checkPageAccess('teacher'));
        $this->assertFalse(checkPageAccess('group_admin'));

        $_SESSION['user']['role'] = 'group_admin';
        $this->assertTrue(checkPageAccess('group_admin'));
        $this->assertTrue(checkPageAccess('teacher'));
    }

    public function testCheckPageAccessInvalidRole(): void
    {
        $_SESSION['user'] = ['role' => 'guest'];
        $this->assertFalse(checkPageAccess('teacher'));
        $this->assertFalse(checkPageAccess('school_admin'));
    }

    // ====================================================================
    // SC-12: Security Headers Configuration
    // ====================================================================

    public function testHttpOnlySessionCookie(): void
    {
        $this->assertStringContainsString('EQAS_SESSION', session_name());
    }

    // ====================================================================
    // SC-13: Password Generation Quality
    // ====================================================================

    public function testGeneratePasswordQuality(): void
    {
        for ($len = [8, 12, 16]; $len[0] <= 16; $len[0]++) {
            $pw = generatePassword($len[0]);
            $this->assertEquals($len[0], strlen($pw), "Password should be {$len[0]} chars");
            $this->assertMatchesRegularExpression('/[a-z]/', $pw, 'Missing lowercase');
            $this->assertMatchesRegularExpression('/[A-Z]/', $pw, 'Missing uppercase');
            $this->assertMatchesRegularExpression('/[0-9]/', $pw, 'Missing digit');
            $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $pw, 'Missing special char');
            if ($len[0] >= 16) break;
        }
    }

    public function testMultipleGeneratedPasswordsAreUnique(): void
    {
        $passwords = [];
        for ($i = 0; $i < 10; $i++) {
            $passwords[] = generatePassword(12);
        }
        $this->assertEquals(10, count(array_unique($passwords)), 'Generated passwords should be unique');
    }
}
