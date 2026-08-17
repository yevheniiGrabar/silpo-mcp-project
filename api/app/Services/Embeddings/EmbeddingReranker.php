<?php

namespace App\Services\Embeddings;

use App\Services\Budget\Candidate;
use Illuminate\Support\Facades\Cache;

/**
 * Семантичний ре-ранк кандидатів (Фаза 2): змішує лексично-категорійну впевненість
 * (Фаза 1) з косинусною близькістю ембедингів «інгредієнт ↔ назва товару».
 * Вектори товарів кешуються за текстом (назви стабільні → платимо один раз).
 * Без ключа/при помилці — прозорий фолбек на порядок Фази 1.
 */
class EmbeddingReranker
{
    public function __construct(private readonly VoyageClient $client) {}

    /**
     * @param  Candidate[]  $candidates  вже відсортовані Фазою 1 (лексика+категорія)
     * @return Candidate[] top-N після семантичного змішування
     */
    public function rerank(string $ingredient, array $candidates, int $topN = 3): array
    {
        if (! $this->client->enabled() || count($candidates) <= 1) {
            return array_slice($candidates, 0, $topN);
        }

        $texts = [$ingredient];
        foreach ($candidates as $c) {
            $texts[] = $c->title;
        }

        $vectors = $this->vectors($texts);
        $ingVec = $vectors[0] ?? null;
        if ($ingVec === null) {
            return array_slice($candidates, 0, $topN); // ембединги недоступні → Фаза 1
        }

        $scored = [];
        foreach ($candidates as $i => $c) {
            $vec = $vectors[$i + 1] ?? null;
            $cos = $vec !== null ? self::cosine($ingVec, $vec) : 0.0;
            $sim = ($cos + 1) / 2; // [-1..1] → [0..1]
            $blended = 0.55 * $sim + 0.45 * $c->confidence;
            $scored[] = ['blended' => $blended, 'price' => $c->price, 'candidate' => $c];
        }

        usort($scored, fn ($a, $b) => [$b['blended'], $a['price']] <=> [$a['blended'], $b['price']]);

        return array_map(fn ($s) => $s['candidate'], array_slice($scored, 0, $topN));
    }

    /**
     * Вектори для текстів із кешем за хешем тексту (пропущені — доембеджуємо одним запитом).
     *
     * @param  string[]  $texts
     * @return array<int, array<int, float>|null>
     */
    private function vectors(array $texts): array
    {
        $out = [];
        $missing = [];
        $missingIdx = [];

        foreach ($texts as $i => $t) {
            $cached = Cache::get('emb:'.md5($t));
            if ($cached !== null) {
                $out[$i] = $cached;
            } else {
                $missing[] = $t;
                $missingIdx[] = $i;
            }
        }

        if ($missing !== []) {
            $fresh = $this->client->embed($missing);
            foreach ($missing as $j => $t) {
                $vec = $fresh[$j] ?? null;
                if ($vec !== null) {
                    Cache::put('emb:'.md5($t), $vec, now()->addDays(30));
                    $out[$missingIdx[$j]] = $vec;
                } else {
                    $out[$missingIdx[$j]] = null;
                }
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Косинусна близькість двох векторів (−1..1). Публічна для тестів.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
