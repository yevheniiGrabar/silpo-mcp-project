<?php

namespace App\Repositories;

use App\Models\CartItem;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Уся робота з Eloquent для меню/кошика (архітектурне правило: БД лише тут).
 */
class MealPlanRepository
{
    public function create(User $user, array $attributes): MealPlan
    {
        return $user->mealPlans()->create($attributes);
    }

    public function find(int $id): ?MealPlan
    {
        return MealPlan::with('items')->find($id);
    }

    public function markStatus(MealPlan $plan, string $status, ?string $error = null): MealPlan
    {
        $plan->update(['status' => $status, 'error' => $error]);

        return $plan;
    }

    public function saveResult(MealPlan $plan, array $totals, array $plan_json): MealPlan
    {
        $plan->update([
            'plan_json' => $plan_json,
            'naive_total' => $totals['naive_total'] ?? null,
            'optimized_total' => $totals['optimized_total'] ?? null,
            'savings' => $totals['savings'] ?? null,
            'status' => 'ready',
        ]);

        return $plan;
    }

    /** @param  array<int, array<string, mixed>>  $items */
    public function replaceItems(MealPlan $plan, array $items): Collection
    {
        $plan->items()->delete();

        return collect($items)->map(fn (array $attrs) => $plan->items()->create($attrs));
    }

    public function findItem(MealPlan $plan, int $itemId): ?CartItem
    {
        return $plan->items()->whereKey($itemId)->first();
    }
}
