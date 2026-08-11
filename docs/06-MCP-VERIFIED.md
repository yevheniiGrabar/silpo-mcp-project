# 06 · Silpo MCP — ПЕРЕВІРЕНО наживо (2026-08-11)

> Реальний прогін через OAuth-токен. Усе нижче — не з документації, а з фактичних відповідей сервера. Ці факти економлять агенту-виконавцю години.

## Авторизація — працює end-to-end
- `POST /register` (Dynamic Client Registration) → повертає `client_id` (public client, `token_endpoint_auth_method: none`, без secret).
- PKCE **S256**. `/authorize` → гість логіниться на auth.silpo.ua (телефон+OTP) → redirect на `redirect_uri?code=...`.
- `POST /token` (grant_type=authorization_code + code_verifier) → `access_token` (bearer), **expires_in = 2592000 (30 днів)**, є `refresh_token`.
- Усі виклики: заголовок `Authorization: Bearer <mcp_token>`.
- ⚠️ redirect_uri у тесті = `http://localhost:8765/callback` (без сервера — code копіюється з адресного рядка). У проді — реальний callback-ендпоінт Laravel.

## MCP транспорт
- Endpoint: `POST https://mcp.silpo.ua/mcp`, JSON-RPC 2.0, `Accept: application/json, text/event-stream`.
- Хендшейк: `initialize` → `notifications/initialized` → далі `tools/list` / `tools/call`.
- Protocol version, що спрацював: `2025-06-18`. Сесія — через заголовок `Mcp-Session-Id` (сервер повертає, клієнт віддає назад).
- `tools/call` формат: `{"name":"silpo_<tool>","arguments":{...}}`. Результат — у `result.content[].text` (JSON-рядок).

## ⚠️ Назви інструментів мають префікс `silpo_`
У доці бували без префікса — насправді **`silpo_get_promotions`, `silpo_find_products_batch`, `silpo_list_branches`** тощо. 39 tools підтверджено.

## ⚠️⚠️ ГОЛОВНИЙ ФУТГАН: обов'язковий контекст доставки + ДОСТУПНИЙ таймслот
`silpo_get_promotions` і `silpo_find_products_batch` **вимагають** усі поля:
`branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd`.

**Найважливіше:** якщо взяти таймслот з `available:false` — пошук повертає **0 результатів навіть для базових продуктів** (курка, яйця, цибуля = порожньо). З таймслотом `available:true` ті самі запити повертають по 30 товарів.

➡️ **Правильний порядок у бекенді перед будь-яким пошуком/акціями:**
1. `silpo_list_branches` → обрати branchId (є `hasPickup`, `open`, координати).
2. Визначити deliveryType (напр. `SelfPickup`).
3. `silpo_get_time_slots(branchId, deliveryType)` → **взяти слот з `available:true`** (беремо перший доступний).
4. Тільки тепер викликати `get_promotions` / `find_products_batch` з цим слотом.

`deliveryType` enum: `Unknown, SelfPickup, DeliveryHome, DeliveryFlat, DeliveryOffice, DeliveryGlovo, DeliveryExpress, DeliveryExpressFood, JustIn, LongDelivery, JustInPost, NovaPoshta, DeliveryExpressByPromise, WideAssortDelivery`.
> Примітка: `silpo_get_available_delivery_types` вимагає `latitude`+`longitude` (не branchId) — координати брати з list_branches.

## Перевірені відповіді (форма даних)

**silpo_list_branches** → `{success, summary:"Found 453 branches", branches:[{branchId, externalId, city, address, latitude, longitude, hasPickup, open}]}`

**silpo_get_promotions** → `{success, summary:"Found 9 active promotions", promotions:[{code, title, productCount, url}]}`
Приклад реальних акцій: «Цінотижики» (572 товари), «Гуртом дешевше» (662), «Тільки Онлайн» (1604), «Купуй та заощаджуй» (4). ➡️ Це **колекції акцій**, не окремі товари — далі треба тягнути товари колекції (через `silpo_get_products` з фільтром промо / `url`).

**silpo_find_products_batch** args: `{branchId, deliveryType, timeslotStart, timeslotEnd, products:[string]≤30, limit?}` → `{success, queries:[{query, totalFound, products:[{id, name, slug, price, oldPrice, stock, available, image, weighted, step, externalProductId}]}]}`
- `oldPrice != null` → товар зі знижкою (є що показати як акцію).
- **Ранжування недосконале:** `рис` → першим преміум ризото (Riso Gallo/Cordero); `цибуля` → першою зелена цибуля 529₴. ➡️ **Підтверджує потребу в детермінованому ре-ранкінгу** (релевантність назви × ціна × промо × наявність) — саме це робить ProductMatchingService (top-3 + confidence). Не довіряти першому результату наосліп.

**silpo_get_my_profile / _food_restrictions / _family / _offline_orders** — працюють, але **тестовий акаунт порожній** (створений сьогодні: без імені, без обмежень, без сім'ї, без історії).
➡️ **Для демо «патерн п'ятниці» потрібен акаунт з реальною історією покупок** — або окремий акаунт з покупками, або зробити фолбек, якщо історії немає.

## Наслідки для планів (що вже враховано)
- `MealPlanService.generate` МУСИТЬ спочатку зарезолвити branch → deliveryType → **доступний timeslot**, і передавати їх у КОЖЕН виклик promotions/products. Додати це в крок 0 оркестратора (`01`, §4).
- Кешувати обраний слот на час генерації (щоб усі батчі йшли з одним контекстом).
- promotion-first: `get_promotions` дає КОЛЕКЦІЇ — треба ще крок «взяти товари колекції» (перевірити `silpo_get_products` args на промо-фільтр).
- Матчинг: нормалізація + ре-ранкінг обов'язкові (сирий пошук ранжує погано).
- Демо-акаунт має містити історію покупок.

---

# ДОДАТОК (2026-08-11, друга сесія тестів) — акції, кошик, матчинг

## silpo_get_products — повна схема (перевірено)
Args: `branchId, deliveryType, timeslotStart, timeslotEnd` (required) + фільтри:
`mustHavePromotion(bool), promotionCode(string), category, set, inStock(bool), fromPrice, toPrice, sortBy, sortDirection, limit, offset`.

**Як витягти товари конкретної акції** (promotion-first крок 2):
`silpo_get_products({...ctx, promotionCode:"cinotyzhyky", mustHavePromotion:true, inStock:true, sortBy:"price", sortDirection:"asc"})`
→ реальні акційні товари з `oldPrice`. Приклад: «Куряче стегно» 89.9₴ (було 109), «Jacobs 3в1» 8.99₴ (було 14.29).
Коди акцій беруться з `silpo_get_promotions` (поле `code`): additional, only_online, melkoopt, **cinotyzhyky** (Цінотижики, 572 товари), cinodidjiky, plyazhnyj-sezon, akciyi-vlasnogo-importu, kupuy_ta_zaoshadjuy тощо.

## Кошик — схеми та ⚠️ ВІДКРИТЕ ПИТАННЯ
- `silpo_get_my_shopping_cart` — без args. На свіжому акаунті → **"Resource not found"** (кошика ще немає).
- `silpo_add_or_update_cart_products` — required `shoppingCartId`(uuid) + `products:[{productId(uuid), companyId(uuid), branchId(uuid), quantity, addQuantity?, comment?}]`.
- `silpo_update_shopping_cart` — required `shoppingCartId, deliveryType, timeslot(object), address, shipments`.
- `silpo_get_shopping_cart_by_id` — `shoppingCartId`.

⚠️ **Створення кошика не очевидне:** випадковий UUID у add_or_update → "Resource not found". get_my_shopping_cart теж порожньо. Тобто кошик не створюється «з повітря» — потрібен існуючий `shoppingCartId`.
➡️ TODO для команди: з'ясувати (а) чи створюється кошик через офіційний застосунок/сайт Сільпо один раз, (б) чи є прихований крок створення, (в) спитати в starter-kit/менторів хакатону. Для демо checkout — мати акаунт із вже ініціалізованим кошиком.

## Матчинг інгредієнт→SKU — ГОЛОВНИЙ висновок (валідовано на 41 товарі)
- Пошук `find_products_batch` знаходить товари для **41/41** побутових запитів (з доступним слотом).
- АЛЕ «взяти перший / найдешевший» промахується у **~30–40%**: приклади реальних промахів —
  `філе куряче`→«Корм для котів», `морква`→«Приправа по-корейськи», `кока-кола`→«Корм для котів з лососем»,
  `олія`→«Тунець в олії», `картопля`→«Картопля варена з паприкою» (готова страва) 99₴ замість 14.99₴,
  `ковбаса`→1499₴ делікатес замість 63.99₴.
- **Простий фільтр релевантності** (назва містить головне слово інгредієнта + блок-лист «корм/приправа/соус/десерт/снек/сухар» + найдешевший серед доступних) виправив ~10/17 позицій і збив кошик тижня з «сміття» до реалістичних **1445₴**.
- Фільтр по назві теж НЕ ідеальний: `масло вершкове`→«Кукурудза з вершковим маслом», `яйця`→«Білок яєчний».
  ➡️ **Рекомендація для ProductMatchingService:** ранжувати не лише по назві, а й по **категорії товару** (тягнути category з get_product_details / get_products by category), відсікати нехарчові/готові страви/корми; тримати top-3 + confidence + ручне підтвердження low-confidence в UI. Це критичний, нетривіальний шматок — закласти час.

## Реюзабельні дані (щоб не шукати знову)
- `docs/reference/product-search-samples.json` — 41 інгредієнт → totalFound + найдешевший (з id/externalProductId/ціна) станом на 2026-08-11.
- `docs/reference/weekly-menu-cart-sample.json` — зібраний кошик тижня (17 позицій) з релевантним підбором, id та цінами; РАЗОМ ≈ 1445₴ на 3 особи (бюджет 4000₴ → великий запас, підтверджує сенс режиму «Смачніше»).
- Порядок пошуку: list_branches → SelfPickup → get_time_slots (available:true) → find_products_batch (products ≤30, укр. загальні назви) → фільтр релевантності.

## ✅ РОЗВ'ЯЗАНО (окончательно): кошик НЕ створюється через MCP
Схеми прямо кажуть: `shoppingCartId` — «from silpo_get_my_shopping_cart», `shipments`/`address` — «from get_shopping_cart_by_id response, do NOT construct manually». Тобто:
- **Tool'а «create cart» НЕМАЄ.** Усі cart-tools працюють лише з ІСНУЮЧИМ кошиком.
- `get_my_shopping_cart` → "Resource not found" на свіжому акаунті, бо кошик ніколи не створювався в застосунку/на сайті Сільпо.
- Перевірено гіпотези (усі провалились): cartId=profileId, zero-uuid, ffff-uuid, випадковий uuid у add/update.
➡️ **Наслідок для продукту:** реальні гості Сільпо зазвичай мають персистентний кошик → у них працює. Для «чистих» акаунтів кошик треба ініціалізувати на silpo.ua/у застосунку один раз (додати будь-який товар), АБО спитати менторів чи є прихований програмний спосіб. Для демо: використати акаунт, у якого вже є активний кошик.
➡️ **Тест-підтвердження:** відкрити silpo.ua (залогінений), додати 1 товар → повторити get_my_shopping_cart → має повернути shoppingCartId → далі add_or_update + get_by_id + checkout працюватимуть.

## 📗 ОФІЦІЙНИЙ канонічний флоу кошика (з доків Сільпо — підтверджує наш висновок)
`silpo_get_my_shopping_cart` = «Отримати ID АКТИВНОГО кошика. ЗАВЖДИ перший крок» → тобто кошик існує заздалегідь (активний кошик гостя), tool'а create немає. "Resource not found" на тесті = у свіжого акаунта ще немає активного кошика; у реального гостя Сільпо він завжди є.

**Канонічний сценарій «Наповнити кошик зі списку покупок»:**
1. `silpo_get_my_shopping_cart` → cartId
2. `silpo_get_shopping_cart_by_id(cartId)` → branchId, deliveryType, timeslot (контекст беремо З КОШИКА)
3. `silpo_get_time_slots` ← обов'язкова валідація слота
4. `silpo_find_products_batch(items)` → productId + companyId + branchId
5. `silpo_add_or_update_cart_products` → додати все
6. `silpo_get_shopping_cart_by_id` ← перевірити: `validations[]`, `loyalty.bonusAvailable` (запропонувати бонуси), express-варіант, і взяти **`checkoutWebLink` + `checkoutMobileLink`**

**Наслідки для нашого плану:**
- ✅ Checkout = поля **checkoutWebLink / checkoutMobileLink** з get_shopping_cart_by_id (окремого checkout-виклику НЕМАЄ).
- ⚠️ Офіційно контекст (branch/deliveryType/timeslot) береться З активного кошика (крок 2). У нас гість обирає філію на майстрі → треба або (а) використати branch кошика, або (б) змінити branch через `update_shopping_cart(branchId=...)`. Вирішити на етапі CartService.
- ✅ Показувати validations[] і loyalty.bonusAvailable в UI (S6/S7).
- Для демо checkout: акаунт з активним кошиком (відкрити silpo.ua/застосунок → кошик з'явиться).
