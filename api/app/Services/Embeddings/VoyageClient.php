<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клієнт ембедингів Voyage AI (через MongoDB-шлюз ai.mongodb.com).
 * Повертає вектори для текстів; на будь-якій помилці/без ключа — [] (м'який фолбек).
 */
class VoyageClient
{
    public function enabled(): bool
    {
        return ! empty(config('services.voyage.key'));
    }

    /**
     * @param  string[]  $texts
     * @return array<int, array<int, float>> вектори у тому ж порядку (або [] при помилці)
     */
    public function embed(array $texts): array
    {
        if (! $this->enabled() || $texts === []) {
            return [];
        }

        try {
            $res = Http::withToken(config('services.voyage.key'))
                ->timeout(15)
                ->post(rtrim((string) config('services.voyage.base_url'), '/').'/embeddings', [
                    'model' => config('services.voyage.model', 'voyage-3.5-lite'),
                    'input' => array_values($texts),
                ]);

            if ($res->failed()) {
                Log::warning('Voyage embed failed', ['status' => $res->status()]);

                return [];
            }

            return array_map(
                fn ($row) => array_map('floatval', $row['embedding'] ?? []),
                $res->json('data') ?? [],
            );
        } catch (\Throwable $e) {
            Log::warning('Voyage embed error', ['msg' => $e->getMessage()]);

            return [];
        }
    }
}
