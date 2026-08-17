<?php

namespace App\Services\Silpo;

use App\Models\MatchCorrection;

/**
 * Пам'ять матчингу: вивчає вибір користувача під час свапів
 * (інгредієнт → обраний SKU) і підказує його при наступних генераціях.
 */
class MatchMemory
{
    /** Запам'ятати вибір користувача (свап позиції на конкретний товар). */
    public function remember(string $ingredient, string $sku, string $title): void
    {
        $ing = mb_strtolower(trim($ingredient));
        if ($ing === '' || $sku === '') {
            return;
        }

        $row = MatchCorrection::firstOrNew(['ingredient' => $ing, 'sku' => $sku]);
        $row->title = $title;
        $row->hits = ($row->hits ?? 0) + 1;
        $row->save();
    }

    /**
     * Найчастіше обраний SKU для кожного інгредієнта.
     *
     * @param  string[]  $ingredients
     * @return array<string, string> ingredient(lower) => sku
     */
    public function preferredSkus(array $ingredients): array
    {
        $ings = array_values(array_unique(array_filter(
            array_map(fn ($i) => mb_strtolower(trim((string) $i)), $ingredients),
        )));
        if ($ings === []) {
            return [];
        }

        $out = [];
        foreach (MatchCorrection::whereIn('ingredient', $ings)->orderByDesc('hits')->get() as $r) {
            $out[$r->ingredient] ??= $r->sku; // перший = найбільше hits
        }

        return $out;
    }
}
