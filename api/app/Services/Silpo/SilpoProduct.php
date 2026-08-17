<?php

namespace App\Services\Silpo;

/** Сирий товар із silpo_find_products_batch (нормалізований). */
final readonly class SilpoProduct
{
    public function __construct(
        public string $id,
        public string $title,
        public int $price,
        public ?int $oldPrice = null,
        public ?string $category = null,   // секція магазину, якщо API її віддає
        public ?float $packSize = null,    // розмір фасовки (у packUnit): 800, 0.9…
        public ?string $packUnit = null,   // 'g' | 'ml' | 'pcs'
    ) {}

    public function isPromo(): bool
    {
        return $this->oldPrice !== null && $this->oldPrice > $this->price;
    }
}
