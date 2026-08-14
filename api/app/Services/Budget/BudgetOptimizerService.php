<?php

namespace App\Services\Budget;

use Illuminate\Support\Collection;

/**
 * Детермінований constraint-оптимізатор кошика (НЕ LLM — див. docs/02).
 * Обирає по одному SKU на інгредієнт залежно від режиму, рахує економію
 * проти «наївного» кошика (перший-ліпший кандидат).
 */
final class BudgetOptimizerService
{
    /**
     * @param  Candidate[]  $candidates  усі кандидати (по кілька на інгредієнт)
     * @return array{mode:string,items:Candidate[],naive_total:int,optimized_total:int,savings:int,effective_limit:int,within_budget:bool}
     */
    public function optimize(array $candidates, int $budget, string $mode = 'economy', int $flexPct = 0): array
    {
        $byIngredient = collect($candidates)->groupBy('ingredient');

        // Наївний кошик = перший кандидат кожного інгредієнта (best-name-match порядок на вході).
        $naiveTotal = $byIngredient->map(fn (Collection $c) => $c->first()->price)->sum();

        // Ефективний ліміт: economy = бюджет; quality = бюджет + flex%.
        $effectiveLimit = $mode === 'quality'
            ? (int) round($budget * (1 + $flexPct / 100))
            : $budget;

        $picked = $byIngredient->map(fn (Collection $c) => $mode === 'quality'
            ? $this->pickQuality($c)
            : $this->pickEconomy($c));

        $optimizedTotal = $picked->map(fn (Candidate $x) => $x->price)->sum();

        return [
            'mode' => $mode,
            'items' => $picked->values()->all(),
            'naive_total' => $naiveTotal,
            'optimized_total' => $optimizedTotal,
            'savings' => max(0, $naiveTotal - $optimizedTotal),
            'effective_limit' => $effectiveLimit,
            'within_budget' => $optimizedTotal <= $effectiveLimit,
        ];
    }

    /** Economy: найдешевше; при рівних цінах — акція, потім Власна марка. */
    private function pickEconomy(Collection $c): Candidate
    {
        return $c->sort(fn (Candidate $a, Candidate $b) => [
            $a->price, $b->isPromo, $b->isPrivateLabel,
        ] <=> [
            $b->price, $a->isPromo, $a->isPrivateLabel,
        ])->first();
    }

    /** Quality: найвища відповідність уподобанням; акція — бонус; ціна не головна. */
    private function pickQuality(Collection $c): Candidate
    {
        return $c->sortByDesc(fn (Candidate $x) => $x->confidence + ($x->isPromo ? 0.05 : 0))->first();
    }
}
