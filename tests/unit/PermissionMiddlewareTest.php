<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class PermissionMiddlewareTest extends TestCase
{
    private array $origSession;

    protected function setUp(): void
    {
        $this->origSession = $_SESSION ?? [];
        $_SESSION = ['user' => ['role' => 'group_admin', 'school_id' => 1]];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->origSession;
    }

    public function testCheckPageAccessGroupAdminPasses(): void
    {
        $this->assertTrue(checkPageAccess('group_admin'));
    }

    public function testCheckPageAccessSchoolAdminFailsForGuest(): void
    {
        $_SESSION['user']['role'] = 'guest';
        $this->assertFalse(checkPageAccess('school_admin'));
    }

    public function testCheckPageAccessSchoolAdminPassesForSchoolAdmin(): void
    {
        $_SESSION['user']['role'] = 'school_admin';
        $this->assertTrue(checkPageAccess('school_admin'));
    }

    public function testCheckSchoolOwnershipOwnSchool(): void
    {
        $_SESSION['user']['role'] = 'school_admin';
        $_SESSION['user']['school_id'] = 5;
        $this->assertTrue(checkSchoolOwnership(5));
    }

    public function testCheckSchoolOwnershipGroupAdminCanAccessAny(): void
    {
        $_SESSION['user']['role'] = 'group_admin';
        $this->assertTrue(checkSchoolOwnership(999));
    }
}
