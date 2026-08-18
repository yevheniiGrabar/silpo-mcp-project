<?php

namespace App\Services\Silpo;

/** Сирий товар із silpo_find_products_batch (нормалізований). */
final readonly class SilpoProduct
{
    public function __construct(
        public string $id,
        public string $title,
        public float $price,
        public ?float $oldPrice = null,
        public ?string $category = null,   // секція магазину, якщо API її віддає
        public ?float $packSize = null,    // розмір фасовки (у packUnit): 800, 0.9…
        public ?string $packUnit = null,   // 'g' | 'ml' | 'pcs'
        public bool $weighted = false,     // ваговий товар (ціна за кг, крок step)
        public float $step = 0,            // крок покупки для вагового (кг)
        public ?string $image = null,      // URL фото товару
        public bool $available = true,     // є в наявності
        public float $stock = 1,           // залишок (0 = «очікується»)
    ) {}

    public function isPromo(): bool
    {
        return $this->oldPrice !== null && $this->oldPrice > $this->price;
    }
}
