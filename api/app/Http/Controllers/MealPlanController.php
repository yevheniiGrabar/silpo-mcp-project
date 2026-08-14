<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMealPlanRequest;
use App\Http\Resources\MealPlanResource;
use App\Jobs\GenerateMealPlanJob;
use App\Models\User;
use App\Repositories\MealPlanRepository;
use App\Services\MealPlanService;
use App\Services\Silpo\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MealPlanController extends Controller
{
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

    /** GET /api/meal-plans/{id} — статус + меню + кошик. */
    public function show(int $id): MealPlanResource|JsonResponse
    {
        $plan = $this->plans->find($id);

        return $plan
            ? new MealPlanResource($plan)
            : response()->json(['message' => 'Not found'], 404);
    }

    /** POST /api/meal-plans/{id}/checkout — зібрати кошик у Сільпо → checkout-лінк. */
    public function checkout(int $id, CartService $cart): JsonResponse
    {
        $plan = $this->plans->find($id);
        if (! $plan) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            return response()->json($cart->checkout($plan));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function currentUser(): User
    {
        return Auth::user() ?? User::firstOrCreate(
            ['email' => 'demo@mealize.app'],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );
    }
}
