<?php

namespace App\Services\Silpo;

use App\Models\MealPlan;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Канонічний флоу оформлення в Сільпо (docs/06):
 * get_my_shopping_cart → get_shopping_cart_by_id → (update_shopping_cart) →
 * add_or_update_cart_products → get_shopping_cart_by_id → checkoutWebLink.
 * Кошик НЕ створюється через MCP — має бути активний кошик гостя.
 */
class CartService
{
    public function __construct(private readonly SilpoClient $silpo) {}

    public function checkout(MealPlan $plan): array
    {
        // 1) Активний кошик гостя (обов'язково перший крок).
        $cart = $this->content($this->silpo->call('silpo_get_my_shopping_cart'));
        $cartId = $cart['id'] ?? $cart['shoppingCartId'] ?? null;
        if (! $cartId) {
            throw new RuntimeException('Немає активного кошика Сільпо (створюється на боці Сільпо).');
        }

        // 2) Контекст кошика (branch/deliveryType/timeslot).
        $ctx = $this->content($this->silpo->call('silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId]));
        $branchId = $ctx['branchId'] ?? $plan->branch_id;
        $companyId = $ctx['companyId'] ?? null;

        if ($plan->branch_id && $plan->branch_id !== ($ctx['branchId'] ?? null)) {
            $this->silpo->call('silpo_update_shopping_cart', ['shoppingCartId' => $cartId, 'branchId' => $plan->branch_id]);
            $branchId = $plan->branch_id;
        }

        // 3) Додати всі позиції кошика.
        $products = $plan->items->map(fn ($i) => array_filter([
            'productId' => $i->silpo_product_id,
            'companyId' => $companyId,
            'branchId' => $branchId,
            'quantity' => $i->qty,
        ], fn ($v) => $v !== null))->values()->all();

        $this->silpo->call('silpo_add_or_update_cart_products', [
            'shoppingCartId' => $cartId,
            'products' => $products,
        ]);
        Log::channel('silpo-mcp')->info('add_or_update_cart_products', ['cartId' => $cartId, 'count' => count($products)]);

        // 4) Фінальний стан кошика → checkout-лінки.
        $final = $this->content($this->silpo->call('silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId]));

        return [
            'checkout_web' => $final['checkoutWebLink'] ?? null,
            'checkout_mobile' => $final['checkoutMobileLink'] ?? null,
            'total' => $final['total'] ?? null,
            'validations' => $final['validations'] ?? [],
        ];
    }

    private function content($result): array
    {
        if ($result->isError) {
            throw new RuntimeException('Silpo MCP: '.$result->text());
        }

        return is_array($result->structuredContent) ? $result->structuredContent : [];
    }
}
