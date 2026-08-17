<?php

namespace Tests\Feature;

use App\Services\Budget\Candidate;
use App\Services\Embeddings\EmbeddingReranker;
use App\Services\Embeddings\VoyageClient;
use Tests\TestCase;

class EmbeddingRerankerTest extends TestCase
{
    public function test_cosine_similarity_bounds(): void
    {
        $this->assertEqualsWithDelta(1.0, EmbeddingReranker::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
        $this->assertEqualsWithDelta(0.0, EmbeddingReranker::cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
        $this->assertEqualsWithDelta(-1.0, EmbeddingReranker::cosine([1.0, 0.0], [-1.0, 0.0]), 1e-9);
    }

    public function test_rerank_falls_back_to_phase1_when_disabled(): void
    {
        config(['services.voyage.key' => null]); // ембединги вимкнені

        $reranker = new EmbeddingReranker(new VoyageClient);
        $candidates = [
            new Candidate('рис', 'a', 'Рис довгозернистий', 60),
            new Candidate('рис', 'b', 'Рис круглозернистий', 40),
        ];

        $out = $reranker->rerank('рис', $candidates, 1);

        $this->assertCount(1, $out);
        $this->assertSame('a', $out[0]->sku); // порядок Фази 1 збережено
    }
}
