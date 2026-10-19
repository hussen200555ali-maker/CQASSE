<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/installer.php';

/**
 * اختبارات محرّك التثبيت (includes/installer.php) على MySQL حقيقي.
 * تُتخطّى تلقائياً إذا تعذّر الاتصال بـ MySQL.
 */
class InstallerEngineTest extends TestCase
{
    private string $host;
    private string $user;
    private string $pass;
    private array $createdDatabases = [];

    protected function setUp(): void
    {
        $this->host = getenv('EQAS_TEST_DB_HOST') ?: 'localhost';
        $this->user = getenv('EQAS_TEST_DB_USER') ?: 'root';
        $this->pass = getenv('EQAS_TEST_DB_PASS') ?: '';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $db) {
            try {
                $this->root()->exec("DROP DATABASE IF EXISTS `{$db}`");
            } catch (Throwable $e) {
                // ignore cleanup failures
            }
        }
    }

    private function root(): PDO
    {
        return new PDO(
            "mysql:host={$this->host};charset=utf8mb4",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function freshDatabase(): string
    {
        $name = 'eqas_it_' . bin2hex(random_bytes(4));
        try {
            $this->root()->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL غير متاح: ' . $e->getMessage());
        }
        $this->createdDatabases[] = $name;
        return $name;
    }

    private function connect(string $name): PDO
    {
        return new PDO(
            "mysql:host={$this->host};dbname={$name};charset=utf8mb4",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function installConfig(string $db, string $adminUsername): array
    {
        return [
            'db'     => ['host' => $this->host, 'name' => $db, 'user' => $this->user, 'pass' => $this->pass],
            'admin'  => ['username' => $adminUsername, 'password' => 'Str0ngPass!23', 'email' => $adminUsername . '@school.ye', 'full_name' => 'مدير'],
            'school' => ['name' => 'مدرسة اختبار', 'type' => 'mixed', 'address' => 'شارع', 'governorate' => 'صنعاء', 'directorate' => 'مديرية'],
            'year'   => ['name' => '2026-2027', 'start' => '2026-09-01', 'end' => '2027-06-30'],
            'write_config' => false,
            'lock_path'    => null,
        ];
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotNull(eqas_validate_admin_password('Admin@123'));
        $this->assertNotNull(eqas_validate_admin_password('short'));
        $this->assertNotNull(eqas_validate_admin_password('NoDigitsHere'));
        $this->assertNull(eqas_validate_admin_password('Str0ngPass!23'));
    }

    public function testCleanInstall(): void
    {
        $db = $this->freshDatabase();
        $result = eqas_perform_installation($this->installConfig($db, 'mgr'));
        $this->assertTrue($result, is_string($result) ? $result : '');

        $pdo = $this->connect($db);

        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
        $this->assertSame(55, $tables, 'عدد الجداول الأساسية');

        $this->assertSame(4, (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn(), 'الأدوار');
        $this->assertGreaterThanOrEqual(30, (int)$pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn(), 'الصلاحيات');
        $this->assertSame(22, (int)$pdo->query("SELECT COUNT(*) FROM governorates")->fetchColumn(), 'المحافظات');
        $this->assertSame(12, (int)$pdo->query("SELECT COUNT(*) FROM grade_levels")->fetchColumn(), 'الصفوف');
        $this->assertGreaterThanOrEqual(96, (int)$pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn(), 'إعدادات النظام');
        $this->assertSame(10, (int)$pdo->query("SELECT COUNT(*) FROM alert_settings")->fetchColumn(), 'إعدادات التنبيه');

        $user = $pdo->query("SELECT role_id, is_active, must_change_password FROM users WHERE username = 'mgr'")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($user, 'حساب المدير أُنشئ');
        $this->assertSame(1, (int)$user['role_id'], 'دور المدير group_admin');
        $this->assertSame(1, (int)$user['is_active']);

        $defaultAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        $this->assertSame(0, $defaultAdmin, 'لا يوجد حساب admin افتراضي');

        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM academic_years")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM semesters")->fetchColumn());
    }

    public function testRefusesNonEmptyDatabase(): void
    {
        $db = $this->freshDatabase();
        $this->assertTrue(eqas_perform_installation($this->installConfig($db, 'mgr')));

        $second = eqas_perform_installation($this->installConfig($db, 'mgr2'));
        $this->assertIsString($second, 'إعادة التثبيت يجب أن تُرفض على قاعدة غير فارغة');

        $pdo = $this->connect($db);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn(), 'لم تُضف بيانات جديدة');
    }

    public function testSampleDataImportsOnReferenceSchema(): void
    {
        $db = $this->freshDatabase();
        $pdo = $this->connect($db);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['schema.sql', 'seed.sql', 'sample_data.sql'] as $file) {
            $path = dirname(__DIR__, 2) . '/database/' . $file;
            $res = eqas_import_sql_file($pdo, $path, false);
            $this->assertSame([], $res['errors'], "استيراد {$file}");
        }

        $this->assertSame(4, (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn());
        $this->assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM semesters")->fetchColumn());
        $this->assertSame(10, (int)$pdo->query("SELECT COUNT(*) FROM alert_settings")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query("SELECT must_change_password FROM users WHERE username = 'admin'")->fetchColumn(), 'حساب الديمو يطلب تغيير كلمة المرور');
    }
}
