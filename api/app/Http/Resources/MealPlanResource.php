<?php

namespace App\Http\Resources;

use App\Models\MealPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MealPlan */
class MealPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'mode' => $this->mode,
            'budget' => $this->budget,
            'currency' => $this->currency,
            'people' => $this->people,
            'diet_style' => $this->diet_style,
            'naive_total' => $this->naive_total,
            'optimized_total' => $this->optimized_total,
            'savings' => $this->savings,
            'savings_pct' => $this->naive_total ? (int) round(($this->savings / $this->naive_total) * 100) : null,
            'menu' => $this->plan_json,
            'error' => $this->error,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'ingredient' => $i->ingredient,
                'title' => $i->title,
                'sku' => $i->silpo_product_id,
                'qty' => $i->qty,
                'price' => $i->price,
                'old_price' => $i->old_price,
                'pack_size' => $i->pack_size,
                'leftover' => $i->leftover,
                'saved' => $i->old_price !== null && $i->old_price > $i->price
                    ? ($i->old_price - $i->price) * $i->qty : 0,
                'price_total' => $i->price_total,
                'is_promo' => $i->is_promo,
                'is_private_label' => $i->is_private_label,
                'match_confidence' => $i->match_confidence,
            ])),
        ];
    }
}
