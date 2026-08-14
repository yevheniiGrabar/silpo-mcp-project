<?php

namespace Tests\Unit;

use App\Services\Budget\BudgetOptimizerService;
use App\Services\Budget\Candidate;
use PHPUnit\Framework\TestCase;

class BudgetOptimizerTest extends TestCase
{
    private function optimizer(): BudgetOptimizerService
    {
        return new BudgetOptimizerService;
    }

    public function test_economy_picks_cheapest_and_computes_savings(): void
    {
        $candidates = [
            new Candidate('молоко', 'sku-premium', 'Молоко преміум', 45, isPromo: false, isPrivateLabel: false, confidence: 0.9),
            new Candidate('молоко', 'sku-vm', 'Молоко Власна марка', 29, isPromo: true, isPrivateLabel: true, confidence: 0.8),
            new Candidate('рис', 'sku-premium', 'Рис преміум', 60, confidence: 0.9),
            new Candidate('рис', 'sku-base', 'Рис звичайний', 40, confidence: 0.85),
        ];

        $r = $this->optimizer()->optimize($candidates, budget: 200, mode: 'economy');

        $this->assertSame(105, $r['naive_total']);      // 45 + 60 (перші кандидати)
        $this->assertSame(69, $r['optimized_total']);   // 29 + 40 (найдешевші)
        $this->assertSame(36, $r['savings']);
        $this->assertTrue($r['within_budget']);
    }

    public function test_quality_prefers_quality_over_price_and_applies_flex(): void
    {
        $candidates = [
            new Candidate('молоко', 'a', 'Дешеве', 29, confidence: 0.6),
            new Candidate('молоко', 'b', 'Якісний бренд', 45, confidence: 0.95),
        ];

        $r = $this->optimizer()->optimize($candidates, budget: 40, mode: 'quality', flexPct: 30);

        $this->assertSame('b', $r['items'][0]->sku);    // обрало якісніше, а не дешевше
        $this->assertSame(52, $r['effective_limit']);   // 40 * 1.30
        $this->assertTrue($r['within_budget']);         // 45 <= 52
    }

    public function test_economy_flags_over_budget(): void
    {
        $candidates = [
            new Candidate('ікра', 'x', 'Ікра', 500, confidence: 0.9),
        ];

        $r = $this->optimizer()->optimize($candidates, budget: 100, mode: 'economy');

        $this->assertFalse($r['within_budget']);
        $this->assertSame(500, $r['optimized_total']);
    }
}
