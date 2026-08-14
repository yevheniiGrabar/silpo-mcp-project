<?php

use App\Http\Controllers\Auth\SilpoOAuthController;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| AI / MCP routes
|--------------------------------------------------------------------------
| OAuth-данс Сільпо через laravel/mcp. Реєструє:
|   GET mcp/silpo/connect          (name mcp.oauth.silpo.connect) — старт логіну
|   GET mcp/oauth/silpo/callback   (name mcp.oauth.silpo.callback) — обмін коду
| Клієнт 'silpo' зареєстрований у AppServiceProvider з ->withOAuth().
*/

Mcp::oAuthRoutesFor('silpo', [SilpoOAuthController::class, 'store']);
