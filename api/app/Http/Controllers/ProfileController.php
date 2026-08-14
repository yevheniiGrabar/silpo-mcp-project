<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Silpo\SilpoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    /** GET /api/me — профіль + статус підключення Сільпо + prefill (родина/дієта). */
    public function show(SilpoClient $silpo): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user() ?? User::where('email', 'demo@mealize.app')->first();

        $connected = $user?->silpoToken()->exists() ?? false;

        $prefill = null;
        if ($connected) {
            try {
                $prefill = [
                    'family' => $silpo->call('silpo_get_my_family')->structuredContent ?? null,
                    'restrictions' => $silpo->call('silpo_get_my_food_restrictions')->structuredContent ?? null,
                ];
            } catch (\Throwable) {
                $prefill = null; // не валимо профіль, якщо Сільпо недоступний
            }
        }

        return response()->json([
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            'silpo_connected' => $connected,
            'connect_url' => url('/mcp/silpo/connect'),
            'prefill' => $prefill,
        ]);
    }
}
