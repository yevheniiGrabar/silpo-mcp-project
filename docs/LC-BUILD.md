# LC-BUILD · Що будувати — «Останній Шанс» (детальне ТЗ)

> Читати разом з `00-OVERVIEW`, `06-MCP-VERIFIED` (механіка MCP + футгани), `CONCEPT-LAST-CHANCE`, `PITCH-LAST-CHANCE`. Архітектура: суворо Controller → Service → Repository.

## 0. Стек / встановлення
```bash
composer create-project laravel/laravel api   # Laravel 12 (перевірити констрейнт пакетів)
cd api && composer require laravel/ai laravel/mcp predis/predis
composer require irazasyed/telegram-bot-sdk   # Telegram-бот (або defstudio/telegraph)
# .env: ANTHROPIC_API_KEY, SILPO_MCP_URL=https://mcp.silpo.ua/mcp, TELEGRAM_BOT_TOKEN, DB(MySQL), REDIS
```
⚠️ ДЕНЬ-1 SPIKE: перевірити, що `laravel/ai`+`laravel/mcp` проксують OAuth-bearer у mcp.silpo.ua і крутять tool-loop. Якщо ні — **фолбек: власний raw JSON-RPC клієнт** (initialize→notifications/initialized→tools/call, protocol 2025-06-18, Mcp-Session-Id — вже перевірений вручну).

## 1. База даних (міграції)
- `users`: + `silpo_user_id`, `telegram_chat_id` (nullable, index).
- `silpo_tokens`: user_id, access_token(encrypted), refresh_token(encrypted), expires_at, scope.
- `last_chance_offers`: id, user_id, coupon_id, coupon_end_date, product_id, product_title, old_price, price, discount_pct, effective_price_after_incentive, habit_match(bool), branch_id, checkout_url, status(generated/sent/opened/converted/expired), created_at.
- (опц.) `offer_events`: offer_id, type(sent/opened/checkout), at — для валідації (redemption/conversion).

## 2. Silpo MCP-клієнт (єдина точка)
`SilpoMcpClient` — обгортка (laravel/mcp АБО raw). Методи: `resolveContext(user)` (branch→deliveryType→available slot, кеш), `call(user, tool, args)`. **Логувати кожен JSON-RPC у канал `silpo-mcp`** (для demo + дашборд).
OAuth 2.1+PKCE флоу (auth.silpo.ua) — `AuthController` (start/callback), токени encrypted, `TokenProvider` рефрешить.

## 3. ЯДРО — LastChanceService (детермінований матчинг, НЕ LLM)
`buildOffer(User $user): ?LastChanceOffer`
1. **Контекст:** `resolveContext` (див. 06 — обовʼязково доступний слот).
2. **Стимули, що згорають:** `get_my_coupons` → купони з `endDate ≤ N днів` (N=3..7); `get_loyalty_info` → бонусний баланс; `get_my_promos`; `get_my_certificates` (try/catch 500). Відсортувати за терміном (що ближче — вище urgency).
3. **Глибокі уцінки (near-expiry proxy):** `get_products({...ctx, mustHavePromotion:true, inStock:true, limit:60})` → відкинути без `oldPrice`, порахувати `discount = (oldPrice-price)/oldPrice`, відсортувати за глибиною.
4. **Звичні товари:** `get_my_offline_orders` (+ `get_my_favorites`) → множина SKU/назв, які гість реально бере.
5. **Матчинг (score):** кандидат = (уцінений товар) × (застосовний стимул) × (звичність). `score = 0.45·habit_match + 0.35·discount_depth + 0.20·incentive_urgency`. Порахувати **ефективну ціну після стимулу**. Взяти топ-1..3.
   - Пріоритет: товар зі звичної корзини. Якщо історії немає (cold-start) — фолбек на найглибші уцінки в базових категоріях + чесна позначка.
6. **Зібрати кошик:** `get_my_shopping_cart` → якщо є cartId: `add_or_update_cart_products` → `get_shopping_cart_by_id` (тотали, validations[], **checkoutWebLink/MobileLink**). Якщо кошика немає → offer без авто-кошика, checkout-лінк-заглушка + підказка активувати кошик (див. 06 — кошик не створюється через MCP).
7. Зберегти `last_chance_offer`, повернути.
> Safe-default: нічого не купується без явного тапу гостя.

## 4. Агент (laravel/ai, опційно) + пояснення
Для природномовних запитів у TG («що в мене горить?», «збери вигідне») — тонкий агент laravel/ai з tools Сільпо (read-only) + виклик LastChanceService. Матчинг/ціни — детерміновано, LLM лише розуміє намір і формулює людське пояснення пропозиції (1-2 речення, tone-guardrail: дружньо, про вигоду, ніколи не тиск/сором).

## 5. Канали
### Telegram-бот (головний)
- `/start` → OAuth-лінк Сільпо (deep-link на web-callback) → зберегти chat_id↔user.
- `/deal` або кнопка «Що горить?» → LastChanceService → **картка з таймером**: товар, стара→нова ціна, купон що згорає (дата), ефективна ціна, кнопки [Додати в кошик]/[Оформити](checkout_url)/[Пропустити].
- Проактивний push (Redis-черга/cron): коли у гостя стимул згорає ≤24-48 год і є matched-уцінка.
### B2B-дашборд (опційно, web)
- Категорійним менеджерам: redemption-rate, sell-through уцінки, ₴ скорочених списань по SKU, конверсія offer→checkout. Blade/Livewire.

## 6. API / контролери
`AuthController` (silpo OAuth), `TelegramWebhookController` (updates), `OfferController` (GET /api/offers/{user}, POST /offers/{id}/checkout), `DashboardController` (метрики). Валідація FormRequest, відповіді Resource.

## 7. Definition of Done
- [ ] Spike laravel/ai+mcp АБО raw-клієнт — живий tools/list.
- [ ] OAuth Silpo, токени server-side.
- [ ] LastChanceService збирає offer на живих даних (купон-що-згорає × глибока уцінка × звичний товар).
- [ ] TG-бот: /start OAuth, /deal віддає картку з реальним checkoutWebLink.
- [ ] Лог `silpo-mcp` пише кожен JSON-RPC (demo).
- [ ] Pest-тести матчингу на фікстурах (детерміновано, мок MCP).
- [ ] (опц.) дашборд-метрики.
