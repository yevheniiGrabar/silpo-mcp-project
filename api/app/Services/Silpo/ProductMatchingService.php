<?php

namespace App\Services\Silpo;

use App\Services\Budget\Candidate;
use App\Services\Embeddings\EmbeddingReranker;

/**
 * Детермінований матчинг «інгредієнт → SKU» (НЕ LLM). Сирий пошук Сільпо
 * промахується ~30-40% → ре-ранкінг за назвою + КАТЕГОРІЙНІ профілі (Фаза 1) +
 * опційний семантичний ре-ранк ембедингами (Фаза 2) + мультизапит за search-термами.
 * Публічний rank() чистий і покритий тестами; match() робить живий виклик MCP.
 */
class ProductMatchingService
{
    /** Нижче цього — матч невпевнений → пробуємо LLM-тайбрейкер (Фаза 3). */
    private const TIEBREAK_THRESHOLD = 0.5;

    /** Ліміт LLM-тайбрейків на одну генерацію (обмежити вартість/латентність). */
    private const TIEBREAK_BUDGET = 12;

    public function __construct(
        private readonly SilpoClient $silpo,
        private readonly ?EmbeddingReranker $reranker = null,
        private readonly ?MatchMemory $memory = null,
        private readonly ?MatchTiebreaker $tiebreaker = null,
    ) {}

    /** Універсально «не для готування» — штрафуємо в будь-якій категорії. */
    private const GLOBAL_FORBID = [
        'корм', 'для тварин', 'дитяч', 'малятк', 'агуня', 'малюк', 'jerky',
    ];

    /**
     * Живий матчинг структурованих інгредієнтів через silpo_find_products_batch.
     * $ingredients: [ ['name'=>, 'category'=>?, 'search'=>?[]] ]
     * $ctx = {branchId, deliveryType, timeslotStart, timeslotEnd}.
     *
     * @return Candidate[] плоский список (по topN на інгредієнт)
     */
    public function match(array $ingredients, array $ctx, int $topN = 3): array
    {
        // Збираємо всі унікальні пошукові терми (розширює recall).
        $terms = [];
        foreach ($ingredients as $ing) {
            foreach ($this->queryTerms($ing) as $t) {
                $terms[$t] = true;
            }
        }

        $byTerm = [];
        foreach (array_chunk(array_keys($terms), 30) as $batch) {
            $data = $this->silpo->callData('silpo_find_products_batch', array_merge($ctx, ['products' => $batch]));
            $byTerm += $this->parse($data);
        }

        // Вивчені вибори користувача (свапи) для цих інгредієнтів.
        $prefs = $this->memory?->preferredSkus(array_map(fn ($i) => $i['name'], $ingredients)) ?? [];
        $tiebreaksLeft = self::TIEBREAK_BUDGET;

        $out = [];
        foreach ($ingredients as $ing) {
            // Пул кандидатів = об'єднання результатів усіх термів (без дублів).
            $pool = [];
            $seen = [];
            foreach ($this->queryTerms($ing) as $t) {
                foreach ($byTerm[$t] ?? [] as $p) {
                    if (! isset($seen[$p->id])) {
                        $pool[] = $p;
                        $seen[$p->id] = true;
                    }
                }
            }
            // Фаза 1: лексика+категорія (беремо ширше для наступних стадій).
            $preN = ($this->reranker !== null || $this->tiebreaker !== null) ? max($topN, 8) : $topN;
            $ranked = $this->rank($ing['name'], $pool, $preN, $ing['category'] ?? null);

            // Фаза 2 (опційно): семантичний ре-ранк ембедингами → фінальний top-N.
            $final = $this->reranker?->rerank($ing['name'], $ranked, $topN) ?? array_slice($ranked, 0, $topN);

            // Фаза 3a: LLM-тайбрейкер для невпевнених матчів (обмежено бюджетом).
            if ($this->tiebreaker !== null && $tiebreaksLeft > 0
                && ! empty($final) && $final[0]->confidence < self::TIEBREAK_THRESHOLD) {
                $tiebreaksLeft--;
                $sku = $this->tiebreaker->pick($ing['name'], $ing['category'] ?? null, array_slice($ranked, 0, 5));
                if ($sku !== null) {
                    $final = $this->pinSku($final, $ranked, $sku, $topN);
                }
            }

            // Фаза 3b: вивчений вибір користувача завжди перемагає (пінимо в топ).
            $pref = $prefs[mb_strtolower(trim($ing['name']))] ?? null;
            if ($pref !== null) {
                $final = $this->pinSku($final, $ranked, $pref, $topN);
            }

            array_push($out, ...$final);
        }

        return $out;
    }

    /** Підняти кандидата з $sku на перше місце (якщо він є у $ranked). */
    private function pinSku(array $final, array $ranked, string $sku, int $topN): array
    {
        if (! empty($final) && $final[0]->sku === $sku) {
            return $final;
        }
        $pick = null;
        foreach ($ranked as $c) {
            if ($c->sku === $sku) {
                $pick = $c;
                break;
            }
        }
        if ($pick === null) {
            return $final; // товар не в поточній видачі — нічого пінити
        }
        $rest = array_values(array_filter($final, fn ($c) => $c->sku !== $sku));

        return array_slice(array_merge([$pick], $rest), 0, $topN);
    }

    /**
     * Ре-ранкінг сирих товарів під інгредієнт → top-N кандидатів.
     * $category (опц.) вмикає категорійні профілі очікуваних/заборонених слів.
     *
     * @param  SilpoProduct[]  $products
     * @return Candidate[]
     */
    public function rank(string $ingredient, array $products, int $topN = 3, ?string $category = null): array
    {
        $words = $this->words($ingredient);
        $maxScore = max(1, count($words));
        [$expect, $forbid] = $this->categoryProfile($category);

        $scored = [];
        foreach ($products as $p) {
            $title = mb_strtolower($p->title);
            $score = 0.0;

            // 1) Лексична релевантність до назви інгредієнта.
            foreach ($words as $w) {
                if (mb_strlen($w) < 3) {
                    continue;
                }
                if (str_contains($title, $w)) {
                    $score += 1.0;
                    if (str_starts_with($title, $w)) {
                        $score += 0.8; // «Молоко …», «Банан …» — головний товар
                    }
                } elseif (mb_strlen($w) >= 5 && str_contains($title, mb_substr($w, 0, 5))) {
                    $score += 0.25;
                }
            }

            // 2) Категорійні бонуси/штрафи (замість глобального блок-листа).
            foreach ($expect as $e) {
                if (str_contains($title, $e)) {
                    $score += 0.5;
                }
            }
            foreach ($forbid as $bad) {
                if (str_contains($title, $bad)) {
                    $score -= 2.5;
                }
            }
            foreach (self::GLOBAL_FORBID as $bad) {
                if (str_contains($title, $bad)) {
                    $score -= 2.5;
                }
            }

            // 3) Якщо магазин віддав секцію товару і вона збігається з категорією — бонус.
            if ($category !== null && $p->category !== null
                && str_contains(mb_strtolower($p->category), $this->categoryHint($category))) {
                $score += 1.0;
            }

            $confidence = max(0.0, min(1.0, $score / $maxScore));
            $scored[] = [
                'confidence' => $confidence,
                'candidate' => new Candidate(
                    ingredient: $ingredient,
                    sku: $p->id,
                    title: $p->title,
                    price: $p->price,
                    isPromo: $p->isPromo(),
                    isPrivateLabel: $this->isPrivateLabel($title),
                    confidence: round($confidence, 2),
                    oldPrice: $p->oldPrice,
                ),
            ];
        }

        // Сорт: релевантність ↓, потім ціна ↑.
        usort($scored, fn ($a, $b) => [$b['confidence'], $a['candidate']->price]
            <=> [$a['confidence'], $b['candidate']->price]);

        return array_map(fn ($s) => $s['candidate'], array_slice($scored, 0, $topN));
    }

    /** Пошукові терми інгредієнта: search[] від агента або сама назва (до 3). */
    private function queryTerms(array $ing): array
    {
        $terms = array_values(array_filter(array_map(
            fn ($t) => trim(mb_strtolower((string) $t)),
            $ing['search'] ?? [],
        )));
        if (empty($terms)) {
            $terms = [mb_strtolower(trim((string) ($ing['name'] ?? '')))];
        }

        return array_slice(array_values(array_unique(array_filter($terms))), 0, 3);
    }

    /**
     * Категорійний профіль: [очікувані слова (+), заборонені слова (−)].
     * Ключі збігаються з enum «category» у MealPlannerAgent.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function categoryProfile(?string $c): array
    {
        return match ($c) {
            'м\'ясо' => [['філе', 'фарш', 'стейк', 'вирізк', 'свинин', 'яловичин', 'ребр', 'гомілк'],
                ['ковбас', 'сосиск', 'сардель', 'паштет', 'консерв', 'снек', 'в\'ялен', 'кабанос', 'копчен']],
            'птиця' => [['куряч', 'курк', 'індич', 'філе', 'гомілк', 'стегн', 'крил', 'грудк'],
                ['ковбас', 'паштет', 'консерв', 'снек', 'наггетс', 'копчен']],
            'риба' => [['риба', 'лосось', 'форель', 'оселедець', 'тунець', 'скумбрі', 'мінтай', 'хек', 'філе'],
                ['корм', 'паштет', 'крабов', 'пресерв', 'снек']],
            'молочне' => [['молоко', 'кефір', 'ряжан', 'йогурт', 'сметан', 'сир', 'вершк', 'масло верш'],
                ['шоколад', 'морозиво', 'десерт', 'глазур', 'напій', 'коктейль', 'сирков']],
            'яйця' => [['яйц', 'яєчн'], ['шоколад', 'порошок', 'кіндер']],
            'овочі' => [[], ['по-корейськ', 'маринован', 'консерв', 'сік ', 'чіпси', 'сушен']],
            'фрукти' => [[], ['сік ', 'нектар', 'джем', 'варенн', 'сушен', 'цукат', 'компот', 'сироп']],
            'крупи' => [['крупа', 'гречан', 'рис', 'пшоно', 'вівсян', 'перлов', 'булгур', 'кукурудзян', 'кіноа'],
                ['борошно', 'хліб', 'макарон', 'паста', 'шоколад', 'снек', 'палички', 'печиво']],
            'бакалія' => [[], ['корм']],
            'олія' => [['олія', 'оливков'], ['спрей', 'крем', 'мило', 'засмаг']],
            'хліб' => [['хліб', 'багет', 'булк', 'лаваш', 'тортил', 'батон'], ['сухар', 'крутон', 'панір']],
            'соуси' => [[], []],
            'напої' => [[], []],
            'солодощі' => [[], []],
            'заморожене' => [[], ['корм']],
            default => [[], []],
        };
    }

    /** Слово-підказка для звірки з секцією магазину (якщо вона є). */
    private function categoryHint(string $c): string
    {
        return match ($c) {
            'м\'ясо', 'птиця' => 'м\'ясо',
            'риба' => 'риб',
            'молочне', 'яйця' => 'молоч',
            'овочі', 'фрукти' => 'овоч',
            'крупи', 'бакалія' => 'бакал',
            'хліб' => 'хліб',
            'напої' => 'напо',
            'солодощі' => 'солод',
            default => $c,
        };
    }

    /**
     * Нормалізує відповідь silpo_find_products_batch у {query => SilpoProduct[]}.
     * Форма: { queries: [ { query, products: [ { id, name, price, oldPrice, available, category? } ] } ] }.
     *
     * @return array<string, SilpoProduct[]>
     */
    private function parse(array $data): array
    {
        $result = [];
        foreach (($data['queries'] ?? []) as $group) {
            $query = mb_strtolower((string) ($group['query'] ?? ''));
            foreach (($group['products'] ?? []) as $item) {
                if (($item['available'] ?? true) === false) {
                    continue;
                }
                $result[$query][] = new SilpoProduct(
                    id: (string) ($item['id'] ?? ''),
                    title: (string) ($item['name'] ?? ''),
                    price: (int) round((float) ($item['price'] ?? 0)),
                    oldPrice: isset($item['oldPrice']) && $item['oldPrice'] !== null
                        ? (int) round((float) $item['oldPrice']) : null,
                    category: isset($item['category']) ? (string) $item['category'] : null,
                );
            }
        }

        return $result;
    }

    /** @return string[] */
    private function words(string $name): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}]+/u', mb_strtolower(trim($name))) ?: [],
            fn ($w) => $w !== '',
        ));
    }

    private function isPrivateLabel(string $lowerTitle): bool
    {
        return str_contains($lowerTitle, 'власна марка') || str_contains($lowerTitle, 'премія');
    }
}
