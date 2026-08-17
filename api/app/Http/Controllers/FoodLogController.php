<?php

namespace App\Http\Controllers;

use App\Support\ResolvesCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodLogController extends Controller
{
    use ResolvesCurrentUser;

    /** POST /api/food-logs — записати з'їдену порцію (подія щоденника). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'grams' => ['nullable', 'integer', 'min:0'],
            'kcal' => ['required', 'integer', 'min:0'],
            'protein' => ['nullable', 'integer', 'min:0'],
            'fat' => ['nullable', 'integer', 'min:0'],
            'carbs' => ['nullable', 'integer', 'min:0'],
            'logged_at' => ['nullable', 'date'],
        ]);

        $log = $this->currentUser()->foodLogs()->create([
            'title' => $data['title'],
            'grams' => $data['grams'] ?? 0,
            'kcal' => $data['kcal'],
            'protein' => $data['protein'] ?? 0,
            'fat' => $data['fat'] ?? 0,
            'carbs' => $data['carbs'] ?? 0,
            'logged_at' => $data['logged_at'] ?? now(),
        ]);

        return response()->json(['data' => $log], 201);
    }
}
