<?php

namespace App\Http\Controllers;

use App\Services\Silpo\SilpoClient;
use App\Support\ResolvesCurrentUser;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    use ResolvesCurrentUser;

    /** GET /api/me — профіль + статус підключення Сільпо + prefill (родина/дієта). */
    public function show(SilpoClient $silpo): JsonResponse
    {
        $user = $this->currentUser();

        $connected = $user->silpoToken()->exists();

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
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'silpo_connected' => $connected,
            'connect_url' => url('/mcp/silpo/connect'),
            'prefill' => $prefill,
        ]);
    }
}
