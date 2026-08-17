<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    /** GET /api/purchases — історія замовлень (нові → старі). */
    public function index(): JsonResponse
    {
        $purchases = $this->currentUser()->purchases()
            ->latest('purchased_at')->limit(50)->get();

        return response()->json(['data' => $purchases]);
    }

    /** POST /api/purchases — записати подію покупки (замовлення + позиції). */
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $data = $request->validate([
            'store' => ['nullable', 'string', 'max:64'],
            'market' => ['nullable', 'string', 'max:8'],
            // BE-3: план має існувати і належати цьому користувачу.
            'meal_plan_id' => ['nullable', 'integer', Rule::exists('meal_plans', 'id')->where('user_id', $user->id)],
            'total' => ['required', 'integer', 'min:0'],
            'saved' => ['nullable', 'integer', 'min:0'],
            'purchased_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.category' => ['nullable', 'string', 'max:64'],
            'items.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'items.*.old_price' => ['nullable', 'integer', 'min:0'],
            'items.*.saved' => ['nullable', 'integer', 'min:0'],
            'items.*.kcal' => ['nullable', 'integer', 'min:0'],
        ]);

        // BE-1: замовлення + позиції — в одній транзакції.
        $purchase = DB::transaction(function () use ($user, $data) {
            $p = $user->purchases()->create([
                'store' => $data['store'] ?? 'Сільпо',
                'market' => $data['market'] ?? 'UA',
                'meal_plan_id' => $data['meal_plan_id'] ?? null,
                'total' => $data['total'],
                'saved' => $data['saved'] ?? 0,
                'items_count' => count($data['items']),
                'purchased_at' => $data['purchased_at'] ?? now(),
            ]);

            $p->items()->createMany(array_map(fn ($it) => [
                'name' => $it['name'],
                'category' => $it['category'] ?? 'Інше',
                'qty' => $it['qty'] ?? 1,
                'price' => $it['price'],
                'old_price' => $it['old_price'] ?? null,
                'saved' => $it['saved'] ?? 0,
                'kcal' => $it['kcal'] ?? null,
            ], $data['items']));

            return $p;
        });

        return response()->json(['data' => $purchase->load('items')], 201);
    }

    private function currentUser(): User
    {
        return Auth::user() ?? User::firstOrCreate(
            ['email' => 'demo@mealize.app'],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );
    }
}
