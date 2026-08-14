<?php

namespace App\Http\Controllers;

use App\Services\Silpo\SilpoClient;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /** GET /api/branches — список філій Сільпо (silpo_list_branches). */
    public function index(SilpoClient $silpo): JsonResponse
    {
        try {
            $raw = $silpo->call('silpo_list_branches')->structuredContent ?? [];
            $list = $raw['branches'] ?? $raw['results'] ?? $raw;

            $branches = collect($list)->map(fn ($b) => [
                'id' => (string) ($b['id'] ?? $b['branchId'] ?? ''),
                'name' => (string) ($b['name'] ?? $b['title'] ?? ''),
                'address' => (string) ($b['address'] ?? ''),
            ])->values();

            return response()->json(['data' => $branches]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
