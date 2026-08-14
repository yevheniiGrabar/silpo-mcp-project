# 11 · BUILD — API (Laravel 13 BFF) · закріплений перевірений стек

> Актуальний план збірки бекенду. Розширює `01-BACKEND.md` (архітектура/схема БД) та
> `02-AI-AGENT.md` (агент/промпти) РЕАЛЬНИМ API пакетів `laravel/ai` + `laravel/mcp`
> (перевірено на Packagist + офіційній доці 13.x, 2026-08). Код живе в `api/`.

## Стек (перевірено)
- PHP 8.4 · Composer 2.10 · Laravel Installer 5.28 (локально є).
- **laravel/ai** v0.10.x (потребує php ^8.3, illuminate ^12|^13) — AI SDK (агенти, структурний вивід).
- **laravel/mcp** v0.9.x (illuminate ^11.45|^12.41|^13) — MCP-КЛІЄНТ до Сільпо.
- App → **BFF** → Silpo MCP. Токени Сільпо server-side. Оплата = checkout-лінк (юзер тапає).
- Архітектура: **Controller → Service → Repository** (+ DTO/Resource). БД лише в репозиторіях.

## Перевірений API (НЕ гадати — це реальні сигнатури)

### MCP-клієнт (`laravel/mcp`)
```php
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

// Реєстрація іменованого клієнта (у AppServiceProvider::boot або routes/ai.php)
Mcp::registerClient('silpo', fn () =>
    Client::web(config('services.silpo.mcp_url'))->withToken($token) // token: string|Closure
);

// Виклик конкретного tool (детерміновані кроки: promotions/products/cart)
$res = Mcp::client('silpo')->callTool('silpo_list_branches', [...]);
$res->text();              // текст
$res->structuredContent;   // структура
$res->isError;             // помилка?

// Список tools (для передачі агенту)
$tools = Mcp::client('silpo')->tools(); // кожен: ->name ->title ->description ->inputSchema
```
> Silpo tools мають префікс **silpo_** (див. `06-MCP-VERIFIED`). Футгани: promotions/products
> вимагають branchId+deliveryType+timeslot з available:true; кошик не створюється через MCP;
> checkout = поле `checkoutWebLink`.

### AI-агент (`laravel/ai`)
```php
use Laravel\Ai\Contracts\{Agent, HasTools, HasStructuredOutput};
use Laravel\Ai\Promptable;
use Laravel\Ai\Attributes\{Provider, Model};
use Laravel\Ai\Enums\Lab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Facades\Mcp;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5')]           // demo: sonnet; можна opus-5
class MealPlannerAgent implements Agent, HasTools, HasStructuredOutput
{
    use Promptable;
    public function instructions(): string { return /* system-prompt з 02 */; }
    public function tools(): iterable {
        return [ ...Mcp::client('silpo')->tools() ]; // лише читальні; обмежимо список
    }
    public function schema(JsonSchema $s): array {  // MealPlanSchema (02 §4)
        return ['days' => $s->array()->items($s->object(fn($s)=>[
            'weekday' => $s->integer()->required(),
            'meals'   => $s->array()->items($s->object(fn($s)=>[
                'type' => $s->string()->enum(['breakfast','lunch','dinner'])->required(),
                'title'=> $s->string()->required(),
                'cook_minutes'=> $s->integer()->required(),
                'ingredients'=> $s->array()->items($s->object(fn($s)=>[
                    'name'=>$s->string()->required(),'qty'=>$s->number()->required(),
                    'unit'=>$s->string()->enum(['g','ml','pcs'])->required(),
                ]))->required(),
            ]))->required(),
        ]))->required()];
    }
}
// $plan = (new MealPlannerAgent)->prompt($userPrompt); // доступ як масив
```
> ⚠️ Planner-агенту давати ЛИШЕ читальні tools (get_promotions/get_my_food_restrictions/
> get_my_family). Матчинг/кошик/ціни — детермінований PHP (BudgetOptimizer), НЕ LLM.

## Фази збірки (acceptance)
- **B0 · Scaffold** — `composer create-project laravel/laravel api`; `composer require laravel/ai laravel/mcp predis/predis`; `.env` (ANTHROPIC_API_KEY, SILPO_MCP_URL, DB, REDIS). acc: `php artisan route:list` ок.
- **B1 · MCP-клієнт live** — `Mcp::registerClient('silpo')` + `services.silpo`; артизан-команда `silpo:ping` кличе `silpo_list_branches` з токеном. acc: реальні філії + JSON-RPC у лог-каналі `silpo-mcp`.
- **B2 · OAuth Сільпо** — AuthController start/callback (PKCE) або `Mcp::oAuthRoutesFor('silpo')`; `silpo_tokens` (encrypted); Sanctum-токен фронту. acc: логін дає серверний токен.
- **B3 · Матчинг+оптимізатор (детерм., БЕЗ мережі)** — ProductMatchingService (ре-ранкінг) + BudgetOptimizer (economy/quality). acc: **Pest-тести зелені на моках MCP** (це головний надійний DoD).
- **B4 · /menu/generate** — MealPlannerAgent → матчинг → оптимізатор → meal_plan+cart_items+savings (naive vs optimized). Черга GenerateMealPlanJob. acc: меню тижня в бюджет, реальні SKU.
- **B5 · /checkout-link** — CartService канонічний флоу (get_my_shopping_cart → add_or_update → checkoutWebLink). acc: робочий лінк.
- **B6 · Хардненинг** — 429/500 backoff у клієнті, rate-limit /menu, лог-канал, Resources. acc: стабільно.

## Ендпоінти (для Flutter) — див. таблицю в `01-BACKEND` §6
`/api/auth/silpo/*`, `/api/me`, `/api/branches`, `/api/home`, `POST /api/meal-plans`,
`GET /api/meal-plans/{id}`, `.../swap`, `.../checkout`.

## Мультиринок (звʼязок із фронтом)
Flutter `StoreProvider` бʼє не в MCP напряму, а в BFF (`/api/stores/silpo/...`). BFF тримає
провайдерів: SilpoProvider (live) → далі Instacart/Kroger (US) окремими клієнтами. Абстракція
на бекенді дзеркалить `lib/data/stores.dart` у мобілці.
