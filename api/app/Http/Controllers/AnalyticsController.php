<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    /** GET /api/analytics — агрегати для сторінки «Аналітика». */
    public function index(AnalyticsService $analytics): JsonResponse
    {
        $user = $this->currentUser();

        return response()->json(['data' => $analytics->forUser($user)]);
    }

    private function currentUser(): User
    {
        return Auth::user() ?? User::firstOrCreate(
            ['email' => 'demo@mealize.app'],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );
    }
}
