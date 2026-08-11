# LC-PHASES · Фази збірки «Останній Шанс» + verify-then-push

> Оркестратор бере наступну незаблоковану фазу → воркер у гілці `claude/<phase>` → verify реальними командами → тільки зелене → PR. Джерело деталей: `LC-BUILD`, `06-MCP-VERIFIED`.
> Acceptance-команди: `composer install` · `php artisan test` (Pest) · `./vendor/bin/pint --test` · `php -l`.

## Фази
- **P0 · Scaffold** — Laravel 12 `api/` + `composer require laravel/ai laravel/mcp telegram-bot-sdk predis`, .env, CI (GitHub Actions). depends_on: — · accept: збирається, CI зелений.
- **P0.1 · SPIKE MCP** — довести laravel/ai+mcp проксують OAuth-bearer у mcp.silpo.ua і крутять tool-loop; інакше raw JSON-RPC клієнт-фолбек. depends_on: P0 · джерело: `LC-BUILD §0`,`06` · accept: живий `tools/list` через обраний шлях. **Робити ОДРАЗУ.**
- **DEMO-ACC · демо-акаунт** (ручна дія людини) — акаунт Сільпо з непорожніми купонами (з датами згорання) + історією покупок + активним кошиком. depends_on: — · accept: get_my_coupons має купон з endDate, get_my_offline_orders має історію, get_my_shopping_cart віддає cartId.
- **B1 · БД + моделі** — міграції з `LC-BUILD §1` (users+telegram_chat_id, silpo_tokens, last_chance_offers, offer_events). depends_on: P0 · accept: migrate ok, фабрики, pint+test.
- **B2 · Silpo-інтеграція** — `SilpoMcpClient` (обраний шлях) + OAuth 2.1+PKCE + `TokenProvider` + `resolveContext` (branch→deliveryType→**available slot**) + лог `silpo-mcp`. depends_on: P0.1 · джерело: `06`,`LC-BUILD §2` · accept: інтеграційний тест з моком; OAuth callback зберігає encrypted токен.
- **B3 · LastChanceService (ЯДРО)** — детермінований матчинг: стимули-що-згорають × глибокі уцінки × звичні товари → ефективна ціна → offer. depends_on: B1,B2 · джерело: `LC-BUILD §3` · accept: Pest на фікстурах — обирає купон з найближчим endDate × найглибшу уцінку × звичний SKU; cold-start фолбек; без мережі (мок MCP).
- **B4 · Кошик+checkout** — get_my_shopping_cart→add_or_update→get_shopping_cart_by_id→checkoutWebLink; безпечний empty-state якщо кошика немає. depends_on: B3 · джерело: `06`(кошик) · accept: на demo-акаунті збирає кошик і віддає реальний checkout-лінк.
- **T1 · Telegram-бот** — /start OAuth deep-link, /deal → картка offer з таймером і кнопками [Оформити](checkout_url), webhook. depends_on: B3(,B4) · джерело: `LC-BUILD §5` · accept: живий бот показує реальну картку.
- **T2 · Проактивний push** — Redis-черга/cron: стимул згорає ≤24-48год + matched-уцінка → push. depends_on: T1 · accept: тест черги.
- **A1 · Агент+пояснення (опц.)** — laravel/ai тонкий агент: намір з TG → LastChanceService → людське пояснення (tone-guardrail). depends_on: B3,T1 · джерело: `LC-BUILD §4`.
- **D1 · B2B-дашборд (опц.)** — метрики redemption/sell-through/списання/конверсія. depends_on: B3 · джерело: `LC-BUILD §5`.
- **B5 · Тести+хардненинг** — покриття матчингу, rate-limit, 429 backoff, 500 на certificates. depends_on: B4,T1 · accept: coverage, pint+test.
- **F1 · Demo-контур** — прогрів сценарію на demo-акаунті, запис екрана, JSON-RPC лог, слайд economics (economics на живих даних + план пілоту вузької категорії). depends_on: B5,T1,DEMO-ACC · джерело: `PITCH-LAST-CHANCE`.

## Граф
```
P0 → P0.1 → B2 ┐
P0 → B1 ───────┼→ B3 → B4 ┐
DEMO-ACC ──────┘          ├→ T1 → T2 ┐
                          └→ (A1,D1) ├→ B5 → F1
```

## Моделі (якщо середовище дозволяє)
Оркестратор — Opus 5/high; воркери — Sonnet 5/high; B3 (ядро-матчинг) — Opus 5/high.
