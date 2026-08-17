<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMealPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget' => ['required', 'integer', 'min:200', 'max:100000'],
            'mode' => ['required', 'in:economy,quality'],
            'budget_flex_pct' => ['nullable', 'integer', 'min:0', 'max:50'],
            'people' => ['nullable', 'integer', 'min:1', 'max:10'],
            'diet_style' => ['nullable', 'in:pp,protein,veggie,budget,surprise'], // legacy, back-compat
            'diet_system' => ['nullable', 'in:omnivore,vegetarian,vegan,pescetarian,keto,paleo'],
            'cuisines' => ['nullable', 'array'],
            'cuisines.*' => ['string', 'max:32'],
            'health_filters' => ['nullable', 'array'],
            'health_filters.*' => ['string', 'max:32'],
            'appliances' => ['nullable', 'array'],
            'appliances.*' => ['string'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string'],
            'max_cook_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'branch_id' => ['nullable', 'string'],
        ];
    }
}
