<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class PromotionEngineTest extends TestCase
{
    private PromotionEngine $engine;
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDB();
        require_once __DIR__ . '/../../includes/promotion.php';
        $this->engine = new PromotionEngine($this->db);
    }

    public function testDecisionLabels(): void
    {
        $labels = $this->engine->getDecisionLabels();
        $this->assertArrayHasKey('promoted', $labels);
        $this->assertArrayHasKey('repeated', $labels);
        $this->assertArrayHasKey('conditional', $labels);
        $this->assertArrayHasKey('under_review', $labels);
        $this->assertEquals('مرفّع', $labels['promoted']);
        $this->assertEquals('معيد', $labels['repeated']);
        $this->assertEquals('ترفيع مشروط', $labels['conditional']);
        $this->assertEquals('قيد المراجعة', $labels['under_review']);
    }

    public function testDecisionColorsArray(): void
    {
        $colors = $this->engine->getDecisionColors();
        $this->assertArrayHasKey('promoted', $colors);
        $this->assertIsArray($colors['promoted']);
        $this->assertArrayHasKey('badge', $colors['promoted']);
        $this->assertEquals('success', $colors['promoted']['badge']);
        $this->assertEquals('danger', $colors['repeated']['badge']);
    }

    public function testDefaultSettingsExist(): void
    {
        $settings = $this->engine->getSettings();
        $this->assertIsArray($settings);
    }
}
