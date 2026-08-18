<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwapItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_swap_replaces_item_and_recomputes_savings(): void
    {
        $user = User::factory()->create();

        $plan = $user->mealPlans()->create([
            'budget' => 200,
            'mode' => 'economy',
            'status' => 'ready',
            'currency' => 'UAH',
            'naive_total' => 105,
            'optimized_total' => 45,
        ]);

        $item = $plan->items()->create([
            'ingredient' => 'молоко',
            'silpo_product_id' => 'a',
            'title' => 'Молоко дороге',
            'qty' => 1,
            'price' => 45,
            'price_total' => 45,
            'is_promo' => false,
            'is_private_label' => false,
            'match_confidence' => 0.9,
            'alt_options' => [
                ['sku' => 'b', 'title' => 'Молоко Власна марка', 'price' => 29, 'is_promo' => true, 'is_private_label' => true, 'confidence' => 0.8],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/meal-plans/{$plan->id}/items/{$item->id}/swap", ['sku' => 'b']);

        $response->assertOk()
            ->assertJsonPath('data.optimized_total', 29)
            ->assertJsonPath('data.savings', 76); // naive 105 - 29

        $item->refresh();
        $this->assertSame('b', $item->silpo_product_id);
        $this->assertEquals(29, $item->price);
        // Попередня позиція стала альтернативою (можна повернути).
        $this->assertSame('a', $item->alt_options[0]['sku']);
    }

    public function test_cannot_access_other_users_plan(): void
    {
        $owner = User::factory()->create();
        $plan = $owner->mealPlans()->create(['budget' => 200, 'mode' => 'economy', 'status' => 'ready']);
        $item = $plan->items()->create([
            'ingredient' => 'рис', 'silpo_product_id' => 'a', 'title' => 'Рис', 'qty' => 1,
            'price' => 40, 'price_total' => 40, 'alt_options' => [],
        ]);

        $attacker = User::factory()->create();

        // SEC-3: чужий план не видно (IDOR закрито).
        $this->actingAs($attacker)->getJson("/api/meal-plans/{$plan->id}")->assertStatus(404);
        $this->actingAs($attacker)
            ->postJson("/api/meal-plans/{$plan->id}/items/{$item->id}/swap", ['sku' => 'b'])
            ->assertStatus(404);
    }

    public function test_swap_rejects_unknown_sku(): void
    {
        $user = User::factory()->create();
        $plan = $user->mealPlans()->create(['budget' => 200, 'mode' => 'economy', 'status' => 'ready']);
        $item = $plan->items()->create([
            'ingredient' => 'рис', 'silpo_product_id' => 'a', 'title' => 'Рис', 'qty' => 1,
            'price' => 40, 'price_total' => 40, 'alt_options' => [],
        ]);

        $this->actingAs($user)
            ->postJson("/api/meal-plans/{$plan->id}/items/{$item->id}/swap", ['sku' => 'nope'])
            ->assertStatus(422);
    }
}
