<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** Read-only: поточне меню користувача на тиждень (страви + орієнтовні калорії). */
class GetMyPlanTool implements Tool
{
    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'get_my_plan';
    }

    public function description(): Stringable|string
    {
        return 'Повертає поточне згенероване меню користувача на тиждень (страви по днях + орієнтовні калорії). Виклич, коли питають про своє меню чи страви цього тижня.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $plan = $this->user->mealPlans()->where('status', 'ready')->latest()->first();
        $days = $plan?->plan_json['days'] ?? [];

        if (empty($days)) {
            return 'Меню ще не складено. Підкажи натиснути «Скласти меню» у Налаштуваннях.';
        }

        $names = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];
        $lines = [];
        foreach ($days as $d) {
            $wd = (int) ($d['weekday'] ?? 0);
            $meals = collect($d['meals'] ?? [])
                ->map(fn ($m) => ($m['title'] ?? '').' (~'.($m['kcal'] ?? '?').' ккал)')
                ->implode(', ');
            $lines[] = ($names[$wd] ?? '?').': '.$meals;
        }

        return "Меню на тиждень:\n".implode("\n", $lines);
    }
}
