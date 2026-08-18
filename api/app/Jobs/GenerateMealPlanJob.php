<?php

namespace App\Jobs;

use App\Repositories\MealPlanRepository;
use App\Services\MealPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Важка генерація меню у черзі (agent + матчинг + оптимізатор). Фронт опитує
 * GET /api/meal-plans/{id} до status=ready|failed.
 */
class GenerateMealPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * AI-3: сервіс робить до 2 спроб агента по ~180с — timeout має вміщати обидві +
     * backoff + матчинг. Воркер запускати з `--timeout` не меншим за це значення.
     */
    public int $timeout = 400;

    public int $backoff = 5;

    public function __construct(public readonly int $mealPlanId) {}

    public function handle(MealPlanService $service, MealPlanRepository $plans): void
    {
        $plan = $plans->find($this->mealPlanId);

        if ($plan !== null) {
            $service->run($plan);
        }
    }
}
