<?php

namespace App\Services\Silpo;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Facades\Mcp;
use Throwable;

/**
 * Єдина точка доступу до Сільпо MCP (docs/01 §2, §8): retry з backoff на
 * транзієнтних помилках (429/5xx/timeout) + лог кожного виклику у канал silpo-mcp.
 */
class SilpoClient
{
    public function __construct(
        private readonly int $maxRetries = 2,
        private readonly int $baseDelayMs = 200,
    ) {}

    public function tools(): Collection
    {
        return Mcp::client('silpo')->tools();
    }

    public function call(string $tool, array $args = []): ToolResult
    {
        return $this->attempt(fn (): ToolResult => Mcp::client('silpo')->callTool($tool, $args), $tool);
    }

    /** Виклик + декод JSON-тіла відповіді (Silpo віддає дані у text). [] на помилку. */
    public function callData(string $tool, array $args = []): array
    {
        $result = $this->call($tool, $args);
        if ($result->isError) {
            return [];
        }
        $decoded = json_decode($result->text(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Виконати виклик з ретраями на транзієнтних помилках.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public function attempt(callable $fn, string $label = '')
    {
        for ($i = 0; ; $i++) {
            try {
                $result = $fn();
                Log::channel('silpo-mcp')->info('CALL', ['label' => $label, 'attempt' => $i]);

                return $result;
            } catch (Throwable $e) {
                if ($i < $this->maxRetries && $this->isTransient($e)) {
                    if ($this->baseDelayMs > 0) {
                        usleep($this->baseDelayMs * 1000 * (2 ** $i)); // 200ms, 400ms, ...
                    }

                    continue;
                }
                Log::channel('silpo-mcp')->error('EXCEPTION', [
                    'label' => $label, 'attempts' => $i + 1, 'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /** Транзієнтна помилка → варто ретраїти (429, 5xx, таймаут/конект). */
    public function isTransient(Throwable $e): bool
    {
        return (bool) preg_match('/\b(429|5\d\d)\b|timed out|timeout|connection/i', $e->getMessage());
    }
}
