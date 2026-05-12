<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class BehaviorCalculatorTest extends TestCase
{
    private BehaviorCalculator $calc;
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        require_once __DIR__ . '/../../includes/behavior_calculator.php';
        $this->calc = new BehaviorCalculator($this->db);
    }

    public function testInitializeBehaviorScore(): void
    {
        $this->db->exec("INSERT INTO schools (id, name_ar, code, school_type) VALUES (1, 'مدرسة', 'SCH', 'primary')");
        $this->db->exec("INSERT INTO academic_years (id, name, is_active) VALUES (1, '2025-2026', 1)");
        $this->db->exec("INSERT INTO semesters (id, academic_year_id, name_ar, is_active) VALUES (1, 1, 'فصل', 1)");
        $this->db->exec("INSERT INTO students (id, school_id, full_name_ar, is_active) VALUES (1, 1, 'طالب', 1)");
        $this->db->exec("INSERT INTO classes (id, name_ar, academic_year_id, school_id) VALUES (1, 'فصل', 1, 1)");

        $result = $this->calc->initializeBehaviorScore(1, 1, 1, 1);
        $this->assertTrue($result['success']);
    }

    public function testBehaviorStartingPointsDefault(): void
    {
        $ref = new ReflectionClass($this->calc);
        $prop = $ref->getProperty('startingPoints');
        $prop->setAccessible(true);
        $this->assertEquals(100, $prop->getValue($this->calc));
    }

    public function testBehaviorMaxPoints(): void
    {
        $ref = new ReflectionClass($this->calc);
        $prop = $ref->getProperty('maxPoints');
        $prop->setAccessible(true);
        $this->assertEquals(120, $prop->getValue($this->calc));
    }

    public function testBehaviorPassingThreshold(): void
    {
        $ref = new ReflectionClass($this->calc);
        $prop = $ref->getProperty('passingThreshold');
        $prop->setAccessible(true);
        $this->assertEquals(60, $prop->getValue($this->calc));
    }
}
