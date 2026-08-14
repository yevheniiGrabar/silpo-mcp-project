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
        public int $price,           // ціна за одиницю (акційна/поточна)
        public bool $isPromo = false,
        public bool $isPrivateLabel = false,
        public float $confidence = 1.0, // 0..1 впевненість матчингу
    ) {}
}
