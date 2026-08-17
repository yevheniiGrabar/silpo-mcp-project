<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Тайбрейкер матчингу (Haiku, дешево) — обирає найкращий товар зі списку
 * кандидатів для інгредієнта, коли лексика+категорія+семантика не впевнені.
 * Викликається лише для «хвоста» (низька впевненість). Повертає {sku}.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class MatchPickerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Ти обираєш товар у магазині, що НАЙКРАЩЕ відповідає інгредієнту рецепта
        для приготування страви. Тобі дають назву інгредієнта, його категорію та
        список товарів (sku + назва).

        Правила вибору:
        - бери базовий продукт для готування, а не напівфабрикат, десерт, снек,
          корм чи дитяче харчування;
        - враховуй категорію інгредієнта (напр. «крупи» → крупа, а не борошно/каша
          швидкого приготування; «молочне: молоко» → молоко, а не шоколад/вершки);
        - якщо кілька підходять — обери найтиповіший і найдешевший на вигляд.

        Поверни СТРОГО {"sku": "<sku одного товару зі списку>"}. Тільки sku зі списку.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['sku' => $schema->string()->required()];
    }
}
