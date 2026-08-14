<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Єдина точка доступу до Сільпо: іменований MCP-клієнт 'silpo'.
        // Токен: у демо — SILPO_DEMO_TOKEN; у проді підмінюємо на per-user OAuth
        // токен із silpo_tokens (див. TokenProvider, фаза B2).
        Mcp::registerClient('silpo', fn () => Client::web(config('services.silpo.mcp_url'))
            ->withToken(fn () => (string) config('services.silpo.demo_token')));
    }
}
