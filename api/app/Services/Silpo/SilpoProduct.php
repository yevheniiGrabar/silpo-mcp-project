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
    ) {}

    public function isPromo(): bool
    {
        return $this->oldPrice !== null && $this->oldPrice > $this->price;
    }
}
