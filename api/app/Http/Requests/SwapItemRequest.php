<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwapItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
        ];
    }
}
