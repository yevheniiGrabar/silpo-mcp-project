# api/ — Розумний кошик (Laravel 12 BFF)

Backend-for-frontend between the Flutter app and `mcp.silpo.ua`. Holds the
Silpo token **server-side (encrypted)**, runs the **deterministic** SKU
matching + budget optimizer (economy/quality), and orchestrates the meal
planner (laravel/ai). See `../AGENTS.md` and `../docs/`.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test
```

## Acceptance (verify-gate)

```bash
composer install
php artisan test          # Pest, green
./vendor/bin/pint --test  # no style violations
php -l <changed files>
```

Architecture: strictly **Controller → Service → Repository** (DB access only in
repositories). MCP calls always resolve context first (branch → deliveryType →
available slot) per `../docs/06`.
