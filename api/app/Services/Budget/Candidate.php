<?php

namespace App\Services\Budget;

/**
 * Кандидат SKU для одного інгредієнта (результат матчингу find_products_batch).
 * Ціни — у копійках/грн (фіксуємо грн для демо). НЕ від LLM — тільки з MCP.
 */
final readonly class Candidate
{
    public function __construct(
        public string $ingredient,   // нормалізована назва інгредієнта
        public string $sku,          // silpo product id
        public string $title,        // назва товару
        public float $price,         // ціна за одиницю в ₴ з копійками (акційна/поточна)
        public bool $isPromo = false,
        public bool $isPrivateLabel = false,
        public float $confidence = 1.0, // 0..1 впевненість матчингу
        public ?float $oldPrice = null, // ціна до знижки в ₴ (для розрахунку економії)
        public ?float $packSize = null, // розмір фасовки (у packUnit)
        public ?string $packUnit = null, // 'g' | 'ml' | 'pcs'
        public bool $weighted = false,  // ваговий товар (ціна за кг)
        public float $step = 0,         // крок покупки для вагового (кг)
    ) {}
}
