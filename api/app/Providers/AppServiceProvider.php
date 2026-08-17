<?php

namespace App\Providers;

use App\Ai\Tools\ToolActionContext;
use App\Services\Silpo\SilpoTokenProvider;
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
        // Один на запит: тулзи Зоряни пишуть сюди змінений план для live-refresh.
        $this->app->scoped(ToolActionContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Єдина точка доступу до Сільпо: іменований MCP-клієнт 'silpo'.
        // ->withOAuth(): потрібен для OAuth-дансу (connect/callback у routes/ai.php),
        //   public client + DCR + PKCE (усе всередині laravel/mcp).
        // ->withToken(): bearer для звичайних викликів tools/callTool — токен із
        //   silpo_tokens залогіненого юзера, або SILPO_DEMO_TOKEN для CLI/демо.
        Mcp::registerClient('silpo', fn () => Client::web(config('services.silpo.mcp_url'))
            ->withOAuth()
            ->withToken(fn () => app(SilpoTokenProvider::class)->currentAccessToken()));
    }
}
