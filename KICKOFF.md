# KICKOFF — старт збірки «Останній Шанс» у Cowork/Dispatch

Підключи в claude.ai аккаунті коннектори: **GitHub**, репо **yevheniiGrabar/silpo-mcp-project**, **Silpo MCP** (`https://mcp.silpo.ua/mcp`, з логіном у акаунт Сільпо). План Pro/Max. Моделі: оркестратор Opus 5/high, воркери Sonnet 5/high.

Встав Dispatch цей текст:

---

Ти — оркестратор збірки проєкту «Останній Шанс» (Last-Chance Engine) у репозиторії yevheniiGrabar/silpo-mcp-project.

**Прочитай спершу:**
1. `ORCHESTRATOR.md` — твоя повна інструкція (цикл, verify-then-push, моделі).
2. `AGENTS.md` — інваріанти.
3. `docs/00-OVERVIEW.md` + `docs/CONCEPT-LAST-CHANCE.md` — продукт.
4. `docs/06-MCP-VERIFIED.md` — перевірені факти MCP (футган слота, купон endDate, уцінки як near-expiry proxy, кошик, checkoutWebLink).
5. `docs/LC-BUILD.md` (що будувати) + `docs/LC-PHASES.md` (фази/порядок/acceptance).
⚠️ docs/01-05,07 — LEGACY meal-planner, НЕ будувати.

**Продукт коротко:** агент розбирає бонуси/купони гостя за термінами згорання і збирає персональну пропозицію, де стимул-що-згорає оплачує супер-ціну на його ЗВИЧНИЙ товар (get_my_offline_orders). Killer: економія гостя = скорочення 2 збитків Сільпо. Канали: Telegram-бот + B2B-дашборд. Матчинг — детермінований PHP, LLM лише пояснення.

**Як діяти:** веди BUILD-STATE.json; бери фази з LC-PHASES по залежностях; воркери в гілках `claude/<phase>`; перевіряй реальними acceptance (php artisan test + pint --test + php -l); тільки зелене → PR. У main не пуш. MCP-виклики реальні (порядок з 06: контекст→available slot→products/promotions).

**Старт:** P0 (scaffold Laravel + laravel/ai+laravel/mcp + telegram-bot-sdk + CI) → одразу P0.1 SPIKE (довести, що пакети проксують OAuth-MCP; інакше raw JSON-RPC фолбек). Паралельно людина готує DEMO-ACC (акаунт з купонами+історією+кошиком). Далі B1→B2→B3(ядро)→B4→T1…

Після кожної фази — короткий звіт: що зроблено, які acceptance пройдені, лінк на PR, що далі. Ключові рішення не міняй без мого дозволу.

---
