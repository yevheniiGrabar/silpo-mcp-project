<?php

namespace Tests\Unit;

use App\Services\Silpo\ProductMatchingService;
use App\Services\Silpo\SilpoClient;
use App\Services\Silpo\SilpoProduct;
use PHPUnit\Framework\TestCase;

class ProductMatchingTest extends TestCase
{
    public function test_reranking_puts_relevant_product_first_and_demotes_blocklisted(): void
    {
        $svc = new ProductMatchingService(new SilpoClient);

        $products = [
            new SilpoProduct('p1', 'Молочний шоколад Millennium', 30),       // блок-лист (шоколад)
            new SilpoProduct('p2', 'Молоко Селянське 2.5% 900 мл', 45, 52),  // релевантне + акція
            new SilpoProduct('p3', 'Вершки 10% 200 мл', 55),                 // нерелевантне
        ];

        $ranked = $svc->rank('молоко', $products, topN: 3);

        // Найрелевантніше — справжнє молоко, а не шоколад.
        $this->assertSame('p2', $ranked[0]->sku);
        $this->assertTrue($ranked[0]->isPromo);            // oldPrice 52 > 45
        $this->assertGreaterThan(0.9, $ranked[0]->confidence);
    }

    public function test_cheaper_relevant_wins_on_tie(): void
    {
        $svc = new ProductMatchingService(new SilpoClient);

        $products = [
            new SilpoProduct('rice-premium', 'Рис довгозернистий', 60),
            new SilpoProduct('rice-cheap', 'Рис круглозернистий', 40),
        ];

        $ranked = $svc->rank('рис', $products, topN: 1);

        // Однакова релевантність → дешевше першим.
        $this->assertSame('rice-cheap', $ranked[0]->sku);
    }
}
