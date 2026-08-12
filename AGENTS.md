# AGENTS.md — інваріанти проєкту «Розумний кошик» (не порушувати)

## Продукт (актуальний)
Meal-planner на Silpo MCP: меню на тиждень під бюджет + два режими `economy`/`quality` + promotion-first + історія покупок. **Нове:** калорійність/БЖУ на страву, заміна страви (live re-plan), freemium-підписка. Канал — Flutter-застосунок. Джерело істини: `docs/00-OVERVIEW`, `01-05`, `06`, `07`, `09`.

## Архітектура
- Flutter → наш Laravel BFF → mcp.silpo.ua. Flutter НІКОЛИ не тримає токен Сільпо.
- Матчинг/ціни/бюджет — детермінований PHP-код. LLM (Claude) — планування меню, оцінка калорій, пояснення замін.
- Токени Сільпо server-side (encrypted). Laravel: Controller → Service → Repository. БД — тільки в репозиторіях.
- Джерело калорій — СВОЄ (LLM/нутрієнт-база), не MCP. `PriceSource`-абстракція (Silpo = реалізація) для майбутньої відвʼязки.

## MCP (див. 06 — перевірено наживо)
Tools `silpo_`; порядок: контекст (branch→deliveryType→**available slot**) → products/promotions (недоступний слот → 0). Матчинг: «перший/дешевий» промахується ~30-40% → ре-ранкінг по категорії + top-3/confidence обовʼязковий. Кошик не створюється через MCP (активний + checkoutWebLink/MobileLink).

## Правила збірки
- Гілки `claude/<phase>`, PR, ніколи не пуш у main напряму.
- Verify-then-push: acceptance зелені (Backend: php artisan test + pint --test + php -l; Mobile: flutter analyze + test + build).
- Оплату товарів у застосунку НЕ беремо (checkout-лінк Сільпо). Підписка — окремий білінг, не плутати.
- Не міняти ключові рішення (economy/quality, детермінований матчинг, токени серверно, freemium-модель) без згоди людини.

## Хакатон (якщо подаємо)
Конект саме до mcp.silpo.ua, ≥1 tool у сценарії, видиме demo з JSON-RPC логом, токени серверно, чіткий Гість+проблема+цінність. MCP у центрі.
