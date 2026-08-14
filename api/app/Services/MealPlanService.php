<?php

namespace App\Services;

use App\Ai\Agents\MealPlannerAgent;
use App\Models\CartItem;
use App\Models\MealPlan;
use App\Models\User;
use App\Repositories\MealPlanRepository;
use App\Services\Budget\BudgetOptimizerService;
use App\Services\Budget\Candidate;
use App\Services\Silpo\ProductMatchingService;
use App\Services\Silpo\SilpoClient;
use App\Services\Silpo\SilpoContextService;
use Throwable;

/**
 * Головний оркестратор генерації меню (docs/01 §4):
 * agent (меню від акцій) → детермінований матчинг → бюджет-оптимізатор → кошик+економія.
 * Синхронно для демо; у проді загорнути у GenerateMealPlanJob (черга).
 */
class MealPlanService
{
    public function __construct(
        private readonly MealPlanRepository $plans,
        private readonly ProductMatchingService $matching,
        private readonly BudgetOptimizerService $optimizer,
        private readonly SilpoContextService $context,
        private readonly SilpoClient $silpo,
    ) {}

    /** Створити план у статусі pending (без важкої генерації). */
    public function create(User $user, array $dto): MealPlan
    {
        return $this->plans->create($user, [
            'branch_id' => $dto['branch_id'] ?? null,
            'budget' => $dto['budget'],
            'people' => $dto['people'] ?? 2,
            'diet_style' => $dto['diet_style'] ?? 'pp',
            'mode' => $dto['mode'] ?? 'economy',
            'budget_flex_pct' => $dto['budget_flex_pct'] ?? 0,
            'appliances' => $dto['appliances'] ?? ['stove', 'oven'],
            'max_cook_minutes' => $dto['max_cook_minutes'] ?? 60,
            'allergies' => $dto['allergies'] ?? [],
            'currency' => 'UAH',
            'status' => 'pending',
        ]);
    }

    /** Синхронно (тести/демо): створити + одразу згенерувати. */
    public function generate(User $user, array $dto): MealPlan
    {
        return $this->run($this->create($user, $dto));
    }

    /** Важка генерація на існуючому плані (викликається з GenerateMealPlanJob). */
    public function run(MealPlan $plan): MealPlan
    {
        $this->plans->markStatus($plan, 'generating');

        try {
            // 0) Реальна філія Сільпо (застосунок може не знати branchId).
            $branchId = $this->resolveBranchId($plan->branch_id);
            if ($branchId !== null && $branchId !== $plan->branch_id) {
                $this->plans->update($plan, ['branch_id' => $branchId]);
                $plan->refresh();
            }

            // 1) Агент будує меню (сам читає акції/профіль через MCP-tools).
            $menu = (new MealPlannerAgent)->prompt($this->userPrompt($plan));
            $menu = is_array($menu) ? $menu : (array) $menu;

            // 2) Агрегуємо унікальні інгредієнти по всьому тижню.
            $ingredients = $this->aggregateIngredients($menu);

            // 3) Детермінований матчинг → кандидати SKU.
            $ctx = $this->deliveryContext($plan);
            $candidates = $this->matching->match($ingredients, $ctx);

            // 4) Оптимізатор бюджету → фінальний кошик + економія.
            $result = $this->optimizer->optimize(
                $candidates,
                budget: $plan->budget,
                mode: $plan->mode,
                flexPct: $plan->budget_flex_pct,
            );

            // 5) Персист (з альтернативами для swap).
            $byIngredient = collect($candidates)->groupBy('ingredient');
            $items = array_map(function (Candidate $c) use ($byIngredient) {
                $alts = $byIngredient->get($c->ingredient, collect())
                    ->reject(fn (Candidate $x) => $x->sku === $c->sku)
                    ->map($this->altShape(...))
                    ->values()->all();

                return $this->toItemAttributes($c) + ['alt_options' => $alts];
            }, $result['items']);

            $this->plans->replaceItems($plan, $items);
            $this->plans->saveResult($plan, $result, $menu);

            return $plan->fresh('items');
        } catch (Throwable $e) {
            return $this->plans->markStatus($plan, 'failed', $e->getMessage());
        }
    }

    /** @return string[] унікальні нормалізовані назви інгредієнтів */
    private function aggregateIngredients(array $menu): array
    {
        $names = [];
        foreach ($menu['days'] ?? [] as $day) {
            foreach ($day['meals'] ?? [] as $meal) {
                foreach ($meal['ingredients'] ?? [] as $ing) {
                    $name = mb_strtolower(trim((string) ($ing['name'] ?? '')));
                    if ($name !== '') {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Замінити позицію кошика на одну з альтернатив (alt_options) і перерахувати економію.
     */
    public function swapItem(MealPlan $plan, CartItem $item, string $sku): MealPlan
    {
        $alts = collect($item->alt_options ?? []);
        $target = $alts->firstWhere('sku', $sku);

        if ($target === null) {
            throw new \InvalidArgumentException('Альтернативу не знайдено');
        }

        // Поточна позиція стає альтернативою (щоб можна було повернути назад).
        $previous = [
            'sku' => $item->silpo_product_id,
            'title' => $item->title,
            'price' => $item->price,
            'is_promo' => $item->is_promo,
            'is_private_label' => $item->is_private_label,
            'confidence' => $item->match_confidence,
        ];

        $this->plans->updateItem($item, [
            'silpo_product_id' => $target['sku'],
            'title' => $target['title'],
            'price' => $target['price'],
            'price_total' => (int) $target['price'] * $item->qty,
            'is_promo' => $target['is_promo'] ?? false,
            'is_private_label' => $target['is_private_label'] ?? false,
            'match_confidence' => $target['confidence'] ?? 1,
            'alt_options' => $alts->reject(fn ($a) => $a['sku'] === $sku)->push($previous)->values()->all(),
        ]);

        // Перерахунок економії.
        $optimized = $this->plans->sumItemsTotal($plan);
        $this->plans->update($plan, [
            'optimized_total' => $optimized,
            'savings' => max(0, ($plan->naive_total ?? $optimized) - $optimized),
        ]);

        return $plan->fresh('items');
    }

    private function altShape(Candidate $c): array
    {
        return [
            'sku' => $c->sku,
            'title' => $c->title,
            'price' => $c->price,
            'is_promo' => $c->isPromo,
            'is_private_label' => $c->isPrivateLabel,
            'confidence' => $c->confidence,
        ];
    }

    private function toItemAttributes(Candidate $c): array
    {
        return [
            'ingredient' => $c->ingredient,
            'silpo_product_id' => $c->sku,
            'title' => $c->title,
            'qty' => 1,
            'price' => $c->price,
            'price_total' => $c->price,
            'is_promo' => $c->isPromo,
            'is_private_label' => $c->isPrivateLabel,
            'match_confidence' => $c->confidence,
        ];
    }

    private function userPrompt(MealPlan $p): string
    {
        $appliances = implode(', ', $p->appliances ?? []);
        $allergies = implode(', ', $p->allergies ?? []) ?: 'немає';
        $flex = $p->mode === 'quality' ? " (+ до {$p->budget_flex_pct}% зверху дозволено)" : '';

        return <<<TXT
        Режим: {$p->mode}. Філія: {$p->branch_id}. Людей: {$p->people}.
        Бюджет-орієнтир: {$p->budget} ₴/тиждень{$flex}.
        Стиль: {$p->diet_style}. Алергії: {$allergies}. Техніка: {$appliances}.
        Ліміт часу на страву: {$p->max_cook_minutes} хв.
        Склади меню на тиждень згідно з правилами. Спочатку перевір сьогоднішні акції.
        TXT;
    }

    /** {branchId, deliveryType, timeslotStart, timeslotEnd} — резолв через ContextService (docs/06). */
    private function deliveryContext(MealPlan $p): array
    {
        return $this->context->resolve($p->branch_id);
    }

    /**
     * Реальний branchId Сільпо. Якщо застосунок прислав пусто/не-числовий id
     * (напр. 'silpo') — беремо першу доступну філію зі silpo_list_branches.
     */
    private function resolveBranchId(?string $given): ?string
    {
        if ($given !== null && ctype_digit($given)) {
            return $given; // вже схоже на реальний id
        }

        try {
            $raw = $this->silpo->call('silpo_list_branches')->structuredContent ?? [];
            $list = $raw['branches'] ?? $raw['results'] ?? $raw;
            if (is_array($list) && $list !== []) {
                $first = reset($list);
                $id = is_array($first) ? ($first['id'] ?? $first['branchId'] ?? null) : null;

                return $id !== null ? (string) $id : $given;
            }
        } catch (Throwable) {
            // Silpo недоступний/не залогінено — лишаємо як є (впаде вище з 401).
        }

        return $given;
    }
}
