# 01 · Backend — Laravel BFF (детальне ТЗ)

> Мета: тонкий, але «розумний» бекенд, що тримає токени Сільпо, оркеструє Claude і викликає Silpo MCP, а бюджет-оптимізацію та матчинг рахує детерміновано.
> Архітектура — суворо **Controller → Service → Repository** (+ DTO/Resource). Жодних запитів до БД поза репозиторіями. Сервіси не викликають моделі напряму.

---

## 0. Передумови / встановлення

```bash
# 1. Новий проєкт (перевірити версію: якщо laravel/ai вимагає L13 — постав 13)
composer create-project laravel/laravel rozumnyi-koshyk-api
cd rozumnyi-koshyk-api

# 2. AI + MCP пакети (офіційні, від Laravel)
composer require laravel/ai laravel/mcp

# 3. Публікація конфігів/маршрутів AI
php artisan vendor:publish --tag=ai-config   # перевірити точний тег у доці пакета
# створює routes/ai.php (де реєструються MCP-сервери/клієнти)

# 4. Інфраструктура
composer require predis/predis                 # Redis
# .env: DB (MySQL), REDIS, QUEUE_CONNECTION=redis, CACHE_STORE=redis

# 5. Ключі
# .env:
#   ANTHROPIC_API_KEY=...            (для laravel/ai провайдера)
#   SILPO_MCP_URL=https://mcp.silpo.ua/mcp
#   SILPO_OAUTH_CLIENT_ID=...        (зі starter-kit хакатону)
#   SILPO_OAUTH_REDIRECT=https://<api-host>/api/auth/silpo/callback
```

> ⚠️ Крок для агента-виконавця: відкрити `https://laravel.com/docs/13.x/mcp` + доку `laravel/ai` і звірити **точні** назви фасадів/методів (`Client::web()`, реєстрація провайдера Anthropic, тег vendor:publish). Нижче наведено робочу модель API — уточнити сигнатури під час установки.

---

## 1. База даних (міграції)

Мінімальна схема. Історія/каталог Сільпо **не дублюється** — це живе в MCP; ми кешуємо тимчасово.

### `users` (стандарт Laravel) + додати:
- `silpo_user_id` (string, nullable, index) — id гостя в Сільпо.

### `silpo_tokens` — токени OAuth (server-side, шифровані)
| поле | тип | нотатки |
|---|---|---|
| id | bigint pk | |
| user_id | fk users | |
| access_token | text (encrypted cast) | |
| refresh_token | text (encrypted cast) | |
| expires_at | timestamp | |
| scope | string nullable | |
| timestamps | | |

### `meal_plans` — згенеровані меню
| поле | тип | |
|---|---|---|
| id | bigint pk | |
| user_id | fk | |
| branch_id | string | філія Сільпо |
| budget | integer | ₴ (у копійках або грн — фіксувати одиницю) |
| people | tinyint | |
| diet_style | string | pp/protein/veggie/budget/surprise |
| mode | enum | `economy` (жорсткий бюджет, promotion-first) / `quality` (м'який бюджет +10–30%, preference-first) |
| budget_flex_pct | tinyint | 0 для economy; 10–30 для quality (наскільки можна вийти за бюджет) |
| appliances | json | ['stove','oven',...] |
| max_cook_minutes | smallint nullable | |
| allergies | json | |
| status | enum | pending/generating/ready/failed |
| currency | string(3) | UAH |
| naive_total | integer nullable | «звичайний» кошик для порівняння |
| optimized_total | integer nullable | |
| savings | integer nullable | |
| timestamps | | |

### `meal_plan_days` → `meal_plan_meals`
- `meal_plan_days`: id, meal_plan_id fk, weekday (1..7).
- `meal_plan_meals`: id, meal_plan_day_id fk, type (breakfast/lunch/dinner), title, recipe_json (кроки, час, техніка), servings.

### `meal_plan_ingredients` — нормалізовані інгредієнти
- id, meal_plan_id fk, name_raw, name_normalized, qty, unit (g/ml/pcs), source_meal_ids json.

### `cart_items` — підібрані товари (результат матчингу)
| поле | тип | |
|---|---|---|
| id | pk | |
| meal_plan_id | fk | |
| ingredient_id | fk meal_plan_ingredients | |
| silpo_product_id | string | SKU |
| title | string | |
| qty | integer | штук/упаковок |
| price | integer | ціна за од. |
| price_total | integer | |
| is_promo | bool | |
| promo_label | string nullable | «−25%» |
| is_private_label | bool | Власна марка |
| match_confidence | decimal(3,2) | 0..1 |
| alt_options | json | top-3 кандидати для swap |

### `purchase_pattern_cache` (опційно) — для «патерн п'ятниці»
- user_id, weekday, products_json, computed_at. (Або рахувати on-the-fly з `get_my_offline_orders`.)

---

## 2. MCP-клієнт до Сільпо (`laravel/mcp`)

Обгортка над MCP-клієнтом — **єдина точка** доступу до Сільпо.

`app/Silpo/SilpoMcpClient.php`
```php
final class SilpoMcpClient
{
    public function __construct(private TokenProvider $tokens) {}

    // Повертає інструменти для передачі в агента laravel/ai
    public function toolsFor(User $user): array
    {
        $token = $this->tokens->accessTokenFor($user); // refresh якщо треба
        return Client::web(config('services.silpo.mcp_url'))
            ->withToken($token)
            ->tools();
    }

    // Прямий виклик конкретного tool (для детермінованих кроків: promotions, batch, cart)
    public function call(User $user, string $tool, array $args): array
    {
        $token = $this->tokens->accessTokenFor($user);
        return Client::web(config('services.silpo.mcp_url'))
            ->withToken($token)
            ->call($tool, $args); // уточнити метод у доці
    }
}
```

**Ключові виклики, які використовуємо:**
`list_branches`, `get_available_delivery_types`, `get_my_family`, `get_my_food_restrictions`, `get_promotions`, `get_my_promos`, `get_my_coupons`, `find_products_batch`, `get_product_details`, `get_similar_products`, `get_replacements`, `get_my_online_orders`, `get_my_offline_orders`, `add_or_update_cart_products`, `update_shopping_cart`, `get_my_shopping_cart`, `get_shopping_cart_by_id`.

**Логування JSON-RPC (обов'язкове для демо):** middleware/decorator навколо `call()`, що пише кожен запит/відповідь у канал `silpo-mcp` (окремий лог-файл) + опційно стрімить у фронт через SSE/websocket на екрані генерації.

---

## 3. OAuth 2.1 + PKCE (auth.silpo.ua)

Окремий, найтонший «ручний» шматок.

`AuthController`:
- `GET /api/auth/silpo/start` → генерує `code_verifier`+`code_challenge`, редіректить на `auth.silpo.ua/authorize?...&code_challenge=...`.
- `GET /api/auth/silpo/callback` → обмінює `code` (+ verifier) на `access/refresh` токени → зберігає в `silpo_tokens` (encrypted) → створює/лінкує `users.silpo_user_id` → видає нашому фронту **власний** сесійний токен (Sanctum).
- `TokenProvider::accessTokenFor(User)` — повертає валідний токен, автоматично рефрешить за `expires_at`.

Flutter отримує **наш** Sanctum-токен, ніколи не токен Сільпо.

---

## 4. Сервіси (бізнес-логіка)

### `MealPlanService` — головний оркестратор
`generate(GenerateMealPlanRequest $dto): MealPlan`
0. **Резолв контексту доставки (ОБОВ'ЯЗКОВО, див. `06-MCP-VERIFIED`):** branchId → deliveryType → `silpo_get_time_slots` → взяти слот з `available:true`. Зберегти {branchId, deliveryType, timeslotStart, timeslotEnd} і передавати їх у КОЖЕН виклик promotions/products. ⚠️ З недоступним слотом пошук повертає 0 навіть для базових продуктів.
1. Створити `meal_plans` (status=generating).
2. `PromotionService::currentPromotions($user, $ctx)` → сьогоднішні акції.
3. `MealPlannerAgent::plan($constraints, $promotions)` → меню (дні/страви) + список інгредієнтів (див. `02`).
4. Зберегти дні/страви/інгредієнти.
5. `ProductMatchingService::match($ingredients, $branchId)` → кандидати SKU (find_products_batch).
6. `BudgetOptimizerService::optimize($candidates, $budget, $promotions, $coupons)` → фінальний кошик + `naive_total` + `optimized_total` + `savings`.
7. Зберегти `cart_items`, оновити тотали, status=ready.
8. Повернути `MealPlanResource`.

Запускати в **черзі** (job `GenerateMealPlanJob`), а фронт опитує статус / слухає SSE. Демо-режим: синхронно на 1 філії, 5–7 вечер.

### `ProductMatchingService` — детермінований матчинг «інгредієнт → SKU»
- Нормалізація назв (нижній регістр, синоніми: «цибуля»≈«лук»; словник у `config/ingredients.php`).
- Нормалізація кількостей/одиниць («2 яйця»→pcs:2; «300 г філе»→g:300).
- `find_products_batch` (до 30 за раз, батчами) → кандидати.
- Ранжування: fuzzy-скор назви × наявність × (акція бонус) × (private-label бонус).
- Зберігати **top-3** (`alt_options`) + `match_confidence`. Low-confidence (<0.6) позначати для підтвердження в UI.

### `BudgetOptimizerService` — constraint-оптимізатор (НЕ LLM)
Поведінка залежить від `mode` (гість обрав на майстрі).

**Спільне:**
- Вхід: кандидати (з цінами/акціями), бюджет, купони, промо, `mode`, `budget_flex_pct`.
- Крок 1: «наївний» кошик (перший-ліпший кандидат) → `naive_total` (для порівняння/економії).

**Режим `economy` (жорсткий бюджет, promotion-first):**
- Ефективний ліміт = `budget`.
- greedy/knapsack: поки сума > ліміту — заміняти дорогі позиції на вигідніші (`get_replacements`/`alt_options`), пріоритет: **акційні → private-label → дешевші аналоги**, без порушення алергій/дієти.
- Застосувати купони/промо (`get_my_coupons`, `get_my_promos`).
- Мета: вкластися й максимізувати економію.

**Режим `quality` (м'який бюджет, preference-first):**
- Ефективний ліміт = `budget * (1 + budget_flex_pct/100)`.
- Вибір кандидата зважує **якість/відповідність уподобанням** (бренд/фреш), а не мінімальну ціну; акції враховуються як бонус, але НЕ диктують.
- Менше даунгрейдів; якщо лишився бюджет — **upsell/добір** (позначити окремо).
- Купони/промо застосовуються, якщо доречні.
- Мета: найкраще меню в межах гнучкого бюджету.

- Вихід (обидва): фінальні `cart_items`, `optimized_total`, `savings = naive_total − optimized_total` (для quality економія може бути меншою — це нормально, показуємо «якість/цінність», а не лише економію).

### `PromotionService`
- `currentPromotions($user,$branch)` → `get_promotions` (+кеш 15 хв у Redis).
- `personalPromos($user)` → `get_my_promos`, `get_my_coupons`.

### `RecommendationService` — «патерн п'ятниці»
- `weekdayPattern($user, $weekday)` → з `get_my_offline_orders`+`get_my_online_orders` вибрати часто-куповані позиції саме цього дня тижня → перетнути з `get_promotions` («зараз в акції»).
- Повертає картку для головного екрана (S2).

### `CartService` (канонічний флоу — див. `06-MCP-VERIFIED`)
- `pushToSilpo($mealPlan)`:
  1. `silpo_get_my_shopping_cart` → cartId (АКТИВНИЙ кошик гостя; якщо "Resource not found" — у гостя немає активного кошика, обробити фолбеком/повідомленням).
  2. `silpo_get_shopping_cart_by_id(cartId)` → поточний branchId/deliveryType/timeslot. Якщо гість обрав інший branch — `silpo_update_shopping_cart(branchId=...)`.
  3. `silpo_add_or_update_cart_products(cartId, products[{productId, companyId, branchId, quantity}])` — усі cart_items.
  4. `silpo_get_shopping_cart_by_id(cartId)` → повернути тотали, `validations[]`, `loyalty.bonusAvailable`, express-опцію та **`checkoutWebLink` + `checkoutMobileLink`** (окремого checkout-виклику немає).
- ⚠️ Створити кошик через MCP не можна — він має вже існувати (активний кошик гостя). Для демо — акаунт з активним кошиком.

---

## 5. Репозиторії
`MealPlanRepository`, `SilpoTokenRepository`, `UserRepository`. Повертають Model/Collection. Уся робота з Eloquent — тут.

## 6. Контролери / API (для Flutter)

| Метод | Роут | Контролер | Що робить |
|---|---|---|---|
| GET | `/api/auth/silpo/start` | AuthController@start | OAuth редірект |
| GET | `/api/auth/silpo/callback` | AuthController@callback | обмін токенів |
| GET | `/api/me` | ProfileController | профіль + prefill (family, restrictions) |
| GET | `/api/branches` | BranchController | `list_branches` (+delivery types) |
| GET | `/api/home` | HomeController | картка «патерн п'ятниці» + акції (S2) |
| POST | `/api/meal-plans` | MealPlanController@store | старт генерації → 202 + id |
| GET | `/api/meal-plans/{id}` | MealPlanController@show | статус + меню + список (полінг) |
| GET | `/api/meal-plans/{id}/stream` | MealPlanController@stream | SSE: live JSON-RPC лог для екрана генерації |
| POST | `/api/meal-plans/{id}/items/{item}/swap` | CartController@swap | замінити позицію на alt |
| POST | `/api/meal-plans/{id}/checkout` | CartController@checkout | зібрати кошик → checkout_url |

Відповіді — через API Resources (`MealPlanResource`, `CartItemResource`, `HomeResource`). Валідація — FormRequest (`StoreMealPlanRequest`: budget, **mode** (economy|quality), **budget_flex_pct** (0 для economy; 10–30 для quality), people, diet_style, appliances[], allergies[], max_cook_minutes, branch_id).

## 7. Черги / Jobs
- `GenerateMealPlanJob` — уся важка генерація (агент + матчинг + оптимізатор).
- Retry ≤ 2, backoff; на фейл → status=failed + причина.

## 8. Безпека / правила
- Токени Сільпо — `encrypted` cast, ніколи не в лог/відповідь.
- Rate-limit на `/meal-plans` (дорогий ендпоінт).
- MCP 429 → експоненційний backoff у `SilpoMcpClient`.
- Уся робота з БД — тільки в репозиторіях.

## 9. Definition of Done (backend)
- [ ] OAuth-логін Сільпо працює, токен серверно.
- [ ] `/api/home` віддає реальну картку з `get_my_offline_orders` + `get_promotions`.
- [ ] `POST /api/meal-plans` генерує меню тижня, матчить SKU, рахує економію.
- [ ] `checkout` повертає робочий Silpo checkout_url.
- [ ] Окремий лог-канал `silpo-mcp` пише кожен JSON-RPC виклик (для демо).
- [ ] Pest-тести на оптимізатор і матчинг (детерміновані, без мережі — мок MCP).
