<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'meal_plan_id', 'ingredient', 'silpo_product_id', 'title', 'qty',
        'price', 'old_price', 'pack_size', 'leftover', 'reason', 'price_total',
        'is_promo', 'is_private_label', 'match_confidence', 'alt_options',
    ];

    protected function casts(): array
    {
        return [
            'is_promo' => 'boolean',
            'is_private_label' => 'boolean',
            'match_confidence' => 'float',
            'alt_options' => 'array',
        ];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }
}
