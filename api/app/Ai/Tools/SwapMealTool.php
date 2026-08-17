<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MealSwapAgent;
use App\Models\User;
use App\Services\MealPlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Action: замінює ОДНУ страву в меню на альтернативу (дешевшу/кориснішу)
 * і перезбирає список. Оновлення видно в застосунку (live-refresh).
 */
class SwapMealTool implements Tool
{
    private const TYPES = ['breakfast' => 'сніданок', 'lunch' => 'обід', 'dinner' => 'вечерю'];

    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'swap_meal';
    }

    public function description(): Stringable|string
    {
        return 'Замінює ОДНУ страву (сніданок/обід/вечеря) у меню користувача на альтернативу (дешевшу/кориснішу/іншу) і оновлює список покупок. Викликай на прохання «заміни вечерю/обід/сніданок».';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['breakfast', 'lunch', 'dinner'])->required(),
            'weekday' => $schema->integer()->min(1)->max(7),
            'goal' => $schema->string()->enum(['cheaper', 'healthier', 'variety']),
        ];
    }

    public function handle(Request $request): string
    {
        $plan = $this->user->mealPlans()->where('status', 'ready')->latest()->first();
        $menu = $plan?->plan_json ?? [];
        if (empty($menu['days'])) {
            return 'Меню ще не складено. Підкажи натиснути «Скласти меню».';
        }

        $args = $request->all();
        $type = (string) ($args['type'] ?? '');
        $goal = (string) ($args['goal'] ?? 'cheaper');
        $weekday = isset($args['weekday']) ? (int) $args['weekday'] : (int) now()->isoWeekday();

        // Знаходимо день (за weekday або перший) і страву за типом.
        $dayIdx = collect($menu['days'])->search(fn ($d) => (int) ($d['weekday'] ?? 0) === $weekday);
        if ($dayIdx === false) {
            $dayIdx = 0;
        }
        $mealIdx = collect($menu['days'][$dayIdx]['meals'] ?? [])->search(fn ($m) => ($m['type'] ?? '') === $type);
        if ($mealIdx === false) {
            return 'Не знайшла такий прийом їжі в меню.';
        }

        $old = (string) ($menu['days'][$dayIdx]['meals'][$mealIdx]['title'] ?? 'страву');
        $allergies = implode(', ', $plan->allergies ?? []) ?: 'немає';

        try {
            $resp = (new MealSwapAgent)->prompt(
                "Заміни {$type} «{$old}». Система харчування: {$plan->diet_system}. "
                ."Алергії (НІКОЛИ не додавай): {$allergies}. Мета: {$goal}.",
                timeout: 30,
            );
            $new = is_array($resp->structured ?? null) ? $resp->structured : null;
        } catch (\Throwable) {
            $new = null;
        }

        if ($new === null || empty($new['title']) || empty($new['ingredients'])) {
            return 'Не вдалося підібрати заміну. Спробуй ще раз або зміни через застосунок.';
        }

        // Підставляємо нову страву (зберігаємо тип) і перезбираємо кошик.
        $new['type'] = $type;
        $menu['days'][$dayIdx]['meals'][$mealIdx] = $new;
        app(MealPlanService::class)->rebuildCart($plan, $menu);
        app(ToolActionContext::class)->touch($plan->id);

        $typeUa = self::TYPES[$type] ?? 'страву';

        return "Заміняю {$typeUa}: замість «{$old}» → «{$new['title']}». Оновила меню та список 👌";
    }
}
