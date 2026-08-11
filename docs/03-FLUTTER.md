# 03 · Flutter-застосунок — детальне ТЗ

> Мета: гарний, швидкий, «дорогий» на вигляд застосунок, який виграє демо. Дизайн — див. `04-DESIGN-SYSTEM.md`. Тут — структура, пакети, екрани, стан, анімації.

---

## 0. Встановлення / старт

```bash
flutter --version        # потрібен stable ≥ 3.24, Dart 3
flutter create --org ua.rozumnyikoshyk --platforms=ios,android rozumnyi_koshyk_app
cd rozumnyi_koshyk_app
```

### Пакети (`pubspec.yaml`)
```yaml
dependencies:
  flutter_riverpod: ^2.5.1      # стан (рекомендовано — швидко + чисто)
  riverpod_annotation: ^2.3.5
  dio: ^5.7.0                    # HTTP до нашого Laravel
  freezed_annotation: ^2.4.4    # immutable моделі
  json_annotation: ^4.9.0
  go_router: ^14.2.0            # навігація + таб-бар
  flutter_animate: ^4.5.0       # анімації (декларативно)
  flutter_secure_storage: ^9.2  # наш Sanctum-токен (НЕ токен Сільпо)
  cached_network_image: ^3.4    # фото товарів
  shimmer: ^3.0                 # skeleton-лоадери
  flutter_svg: ^2.0             # іконки
  google_fonts: ^6.2            # АБО бандл шрифтів локально (див. §дизайн)
dev_dependencies:
  build_runner: ^2.4
  freezed: ^2.5
  json_serializable: ^6.8
  riverpod_generator: ^2.4
```

> ⚠️ `google_fonts` тягне шрифт з мережі при першому запуску. Для стабільного демо — **забандлити** шрифт локально (`assets/fonts/`) і оголосити у `pubspec`. Див. `04`.

---

## 1. Архітектура застосунку

```
lib/
  main.dart
  app/                 # MaterialApp/CupertinoApp, тема, router
    theme/             # ← з 04-DESIGN-SYSTEM
    router.dart        # go_router + ShellRoute (таб-бар)
  core/
    api/               # Dio-клієнт, інтерсептори (auth token), помилки
    models/            # freezed DTO (MealPlan, CartItem, HomeCard, Branch...)
  features/
    auth/              # логін через Сільпо
    home/              # S2 головна + рекомендації
    wizard/            # S3a/S3b майстер
    generation/        # S4 генерація + live лог
    menu/              # S5 меню тижня
    shopping_list/     # S6 список + економія
    cart/              # S7 кошик + checkout
  shared/
    widgets/           # PhoneButton, Chip, Stepper, Slider, DayCard...
```
Патерн: **feature-first** + Riverpod-провайдери (repository → notifier → UI). Кожна фіча: `data/` (repo), `application/` (notifier/state), `presentation/` (screens/widgets).

---

## 2. Навігація (go_router + ShellRoute)

Таб-бар постійний (3 таби), центральна кнопка запускає майстер поверх (push):
```
ShellRoute (BottomNav: Головна | ＋Скласти меню | Кошик)
  /home            → HomeScreen (S2)
  /cart            → CartScreen (S7)
  push поверх:
    /wizard/step1  → WizardForWhomScreen (S3a)
    /wizard/step2  → WizardHowScreen (S3b)
    /generating/:id→ GenerationScreen (S4)
    /menu/:id      → WeekMenuScreen (S5)
    /list/:id      → ShoppingListScreen (S6)
  /login           → LoginScreen (S1, поза shell)
```
Центральна `＋` — не таб, а `context.push('/wizard/step1')`.

---

## 3. Екрани (детально)

### S1 · LoginScreen
- Лого, назва, tagline, вибір магазину (Сільпо active; АТБ/Novus disabled «скоро»).
- Кнопка «Увійти через Сільпо» → відкриває OAuth (`flutter_web_auth` або in-app browser) → callback → зберігаємо наш Sanctum-токен у secure storage.
- Анімація входу: fade+slide лого (flutter_animate).

### S2 · HomeScreen (таб)
- Привітання (ім'я з `/api/me`).
- **Картка «патерн п'ятниці»** (accent-soft): текст із `/api/home`, кнопки «Додати в кошик» / «Пропустити». Показувати лише якщо є патерн на сьогодні.
- Горизонтальний скрол «В акції зараз» (чіпи з `get_promotions`).
- Порожній стан → підказка на центральну кнопку.
- Pull-to-refresh. Skeleton (shimmer) поки вантажиться.

### S3a · WizardForWhomScreen
- Крок 1/2. Stepper «скільки людей». Чіпи алергій (prefill з `/api/me`, редаговані). Segmented «стиль» (ПП/Білкова/Овочі/Бюджетно/✨Здивуй мене).
- Кнопка «Далі».

### S3b · WizardHowScreen
- Крок 2/2. Grid іконок техніки (мультиселект). Segmented «час на страву».
- **Режим** (segmented, 2 опції): «💰 Економний» (жорсткий бюджет, меню від акцій) vs «✨ Смачніше» (бюджет м'який, +10–30%, смак важливіший). Обов'язковий вибір — впливає на оптимізатор.
- Slider «бюджет». У режимі «Смачніше» під слайдером — другий міні-slider/чіпи «наскільки можна вийти за бюджет: +10% / +20% / +30%».
- Кнопка «Згенерувати меню» → `POST /api/meal-plans` (з `mode`, `budget_flex_pct`) → отримати id → перейти на S4.

### S4 · GenerationScreen
- Анімований лоадер (кастомний, не дефолтний спінер — див. §анімації).
- **Live лог JSON-RPC** через SSE `/api/meal-plans/{id}/stream` — рядки з'являються по одному (важливо для журі: видно виклики mcp.silpo.ua).
- По `status=ready` → авто-перехід на S5.

### S5 · WeekMenuScreen
- Верх: назва + budget pill (optimized/budget). Кнопка «🧾 Список покупок» → S6.
- Список днів (Пн–Нд), кожен день — картка з 3 стравами (снід/обід/веч). Тап по страві → bottom-sheet з рецептом (кроки, час, техніка).
- Плавна поява карток (staggered fade-in).

### S6 · ShoppingListScreen
- **Банер економії**: дві суми (звичайна закреслена → розумна) + «економія X ₴» + прогрес-бар. Анімований лічильник (число «набігає»).
- Чек-ліст товарів: назва, граммовка, ціна, бейдж акції, іконка «↔ заміна». Тап «↔» → bottom-sheet з alt_options → `POST swap`.
- Low-confidence позиції — легкий маркер «перевір».
- Кнопка «Оформити в Сільпо».

### S7 · CartScreen (таб)
- Підсумок кошика, банер економії. Вибір отримання (самовивіз/доставка — radio). Кнопка «Оформити в Сільпо» → `POST checkout` → відкрити `checkout_url` у браузері.
- Примітка «оплату підтверджуєш у Сільпо».

---

## 4. Стан (Riverpod)
- `authProvider` — токен, статус логіну.
- `homeProvider` (FutureProvider) — картка + акції.
- `wizardProvider` (Notifier) — збирає всі вибори майстра.
- `mealPlanProvider(id)` — полінг/SSE статусу + дані меню.
- `cartProvider` — поточний кошик, swap, checkout.
Помилки — типізовані (`ApiFailure`), показуються як людські повідомлення (не стектрейс).

---

## 5. Анімації (де і які — стримано, «дорого»)
- **Page transitions:** плавні, iOS-стиль (Cupertino) або кастомний fade-through. Без стрибків.
- **Staggered reveal** карток днів/товарів (flutter_animate `.animate().fadeIn().slideY()` з інтервалом).
- **Лічильник економії** на S6 — TweenAnimationBuilder (число набігає 0→260).
- **Лоадер генерації** — кастомна: пульсуючий кошик/іконки продуктів або прогрес-кільце + друкований лог.
- **Мікровзаємодії:** натиск кнопок (scale 0.97 + haptic `HapticFeedback.lightImpact`), toggle чіпів (spring).
- **Skeleton (shimmer)** замість спінерів при завантаженні списків.
- Поважати `MediaQuery.disableAnimations` / reduce motion.
- Правило: одна яскрава оркестрована мить (екран економії) + все решта тихе. Не перевантажувати — зайва анімація = відчуття «AI-generated».

---

## 6. Definition of Done (Flutter)
- [ ] Логін через Сільпо → наш токен у secure storage.
- [ ] Головна показує реальні рекомендації + акції.
- [ ] Майстер (2 екрани) → генерація → меню → список → checkout — повний прохід.
- [ ] Екран генерації показує live JSON-RPC лог.
- [ ] Екран економії з анімованим лічильником і двома корзинами.
- [ ] Дизайн відповідає `04`, працює світла/темна тема, шрифт забандлений.
- [ ] Плавно на реальному пристрої (демо на iPhone у симуляторі/пристрої).
