<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** Read-only: що користувач з'їв сьогодні + скільки калорій лишилось до цілі. */
class GetTodayDiaryTool implements Tool
{
    private const GOAL_KCAL = 1900;

    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'get_today_diary';
    }

    public function description(): Stringable|string
    {
        return 'Повертає, що користувач з\'їв сьогодні та скільки калорій лишилось до денної цілі. Виклич для питань про калорії/раціон СЬОГОДНІ.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $logs = $this->user->foodLogs()->whereDate('logged_at', today())->get();
        $kcal = (int) $logs->sum('kcal');
        $left = max(0, self::GOAL_KCAL - $kcal);
        $items = $logs->pluck('title')->implode(', ');

        return "Сьогодні з'їдено: {$kcal} ккал з ".self::GOAL_KCAL." (лишилось {$left})."
            .($items !== '' ? " Записи: {$items}." : ' Записів ще немає.');
    }
}
