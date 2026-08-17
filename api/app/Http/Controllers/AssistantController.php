<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ZoryanaAgent;
use App\Ai\Tools\ToolActionContext;
use App\Support\ResolvesCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    use ResolvesCurrentUser;

    /** POST /api/assistant — повідомлення до Зоряни (Claude) → текстова відповідь. */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $reply = (string) (new ZoryanaAgent($this->currentUser()))
                ->prompt($this->buildPrompt($data['message'], $data['history'] ?? []));

            // Якщо тул змінив/створив план — віддаємо id, щоб застосунок перечитав меню.
            $planId = app(ToolActionContext::class)->planId;

            return response()->json(['reply' => trim($reply), 'plan_id' => $planId]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Зоряна тимчасово недоступна'], 503);
        }
    }

    /** Останні репліки як контекст діалогу (пам'ять) + поточне повідомлення. */
    private function buildPrompt(string $message, array $history): string
    {
        if ($history === []) {
            return $message;
        }

        $lines = [];
        foreach (array_slice($history, -10) as $h) {
            $who = ($h['role'] ?? 'user') === 'assistant' ? 'Зоряна' : 'Гість';
            $lines[] = "{$who}: ".trim((string) ($h['text'] ?? ''));
        }
        $lines[] = 'Гість: '.$message;

        return implode("\n", $lines);
    }
}
