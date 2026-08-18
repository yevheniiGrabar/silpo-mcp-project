<?php

namespace App\Services;

use App\Ai\Agents\MealPlannerAgent;
use App\Models\CartItem;
use App\Models\MealPlan;
use App\Models\User;
use App\Repositories\MealPlanRepository;
use App\Services\Budget\BudgetOptimizerService;
use App\Services\Budget\Candidate;
use App\Services\Silpo\MatchMemory;
use App\Services\Silpo\ProductMatchingService;
use App\Services\Silpo\SilpoClient;
use App\Services\Silpo\SilpoContextService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly MatchMemory $matchMemory,
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
            'shopping_days' => $dto['days'] ?? 7, // меню й список — на стільки днів
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
            //    AI-3: з ретраєм на транзієнтних збоях моделі (таймаут/429/5xx/529).
            $menu = $this->generateMenu($plan);

            $this->rebuildCart($plan, $menu);

            return $plan->fresh('items');
        } catch (Throwable $e) {
            return $this->plans->markStatus($plan, 'failed', $e->getMessage());
        }
    }

    /**
     * AI-3: виклик планувальника з ретраєм на транзієнтних збоях (таймаут Anthropic,
     * 429/5xx/529, обрив зʼєднання) та на разовому порожньому меню. Структурований
     * вивід laravel/ai лежить у $response->structured (['days'=>...]).
     */
    private function generateMenu(MealPlan $plan): array
    {
        $attempts = 2;                 // 1 ретрай: покриває разовий блип моделі
        $perAttemptTimeout = 180;      // с; Sonnet на великому меню; 2×180 + backoff у timeout job'а
        $lastError = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = (new MealPlannerAgent)->prompt($this->userPrompt($plan), timeout: $perAttemptTimeout);
                $menu = is_array($response->structured ?? null) ? $response->structured : [];

                // AI-1: порожнє/невалідне меню — помилка, а не «успішний» пустий план.
                if (empty($menu['days'])) {
                    throw new \RuntimeException('Агент не повернув меню');
                }

                return $menu;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($i < $attempts && $this->isTransientAi($e)) {
                    usleep(2_000_000 * $i); // backoff 2с, 4с…
                    continue;
                }
                throw $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Агент не повернув меню');
    }

    /** Транзієнтний збій моделі — варто повторити (мережа/латентність/перевантаження). */
    private function isTransientAi(Throwable $e): bool
    {
        $m = mb_strtolower($e->getMessage());

        foreach ([
            'curl error 28', 'timed out', 'timeout', 'operation timed out',
            'connection', 'reset by peer', 'overloaded', 'try again',
            '429', '500', '502', '503', '504', '529',
            'не повернув меню', // разовий порожній вивід моделі
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Матчинг + оптимізатор + ужим під бюджет → кошик і тотали для готового меню.
     * Використовується і повною генерацією (run), і заміною однієї страви (swap_meal).
     */
    public function rebuildCart(MealPlan $plan, array $menu, ?int $days = null): MealPlan
    {
        // Горизонт покупок: список лише на перші $days днів (меню лишається на тиждень).
        $scopedMenu = $menu;
        if ($days !== null && $days > 0) {
            $scopedMenu['days'] = array_slice($menu['days'] ?? [], 0, $days);
        }

        // Агрегуємо інгредієнти за обраний горизонт (із сумарними к-стями).
        $ingredients = $this->aggregateIngredients($scopedMenu);
        $needByName = [];
        foreach ($ingredients as $ing) {
            $needByName[$ing['name']] = ['qty' => (float) $ing['qty'], 'unit' => (string) $ing['unit']];
        }

        // Детермінований матчинг → кандидати SKU.
        $ctx = $this->deliveryContext($plan);
        $candidates = $this->matching->match($ingredients, $ctx);

        // Оптимізатор бюджету → вибір товару на інгредієнт.
        $result = $this->optimizer->optimize(
            $candidates,
            budget: $plan->budget,
            mode: $plan->mode,
            flexPct: $plan->budget_flex_pct,
        );

        // Кошик із РЕАЛЬНИМИ к-стями: qty = упаковки (від фасовки товару), + залишок.
        $byIngredient = collect($candidates)->groupBy('ingredient');

        // BE-7: якщо кошик перевищує ліміт — жадібно даунгрейдимо на дешевші альтернативи.
        $picked = $this->squeezeToBudget(
            collect($result['items'])->keyBy('ingredient')->all(),
            $byIngredient,
            $needByName,
            (int) $result['effective_limit'],
        );

        $optimized = 0.0;
        $naive = 0.0;
        $items = array_map(function (Candidate $c) use ($byIngredient, $needByName, &$optimized, &$naive) {
            $need = $needByName[$c->ingredient] ?? ['qty' => 0.0, 'unit' => 'g'];
            $group = $byIngredient->get($c->ingredient, collect());
            $naivePrice = (float) ($group->max(fn (Candidate $x) => $x->price) ?? $c->price);

            if ($c->weighted && $c->step > 0) {
                // Ваговий товар: ціна за кг, купуємо кратно step (кг).
                $needKg = $this->toKg($need['qty'], $need['unit'], $c->step);
                $qty = max(1, (int) ceil($needKg / $c->step));         // к-сть кроків
                $weightKg = round($qty * $c->step, 3);
                $leftover = null;
                $packSize = (int) round($c->step * 1000);             // г у кроці (для показу)
                $lineTotal = round($c->price * $weightKg, 2);
                $naive += $naivePrice * $weightKg;
            } else {
                [$qty, $leftover, $packSize] = $this->computePacks($need['qty'], $need['unit'], $c->packSize, $c->packUnit);
                $lineTotal = round($c->price * $qty, 2);
                $naive += $naivePrice * $qty;
            }
            $optimized += $lineTotal;

            $alts = $group->reject(fn (Candidate $x) => $x->sku === $c->sku)
                ->map($this->altShape(...))->values()->all();

            $reason = $this->substitutionReason($c, $naivePrice);

            return $this->toItemAttributes($c, $qty, $leftover, $packSize, $reason, $lineTotal) + ['alt_options' => $alts];
        }, array_values($picked));

        $result['optimized_total'] = round($optimized, 2);
        $result['naive_total'] = round($naive, 2);
        $result['savings'] = round(max(0, $naive - $optimized), 2);
        $result['within_budget'] = $optimized <= (float) $result['effective_limit'];

        // Ціна на кожну страву = частка вартості кошика пропорційно вжитку інгредієнтів.
        $menu = $this->attachMealPrices($menu, $items);

        $this->plans->replaceItems($plan, $items);
        $this->plans->saveResult($plan, $result, $menu); // зберігаємо ПОВНЕ меню на тиждень
        if ($days !== null) {
            $this->plans->update($plan, ['shopping_days' => $days]);
        }

        return $plan->fresh('items');
    }

    /**
     * Проставити price на кожну страву в меню: частка вартості кошика (price_total
     * обраних товарів) пропорційно к-сті інгредієнта в страві до тижневої потреби.
     * Сума цін страв ≈ optimized_total. Стійко до одиниць (працює на частках).
     *
     * @param  array<int, array{ingredient:string, price_total:int}>  $items
     */
    private function attachMealPrices(array $menu, array $items): array
    {
        // Вартість кошика по інгредієнту (lowercase name → price_total).
        $totals = [];
        foreach ($items as $it) {
            $name = mb_strtolower(trim((string) ($it['ingredient'] ?? '')));
            if ($name !== '') {
                $totals[$name] = ($totals[$name] ?? 0) + (int) ($it['price_total'] ?? 0);
            }
        }

        // Тижнева потреба по інгредієнту (сума qty по всьому меню).
        $need = [];
        foreach ($this->aggregateIngredients($menu) as $ing) {
            $need[$ing['name']] = (float) $ing['qty'];
        }

        foreach ($menu['days'] ?? [] as $di => $day) {
            foreach ($day['meals'] ?? [] as $mi => $meal) {
                $price = 0.0;
                foreach ($meal['ingredients'] ?? [] as $ing) {
                    $name = mb_strtolower(trim((string) ($ing['name'] ?? '')));
                    $weekQty = $need[$name] ?? 0.0;
                    if ($name === '' || $weekQty <= 0 || ! isset($totals[$name])) {
                        continue;
                    }
                    $price += $totals[$name] * ((float) ($ing['qty'] ?? 0) / $weekQty);
                }
                $menu['days'][$di]['meals'][$mi]['price'] = (int) round($price);
            }
        }

        return $menu;
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
                    if ($name === '') {
                        continue;
                    }
                    if (! isset($byName[$name])) {
                        $byName[$name] = [
                            'name' => $name,
                            'category' => isset($ing['category']) ? (string) $ing['category'] : null,
                            'search' => array_values(array_filter((array) ($ing['search'] ?? []))),
                            'qty' => 0.0,
                            'unit' => (string) ($ing['unit'] ?? 'g'),
                        ];
                    }
                    $byName[$name]['qty'] += (float) ($ing['qty'] ?? 0);
                }
            }
        }

        return array_values($byName);
    }

    /**
     * К-сть упаковок + залишок для інгредієнта.
     * Якщо відома реальна фасовка товару (packSize у тих самих одиницях) —
     * packs = ceil(потреба / фасовка), залишок = packs*фасовка − потреба.
     * Інакше — грубий фолбек (~1 кг/1 л на упаковку), залишок невідомий.
     *
     * @return array{0: int, 1: ?int, 2: ?int} [packs, leftover, packSize]
     */
    /**
     * BE-7: жадібно даунгрейдимо найдорожчі позиції на дешевші альтернативи,
     * поки тижнева сума (з упаковками) не влізе в ліміт (або нічого ужимати).
     *
     * @param  array<string, Candidate>  $chosen  ingredient => обраний кандидат
     * @param  Collection<string, Collection<int, Candidate>>  $byIngredient
     * @param  array<string, array{qty: float, unit: string}>  $needByName
     * @return array<string, Candidate>
     */
    private function squeezeToBudget(array $chosen, $byIngredient, array $needByName, int $limit): array
    {
        $weekly = function (Candidate $c) use ($needByName): float {
            $need = $needByName[$c->ingredient] ?? ['qty' => 0.0, 'unit' => 'g'];
            [$packs] = $this->computePacks($need['qty'], $need['unit'], $c->packSize, $c->packUnit);

            return $c->price * $packs;
        };
        $total = fn (): float => array_sum(array_map($weekly, $chosen));

        $guard = 0;
        while ($total() > $limit && $guard++ < 200) {
            $bestIng = null;
            $bestAlt = null;
            $bestSaving = 0;

            foreach ($chosen as $ing => $cur) {
                $alt = ($byIngredient->get($ing) ?? collect())
                    ->filter(fn (Candidate $x) => $x->price < $cur->price)
                    ->sortBy('price')->first();
                if ($alt === null) {
                    continue;
                }
                $saving = $weekly($cur) - $weekly($alt);
                if ($saving > $bestSaving) {
                    $bestSaving = $saving;
                    $bestIng = $ing;
                    $bestAlt = $alt;
                }
            }

            if ($bestIng === null || $bestSaving <= 0) {
                break; // дешевших альтернатив немає — далі не ужати
            }
            $chosen[$bestIng] = $bestAlt;
        }

        return $chosen;
    }

    private function computePacks(float $need, string $unit, ?float $packSize, ?string $packUnit): array
    {
        if ($need <= 0) {
            return [1, null, $packSize !== null ? (int) round($packSize) : null];
        }

        if ($packSize !== null && $packSize > 0 && $packUnit === $unit) {
            $packs = max(1, (int) ceil($need / $packSize));
            $leftover = (int) round($packs * $packSize - $need);

            return [$packs, $leftover, (int) round($packSize)];
        }

        $packs = match ($unit) {
            'ml', 'g' => max(1, (int) ceil($need / 1000)),
            default => max(1, (int) ceil($need)),
        };

        return [$packs, null, null];
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

        // BE-1: заміна позиції + перерахунок економії — атомарно.
        DB::transaction(function () use ($plan, $item, $target, $alts, $sku, $previous) {
            $this->plans->updateItem($item, [
                'silpo_product_id' => $target['sku'],
                'title' => $target['title'],
                'price' => $target['price'],
                'old_price' => $target['old_price'] ?? null,
                'price_total' => round((float) $target['price'] * $item->qty, 2),
                'is_promo' => $target['is_promo'] ?? false,
                'is_private_label' => $target['is_private_label'] ?? false,
                'match_confidence' => $target['confidence'] ?? 1,
                'alt_options' => $alts->reject(fn ($a) => $a['sku'] === $sku)->push($previous)->values()->all(),
            ]);

            $optimized = $this->plans->sumItemsTotal($plan);
            $this->plans->update($plan, [
                'optimized_total' => $optimized,
                'savings' => max(0, ($plan->naive_total ?? $optimized) - $optimized),
            ]);
        });

        // Навчання матчингу (поза транзакцією — не критично до атомарності).
        $this->matchMemory->remember($item->ingredient, (string) $target['sku'], (string) $target['title']);

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

    /** г/мл → кг; для вагового у pcs беремо один крок. */
    private function toKg(float $qty, string $unit, float $step): float
    {
        return match ($unit) {
            'g', 'ml' => $qty / 1000,
            default => $step > 0 ? $step : 0.1, // pcs у вагового — рідко; беремо крок
        };
    }

    private function toItemAttributes(Candidate $c, int $qty = 1, ?int $leftover = null, ?int $packSize = null, ?string $reason = null, ?float $priceTotal = null): array
    {
        return [
            'ingredient' => $c->ingredient,
            'silpo_product_id' => $c->sku,
            'title' => $c->title,
            'qty' => $qty,
            'price' => round($c->price, 2),
            'old_price' => $c->oldPrice !== null ? round($c->oldPrice, 2) : null,
            'price_total' => $priceTotal !== null ? round($priceTotal, 2) : round($c->price * $qty, 2),
            'pack_size' => $packSize,
            'leftover' => $leftover,
            'reason' => $reason,
            'is_promo' => $c->isPromo,
            'is_private_label' => $c->isPrivateLabel,
            'match_confidence' => $c->confidence,
        ];
    }

    /** SubstitutionExplainer: чому саме цей товар («Власна марка, дешевше на 20 ₴»). */
    private function substitutionReason(Candidate $c, float $naivePrice): ?string
    {
        $parts = [];
        if ($c->isPromo) {
            $parts[] = $c->oldPrice !== null && $c->oldPrice > $c->price
                ? 'Акція −'.(int) round(($c->oldPrice - $c->price) / $c->oldPrice * 100).'%'
                : 'Акція';
        } elseif ($c->isPrivateLabel) {
            $parts[] = 'Власна марка';
        }
        if ($naivePrice > $c->price) {
            $parts[] = 'дешевше на '.(int) round($naivePrice - $c->price).' ₴';
        }

        return $parts === [] ? null : implode(', ', $parts);
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
        $days = max(1, min(7, (int) ($p->shopping_days ?? 7)));
        $periodBudget = (int) round($p->budget * $days / 7); // орієнтир витрат на обраний період

        return <<<TXT
        Режим: {$p->mode}. Людей: {$p->people}.
        Кількість днів у меню: РІВНО {$days} (не більше й не менше; кожен день унікальний).
        Бюджет-орієнтир: ~{$periodBudget} ₴ на ці {$days} дн. (з тижневого {$p->budget} ₴){$flex}.
        Система харчування (ЖОРСТКЕ правило): {$diet}.
        Бажані кухні (м'яке вподобання): {$cuisines}.
        Здорові фільтри (цілі складу): {$health}.
        Алергії/виключення (НІКОЛИ не додавай навіть слідів): {$allergies}.
        Доступна техніка: {$appliances}. Ліміт часу на страву: {$p->max_cook_minutes} хв.
        Склади меню рівно на {$days} днів СУВОРО за системою харчування та правилами. Спочатку перевір сьогоднішні акції.
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
