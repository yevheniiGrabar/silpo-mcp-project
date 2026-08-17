<?php

namespace App\Services\Silpo;

use App\Ai\Agents\MatchPickerAgent;
use App\Services\Budget\Candidate;

/**
 * LLM-тайбрейкер (Фаза 3): для невпевнених матчів питає Haiku, який товар
 * зі списку кандидатів найкраще підходить як інгредієнт. Graceful: будь-яка
 * помилка/невалідний sku → null (лишається порядок Фаз 1-2).
 */
class MatchTiebreaker
{
    /**
     * @param  Candidate[]  $candidates
     * @return string|null обраний sku (гарантовано зі списку) або null
     */
    public function pick(string $ingredient, ?string $category, array $candidates): ?string
    {
        if (count($candidates) < 2) {
            return null;
        }

        $list = '';
        foreach ($candidates as $c) {
            $list .= "- [{$c->sku}] {$c->title}\n";
        }
        $cat = $category !== null ? " (категорія: {$category})" : '';

        try {
            $resp = (new MatchPickerAgent)->prompt(
                "Інгредієнт: {$ingredient}{$cat}.\nТовари:\n{$list}\nОбери sku, що найкраще підходить як цей інгредієнт для готування.",
                timeout: 20,
            );
            $sku = is_array($resp->structured ?? null) ? ($resp->structured['sku'] ?? null) : null;

            // sku має бути саме зі списку кандидатів.
            foreach ($candidates as $c) {
                if ($c->sku === $sku) {
                    return $sku;
                }
            }
        } catch (\Throwable) {
            // ignore — фолбек на Фази 1-2
        }

        return null;
    }
}
