<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Support\ResolvesCurrentUser;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    use ResolvesCurrentUser;

    /** GET /api/analytics — агрегати для сторінки «Аналітика». */
    public function index(AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->forUser($this->currentUser())]);
    }
}
