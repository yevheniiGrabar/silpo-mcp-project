<?php

namespace Tests\Unit;

use App\Services\Silpo\RecommendationService;
use App\Services\Silpo\SilpoClient;
use App\Services\Silpo\SilpoContextService;
use PHPUnit\Framework\TestCase;

class RecommendationTest extends TestCase
{
    public function test_weekday_pattern_ranks_items_bought_on_that_day(): void
    {
        $svc = new RecommendationService(new SilpoClient, new SilpoContextService(new SilpoClient));

        $orders = [
            ['weekday' => 5, 'items' => ['Пиво', 'Чіпси']],
            ['weekday' => 5, 'items' => ['Пиво', 'Горіхи']],
            ['weekday' => 1, 'items' => ['Молоко']], // інший день — ігнор
        ];

        $pattern = $svc->weekdayPattern($orders, 5);

        $this->assertSame(5, $pattern['weekday']);
        $this->assertSame('пиво', $pattern['items'][0]['name']);
        $this->assertSame(2, $pattern['items'][0]['count']);
        // «молоко» (день 1) не має потрапити у патерн для дня 5
        $this->assertNotContains('молоко', array_column($pattern['items'], 'name'));
    }
}
