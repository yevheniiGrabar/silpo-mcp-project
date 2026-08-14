<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ZoryanaAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    /** POST /api/assistant — повідомлення до Зоряни (Claude) → текстова відповідь. */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $reply = (string) (new ZoryanaAgent)->prompt($data['message']);

            return response()->json(['reply' => trim($reply)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
