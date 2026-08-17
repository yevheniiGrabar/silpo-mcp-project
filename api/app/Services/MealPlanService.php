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
            'diet_system' => $dto['diet_system'] ?? 'omnivore',
            'cuisines' => $dto['cuisines'] ?? [],
            'health_filters' => $dto['health_filters'] ?? [],
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
            // Структурований вивід laravel/ai лежить у $response->structured (['days'=>...]).
            $response = (new MealPlannerAgent)->prompt($this->userPrompt($plan));
            $menu = is_array($response->structured ?? null) ? $response->structured : [];

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

    /**
     * Унікальні інгредієнти по всьому тижню зі структурою для матчингу.
     *
     * @return array<int, array{name:string, category:?string, search:array}>
     */
    private function aggregateIngredients(array $menu): array
    {
        $byName = [];
        foreach ($menu['days'] ?? [] as $day) {
            foreach ($day['meals'] ?? [] as $meal) {
                foreach ($meal['ingredients'] ?? [] as $ing) {
                    $name = mb_strtolower(trim((string) ($ing['name'] ?? '')));
                    if ($name === '' || isset($byName[$name])) {
                        continue;
                    }
                    $byName[$name] = [
                        'name' => $name,
                        'category' => isset($ing['category']) ? (string) $ing['category'] : null,
                        'search' => array_values(array_filter((array) ($ing['search'] ?? []))),
                    ];
                }
            }
        }

        return array_values($byName);
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
            'old_price' => $item->old_price,
            'is_promo' => $item->is_promo,
            'is_private_label' => $item->is_private_label,
            'confidence' => $item->match_confidence,
        ];

        $this->plans->updateItem($item, [
            'silpo_product_id' => $target['sku'],
            'title' => $target['title'],
            'price' => $target['price'],
            'old_price' => $target['old_price'] ?? null,
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
            'old_price' => $c->oldPrice,
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
            'old_price' => $c->oldPrice,
            'price_total' => $c->price,
            'is_promo' => $c->isPromo,
            'is_private_label' => $c->isPrivateLabel,
            'match_confidence' => $c->confidence,
        ];
    }

    private function userPrompt(MealPlan $p): string
    {
        $appliances = implode(', ', $p->appliances ?? []) ?: 'плита';
        $allergies = implode(', ', $p->allergies ?? []) ?: 'немає';
        $cuisines = implode(', ', $p->cuisines ?? []) ?: 'без переваг';
        $health = implode(', ', $p->health_filters ?? []) ?: 'немає';
        $diet = $this->dietLabel($p->diet_system);
        $flex = $p->mode === 'quality' && ($p->budget_flex_pct ?? 0) > 0
            ? " (+ до {$p->budget_flex_pct}% зверху дозволено)" : '';

        return <<<TXT
        Режим: {$p->mode}. Філія: {$p->branch_id}. Людей: {$p->people}.
        Бюджет-орієнтир: {$p->budget} ₴/тиждень{$flex}.
        Система харчування (ЖОРСТКЕ правило): {$diet}.
        Бажані кухні (м'яке вподобання): {$cuisines}.
        Здорові фільтри (цілі складу): {$health}.
        Алергії/виключення (НІКОЛИ не додавай навіть слідів): {$allergies}.
        Доступна техніка: {$appliances}. Ліміт часу на страву: {$p->max_cook_minutes} хв.
        Склади меню на тиждень СУВОРО за системою харчування та правилами. Спочатку перевір сьогоднішні акції.
        TXT;
    }

    /** Код системи харчування → людський опис для промпту. */
    private function dietLabel(?string $s): string
    {
        return match ($s) {
            'vegetarian' => 'вегетаріанське (без м’яса, птиці, риби та морепродуктів; молочка, яйця, мед — дозволені)',
            'vegan' => 'веганське (ЖОДНИХ продуктів тваринного походження: без м’яса, риби, яєць, молока, сиру, масла, меду, желатину)',
            'pescetarian' => 'пескетаріанське (риба і морепродукти дозволені; м’ясо і птиця — ні)',
            'keto' => 'кето / низьковуглеводне (мінімум вуглеводів; без цукру, борошна, хліба, круп, картоплі, солодких фруктів; акцент на білок і корисні жири)',
            'paleo' => 'палео (без круп, бобових, молочних продуктів, цукру та оброблених продуктів; м’ясо, риба, яйця, овочі, фрукти, горіхи)',
            default => 'звичайне (без обмежень)',
        };
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
            $data = $this->silpo->callData('silpo_list_branches');
            foreach (($data['branches'] ?? []) as $b) {
                // перша реальна відкрита філія з самовивозом
                if (($b['open'] ?? false) === true && ($b['hasPickup'] ?? false) === true) {
                    return (string) ($b['branchId'] ?? $b['id'] ?? $given);
                }
            }
            $first = ($data['branches'] ?? [])[0] ?? null;
            if (is_array($first)) {
                return (string) ($first['branchId'] ?? $first['id'] ?? $given);
            }
        } catch (Throwable) {
            // Silpo недоступний/не залогінено — лишаємо як є (впаде вище з 401).
        }

        return $given;
    }
}
