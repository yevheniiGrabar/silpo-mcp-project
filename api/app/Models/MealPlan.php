<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    protected $fillable = [
        'user_id', 'branch_id', 'budget', 'people', 'shopping_days', 'diet_style', 'diet_system',
        'cuisines', 'health_filters', 'mode',
        'budget_flex_pct', 'appliances', 'max_cook_minutes', 'allergies',
        'status', 'currency', 'plan_json', 'naive_total', 'optimized_total',
        'savings', 'error',
    ];

    protected function casts(): array
    {
        return [
            'appliances' => 'array',
            'allergies' => 'array',
            'cuisines' => 'array',
            'health_filters' => 'array',
            'plan_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
