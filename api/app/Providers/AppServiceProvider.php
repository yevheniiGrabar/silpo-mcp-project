<?php

namespace App\Providers;

use App\Ai\Tools\ToolActionContext;
use App\Services\Silpo\SilpoTokenProvider;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\ClientManager;
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

        // #3 — свіжий MCP-клієнт на кожен queue-job. ClientManager — синглтон і
        // кешує Client (з токеном, взятим при першому build) на весь процес; у
        // довгоживучому `queue:work` це призводить до протухлого токена (401).
        // HTTP-запити скидають клієнт самі (app terminating), а воркер — ні,
        // тож перед кожним job примусово роз'єднуємо → наступний виклик
        // перебудує клієнт зі свіжим токеном із silpo_tokens.
        Event::listen(JobProcessing::class, function (): void {
            if ($this->app->resolved(ClientManager::class)) {
                $this->app->make(ClientManager::class)->disconnectAll();
            }
        });
    }
}
