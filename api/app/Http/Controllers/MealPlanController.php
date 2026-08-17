<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMealPlanRequest;
use App\Http\Requests\SwapItemRequest;
use App\Http\Resources\MealPlanResource;
use App\Jobs\GenerateMealPlanJob;
use App\Repositories\MealPlanRepository;
use App\Services\MealPlanService;
use App\Services\Silpo\CartService;
use App\Support\ResolvesCurrentUser;
use Illuminate\Http\JsonResponse;

class MealPlanController extends Controller
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly MealPlanService $service,
        private readonly MealPlanRepository $plans,
    ) {}

    /** POST /api/meal-plans — створити план і поставити генерацію в чергу (202). */
    public function store(StoreMealPlanRequest $request): JsonResponse
    {
        $plan = $this->service->create($this->currentUser(), $request->validated());

        GenerateMealPlanJob::dispatch($plan->id);

        return (new MealPlanResource($plan))
            ->additional(['poll' => "/api/meal-plans/{$plan->id}"])
            ->response()
            ->setStatusCode(202);
    }

    /** GET /api/meal-plans/{id} — статус + меню + кошик (лише свій план). */
    public function show(int $id): MealPlanResource|JsonResponse
    {
        $plan = $this->plans->findForUser($this->currentUser(), $id);

        return $plan
            ? new MealPlanResource($plan)
            : response()->json(['message' => 'Not found'], 404);
    }

    /** POST /api/meal-plans/{id}/items/{item}/swap — замінити позицію на альтернативу. */
    public function swap(int $id, int $item, SwapItemRequest $request): MealPlanResource|JsonResponse
    {
        $plan = $this->plans->findForUser($this->currentUser(), $id);
        if (! $plan) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $cartItem = $this->plans->findItem($plan, $item);
        if (! $cartItem) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        try {
            $plan = $this->service->swapItem($plan, $cartItem, $request->validated()['sku']);

            return new MealPlanResource($plan);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/meal-plans/{id}/checkout — зібрати кошик у Сільпо → checkout-лінк. */
    public function checkout(int $id, CartService $cart): JsonResponse
    {
        $plan = $this->plans->findForUser($this->currentUser(), $id);
        if (! $plan) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            return response()->json($cart->checkout($plan));
        } catch (\Throwable $e) {
            report($e); // SEC-5: у лог, не клієнту

            return response()->json(['message' => 'Сервіс Сільпо тимчасово недоступний'], 503);
        }
    }
}
