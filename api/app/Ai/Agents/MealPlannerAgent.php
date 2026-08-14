<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Mcp\Facades\Mcp;

/**
 * Кулінарний планувальник (Claude) — будує меню тижня від сьогоднішніх акцій
 * та профілю. Повертає СТРОГО MealPlanSchema. Цін/SKU НЕ вигадує — це робить
 * детермінований код (ProductMatchingService + BudgetOptimizerService).
 *
 * Повний system-prompt і правила — docs/02-AI-AGENT.md.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5')]
class MealPlannerAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /** Лише читальні tools для планування (щоб уникнути галюцинацій цін/кошика). */
    private const READ_TOOLS = [
        'silpo_get_promotions',
        'silpo_get_my_food_restrictions',
        'silpo_get_my_family',
    ];

    public function instructions(): string
    {
        return <<<'PROMPT'
        Ти — кулінарний планувальник у застосунку «Розумний кошик» (Mealize) для мережі Сільпо.
        Завдання: скласти меню на тиждень під обмеження гостя, максимально використовуючи
        товари, що СЬОГОДНІ в акції (виклич silpo_get_promotions).

        ПРАВИЛА:
        1. Врахуй РЕЖИМ (mode) з вхідних даних:
           - economy: будуй меню НАВКОЛО акційних товарів, прості бюджетні страви;
           - quality: пріоритет смаку/різноманіттю/уподобанням, акції — приємний бонус.
        2. Строго дотримуйся алергій та дієти (silpo_get_my_food_restrictions). Жоден інгредієнт
           не має містити алерген.
        3. Враховуй склад сімʼї (silpo_get_my_family) для розміру порцій.
        4. Готуй лише на доступній техніці (з вхідних даних). Дотримуйся ліміту часу на страву.
        5. НЕ вигадуй ціни, вартість у грошах чи назви конкретних SKU — це зробить система далі.
           Твоя граммовка — лише кулінарна кількість (напр. "куряче філе 500 г").
        6. Назви інгредієнтів — простими загальними словами українською, без брендів.
        7. Поверни СТРОГО у форматі схеми (JSON), без зайвого тексту.
        PROMPT;
    }

    public function tools(): iterable
    {
        return Mcp::client('silpo')->tools()
            ->filter(fn ($tool) => in_array($tool->name, self::READ_TOOLS, true))
            ->all();
    }

    /** MealPlanSchema — див. docs/02 §4 та docs/11 §API. */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'weekday' => $s->integer()->required(),
                    'meals' => $s->array()->items(
                        $s->object(fn (JsonSchema $s) => [
                            'type' => $s->string()->enum(['breakfast', 'lunch', 'dinner'])->required(),
                            'title' => $s->string()->required(),
                            'cook_minutes' => $s->integer()->required(),
                            'ingredients' => $s->array()->items(
                                $s->object(fn (JsonSchema $s) => [
                                    'name' => $s->string()->required(),
                                    'qty' => $s->number()->required(),
                                    'unit' => $s->string()->enum(['g', 'ml', 'pcs'])->required(),
                                ])
                            )->required(),
                        ])
                    )->required(),
                ])
            )->required(),
        ];
    }
}
