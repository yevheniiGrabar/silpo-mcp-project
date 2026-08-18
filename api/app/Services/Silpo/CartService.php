<?php

namespace App\Services\Silpo;

use App\Models\MealPlan;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Канонічний флоу наповнення кошика в Сільпо (перевірено на живому MCP):
 *   silpo_get_my_shopping_cart          → shoppingCartId (кошик існує на боці Сільпо)
 *   silpo_get_shopping_cart_by_id       → cart.shipments[0] (branchId+companyId), cart.calculation
 *   (silpo_update_shopping_cart)        → перемкнути філію за потреби
 *   silpo_add_or_update_cart_products   → додати позиції {productId, companyId, branchId, quantity}
 *   silpo_get_shopping_cart_by_id       → підсумок (calculation.total) + валідації
 *
 * Важливо: MCP НЕ повертає checkout-лінк. Оформлення/оплату користувач завершує
 * у застосунку Сільпо — кошик спільний з його акаунтом. Кошик через MCP не
 * створюється: має бути активний кошик гостя.
 */
class CartService
{
    public function __construct(private readonly SilpoClient $silpo) {}

    public function checkout(MealPlan $plan): array
    {
        // 1) Активний кошик гостя.
        $mine = $this->silpo->callData('silpo_get_my_shopping_cart');
        $cartId = $mine['shoppingCartId'] ?? ($mine['cart']['id'] ?? null);
        if (! $cartId) {
            throw new RuntimeException('Немає активного кошика Сільпо (створюється на боці Сільпо).');
        }

        // 2) Стан кошика: shipments[0] дає branchId+companyId (обов'язкові для додавання).
        $cart = $this->cartById($cartId);
        [$branchId, $companyId] = $this->fulfilment($cart);

        // 2a) Якщо план прив'язаний до іншої філії — перемкнути кошик і перечитати.
        if ($plan->branch_id && $branchId && $plan->branch_id !== $branchId) {
            $this->silpo->call('silpo_update_shopping_cart', [
                'shoppingCartId' => $cartId,
                'branchId' => $plan->branch_id,
            ]);
            $cart = $this->cartById($cartId);
            [$branchId, $companyId] = $this->fulfilment($cart);
        }

        if (! $branchId || ! $companyId) {
            throw new RuntimeException('Кошик Сільпо без філії — відкрий Сільпо й обери магазин або самовивіз.');
        }

        // 3) Додати позиції плану (усі в одну філію/компанію самовивозу).
        $products = $plan->items
            ->filter(fn ($i) => ! empty($i->silpo_product_id) && $i->available !== false)
            ->map(fn ($i) => [
                'productId' => $i->silpo_product_id,
                'companyId' => $companyId,
                'branchId' => $branchId,
                // Для вагових шлемо ВАГУ (кг), для штучних — к-сть упаковок.
                'quantity' => (float) ($i->order_qty ?? max(1, (int) $i->qty)),
            ])->values()->all();

        if (empty($products)) {
            throw new RuntimeException('У плані немає товарів для кошика.');
        }

        $add = $this->silpo->call('silpo_add_or_update_cart_products', [
            'shoppingCartId' => $cartId,
            'products' => $products,
        ]);
        if ($add->isError) {
            throw new RuntimeException('Silpo MCP: '.$add->text());
        }
        Log::channel('silpo-mcp')->info('add_or_update_cart_products', ['cartId' => $cartId, 'count' => count($products)]);

        // 4) Фінальний підсумок. Checkout-лінк MCP не віддає — оформлення в застосунку Сільпо.
        $final = $this->cartById($cartId);
        $calc = $final['calculation'] ?? [];

        return [
            'cart_id' => $cartId,
            'added' => count($products),
            'total' => $calc['total'] ?? null,
            'products_total' => $calc['productsTotal'] ?? null,
            'validations' => $calc['validations'] ?? [],
            'finish_in_silpo' => true, // немає MCP-лінку: заказ завершується в застосунку Сільпо
            'checkout_web' => null,
            'checkout_mobile' => null,
        ];
    }

    /** Читання кошика за id → об'єкт cart (дані вкладені під ключем "cart"). */
    private function cartById(string $cartId): array
    {
        $resp = $this->silpo->call('silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId]);
        if ($resp->isError) {
            throw new RuntimeException('Silpo MCP: '.$resp->text());
        }
        $data = is_array($resp->structuredContent) && $resp->structuredContent !== []
            ? $resp->structuredContent
            : (json_decode($resp->text(), true) ?: []);

        return $data['cart'] ?? $data;
    }

    /** branchId+companyId філії виконання з першого shipment кошика. */
    private function fulfilment(array $cart): array
    {
        $shipment = $cart['shipments'][0] ?? [];

        return [$shipment['branchId'] ?? null, $shipment['companyId'] ?? null];
    }
}
