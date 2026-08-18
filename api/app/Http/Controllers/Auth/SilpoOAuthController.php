<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Silpo\SilpoTokenProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Client\OAuth\TokenSet;

/**
 * Приймає результат OAuth-дансу Сільпо (laravel/mcp: discovery + DCR + PKCE),
 * зберігає TokenSet server-side у silpo_tokens. Викликається з routes/ai.php
 * через Mcp::oAuthRoutesFor('silpo', [SilpoOAuthController::class, 'store']).
 */
class SilpoOAuthController extends Controller
{
    public function store(TokenSet $token, SilpoTokenProvider $tokens): JsonResponse
    {
        // Прив'язуємо до залогіненого юзера; для демо — до demo-юзера.
        $user = Auth::user() ?? User::firstOrCreate(
            ['email' => config('app.demo_email')],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );

        $tokens->storeFor($user, $token);

        // #1 (страховка до #3): сигналимо довгоживучим воркерам м'яко перезапуститися,
        // щоб гарантовано підхопити новий токен (і будь-які зміни коду) після підключення.
        Artisan::call('queue:restart');

        return response()->json([
            'connected' => true,
            'user_id' => $user->id,
            'expires_at' => $token->expiresAt ? date(DATE_ATOM, $token->expiresAt) : null,
            'scope' => $token->scope,
            'message' => 'Silpo підключено — токен збережено server-side.',
        ]);
    }
}
