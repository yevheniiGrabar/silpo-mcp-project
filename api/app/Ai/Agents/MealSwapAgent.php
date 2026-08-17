<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Заміна ОДНОЇ страви (Haiku, швидко): пропонує альтернативу для слота
 * (сніданок/обід/вечеря) з урахуванням системи харчування, алергій та мети.
 * Повертає одну страву у форматі схеми планувальника (щоб код зібрав кошик).
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[Timeout(30)]
class MealSwapAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Ти пропонуєш ОДНУ альтернативну страву замість заданої (той самий тип прийому:
        сніданок/обід/вечеря). Дотримуйся жорстко: система харчування (вег/веган/кето…),
        алергії/виключення (жодних слідів). Врахуй мету: cheaper — простіша й дешевша;
        healthier — легша/корисніша; variety — просто інша, але в тому ж стилі.

        Для КОЖНОГО інгредієнта заповни: name (проста назва укр), category (з переліку схеми),
        search (1-3 «магазинних» запити без брендів), qty (кулінарна к-сть на сім'ю), unit (g/ml/pcs).
        Додай kcal (орієнтовно на 1 порцію) і photo_hint (короткий опис вигляду страви).
        Назви — без брендів. Поверни СТРОГО у форматі схеми, без зайвого тексту.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'cook_minutes' => $schema->integer()->required(),
            'kcal' => $schema->integer()->required(),
            'photo_hint' => $schema->string(),
            'ingredients' => $schema->array()->min(1)->max(12)->items(
                $schema->object(fn (JsonSchema $s) => [
                    'name' => $s->string()->required(),
                    'category' => $s->string()->enum([
                        'м\'ясо', 'птиця', 'риба', 'молочне', 'яйця', 'овочі',
                        'фрукти', 'крупи', 'бакалія', 'олія', 'хліб', 'соуси',
                        'напої', 'солодощі', 'заморожене', 'інше',
                    ])->required(),
                    'search' => $s->array()->items($s->string())->required(),
                    'qty' => $s->number()->required(),
                    'unit' => $s->string()->enum(['g', 'ml', 'pcs'])->required(),
                ])
            )->required(),
        ];
    }
}
