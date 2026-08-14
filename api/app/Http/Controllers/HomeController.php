<?php

namespace App\Http\Controllers;

use App\Services\Silpo\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** GET /api/home — акції + «патерн дня тижня» для головного екрана. */
    public function index(Request $request, RecommendationService $rec): JsonResponse
    {
        $branchId = $request->query('branch_id');
        $weekday = (int) date('N'); // 1..7 (пн..нд)

        try {
            return response()->json($rec->home(is_string($branchId) ? $branchId : null, $weekday));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
