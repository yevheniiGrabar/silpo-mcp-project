<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealPlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Action: перезбирає список покупок на N перших днів тижня
 * (меню лишається на тиждень). «Збери список на 2 дні».
 */
class ShoppingDaysTool implements Tool
{
    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'set_shopping_days';
    }

    public function description(): Stringable|string
    {
        return 'Перезбирає список покупок на N перших днів тижня (1–7); меню лишається на тиждень. Викликай на «збери список на 2/3 дні», «купити на пару днів».';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['days' => $schema->integer()->min(1)->max(7)->required()];
    }

    public function handle(Request $request): string
    {
        $plan = $this->user->mealPlans()->where('status', 'ready')->latest()->first();
        if ($plan === null || empty($plan->plan_json['days'])) {
            return 'Меню ще не складено. Підкажи натиснути «Скласти меню».';
        }

        $days = max(1, min(7, (int) ($request->all()['days'] ?? 7)));
        app(MealPlanService::class)->rebuildCart($plan, $plan->plan_json, $days);
        app(ToolActionContext::class)->touch($plan->id);

        return "Зібрала список покупок на {$days} дн. — дивись у вкладці «Список».";
    }
}
