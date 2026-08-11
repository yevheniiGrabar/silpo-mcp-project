# 07 · Оркестрація збірки — фази, агенти, verify-then-push

> Як будувати проєкт кількома агентами під наглядом одного оркестратора. Оркестратор бере наступну незаблоковану фазу → дає воркеру → **перевіряє реальними командами** → тільки після зеленого пушить (гілка `claude/<phase>` + PR).
> Джерела для кожної фази — у нашому `docs/` (посилання в кожній фазі). Інваріанти — `AGENTS.md`.

## Правила оркестратора (жорсткі)
- **1 рівень вкладеності:** оркестратор → воркери. Воркери не плодять воркерів.
- **Гілки:** кожен воркер працює в `claude/<phase-id>`; злиття/PR робить оркестратор.
- **Verify-gate:** фаза вважається done ЛИШЕ якщо пройшли acceptance-команди (нижче). Не зелено → повернути воркеру лог помилки, ≤2 ретраї, далі — позначити BLOCKED і йти далі по незалежних фазах.
- **Стан:** оркестратор веде `BUILD-STATE.json` (phase → todo/in_progress/done/blocked) і коментує PR.
- **Залежності:** дотримуватись `depends_on`. Паралелити лише незалежні фази.
- **Не чіпати main напряму:** усе через PR.

## Acceptance-команди (verify-gate)
- **Backend (Laravel):** `composer install` ok · `php artisan test` (Pest) зелено · `./vendor/bin/pint --test` без порушень · `php -l` на змінених файлах.
- **Mobile (Flutter):** `flutter pub get` ok · `flutter analyze` без error · `flutter test` зелено · `flutter build apk --debug` (або ios) збирається.
- **Спільне:** гілка мержиться без конфліктів; PR має опис що зроблено + які acceptance пройдені.

---

## ФАЗИ

Позначення: `id` · мета · **owner** (тип воркера) · `depends_on` · джерело · acceptance.

### Спільне
- **P0 · Scaffold монорепо** — `api/` (Laravel 12 + `composer require laravel/ai laravel/mcp`), `app/` (Flutter), `docs/`, `AGENTS.md`, CI (GitHub Actions: тести обох). depends_on: — · джерело: `01 §0`, `03 §0` · accept: обидва проєкти збираються, CI зелений.
- **P0.5 · API-контракт** — зафіксувати ендпоінти+DTO (з `01 §6`) у `docs/api-contract.md` (+ OpenAPI за бажанням). Дає мобілці будувати паралельно проти мок-сервера. depends_on: P0 · accept: контракт покриває всі екрани S1–S7.

### Backend (owner: laravel-specialist / php-pro)
- **B1 · БД + міграції + моделі** — усі таблиці з `01 §1` (incl. `mode`, `budget_flex_pct`). depends_on: P0 · accept: `migrate` ok, фабрики/моделі, pint+test зелено.
- **B2 · Silpo-інтеграція** — `SilpoMcpClient` (laravel/mcp), OAuth 2.1+PKCE флоу, `TokenProvider`, **DeliveryContextResolver** (branch→deliveryType→**available slot**). depends_on: P0 · джерело: `01 §2–3`, **`06` (футган слота!)** · accept: інтеграційний тест з моком MCP; OAuth callback зберігає токен (encrypted).
- **B3 · Детерміноване ядро** — `ProductMatchingService` (нормалізація + **ре-ранкінг по категорії** + top-3/confidence) та `BudgetOptimizerService` (**economy/quality**). depends_on: B1 · джерело: `01 §4`, **`06` (матчинг!)** · accept: Pest-тести на фікстурах (без мережі): фільтр відсікає корм/приправи; economy вкладається в бюджет; quality тримає +flex%.
- **B4 · MealPlannerAgent** — агент laravel/ai + system-prompt + MealPlanSchema + SubstitutionExplainer. depends_on: B2 · джерело: `02` · accept: повертає валідний MealPlanSchema під 5 стилів; без алергенів (тест на профілях).
- **B5 · Оркестратор + сервіси** — `MealPlanService` (крок 0 resolve context!), `GenerateMealPlanJob`, `PromotionService`, `RecommendationService`, `CartService` (checkoutWebLink/MobileLink). depends_on: B3, B4 · джерело: `01 §4`, **`06` (канон. флоу кошика)** · accept: e2e з моком MCP: генерація→матчинг→оптимізатор→cart-контекст.
- **B6 · API-шар** — контролери/Resources/FormRequests/роути + SSE `/stream`. depends_on: B5, P0.5 · джерело: `01 §6` · accept: усі ендпоінти віддають за контрактом; feature-тести.
- **B7 · Тести+хардненинг** — покриття оптимізатора/матчингу, rate-limit, 429 backoff. depends_on: B6 · accept: coverage ключових сервісів, pint+test зелено.

### Mobile (owner: flutter-expert)
- **M1 · Каркас+дизайн** — тема/токени (`04`), go_router shell+таби, dio-клієнт, freezed-моделі, secure storage. depends_on: P0 · джерело: `03 §1–2`, `04` · accept: `flutter analyze`/`test` зелено, світла/темна тема.
- **M2 · Auth** — логін через Сільпо (OAuth через BFF), зберігання нашого токена. depends_on: M1, B2(контракт) · джерело: `03 S1` · accept: флоу логіну проти мок/реального BFF.
- **M3 · Home** — рекомендації «патерн п'ятниці» + акції. depends_on: M1, P0.5 · джерело: `03 S2` · accept: рендер з мок-даних, pull-to-refresh, skeleton.
- **M4 · Майстер** — S3a/S3b: люди/алергії/стиль + техніка/час/бюджет + **перемикач режиму economy/quality**. depends_on: M1 · джерело: `03 S3`, `00` (режими) · accept: збирає коректний payload POST /meal-plans.
- **M5 · Генерація** — S4 + live JSON-RPC лог через SSE. depends_on: M4, B6 · джерело: `03 S4` · accept: показує лог, авто-перехід по ready.
- **M6 · Меню+Список** — S5 меню тижня + S6 список з **двома корзинами/лічильником економії** + swap. depends_on: M5 · джерело: `03 S5–S6` · accept: анімований лічильник, swap працює.
- **M7 · Кошик+Checkout** — S7: самовивіз/доставка + checkout-лінки. depends_on: M6, B6 · джерело: `03 S7`, `06` · accept: відкриває checkoutWebLink.
- **M8 · Поліш** — анімації, edge-cases (немає в наявності, порожній матч, > бюджету), reduce-motion. depends_on: M7 · accept: демо-прохід без збоїв.

### Фінал
- **F1 · Демо-контур** — прогрів сценарію, запис екрана, JSON-RPC лог (обов'язково для журі). depends_on: B7, M8 · джерело: `05`.

## Граф залежностей (спрощено)
```
P0 → P0.5
P0 → B1 → B3 ┐
P0 → B2 ─────┼→ B4 → B5 → B6 → B7 ┐
P0.5 --------┘                     ├→ F1
P0 → M1 → {M2,M3,M4} → M5 → M6 → M7 → M8 ┘
        (M5,M7 чекають B6)
```
Паралель: {B1,B2} одночасно; {M1 і весь backend-ланцюг} одночасно (мобілка на моку до B6).
