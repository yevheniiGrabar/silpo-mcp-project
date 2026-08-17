<?php

namespace Tests\Feature;

use App\Services\Silpo\MatchMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_learns_from_swaps_and_returns_most_chosen(): void
    {
        $m = new MatchMemory;

        // Користувач двічі обрав крупу і раз — борошно (case-insensitive ключ).
        $m->remember('Гречка', 'sku-groats', 'Крупа гречана');
        $m->remember('гречка', 'sku-groats', 'Крупа гречана');
        $m->remember('гречка', 'sku-flour', 'Борошно гречане');

        $prefs = $m->preferredSkus(['Гречка']);

        $this->assertSame('sku-groats', $prefs['гречка']); // найбільше hits перемагає
    }

    public function test_returns_empty_for_unknown_ingredient(): void
    {
        $this->assertSame([], (new MatchMemory)->preferredSkus(['невідомий інгредієнт']));
    }
}
