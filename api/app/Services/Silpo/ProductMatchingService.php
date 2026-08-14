<?php

namespace App\Services\Silpo;

use App\Services\Budget\Candidate;

/**
 * Детермінований матчинг «інгредієнт → SKU» (НЕ LLM). Сирий пошук Сільпо
 * промахується ~30-40% (docs/06) → ре-ранкінг за назвою + блок-лист + бонуси.
 * Публічний rank() чистий і покритий тестами; match() робить живий виклик MCP.
 */
class ProductMatchingService
{
    public function __construct(private readonly SilpoClient $silpo) {}

    /** Слова, що майже завжди означають нерелевантний товар для базового інгредієнта. */
    private const BLOCK = ['шоколад', 'цукерк', 'корм', 'десерт', 'морозиво', 'печиво', 'сік ', 'напій'];

    /**
     * Ре-ранкінг сирих товарів під інгредієнт → top-N кандидатів.
     *
     * @param  SilpoProduct[]  $products
     * @return Candidate[]
     */
    public function rank(string $ingredient, array $products, int $topN = 3): array
    {
        $words = $this->words($ingredient);
        $maxScore = max(1, count($words));

        $scored = [];
        foreach ($products as $p) {
            $title = mb_strtolower($p->title);
            $score = 0.0;

            foreach ($words as $w) {
                if (mb_strlen($w) < 3) {
                    continue;
                }
                if (str_contains($title, $w)) {
                    $score += 1.0;
                } elseif (str_contains($title, mb_substr($w, 0, 4))) {
                    $score += 0.4;
                }
            }
            foreach (self::BLOCK as $bad) {
                if (str_contains($title, $bad)) {
                    $score -= 1.5;
                }
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
                ),
            ];
        }

        // Сорт: релевантність ↓, потім ціна ↑.
        usort($scored, fn ($a, $b) => [$b['confidence'], $a['candidate']->price]
            <=> [$a['confidence'], $b['candidate']->price]);

        return array_map(fn ($s) => $s['candidate'], array_slice($scored, 0, $topN));
    }

    /**
     * Живий матчинг усіх інгредієнтів через silpo_find_products_batch.
     * $ctx = {branchId, deliveryType, timeslotStart, timeslotEnd} (docs/06 — обов'язково).
     *
     * @param  string[]  $ingredients
     * @return Candidate[]  плоский список (по topN на інгредієнт)
     */
    public function match(array $ingredients, array $ctx, int $topN = 3): array
    {
        $out = [];
        foreach (array_chunk($ingredients, 30) as $batch) {
            $res = $this->silpo->call('silpo_find_products_batch', array_merge($ctx, [
                'products' => $batch,
            ]));

            $grouped = $this->parse($res->structuredContent ?? []);
            foreach ($batch as $ingredient) {
                $raw = $grouped[$ingredient] ?? [];
                array_push($out, ...$this->rank($ingredient, $raw, $topN));
            }
        }

        return $out;
    }

    /**
     * Нормалізує відповідь MCP у {ingredient => SilpoProduct[]}.
     * Форма відповіді уточнюється на живих даних (docs/06 reference).
     *
     * @return array<string, SilpoProduct[]>
     */
    private function parse(array $structured): array
    {
        $result = [];
        foreach (($structured['results'] ?? $structured['products'] ?? []) as $group) {
            $query = (string) ($group['query'] ?? $group['ingredient'] ?? '');
            foreach (($group['items'] ?? $group['products'] ?? []) as $item) {
                $result[$query][] = new SilpoProduct(
                    id: (string) ($item['id'] ?? $item['productId'] ?? ''),
                    title: (string) ($item['title'] ?? $item['name'] ?? ''),
                    price: (int) round((float) ($item['price'] ?? 0)),
                    oldPrice: isset($item['oldPrice']) && $item['oldPrice'] !== null
                        ? (int) round((float) $item['oldPrice']) : null,
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
