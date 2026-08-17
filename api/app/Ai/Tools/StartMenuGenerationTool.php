<?php

namespace App\Ai\Tools;

use App\Jobs\GenerateMealPlanJob;
use App\Models\User;
use App\Services\MealPlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Action: запускає генерацію нового меню за останніми налаштуваннями користувача.
 * Викликати ЛИШЕ після явного підтвердження (правило в промпті Зоряни).
 */
class StartMenuGenerationTool implements Tool
{
    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'start_menu_generation';
    }

    public function description(): Stringable|string
    {
        return 'Запускає генерацію НОВОГО меню на тиждень за останніми налаштуваннями користувача (бюджет/діета/аллергії). Виклич ЛИШЕ після явного «так» від користувача.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $last = $this->user->mealPlans()->latest()->first();
        if ($last === null) {
            return 'Немає попередніх налаштувань. Скористайся «Скласти меню» у Налаштуваннях.';
        }

        $plan = app(MealPlanService::class)->create($this->user, [
            'budget' => $last->budget,
            'mode' => $last->mode,
            'budget_flex_pct' => $last->budget_flex_pct,
            'people' => $last->people,
            'diet_system' => $last->diet_system,
            'cuisines' => $last->cuisines ?? [],
            'health_filters' => $last->health_filters ?? [],
            'appliances' => $last->appliances ?? [],
            'allergies' => $last->allergies ?? [],
        ]);
        GenerateMealPlanJob::dispatch($plan->id);

        return 'Запускаю нове меню за твоїми останніми налаштуваннями — глянь у вкладці «Список» за хвилину.';
    }
}
